<?php

declare(strict_types=1);

namespace Plugins\Image\Domain\ValueObjects;

/**
 * Cover   — scale to fill the target box, center-cropping the overflow.
 *           Used for fixed-size thumbnails. Never upscales: when the
 *           aspect-correct crop is already smaller than the requested box,
 *           the engine ships the crop at its native size instead of
 *           stretching it up.
 * Contain — scale to fit within the target box, preserving aspect ratio,
 *           no crop. Used for responsive/srcset variants. Never upscales.
 * Stretch — force the exact target width and height, ignoring aspect ratio.
 *           The only mode that will upscale, since an exact size was asked
 *           for explicitly.
 * Smart   — like Cover (fills the box, never upscales), but positions the
 *           crop over the most visually significant region (edges, skin
 *           tones, saturated color) instead of the geometric center.
 *           VIPS-ONLY: GD and Imagick have no content-aware cropping
 *           primitive, so they fall back to plain Cover — see each engine's
 *           computeGeometry()/resizeInternal() for exactly where.
 */
enum ResizeMode
{
    case Cover;
    case Contain;
    case Stretch;
    case Smart;
}
