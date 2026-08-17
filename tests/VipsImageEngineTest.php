<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Image;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Image\Domain\Support\ThumbHash;
use Plugins\Image\Domain\ValueObjects\ImageFormat;
use Plugins\Image\Domain\ValueObjects\Position;
use Plugins\Image\Domain\ValueObjects\ResizeMode;
use Plugins\Image\Infrastructure\Engines\VipsImageEngine;

/**
 * Self-skips wherever ext-ffi or the jcupitt/vips package isn't available.
 * Where this suite DOES run, every assertion here was independently
 * confirmed against this exact host's libvips install during development
 * (including a hand-built real EXIF orientation=6 JPEG) before being
 * written up as a test — see VipsImageEngine's class docblock.
 */
#[CoversClass(VipsImageEngine::class)]
final class VipsImageEngineTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('ffi') || !class_exists(\Jcupitt\Vips\Image::class)) {
            self::markTestSkipped('ext-ffi or jcupitt/vips is not available.');
        }
    }

    private function engine(int $quality = 85): VipsImageEngine
    {
        return new VipsImageEngine($quality);
    }

    private function jpeg(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 20, 20));
        ob_start();
        imagejpeg($img, null, 90);
        return (string) ob_get_clean();
    }

    private function pngWithAlpha(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefilledrectangle($img, 0, 0, $w, $h, $transparent);
        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    }

    private function jpegColor(int $w, int $h, int $r, int $g, int $b): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
        ob_start();
        imagejpeg($img, null, 95);
        return (string) ob_get_clean();
    }

    /** $alpha255: 0 = fully transparent, 255 = fully opaque. */
    private function pngColorAlpha(int $w, int $h, int $r, int $g, int $b, int $alpha255): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $gdAlpha = (int) round(127 * (1 - $alpha255 / 255));
        $color   = imagecolorallocatealpha($img, $r, $g, $b, $gdAlpha);
        imagefilledrectangle($img, 0, 0, $w, $h, $color);
        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    }

    private function pixel(string $bytes, int $x, int $y): array
    {
        return \Jcupitt\Vips\Image::newFromBuffer($bytes)->getpoint($x, $y);
    }

    /** Hand-builds a minimal, real EXIF APP1 segment and splices it after the JPEG SOI marker. */
    private function jpegWithOrientation(int $w, int $h, int $orientation): string
    {
        $plain = $this->jpeg($w, $h);

        $tiffHeader = "MM\x00\x2A" . pack('N', 8);
        $entry      = pack('n', 0x0112) . pack('n', 3) . pack('N', 1) . pack('n', $orientation) . "\x00\x00";
        $ifd0       = pack('n', 1) . $entry . pack('N', 0);
        $tiff       = $tiffHeader . $ifd0;
        $payload    = "Exif\x00\x00" . $tiff;
        $app1       = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

        return substr($plain, 0, 2) . $app1 . substr($plain, 2);
    }

    public function test_dimensions_reads_real_size(): void
    {
        $dims = $this->engine()->dimensions($this->jpeg(800, 400));
        self::assertSame(800, $dims->width);
        self::assertSame(400, $dims->height);
    }

    public function test_dimensions_on_garbage_throws(): void
    {
        $this->expectException(\DomainException::class);
        $this->engine()->dimensions('not an image');
    }

    public function test_cover_fills_the_exact_target_box(): void
    {
        $out  = $this->engine()->resize($this->jpeg(800, 400), 100, 100, ResizeMode::Cover);
        $dims = $this->engine()->dimensions($out);
        self::assertSame(100, $dims->width);
        self::assertSame(100, $dims->height);
    }

    public function test_contain_fits_within_the_box_preserving_aspect(): void
    {
        $out  = $this->engine()->resize($this->jpeg(800, 400), 200, 200, ResizeMode::Contain);
        $dims = $this->engine()->dimensions($out);
        self::assertSame(200, $dims->width);
        self::assertSame(100, $dims->height);
    }

    public function test_stretch_forces_the_exact_size_ignoring_aspect(): void
    {
        $out  = $this->engine()->resize($this->jpeg(800, 400), 50, 300, ResizeMode::Stretch);
        $dims = $this->engine()->dimensions($out);
        self::assertSame(50, $dims->width);
        self::assertSame(300, $dims->height);
    }

    public function test_contain_never_upscales(): void
    {
        $out  = $this->engine()->resize($this->jpeg(50, 50), 500, 500, ResizeMode::Contain);
        $dims = $this->engine()->dimensions($out);
        self::assertSame(50, $dims->width);
        self::assertSame(50, $dims->height);
    }

    public function test_cover_never_upscales(): void
    {
        $out  = $this->engine()->resize($this->jpeg(50, 50), 500, 300, ResizeMode::Cover);
        $dims = $this->engine()->dimensions($out);
        self::assertLessThanOrEqual(500, $dims->width);
        self::assertLessThanOrEqual(300, $dims->height);
    }

    public function test_convert_round_trips_through_every_supported_format(): void
    {
        $source = $this->jpeg(64, 64);
        $engine = $this->engine();

        foreach ([ImageFormat::Webp, ImageFormat::Png, ImageFormat::Gif, ImageFormat::Avif] as $format) {
            $out = $engine->convert($source, $format, 80);
            self::assertNotSame('', $out);
            self::assertSame($format, $engine->detectFormat($out));
        }
    }

    public function test_variants_produces_every_preset_at_the_requested_format(): void
    {
        $variants = $this->engine()->variants(
            $this->jpeg(800, 400),
            ['thumbnail' => 150, 'medium' => 300, 'large' => 1024],
            ImageFormat::Webp,
        );

        self::assertSame(['thumbnail', 'medium', 'large'], array_keys($variants));
        self::assertSame(150, $variants['thumbnail']->width);
        self::assertSame(75, $variants['thumbnail']->height);
        self::assertSame(ImageFormat::Webp, $variants['thumbnail']->format);

        // "large" preset (1024) exceeds the 800px source width — no upscale.
        self::assertSame(800, $variants['large']->width);
        self::assertSame(400, $variants['large']->height);
    }

    /**
     * Regression-relevant: unlike GdImageEngine (which needed hand-written
     * rotation math, and had a real transpose/transverse sign bug caught by
     * testing), libvips' thumbnail_buffer()/autorot() auto-orient natively.
     * This asserts the dimension swap a 90-degree correction produces.
     */
    public function test_orient_swaps_dimensions_for_a_90_degree_correction(): void
    {
        $withExif = $this->jpegWithOrientation(20, 10, 6);

        $engine = $this->engine();
        $before = $engine->dimensions($withExif);
        self::assertSame(20, $before->width);
        self::assertSame(10, $before->height);

        $after = $engine->dimensions($engine->orient($withExif));
        self::assertSame(10, $after->width);
        self::assertSame(20, $after->height);
    }

    public function test_resize_auto_orients_before_resizing(): void
    {
        // thumbnail_buffer() auto-rotates by default — a resize of a sideways
        // 20x10 (orientation 6) source to width=10 should reflect the
        // UPRIGHT 10x20 image scaled down (5x10), not the raw 20x10 (10x5).
        $withExif = $this->jpegWithOrientation(20, 10, 6);

        $out  = $this->engine()->resize($withExif, 10, null, ResizeMode::Contain);
        $dims = $this->engine()->dimensions($out);

        self::assertSame(5, $dims->width);
        self::assertSame(10, $dims->height);
    }

    public function test_orient_is_safe_without_exif(): void
    {
        $out = $this->engine()->orient($this->jpeg(20, 10));
        $dims = $this->engine()->dimensions($out);
        self::assertSame(20, $dims->width);
        self::assertSame(10, $dims->height);
    }

    public function test_resize_preserves_png_alpha_transparency(): void
    {
        $out = $this->engine()->resize($this->pngWithAlpha(200, 200), 50, 50, ResizeMode::Contain);
        self::assertSame(ImageFormat::Png, $this->engine()->detectFormat($out));

        $img   = imagecreatefromstring($out);
        self::assertNotFalse($img);
        $rgba  = imagecolorat($img, 0, 0);
        $alpha = ($rgba >> 24) & 0x7F;
        self::assertSame(127, $alpha, 'corner pixel should still be fully transparent after resize');
    }

    public function test_strip_metadata_keeps_dimensions_and_format(): void
    {
        $source   = $this->jpeg(64, 32);
        $stripped = $this->engine()->stripMetadata($source);

        $dims = $this->engine()->dimensions($stripped);
        self::assertSame(64, $dims->width);
        self::assertSame(32, $dims->height);
        self::assertSame(ImageFormat::Jpeg, $this->engine()->detectFormat($stripped));
    }

    // ── ResizeMode::Smart ────────────────────────────────────────────────────

    public function test_smart_crop_centers_on_the_salient_region(): void
    {
        $img = imagecreatetruecolor(200, 100);
        imagefill($img, 0, 0, imagecolorallocate($img, 50, 50, 50));
        imagefilledrectangle($img, 150, 10, 190, 90, imagecolorallocate($img, 255, 0, 0));
        ob_start();
        imagejpeg($img, null, 95);
        $asym = (string) ob_get_clean();

        [$r] = $this->pixel($this->engine()->resize($asym, 80, 80, ResizeMode::Smart), 40, 40);
        self::assertGreaterThan(150, $r); // reddish, not the gray background
    }

    // ── crop() ───────────────────────────────────────────────────────────────

    public function test_crop_extracts_the_exact_rectangle(): void
    {
        $dims = $this->engine()->dimensions($this->engine()->crop($this->jpeg(100, 100), 20, 20, 40, 40));
        self::assertSame(40, $dims->width);
        self::assertSame(40, $dims->height);
    }

    public function test_crop_clamps_an_out_of_range_rectangle(): void
    {
        $dims = $this->engine()->dimensions($this->engine()->crop($this->jpeg(200, 100), 190, 90, 50, 50));
        self::assertSame(10, $dims->width);
        self::assertSame(10, $dims->height);
    }

    // ── blur() / sharpen() / pixelate() ─────────────────────────────────────

    public function test_blur_softens_a_hard_edge(): void
    {
        $img = imagecreatetruecolor(50, 50);
        imagefill($img, 0, 0, imagecolorallocate($img, 0, 0, 0));
        imagefilledrectangle($img, 25, 0, 49, 49, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagejpeg($img, null, 100);
        $edge = (string) ob_get_clean();

        [$r] = $this->pixel($this->engine()->blur($edge, 3.0), 25, 25);
        self::assertGreaterThan(20, $r);
        self::assertLessThan(235, $r);
    }

    public function test_sharpen_does_not_error_and_keeps_dimensions(): void
    {
        $dims = $this->engine()->dimensions($this->engine()->sharpen($this->jpeg(64, 64)));
        self::assertSame(64, $dims->width);
        self::assertSame(64, $dims->height);
    }

    public function test_pixelate_keeps_dimensions(): void
    {
        $dims = $this->engine()->dimensions($this->engine()->pixelate($this->jpeg(200, 100), 10));
        self::assertSame(200, $dims->width);
        self::assertSame(100, $dims->height);
    }

    // ── grayscale() / adjustColor() ─────────────────────────────────────────

    public function test_grayscale_produces_a_single_band_image(): void
    {
        $out = $this->engine()->grayscale($this->jpegColor(20, 20, 100, 150, 200));
        self::assertSame(1, \Jcupitt\Vips\Image::newFromBuffer($out)->bands);
    }

    public function test_brightness_increases_every_channel(): void
    {
        $base = $this->jpegColor(20, 20, 100, 150, 200);
        [$r0, $g0, $b0] = $this->pixel($base, 10, 10);
        [$r1, $g1, $b1] = $this->pixel($this->engine()->adjustColor($base, brightness: 50), 10, 10);
        self::assertGreaterThan($r0, $r1);
        self::assertGreaterThan($g0, $g1);
        self::assertGreaterThanOrEqual($b0, $b1);
    }

    public function test_positive_contrast_widens_the_spread_from_midpoint(): void
    {
        $base = $this->jpegColor(20, 20, 100, 150, 200);
        [, , $b0] = $this->pixel($base, 10, 10);
        [, , $b1] = $this->pixel($this->engine()->adjustColor($base, contrast: 50), 10, 10);
        self::assertGreaterThan($b0, $b1);
    }

    public function test_full_desaturation_equalises_channels(): void
    {
        [$r, $g, $b] = $this->pixel($this->engine()->adjustColor($this->jpegColor(20, 20, 100, 150, 200), saturation: -100), 10, 10);
        self::assertEqualsWithDelta($r, $g, 1.0);
        self::assertEqualsWithDelta($g, $b, 1.0);
    }

    public function test_saturation_boost_widens_the_spread(): void
    {
        $base = $this->jpegColor(50, 50, 200, 80, 80);
        [$r0, $g0] = $this->pixel($base, 10, 10);
        [$r1, $g1] = $this->pixel($this->engine()->adjustColor($base, saturation: 60), 10, 10);
        self::assertGreaterThan($r0 - $g0, $r1 - $g1);
    }

    // ── watermarkImage() / watermarkText() ──────────────────────────────────

    public function test_watermark_image_blends_using_the_overlays_own_alpha(): void
    {
        $bg      = $this->jpegColor(100, 100, 255, 255, 255);
        $overlay = $this->pngColorAlpha(20, 20, 0, 0, 0, 128);

        [$r] = $this->pixel($this->engine()->watermarkImage($bg, $overlay, Position::TopLeft, 100, 0, 0), 10, 10);
        self::assertGreaterThan(80, $r);
        self::assertLessThan(180, $r);
    }

    public function test_watermark_image_zero_opacity_leaves_the_background_untouched(): void
    {
        $bg      = $this->jpegColor(100, 100, 255, 255, 255);
        $overlay = $this->pngColorAlpha(20, 20, 0, 0, 0, 255);

        [$r, $g, $b] = $this->pixel($this->engine()->watermarkImage($bg, $overlay, Position::TopLeft, 0, 0, 0), 10, 10);
        self::assertGreaterThan(250, $r);
        self::assertGreaterThan(250, $g);
        self::assertGreaterThan(250, $b);
    }

    public function test_watermark_text_does_not_error(): void
    {
        $bg   = $this->jpegColor(100, 100, 255, 255, 255);
        $dims = $this->engine()->dimensions($this->engine()->watermarkText($bg, 'HELLO', Position::BottomRight, 16, '#FF0000'));
        self::assertSame(100, $dims->width);
        self::assertSame(100, $dims->height);
    }

    // ── placeholder() ────────────────────────────────────────────────────────

    public function test_placeholder_decodes_to_approximately_the_source_average_color(): void
    {
        $source = $this->jpegColor(64, 64, 100, 150, 200);
        $hash   = base64_decode($this->engine()->placeholder($source));
        $avg    = ThumbHash::averageColor($hash);

        self::assertEqualsWithDelta(100 / 255, $avg['r'], 0.08);
        self::assertEqualsWithDelta(150 / 255, $avg['g'], 0.08);
        self::assertEqualsWithDelta(200 / 255, $avg['b'], 0.08);
    }

    // ── decode()/guardDecodable() guard (structural counterpart to validate()) ──

    public function test_convert_rejects_oversized_dimensions_without_validate_ever_being_called(): void
    {
        $engine = new VipsImageEngine(defaultQuality: 85, maxWidth: 50, maxHeight: 50);
        $this->expectException(\DomainException::class);
        $engine->convert($this->jpeg(200, 200), ImageFormat::Png);
    }

    public function test_convert_rejects_oversized_bytes_without_validate_ever_being_called(): void
    {
        $engine = new VipsImageEngine(defaultQuality: 85, maxBytes: 100);
        $this->expectException(\DomainException::class);
        $engine->convert($this->jpeg(200, 200), ImageFormat::Png);
    }

    public function test_resize_rejects_oversized_dimensions_without_validate_ever_being_called(): void
    {
        // resize()/variants() go through resizeInternal(), which calls
        // thumbnail_buffer() DIRECTLY on the raw buffer for libvips'
        // shrink-on-load optimisation — it never routes through decode(),
        // so guardDecodable() had to be added explicitly there too, not
        // just in decode() itself.
        $engine = new VipsImageEngine(defaultQuality: 85, maxWidth: 50, maxHeight: 50);
        $this->expectException(\DomainException::class);
        $engine->resize($this->jpeg(200, 200), 20);
    }

    public function test_placeholder_rejects_oversized_dimensions_without_validate_ever_being_called(): void
    {
        // placeholder() also calls thumbnail_buffer() directly, bypassing
        // decode() the same way resizeInternal() does.
        $engine = new VipsImageEngine(defaultQuality: 85, maxWidth: 50, maxHeight: 50);
        $this->expectException(\DomainException::class);
        $engine->placeholder($this->jpeg(200, 200));
    }

    public function test_resize_and_variants_still_work_within_the_configured_ceiling(): void
    {
        $engine = new VipsImageEngine(defaultQuality: 85, maxWidth: 500, maxHeight: 500);
        $out    = $engine->resize($this->jpeg(200, 200), 100);
        self::assertSame(100, $engine->dimensions($out)->width);
    }
}
