<?php

declare(strict_types=1);

namespace Plugins\Image\Domain\ValueObjects;

/**
 * One rendition produced by ImageProcessorContract::variants() — e.g. the
 * "thumbnail" or "large" entry of a variant preset map. Carries the encoded
 * bytes plus the actual pixel size the engine produced (which, per
 * ResizeMode::Cover/Contain's no-upscale rule, may be smaller than the
 * preset's nominal target width).
 */
final readonly class ImageVariant
{
    public function __construct(
        public string $name,
        public string $contents,
        public int $width,
        public int $height,
        public ImageFormat $format,
    ) {}

    public function bytes(): int
    {
        return strlen($this->contents);
    }
}
