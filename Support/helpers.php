<?php

declare(strict_types=1);

if (!function_exists('image_config')) {
    /**
     * Read the image processing configuration (config/image.php).
     *
     *   image_config();                 // full array
     *   image_config('driver');         // 'gd' | 'imagick' | 'auto'
     *   image_config('quality');
     *   image_config('local.root', '/tmp')   // value, or fallback if absent
     *
     * Backed by the compiled config manifest, so a project's
     * projects/<name>/config/image.php is DEEP-MERGED over the plugin default.
     *
     * @return mixed the whole config array, or a single (dotted) key's value
     */
    function image_config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            $all = function_exists('config') ? config('image') : null;

            return is_array($all) && $all !== [] ? $all : image_config_fallback();
        }

        if (function_exists('config')) {
            $value = config('image.' . $key, $sentinel = new stdClass());
            if ($value !== $sentinel) {
                return $value;
            }
        }

        // No manifest compiled (e.g. a unit test that never ran the BootPipeline).
        $config = image_config_fallback();
        foreach (explode('.', $key) as $segment) {
            if (!is_array($config) || !array_key_exists($segment, $config)) {
                return $default;
            }
            $config = $config[$segment];
        }

        return $config;
    }
}

if (!function_exists('image_config_fallback')) {
    /**
     * The plugin's shipped defaults, used only when no config manifest exists.
     *
     * @return array<string, mixed>
     */
    function image_config_fallback(): array
    {
        static $config = null;

        if ($config === null) {
            $loaded = require __DIR__ . '/../config/image.php';
            $config = is_array($loaded) ? $loaded : [];
        }

        return $config;
    }
}

if (!function_exists('image_variant_presets')) {
    /**
     * Parse "name:width,name:width" into ['name' => width, ...] — the shape
     * ImageProcessorContract::variants() expects. A "name:WxH" entry becomes
     * a [W, H] box (fit inside it, either orientation). Defaults to the
     * IMAGE_VARIANT_PRESETS config value when $spec is omitted.
     *
     * @return array<string, int|array{0: int, 1: int}>
     */
    function image_variant_presets(?string $spec = null): array
    {
        $spec ??= (string) image_config('variant_presets', '');

        $presets = [];
        foreach (explode(',', $spec) as $entry) {
            $entry = trim($entry);
            if ($entry === '' || !str_contains($entry, ':')) {
                continue;
            }
            [$name, $size] = explode(':', $entry, 2);
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            if (preg_match('/^\s*(\d+)\s*x\s*(\d+)\s*$/i', $size, $box) === 1) {
                if ((int) $box[1] > 0 && (int) $box[2] > 0) {
                    $presets[$name] = [(int) $box[1], (int) $box[2]];
                }
                continue;
            }
            $width = (int) trim($size);
            if ($width > 0) {
                $presets[$name] = $width;
            }
        }

        return $presets;
    }
}

if (!function_exists('image_allowed_mime_types')) {
    /**
     * Parse a comma-separated MIME list (default: IMAGE_ALLOWED_MIME) into
     * the list validate() expects.
     *
     * @return list<string>
     */
    function image_allowed_mime_types(?string $spec = null): array
    {
        $spec ??= (string) image_config('allowed_mime_types', '');

        $list = array_filter(array_map(trim(...), explode(',', $spec)), static fn(string $m): bool => $m !== '');

        return array_values($list);
    }
}
