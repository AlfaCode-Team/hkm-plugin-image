# hkm-plugin-image

> HKM Kernel plugin — provides **`image.processing`**.
> Part of the [HKM Kernel](https://github.com/AlfaCode-Team/hkm-kernel) Gated Demand Architecture framework.

[![License: MIT](https://img.shields.io/badge/License-MIT-green)](LICENSE)
![PHP](https://img.shields.io/badge/PHP-8.4%2B-777bb4)

## Install

```bash
composer require alfacode-team/hkm-plugin-image
# or, from a project:
hkm plugins add image
```

## Capability

Provides `image.processing` over three engines — GD (default), Imagick
(optional, `ext-imagick`), and Vips (`IMAGE_DRIVER=vips`, via `jcupitt/vips` —
the library the 0.3 `ImageService` this plugin replaces actually used, and a
hard dependency of this package; it still needs the libvips shared library on
the host to actually run):

- resize (Cover / Contain / Stretch / Smart content-aware crop — Vips only)
- manual rectangle crop
- format conversion, responsive variant generation
- EXIF auto-orient, metadata stripping, upload validation
- blur, sharpen, pixelate, grayscale, brightness/contrast/saturation
- image and text watermarking (9-point gravity, opacity, margins)
- ThumbHash blur-up placeholder generation

This plugin has **no persistence of its own** — every method is a pure
byte-string transform, `string $contents` in, `string $contents` out. Pair it
with `storage.local` ([hkm-plugin-storage](https://github.com/AlfaCode-Team/hkm-plugin-storage))
to keep a result:

```php
$image   = $container->make(ImageProcessorContract::class);
$storage = $container->make(StoragePort::class);

$image->validate(
    $bytes,
    maxBytes: (int) image_config('max_bytes'),
    allowedMimeTypes: image_allowed_mime_types(),
);

$bytes = $image->watermarkText($bytes, '© 2026', Position::BottomRight, opacityPercent: 60);

foreach ($image->variants($bytes, image_variant_presets(), ImageFormat::Webp) as $name => $variant) {
    $storage->store($variant->contents, "{$name}.webp", "uploads/{$id}");
}

$placeholder = $image->placeholder($bytes); // ship alongside the URL for blur-up loading
```

A route or job that resolves `ImageProcessorContract` must declare
`"requires": ["image.processing"]` in its `module.json`.

A preset can be a width or a `[width, height]` box. A box is how "no side
longer than 2048" is said, since which side is longer depends on how the photo
was held:

```php
$image->variants($bytes, ['full' => [2048, 2048], 'card' => 480], ImageFormat::Webp, mode: ResizeMode::Contain);
// IMAGE_VARIANT_PRESETS="full:2048x2048,card:480"
```

## What every engine guarantees

Checked for all three engines by `tests/EngineConformanceTestCase.php`:

- **Upright.** Every method applies the EXIF orientation, because every output
  drops EXIF. A phone photo comes out of `convert()`, `crop()` or a filter the
  way it was taken, not sideways. `crop()` coordinates are relative to the
  upright picture, which is what a browser shows the person choosing them.
- **Any input format to any output format.** PNG-8 and GIF convert to WebP and
  AVIF with their transparency.
- **Transparency is kept** wherever the output format has it. In a JPEG it
  becomes white, not black.
- **Never a non-image.** Output that is not really the requested format is a
  `GatewayException`, never bytes for the caller to store.
- **Metadata is stripped**, GPS included.

Colour profiles are converted to sRGB by Vips (Display P3, CMYK with a
profile) and Imagick (CMYK). GD has no colour management, so a Display P3
photo comes out slightly duller through GD.

## Choosing an engine

| | GD (default) | Vips | Imagick |
|---|---|---|---|
| Needs | `ext-gd` | `ext-ffi` + libvips | `ext-imagick` |
| Large photos | decodes at full size: ~4 bytes/pixel, outside `memory_limit` | shrink-on-load: a 24 MP JPEG becomes a thumbnail without a full decode | `jpeg:size` hint for placeholders only |
| Colour profiles | ignored | converted to sRGB | CMYK converted |
| Animated WebP input | refused (GD cannot read it) | first frame | first frame |
| `ResizeMode::Smart` | same as Cover | content-aware | same as Cover |

`IMAGE_DRIVER=auto` uses Vips when it can actually load, then Imagick, then GD.

### Running the Vips engine

1. Install libvips: `apt install libvips42` (Debian/Ubuntu) or `brew install vips`.
   In a project, also run `composer require jcupitt/vips`. `hkm plugins
   install` does not install a plugin's composer dependencies into the
   project, and the project's `vendor/` is where the classes are loaded from.
2. Allow FFI for the web SAPI. Set **`ffi.enable=true`** in the php.ini PHP-FPM
   reads. Many builds default to `ffi.enable=preload`, which allows FFI in the
   CLI only. With it, the test suite and every `hkm` command work while every
   web request fails on its first image.
3. Set `IMAGE_DRIVER=vips` (or `auto`) and restart PHP-FPM.

`Plugins\Image\Provider::vipsUnavailableReason()` returns why Vips cannot run
in the current process, or `null` when it can. With `IMAGE_DRIVER=vips` the
boot fails with that reason instead of failing later on the first image.

## Documentation

- [Kernel guides](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/docs/guides) — the framework contracts this plugin builds on.
- [docs/Image-Plugin-Usage-Guide.pdf](docs/Image-Plugin-Usage-Guide.pdf) — the full method reference, every example run against real GD/Imagick/Vips.
- [docs/Image-Upload-WordPress-Compression-Guide.pdf](docs/Image-Upload-WordPress-Compression-Guide.pdf) — file upload, base64/data-URI upload, WordPress-style sizes, and a measured compression guide.
- [CHANGELOG.md](CHANGELOG.md) — what changed in each release.

## License

MIT — see [LICENSE](LICENSE).
