<?php

declare(strict_types=1);

namespace Plugins\Image\Infrastructure\Engines\Concerns;

use Plugins\Image\Domain\Rules\AllowedMimeRule;
use Plugins\Image\Domain\Rules\MaxBytesRule;
use Plugins\Image\Domain\Rules\MaxDimensionsRule;
use Plugins\Image\Domain\ValueObjects\Dimensions;
use Plugins\Image\Domain\ValueObjects\ImageFormat;

/**
 * getimagesizefromstring() reads image headers without decoding pixel data
 * through GD or Imagick, so dimension/format inspection and validation are
 * identical across engines regardless of which one does the actual pixel
 * work — shared here rather than duplicated in both.
 */
trait InspectsImages
{
    public function dimensions(string $contents): Dimensions
    {
        $info = @getimagesizefromstring($contents);
        if ($info === false) {
            throw new \DomainException('Image: could not read dimensions — not a valid image.');
        }
        return new Dimensions((int) $info[0], (int) $info[1]);
    }

    public function detectFormat(string $contents): ImageFormat
    {
        $info = @getimagesizefromstring($contents);
        if ($info === false || !isset($info['mime'])) {
            throw new \DomainException('Image: could not detect format — not a valid image.');
        }
        return ImageFormat::fromMime($info['mime']);
    }

    /** @param list<string> $allowedMimeTypes */
    public function validate(
        string $contents,
        int $maxBytes,
        array $allowedMimeTypes,
        ?int $maxWidth = null,
        ?int $maxHeight = null,
    ): void {
        MaxBytesRule::check(strlen($contents), $maxBytes);

        $info = @getimagesizefromstring($contents);
        if ($info === false || !isset($info['mime'])) {
            throw new \DomainException('Image: not a valid image (could not read dimensions or MIME type).');
        }

        AllowedMimeRule::check($info['mime'], $allowedMimeTypes);
        MaxDimensionsRule::check(new Dimensions((int) $info[0], (int) $info[1]), $maxWidth, $maxHeight);
    }

    /**
     * Runs from every engine's decode() — the STRUCTURAL counterpart to
     * validate()'s opt-in checks. A caller who skips validate() (documented
     * as an antipattern, but nothing previously enforced it) could still
     * hand any engine an oversized or dimension-bomb blob directly via
     * resize()/convert()/etc.; this closes that gap at the one place every
     * one of those methods already goes through.
     *
     * Cheap by design: getimagesizefromstring() reads only the header, the
     * same call validate() itself makes, so this costs no more than
     * validate() already would — never a second full decode.
     *
     * Deliberately does NOT apply IMAGE_ALLOWED_MIME here — that allowlist
     * is a per-route business rule (e.g. "this upload form only accepts
     * jpeg/png") that stays validate()'s job. What this guards is narrower
     * and non-configurable: is $contents even one of the 5 formats this
     * engine claims to support at all, checked BEFORE a single byte reaches
     * the native GD/Imagick/libvips decoder — ImageFormat::fromMime()
     * throws otherwise.
     */
    private function guardDecodable(string $contents): void
    {
        MaxBytesRule::check(strlen($contents), $this->maxBytes);

        $info = @getimagesizefromstring($contents);
        if ($info === false || !isset($info['mime'])) {
            throw new \DomainException('Image: could not decode image data — unsupported or corrupt file.');
        }

        ImageFormat::fromMime($info['mime']);

        MaxDimensionsRule::check(new Dimensions((int) $info[0], (int) $info[1]), $this->maxWidth, $this->maxHeight);
    }
}
