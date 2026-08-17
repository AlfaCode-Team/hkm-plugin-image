<?php

declare(strict_types=1);

/**
 * Runs every ImageProcessorContract method against real GD/Imagick/Vips
 * engines (whichever are actually available on this host) and records real
 * output — byte sizes, dimensions, timings, the guard's real rejection
 * message, the real Imagick resource-limit values — to image_data.json.
 *
 * build_image.py renders that JSON into the usage guide, so every number in
 * the PDF is a recorded run, not prose written from memory.
 *
 *   php probe_image.php && python3 build_image.py
 */

require __DIR__ . '/../../vendor/autoload.php';

use Plugins\Image\Domain\ValueObjects\ImageFormat;
use Plugins\Image\Domain\ValueObjects\Position;
use Plugins\Image\Domain\ValueObjects\ResizeMode;
use Plugins\Image\Infrastructure\Engines\GdImageEngine;
use Plugins\Image\Infrastructure\Engines\ImagickImageEngine;
use Plugins\Image\Infrastructure\Engines\VipsImageEngine;

function buildPhoto(int $w, int $h): string
{
    $img = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        $c = imagecolorallocate($img, (int) (255 * $y / $h), 120, (int) (255 * (1 - $y / $h)));
        imageline($img, 0, $y, $w, $y, $c);
    }
    mt_srand(7);
    for ($i = 0; $i < 60; $i++) {
        imagefilledellipse(
            $img,
            mt_rand(0, $w),
            mt_rand(0, $h),
            mt_rand(20, 200),
            mt_rand(20, 200),
            imagecolorallocate($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)),
        );
    }
    ob_start();
    imagejpeg($img, null, 90);
    return (string) ob_get_clean();
}

/** Hand-builds a minimal, real EXIF APP1 segment and splices it after the JPEG SOI marker — same technique the test suite uses. */
function jpegWithOrientation(int $w, int $h, int $orientation): string
{
    $plain = buildPhoto($w, $h);

    $tiffHeader = "MM\x00\x2A" . pack('N', 8);
    $entry      = pack('n', 0x0112) . pack('n', 3) . pack('N', 1) . pack('n', $orientation) . "\x00\x00";
    $ifd0       = pack('n', 1) . $entry . pack('N', 0);
    $tiff       = $tiffHeader . $ifd0;
    $payload    = "Exif\x00\x00" . $tiff;
    $app1       = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

    return "\xFF\xD8" . $app1 . substr($plain, 2);
}

function ms(float $t0, float $t1): float
{
    return round(($t1 - $t0) / 1e6, 3);
}

$results = [];

$W = 1600;
$H = 900;
$photo = buildPhoto($W, $H);
$logo  = buildPhoto(160, 80);
$results['php_version']    = PHP_VERSION;
$results['source']         = ['width' => $W, 'height' => $H, 'bytes' => strlen($photo)];
$results['generated_note'] = 'Every value below came from actually running the listed method.';

$engines = ['gd' => new GdImageEngine(82)];
if (class_exists(\Imagick::class)) {
    $engines['imagick'] = new ImagickImageEngine(82);
}
if (extension_loaded('ffi') && class_exists(\Jcupitt\Vips\Image::class)) {
    $engines['vips'] = new VipsImageEngine(82);
}
$results['engines_available'] = array_keys($engines);

foreach ($engines as $name => $engine) {
    $row = [];

    try {
        $engine->validate($photo, 10 * 1024 * 1024, ['image/jpeg', 'image/png', 'image/webp']);
        $row['validate_ok'] = true;
    } catch (\Throwable $e) {
        $row['validate_ok'] = false;
    }

    try {
        $engine->validate($photo, 10 * 1024 * 1024, ['image/jpeg'], maxWidth: 100);
        $row['validate_rejects_oversized'] = false;
    } catch (\DomainException $e) {
        $row['validate_rejects_oversized'] = true;
        $row['validate_reject_message']    = $e->getMessage();
    }

    $t0    = hrtime(true);
    $webp  = $engine->convert($photo, ImageFormat::Webp);
    $t1    = hrtime(true);
    $row['convert'] = ['bytes' => strlen($webp), 'ms' => ms($t0, $t1), 'ratio' => round(strlen($webp) / strlen($photo) * 100, 1)];

    foreach ([ResizeMode::Cover, ResizeMode::Contain, ResizeMode::Stretch] as $mode) {
        $t0   = hrtime(true);
        $out  = $engine->resize($photo, 300, 200, $mode);
        $t1   = hrtime(true);
        $dims = $engine->dimensions($out);
        $row['resize'][$mode->name] = ['w' => $dims->width, 'h' => $dims->height, 'bytes' => strlen($out), 'ms' => ms($t0, $t1)];
    }

    $t0       = hrtime(true);
    $variants = $engine->variants($photo, ['thumbnail' => 150, 'medium' => 300, 'large' => 1024], ImageFormat::Webp);
    $t1       = hrtime(true);
    $row['variants_total_ms'] = ms($t0, $t1);
    foreach ($variants as $vname => $v) {
        $row['variants'][$vname] = ['w' => $v->width, 'h' => $v->height, 'bytes' => $v->bytes()];
    }

    $rotated       = jpegWithOrientation(200, 100, 6);
    $beforeOrient  = $engine->dimensions($rotated);
    $afterOrient   = $engine->dimensions($engine->orient($rotated));
    $row['orient'] = [
        'before' => "{$beforeOrient->width}x{$beforeOrient->height}",
        'after'  => "{$afterOrient->width}x{$afterOrient->height}",
    ];

    $cropped     = $engine->crop($photo, 100, 100, 500, 350);
    $cropDims    = $engine->dimensions($cropped);
    $row['crop'] = ['w' => $cropDims->width, 'h' => $cropDims->height, 'bytes' => strlen($cropped)];

    $filters = [
        'blur'      => static fn() => $engine->blur($photo, 3.0),
        'sharpen'   => static fn() => $engine->sharpen($photo),
        'pixelate'  => static fn() => $engine->pixelate($photo, 12),
        'grayscale' => static fn() => $engine->grayscale($photo),
    ];
    foreach ($filters as $fname => $fn) {
        $t0  = hrtime(true);
        $out = $fn();
        $t1  = hrtime(true);
        $row['filters'][$fname] = ['bytes' => strlen($out), 'ms' => ms($t0, $t1)];
    }

    $adjusted            = $engine->adjustColor($photo, brightness: 10, contrast: 15, saturation: 20);
    $row['adjust_color'] = ['bytes' => strlen($adjusted)];

    $wmImage                 = $engine->watermarkImage($photo, $logo, Position::BottomRight, 70);
    $row['watermark_image']  = ['bytes' => strlen($wmImage)];
    $wmText                  = $engine->watermarkText($photo, '(c) 2026 Acme', Position::Bottom, 18, '#FFFFFF', 80);
    $row['watermark_text']   = ['bytes' => strlen($wmText)];

    $placeholder        = $engine->placeholder($photo);
    $row['placeholder'] = ['base64' => $placeholder, 'len' => strlen($placeholder)];

    // Structural guard demo — convert() directly, no validate() call at all,
    // against a tiny-limit engine constructed just for this check.
    $tinyEngine = match ($name) {
        'gd'      => new GdImageEngine(82, null, 400, 400),
        'imagick' => new ImagickImageEngine(82, 400, 400),
        'vips'    => new VipsImageEngine(82, 400, 400),
    };
    try {
        $tinyEngine->convert($photo, ImageFormat::Png);
        $row['guard_rejected'] = false;
    } catch (\DomainException $e) {
        $row['guard_rejected']    = true;
        $row['guard_message']     = $e->getMessage();
    }

    $results['engines'][$name] = $row;
}

if (isset($engines['imagick'])) {
    // Re-apply the plugin's real default config values (the 400x400 demo
    // above left the process-global Imagick limits at 400x400) so the
    // recorded limits reflect a normal deployment, not the test above.
    new ImagickImageEngine(82, 8000, 8000, 10 * 1024 * 1024);
    $results['imagick_resource_limits'] = [
        'width'  => \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_WIDTH),
        'height' => \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_HEIGHT),
        'area'   => \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_AREA),
        'memory' => \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_MEMORY),
        'map'    => \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_MAP),
        'disk'   => \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_DISK),
        'time'   => \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_TIME),
    ];
}

file_put_contents(__DIR__ . '/image_data.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo 'wrote image_data.json (' . implode(', ', array_keys($engines)) . ")\n";
