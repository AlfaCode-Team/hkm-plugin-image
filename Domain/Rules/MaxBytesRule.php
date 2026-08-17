<?php

declare(strict_types=1);

namespace Plugins\Image\Domain\Rules;

final class MaxBytesRule
{
    public static function check(int $bytes, int $maxBytes): void
    {
        if ($bytes > $maxBytes) {
            throw new \DomainException(sprintf(
                'Image: upload is %d bytes, over the %d-byte limit.',
                $bytes,
                $maxBytes,
            ));
        }
    }
}
