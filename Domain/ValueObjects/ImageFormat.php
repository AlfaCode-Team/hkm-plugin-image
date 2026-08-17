<?php

declare(strict_types=1);

namespace Plugins\Image\Domain\ValueObjects;

enum ImageFormat: string
{
    case Jpeg = 'jpeg';
    case Png  = 'png';
    case Webp = 'webp';
    case Gif  = 'gif';
    case Avif = 'avif';

    public function mimeType(): string
    {
        return match ($this) {
            self::Jpeg => 'image/jpeg',
            self::Png  => 'image/png',
            self::Webp => 'image/webp',
            self::Gif  => 'image/gif',
            self::Avif => 'image/avif',
        };
    }

    /** File extension conventionally used for this format ('jpeg' -> 'jpg'). */
    public function extension(): string
    {
        return $this === self::Jpeg ? 'jpg' : $this->value;
    }

    public static function fromMime(string $mime): self
    {
        return match (strtolower(trim($mime))) {
            'image/jpeg', 'image/jpg', 'image/pjpeg' => self::Jpeg,
            'image/png' => self::Png,
            'image/webp' => self::Webp,
            'image/gif' => self::Gif,
            'image/avif' => self::Avif,
            default => throw new \DomainException("Image: unsupported MIME type [{$mime}]."),
        };
    }
}
