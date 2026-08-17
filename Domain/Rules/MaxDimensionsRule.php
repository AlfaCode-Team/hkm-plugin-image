<?php

declare(strict_types=1);

namespace Plugins\Image\Domain\Rules;

use Plugins\Image\Domain\ValueObjects\Dimensions;

/**
 * Guards against a decompression-bomb style upload: a small file that
 * decodes into an enormous pixel buffer (e.g. a 40000x40000 PNG). Checked
 * from getimagesize()'s header read, BEFORE any engine decodes the pixels,
 * so the rejection costs no more memory than reading the file header.
 */
final class MaxDimensionsRule
{
    public static function check(Dimensions $dimensions, ?int $maxWidth, ?int $maxHeight): void
    {
        if ($maxWidth !== null && $dimensions->width > $maxWidth) {
            throw new \DomainException("Image: width {$dimensions->width}px exceeds the {$maxWidth}px limit.");
        }
        if ($maxHeight !== null && $dimensions->height > $maxHeight) {
            throw new \DomainException("Image: height {$dimensions->height}px exceeds the {$maxHeight}px limit.");
        }
    }
}
