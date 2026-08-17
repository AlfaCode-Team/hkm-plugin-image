<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Image;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Image\Domain\Rules\AllowedMimeRule;
use Plugins\Image\Domain\Rules\MaxBytesRule;
use Plugins\Image\Domain\Rules\MaxDimensionsRule;
use Plugins\Image\Domain\ValueObjects\Dimensions;
use Plugins\Image\Domain\ValueObjects\ImageFormat;

#[CoversClass(MaxBytesRule::class)]
#[CoversClass(AllowedMimeRule::class)]
#[CoversClass(MaxDimensionsRule::class)]
#[CoversClass(Dimensions::class)]
#[CoversClass(ImageFormat::class)]
final class DomainRulesTest extends TestCase
{
    public function test_max_bytes_rule_allows_at_the_limit(): void
    {
        MaxBytesRule::check(100, 100);
        $this->addToAssertionCount(1);
    }

    public function test_max_bytes_rule_rejects_over_the_limit(): void
    {
        $this->expectException(\DomainException::class);
        MaxBytesRule::check(101, 100);
    }

    public function test_allowed_mime_rule_is_case_insensitive(): void
    {
        AllowedMimeRule::check('IMAGE/JPEG', ['image/jpeg']);
        $this->addToAssertionCount(1);
    }

    public function test_allowed_mime_rule_rejects_unlisted_mime(): void
    {
        $this->expectException(\DomainException::class);
        AllowedMimeRule::check('image/png', ['image/jpeg']);
    }

    public function test_allowed_mime_rule_with_empty_list_allows_anything(): void
    {
        AllowedMimeRule::check('image/anything', []);
        $this->addToAssertionCount(1);
    }

    public function test_max_dimensions_rule_allows_within_bounds(): void
    {
        MaxDimensionsRule::check(new Dimensions(800, 600), 1000, 1000);
        $this->addToAssertionCount(1);
    }

    public function test_max_dimensions_rule_rejects_over_width(): void
    {
        $this->expectException(\DomainException::class);
        MaxDimensionsRule::check(new Dimensions(1200, 600), 1000, 1000);
    }

    public function test_max_dimensions_rule_rejects_over_height(): void
    {
        $this->expectException(\DomainException::class);
        MaxDimensionsRule::check(new Dimensions(800, 1200), 1000, 1000);
    }

    public function test_max_dimensions_rule_with_null_bounds_allows_anything(): void
    {
        MaxDimensionsRule::check(new Dimensions(99999, 99999), null, null);
        $this->addToAssertionCount(1);
    }

    public function test_dimensions_rejects_zero_or_negative(): void
    {
        $this->expectException(\DomainException::class);
        new Dimensions(0, 10);
    }

    public function test_dimensions_aspect_ratio(): void
    {
        self::assertSame(2.0, (new Dimensions(800, 400))->aspectRatio());
    }

    public function test_image_format_from_mime(): void
    {
        self::assertSame(ImageFormat::Jpeg, ImageFormat::fromMime('image/jpeg'));
        self::assertSame(ImageFormat::Jpeg, ImageFormat::fromMime('IMAGE/JPG'));
        self::assertSame(ImageFormat::Webp, ImageFormat::fromMime('image/webp'));
    }

    public function test_image_format_from_unknown_mime_throws(): void
    {
        $this->expectException(\DomainException::class);
        ImageFormat::fromMime('image/tiff');
    }

    public function test_image_format_extension_maps_jpeg_to_jpg(): void
    {
        self::assertSame('jpg', ImageFormat::Jpeg->extension());
        self::assertSame('webp', ImageFormat::Webp->extension());
    }
}
