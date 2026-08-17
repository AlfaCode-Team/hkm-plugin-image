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

## Documentation

- [CLAUDE.md](CLAUDE.md) — this plugin's contract, config and rules (start here).
- [Kernel guides](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/docs/guides) — the framework contracts this plugin builds on.
- [docs/Image-Plugin-Usage-Guide.pdf](docs/Image-Plugin-Usage-Guide.pdf) — the full method reference, every example run against real GD/Imagick/Vips.
- [docs/Image-Upload-WordPress-Compression-Guide.pdf](docs/Image-Upload-WordPress-Compression-Guide.pdf) — file upload, base64/data-URI upload, WordPress-style sizes, and a measured compression guide.
- [CHANGELOG.md](CHANGELOG.md) — what changed in each release.

## License

MIT — see [LICENSE](LICENSE).
