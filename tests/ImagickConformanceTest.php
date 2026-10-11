<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Image;

use PHPUnit\Framework\Attributes\CoversClass;
use Plugins\Image\API\Contracts\ImageProcessorContract;
use Plugins\Image\Infrastructure\Engines\ImagickImageEngine;

#[CoversClass(ImagickImageEngine::class)]
final class ImagickConformanceTest extends EngineConformanceTestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('imagick')) {
            self::markTestSkipped('ext-imagick is not loaded — ImagickImageEngine is untested in this environment.');
        }
    }

    protected function engine(): ImageProcessorContract
    {
        return new ImagickImageEngine(85);
    }
}
