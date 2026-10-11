<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Image;

use PHPUnit\Framework\Attributes\CoversClass;
use Plugins\Image\API\Contracts\ImageProcessorContract;
use Plugins\Image\Infrastructure\Engines\GdImageEngine;

#[CoversClass(GdImageEngine::class)]
final class GdConformanceTest extends EngineConformanceTestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('ext-gd is not loaded.');
        }
    }

    protected function engine(): ImageProcessorContract
    {
        return new GdImageEngine(85);
    }
}
