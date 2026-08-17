# Generated docs

## `Image-Plugin-Usage-Guide.pdf`

How to install, configure and use every `ImageProcessorContract` method — GD,
Imagick and Vips side by side, the security guarantees `decode()` now enforces
structurally, and honest single-machine performance notes.

**Nothing in it is written from memory.** The two files here are a pair:

| File | Does |
|---|---|
| `probe_image.php` | runs every method against real GD/Imagick/Vips engines on a real generated JPEG, writes `image_data.json` |
| `build_image.py` | renders that JSON to LaTeX and runs `lualatex` twice |

```bash
cd docs/generators
php probe_image.php          # → image_data.json
python3 build_image.py       # → IMAGE_PLUGIN_USAGE_GUIDE.pdf
cp IMAGE_PLUGIN_USAGE_GUIDE.pdf ../Image-Plugin-Usage-Guide.pdf
```

So every byte size, dimension, timing and error message in the guide is a
recorded run. If an engine's output changes, the guide changes with it
instead of going stale.

Requires `lualatex` with `tcolorbox`, `listings`, `tocloft`, `needspace`,
`fontspec`, plus DejaVu Sans and Fira Code. On Debian/Kali:
`texlive-luatex texlive-latex-extra fonts-firacode`.

Probing needs whichever of `ext-gd` / `ext-imagick` / (`ext-ffi` +
`jcupitt/vips` + libvips) are actually installed — `gd` is always required by
this plugin's own `composer.json`; the guide records only the engines that
are actually available at generation time (`engines_available` in the JSON)
and labels its comparison tables accordingly.

## `Image-Upload-WordPress-Compression-Guide.pdf`

A cookbook for the four things people ask for first: a normal multipart file
upload, a base64/data-URI upload (what a JS cropper sends), WordPress-style
multi-size generation, and compressing without a visible quality loss.

| File | Does |
|---|---|
| `probe_usage.php` | runs the REAL `Kernel\Http\UploadedFile` class end to end, a real base64/data-URI decode, real `resize()`/`variants()` calls, and a measured quality/size/PSNR sweep across GD/Imagick/Vips; writes `usage_data.json` |
| `build_usage.py` | renders that JSON to LaTeX and runs `lualatex` twice |

```bash
cd docs/generators
php probe_usage.php          # → usage_data.json
python3 build_usage.py       # → IMAGE_UPLOAD_COMPRESSION_GUIDE.pdf
cp IMAGE_UPLOAD_COMPRESSION_GUIDE.pdf ../Image-Upload-WordPress-Compression-Guide.pdf
```

The PSNR sweep is measured (peak signal-to-noise ratio between the original
and the re-encoded-then-decoded pixels, sampled on a stride), not asserted —
including the finding that PNG is pixel-identical at every "quality"
(compression level) setting, and that `stripMetadata()` saves 0 bytes beyond
a same-quality `convert()` because every engine already strips metadata on
every encode. Same requirements as above.
