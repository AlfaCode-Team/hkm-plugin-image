<?php

declare(strict_types=1);

namespace Plugins\Image\Domain\ValueObjects;

final readonly class Dimensions
{
    public function __construct(
        public int $width,
        public int $height,
    ) {
        if ($this->width < 1 || $this->height < 1) {
            throw new \DomainException('Image: dimensions must be at least 1x1 pixel.');
        }
    }

    public static function of(int $width, int $height): self
    {
        return new self($width, $height);
    }

    public function aspectRatio(): float
    {
        return $this->width / $this->height;
    }

    public function fitsWithin(?int $maxWidth, ?int $maxHeight): bool
    {
        if ($maxWidth !== null && $this->width > $maxWidth) {
            return false;
        }
        if ($maxHeight !== null && $this->height > $maxHeight) {
            return false;
        }
        return true;
    }
}
