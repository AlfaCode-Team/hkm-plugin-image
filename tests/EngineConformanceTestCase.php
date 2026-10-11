<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Image;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Image\API\Contracts\ImageProcessorContract;
use Plugins\Image\Domain\ValueObjects\ImageFormat;
use Plugins\Image\Domain\ValueObjects\ResizeMode;

/**
 * The same promises, checked against every engine (one subclass each).
 *
 * Each case here is a defect that was found by running real files through
 * the engines, not by reading them:
 *
 * - a PNG-8 or GIF could not become WebP/AVIF on GD, and with display_errors
 *   on GD returned the warning TEXT as the image bytes;
 * - transparency turned black on the way to JPEG (every engine), and GD
 *   dropped a truecolor PNG's alpha through convert()/stripMetadata();
 * - convert(), stripMetadata(), crop() and the filters stripped the EXIF
 *   orientation without applying it, so phone photos came out sideways;
 * - Vips pixelate() crashed on an image smaller than its block.
 *
 * The fixtures are built here with GD (always present) except two that GD
 * cannot write: tests/fixtures/cmyk.jpg and tests/fixtures/display-p3.jpg.
 */
abstract class EngineConformanceTestCase extends TestCase
{
    abstract protected function engine(): ImageProcessorContract;

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** A PNG-8 (palette) image: transparent background, opaque red disc. */
    protected static function palettePng(): string
    {
        $img   = imagecreate(80, 80);
        $clear = imagecolorallocate($img, 0, 255, 0);
        imagecolortransparent($img, $clear);
        imagefill($img, 0, 0, $clear);
        imagefilledellipse($img, 40, 40, 50, 50, imagecolorallocate($img, 220, 0, 0));

        return self::capture(static fn () => imagepng($img));
    }

    protected static function gif(): string
    {
        $img = imagecreate(60, 40);
        imagecolorallocate($img, 0, 0, 200);
        imagefilledrectangle($img, 10, 10, 30, 30, imagecolorallocate($img, 255, 255, 0));

        return self::capture(static fn () => imagegif($img));
    }

    /** Truecolor PNG with a real alpha channel: clear corners, half-opaque centre. */
    protected static function rgbaPng(): string
    {
        $img = imagecreatetruecolor(80, 80);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagefilledellipse($img, 40, 40, 50, 50, imagecolorallocatealpha($img, 0, 90, 255, 63));

        return self::capture(static fn () => imagepng($img));
    }

    /**
     * A 120x60 landscape JPEG with a white marker in its top-left corner,
     * tagged EXIF orientation 6. Upright it is 60x120 PORTRAIT with the
     * marker in the TOP-RIGHT corner.
     */
    protected static function sidewaysJpeg(): string
    {
        $img = imagecreatetruecolor(120, 60);
        imagefill($img, 0, 0, imagecolorallocate($img, 30, 30, 160));
        imagefilledrectangle($img, 0, 0, 24, 12, imagecolorallocate($img, 255, 255, 255));

        return self::withOrientation(self::capture(static fn () => imagejpeg($img, null, 95)), 6);
    }

    protected static function plainJpeg(int $w = 90, int $h = 60): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));

        return self::capture(static fn () => imagejpeg($img, null, 90));
    }

    protected static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
    }

    // ── format conversion ────────────────────────────────────────────────────

    public function test_a_palette_png_converts_to_webp_and_keeps_its_transparency(): void
    {
        $webp = $this->withDisplayErrors(fn () => $this->engine()->convert(self::palettePng(), ImageFormat::Webp));

        self::assertSame('image/webp', getimagesizefromstring($webp)['mime']);
        self::assertGreaterThan(120, $this->pixel($webp, 2, 2)[3], 'corner stays transparent');
        self::assertLessThan(10, $this->pixel($webp, 40, 40)[3], 'disc stays opaque');
    }

    public function test_a_gif_converts_to_webp(): void
    {
        $webp = $this->withDisplayErrors(fn () => $this->engine()->convert(self::gif(), ImageFormat::Webp));

        self::assertSame('image/webp', getimagesizefromstring($webp)['mime']);
        [$r, $g, $b] = $this->pixel($webp, 20, 20);
        self::assertTrue($r > 200 && $g > 200 && $b < 80, "yellow square kept, got rgb($r,$g,$b)");
    }

    public function test_transparency_becomes_white_not_black_in_a_jpeg(): void
    {
        foreach (['palette' => self::palettePng(), 'rgba' => self::rgbaPng()] as $label => $png) {
            $jpeg = $this->engine()->convert($png, ImageFormat::Jpeg);
            [$r, $g, $b] = $this->pixel($jpeg, 2, 2);
            self::assertGreaterThan(235, min($r, $g, $b), "$label: transparent corner became rgb($r,$g,$b)");
        }
    }

    public function test_a_truecolor_png_keeps_its_alpha_through_convert_and_strip(): void
    {
        $engine = $this->engine();

        foreach ([
            'convert:png'  => $engine->convert(self::rgbaPng(), ImageFormat::Png),
            'convert:webp' => $engine->convert(self::rgbaPng(), ImageFormat::Webp),
            'strip'        => $engine->stripMetadata(self::rgbaPng()),
        ] as $label => $out) {
            self::assertGreaterThan(120, $this->pixel($out, 2, 2)[3], "$label: corner stays transparent");
        }
    }

    public function test_a_cmyk_jpeg_keeps_its_colours(): void
    {
        $webp = $this->engine()->convert(self::fixture('cmyk.jpg'), ImageFormat::Webp);

        [$r, , $b] = $this->pixel($webp, 24, 1);
        self::assertTrue($r > 180 && $b < 90, "top of the red→blue gradient should be red, got r$r b$b");
    }

    // ── orientation ──────────────────────────────────────────────────────────

    /** @return iterable<string, array{0: string}> */
    public static function reencodingOperations(): iterable
    {
        foreach (['orient', 'convert', 'strip', 'resize', 'variants', 'crop', 'blur', 'sharpen', 'grayscale'] as $op) {
            yield $op => [$op];
        }
    }

    #[DataProvider('reencodingOperations')]
    public function test_every_operation_returns_upright_pixels(string $op): void
    {
        $engine = $this->engine();
        $src    = self::sidewaysJpeg();

        $out = match ($op) {
            'orient'    => $engine->orient($src),
            'convert'   => $engine->convert($src, ImageFormat::Png),
            'strip'     => $engine->stripMetadata($src),
            'resize'    => $engine->resize($src, 60, 120, ResizeMode::Contain),
            'variants'  => $engine->variants($src, ['full' => [200, 200]], ImageFormat::Png, null, ResizeMode::Contain)['full']->contents,
            'crop'      => $engine->crop($src, 0, 0, 1000, 1000),
            'blur'      => $engine->blur($src, 0.5),
            'sharpen'   => $engine->sharpen($src),
            'grayscale' => $engine->grayscale($src),
        };

        [$w, $h] = getimagesizefromstring($out);
        self::assertSame([60, 120], [$w, $h], "$op: upright is portrait");
        [$r, $g, $b] = $this->pixel($out, $w - 4, 3);
        self::assertGreaterThan(200, min($r, $g, $b), "$op: marker belongs top-right once upright, got rgb($r,$g,$b)");
    }

    public function test_orient_returns_an_upright_image_untouched(): void
    {
        $jpeg = self::plainJpeg();

        self::assertSame($jpeg, $this->engine()->orient($jpeg));
    }

    // ── variants: boxes ──────────────────────────────────────────────────────

    public function test_a_box_preset_fits_whichever_way_the_picture_is_turned(): void
    {
        $engine = $this->engine();

        $landscape = $engine->variants(self::plainJpeg(300, 200), ['box' => [100, 100]], ImageFormat::Webp, null, ResizeMode::Contain)['box'];
        self::assertSame([100, 67], [$landscape->width, $landscape->height]);

        // 120x60 stored, 60x120 upright: the long side is the height.
        $portrait = $engine->variants(self::sidewaysJpeg(), ['box' => [50, 50]], ImageFormat::Webp, null, ResizeMode::Contain)['box'];
        self::assertSame([25, 50], [$portrait->width, $portrait->height]);
    }

    public function test_presets_mix_widths_and_boxes_and_keep_their_order(): void
    {
        $out = $this->engine()->variants(
            self::plainJpeg(300, 200),
            ['full' => [150, 150], 'small' => 60, 'legacy' => '90'],
            ImageFormat::Webp,
            null,
            ResizeMode::Contain,
        );

        self::assertSame(['full', 'small', 'legacy'], array_keys($out));
        self::assertSame([150, 100], [$out['full']->width, $out['full']->height]);
        self::assertSame(60, $out['small']->width);
        self::assertSame(90, $out['legacy']->width, 'a digit-string width still works');
    }

    public function test_a_malformed_preset_is_refused(): void
    {
        $this->expectException(\DomainException::class);

        $this->engine()->variants(self::plainJpeg(), ['bad' => [100]]);
    }

    // ── robustness ───────────────────────────────────────────────────────────

    public function test_pixelate_copes_with_an_image_smaller_than_its_block(): void
    {
        $img = imagecreatetruecolor(1, 1);
        $png = self::capture(static fn () => imagepng($img));

        self::assertSame([1, 1], array_slice(getimagesizefromstring($this->engine()->pixelate($png, 8)), 0, 2));
    }

    public function test_bytes_that_are_not_an_image_are_refused_not_converted(): void
    {
        $this->expectException(\DomainException::class);

        $this->engine()->convert('<html><body>Not found</body></html>', ImageFormat::Webp);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{0: int, 1: int, 2: int, 3: int} r, g, b, GD alpha (0 opaque … 127 clear) */
    protected function pixel(string $bytes, int $x, int $y): array
    {
        $img = imagecreatefromstring($bytes);
        self::assertInstanceOf(\GdImage::class, $img, 'output decodes');
        imagepalettetotruecolor($img);
        $c = imagecolorat($img, min($x, imagesx($img) - 1), min($y, imagesy($img) - 1));

        return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF, ($c >> 24) & 0x7F];
    }

    /**
     * Run with display_errors ON — the setting under which GD used to hand
     * back a printed warning as the image.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    protected function withDisplayErrors(callable $fn): mixed
    {
        $previous = ini_set('display_errors', '1');
        try {
            return $fn();
        } finally {
            ini_set('display_errors', (string) $previous);
        }
    }

    private static function capture(callable $write): string
    {
        ob_start();
        $write();

        return (string) ob_get_clean();
    }

    /** Splices a minimal big-endian EXIF APP1 (one IFD0 Orientation entry) after the SOI marker. */
    private static function withOrientation(string $jpeg, int $orientation): string
    {
        $tiff    = "MM\x00\x2A" . pack('N', 8) . pack('n', 1)
                 . pack('n', 0x0112) . pack('n', 3) . pack('N', 1) . pack('n', $orientation) . "\x00\x00"
                 . pack('N', 0);
        $payload = "Exif\x00\x00" . $tiff;

        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
    }
}
