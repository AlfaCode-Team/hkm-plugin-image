<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Image;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Image\Domain\ValueObjects\Position;

#[CoversClass(Position::class)]
final class PositionTest extends TestCase
{
    /** @return iterable<string, array{0: Position, 1: int, 2: int}> */
    public static function offsetProvider(): iterable
    {
        // 100x100 canvas, 20x20 overlay, margin 5x5.
        yield 'TopLeft'     => [Position::TopLeft, 5, 5];
        yield 'Top'         => [Position::Top, 40, 5];
        yield 'TopRight'    => [Position::TopRight, 75, 5];
        yield 'Left'        => [Position::Left, 5, 40];
        yield 'Center'      => [Position::Center, 40, 40];
        yield 'Right'       => [Position::Right, 75, 40];
        yield 'BottomLeft'  => [Position::BottomLeft, 5, 75];
        yield 'Bottom'      => [Position::Bottom, 40, 75];
        yield 'BottomRight' => [Position::BottomRight, 75, 75];
    }

    #[DataProvider('offsetProvider')]
    public function test_offset_places_the_overlay_correctly(Position $position, int $expectedX, int $expectedY): void
    {
        [$x, $y] = $position->offset(100, 100, 20, 20, 5, 5);
        self::assertSame($expectedX, $x);
        self::assertSame($expectedY, $y);
    }

    public function test_center_ignores_margin_on_the_centered_axis(): void
    {
        [$x, $y] = Position::Center->offset(100, 100, 20, 20, 999, 999);
        self::assertSame(40, $x);
        self::assertSame(40, $y);
    }
}
