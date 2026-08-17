<?php

declare(strict_types=1);

namespace Plugins\Image\Domain\ValueObjects;

/**
 * Nine-point gravity, the convention every competitor (Cloudinary, imgproxy,
 * Intervention Image) uses for watermark placement.
 */
enum Position
{
    case TopLeft;
    case Top;
    case TopRight;
    case Left;
    case Center;
    case Right;
    case BottomLeft;
    case Bottom;
    case BottomRight;

    /**
     * Top-left (x, y) offset for an $overlayW x $overlayH box placed on a
     * $canvasW x $canvasH canvas, honouring $marginX/$marginY. Centered
     * positions ignore the margin on the axis they're centered on.
     *
     * @return array{0: int, 1: int}
     */
    public function offset(int $canvasW, int $canvasH, int $overlayW, int $overlayH, int $marginX, int $marginY): array
    {
        $x = match ($this) {
            self::TopLeft, self::Left, self::BottomLeft => $marginX,
            self::Top, self::Center, self::Bottom => intdiv($canvasW - $overlayW, 2),
            self::TopRight, self::Right, self::BottomRight => $canvasW - $overlayW - $marginX,
        };
        $y = match ($this) {
            self::TopLeft, self::Top, self::TopRight => $marginY,
            self::Left, self::Center, self::Right => intdiv($canvasH - $overlayH, 2),
            self::BottomLeft, self::Bottom, self::BottomRight => $canvasH - $overlayH - $marginY,
        };

        return [$x, $y];
    }
}
