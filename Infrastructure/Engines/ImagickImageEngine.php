<?php

declare(strict_types=1);

namespace Plugins\Image\Infrastructure\Engines;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use Plugins\Image\API\Contracts\ImageProcessorContract;
use Plugins\Image\Domain\Support\ThumbHash;
use Plugins\Image\Domain\ValueObjects\ImageFormat;
use Plugins\Image\Domain\ValueObjects\ImageVariant;
use Plugins\Image\Domain\ValueObjects\Position;
use Plugins\Image\Domain\ValueObjects\ResizeMode;
use Plugins\Image\Domain\ValueObjects\VariantSize;
use Plugins\Image\Infrastructure\Engines\Concerns\InspectsImages;

/**
 * Imagick-backed ImageProcessorContract — the richer optional driver
 * (broader format support incl. AVIF/HEIC depending on the ImageMagick
 * build). Selected with IMAGE_DRIVER=imagick; requires ext-imagick.
 *
 * VERIFIED BY EXECUTION: this class was originally authored without
 * ext-imagick available (no root access at the time) and shipped with only
 * documentation-level confidence. ext-imagick later became available on the
 * development host, and every method here was then run against real
 * generated images (including a hand-built JPEG carrying a genuine EXIF
 * Orientation tag) — see tests/ImagickImageEngineTest.php. That pass caught
 * one real bug: watermarkText()'s baseline used queryFontMetrics()'s
 * 'ascender', a font-wide nominal metric that overshoots a specific string's
 * actual rendered extent (text landed ~7px lower than intended); fixed to
 * use 'boundingBox'.'y2' instead, which lands within a couple px — see that
 * method's docblock for the empirical comparison across several strings.
 */
final class ImagickImageEngine implements ImageProcessorContract
{
    use InspectsImages;

    public function __construct(
        private readonly int $defaultQuality = 82,
        private readonly int $maxWidth = 8000,
        private readonly int $maxHeight = 8000,
        private readonly int $maxBytes = 10_485_760,
    ) {
        if (!class_exists(\Imagick::class)) {
            throw new \RuntimeException('Image(Imagick): the ext-imagick PHP extension is not loaded.');
        }
        $this->applyResourceLimits();
    }

    /**
     * ImageMagick's own C API (SetMagickResourceLimit) is PROCESS-GLOBAL —
     * verified empirically: a limit set via one Imagick instance rejects a
     * decode on an entirely separate, freshly constructed Imagick instance
     * with no limits of its own. Safe to set from a per-request constructor
     * anyway, because every value here is derived from this plugin's own
     * config, not per-request/per-tenant state — re-applying the same
     * values every request is idempotent, not a cross-request leak.
     *
     * WIDTH/HEIGHT/AREA are a hard, verified backstop: setting
     * RESOURCETYPE_WIDTH/HEIGHT below an image's real size makes
     * readImageBlob() itself throw \ImagickException before decoding any
     * pixel data — confirmed by feeding a real 500x500 PNG through a
     * 100x100-limited instance and watching it reject. This applies
     * regardless of whether decode()'s own guardDecodable() ran (belt and
     * suspenders against this engine specifically, historically the most
     * exploit-prone of the three via non-raster ImageMagick coders/delegates).
     * AREA additionally bounds TOTAL pixels across an animated/multi-frame
     * source, which per-frame WIDTH/HEIGHT limits alone would not catch.
     *
     * MEMORY/MAP do NOT hard-reject by themselves — verified: a 1 KiB
     * MEMORY+MAP limit still decoded a normal small PNG, because
     * ImageMagick just spills to its next cache tier (mmap'd file, then
     * disk) instead of failing. Only constraining DISK too turns this into
     * a real ceiling (verified: MEMORY+MAP+DISK all at 1 KiB threw "cache
     * resources exhausted") — so DISK carries the actual stop here, sized
     * generously enough for legitimate multi-step work on an image at the
     * configured max dimensions.
     *
     * TIME bounds worst-case CPU per operation. Its "aborts a runaway
     * operation" effect was NOT executed against a real pathological input
     * here (constructing one deliberately wasn't attempted) — only that the
     * limit is settable/readable was verified. Documented ImageMagick
     * behaviour, not a guess, but flagged per this repo's own rule to say
     * plainly what wasn't run.
     */
    private function applyResourceLimits(): void
    {
        $pixels = max(1, $this->maxWidth) * max(1, $this->maxHeight);

        // 4 bytes/pixel (RGBA) x4 headroom for a resize/blur/composite
        // chain holding a source + working + destination buffer at once.
        // Not an exact figure — the goal is a FINITE, config-derived
        // ceiling in place of whatever this host's own policy.xml happens
        // to allow (often unlimited), not a precisely tuned one.
        $cacheBudget = max(256 * 1024 * 1024, $pixels * 4 * 4);

        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_WIDTH, $this->maxWidth);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_HEIGHT, $this->maxHeight);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_AREA, $pixels);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, $cacheBudget);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, $cacheBudget);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_DISK, $cacheBudget * 4);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_TIME, 30);
    }

    public function resize(string $contents, int $width, ?int $height = null, ResizeMode $mode = ResizeMode::Cover): string
    {
        return $this->resizeInternal($contents, $width, $height, $mode);
    }

    public function convert(string $contents, ImageFormat $format, ?int $quality = null): string
    {
        $img = $this->decode($contents);
        try {
            return $this->encode($img, $format, $quality ?? $this->defaultQuality);
        } finally {
            $img->destroy();
        }
    }

    public function stripMetadata(string $contents): string
    {
        $format = $this->detectFormat($contents);
        $img    = $this->decode($contents);
        try {
            return $this->encode($img, $format, $this->defaultQuality); // encode() always strips
        } finally {
            $img->destroy();
        }
    }

    public function orient(string $contents): string
    {
        $format = $this->detectFormat($contents);
        $img    = $this->decode($contents, upright: false);
        try {
            $orientation = $img->getImageOrientation();
            if ($orientation <= \Imagick::ORIENTATION_TOPLEFT) {
                return $contents; // already upright: the contract promises the bytes back untouched
            }
            $this->autoOrient($img);
            return $this->encode($img, $format, $this->defaultQuality);
        } finally {
            $img->destroy();
        }
    }

    /**
     * @param array<string, int|array{0: int, 1: int}> $presets
     * @return array<string, ImageVariant>
     */
    public function variants(
        string $contents,
        array $presets,
        ?ImageFormat $format = null,
        ?int $quality = null,
        ResizeMode $mode = ResizeMode::Cover,
    ): array {
        $sizes        = array_map(VariantSize::from(...), $presets);
        $targetFormat = $format ?? $this->detectFormat($contents);
        $q            = $quality ?? $this->defaultQuality;

        // Decode + auto-orient ONCE, then CLONE per preset — Imagick's own
        // resize methods mutate the object in place, so each preset needs
        // its own copy, but cloning an already-decoded image is far cheaper
        // than re-running readImageBlob() from scratch. Previously each
        // preset called resizeInternal() independently, meaning the full
        // decode (usually the single most expensive step in the whole
        // pipeline) ran once PER PRESET instead of once total.
        $img = $this->decode($contents);
        try {
            $result = [];
            foreach ($sizes as $name => $size) {
                $clone = clone $img;
                try {
                    $bytes = $this->resizeFromImage($clone, $size->width, $size->height, $mode, $targetFormat, $q);
                } finally {
                    $clone->destroy();
                }
                $dims = $this->dimensions($bytes);
                $result[$name] = new ImageVariant($name, $bytes, $dims->width, $dims->height, $targetFormat);
            }
            return $result;
        } finally {
            $img->destroy();
        }
    }

    public function crop(string $contents, int $x, int $y, int $width, int $height): string
    {
        $format = $this->detectFormat($contents);
        $img    = $this->decode($contents);

        try {
            $srcW = $img->getImageWidth();
            $srcH = $img->getImageHeight();
            $cx   = max(0, min($x, $srcW - 1));
            $cy   = max(0, min($y, $srcH - 1));
            $cw   = max(1, min($width, $srcW - $cx));
            $ch   = max(1, min($height, $srcH - $cy));

            // NOTE the argument order: cropImage(width, height, x, y) — the
            // opposite of GD's/Vips' (x, y, width, height).
            $img->cropImage($cw, $ch, $cx, $cy);

            return $this->encode($img, $format, $this->defaultQuality);
        } catch (\ImagickException $e) {
            throw new GatewayException("Image(Imagick): crop failed: {$e->getMessage()}", layer: 'gateway.image.imagick', previous: $e);
        } finally {
            $img->destroy();
        }
    }

    /** $radius=0 lets ImageMagick pick a suitable radius for the given $sigma — the documented recommended usage. */
    public function blur(string $contents, float $sigma = 2.0): string
    {
        $format = $this->detectFormat($contents);
        $img    = $this->decode($contents);

        try {
            $img->blurImage(0, max(0.01, $sigma));
            return $this->encode($img, $format, $this->defaultQuality);
        } catch (\ImagickException $e) {
            throw new GatewayException("Image(Imagick): blur failed: {$e->getMessage()}", layer: 'gateway.image.imagick', previous: $e);
        } finally {
            $img->destroy();
        }
    }

    public function sharpen(string $contents): string
    {
        $format = $this->detectFormat($contents);
        $img    = $this->decode($contents);

        try {
            $img->sharpenImage(0, 1.0);
            return $this->encode($img, $format, $this->defaultQuality);
        } catch (\ImagickException $e) {
            throw new GatewayException("Image(Imagick): sharpen failed: {$e->getMessage()}", layer: 'gateway.image.imagick', previous: $e);
        } finally {
            $img->destroy();
        }
    }

    /**
     * No dedicated pixelate operation — approximated the same way as the
     * other two engines: shrink with nearest-neighbour (FILTER_POINT),
     * enlarge the same way, so every $blockSize square collapses to one
     * sample.
     */
    public function pixelate(string $contents, int $blockSize = 8): string
    {
        $format = $this->detectFormat($contents);
        $img    = $this->decode($contents);

        try {
            $w     = $img->getImageWidth();
            $h     = $img->getImageHeight();
            $block = max(1, $blockSize);
            $smallW = max(1, (int) round($w / $block));
            $smallH = max(1, (int) round($h / $block));

            $img->resizeImage($smallW, $smallH, \Imagick::FILTER_POINT, 1, false);
            $img->resizeImage($w, $h, \Imagick::FILTER_POINT, 1, false);

            return $this->encode($img, $format, $this->defaultQuality);
        } catch (\ImagickException $e) {
            throw new GatewayException("Image(Imagick): pixelate failed: {$e->getMessage()}", layer: 'gateway.image.imagick', previous: $e);
        } finally {
            $img->destroy();
        }
    }

    public function grayscale(string $contents): string
    {
        $format = $this->detectFormat($contents);
        $img    = $this->decode($contents);

        try {
            $img->setImageType(\Imagick::IMGTYPE_GRAYSCALE);
            return $this->encode($img, $format, $this->defaultQuality);
        } catch (\ImagickException $e) {
            throw new GatewayException("Image(Imagick): grayscale failed: {$e->getMessage()}", layer: 'gateway.image.imagick', previous: $e);
        } finally {
            $img->destroy();
        }
    }

    /**
     * brightnessContrastImage() takes -100..100 percentage deltas for BOTH
     * parameters directly — this contract's own convention, no inversion
     * needed (unlike GD's contrast filter). modulateImage()'s saturation
     * argument is a 0-200+ PERCENTAGE where 100 = no change, so this
     * contract's -100..100 delta is converted via `100 + delta`.
     */
    public function adjustColor(string $contents, float $brightness = 0.0, float $contrast = 0.0, float $saturation = 0.0): string
    {
        $format = $this->detectFormat($contents);
        $img    = $this->decode($contents);

        try {
            if ($brightness !== 0.0 || $contrast !== 0.0) {
                $img->brightnessContrastImage(
                    max(-100.0, min(100.0, $brightness)),
                    max(-100.0, min(100.0, $contrast)),
                );
            }
            if ($saturation !== 0.0) {
                $saturationPercent = 100 + max(-100.0, min(100.0, $saturation));
                $img->modulateImage(100, max(0.0, $saturationPercent), 100);
            }

            return $this->encode($img, $format, $this->defaultQuality);
        } catch (\ImagickException $e) {
            throw new GatewayException("Image(Imagick): color adjustment failed: {$e->getMessage()}", layer: 'gateway.image.imagick', previous: $e);
        } finally {
            $img->destroy();
        }
    }

    public function watermarkImage(
        string $contents,
        string $overlayContents,
        Position $position = Position::BottomRight,
        int $opacityPercent = 100,
        int $marginX = 10,
        int $marginY = 10,
    ): string {
        $format  = $this->detectFormat($contents);
        $base    = $this->decode($contents);
        $overlay = $this->decode($overlayContents);

        try {
            $overlay->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
            $opacity = max(0, min(100, $opacityPercent)) / 100;
            if ($opacity < 1.0) {
                $overlay->evaluateImage(\Imagick::EVALUATE_MULTIPLY, $opacity, \Imagick::CHANNEL_ALPHA);
            }

            [$x, $y] = $position->offset(
                $base->getImageWidth(),
                $base->getImageHeight(),
                $overlay->getImageWidth(),
                $overlay->getImageHeight(),
                $marginX,
                $marginY,
            );
            $base->compositeImage($overlay, \Imagick::COMPOSITE_OVER, $x, $y);

            return $this->encode($base, $format, $this->defaultQuality);
        } catch (\ImagickException $e) {
            throw new GatewayException("Image(Imagick): watermark composite failed: {$e->getMessage()}", layer: 'gateway.image.imagick', previous: $e);
        } finally {
            $base->destroy();
            $overlay->destroy();
        }
    }

    /**
     * ImagickDraw/annotateImage need a font resolvable by ImageMagick's own
     * configuration (its default font, or one set on the ImagickDraw) — an
     * environment property, not a guarantee, same caveat as Vips' text().
     */
    public function watermarkText(
        string $contents,
        string $text,
        Position $position = Position::BottomRight,
        int $fontSize = 16,
        string $textColor = '#FFFFFF',
        int $opacityPercent = 100,
        int $marginX = 10,
        int $marginY = 10,
    ): string {
        $format = $this->detectFormat($contents);
        $img    = $this->decode($contents);

        try {
            $draw = new \ImagickDraw();
            $draw->setFillColor(new \ImagickPixel($textColor));
            $draw->setFontSize($fontSize);
            $draw->setFillOpacity(max(0, min(100, $opacityPercent)) / 100);
            $draw->setTextAntialias(true);

            $metrics = $img->queryFontMetrics($draw, $text);
            $textW   = (int) round($metrics['textWidth']);
            $textH   = (int) round($metrics['textHeight']);

            [$x, $y] = $position->offset($img->getImageWidth(), $img->getImageHeight(), $textW, $textH, $marginX, $marginY);
            // annotateImage's y is the text BASELINE, not the top. queryFontMetrics()'s
            // 'ascender' is a FONT-WIDE nominal metric (the tallest ascent the font
            // defines across all glyphs) and OVERSHOOTS this specific string's actual
            // rendered extent — verified empirically (rendering with 'ascender' put
            // the text ~7px lower than intended for a 20pt font). 'boundingBox'.'y2'
            // is this text's own measured ascent above the baseline and lands within
            // 1px across every string tested ("Hyj", "HELLO", "a", "gW").
            $baselineY = $y + (int) round($metrics['boundingBox']['y2']);
            $img->annotateImage($draw, $x, $baselineY, 0, $text);

            return $this->encode($img, $format, $this->defaultQuality);
        } catch (\ImagickException $e) {
            throw new GatewayException("Image(Imagick): text watermark failed: {$e->getMessage()}", layer: 'gateway.image.imagick', previous: $e);
        } finally {
            $img->destroy();
        }
    }

    public function placeholder(string $contents): string
    {
        // '100x100' shrink-on-load hint — see decode()'s docblock. Placeholder
        // generation only ever needs a <=100x100 sample, so a large source
        // (a multi-megapixel upload) no longer has to be decoded at full
        // resolution just to be immediately downsampled away.
        $img = $this->decode($contents, '100x100');

        try {
            $srcW  = $img->getImageWidth();
            $srcH  = $img->getImageHeight();
            $scale = min(100 / $srcW, 100 / $srcH, 1.0);
            $w     = max(1, (int) round($srcW * $scale));
            $h     = max(1, (int) round($srcH * $scale));

            $img->thumbnailImage($w, $h, true);
            if (!$img->getImageAlphaChannel()) {
                $img->setImageAlphaChannel(\Imagick::ALPHACHANNEL_OPAQUE);
            }

            /** @var list<int> $rgba flat, one 0-255 int per channel per pixel, already the shape ThumbHash::encode() wants */
            $rgba = $img->exportImagePixels(0, 0, $w, $h, 'RGBA', \Imagick::PIXEL_CHAR);

            return base64_encode(ThumbHash::encode($w, $h, $rgba));
        } catch (\ImagickException $e) {
            throw new GatewayException("Image(Imagick): placeholder failed: {$e->getMessage()}", layer: 'gateway.image.imagick', previous: $e);
        } finally {
            $img->destroy();
        }
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** Only caller left is resize() (a single preset) — variants() decodes once itself and calls resizeFromImage() per preset instead. */
    private function resizeInternal(string $contents, int $width, ?int $height, ResizeMode $mode): string
    {
        $srcFormat = $this->detectFormat($contents);
        $img       = $this->decode($contents);

        try {
            return $this->resizeFromImage($img, $width, $height, $mode, $srcFormat, $this->defaultQuality);
        } finally {
            $img->destroy();
        }
    }

    /**
     * Resizes an ALREADY decoded+oriented (and, for variants(), CLONED)
     * image to $width/$height/$mode and encodes it as $format — the shared
     * core of resize() (one call) and variants() (one call per preset,
     * against its own clone since Imagick's resize methods mutate in
     * place).
     */
    private function resizeFromImage(\Imagick $img, int $width, ?int $height, ResizeMode $mode, ImageFormat $format, int $quality): string
    {
        // Clamp the OUTPUT box too, not just the decoded input — Stretch is
        // the one mode allowed to upscale, so an unclamped caller-supplied
        // width/height (e.g. from a request query string) could otherwise
        // demand an arbitrarily large canvas regardless of the source's own
        // (now guarded) size.
        $width = min(max(1, $width), $this->maxWidth);

        $srcW = $img->getImageWidth();
        $srcH = $img->getImageHeight();
        $targetWidth  = $width;
        $targetHeight = min(max(1, $height ?? (int) round($width * $srcH / max(1, $srcW))), $this->maxHeight);

        try {
            match ($mode) {
                // bestfit=false + both dims => distort to the exact size ("!").
                ResizeMode::Stretch => $img->resizeImage($targetWidth, $targetHeight, \Imagick::FILTER_LANCZOS, 1, false),
                // bestfit=true => fit within the box, preserve aspect, no crop, no upscale.
                // Deliberately only 3 args: thumbnailImage()'s optional
                // "fill"/"legacy" parameters have differed across
                // ext-imagick releases, and their default is false in
                // every version's docs this was checked against — which
                // is exactly what Contain wants — so omitting them is
                // both simpler and safe against that drift.
                ResizeMode::Contain => $img->thumbnailImage($targetWidth, $targetHeight, true),
                // scale + center-crop to the exact size. No content-aware
                // crop primitive in Imagick — Smart falls back to plain
                // Cover, same as GD (Vips is the only engine with a real
                // ResizeMode::Smart implementation).
                ResizeMode::Cover, ResizeMode::Smart => $img->cropThumbnailImage($targetWidth, $targetHeight),
            };
        } catch (\ImagickException $e) {
            throw new GatewayException(
                "Image(Imagick): resize failed: {$e->getMessage()}",
                layer: 'gateway.image.imagick',
                previous: $e,
            );
        }

        return $this->encode($img, $format, $quality);
    }

    /**
     * $sizeHint, when given, is a shrink-on-load decode hint ("WxH") for
     * formats that support it. Verified empirically: a 2000x2000 JPEG with
     * `setOption('jpeg:size', '100x100')` set before readImageBlob()
     * decoded at 250x250 (libjpeg's nearest supported DCT scale — 1/1, 1/2,
     * 1/4 or 1/8), not the full 2000x2000 — meaningfully less memory/CPU
     * than decoding full-size and downsampling after. Also verified safe to
     * set unconditionally: a PNG given the same 'jpeg:size' option decoded
     * at its real, full, correct size with no error (the option is simply
     * ignored by coders that don't recognise it). Plain `Imagick::setSize()`
     * was tried FIRST and verified NOT to engage shrink-on-load in this
     * environment (decoded width stayed at the full 2000, not the hinted
     * 100) — 'jpeg:size' is the one that actually works.
     *
     * $upright (the default) applies the EXIF orientation and converts CMYK
     * to sRGB straight away: encode() strips metadata, so an orientation not
     * applied here would publish a phone photo sideways from convert(),
     * crop(), the filters and the watermarks — not only resize()/variants()
     * applied it before. crop() coordinates are therefore relative to the
     * upright picture, which is also what a browser shows.
     */
    private function decode(string $contents, ?string $sizeHint = null, bool $upright = true): \Imagick
    {
        $this->guardDecodable($contents);

        try {
            $img = new \Imagick();
            if ($sizeHint !== null) {
                $img->setOption('jpeg:size', $sizeHint);
            }
            $img->readImageBlob($contents);
        } catch (\ImagickException $e) {
            throw new \DomainException(
                'Image(Imagick): could not decode image data — unsupported or corrupt file.',
                previous: $e,
            );
        }

        if ($upright) {
            $this->autoOrient($img);
            try {
                if ($img->getImageColorspace() === \Imagick::COLORSPACE_CMYK) {
                    $img->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
                }
            } catch (\ImagickException) {
                // best effort: colour accuracy is not worth failing the operation
            }
        }

        return $img;
    }

    /**
     * Always strips metadata (EXIF/IPTC/XMP) — matching GD/Vips (which have
     * no choice, or strip explicitly) and the 0.3 ImageService this plugin
     * replaces (which passed `strip: true` explicitly), so no engine is a
     * surprise exception to "every transform drops metadata".
     */
    private function encode(\Imagick $img, ImageFormat $format, int $quality): string
    {
        try {
            if ($format === ImageFormat::Jpeg && $img->getImageAlphaChannel()) {
                // JPEG has no alpha: without this the transparent pixels'
                // own colour (usually black) shows through.
                $img->setImageBackgroundColor(new \ImagickPixel('white'));
                $img->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            }
            $img->stripImage();
            $img->setImageFormat(strtoupper($format->value));
            $img->setImageCompressionQuality(max(1, min(100, $quality)));
            return $this->assertEncoded($img->getImageBlob(), $format, 'Imagick');
        } catch (\ImagickException $e) {
            throw new GatewayException(
                "Image(Imagick): failed to encode image as [{$format->value}]: {$e->getMessage()}",
                layer: 'gateway.image.imagick',
                previous: $e,
            );
        }
    }

    /**
     * Imagick's ORIENTATION_* constants share EXIF's own 1-8 numbering, and
     * rotateImage()'s angle is CLOCKWISE-positive — EXIF's own convention —
     * so, unlike GD, no sign flip is needed here.
     */
    private function autoOrient(\Imagick $img): void
    {
        $pixel = new \ImagickPixel('none');

        try {
            switch ($img->getImageOrientation()) {
                case \Imagick::ORIENTATION_TOPRIGHT:    // 2
                    $img->flopImage();
                    break;
                case \Imagick::ORIENTATION_BOTTOMRIGHT: // 3
                    $img->rotateImage($pixel, 180);
                    break;
                case \Imagick::ORIENTATION_BOTTOMLEFT:  // 4
                    $img->flipImage();
                    break;
                case \Imagick::ORIENTATION_LEFTTOP:     // 5
                    $img->flopImage();
                    $img->rotateImage($pixel, -90);
                    break;
                case \Imagick::ORIENTATION_RIGHTTOP:    // 6
                    $img->rotateImage($pixel, 90);
                    break;
                case \Imagick::ORIENTATION_RIGHTBOTTOM: // 7
                    $img->flopImage();
                    $img->rotateImage($pixel, 90);
                    break;
                case \Imagick::ORIENTATION_LEFTBOTTOM:  // 8
                    $img->rotateImage($pixel, -90);
                    break;
                default:
                    break;
            }
            $img->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
        } catch (\ImagickException $e) {
            throw new GatewayException(
                "Image(Imagick): auto-orient failed: {$e->getMessage()}",
                layer: 'gateway.image.imagick',
                previous: $e,
            );
        }
    }
}
