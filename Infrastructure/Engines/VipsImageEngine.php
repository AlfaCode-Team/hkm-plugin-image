<?php

declare(strict_types=1);

namespace Plugins\Image\Infrastructure\Engines;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use Jcupitt\Vips\BlendMode;
use Jcupitt\Vips\Exception as VipsException;
use Jcupitt\Vips\Image as VipsImage;
use Jcupitt\Vips\Interpretation;
use Plugins\Image\API\Contracts\ImageProcessorContract;
use Plugins\Image\Domain\Support\ThumbHash;
use Plugins\Image\Domain\ValueObjects\ImageFormat;
use Plugins\Image\Domain\ValueObjects\ImageVariant;
use Plugins\Image\Domain\ValueObjects\Position;
use Plugins\Image\Domain\ValueObjects\ResizeMode;
use Plugins\Image\Infrastructure\Engines\Concerns\InspectsImages;

/**
 * libvips-backed ImageProcessorContract, via the jcupitt/vips FFI binding —
 * the library the 0.3 ImageService actually used under the hood. Selected
 * with IMAGE_DRIVER=vips. jcupitt/vips is a HARD `require` of this plugin's
 * own composer.json (deliberate — a straight upgrade path for existing
 * jcupitt/vips-based projects), so its PHP class is always autoloadable; but
 * that alone does not make this engine usable — it still needs ext-ffi
 * loaded AND the libvips SHARED LIBRARY installed on the host, neither of
 * which composer can install for you. See Provider::vipsAvailable().
 *
 * Uses vips_thumbnail_buffer() rather than decode-then-resize: it applies
 * libvips' shrink-on-load optimisation (a JPEG/WEBP/etc. decoder can skip
 * full-resolution decoding when it already knows the target size), and it
 * auto-rotates by EXIF orientation BEFORE resizing by default — confirmed
 * against a real orientation=6 JPEG in this repo's tests, so orient()/resize()
 * need no hand-written rotation math at all (unlike GdImageEngine, which does
 * and had a real transpose/transverse sign bug caught by testing it).
 *
 * VERIFIED BY EXECUTION: every call in this file was run against real image
 * bytes (including a hand-built JPEG carrying a genuine EXIF Orientation
 * tag) using this host's actual libvips install during development — not
 * just checked against documentation. See tests/VipsImageEngineTest.php.
 */
final class VipsImageEngine implements ImageProcessorContract
{
    use InspectsImages;

    public function __construct(
        private readonly int $defaultQuality = 82,
        private readonly int $maxWidth = 8000,
        private readonly int $maxHeight = 8000,
        private readonly int $maxBytes = 10_485_760,
    ) {
        if (!extension_loaded('ffi')) {
            throw new \RuntimeException('Image(Vips): the ext-ffi PHP extension is not loaded (required by jcupitt/vips).');
        }
        if (!class_exists(VipsImage::class)) {
            throw new \RuntimeException('Image(Vips): the jcupitt/vips composer package is not installed — run `composer require jcupitt/vips`.');
        }
    }

    public function resize(string $contents, int $width, ?int $height = null, ResizeMode $mode = ResizeMode::Cover): string
    {
        return $this->resizeInternal($contents, $width, $height, $mode, null, null);
    }

    public function convert(string $contents, ImageFormat $format, ?int $quality = null): string
    {
        return $this->encode($this->decode($contents), $format, $quality ?? $this->defaultQuality);
    }

    public function stripMetadata(string $contents): string
    {
        $format = $this->detectFormat($contents);
        return $this->encode($this->decode($contents), $format, $this->defaultQuality);
    }

    public function orient(string $contents): string
    {
        $format = $this->detectFormat($contents);
        $img    = $this->decode($contents);

        try {
            $oriented = $img->autorot();
        } catch (VipsException $e) {
            throw new GatewayException("Image(Vips): auto-orient failed: {$e->getMessage()}", layer: 'gateway.image.vips', previous: $e);
        }

        return $this->encode($oriented, $format, $this->defaultQuality);
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
        $targetFormat = $format ?? $this->detectFormat($contents);
        $q            = $quality ?? $this->defaultQuality;

        $result = [];
        foreach ($presets as $name => $width) {
            $bytes = $this->resizeInternal($contents, (int) $width, null, $mode, $targetFormat, $q);
            $dims  = $this->dimensions($bytes);
            $result[$name] = new ImageVariant($name, $bytes, $dims->width, $dims->height, $targetFormat);
        }
        return $result;
    }

    public function crop(string $contents, int $x, int $y, int $width, int $height): string
    {
        $format = $this->detectFormat($contents);
        $img    = $this->decode($contents);

        $x = max(0, min($x, $img->width - 1));
        $y = max(0, min($y, $img->height - 1));
        $w = max(1, min($width, $img->width - $x));
        $h = max(1, min($height, $img->height - $y));

        try {
            $cropped = $img->crop($x, $y, $w, $h);
        } catch (VipsException $e) {
            throw new GatewayException("Image(Vips): crop failed: {$e->getMessage()}", layer: 'gateway.image.vips', previous: $e);
        }

        return $this->encode($cropped, $format, $this->defaultQuality);
    }

    public function blur(string $contents, float $sigma = 2.0): string
    {
        $format = $this->detectFormat($contents);
        $img    = $this->decode($contents);

        try {
            $blurred = $img->gaussblur(max(0.01, $sigma));
        } catch (VipsException $e) {
            throw new GatewayException("Image(Vips): blur failed: {$e->getMessage()}", layer: 'gateway.image.vips', previous: $e);
        }

        return $this->encode($blurred, $format, $this->defaultQuality);
    }

    public function sharpen(string $contents): string
    {
        $format = $this->detectFormat($contents);
        $img    = $this->decode($contents);

        try {
            $sharpened = $img->sharpen();
        } catch (VipsException $e) {
            throw new GatewayException("Image(Vips): sharpen failed: {$e->getMessage()}", layer: 'gateway.image.vips', previous: $e);
        }

        return $this->encode($sharpened, $format, $this->defaultQuality);
    }

    /**
     * No dedicated pixelate operation in libvips — approximated (verified,
     * produces genuinely blocky output, not just a blur) by shrinking with
     * nearest-neighbour sampling and enlarging back the same way, so every
     * pixel in a $blockSize square collapses to one sample.
     */
    public function pixelate(string $contents, int $blockSize = 8): string
    {
        $format = $this->detectFormat($contents);
        $img    = $this->decode($contents);
        $block  = max(1, $blockSize);

        try {
            $small     = $img->resize(1 / $block, ['kernel' => 'nearest']);
            $pixelated = $small->resize((float) $block, ['kernel' => 'nearest']);
        } catch (VipsException $e) {
            throw new GatewayException("Image(Vips): pixelate failed: {$e->getMessage()}", layer: 'gateway.image.vips', previous: $e);
        }

        return $this->encode($pixelated, $format, $this->defaultQuality);
    }

    public function grayscale(string $contents): string
    {
        $format = $this->detectFormat($contents);
        $img    = $this->decode($contents);

        try {
            $gray = $img->colourspace(Interpretation::B_W);
        } catch (VipsException $e) {
            throw new GatewayException("Image(Vips): grayscale failed: {$e->getMessage()}", layer: 'gateway.image.vips', previous: $e);
        }

        return $this->encode($gray, $format, $this->defaultQuality);
    }

    /**
     * Brightness/contrast via linear(a*in+b) applied ONLY to the color
     * bands (alpha, if present, is passed through with a=1,b=0 — verified:
     * applying linear() to all bands unscoped would also transform the
     * alpha channel, corrupting transparency). Saturation via the LCH
     * colourspace's Chroma band specifically (verified against a real
     * image) — a colorimetrically correct scale, not GD's luminance-blend
     * approximation.
     */
    public function adjustColor(string $contents, float $brightness = 0.0, float $contrast = 0.0, float $saturation = 0.0): string
    {
        $format = $this->detectFormat($contents);
        $img    = $this->decode($contents);

        try {
            if ($brightness !== 0.0 || $contrast !== 0.0) {
                $factor = 1 + max(-100.0, min(100.0, $contrast)) / 100;
                $offset = 128 * (1 - $factor) + max(-100.0, min(100.0, $brightness)) / 100 * 255;
                $img    = $this->linearColorBands($img, $factor, $offset);
            }
            if ($saturation !== 0.0) {
                $chromaFactor = 1 + max(-100.0, min(100.0, $saturation)) / 100;
                $lch  = $img->colourspace(Interpretation::LCH);
                $a    = array_fill(0, $lch->bands, 1.0);
                $b    = array_fill(0, $lch->bands, 0.0);
                $a[1] = max(0.0, $chromaFactor); // band 1 = Chroma; L, H, (alpha) untouched
                $img  = $lch->linear($a, $b)->colourspace(Interpretation::SRGB);
            }
        } catch (VipsException $e) {
            throw new GatewayException("Image(Vips): color adjustment failed: {$e->getMessage()}", layer: 'gateway.image.vips', previous: $e);
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

        try {
            if (!$overlay->hasAlpha()) {
                $overlay = $overlay->bandjoin(255); // fully opaque if the overlay has no alpha of its own
            }
            $overlay = $this->scaleAlphaBand($overlay, max(0, min(100, $opacityPercent)) / 100);

            [$x, $y] = $position->offset($base->width, $base->height, $overlay->width, $overlay->height, $marginX, $marginY);
            $composited = $base->composite2($overlay, BlendMode::OVER, ['x' => $x, 'y' => $y]);
        } catch (VipsException $e) {
            throw new GatewayException("Image(Vips): watermark composite failed: {$e->getMessage()}", layer: 'gateway.image.vips', previous: $e);
        }

        return $this->encode($composited, $format, $this->defaultQuality);
    }

    /**
     * Text rendering needs a font resolvable through the host's font system
     * (fontconfig on Linux) — verified working on the host this plugin was
     * developed on, but that is an environment property, not a guarantee.
     * `Image::text()` returns a 1-band coverage mask; this colorizes it by
     * joining a solid-color layer with the mask as that layer's alpha band,
     * then composites like watermarkImage(). A synthetic `Image::black()`
     * layer's default interpretation isn't a real colorspace vips can
     * composite against another image with ("no known route from
     * 'multiband' to 'srgb'", caught by testing this exact path) — cast()/
     * copy(['interpretation' => SRGB]) fixes it, done in solidColorLayer().
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
        $base   = $this->decode($contents);
        [$r, $g, $b] = $this->hexToRgb($textColor);

        try {
            $mask = VipsImage::text($text, ['width' => $base->width, 'dpi' => (int) round($fontSize * 8)]);
            $textRgba = $this->solidColorLayer($mask->width, $mask->height, $r, $g, $b)->bandjoin($mask);
            $textRgba = $this->scaleAlphaBand($textRgba, max(0, min(100, $opacityPercent)) / 100);

            [$x, $y] = $position->offset($base->width, $base->height, $textRgba->width, $textRgba->height, $marginX, $marginY);
            $composited = $base->composite2($textRgba, BlendMode::OVER, ['x' => $x, 'y' => $y]);
        } catch (VipsException $e) {
            throw new GatewayException("Image(Vips): text watermark failed: {$e->getMessage()}", layer: 'gateway.image.vips', previous: $e);
        }

        return $this->encode($composited, $format, $this->defaultQuality);
    }

    public function placeholder(string $contents): string
    {
        // thumbnail_buffer() below bypasses decode()'s guardDecodable() call
        // the same way resizeInternal() does — guard explicitly.
        $this->guardDecodable($contents);

        try {
            $img   = VipsImage::thumbnail_buffer($contents, 100, ['height' => 100, 'size' => 'down']);
            $rgba  = $img->hasAlpha() ? $img : $img->bandjoin(255);
            $rgba  = $rgba->colourspace(Interpretation::SRGB);
            $bytes = array_values(unpack('C*', $rgba->writeToMemory()) ?: []);
        } catch (VipsException $e) {
            throw new GatewayException("Image(Vips): placeholder failed: {$e->getMessage()}", layer: 'gateway.image.vips', previous: $e);
        }

        return base64_encode(ThumbHash::encode($rgba->width, $rgba->height, $bytes));
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * thumbnail_buffer() works from the raw buffer directly (shrink-on-load),
     * so this does not go through decode()/encode() for the resize step
     * itself — only for translating its own failures and for the final
     * format re-encode.
     */
    private function resizeInternal(
        string $contents,
        int $width,
        ?int $height,
        ResizeMode $mode,
        ?ImageFormat $format,
        ?int $quality,
    ): string {
        // thumbnail_buffer() below reads $contents directly (shrink-on-load,
        // never through decode()), so this is the one place that chokepoint
        // is bypassed — guard explicitly rather than relying on decode()'s
        // guardDecodable() call, which never runs on this path.
        $this->guardDecodable($contents);

        $srcFormat = $format ?? $this->detectFormat($contents);

        // Clamp the OUTPUT box too, not just the decoded input — Stretch is
        // the one mode allowed to upscale, so an unclamped caller-supplied
        // width/height (e.g. from a request query string) could otherwise
        // demand an arbitrarily large canvas regardless of the source's own
        // (now guarded) size.
        $width = min(max(1, $width), $this->maxWidth);
        if ($height !== null) {
            $height = min(max(1, $height), $this->maxHeight);
        }

        // Cover's 'crop' option needs an explicit target box: WITHOUT a
        // height, libvips defaults height to width (a square crop), not a
        // proportional one — verified empirically (a 150-wide Cover crop of
        // an 800x400 source came back 150x150, not 150x75, until this was
        // added). Contain/Stretch don't have that trap: omitting 'height'
        // for Contain already gives a proportional resize natively, and
        // Stretch only engages 'force' when $height is explicit.
        if (($mode === ResizeMode::Cover || $mode === ResizeMode::Smart) && $height === null) {
            $src    = $this->dimensions($contents);
            $height = min(max(1, (int) round($width * $src->height / max(1, $src->width))), $this->maxHeight);
        }

        // 'size' => 'down' never upscales (skipped only for Stretch, which
        // is explicitly allowed to force any size).
        $options = ['size' => 'down'];
        if ($height !== null) {
            $options['height'] = max(1, $height);
        }
        if ($mode === ResizeMode::Cover) {
            $options['crop'] = 'centre';
        } elseif ($mode === ResizeMode::Smart) {
            // Content-aware crop, verified against a real image (the crop
            // box centered on a salient region instead of the geometric
            // center) — VIPS-only, no GD/Imagick equivalent.
            $options['crop'] = 'attention';
        } elseif ($mode === ResizeMode::Stretch && $height !== null) {
            $options['size'] = 'force';
        }

        try {
            $thumb = VipsImage::thumbnail_buffer($contents, max(1, $width), $options);
        } catch (VipsException $e) {
            throw new \DomainException('Image(Vips): could not decode image data — unsupported or corrupt file.', previous: $e);
        }

        return $this->encode($thumb, $srcFormat, $quality ?? $this->defaultQuality);
    }

    private function decode(string $contents): VipsImage
    {
        $this->guardDecodable($contents);

        try {
            return VipsImage::newFromBuffer($contents);
        } catch (VipsException $e) {
            throw new \DomainException('Image(Vips): could not decode image data — unsupported or corrupt file.', previous: $e);
        }
    }

    /**
     * Always strips metadata (EXIF/IPTC/XMP) — matching GD (which has no
     * choice) and the 0.3 ImageService this plugin replaces (which passed
     * `strip: true` explicitly). Quality maps to PNG's 0-9 compression scale
     * the same way GdImageEngine does; GIF has no quality-equivalent option.
     */
    private function encode(VipsImage $img, ImageFormat $format, int $quality): string
    {
        $q = max(1, min(100, $quality));

        $options = match ($format) {
            ImageFormat::Jpeg, ImageFormat::Webp, ImageFormat::Avif => ['Q' => $q, 'strip' => true],
            ImageFormat::Png => ['compression' => (int) round((100 - $q) / 100 * 9), 'strip' => true],
            ImageFormat::Gif => ['strip' => true],
        };

        try {
            return $img->writeToBuffer('.' . $format->extension(), $options);
        } catch (VipsException $e) {
            throw new GatewayException(
                "Image(Vips): failed to encode image as [{$format->value}]: {$e->getMessage()}",
                layer: 'gateway.image.vips',
                previous: $e,
            );
        }
    }

    /**
     * linear(a*in+b) applied only to the color bands, alpha (if present)
     * passed through unchanged — verified: applying linear() to every band
     * unscoped also transforms alpha, corrupting transparency.
     */
    private function linearColorBands(VipsImage $img, float $factor, float $offset): VipsImage
    {
        $bands      = $img->bands;
        $colorBands = $img->hasAlpha() ? $bands - 1 : $bands;

        $a = array_fill(0, $colorBands, $factor);
        $b = array_fill(0, $colorBands, $offset);
        if ($img->hasAlpha()) {
            $a[] = 1.0;
            $b[] = 0.0;
        }

        return $img->linear($a, $b);
    }

    /**
     * Scales an image's OWN alpha band by $factor (0.0-1.0) — used for both
     * watermark opacity and pre-scaling a text mask's coverage. Ensures the
     * image has an alpha band first (bandjoin() auto-detects a numeric
     * constant and delegates to bandjoin_const() — verified, no separate
     * call needed).
     */
    private function scaleAlphaBand(VipsImage $img, float $factor): VipsImage
    {
        if ($factor >= 1.0) {
            return $img;
        }
        if (!$img->hasAlpha()) {
            $img = $img->bandjoin(255);
        }

        $bands = $img->bands;
        $a     = array_fill(0, $bands - 1, 1.0);
        $b     = array_fill(0, $bands - 1, 0.0);
        $a[]   = $factor;
        $b[]   = 0.0;

        return $img->linear($a, $b);
    }

    /**
     * A solid-color, sRGB-tagged image — a plain Image::black()->linear()
     * result has interpretation "multiband", which composite2() cannot
     * blend against a real image ("no known route from 'multiband' to
     * 'srgb'", caught by testing this exact path); cast()+copy() fixes it.
     */
    private function solidColorLayer(int $width, int $height, int $r, int $g, int $b): VipsImage
    {
        return VipsImage::black($width, $height)
            ->linear([0, 0, 0], [$r, $g, $b])
            ->cast('uchar')
            ->copy(['interpretation' => Interpretation::SRGB]);
    }

    /** @return array{0:int,1:int,2:int} */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            throw new \DomainException("Image(Vips): [{$hex}] is not a valid hex color.");
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}
