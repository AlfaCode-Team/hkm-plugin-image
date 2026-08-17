<?php

declare(strict_types=1);

/**
 * Runs the four real-world flows this guide covers — multipart file upload,
 * base64/data-URI upload, WordPress-style multi-size generation, and
 * quality/compression tradeoffs — against real engines, and records real
 * output (including a measured PSNR for the lossy formats) to usage_data.json.
 *
 *   php probe_usage.php && python3 build_usage.py
 */

require __DIR__ . '/../../vendor/autoload.php';

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\UploadedFile;
use Plugins\Image\Domain\ValueObjects\ImageFormat;
use Plugins\Image\Domain\ValueObjects\ResizeMode;
use Plugins\Image\Infrastructure\Engines\GdImageEngine;
use Plugins\Image\Infrastructure\Engines\ImagickImageEngine;
use Plugins\Image\Infrastructure\Engines\VipsImageEngine;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

function buildPhoto(int $w, int $h): string
{
    $img = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        $c = imagecolorallocate($img, (int) (200 * $y / $h) + 20, 90 + (int) (60 * sin($y / 20)), (int) (220 * (1 - $y / $h)));
        imageline($img, 0, $y, $w, $y, $c);
    }
    mt_srand(11);
    for ($i = 0; $i < 140; $i++) {
        imagefilledellipse(
            $img,
            mt_rand(0, $w),
            mt_rand(0, $h),
            mt_rand(10, 140),
            mt_rand(10, 140),
            imagecolorallocate($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)),
        );
    }
    // a thin fine-grain noise band (like sensor grain/fabric texture) so the
    // PSNR measurements below aren't only smooth gradients/flat fills, which
    // every codec compresses trivially well at any quality — kept small
    // relative to the frame so it doesn't dominate the whole-image average.
    $noiseBand = max(4, (int) round($h * 0.03));
    for ($x = 0; $x < $w; $x += 2) {
        for ($y = 0; $y < $noiseBand; $y++) {
            imagesetpixel($img, $x, $y, imagecolorallocate($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        }
    }
    ob_start();
    imagejpeg($img, null, 96);
    return (string) ob_get_clean();
}

/** Simulated fake camera EXIF (a UserComment tag padded to look like real-world GPS/camera metadata volume). */
function jpegWithBulkyExif(int $w, int $h): string
{
    $plain = buildPhoto($w, $h);

    $comment = str_repeat('Shot on Acme Camera X100, f/2.8, ISO 400, GPS 37.7749N 122.4194W. ', 40); // ~2.7 KB
    $tiffHeader = "MM\x00\x2A" . pack('N', 8);
    $strOffset  = 8 + 2 + 12 + 4; // after IFD0 header+1 entry+next-IFD pointer
    $entry      = pack('n', 0x9286) . pack('n', 2) . pack('N', strlen($comment) + 1) . pack('N', $strOffset);
    $ifd0       = pack('n', 1) . $entry . pack('N', 0);
    $tiff       = $tiffHeader . $ifd0 . $comment . "\x00";
    $payload    = "Exif\x00\x00" . $tiff;
    $app1       = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

    return "\xFF\xD8" . $app1 . substr($plain, 2);
}

/** Sparse-sampled PSNR in dB between two same-dimension images — infinite (returned as null) means pixel-identical over the sample. */
function psnr(string $a, string $b, int $stride = 4): ?float
{
    $ia = imagecreatefromstring($a);
    $ib = imagecreatefromstring($b);
    $w  = min(imagesx($ia), imagesx($ib));
    $h  = min(imagesy($ia), imagesy($ib));

    $sumSq = 0.0;
    $n     = 0;
    for ($y = 0; $y < $h; $y += $stride) {
        for ($x = 0; $x < $w; $x += $stride) {
            $ca = imagecolorat($ia, $x, $y);
            $cb = imagecolorat($ib, $x, $y);
            foreach ([16, 8, 0] as $shift) { // R, G, B
                $diff = (($ca >> $shift) & 0xFF) - (($cb >> $shift) & 0xFF);
                $sumSq += $diff * $diff;
                $n++;
            }
        }
    }
    if ($sumSq == 0.0) {
        return null; // pixel-identical over the sampled grid
    }
    $mse = $sumSq / $n;
    return round(10 * log10((255 * 255) / $mse), 2);
}

$results = [];
$W = 900;
$H = 600;
$photo = buildPhoto($W, $H);
$results['source'] = ['width' => $W, 'height' => $H, 'bytes' => strlen($photo)];

$gd = new GdImageEngine(82);
$engines = ['gd' => $gd];
if (class_exists(\Imagick::class)) {
    $engines['imagick'] = new ImagickImageEngine(82);
}
if (extension_loaded('ffi') && class_exists(\Jcupitt\Vips\Image::class)) {
    $engines['vips'] = new VipsImageEngine(82);
}
$results['engines_available'] = array_keys($engines);

// ── 1. Multipart file upload — the REAL UploadedFile class, not a mock ────────
$tmp = tempnam(sys_get_temp_dir(), 'upload_probe_');
file_put_contents($tmp, $photo);
$symfonyFile = new SymfonyUploadedFile($tmp, 'holiday.jpg', 'image/jpeg', UPLOAD_ERR_OK, true);
$kernelFile  = UploadedFile::createFromBase($symfonyFile, test: true);
$fromUpload  = $kernelFile->contents();
$results['upload_flow'] = [
    'client_name'   => $kernelFile->clientName(),
    'client_mime'   => $kernelFile->clientMimeType(),
    'size_reported' => $kernelFile->size(),
    'contents_len'  => strlen($fromUpload),
    'bytes_match'   => $fromUpload === $photo,
    'gd_decodes_ok' => (static function () use ($fromUpload) {
        try {
            (new GdImageEngine(82))->dimensions($fromUpload);
            return true;
        } catch (\Throwable) {
            return false;
        }
    })(),
];
unlink($tmp);

// ── 2. Base64 / data-URI upload ────────────────────────────────────────────────
$dataUri = 'data:image/jpeg;base64,' . base64_encode($photo);
function decodeUploadedBase64(string $input): string
{
    if (str_starts_with($input, 'data:')) {
        $comma = strpos($input, ',');
        $input = $comma === false ? '' : substr($input, $comma + 1);
    }
    $decoded = base64_decode($input, true);
    if ($decoded === false) {
        throw new \DomainException('Image: not valid base64.');
    }
    return $decoded;
}
$fromBase64 = decodeUploadedBase64($dataUri);
$results['base64_flow'] = [
    'data_uri_len'    => strlen($dataUri),
    'decoded_bytes'   => strlen($fromBase64),
    'bytes_match'     => $fromBase64 === $photo,
    'dimensions_ok'   => (static function () use ($fromBase64, $W, $H) {
        $d = (new GdImageEngine(82))->dimensions($fromBase64);
        return $d->width === $W && $d->height === $H;
    })(),
];
// also prove a raw (no data: prefix) base64 body works the same way
$rawBase64 = base64_encode($photo);
$results['base64_flow']['raw_no_prefix_ok'] = decodeUploadedBase64($rawBase64) === $photo;
// and that garbage is rejected, not silently corrupted
try {
    decodeUploadedBase64('data:image/jpeg;base64,%%%not-base64%%%');
    $results['base64_flow']['rejects_garbage'] = false;
} catch (\DomainException $e) {
    $results['base64_flow']['rejects_garbage']    = true;
    $results['base64_flow']['garbage_reject_note'] = 'base64_decode(strict) returned false before any image call ran';
}

// ── 3. WordPress-style multi-size generation ───────────────────────────────────
$wpPresets = ['thumbnail' => 150, 'medium' => 300, 'medium_large' => 768, 'large' => 1024];
$viaVariants = $gd->variants($photo, $wpPresets, ImageFormat::Webp, mode: ResizeMode::Cover);
$results['wp_style']['variants_preset_widths_only'] = array_map(
    static fn($v) => ['w' => $v->width, 'h' => $v->height, 'bytes' => $v->bytes()],
    $viaVariants,
);
$squareThumb = $gd->resize($photo, 150, 150, ResizeMode::Cover);
$sd = $gd->dimensions($squareThumb);
$results['wp_style']['explicit_square_thumbnail'] = ['w' => $sd->width, 'h' => $sd->height, 'bytes' => strlen($squareThumb), 'is_square' => $sd->width === $sd->height];

$mediumContain = $gd->resize($photo, 300, 300, ResizeMode::Contain);
$mc = $gd->dimensions($mediumContain);
$results['wp_style']['medium_contain_no_crop'] = ['w' => $mc->width, 'h' => $mc->height, 'bytes' => strlen($mediumContain)];

// small source: prove Contain never upscales past the source (WordPress does the same)
$smallSource = buildPhoto(200, 120);
$smallLarge  = $gd->resize($smallSource, 1024, 1024, ResizeMode::Contain);
$sl = $gd->dimensions($smallLarge);
$results['wp_style']['contain_never_upscales'] = ['requested' => '1024x1024', 'source' => '200x120', 'actual' => "{$sl->width}x{$sl->height}"];

// ── 4. Compression — quality/size/PSNR curves, real measured ──────────────────
$qualities = [60, 75, 85, 90, 95, 100];
foreach (['jpeg' => ImageFormat::Jpeg, 'webp' => ImageFormat::Webp, 'avif' => ImageFormat::Avif] as $fmtName => $fmt) {
    foreach ($qualities as $q) {
        try {
            $out = $gd->convert($photo, $fmt, $q);
        } catch (\Throwable $e) {
            continue; // e.g. AVIF unsupported on this GD build
        }
        // imagecreatefromstring() decodes jpeg/webp/avif/png alike (verified:
        // a GD-encoded AVIF round-trips through it), so psnr() can compare the
        // original JPEG directly against the re-encoded bytes in ANY format.
        $results['compression'][$fmtName][] = [
            'quality' => $q,
            'bytes'   => strlen($out),
            'ratio'   => round(strlen($out) / strlen($photo) * 100, 1),
            'psnr_db' => psnr($photo, $out),
        ];
    }
}
// PNG — always lossless regardless of "quality" (compression LEVEL only)
foreach ([10, 50, 82, 100] as $q) {
    $out = $gd->convert($photo, ImageFormat::Png, $q);
    $results['compression']['png'][] = [
        'quality'      => $q,
        'bytes'        => strlen($out),
        'ratio'        => round(strlen($out) / strlen($photo) * 100, 1),
        'psnr_db'      => psnr($photo, $out),
        'pixel_perfect' => psnr($photo, $out) === null,
    ];
}

// stripMetadata() savings on a JPEG carrying a realistic-volume fake EXIF
// block. jpegWithBulkyExif() calls buildPhoto($W, $H) internally, which is
// deterministic (mt_srand(11) reset at the top) — so it reproduces the exact
// same pixel content as $photo above and splices the EXIF block in RAW,
// without touching the compressed scan data. That means
// with_exif_bytes - $photo's own byte size is the metadata's TRUE byte
// contribution, uncontaminated by any re-encode.
$withExif = jpegWithBulkyExif($W, $H);
$stripped = $gd->stripMetadata($withExif); // NOTE: also re-encodes at a fixed quality (92) as a side effect — see GdImageEngine::stripMetadata()
$sameQualityBaseline = $gd->convert($withExif, ImageFormat::Jpeg, 92); // same fixed quality stripMetadata() uses, EXIF still attached — isolates the metadata-only delta
$results['metadata'] = [
    'plain_bytes'                  => strlen($photo),
    'with_exif_bytes'              => strlen($withExif),
    'metadata_overhead_bytes'      => strlen($withExif) - strlen($photo),
    'stripped_bytes'               => strlen($stripped),
    'same_quality_with_exif_bytes' => strlen($sameQualityBaseline),
    'metadata_only_savings_bytes'  => strlen($sameQualityBaseline) - strlen($stripped),
    'pixel_identical_to_original'  => psnr($photo, $stripped) === null,
];

file_put_contents(__DIR__ . '/usage_data.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "wrote usage_data.json\n";
