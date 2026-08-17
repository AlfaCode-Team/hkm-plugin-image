<?php

declare(strict_types=1);

namespace Plugins\Image\Domain\Rules;

final class AllowedMimeRule
{
    /** @param list<string> $allowed */
    public static function check(string $mime, array $allowed): void
    {
        $normalised = strtolower(trim($mime));
        $allowList  = array_map(static fn(string $m): string => strtolower(trim($m)), $allowed);

        if ($allowList !== [] && !in_array($normalised, $allowList, true)) {
            throw new \DomainException("Image: MIME type [{$mime}] is not allowed.");
        }
    }
}
