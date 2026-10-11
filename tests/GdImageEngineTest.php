<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Image;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Image\Domain\Support\ThumbHash;
use Plugins\Image\Domain\ValueObjects\ImageFormat;
use Plugins\Image\Domain\ValueObjects\Position;
use Plugins\Image\Domain\ValueObjects\ResizeMode;
use Plugins\Image\Infrastructure\Engines\GdImageEngine;

#[CoversClass(GdImageEngine::class)]
final class GdImageEngineTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('ext-gd is not loaded.');
        }
    }

    private function engine(int $quality = 85): GdImageEngine
    {
        return new GdImageEngine($quality);
    }

    /** Plain rectangle JPEG, no EXIF. */
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
        $img = imagecreatefromstring($bytes);
        self::assertNotFalse($img);
        $c = imagecolorat($img, $x, $y);
        return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
    }

    // ── inspection ───────────────────────────────────────────────────────────

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

    public function test_detect_format(): void
    {
        self::assertSame(ImageFormat::Jpeg, $this->engine()->detectFormat($this->jpeg(10, 10)));
    }

    // ── validate() ───────────────────────────────────────────────────────────

    public function test_validate_passes_a_conforming_image(): void
    {
        $this->engine()->validate($this->jpeg(100, 100), 1_000_000, ['image/jpeg']);
        $this->addToAssertionCount(1);
    }

    public function test_validate_rejects_oversized_upload(): void
    {
        $this->expectException(\DomainException::class);
        $this->engine()->validate($this->jpeg(100, 100), 10, ['image/jpeg']);
    }

    public function test_validate_rejects_disallowed_mime(): void
    {
        $this->expectException(\DomainException::class);
        $this->engine()->validate($this->jpeg(100, 100), 1_000_000, ['image/png']);
    }

    public function test_validate_rejects_oversized_dimensions(): void
    {
        $this->expectException(\DomainException::class);
        $this->engine()->validate($this->jpeg(800, 400), 1_000_000, ['image/jpeg'], maxWidth: 100);
    }

    // ── resize() ─────────────────────────────────────────────────────────────

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
        // source is 2:1 — contain within 200x200 -> 200x100, never 200x200
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
        // aspect-correct crop of a 50x50 source for a 500:300 (5:3) target box
        // is 50x30 — shipped at that native size rather than stretched to 500x300.
        self::assertSame(50, $dims->width);
        self::assertSame(30, $dims->height);
    }

    public function test_smart_mode_falls_back_to_cover_geometry(): void
    {
        // GD has no content-aware crop primitive — Smart must behave exactly
        // like Cover (same exact-fill, no-upscale geometry), not error out.
        $cover = $this->engine()->resize($this->jpeg(800, 400), 100, 100, ResizeMode::Cover);
        $smart = $this->engine()->resize($this->jpeg(800, 400), 100, 100, ResizeMode::Smart);
        self::assertEquals($this->engine()->dimensions($cover), $this->engine()->dimensions($smart));
    }

    public function test_resize_preserves_the_source_format(): void
    {
        $out = $this->engine()->resize($this->jpeg(100, 100), 50, 50);
        self::assertSame(ImageFormat::Jpeg, $this->engine()->detectFormat($out));
    }

    public function test_resize_preserves_png_alpha_transparency(): void
    {
        $out    = $this->engine()->resize($this->pngWithAlpha(200, 200), 50, 50, ResizeMode::Contain);
        $img    = imagecreatefromstring($out);
        self::assertNotFalse($img);
        $rgba   = imagecolorat($img, 0, 0);
        $alpha  = ($rgba >> 24) & 0x7F;
        self::assertSame(127, $alpha, 'corner pixel should still be fully transparent after resize');
    }

    // ── convert() ────────────────────────────────────────────────────────────

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

    // ── variants() ───────────────────────────────────────────────────────────

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

    // ── orient() ─────────────────────────────────────────────────────────────

    public function test_orient_is_a_no_op_without_exif(): void
    {
        $source = $this->jpeg(100, 50);
        self::assertSame($source, $this->engine()->orient($source));
    }

    public function test_orient_is_a_no_op_for_non_jpeg(): void
    {
        $png = $this->pngWithAlpha(20, 10);
        self::assertSame($png, $this->engine()->orient($png));
    }

    /**
     * Regression cover: EXIF orientations 5 (transpose) and 7 (transverse)
     * were originally implemented with their flip+rotate angle swapped,
     * producing a correctly-DIMENSIONED but wrongly-MIRRORED image with no
     * exception raised. Verified against Pillow's ImageOps.exif_transpose
     * composition (5 = FLIP_LEFT_RIGHT + ROTATE_90-CCW, 7 = FLIP_LEFT_RIGHT +
     * ROTATE_90-CW) by tracking where an asymmetric marker painted in the
     * source's top-left quadrant ends up after each orientation's transform.
     *
     * @return iterable<string, array{0:int,1:string}>
     */
    public static function orientationProvider(): iterable
    {
        yield 'flip horizontal'          => [2, 'TOP-RIGHT'];
        yield 'rotate 180'               => [3, 'BOTTOM-RIGHT'];
        yield 'flip vertical'            => [4, 'BOTTOM-LEFT'];
        yield 'transpose'                => [5, 'TOP-LEFT'];
        yield 'rotate 90 CW'             => [6, 'TOP-RIGHT'];
        yield 'transverse'               => [7, 'BOTTOM-RIGHT'];
        yield 'rotate 90 CCW (270 CW)'   => [8, 'BOTTOM-LEFT'];
    }

    #[DataProvider('orientationProvider')]
    public function test_apply_orientation_moves_the_marker_correctly(int $orientation, string $expectedQuadrant): void
    {
        $engine = $this->engine();
        $ref    = new \ReflectionClass($engine);
        $method = $ref->getMethod('applyOrientation');

        $w = 200;
        $h = 100;
        $src = imagecreatetruecolor($w, $h);
        imagefill($src, 0, 0, imagecolorallocate($src, 200, 0, 0));
        imagefilledrectangle($src, 0, 0, intdiv($w, 4), intdiv($h, 4), imagecolorallocate($src, 255, 255, 255));

        /** @var \GdImage $out */
        $out = $method->invoke($engine, $src, $orientation);
        $ow  = imagesx($out);
        $oh  = imagesy($out);

        $isWhite = static function (\GdImage $img, int $x, int $y): bool {
            $rgb = imagecolorat($img, $x, $y);
            return (($rgb >> 16) & 0xFF) > 200 && (($rgb >> 8) & 0xFF) > 200 && ($rgb & 0xFF) > 200;
        };

        $quadrants = [
            'TOP-LEFT'     => $isWhite($out, intdiv($ow, 8), intdiv($oh, 8)),
            'TOP-RIGHT'    => $isWhite($out, $ow - intdiv($ow, 8), intdiv($oh, 8)),
            'BOTTOM-LEFT'  => $isWhite($out, intdiv($ow, 8), $oh - intdiv($oh, 8)),
            'BOTTOM-RIGHT' => $isWhite($out, $ow - intdiv($ow, 8), $oh - intdiv($oh, 8)),
        ];

        self::assertTrue($quadrants[$expectedQuadrant], "orientation {$orientation}: expected marker at {$expectedQuadrant}, got " . json_encode($quadrants));
    }

    public function test_orient_swaps_dimensions_for_a_90_degree_correction(): void
    {
        // Orientation 6 needs a 90 CW correction — width/height must swap.
        $withExif = $this->jpegWithOrientation(20, 10, 6);

        $engine = $this->engine();
        $before = $engine->dimensions($withExif);
        self::assertSame(20, $before->width);
        self::assertSame(10, $before->height);

        $after = $engine->dimensions($engine->orient($withExif));
        self::assertSame(10, $after->width);
        self::assertSame(20, $after->height);
    }

    /** Hand-builds a minimal, real EXIF APP1 segment (big-endian, one IFD0 entry) and splices it after the JPEG SOI marker. */
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

    // ── stripMetadata() ──────────────────────────────────────────────────────

    public function test_strip_metadata_keeps_dimensions_and_format(): void
    {
        $source   = $this->jpeg(64, 32);
        $stripped = $this->engine()->stripMetadata($source);

        $dims = $this->engine()->dimensions($stripped);
        self::assertSame(64, $dims->width);
        self::assertSame(32, $dims->height);
        self::assertSame(ImageFormat::Jpeg, $this->engine()->detectFormat($stripped));
    }

    // ── crop() ───────────────────────────────────────────────────────────────

    public function test_crop_extracts_the_exact_rectangle(): void
    {
        $out  = $this->engine()->crop($this->jpeg(100, 100), 20, 20, 40, 40);
        $dims = $this->engine()->dimensions($out);
        self::assertSame(40, $dims->width);
        self::assertSame(40, $dims->height);
    }

    public function test_crop_clamps_an_out_of_range_rectangle(): void
    {
        $out  = $this->engine()->crop($this->jpeg(100, 100), 80, 80, 50, 50);
        $dims = $this->engine()->dimensions($out);
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

    public function test_pixelate_keeps_dimensions(): void
    {
        $dims = $this->engine()->dimensions($this->engine()->pixelate($this->jpeg(50, 50), 10));
        self::assertSame(50, $dims->width);
        self::assertSame(50, $dims->height);
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
        // Verified against raw imagefilter(): IMG_FILTER_CONTRAST is
        // inverted in GD (positive level REDUCES contrast) — this contract's
        // positive-contrast="more contrast" convention must hold regardless.
        $base = $this->jpegColor(20, 20, 100, 150, 200);
        [, , $b0] = $this->pixel($base, 10, 10);
        [, , $b1] = $this->pixel($this->engine()->adjustColor($base, contrast: 50), 10, 10);
        self::assertGreaterThan($b0, $b1); // 200 is above the 128 midpoint -> more contrast pushes it higher
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
        [$r1, $g1] = $this->pixel($this->engine()->adjustColor($base, saturation: 50), 10, 10);
        self::assertGreaterThan($r0 - $g0, $r1 - $g1); // red-vs-green gap should widen, not shrink
    }

    // ── watermarkImage() / watermarkText() ──────────────────────────────────

    public function test_watermark_image_blends_using_the_overlays_own_alpha(): void
    {
        $bg      = $this->jpegColor(100, 100, 255, 255, 255);
        $overlay = $this->pngColorAlpha(20, 20, 0, 0, 0, 128); // ~50% opaque black

        [$r] = $this->pixel($this->engine()->watermarkImage($bg, $overlay, Position::TopLeft, 100, 0, 0), 10, 10);
        self::assertGreaterThan(80, $r);
        self::assertLessThan(180, $r); // roughly half-blended, not pure white or pure black
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

    public function test_watermark_text_does_not_error(): void
    {
        $bg   = $this->jpegColor(100, 100, 255, 255, 255);
        $dims = $this->engine()->dimensions($this->engine()->watermarkText($bg, 'HELLO', Position::BottomRight, 16, '#FF0000'));
        self::assertSame(100, $dims->width);
        self::assertSame(100, $dims->height);
    }

    /**
     * Regression cover: the TTF baseline formula originally computed
     * `$y + $textH + $box[7]`, which placed the rendered glyph's top ~16px
     * ABOVE the intended position (verified by rendering and scanning for
     * the topmost non-background pixel). The fix is `$y - $box[7]`
     * (top + ascent, since imagettfbbox()'s upper-left y is negative).
     */
    public function test_watermark_text_ttf_baseline_lands_near_the_intended_top(): void
    {
        $font = '/usr/share/fonts/truetype/lato/Lato-Medium.ttf';
        if (!is_file($font)) {
            self::markTestSkipped('No TTF font available on this host to test against.');
        }

        $engine = new GdImageEngine(90, $font);
        $bg     = (function (): string {
            $img = imagecreatetruecolor(200, 100);
            imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
            ob_start();
            imagepng($img); // lossless — JPEG compression noise near-white pixels would false-positive the scan
            return (string) ob_get_clean();
        })();

        $out = $engine->watermarkText($bg, 'Hyj', Position::TopLeft, 20, '#000000', 100, 5, 5);
        $img = imagecreatefromstring($out);
        self::assertNotFalse($img);

        $topY = null;
        for ($y = 0; $y < 100 && $topY === null; $y++) {
            for ($x = 0; $x < 200; $x++) {
                $c = imagecolorat($img, $x, $y) & 0xFFFFFF;
                $r = ($c >> 16) & 0xFF;
                $g = ($c >> 8) & 0xFF;
                $b = $c & 0xFF;
                if ($r < 250 || $g < 250 || $b < 250) { // ignore near-white antialiasing fringe
                    $topY = $y;
                    break;
                }
            }
        }

        self::assertNotNull($topY, 'expected to find a rendered glyph pixel');
        self::assertEqualsWithDelta(5, $topY, 3.0, 'glyph top should land within a few px of the requested margin, not ~16px off');
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

    // ── decode() guard (structural counterpart to validate()) ──────────────────

    public function test_convert_rejects_oversized_dimensions_without_validate_ever_being_called(): void
    {
        // convert() never called validate() itself and still doesn't — the
        // point is decode() now enforces the engine's own configured
        // ceiling regardless, so skipping validate() no longer opens an
        // unbounded decompression-bomb path.
        $engine = new GdImageEngine(defaultQuality: 85, maxWidth: 50, maxHeight: 50);
        $this->expectException(\DomainException::class);
        $engine->convert($this->jpeg(200, 200), ImageFormat::Png);
    }

    public function test_convert_rejects_oversized_bytes_without_validate_ever_being_called(): void
    {
        $engine = new GdImageEngine(defaultQuality: 85, maxBytes: 100);
        $this->expectException(\DomainException::class);
        $engine->convert($this->jpeg(200, 200), ImageFormat::Png);
    }

    public function test_resize_and_variants_still_work_within_the_configured_ceiling(): void
    {
        $engine = new GdImageEngine(defaultQuality: 85, maxWidth: 500, maxHeight: 500);
        $out    = $engine->resize($this->jpeg(200, 200), 100);
        self::assertSame(100, $engine->dimensions($out)->width);
    }

    public function test_convert_rejects_a_gd_decodable_but_unsupported_format(): void
    {
        // BMP is a format GD's own imagecreatefromstring() happily decodes
        // (verified), but it is NOT one of this plugin's 5 supported
        // ImageFormat cases. Before this fix, convert() skipped even the
        // cheap detectFormat()/MIME-sniff gate every other method gets — it
        // went straight to decode() — so a BMP silently succeeded here
        // instead of being rejected as out of scope.
        $this->expectException(\DomainException::class);
        $this->engine()->convert($this->bmp(20, 10), ImageFormat::Png);
    }

    public function test_variants_rejects_a_gd_decodable_but_unsupported_format_with_explicit_target(): void
    {
        // Same gap as convert(), reached a different way: resizeInternal()'s
        // $format ?? detectFormat($contents) short-circuits detectFormat()
        // whenever variants() is called with an explicit target $format —
        // exactly the call shape this contract's own docs demonstrate.
        $this->expectException(\DomainException::class);
        $this->engine()->variants($this->bmp(20, 10), ['v' => 10], ImageFormat::Webp);
    }

    private function bmp(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 20, 30));
        ob_start();
        imagebmp($img);
        return (string) ob_get_clean();
    }

    // ── variants() orientation (uses the SOURCE format, not the requested target) ──

    public function test_variants_auto_orients_from_the_source_even_when_the_target_format_differs(): void
    {
        // Regression: variants() previously decided whether to even check
        // for EXIF orientation using the TARGET format ($format ?? …),
        // rather than the source bytes' own real format. Requesting a
        // non-JPEG target format on a real EXIF-rotated JPEG source
        // therefore silently skipped auto-orientation.
        //
        // Orientation 6 needs a 90 CW correction: a 200(w)x100(h) landscape
        // source becomes 100(w)x200(h) portrait once corrected. Cover mode
        // never upscales, so resizing the (corrected) portrait source to a
        // 50-wide box yields a 50x100 portrait variant; if orientation were
        // skipped, the still-landscape 200x100 source would instead yield a
        // 50x25 landscape variant.
        $withExif = $this->jpegWithOrientation(200, 100, 6);

        $variants = $this->engine()->variants($withExif, ['v' => 50], ImageFormat::Webp);

        self::assertSame(50, $variants['v']->width);
        self::assertSame(100, $variants['v']->height);
        self::assertGreaterThan($variants['v']->width, $variants['v']->height, 'expected a portrait variant — orientation was skipped');
        self::assertSame(ImageFormat::Webp, $variants['v']->format);
    }
}
