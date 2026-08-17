<?php

declare(strict_types=1);

namespace Plugins\Image;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use Plugins\Image\API\Contracts\ImageProcessorContract;
use Plugins\Image\Infrastructure\Engines\GdImageEngine;
use Plugins\Image\Infrastructure\Engines\ImagickImageEngine;
use Plugins\Image\Infrastructure\Engines\VipsImageEngine;

/**
 * Image plugin — GD/Imagick/Vips adapter for the published ImageProcessorContract.
 *
 * Structured after Plugins\Storage\Provider: guard against an already-bound
 * contract, then pick a driver from config. Unlike Storage there is no
 * kernel-defined "ImagePort" — image processing isn't infrastructure every
 * project needs the way a database or a disk is, so this plugin publishes
 * its OWN contract (API/Contracts/ImageProcessorContract) instead of
 * implementing a kernel Port.
 *
 * Where Storage leaves StoragePort unbound when its driver isn't configured
 * yet (an intentionally optional, not-yet-set-up state), this plugin FAILS
 * FAST when the selected driver's PHP extension is missing: 'gd' is the
 * default and ships with nearly every PHP build, so a missing extension
 * here is an environment problem worth surfacing immediately rather than a
 * silently unresolvable binding discovered later at the call site.
 *
 * ACTIVATION: this is an ON-DEMAND module. A module whose routes process
 * images must declare it in module.json so it joins the request graph:
 *   { "requires": ["image.processing"] }
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'image.processing';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [ImageProcessorContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        if ($container->has(ImageProcessorContract::class)) {
            return; // a project already provided ImageProcessorContract
        }

        $quality   = (int) image_config('quality', 82);
        $driver    = strtolower((string) image_config('driver', 'gd'));
        $maxWidth  = (int) image_config('max_width', 8000);
        $maxHeight = (int) image_config('max_height', 8000);
        $maxBytes  = (int) image_config('max_bytes', 10 * 1024 * 1024);

        // Priority order for 'auto': vips (fastest, broadest format support,
        // matches what the 0.3 ImageService used) > imagick > gd.
        if ($driver === 'auto') {
            $driver = match (true) {
                self::vipsAvailable()        => 'vips',
                extension_loaded('imagick')  => 'imagick',
                default                      => 'gd',
            };
        }

        if ($driver === 'vips') {
            if (!self::vipsAvailable()) {
                // jcupitt/vips is a hard `require` of this plugin's own
                // composer.json, so its class is normally always present —
                // if this throws, the remaining suspects are ext-ffi not
                // being loaded, or (composer can't detect this at all) the
                // libvips SYSTEM LIBRARY not being installed on this host.
                throw new \RuntimeException(
                    'Image: IMAGE_DRIVER=vips but jcupitt/vips is unavailable — check that ext-ffi is loaded '
                    . 'and that the libvips shared library is installed on this host.',
                );
            }
            $container->singleton(ImageProcessorContract::class, static fn() => new VipsImageEngine($quality, $maxWidth, $maxHeight, $maxBytes));
            return;
        }

        if ($driver === 'imagick') {
            if (!extension_loaded('imagick')) {
                throw new \RuntimeException(
                    'Image: IMAGE_DRIVER=imagick but the ext-imagick PHP extension is not loaded.',
                );
            }
            $container->singleton(ImageProcessorContract::class, static fn() => new ImagickImageEngine($quality, $maxWidth, $maxHeight, $maxBytes));
            return;
        }

        if (!extension_loaded('gd')) {
            throw new \RuntimeException('Image: IMAGE_DRIVER=gd but the ext-gd PHP extension is not loaded.');
        }
        $ttfPath = (string) image_config('watermark_ttf_path', '');
        $container->singleton(ImageProcessorContract::class, static fn() => new GdImageEngine($quality, $ttfPath !== '' ? $ttfPath : null, $maxWidth, $maxHeight, $maxBytes));
    }

    /**
     * jcupitt/vips is a composer package built on ext-ffi, not a PHP
     * extension itself — extension_loaded() can't detect it.
     */
    private static function vipsAvailable(): bool
    {
        return extension_loaded('ffi') && class_exists(\Jcupitt\Vips\Image::class);
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
    }
}
