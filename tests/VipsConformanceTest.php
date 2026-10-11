<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Image;

use PHPUnit\Framework\Attributes\CoversClass;
use Plugins\Image\API\Contracts\ImageProcessorContract;
use Plugins\Image\Domain\ValueObjects\ImageFormat;
use Plugins\Image\Domain\ValueObjects\ResizeMode;
use Plugins\Image\Infrastructure\Engines\VipsImageEngine;
use Plugins\Image\Provider;

#[CoversClass(VipsImageEngine::class)]
final class VipsConformanceTest extends EngineConformanceTestCase
{
    protected function setUp(): void
    {
        $reason = Provider::vipsUnavailableReason();
        if ($reason !== null) {
            self::markTestSkipped("vips: {$reason}");
        }
    }

    protected function engine(): ImageProcessorContract
    {
        return new VipsImageEngine(95);
    }

    /**
     * A Display P3 photo (every recent iPhone) must come out in sRGB: once
     * the profile is stripped a browser reads the numbers as sRGB, so P3
     * numbers left as they were look duller. rgb(40,180,90) in P3 is about
     * rgb(0,183,79) in sRGB (ImageMagick's colour-managed conversion agrees).
     * GD has no colour management at all, so this is Vips-only.
     */
    public function test_a_display_p3_photo_is_converted_to_srgb(): void
    {
        $p3 = self::fixture('display-p3.jpg');

        foreach ([
            'convert'  => $this->engine()->convert($p3, ImageFormat::Png),
            'variants' => $this->engine()->variants($p3, ['s' => 16], ImageFormat::Png, null, ResizeMode::Contain)['s']->contents,
        ] as $op => $out) {
            [$r, $g, $b] = $this->pixel($out, 8, 8);
            self::assertLessThan(15, $r, "$op: red channel, got rgb($r,$g,$b)");
            self::assertEqualsWithDelta(183, $g, 6, "$op: green channel");
            self::assertEqualsWithDelta(79, $b, 6, "$op: blue channel");
        }
    }
}
