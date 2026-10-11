<?php

declare(strict_types=1);

namespace Plugins\Image\Domain\ValueObjects;

/**
 * One entry of a variants() preset map: a target WIDTH, or a [width, height]
 * BOX.
 *
 *   ['thumb' => 160]           160 wide, height follows the aspect ratio
 *   ['full'  => [2048, 2048]]  fits inside 2048x2048 whichever way the
 *                              (upright) picture is turned
 *
 * The box form is what "no side longer than N" needs: a width alone cannot
 * say it, because a portrait photo's long side is its height, and which side
 * that is depends on the EXIF orientation the engine applies while decoding.
 */
final readonly class VariantSize
{
    private function __construct(
        public int $width,
        public ?int $height,
    ) {
        if ($this->width < 1 || ($this->height !== null && $this->height < 1)) {
            throw new \DomainException('Image: a variant size must be at least 1 pixel.');
        }
    }

    /**
     * A digit string ("300") is accepted wherever an int is, as variants()
     * always cast its widths with (int) before boxes existed.
     *
     * @param int|string|array{0: int|string, 1: int|string}|mixed $preset
     */
    public static function from(mixed $preset): self
    {
        $width = self::pixels($preset);
        if ($width !== null) {
            return new self($width, null);
        }

        if (is_array($preset) && array_is_list($preset) && count($preset) === 2) {
            [$w, $h] = [self::pixels($preset[0]), self::pixels($preset[1])];
            if ($w !== null && $h !== null) {
                return new self($w, $h);
            }
        }

        throw new \DomainException('Image: a variant preset must be a width or a [width, height] box of whole pixels.');
    }

    private static function pixels(mixed $value): ?int
    {
        return match (true) {
            is_int($value)                                 => $value,
            is_string($value) && ctype_digit(trim($value)) => (int) trim($value),
            default                                        => null,
        };
    }
}
