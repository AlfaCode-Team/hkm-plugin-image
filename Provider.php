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
                self::vipsUnavailableReason() === null => 'vips',
                extension_loaded('imagick')            => 'imagick',
                default                                => 'gd',
            };
        }

        if ($driver === 'vips') {
            $reason = self::vipsUnavailableReason();
            if ($reason !== null) {
                throw new \RuntimeException("Image: IMAGE_DRIVER=vips but {$reason}");
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
     * Why the vips engine cannot run in THIS process, or null when it can.
     *
     * jcupitt/vips is a composer package built on ext-ffi, not a PHP
     * extension, so extension_loaded() cannot see it — and "the classes
     * exist" was not enough either. php.ini's common `ffi.enable=preload`
     * allows FFI in the CLI only: the CLI (and the test suite) worked while
     * every web request failed on its first image. So this checks the SAPI's
     * ffi.enable and then really loads libvips once (memoised per process —
     * a fact about the host, not about any request).
     */
    public static function vipsUnavailableReason(): ?string
    {
        static $reason = false;
        if ($reason !== false) {
            return $reason;
        }

        $ffi = strtolower((string) ini_get('ffi.enable'));

        return $reason = match (true) {
            !extension_loaded('ffi')
                => 'the ext-ffi PHP extension is not loaded.',
            !in_array($ffi, ['1', 'true', 'on', 'yes'], true) && !($ffi === 'preload' && PHP_SAPI === 'cli')
                => "ext-ffi is restricted for this SAPI (ffi.enable={$ffi}, sapi=" . PHP_SAPI . '): set ffi.enable=true in php.ini.',
            // `hkm plugins install` does not install a plugin's composer
            // dependencies into the project: the PROJECT must require it.
            !class_exists(\Jcupitt\Vips\Image::class)
                => 'the jcupitt/vips package is not installed: run `composer require jcupitt/vips` in the project.',
            default => self::libvipsLoadError(),
        };
    }

    private static function libvipsLoadError(): ?string
    {
        try {
            \Jcupitt\Vips\Config::version();
            return null;
        } catch (\Throwable $e) {
            return 'the libvips shared library could not be loaded (' . $e->getMessage() . '). Install libvips (apt install libvips42 / brew install vips).';
        }
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
    }
}
