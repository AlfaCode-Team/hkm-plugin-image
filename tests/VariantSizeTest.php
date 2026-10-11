<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Image;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Image\Domain\ValueObjects\VariantSize;

#[CoversClass(VariantSize::class)]
final class VariantSizeTest extends TestCase
{
    public function test_a_width(): void
    {
        $size = VariantSize::from(480);
        self::assertSame([480, null], [$size->width, $size->height]);
    }

    public function test_a_digit_string_is_a_width(): void
    {
        self::assertSame(300, VariantSize::from(' 300 ')->width);
    }

    public function test_a_box(): void
    {
        $size = VariantSize::from([2048, 1536]);
        self::assertSame([2048, 1536], [$size->width, $size->height]);
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function malformed(): iterable
    {
        yield 'zero'          => [0];
        yield 'negative'      => [-5];
        yield 'float'         => [1.5];
        yield 'word'          => ['wide'];
        yield 'one-item box'  => [[100]];
        yield 'keyed box'     => [['w' => 1, 'h' => 2]];
        yield 'zero box side' => [[100, 0]];
    }

    #[DataProvider('malformed')]
    public function test_anything_else_is_refused(mixed $preset): void
    {
        $this->expectException(\DomainException::class);

        VariantSize::from($preset);
    }

    public function test_the_env_spec_reads_boxes(): void
    {
        self::assertSame(
            ['thumb' => 160, 'full' => [2048, 2048], 'card' => 480],
            image_variant_presets('thumb:160, full:2048x2048 ,card:480,broken:0x10,nameless'),
        );
    }
}
