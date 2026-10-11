<?php

declare(strict_types=1);

/**
 * Image processing configuration — every value is env-driven so the engine,
 * quality and limits can be tuned per deployment without touching code.
 *
 * Resolution order at runtime:
 *   1. projects/<name>/config/image.php  (project override — copy this file there)
 *   2. plugins/Image/config/image.php    (this file — framework default)
 *
 * Read it anywhere with the image_config() helper:
 *   image_config('driver');            // 'gd' | 'imagick' | 'vips' | 'auto'
 *   image_config('quality');
 *   image_config('variant_presets');   // "thumbnail:150,medium:300,large:1024"
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Engine Driver
    |--------------------------------------------------------------------------
    | 'gd'      zero-dependency default — bundled with nearly every PHP build
    | 'imagick' richer format support (AVIF/HEIC depend on the ImageMagick
    |           build); requires ext-imagick
    | 'vips'    the library the 0.3 ImageService actually used — fastest,
    |           broadest format support, EXIF auto-orient built into the
    |           resize path itself; requires `composer require jcupitt/vips`
    |           plus ext-ffi and the libvips shared library on the host
    | 'auto'    vips if installed, else imagick if loaded, else gd
    */
    'driver' => strtolower((string) (env('IMAGE_DRIVER') ?: 'gd')),

    /*
    |--------------------------------------------------------------------------
    | Encoding
    |--------------------------------------------------------------------------
    | default_format used by variants() when the caller doesn't pass one
    | quality         1-100, applied to jpeg/webp/avif; mapped onto PNG's
    |                 0-9 compression scale internally
    */
    'default_format' => strtolower((string) (env('IMAGE_DEFAULT_FORMAT') ?: 'webp')),
    'quality'         => (int) (env('IMAGE_QUALITY') ?: 82),

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    | max_bytes          upload size ceiling passed to validate()
    | allowed_mime_types comma list — parse with image_allowed_mime_types()
    | max_width/height   decompression-bomb guard: refuse an image whose
    |                     reported dimensions exceed this BEFORE any engine
    |                     decodes a single pixel
    */
    'max_bytes'          => (int) (env('IMAGE_MAX_BYTES') ?: 10 * 1024 * 1024),
    'allowed_mime_types' => (string) (env('IMAGE_ALLOWED_MIME') ?: 'image/jpeg,image/png,image/webp,image/gif,image/avif'),
    'max_width'          => (int) (env('IMAGE_MAX_WIDTH') ?: 8000),
    'max_height'         => (int) (env('IMAGE_MAX_HEIGHT') ?: 8000),

    /*
    |--------------------------------------------------------------------------
    | Variant Presets
    |--------------------------------------------------------------------------
    | "name:width,name:width,…" — fed to variants() via image_variant_presets().
    | "name:WxH" is a box instead of a width: the variant fits inside W x H
    | whichever way the picture is turned (e.g. "full:2048x2048").
    | Mirrors the fixed 150/300/1024 thumbnail/medium/large sizes the 0.3
    | ImageService hardcoded, now configurable per project.
    */
    'variant_presets' => (string) (env('IMAGE_VARIANT_PRESETS') ?: 'thumbnail:150,medium:300,large:1024'),

    /*
    |--------------------------------------------------------------------------
    | Text Watermarks
    |--------------------------------------------------------------------------
    | GD-only: an absolute path to a .ttf/.otf file. Unset (default), GD
    | renders watermarkText() with its 5 built-in bitmap fonts (always
    | available, no file needed, but fixed-size and blocky — $fontSize is
    | ignored). Imagick and Vips both need a font resolvable through the
    | host's own font system regardless of this setting — see each engine's
    | watermarkText() docblock.
    */
    'watermark_ttf_path' => (string) (env('IMAGE_WATERMARK_TTF_PATH') ?: ''),

];
