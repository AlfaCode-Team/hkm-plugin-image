<?php

declare(strict_types=1);

namespace Plugins\Image\Domain\Support;

/**
 * Pure-PHP port of Evan Wallace's ThumbHash algorithm
 * (https://github.com/evanw/thumbhash, MIT) — a compact (~25-38 byte) DCT-based
 * image placeholder, the modern replacement for BlurHash (smaller, supports
 * alpha, decodes faster).
 *
 * This is a line-for-line port of the reference JavaScript `rgbaToThumbHash`
 * and `thumbHashToAverageRGBA` functions, fetched directly from the upstream
 * repository during development — not reimplemented from a paraphrased
 * description of the DCT math, which is exactly the kind of "coherent but
 * false belief" this framework's verification rule warns about. `averageColor()`
 * exists specifically so a round-trip (encode a known image, decode its
 * average color, compare) can be asserted in tests without porting the full
 * inverse-DCT decoder.
 *
 * Zero external imports — pure integer/float math, fits the Domain layer.
 */
final class ThumbHash
{
    /**
     * @param list<int> $rgba flat [r,g,b,a, r,g,b,a, ...] bytes (0-255 each),
     *     row-major, exactly $w * $h * 4 entries
     * @return string raw binary ThumbHash bytes (NOT base64-encoded)
     */
    public static function encode(int $w, int $h, array $rgba): string
    {
        if ($w < 1 || $h < 1) {
            throw new \DomainException('ThumbHash: width and height must be at least 1.');
        }
        if ($w > 100 || $h > 100) {
            throw new \DomainException("ThumbHash: {$w}x{$h} doesn't fit in 100x100 — shrink the image first.");
        }

        $avgR = 0.0;
        $avgG = 0.0;
        $avgB = 0.0;
        $avgA = 0.0;
        for ($i = 0, $j = 0; $i < $w * $h; $i++, $j += 4) {
            $alpha = $rgba[$j + 3] / 255;
            $avgR += $alpha / 255 * $rgba[$j];
            $avgG += $alpha / 255 * $rgba[$j + 1];
            $avgB += $alpha / 255 * $rgba[$j + 2];
            $avgA += $alpha;
        }
        if ($avgA > 0) {
            $avgR /= $avgA;
            $avgG /= $avgA;
            $avgB /= $avgA;
        }

        $hasAlpha = $avgA < $w * $h;
        $lLimit   = $hasAlpha ? 5 : 7;
        $lx       = max(1, (int) round($lLimit * $w / max($w, $h)));
        $ly       = max(1, (int) round($lLimit * $h / max($w, $h)));

        // Convert RGBA -> LPQA (composited atop the average color).
        $l = [];
        $p = [];
        $q = [];
        $a = [];
        for ($i = 0, $j = 0; $i < $w * $h; $i++, $j += 4) {
            $alpha  = $rgba[$j + 3] / 255;
            $r      = $avgR * (1 - $alpha) + $alpha / 255 * $rgba[$j];
            $g      = $avgG * (1 - $alpha) + $alpha / 255 * $rgba[$j + 1];
            $b      = $avgB * (1 - $alpha) + $alpha / 255 * $rgba[$j + 2];
            $l[$i]  = ($r + $g + $b) / 3;
            $p[$i]  = ($r + $g) / 2 - $b;
            $q[$i]  = $r - $g;
            $a[$i]  = $alpha;
        }

        [$lDc, $lAc, $lScale] = self::encodeChannel($l, max(3, $lx), max(3, $ly), $w, $h);
        [$pDc, $pAc, $pScale] = self::encodeChannel($p, 3, 3, $w, $h);
        [$qDc, $qAc, $qScale] = self::encodeChannel($q, 3, 3, $w, $h);
        [$aDc, $aAc, $aScale] = $hasAlpha ? self::encodeChannel($a, 5, 5, $w, $h) : [0.0, [], 0.0];

        $isLandscape = $w > $h;
        $header24 = ((int) round(63 * $lDc))
            | (((int) round(31.5 + 31.5 * $pDc)) << 6)
            | (((int) round(31.5 + 31.5 * $qDc)) << 12)
            | (((int) round(31 * $lScale)) << 18)
            | (($hasAlpha ? 1 : 0) << 23);
        $header16 = ($isLandscape ? $ly : $lx)
            | (((int) round(63 * $pScale)) << 3)
            | (((int) round(63 * $qScale)) << 9)
            | (($isLandscape ? 1 : 0) << 15);

        $hash = [
            $header24 & 255,
            ($header24 >> 8) & 255,
            $header24 >> 16,
            $header16 & 255,
            $header16 >> 8,
        ];

        $acStart = $hasAlpha ? 6 : 5;
        if ($hasAlpha) {
            $hash[] = ((int) round(15 * $aDc)) | (((int) round(15 * $aScale)) << 4);
        }

        // JS auto-extends a sparse array on out-of-range |=, treating the
        // missing slot as 0. PHP has no such thing — pre-fill the AC region
        // with zero bytes before OR-writing into it.
        $acLists     = $hasAlpha ? [$lAc, $pAc, $qAc, $aAc] : [$lAc, $pAc, $qAc];
        $totalAc     = array_sum(array_map('count', $acLists));
        $neededBytes = $acStart + (int) ceil($totalAc / 2);
        while (count($hash) < $neededBytes) {
            $hash[] = 0;
        }

        $acIndex = 0;
        foreach ($acLists as $ac) {
            foreach ($ac as $f) {
                $slot           = $acStart + ($acIndex >> 1);
                $hash[$slot]   |= ((int) round(15 * $f)) << (($acIndex & 1) << 2);
                $acIndex++;
            }
        }

        return implode('', array_map(chr(...), $hash));
    }

    /**
     * Decode ONLY the average color from a ThumbHash — the DC (0th DCT)
     * terms in the header, no inverse-DCT reconstruction needed. Useful as a
     * CSS background-color fallback, and as this class's own round-trip
     * self-check (see tests/ThumbHashTest.php).
     *
     * @return array{r: float, g: float, b: float, a: float} each 0.0-1.0
     */
    public static function averageColor(string $hash): array
    {
        $bytes = array_values(unpack('C*', $hash) ?: []);
        if (count($bytes) < 5) {
            throw new \DomainException('ThumbHash: hash is too short to decode (need at least 5 bytes).');
        }

        $header   = $bytes[0] | ($bytes[1] << 8) | ($bytes[2] << 16);
        $l        = ($header & 63) / 63;
        $p        = (($header >> 6) & 63) / 31.5 - 1;
        $q        = (($header >> 12) & 63) / 31.5 - 1;
        $hasAlpha = ($header >> 23) !== 0;
        $alpha    = $hasAlpha ? ($bytes[5] & 15) / 15 : 1.0;

        $b = $l - 2 / 3 * $p;
        $r = (3 * $l - $b + $q) / 2;
        $g = $r - $q;

        return [
            'r' => max(0.0, min(1.0, $r)),
            'g' => max(0.0, min(1.0, $g)),
            'b' => max(0.0, min(1.0, $b)),
            'a' => $alpha,
        ];
    }

    /**
     * @param list<float> $channel flat w*h samples
     * @return array{0: float, 1: list<float>, 2: float} [dc, ac, scale]
     */
    private static function encodeChannel(array $channel, int $nx, int $ny, int $w, int $h): array
    {
        $dc    = 0.0;
        $ac    = [];
        $scale = 0.0;

        for ($cy = 0; $cy < $ny; $cy++) {
            for ($cx = 0; $cx * $ny < $nx * ($ny - $cy); $cx++) {
                $f  = 0.0;
                $fx = [];
                for ($x = 0; $x < $w; $x++) {
                    $fx[$x] = cos(M_PI / $w * $cx * ($x + 0.5));
                }
                for ($y = 0; $y < $h; $y++) {
                    $fy = cos(M_PI / $h * $cy * ($y + 0.5));
                    for ($x = 0; $x < $w; $x++) {
                        $f += $channel[$x + $y * $w] * $fx[$x] * $fy;
                    }
                }
                $f /= $w * $h;
                if ($cx !== 0 || $cy !== 0) {
                    $ac[]  = $f;
                    $scale = max($scale, abs($f));
                } else {
                    $dc = $f;
                }
            }
        }

        if ($scale > 0) {
            foreach ($ac as $i => $v) {
                $ac[$i] = 0.5 + 0.5 / $scale * $v;
            }
        }

        return [$dc, $ac, $scale];
    }
}
