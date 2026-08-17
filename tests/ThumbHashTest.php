<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Image;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Image\Domain\Support\ThumbHash;

/**
 * A line-for-line port of the reference JavaScript algorithm — verified by
 * round-tripping known solid/split-color test images through encode() then
 * averageColor() and comparing against the expected average, including the
 * 4-bit alpha quantization (0.5 -> round(15*0.5)/15 = 0.5333...) and the
 * fully-transparent (avgA=0) division-by-zero guard.
 */
#[CoversClass(ThumbHash::class)]
final class ThumbHashTest extends TestCase
{
    /** @return list<int> flat RGBA bytes */
    private function solidRgba(int $w, int $h, int $r, int $g, int $b, int $a = 255): array
    {
        $rgba = [];
        for ($i = 0; $i < $w * $h; $i++) {
            $rgba[] = $r;
            $rgba[] = $g;
            $rgba[] = $b;
            $rgba[] = $a;
        }
        return $rgba;
    }

    private function assertAverageNear(string $hash, float $r, float $g, float $b, float $a, float $tolerance = 0.08): void
    {
        $avg = ThumbHash::averageColor($hash);
        self::assertEqualsWithDelta($r, $avg['r'], $tolerance, 'r channel');
        self::assertEqualsWithDelta($g, $avg['g'], $tolerance, 'g channel');
        self::assertEqualsWithDelta($b, $avg['b'], $tolerance, 'b channel');
        self::assertEqualsWithDelta($a, $avg['a'], $tolerance, 'a channel');
    }

    public function test_solid_red_round_trips(): void
    {
        $hash = ThumbHash::encode(32, 32, $this->solidRgba(32, 32, 255, 0, 0));
        $this->assertAverageNear($hash, 1.0, 0.0, 0.0, 1.0);
    }

    public function test_solid_blue_round_trips(): void
    {
        $hash = ThumbHash::encode(32, 32, $this->solidRgba(32, 32, 0, 0, 255));
        $this->assertAverageNear($hash, 0.0, 0.0, 1.0, 1.0);
    }

    public function test_solid_gray_round_trips(): void
    {
        $hash = ThumbHash::encode(20, 20, $this->solidRgba(20, 20, 128, 128, 128));
        $this->assertAverageNear($hash, 0.5, 0.5, 0.5, 1.0);
    }

    public function test_landscape_image_round_trips(): void
    {
        $hash = ThumbHash::encode(80, 20, $this->solidRgba(80, 20, 0, 255, 0));
        $this->assertAverageNear($hash, 0.0, 1.0, 0.0, 1.0);
    }

    public function test_semi_transparent_alpha_quantizes_to_nearest_15th(): void
    {
        $hash = ThumbHash::encode(16, 16, $this->solidRgba(16, 16, 255, 255, 255, 128));
        $avg  = ThumbHash::averageColor($hash);
        // Alpha is stored in 4 bits (0-15): round(15*0.5)/15 = 8/15 = 0.5333...
        // — assert this one exactly, since it's the specific quantization
        // behavior under test; the color channels tolerate DCT rounding noise.
        self::assertEqualsWithDelta(8 / 15, $avg['a'], 0.001);
        $this->assertAverageNear($hash, 1.0, 1.0, 1.0, $avg['a'], 0.03);
    }

    public function test_fully_transparent_image_does_not_divide_by_zero(): void
    {
        $hash = ThumbHash::encode(16, 16, $this->solidRgba(16, 16, 10, 20, 30, 0));
        $this->assertAverageNear($hash, 0.0, 0.0, 0.0, 0.0);
    }

    public function test_100x100_boundary_does_not_throw(): void
    {
        $hash = ThumbHash::encode(100, 100, $this->solidRgba(100, 100, 1, 2, 3));
        self::assertNotSame('', $hash);
    }

    public function test_101_width_throws(): void
    {
        $this->expectException(\DomainException::class);
        ThumbHash::encode(101, 50, array_fill(0, 101 * 50 * 4, 0));
    }

    public function test_101_height_throws(): void
    {
        $this->expectException(\DomainException::class);
        ThumbHash::encode(50, 101, array_fill(0, 50 * 101 * 4, 0));
    }

    public function test_zero_dimension_throws(): void
    {
        $this->expectException(\DomainException::class);
        ThumbHash::encode(0, 10, []);
    }

    public function test_average_color_rejects_a_too_short_hash(): void
    {
        $this->expectException(\DomainException::class);
        ThumbHash::averageColor("\x00\x00");
    }

    /** @return iterable<string, array{0:int,1:int,2:int}> */
    public static function halfSplitProvider(): iterable
    {
        yield 'red/green split' => [255, 0, 0];
    }

    #[DataProvider('halfSplitProvider')]
    public function test_half_split_averages_to_the_midpoint(int $r, int $g, int $b): void
    {
        $w = 40;
        $h = 20;
        $rgba = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ($x < $w / 2) {
                    $rgba[] = $r; $rgba[] = $g; $rgba[] = $b; $rgba[] = 255;
                } else {
                    $rgba[] = 0; $rgba[] = 255; $rgba[] = 0; $rgba[] = 255;
                }
            }
        }
        $hash = ThumbHash::encode($w, $h, $rgba);
        $this->assertAverageNear($hash, ($r + 0) / 2 / 255, ($g + 255) / 2 / 255, ($b + 0) / 2 / 255, 1.0, 0.15);
    }
}
