<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Image;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Image\Domain\Support\ThumbHash;
use Plugins\Image\Domain\ValueObjects\ImageFormat;
use Plugins\Image\Domain\ValueObjects\Position;
use Plugins\Image\Domain\ValueObjects\ResizeMode;
use Plugins\Image\Infrastructure\Engines\ImagickImageEngine;

/**
 * Self-skips wherever ext-imagick isn't installed. Where this suite DOES
 * run, every assertion here was independently confirmed against a real
 * ext-imagick build during development — this class was originally authored
 * without the extension available (documented as unverified), then upgraded
 * to real pixel-level assertions once ext-imagick became available on this
 * host. That upgrade caught a real bug: watermarkText()'s baseline formula
 * used queryFontMetrics()'s 'ascender' (a font-wide nominal metric that
 * overshoots a specific string's actual rendered extent), landing text ~7px
 * lower than intended; fixed to use 'boundingBox'.'y2' instead — see that
 * method's docblock.
 */
#[CoversClass(ImagickImageEngine::class)]
final class ImagickImageEngineTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('imagick')) {
            self::markTestSkipped('ext-imagick is not loaded — ImagickImageEngine is untested in this environment.');
        }
    }

    private function engine(int $quality = 85): ImagickImageEngine
    {
        return new ImagickImageEngine($quality);
    }

    private function jpeg(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 20, 20));
        ob_start();
        imagejpeg($img, null, 90);
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

    /** @return array{0:int,1:int,2:int} */
    private function pixel(string $bytes, int $x, int $y): array
    {
        $img = new \Imagick();
        $img->readImageBlob($bytes);
        $color = $img->getImagePixelColor($x, $y)->getColor();
        $img->destroy();
        return [$color['r'], $color['g'], $color['b']];
    }

    /** Scans top-down/left-right for the first pixel whose red channel is meaningfully below white. */
    private function topmostDarkPixelY(string $bytes, int $maxW, int $maxH, int $threshold = 200): ?int
    {
        $img = new \Imagick();
        $img->readImageBlob($bytes);
        $found = null;
        for ($y = 0; $y < $maxH && $found === null; $y++) {
            for ($x = 0; $x < $maxW; $x++) {
                $c = $img->getImagePixelColor($x, $y)->getColor();
                if ($c['r'] < $threshold) {
                    $found = $y;
                    break;
                }
            }
        }
        $img->destroy();
        return $found;
    }

    // ── inspection / resize ──────────────────────────────────────────────────

    public function test_dimensions_reads_real_size(): void
    {
        $dims = $this->engine()->dimensions($this->jpeg(800, 400));
        self::assertSame(800, $dims->width);
        self::assertSame(400, $dims->height);
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

    public function test_stretch_forces_the_exact_size(): void
    {
        $out  = $this->engine()->resize($this->jpeg(800, 400), 50, 300, ResizeMode::Stretch);
        $dims = $this->engine()->dimensions($out);
        self::assertSame(50, $dims->width);
        self::assertSame(300, $dims->height);
    }

    public function test_smart_mode_falls_back_to_cover_geometry(): void
    {
        $cover = $this->engine()->resize($this->jpeg(800, 400), 100, 100, ResizeMode::Cover);
        $smart = $this->engine()->resize($this->jpeg(800, 400), 100, 100, ResizeMode::Smart);
        self::assertEquals($this->engine()->dimensions($cover), $this->engine()->dimensions($smart));
    }

    public function test_convert_round_trips_through_webp(): void
    {
        $out = $this->engine()->convert($this->jpeg(64, 64), ImageFormat::Webp, 80);
        self::assertSame(ImageFormat::Webp, $this->engine()->detectFormat($out));
    }

    public function test_variants_produces_every_preset(): void
    {
        $variants = $this->engine()->variants(
            $this->jpeg(800, 400),
            ['thumbnail' => 150, 'medium' => 300],
            ImageFormat::Webp,
        );

        self::assertSame(['thumbnail', 'medium'], array_keys($variants));
        self::assertSame(150, $variants['thumbnail']->width);
    }

    public function test_strip_metadata_keeps_dimensions(): void
    {
        $out  = $this->engine()->stripMetadata($this->jpeg(64, 32));
        $dims = $this->engine()->dimensions($out);
        self::assertSame(64, $dims->width);
        self::assertSame(32, $dims->height);
    }

    // ── orient() ─────────────────────────────────────────────────────────────

    public function test_orient_is_a_no_op_without_exif(): void
    {
        $source = $this->jpeg(100, 50);
        $out    = $this->engine()->orient($source);
        self::assertNotSame('', $out);
    }

    public function test_orient_swaps_dimensions_for_a_90_degree_correction(): void
    {
        $withExif = $this->jpegWithOrientation(20, 10, 6);

        $before = $this->engine()->dimensions($withExif);
        self::assertSame(20, $before->width);
        self::assertSame(10, $before->height);

        $after = $this->engine()->dimensions($this->engine()->orient($withExif));
        self::assertSame(10, $after->width);
        self::assertSame(20, $after->height);
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

    // ── crop() ───────────────────────────────────────────────────────────────

    public function test_crop_extracts_the_exact_rectangle(): void
    {
        $dims = $this->engine()->dimensions($this->engine()->crop($this->jpeg(100, 100), 20, 20, 40, 40));
        self::assertSame(40, $dims->width);
        self::assertSame(40, $dims->height);
    }

    public function test_crop_uses_the_width_height_x_y_argument_order_correctly(): void
    {
        // A distinguishing image: black canvas with a white block in the
        // bottom-right — if cropImage()'s argument order were wrong (x,y
        // swapped with width,height), this crop would miss the white block.
        $img = imagecreatetruecolor(100, 50);
        imagefill($img, 0, 0, imagecolorallocate($img, 0, 0, 0));
        imagefilledrectangle($img, 60, 30, 99, 49, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagejpeg($img, null, 95);
        $asym = (string) ob_get_clean();

        $cropped = $this->engine()->crop($asym, 60, 30, 40, 20);
        $dims    = $this->engine()->dimensions($cropped);
        self::assertSame(40, $dims->width);
        self::assertSame(20, $dims->height);

        [$r] = $this->pixel($cropped, 5, 5);
        self::assertGreaterThan(200, $r); // should be inside the white block
    }

    public function test_crop_clamps_an_out_of_range_rectangle(): void
    {
        $dims = $this->engine()->dimensions($this->engine()->crop($this->jpeg(100, 100), 80, 80, 50, 50));
        self::assertSame(20, $dims->width);
        self::assertSame(20, $dims->height);
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

    public function test_pixelate_produces_genuinely_blocky_output(): void
    {
        $img = imagecreatetruecolor(40, 40);
        for ($y = 0; $y < 40; $y++) {
            for ($x = 0; $x < 40; $x++) {
                imagesetpixel($img, $x, $y, imagecolorallocate($img, ($x * 6) % 256, ($y * 6) % 256, 100));
            }
        }
        ob_start();
        imagepng($img);
        $gradient = (string) ob_get_clean();

        $pixelated = $this->engine()->pixelate($gradient, 8);
        [$r1] = $this->pixel($pixelated, 0, 0);
        [$r2] = $this->pixel($pixelated, 5, 5);  // same 8px block as (0,0)
        [$r3] = $this->pixel($pixelated, 20, 20); // a different block

        self::assertSame($r1, $r2);
        self::assertNotSame($r1, $r3);
    }

    // ── grayscale() / adjustColor() ─────────────────────────────────────────

    public function test_grayscale_equalises_channels(): void
    {
        [$r, $g, $b] = $this->pixel($this->engine()->grayscale($this->jpegColor(20, 20, 100, 150, 200)), 10, 10);
        self::assertSame($r, $g);
        self::assertSame($g, $b);
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
        // brightnessContrastImage() is NOT inverted the way GD's raw
        // IMG_FILTER_CONTRAST is — positive really does mean more contrast.
        $base = $this->jpegColor(20, 20, 100, 150, 200);
        [, , $b0] = $this->pixel($base, 10, 10);
        [, , $b1] = $this->pixel($this->engine()->adjustColor($base, contrast: 50), 10, 10);
        self::assertGreaterThan($b0, $b1);
    }

    public function test_full_desaturation_equalises_channels(): void
    {
        [$r, $g, $b] = $this->pixel($this->engine()->adjustColor($this->jpegColor(20, 20, 100, 150, 200), saturation: -100), 10, 10);
        self::assertSame($r, $g);
        self::assertSame($g, $b);
    }

    public function test_saturation_boost_widens_the_spread(): void
    {
        $base = $this->jpegColor(20, 20, 200, 80, 80);
        [$r0, $g0] = $this->pixel($base, 10, 10);
        [$r1, $g1] = $this->pixel($this->engine()->adjustColor($base, saturation: 60), 10, 10);
        self::assertGreaterThan($r0 - $g0, $r1 - $g1);
    }

    // ── watermarkImage() / watermarkText() ──────────────────────────────────

    public function test_watermark_image_blends_using_the_overlays_own_alpha(): void
    {
        $bg      = $this->jpegColor(100, 100, 255, 255, 255);
        $overlay = $this->pngColorAlpha(20, 20, 0, 0, 0, 128); // ~50% opaque black

        [$r] = $this->pixel($this->engine()->watermarkImage($bg, $overlay, Position::TopLeft, 100, 0, 0), 10, 10);
        self::assertGreaterThan(80, $r);
        self::assertLessThan(180, $r);
    }

    public function test_watermark_image_zero_opacity_leaves_the_background_untouched(): void
    {
        $bg      = $this->jpegColor(100, 100, 255, 255, 255);
        $overlay = $this->pngColorAlpha(20, 20, 0, 0, 0, 255); // fully opaque black

        [$r, $g, $b] = $this->pixel($this->engine()->watermarkImage($bg, $overlay, Position::TopLeft, 0, 0, 0), 10, 10);
        self::assertGreaterThan(250, $r);
        self::assertGreaterThan(250, $g);
        self::assertGreaterThan(250, $b);
    }

    public function test_watermark_image_positions_consistently_across_gravities(): void
    {
        $bg      = $this->jpegColor(100, 100, 255, 255, 255);
        $overlay = $this->pngColorAlpha(20, 20, 0, 0, 0, 128);

        $samples = [];
        foreach ([Position::TopLeft, Position::Center, Position::BottomRight] as $pos) {
            $wm = $this->engine()->watermarkImage($bg, $overlay, $pos, 100, 5, 5);
            [$x, $y] = $pos->offset(100, 100, 20, 20, 5, 5);
            [$r] = $this->pixel($wm, $x + 10, $y + 10);
            $samples[] = $r;
        }

        // Same blend everywhere — proves Position::offset() correctly
        // targets each gravity's overlay center, not a fixed spot.
        self::assertEqualsWithDelta($samples[0], $samples[1], 2);
        self::assertEqualsWithDelta($samples[1], $samples[2], 2);
    }

    public function test_watermark_text_does_not_error(): void
    {
        if (\Imagick::queryFonts('*') === []) {
            self::markTestSkipped('ImageMagick sees no font on this host.');
        }
        $bg   = $this->jpegColor(100, 100, 255, 255, 255);
        $dims = $this->engine()->dimensions($this->engine()->watermarkText($bg, 'HI', Position::BottomRight, 14, '#FFFFFF'));
        self::assertSame(100, $dims->width);
    }

    /**
     * Regression cover: queryFontMetrics()'s 'ascender' overshot this
     * string's real extent, landing text ~7px below the intended top.
     * 'boundingBox'.'y2' lands within a couple px across every string this
     * was checked against ("Hyj", "HELLO", "a", "gW").
     */
    public function test_watermark_text_baseline_lands_near_the_intended_top(): void
    {
        if (\Imagick::queryFonts('*') === []) {
            self::markTestSkipped('ImageMagick sees no font on this host.');
        }
        $bg = $this->jpegColor(100, 100, 255, 255, 255);

        $out  = $this->engine()->watermarkText($bg, 'Hyj', Position::TopLeft, 20, '#000000', 100, 5, 5);
        $topY = $this->topmostDarkPixelY($out, 100, 100);

        self::assertNotNull($topY);
        self::assertEqualsWithDelta(5, $topY, 3.0, 'glyph top should land within a few px of the requested margin, not ~7px off');
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

    /** Regression: placeholder() now decodes JPEG via a 'jpeg:size' shrink-on-load hint — must still produce a correct color. */
    public function test_placeholder_on_a_larger_source_still_decodes_correctly(): void
    {
        $source = $this->jpegColor(1200, 800, 30, 200, 60);
        $hash   = base64_decode($this->engine()->placeholder($source));
        $avg    = ThumbHash::averageColor($hash);

        self::assertEqualsWithDelta(30 / 255, $avg['r'], 0.08);
        self::assertEqualsWithDelta(200 / 255, $avg['g'], 0.08);
        self::assertEqualsWithDelta(60 / 255, $avg['b'], 0.08);
    }

    // ── decode() guard (structural counterpart to validate()) ──────────────────

    public function test_convert_rejects_oversized_dimensions_without_validate_ever_being_called(): void
    {
        $engine = new ImagickImageEngine(defaultQuality: 85, maxWidth: 50, maxHeight: 50);
        $this->expectException(\DomainException::class);
        $engine->convert($this->jpeg(200, 200), ImageFormat::Png);
    }

    public function test_convert_rejects_oversized_bytes_without_validate_ever_being_called(): void
    {
        $engine = new ImagickImageEngine(defaultQuality: 85, maxBytes: 100);
        $this->expectException(\DomainException::class);
        $engine->convert($this->jpeg(200, 200), ImageFormat::Png);
    }

    public function test_resize_and_variants_still_work_within_the_configured_ceiling(): void
    {
        $engine = new ImagickImageEngine(defaultQuality: 85, maxWidth: 500, maxHeight: 500);
        $out    = $engine->resize($this->jpeg(200, 200), 100);
        self::assertSame(100, $engine->dimensions($out)->width);
    }

    public function test_convert_rejects_an_imagick_decodable_but_unsupported_format(): void
    {
        // BMP is a format Imagick happily decodes, but it is NOT one of this
        // plugin's 5 supported ImageFormat cases. Before this fix, convert()
        // skipped even the cheap detectFormat()/MIME-sniff gate every other
        // method gets — it went straight to decode() — so a BMP (or,
        // depending on the host's ImageMagick policy.xml, something far more
        // dangerous) could reach readImageBlob() completely unfiltered.
        $img = new \Imagick();
        $img->newImage(20, 10, new \ImagickPixel('red'), 'bmp');
        $bmp = $img->getImageBlob();
        $img->destroy();

        $this->expectException(\DomainException::class);
        $this->engine()->convert($bmp, ImageFormat::Png);
    }

    // ── Imagick resource limits (applied once per engine construction) ─────────

    public function test_construction_applies_configured_width_height_area_resource_limits(): void
    {
        // Imagick::setResourceLimit() is PROCESS-GLOBAL (verified during
        // development), so this test — and the next one — restore a
        // generous default afterward rather than leaving a small ceiling
        // behind for whatever test runs next in this PHP process.
        try {
            new ImagickImageEngine(defaultQuality: 85, maxWidth: 321, maxHeight: 654);

            // getResourceLimit() returns a float regardless of what type
            // was passed to setResourceLimit() — verified empirically.
            self::assertSame(321.0, \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_WIDTH));
            self::assertSame(654.0, \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_HEIGHT));
            self::assertSame((float) (321 * 654), \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_AREA));
        } finally {
            new ImagickImageEngine();
        }
    }

    public function test_resource_limits_reject_a_decode_over_the_configured_ceiling_even_bypassing_this_plugin_entirely(): void
    {
        // Belt-and-suspenders, proven precisely: this plugin's OWN
        // decode()/guardDecodable() is deliberately bypassed here (a raw
        // \Imagick, not this engine) to prove the SEPARATE backstop
        // applyResourceLimits() installs — ImageMagick's own resource
        // limiter refuses the decode independently, because the limit it
        // set is PROCESS-GLOBAL (verified during development: a limit set
        // via one Imagick instance rejected a decode on a completely
        // separate one with no limits of its own).
        try {
            new ImagickImageEngine(defaultQuality: 85, maxWidth: 50, maxHeight: 50);

            $raw = new \Imagick();
            try {
                $this->expectException(\ImagickException::class);
                $raw->readImageBlob($this->jpeg(200, 200));
            } finally {
                $raw->destroy();
            }
        } finally {
            new ImagickImageEngine();
        }
    }
}
