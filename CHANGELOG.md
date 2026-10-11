# Changelog

All notable changes to `hkm-plugin-image` are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-10-11

Found by running every contract method over awkward real-world files (phone
photos, PNG-8 logos, CMYK and Display P3 JPEGs, truncated files) on GD and
libvips, and over 3,219 real uploads. `tests/EngineConformanceTestCase.php`
now holds every engine to the same promises.

### Fixed
- **GD: PNG-8 and GIF could not become WebP or AVIF.** GD refuses to write a
  palette image in either format. It reports this only as a warning, so the
  conversion failed, and with `display_errors` on, `encode()` returned the
  warning's TEXT as the image (it collected output with `ob_start()`). Every
  decoded image is now promoted to truecolor first, and encoding goes through
  a memory stream.
- **No engine returns a non-image.** Every encode is checked to really be the
  requested format; anything else is a `GatewayException` carrying the
  encoder's warning.
- **Phone photos came out sideways** from `convert()`, `stripMetadata()`,
  `crop()`, `blur()`, `sharpen()`, `grayscale()`, `pixelate()`,
  `adjustColor()` and the watermarks, on every engine. These dropped EXIF
  without applying the Orientation it held. Every engine now applies it while
  decoding.
- **Transparency turned black in a JPEG**, on every engine. It is now
  flattened onto white.
- **GD dropped a truecolor PNG's alpha** through `convert()` and
  `stripMetadata()`.
- **Vips: Display P3 and CMYK photos lost their colours.** Once the profile is
  stripped, the numbers are read as sRGB, so P3 numbers look duller. Both are
  now converted to sRGB: on decode, and in `thumbnail()` via
  `export_profile`. Imagick converts CMYK to sRGB.
- **Vips: `IMAGE_DRIVER=vips`/`auto` failed on every web request under
  `ffi.enable=preload`.** That setting allows FFI in the CLI only. The
  availability check now reads the SAPI's `ffi.enable` and really loads
  libvips. `Provider::vipsUnavailableReason()` says why it cannot run, and a
  web request no longer gets a misleading "Unable to open library".
- **Vips: `pixelate()` crashed** on an image smaller than its block.
- **Vips: warning spam.** A truncated JPEG's warning stayed in libvips' error
  buffer, and every later warning re-logged the whole buffer. It is now
  cleared after each load.
- **Imagick enlarged small pictures** in `Contain` and `Cover`, which the
  contract says never upscale: `thumbnailImage()`'s bestfit and
  `cropThumbnailImage()` both enlarge. The fit and the crop are now computed
  the way GD computes them.
- **Imagick text watermarks failed on a host without ImageMagick's default
  font** ("unable to read font `(null)'"). They now retry with a font
  ImageMagick can see, or say which fonts to install. CI installs
  `fonts-dejavu-core`.
- `orient()` on Vips and Imagick returned re-encoded bytes for an image that
  was already upright. The contract promises the bytes back untouched, as GD
  did.

### Added
- **Box presets.** A `variants()` preset may be `[width, height]` as well as a
  width: fit inside the box whichever way the upright picture is turned. In
  `IMAGE_VARIANT_PRESETS` it is written `name:WxH`. New
  `Domain\ValueObjects\VariantSize`. A malformed preset is a
  `\DomainException`, where a bad value used to be cast to 1px.

### Changed
- `crop()` coordinates are relative to the upright picture (see above), which
  is what a browser shows the person choosing them.

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
