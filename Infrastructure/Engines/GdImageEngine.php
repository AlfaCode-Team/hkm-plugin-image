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
use Plugins\Image\Infrastructure\Engines\Concerns\InspectsImages;

/**
 * GD-backed ImageProcessorContract — the zero-additional-dependency default
 * (ext-gd ships with nearly every PHP build, mirroring how LocalStorageAdapter
 * is Storage's zero-dependency default driver).
 *
 * GD has no API to read or write EXIF/IPTC/XMP chunks: decoding through
 * imagecreatefromstring() and re-encoding always drops them. That means every
 * method here — not just stripMetadata() — strips metadata as a side effect.
 * If preserving non-orientation metadata through a transform matters, use
 * ImagickImageEngine (IMAGE_DRIVER=imagick) instead.
 *
 * Filter/adjustment notes, each verified by execution (see class docblocks
 * on the individual methods for the specific gotcha each one guards against):
 * - ResizeMode::Smart has no GD equivalent (no content-aware crop primitive)
 *   and falls back to plain Cover — see computeGeometry().
 * - IMG_FILTER_CONTRAST is famously inverted in GD (a POSITIVE level REDUCES
 *   contrast); adjustColor() negates the caller's value to compensate.
 * - imagecopymerge() silently flattens an overlay's own alpha channel to
 *   fully opaque — watermarkImage()/watermarkText() use imagecopy() with
 *   alpha blending enabled on the destination instead, and scale the
 *   overlay's own per-pixel alpha for the opacity parameter.
 * - Saturation has no native GD filter; adjustColor() falls back to a
 *   per-pixel blend loop, which is O(width x height) and meaningfully
 *   slower than Imagick's/Vips' native saturation on large images.
 */
final class GdImageEngine implements ImageProcessorContract
{
    use InspectsImages;

    public function __construct(
        private readonly int $defaultQuality = 82,
        private readonly ?string $watermarkTtfPath = null,
        private readonly int $maxWidth = 8000,
        private readonly int $maxHeight = 8000,
        private readonly int $maxBytes = 10_485_760,
    ) {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('Image(GD): the ext-gd PHP extension is not loaded.');
        }
    }

    public function resize(string $contents, int $width, ?int $height = null, ResizeMode $mode = ResizeMode::Cover): string
    {
        return $this->resizeInternal($contents, $width, $height, $mode);
    }

    public function convert(string $contents, ImageFormat $format, ?int $quality = null): string
    {
        $img = $this->decode($contents);
        return $this->encode($img, $format, $quality ?? $this->defaultQuality);
    }

    public function stripMetadata(string $contents): string
    {
        $format = $this->detectFormat($contents);
        $img    = $this->decode($contents);
        return $this->encode($img, $format, 92);
    }

    public function orient(string $contents): string
    {
        $format = $this->detectFormat($contents);
        if ($format !== ImageFormat::Jpeg) {
            return $contents; // only JPEG carries an EXIF Orientation tag here
        }

        $orientation = $this->readOrientation($contents);
        if ($orientation <= 1) {
            return $contents;
        }

        $img = $this->applyOrientation($this->decode($contents), $orientation);
        return $this->encode($img, $format, 92);
    }

    /**
     * @param array<string, int> $presets
     * @return array<string, ImageVariant>
     */
    public function variants(
        string $contents,
        array $presets,
        ?ImageFormat $format = null,
        ?int $quality = null,
        ResizeMode $mode = ResizeMode::Cover,
    ): array {
        // Detected from $contents itself, NEVER from $format: orientation
        // must be decided from the SOURCE bytes' real format regardless of
        // what the caller wants to re-encode to. Previously this used
        // $targetFormat for the Jpeg check too (via resizeInternal()'s own
        // $format ?? detectFormat()), so calling variants(..., ImageFormat::Webp)
        // on a real EXIF-rotated JPEG silently skipped auto-orientation —
        // $targetFormat !== ImageFormat::Jpeg short-circuited the read even
        // though the source bytes were a JPEG.
        $sourceFormat = $this->detectFormat($contents);
        $targetFormat = $format ?? $sourceFormat;
        $q            = $quality ?? $this->defaultQuality;

        // Decode + auto-orient ONCE and reuse for every preset —
        // imagecopyresampled() reads from $src without mutating it, so the
        // same decoded image is safe to resample from repeatedly. Previously
        // each preset re-ran resizeInternal() from scratch: a full
        // imagecreatefromstring() decode (usually the single most expensive
        // step in the whole pipeline) plus a fresh EXIF read ran once PER
        // PRESET instead of once total.
        $src = $this->decodeOriented($contents, $sourceFormat);

        $result = [];
        foreach ($presets as $name => $width) {
            $bytes = $this->resizeFromImage($src, (int) $width, null, $mode, $targetFormat, $q);
            $dims  = $this->dimensions($bytes);
            $result[$name] = new ImageVariant($name, $bytes, $dims->width, $dims->height, $targetFormat);
        }
        return $result;
    }

    public function crop(string $contents, int $x, int $y, int $width, int $height): string
    {
        $format = $this->detectFormat($contents);
        $src    = $this->decode($contents);
        $srcW   = imagesx($src);
        $srcH   = imagesy($src);

        // Clamp rather than error — a caller-driven crop UI shouldn't have
        // to pre-validate its own math against the source's true bounds.
        $x = max(0, min($x, $srcW - 1));
        $y = max(0, min($y, $srcH - 1));
        $w = max(1, min($width, $srcW - $x));
        $h = max(1, min($height, $srcH - $y));

        $dst = $this->newCanvas($w, $h, $format);
        if (!imagecopy($dst, $src, 0, 0, $x, $y, $w, $h)) {
            throw new GatewayException('Image(GD): crop failed.', layer: 'gateway.image.gd');
        }

        return $this->encode($dst, $format, $this->defaultQuality);
    }

    /**
     * GD has no adjustable-sigma Gaussian blur — IMG_FILTER_GAUSSIAN_BLUR
     * applies one fixed-radius pass. $sigma is approximated by repeating
     * that pass proportionally (verified: repeated passes visibly increase
     * blur radius, matching how every other Gaussian-blur-by-repeated-box-blur
     * approximation behaves).
     */
    public function blur(string $contents, float $sigma = 2.0): string
    {
        $format = $this->detectFormat($contents);
        $img    = $this->decode($contents);

        $passes = max(1, (int) round($sigma));
        for ($i = 0; $i < $passes; $i++) {
            if (!imagefilter($img, IMG_FILTER_GAUSSIAN_BLUR)) {
                throw new GatewayException('Image(GD): blur failed.', layer: 'gateway.image.gd');
            }
        }

        return $this->encode($img, $format, $this->defaultQuality);
    }

    public function sharpen(string $contents): string
    {
        $format = $this->detectFormat($contents);
        $img    = $this->decode($contents);

        $sharpenMatrix = [
            [-1.0, -1.0, -1.0],
            [-1.0,  9.0, -1.0],
            [-1.0, -1.0, -1.0],
        ];
        if (!imageconvolution($img, $sharpenMatrix, 1.0, 0.0)) {
            throw new GatewayException('Image(GD): sharpen failed.', layer: 'gateway.image.gd');
        }

        return $this->encode($img, $format, $this->defaultQuality);
    }

    public function pixelate(string $contents, int $blockSize = 8): string
    {
        $format = $this->detectFormat($contents);
        $img    = $this->decode($contents);

        if (!imagefilter($img, IMG_FILTER_PIXELATE, max(1, $blockSize), true)) {
            throw new GatewayException('Image(GD): pixelate failed.', layer: 'gateway.image.gd');
        }

        return $this->encode($img, $format, $this->defaultQuality);
    }

    public function grayscale(string $contents): string
    {
        $format = $this->detectFormat($contents);
        $img    = $this->decode($contents);

        if (!imagefilter($img, IMG_FILTER_GRAYSCALE)) {
            throw new GatewayException('Image(GD): grayscale failed.', layer: 'gateway.image.gd');
        }

        return $this->encode($img, $format, $this->defaultQuality);
    }

    public function adjustColor(string $contents, float $brightness = 0.0, float $contrast = 0.0, float $saturation = 0.0): string
    {
        $format = $this->detectFormat($contents);
        $img    = $this->decode($contents);

        if ($brightness !== 0.0) {
            // GD's own scale is already "positive = brighter" — no inversion needed.
            $level = (int) round(max(-100.0, min(100.0, $brightness)) / 100 * 255);
            if (!imagefilter($img, IMG_FILTER_BRIGHTNESS, $level)) {
                throw new GatewayException('Image(GD): brightness adjustment failed.', layer: 'gateway.image.gd');
            }
        }

        if ($contrast !== 0.0) {
            // IMG_FILTER_CONTRAST is inverted (verified: a POSITIVE level
            // REDUCES contrast) — negate so this contract's "positive =
            // more contrast" convention holds regardless of engine.
            $level = (int) round(-max(-100.0, min(100.0, $contrast)));
            if (!imagefilter($img, IMG_FILTER_CONTRAST, $level)) {
                throw new GatewayException('Image(GD): contrast adjustment failed.', layer: 'gateway.image.gd');
            }
        }

        if ($saturation !== 0.0) {
            $img = $this->applySaturation($img, max(-100.0, min(100.0, $saturation)));
        }

        return $this->encode($img, $format, $this->defaultQuality);
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

        // Verified: returns true both when converting AND when the image is
        // already truecolor, so this is safe to treat as a real failure.
        if (!imagepalettetotruecolor($overlay)) {
            throw new GatewayException('Image(GD): could not convert watermark overlay to truecolor.', layer: 'gateway.image.gd');
        }
        imagealphablending($overlay, false);
        imagesavealpha($overlay, true);
        $overlay = $this->scaleOverlayOpacity($overlay, $opacityPercent);

        $baseW = imagesx($base);
        $baseH = imagesy($base);
        $ovW   = imagesx($overlay);
        $ovH   = imagesy($overlay);
        [$x, $y] = $position->offset($baseW, $baseH, $ovW, $ovH, $marginX, $marginY);

        // The imagecopymerge()-flattens-alpha gotcha (verified): true
        // per-pixel-alpha compositing needs plain imagecopy() with
        // alpha blending ON for the DESTINATION, not imagecopymerge().
        imagealphablending($base, true);
        if (!imagecopy($base, $overlay, $x, $y, 0, 0, $ovW, $ovH)) {
            throw new GatewayException('Image(GD): watermark composite failed.', layer: 'gateway.image.gd');
        }

        return $this->encode($base, $format, $this->defaultQuality);
    }

    /**
     * Uses GD's 5 built-in bitmap fonts (1-5, always available, no font file
     * needed) unless $watermarkTtfPath was configured, in which case
     * imagettftext() renders at the requested $fontSize. Built-in fonts
     * ignore $fontSize entirely (mapped to the closest of the 5 fixed
     * sizes) — this is a real GD limitation, not an oversight.
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
        [$r, $g, $b] = $this->hexToRgb($textColor);

        $useTtf = $this->watermarkTtfPath !== null && is_file($this->watermarkTtfPath);

        if ($useTtf) {
            $box = imagettfbbox($fontSize, 0, $this->watermarkTtfPath, $text);
            if ($box === false) {
                throw new GatewayException('Image(GD): could not measure TTF text.', layer: 'gateway.image.gd');
            }
            $textW = abs($box[2] - $box[0]);
            $textH = abs($box[7] - $box[1]);
        } else {
            $font  = 5; // largest built-in bitmap font
            $textW = imagefontwidth($font) * strlen($text);
            $textH = imagefontheight($font);
        }

        [$x, $y] = $position->offset(imagesx($img), imagesy($img), (int) $textW, (int) $textH, $marginX, $marginY);
        $alpha = (int) round(127 - (max(0, min(100, $opacityPercent)) / 100 * 127));
        $color = imagecolorallocatealpha($img, $r, $g, $b, $alpha);
        if ($color === false) {
            throw new GatewayException('Image(GD): could not allocate watermark text color.', layer: 'gateway.image.gd');
        }

        if ($useTtf) {
            // imagettftext()'s $y is the text BASELINE, not the top. box[7]
            // (upper-left y) is negative — the ascent above the baseline —
            // so the baseline sits at top + ascent = $y - box[7]. Verified
            // empirically: rendering with this formula and scanning for the
            // topmost non-background pixel landed within 1px of the intended
            // $y; an earlier `$y + $textH + $box[7]` formula was off by 16px.
            $baselineY = $y - $box[7];
            if (imagettftext($img, $fontSize, 0, $x, $baselineY, $color, $this->watermarkTtfPath, $text) === false) {
                throw new GatewayException('Image(GD): TTF text watermark failed.', layer: 'gateway.image.gd');
            }
        } else {
            imagestring($img, 5, $x, $y, $text, $color);
        }

        return $this->encode($img, $format, $this->defaultQuality);
    }

    public function placeholder(string $contents): string
    {
        $img  = $this->decode($contents);
        $srcW = imagesx($img);
        $srcH = imagesy($img);

        // ThumbHash caps input at 100x100 — shrink proportionally.
        $scale = min(100 / $srcW, 100 / $srcH, 1.0);
        $w     = max(1, (int) round($srcW * $scale));
        $h     = max(1, (int) round($srcH * $scale));

        $small = $this->newCanvas($w, $h, ImageFormat::Png);
        if (!imagecopyresampled($small, $img, 0, 0, 0, 0, $w, $h, $srcW, $srcH)) {
            throw new GatewayException('Image(GD): placeholder shrink failed.', layer: 'gateway.image.gd');
        }

        $rgba = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($small, $x, $y);
                $rgba[] = ($c >> 16) & 0xFF;
                $rgba[] = ($c >> 8) & 0xFF;
                $rgba[] = $c & 0xFF;
                $gdAlpha = ($c >> 24) & 0x7F; // GD: 0=opaque, 127=transparent
                $rgba[] = (int) round(255 * (1 - $gdAlpha / 127));
            }
        }

        return base64_encode(ThumbHash::encode($w, $h, $rgba));
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** Only caller left is resize() (a single preset) — variants() decodes once itself and calls resizeFromImage() per preset instead. */
    private function resizeInternal(string $contents, int $width, ?int $height, ResizeMode $mode): string
    {
        $srcFormat = $this->detectFormat($contents);
        $src       = $this->decodeOriented($contents, $srcFormat);

        return $this->resizeFromImage($src, $width, $height, $mode, $srcFormat, $this->defaultQuality);
    }

    /** Decodes + applies EXIF auto-orientation (JPEG only) in one step — shared by resize() and variants(). */
    private function decodeOriented(string $contents, ImageFormat $srcFormat): \GdImage
    {
        $orientation = $srcFormat === ImageFormat::Jpeg ? $this->readOrientation($contents) : 1;
        $src         = $this->decode($contents);

        return $orientation > 1 ? $this->applyOrientation($src, $orientation) : $src;
    }

    /**
     * Resamples an ALREADY decoded+oriented image to $width/$height/$mode
     * and encodes it as $format — the shared core of resize() (one call)
     * and variants() (one call per preset, reusing the same decoded $src;
     * imagecopyresampled() reads from $src without mutating it, so reuse
     * across presets is safe).
     */
    private function resizeFromImage(\GdImage $src, int $width, ?int $height, ResizeMode $mode, ImageFormat $format, int $quality): string
    {
        // Clamp the OUTPUT box too, not just the decoded input — Stretch is
        // the one mode allowed to upscale, so an unclamped caller-supplied
        // width/height (e.g. from a request query string) could otherwise
        // demand an arbitrarily large canvas regardless of the source's own
        // (now guarded) size.
        $width  = min(max(1, $width), $this->maxWidth);
        $height = $height !== null ? min(max(1, $height), $this->maxHeight) : null;

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        [$dstW, $dstH, $srcX, $srcY, $cropW, $cropH] = $this->computeGeometry($srcW, $srcH, $width, $height, $mode);

        $dst = $this->newCanvas($dstW, $dstH, $format);
        if (!imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $dstW, $dstH, $cropW, $cropH)) {
            throw new GatewayException('Image(GD): resample failed.', layer: 'gateway.image.gd');
        }

        return $this->encode($dst, $format, $quality);
    }

    /** @return array{0:int,1:int,2:int,3:int,4:int,5:int} dstW, dstH, srcX, srcY, cropW, cropH */
    private function computeGeometry(int $srcW, int $srcH, int $targetWidth, ?int $targetHeight, ResizeMode $mode): array
    {
        $targetHeight ??= (int) round($targetWidth * $srcH / max(1, $srcW));
        $targetWidth   = max(1, $targetWidth);
        $targetHeight  = max(1, $targetHeight);

        return match ($mode) {
            ResizeMode::Stretch => [$targetWidth, $targetHeight, 0, 0, $srcW, $srcH],
            ResizeMode::Contain => $this->containGeometry($srcW, $srcH, $targetWidth, $targetHeight),
            // No content-aware crop primitive in GD — same as plain Cover.
            ResizeMode::Cover, ResizeMode::Smart => $this->coverGeometry($srcW, $srcH, $targetWidth, $targetHeight),
        };
    }

    /** @return array{0:int,1:int,2:int,3:int,4:int,5:int} */
    private function containGeometry(int $srcW, int $srcH, int $targetWidth, int $targetHeight): array
    {
        $scale = min($targetWidth / $srcW, $targetHeight / $srcH, 1.0); // never upscale
        $dstW  = max(1, (int) round($srcW * $scale));
        $dstH  = max(1, (int) round($srcH * $scale));

        return [$dstW, $dstH, 0, 0, $srcW, $srcH];
    }

    /** @return array{0:int,1:int,2:int,3:int,4:int,5:int} */
    private function coverGeometry(int $srcW, int $srcH, int $targetWidth, int $targetHeight): array
    {
        $targetRatio = $targetWidth / $targetHeight;
        $srcRatio    = $srcW / $srcH;

        if ($srcRatio > $targetRatio) {
            $cropH = $srcH;
            $cropW = (int) round($srcH * $targetRatio);
            $srcY  = 0;
            $srcX  = intdiv($srcW - $cropW, 2);
        } else {
            $cropW = $srcW;
            $cropH = (int) round($srcW / $targetRatio);
            $srcX  = 0;
            $srcY  = intdiv($srcH - $cropH, 2);
        }

        // Never upscale: if the aspect-correct crop is already smaller than
        // the requested box, ship the crop at its native size rather than
        // stretching it up to fill the nominal target.
        $dstW = min($targetWidth, $cropW);
        $dstH = min($targetHeight, $cropH);

        return [$dstW, $dstH, $srcX, $srcY, $cropW, $cropH];
    }

    private function newCanvas(int $width, int $height, ImageFormat $format): \GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        if (!$canvas instanceof \GdImage) {
            throw new GatewayException(
                "Image(GD): could not allocate a {$width}x{$height} canvas (out of memory?).",
                layer: 'gateway.image.gd',
            );
        }

        if ($format === ImageFormat::Jpeg) {
            // JPEG has no alpha channel — fill white instead of letting GD
            // flatten transparency to black on encode.
            $white = imagecolorallocate($canvas, 255, 255, 255);
            if ($white === false || !imagefill($canvas, 0, 0, $white)) {
                throw new GatewayException('Image(GD): failed to initialise the JPEG canvas background.', layer: 'gateway.image.gd');
            }
            return $canvas;
        }

        // Standard "preserve alpha through a resample" recipe: blending OFF
        // so imagecopyresampled() copies source alpha values directly rather
        // than compositing them onto the (empty) canvas.
        if (!imagealphablending($canvas, false) || !imagesavealpha($canvas, true)) {
            throw new GatewayException('Image(GD): failed to configure the canvas alpha channel.', layer: 'gateway.image.gd');
        }

        return $canvas;
    }

    private function decode(string $contents): \GdImage
    {
        $this->guardDecodable($contents);

        $img = @imagecreatefromstring($contents);
        if (!$img instanceof \GdImage) {
            throw new \DomainException('Image(GD): could not decode image data — unsupported or corrupt file.');
        }
        return $img;
    }

    private function encode(\GdImage $img, ImageFormat $format, int $quality): string
    {
        $q = max(0, min(100, $quality));

        ob_start();
        $ok = match ($format) {
            ImageFormat::Jpeg => imagejpeg($img, null, $q),
            ImageFormat::Png  => imagepng($img, null, (int) round((100 - $q) / 100 * 9)),
            ImageFormat::Webp => imagewebp($img, null, $q),
            ImageFormat::Gif  => imagegif($img),
            ImageFormat::Avif => function_exists('imageavif')
                ? imageavif($img, null, $q)
                : throw new GatewayException(
                    'Image(GD): AVIF encoding requires GD built against libavif (function imageavif() is missing).',
                    layer: 'gateway.image.gd',
                ),
        };
        $bytes = ob_get_clean();

        if ($ok === false || $bytes === false || $bytes === '') {
            throw new GatewayException("Image(GD): failed to encode image as [{$format->value}].", layer: 'gateway.image.gd');
        }

        return $bytes;
    }

    /** @return int EXIF Orientation tag (1-8), or 1 when absent/unreadable */
    private function readOrientation(string $contents): int
    {
        if (!function_exists('exif_read_data')) {
            return 1;
        }

        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $contents);
        rewind($stream);
        $exif = @exif_read_data($stream, 'IFD0');
        fclose($stream);

        return is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
    }

    /**
     * GD's imagerotate() rotates COUNTER-clockwise by the given angle — the
     * opposite convention from EXIF's "degrees needed to rotate CLOCKWISE to
     * correct". Angles below are negated accordingly.
     *
     * 5 (transpose) and 7 (transverse) are flip+rotate combos, and the two
     * are easy to swap: 5 is "flip horizontal, then rotate 90° CCW" (GD +90),
     * 7 is "flip horizontal, then rotate 90° CW" (GD -90). Verified against
     * an independent reference mapping (Pillow's ImageOps.exif_transpose,
     * which composes orientation 5 as FLIP_LEFT_RIGHT + ROTATE_90-CCW and
     * orientation 7 as FLIP_LEFT_RIGHT + ROTATE_90-CW) and against this
     * engine's own marker-position test in tests/GdImageEngineTest.php.
     */
    private function applyOrientation(\GdImage $img, int $orientation): \GdImage
    {
        return match ($orientation) {
            2 => $this->flipped($img, IMG_FLIP_HORIZONTAL),
            3 => $this->rotated($img, 180),
            4 => $this->flipped($img, IMG_FLIP_VERTICAL),
            5 => $this->rotated($this->flipped($img, IMG_FLIP_HORIZONTAL), 90),
            6 => $this->rotated($img, -90),
            7 => $this->rotated($this->flipped($img, IMG_FLIP_HORIZONTAL), -90),
            8 => $this->rotated($img, 90),
            default => $img,
        };
    }

    private function flipped(\GdImage $img, int $mode): \GdImage
    {
        imageflip($img, $mode);
        return $img;
    }

    private function rotated(\GdImage $img, float $angle): \GdImage
    {
        $rotated = imagerotate($img, $angle, 0);
        if (!$rotated instanceof \GdImage) {
            throw new GatewayException('Image(GD): rotate failed.', layer: 'gateway.image.gd');
        }
        return $rotated;
    }

    /**
     * No native GD saturation filter exists, so this blends every pixel
     * toward (negative $delta) or away from (positive $delta) its own
     * gray-luminance equivalent. O(width x height) — meaningfully slower
     * than Imagick's/Vips' native saturation on large images; prefer those
     * engines when this runs on anything bigger than a thumbnail.
     */
    private function applySaturation(\GdImage $img, float $delta): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $factor = 1 + $delta / 100; // -100 => 0.0 (fully gray), 0 => 1.0, 100 => 2.0

        imagealphablending($img, false);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($img, $x, $y);
                $r = ($c >> 16) & 0xFF;
                $g = ($c >> 8) & 0xFF;
                $b = $c & 0xFF;
                $alpha = ($c >> 24) & 0x7F;

                $gray  = 0.299 * $r + 0.587 * $g + 0.114 * $b;
                $newR  = (int) round(max(0, min(255, $gray + $factor * ($r - $gray))));
                $newG  = (int) round(max(0, min(255, $gray + $factor * ($g - $gray))));
                $newB  = (int) round(max(0, min(255, $gray + $factor * ($b - $gray))));

                $color = imagecolorallocatealpha($img, $newR, $newG, $newB, $alpha);
                if ($color === false || !imagesetpixel($img, $x, $y, $color)) {
                    throw new GatewayException('Image(GD): saturation adjustment failed.', layer: 'gateway.image.gd');
                }
            }
        }

        return $img;
    }

    /**
     * Scales an overlay's OWN per-pixel alpha by $opacityPercent — needed
     * because imagecopymerge() (verified) ignores per-pixel alpha entirely,
     * so opacity has to be baked into the overlay before a plain imagecopy().
     */
    private function scaleOverlayOpacity(\GdImage $overlay, int $opacityPercent): \GdImage
    {
        $opacityPercent = max(0, min(100, $opacityPercent));
        if ($opacityPercent >= 100) {
            return $overlay;
        }

        $w = imagesx($overlay);
        $h = imagesy($overlay);
        $factor = $opacityPercent / 100;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($overlay, $x, $y);
                $r = ($c >> 16) & 0xFF;
                $g = ($c >> 8) & 0xFF;
                $b = $c & 0xFF;
                $gdAlpha = ($c >> 24) & 0x7F; // 0 = opaque, 127 = fully transparent
                $opacity = 1 - $gdAlpha / 127;
                $newGdAlpha = (int) round(127 * (1 - $opacity * $factor));

                $color = imagecolorallocatealpha($overlay, $r, $g, $b, $newGdAlpha);
                if ($color === false || !imagesetpixel($overlay, $x, $y, $color)) {
                    throw new GatewayException('Image(GD): watermark opacity scaling failed.', layer: 'gateway.image.gd');
                }
            }
        }

        return $overlay;
    }

    /** @return array{0:int,1:int,2:int} */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            throw new \DomainException("Image(GD): [{$hex}] is not a valid hex color.");
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}
