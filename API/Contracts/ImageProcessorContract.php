<?php

declare(strict_types=1);

namespace Plugins\Image\API\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use Plugins\Image\Domain\ValueObjects\Dimensions;
use Plugins\Image\Domain\ValueObjects\ImageFormat;
use Plugins\Image\Domain\ValueObjects\ImageVariant;
use Plugins\Image\Domain\ValueObjects\Position;
use Plugins\Image\Domain\ValueObjects\ResizeMode;

/**
 * Published contract for the `image.processing` domain.
 *
 * Every method is a pure byte-string transform: raw image bytes in, raw
 * image bytes out. This plugin never persists anything — pair it with
 * StoragePort (e.g. `storage.local`) to keep a result:
 *
 *   $image->validate($bytes, maxBytes: 10_000_000, allowedMimeTypes: [...]);
 *   $webp = $image->convert($bytes, ImageFormat::Webp);
 *   $storage->store($webp, 'photo.webp', 'uploads/2026');
 *
 * A route or job that resolves this contract MUST declare
 * `"requires": ["image.processing"]` in its module.json.
 */
interface ImageProcessorContract
{
    /** @throws \DomainException if $contents is not a decodable image */
    public function dimensions(string $contents): Dimensions;

    /** @throws \DomainException if $contents is not a decodable image, or an unsupported format */
    public function detectFormat(string $contents): ImageFormat;

    /**
     * Validate an upload before doing anything else with it.
     *
     * @param list<string> $allowedMimeTypes empty list = no MIME restriction
     * @throws \DomainException on the first rule that fails (size, MIME, then dimensions)
     */
    public function validate(
        string $contents,
        int $maxBytes,
        array $allowedMimeTypes,
        ?int $maxWidth = null,
        ?int $maxHeight = null,
    ): void;

    /**
     * Resize to $width (and $height, or proportional if omitted), preserving
     * the source format. Auto-orients first (see orient()), so a sideways
     * phone photo resizes upright. Never upscales in Cover/Contain mode —
     * see ResizeMode. Metadata (EXIF/IPTC) is not preserved through a resize.
     *
     * @return string re-encoded image bytes
     * @throws \DomainException if $contents is not a decodable image
     * @throws GatewayException on an underlying engine failure
     */
    public function resize(
        string $contents,
        int $width,
        ?int $height = null,
        ResizeMode $mode = ResizeMode::Cover,
    ): string;

    /**
     * Re-encode into a different format. $quality (1-100) falls back to the
     * engine's configured default (IMAGE_QUALITY) when omitted; ignored for
     * Gif.
     *
     * @return string re-encoded image bytes
     * @throws \DomainException if $contents is not a decodable image
     * @throws GatewayException on an underlying engine failure
     */
    public function convert(string $contents, ImageFormat $format, ?int $quality = null): string;

    /**
     * Strip EXIF/IPTC/XMP metadata (GPS location, camera serial, etc.)
     * without resizing. Re-encodes in the source format at a high fidelity
     * quality — every engine drops metadata on any re-encode, so this is
     * mainly useful when you want metadata gone WITHOUT also resizing.
     *
     * @return string re-encoded image bytes, same dimensions
     * @throws \DomainException if $contents is not a decodable image
     * @throws GatewayException on an underlying engine failure
     */
    public function stripMetadata(string $contents): string;

    /**
     * Read the EXIF Orientation tag and physically rotate/flip the pixels to
     * match it, then reset the tag to "normal". A no-op (returns $contents
     * unchanged) for formats that don't carry EXIF orientation or that are
     * already upright.
     *
     * @return string re-encoded image bytes
     * @throws \DomainException if $contents is not a decodable image
     * @throws GatewayException on an underlying engine failure
     */
    public function orient(string $contents): string;

    /**
     * Generate several named renditions in one pass — the responsive
     * thumbnail/medium/large set a gallery or srcset needs. $presets maps a
     * variant name to its target width, e.g. image_variant_presets()'s
     * ['thumbnail' => 150, 'medium' => 300, 'large' => 1024]. Each variant is
     * auto-oriented before resizing.
     *
     * @param array<string, int> $presets variant name => target width
     * @return array<string, ImageVariant> variant name => result, same key order as $presets
     * @throws \DomainException if $contents is not a decodable image
     * @throws GatewayException on an underlying engine failure
     */
    public function variants(
        string $contents,
        array $presets,
        ?ImageFormat $format = null,
        ?int $quality = null,
        ResizeMode $mode = ResizeMode::Cover,
    ): array;

    /**
     * Extract an exact pixel rectangle — for a caller-driven crop UI (an
     * avatar cropper, a manual "select this region" tool), as opposed to
     * resize()'s automatic Cover/Contain/Smart framing. $x/$y/$width/$height
     * are clamped to the source's actual bounds rather than erroring on an
     * out-of-range request, so a UI doesn't need to pre-clamp its own math.
     *
     * @return string re-encoded image bytes, exactly $width x $height (or
     *     less, if the clamped rectangle came out smaller than requested)
     * @throws \DomainException if $contents is not a decodable image
     * @throws GatewayException on an underlying engine failure
     */
    public function crop(string $contents, int $x, int $y, int $width, int $height): string;

    /**
     * Gaussian blur. $sigma is the blur radius in pixels — larger is
     * blurrier. Useful for privacy redaction (blur a face/plate region after
     * crop()) or a stylistic background treatment.
     *
     * @return string re-encoded image bytes, same dimensions
     * @throws \DomainException if $contents is not a decodable image
     * @throws GatewayException on an underlying engine failure
     */
    public function blur(string $contents, float $sigma = 2.0): string;

    /**
     * Unsharp-mask sharpening at each engine's default strength.
     *
     * @return string re-encoded image bytes, same dimensions
     * @throws \DomainException if $contents is not a decodable image
     * @throws GatewayException on an underlying engine failure
     */
    public function sharpen(string $contents): string;

    /**
     * Mosaic/pixelate — replace each $blockSize x $blockSize block with its
     * average color. The other common use of "blur" for redaction, and more
     * resistant to reversal than a Gaussian blur.
     *
     * @return string re-encoded image bytes, same dimensions
     * @throws \DomainException if $contents is not a decodable image
     * @throws GatewayException on an underlying engine failure
     */
    public function pixelate(string $contents, int $blockSize = 8): string;

    /**
     * Desaturate to grayscale (luminance preserved, color removed).
     *
     * @return string re-encoded image bytes, same dimensions
     * @throws \DomainException if $contents is not a decodable image
     * @throws GatewayException on an underlying engine failure
     */
    public function grayscale(string $contents): string;

    /**
     * Adjust brightness/contrast/saturation. Every parameter is a delta from
     * "no change" at 0.0; the valid range and exact effect per unit is
     * engine-dependent (GD has no native saturation control — see
     * GdImageEngine's docblock for the approximation used). Pass only the
     * parameters you want changed; the rest default to a no-op.
     *
     * @return string re-encoded image bytes, same dimensions
     * @throws \DomainException if $contents is not a decodable image
     * @throws GatewayException on an underlying engine failure
     */
    public function adjustColor(string $contents, float $brightness = 0.0, float $contrast = 0.0, float $saturation = 0.0): string;

    /**
     * Composite $overlayContents (any supported image format, ideally one
     * with an alpha channel) onto $contents at $position, offset by
     * $marginX/$marginY from that edge (ignored on an axis Position centers
     * on). $opacityPercent (0-100) scales the overlay's own alpha.
     *
     * @return string re-encoded image bytes, same dimensions as $contents
     * @throws \DomainException if $contents or $overlayContents is not a decodable image
     * @throws GatewayException on an underlying engine failure
     */
    public function watermarkImage(
        string $contents,
        string $overlayContents,
        Position $position = Position::BottomRight,
        int $opacityPercent = 100,
        int $marginX = 10,
        int $marginY = 10,
    ): string;

    /**
     * Draw $text onto $contents at $position. Text rendering quality and
     * font availability are ENGINE-DEPENDENT and NOT interchangeable: GD
     * uses its 5 built-in bitmap fonts (always available, no font file
     * needed, blocky, $fontSize is clamped to that fixed set) unless
     * IMAGE_WATERMARK_TTF_PATH names a real .ttf file; Imagick and Vips both
     * need a font resolvable through the host's font system (fontconfig on
     * Linux) and render TrueType/OpenType at the requested $fontSize. See
     * each engine's watermarkText() docblock before assuming visual parity
     * across drivers.
     *
     * @param string $textColor a "#RRGGBB" or "#RGB" hex color
     * @return string re-encoded image bytes, same dimensions as $contents
     */
    public function watermarkText(
        string $contents,
        string $text,
        Position $position = Position::BottomRight,
        int $fontSize = 16,
        string $textColor = '#FFFFFF',
        int $opacityPercent = 100,
        int $marginX = 10,
        int $marginY = 10,
    ): string;

    /**
     * Encode a ThumbHash — a compact (~25-38 byte) DCT-based placeholder for
     * blur-up/progressive image loading (the modern replacement for
     * BlurHash: smaller, supports transparency). Ship the base64 string
     * alongside a variant's URL; decode it client-side into a tiny blurred
     * preview shown before the real image loads. See
     * Domain\Support\ThumbHash for the algorithm and
     * Domain\Support\ThumbHash::averageColor() for a cheap CSS
     * background-color fallback that needs no client-side decoder at all.
     *
     * @return string base64-encoded ThumbHash bytes
     */
    public function placeholder(string $contents): string;
}
