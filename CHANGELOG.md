# Changelog

All notable changes to `hkm-plugin-image` are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-08-17

Initial release. Provides `image.processing` — resize, convert, crop, filter,
watermark and ThumbHash placeholder generation — over three interchangeable
engines (GD, Imagick, Vips) behind one published contract,
`ImageProcessorContract`.

### Added
- **Three engines, one contract.** `GdImageEngine` (zero-dependency default),
  `ImagickImageEngine` (`IMAGE_DRIVER=imagick`) and `VipsImageEngine`
  (`IMAGE_DRIVER=vips`, via `jcupitt/vips` — the library the 0.3 `ImageService`
  this plugin replaces actually used). `IMAGE_DRIVER=auto` picks the best one
  installed.
- **Full transform surface:** `resize()` (`Cover`/`Contain`/`Stretch`/`Smart` —
  `Smart` content-aware crop is Vips-only), `convert()`, `variants()` (several
  named renditions in one pass), `crop()`, `blur()`, `sharpen()`, `pixelate()`,
  `grayscale()`, `adjustColor()`, `watermarkImage()`/`watermarkText()`
  (9-point gravity), `orient()` (EXIF auto-orientation), `stripMetadata()` and
  `placeholder()` (ThumbHash blur-up placeholders).
- **Structural decompression-bomb and format guard.** Every engine's internal
  `decode()` now enforces `IMAGE_MAX_BYTES`/`IMAGE_MAX_WIDTH`/`IMAGE_MAX_HEIGHT`
  and a supported-format check BEFORE any native decoder sees a byte —
  independent of whether the caller remembers to call `validate()` first.
  Closes a gap where `convert()`, and `variants()` called with an explicit
  target format, previously skipped even the cheap header-based MIME sniff
  every other method got.
- **Real `Imagick` resource limits.** `ImagickImageEngine` installs
  `Imagick::setResourceLimit()` bounds (WIDTH/HEIGHT/AREA/MEMORY/MAP/DISK/TIME)
  once per construction, derived from config — an independent backstop for the
  engine most exposed to crafted-content issues via non-raster
  coders/delegates, instead of relying solely on the host's own `policy.xml`.
- **Single-decode `variants()`.** GD and Imagick now decode (and auto-orient)
  the source ONCE per `variants()` call and reuse it for every preset (GD
  resamples the same decoded image; Imagick clones it per preset), instead of
  re-decoding from scratch per preset. Fixed alongside a real GD-only bug this
  surfaced: `variants()` was deciding whether to even check EXIF orientation
  using the caller's requested *output* format rather than the source's real
  format, so `variants($jpegBytes, $presets, ImageFormat::Webp)` on a real
  EXIF-rotated JPEG silently skipped auto-orientation.
- **Imagick shrink-on-load for `placeholder()`**, via a `jpeg:size` decode
  hint — large sources no longer decode at full resolution just to build a
  ~30-byte hash.
- **Output-size clamping.** `resize()`/`variants()` now clamp the caller's
  target width/height to `IMAGE_MAX_WIDTH`/`IMAGE_MAX_HEIGHT` — `Stretch` is
  the one mode allowed to upscale, so this closes an otherwise-unbounded
  output-canvas size on that path.
- **Docs.** `docs/Image-Plugin-Usage-Guide.pdf` (full method reference) and
  `docs/Image-Upload-WordPress-Compression-Guide.pdf` (multipart upload,
  base64/data-URI upload, WordPress-style multi-size generation, and a
  measured quality/size/PSNR compression guide) — both generated from real
  executed code via the `docs/generators/` probe+build script pairs, not
  written from memory.
