<?php
/**
 * Plugin Name: ePHPm Object Cache
 * Description: WordPress object-cache drop-in backed by ePHPm's in-process KV store (ephpm_kv_* SAPI functions). Real wp_cache_flush() via ephpm_kv_flush_all().
 * Version: 0.1.0
 * Author: ePHPm
 * License: MIT
 *
 * INSTALL
 * -------
 * Copy (or symlink) this file to `wp-content/object-cache.php`. WordPress
 * loads it automatically during `wp_start_object_cache()` — very early in
 * the bootstrap, BEFORE plugins and (usually) before any Composer
 * autoloader has been required. This file therefore loads the package's
 * classes itself.
 *
 * EXPECTED LAYOUT
 * ---------------
 * The package is installed via Composer (`composer require ephpm/cache-wordpress`).
 * To locate its classes this drop-in tries, in order:
 *
 *   1. A `EPHPM_CACHE_AUTOLOAD` constant pointing at a `vendor/autoload.php`
 *      (define it in `wp-config.php` if your layout is non-standard).
 *   2. `WP_CONTENT_DIR/vendor/autoload.php`
 *   3. `ABSPATH/vendor/autoload.php`
 *   4. `<this dir>/vendor/autoload.php` and a couple of relative climbs,
 *      covering the case where the drop-in lives inside the installed
 *      package directory.
 *   5. As a last resort, it `require_once`s the package's `src/*.php`
 *      files directly, resolved relative to this drop-in's location.
 *
 * If none of those resolve the `Ephpm\Cache\WordPress\ObjectCache` class
 * the drop-in does nothing (WordPress falls back to its built-in
 * non-persistent cache) and logs a notice, rather than fataling the site.
 *
 * @package Ephpm\Cache\WordPress
 */

if (!defined('ABSPATH')) {
    // Not loaded by WordPress; bail rather than expose functions standalone.
    return;
}

if (!class_exists('Ephpm\\Cache\\WordPress\\ObjectCache')) {
    (static function (): void {
        $candidates = [];

        if (defined('EPHPM_CACHE_AUTOLOAD')) {
            $candidates[] = (string) EPHPM_CACHE_AUTOLOAD;
        }
        if (defined('WP_CONTENT_DIR')) {
            $candidates[] = rtrim((string) WP_CONTENT_DIR, '/\\') . '/vendor/autoload.php';
        }
        $candidates[] = rtrim((string) ABSPATH, '/\\') . '/vendor/autoload.php';
        $candidates[] = __DIR__ . '/vendor/autoload.php';
        $candidates[] = dirname(__DIR__) . '/vendor/autoload.php';
        $candidates[] = dirname(__DIR__, 2) . '/vendor/autoload.php';

        foreach ($candidates as $autoload) {
            if ($autoload !== '' && is_file($autoload)) {
                require_once $autoload;
                if (class_exists('Ephpm\\Cache\\WordPress\\ObjectCache')) {
                    return;
                }
            }
        }

        // Last resort: require the package source files directly. This
        // drop-in ships inside the package at `dropin/object-cache.php`, so
        // the sources are one directory up under `src/`. We also probe a
        // vendor install path for safety.
        $srcDirs = [
            dirname(__DIR__) . '/src',
            dirname(__DIR__, 1) . '/vendor/ephpm/cache-wordpress/src',
        ];
        foreach ($srcDirs as $src) {
            $iface = $src . '/KvOpsInterface.php';
            if (is_file($iface)) {
                require_once $iface;
                require_once $src . '/InMemoryKvOps.php';
                require_once $src . '/SapiKvOps.php';
                require_once $src . '/ObjectCache.php';
                if (class_exists('Ephpm\\Cache\\WordPress\\ObjectCache')) {
                    return;
                }
            }
        }
    })();
}

if (!class_exists('Ephpm\\Cache\\WordPress\\ObjectCache')) {
    // Couldn't find the package. Leave WordPress to its built-in runtime
    // cache instead of fataling; surface a hint in the error log.
    if (function_exists('error_log')) {
        error_log(
            'ephpm object-cache.php: could not locate '
            . 'Ephpm\\Cache\\WordPress\\ObjectCache. '
            . 'Install ephpm/cache-wordpress via Composer or define '
            . 'EPHPM_CACHE_AUTOLOAD in wp-config.php.'
        );
    }
    return;
}

/**
 * Access (lazily creating) the global object-cache instance.
 */
if (!function_exists('wp_cache_init')) {
    function wp_cache_init(): void
    {
        $GLOBALS['wp_object_cache'] = new \Ephpm\Cache\WordPress\ObjectCache();
    }
}

if (!function_exists('wp_cache_add')) {
    /**
     * @param int|string $key
     */
    function wp_cache_add($key, mixed $data, string $group = '', int $expire = 0): bool
    {
        return $GLOBALS['wp_object_cache']->add($key, $data, $group, $expire);
    }
}

if (!function_exists('wp_cache_add_multiple')) {
    /**
     * @param array<int|string, mixed> $data
     *
     * @return array<int|string, bool>
     */
    function wp_cache_add_multiple(array $data, string $group = '', int $expire = 0): array
    {
        return $GLOBALS['wp_object_cache']->add_multiple($data, $group, $expire);
    }
}

if (!function_exists('wp_cache_set')) {
    /**
     * @param int|string $key
     */
    function wp_cache_set($key, mixed $data, string $group = '', int $expire = 0): bool
    {
        return $GLOBALS['wp_object_cache']->set($key, $data, $group, $expire);
    }
}

if (!function_exists('wp_cache_set_multiple')) {
    /**
     * @param array<int|string, mixed> $data
     *
     * @return array<int|string, bool>
     */
    function wp_cache_set_multiple(array $data, string $group = '', int $expire = 0): array
    {
        return $GLOBALS['wp_object_cache']->set_multiple($data, $group, $expire);
    }
}

if (!function_exists('wp_cache_get')) {
    /**
     * @param int|string $key
     * @param bool       $found
     */
    function wp_cache_get($key, string $group = '', bool $force = false, &$found = null): mixed
    {
        return $GLOBALS['wp_object_cache']->get($key, $group, $force, $found);
    }
}

if (!function_exists('wp_cache_get_multiple')) {
    /**
     * @param array<int, int|string> $keys
     *
     * @return array<int|string, mixed>
     */
    function wp_cache_get_multiple(array $keys, string $group = '', bool $force = false): array
    {
        return $GLOBALS['wp_object_cache']->get_multiple($keys, $group, $force);
    }
}

if (!function_exists('wp_cache_replace')) {
    /**
     * @param int|string $key
     */
    function wp_cache_replace($key, mixed $data, string $group = '', int $expire = 0): bool
    {
        return $GLOBALS['wp_object_cache']->replace($key, $data, $group, $expire);
    }
}

if (!function_exists('wp_cache_delete')) {
    /**
     * @param int|string $key
     */
    function wp_cache_delete($key, string $group = ''): bool
    {
        return $GLOBALS['wp_object_cache']->delete($key, $group);
    }
}

if (!function_exists('wp_cache_delete_multiple')) {
    /**
     * @param array<int, int|string> $keys
     *
     * @return array<int|string, bool>
     */
    function wp_cache_delete_multiple(array $keys, string $group = ''): array
    {
        return $GLOBALS['wp_object_cache']->delete_multiple($keys, $group);
    }
}

if (!function_exists('wp_cache_incr')) {
    /**
     * @param int|string $key
     */
    function wp_cache_incr($key, int $offset = 1, string $group = ''): int|false
    {
        return $GLOBALS['wp_object_cache']->incr($key, $offset, $group);
    }
}

if (!function_exists('wp_cache_decr')) {
    /**
     * @param int|string $key
     */
    function wp_cache_decr($key, int $offset = 1, string $group = ''): int|false
    {
        return $GLOBALS['wp_object_cache']->decr($key, $offset, $group);
    }
}

if (!function_exists('wp_cache_flush')) {
    function wp_cache_flush(): bool
    {
        return $GLOBALS['wp_object_cache']->flush();
    }
}

if (!function_exists('wp_cache_flush_runtime')) {
    function wp_cache_flush_runtime(): bool
    {
        return $GLOBALS['wp_object_cache']->flush_runtime();
    }
}

if (!function_exists('wp_cache_flush_group')) {
    function wp_cache_flush_group(string $group): bool
    {
        return $GLOBALS['wp_object_cache']->flush_group($group);
    }
}

if (!function_exists('wp_cache_close')) {
    function wp_cache_close(): bool
    {
        return $GLOBALS['wp_object_cache']->close();
    }
}

if (!function_exists('wp_cache_add_global_groups')) {
    /**
     * @param string|array<int, string> $groups
     */
    function wp_cache_add_global_groups($groups): void
    {
        $GLOBALS['wp_object_cache']->add_global_groups($groups);
    }
}

if (!function_exists('wp_cache_add_non_persistent_groups')) {
    /**
     * @param string|array<int, string> $groups
     */
    function wp_cache_add_non_persistent_groups($groups): void
    {
        $GLOBALS['wp_object_cache']->add_non_persistent_groups($groups);
    }
}

if (!function_exists('wp_cache_switch_to_blog')) {
    function wp_cache_switch_to_blog(int $blog_id): void
    {
        $GLOBALS['wp_object_cache']->switch_to_blog($blog_id);
    }
}

if (!function_exists('wp_cache_reset')) {
    /**
     * Deprecated in WordPress core; retained as an alias of
     * `wp_cache_flush_runtime()` for plugins that still call it.
     */
    function wp_cache_reset(): bool
    {
        return $GLOBALS['wp_object_cache']->flush_runtime();
    }
}

if (!function_exists('wp_cache_supports')) {
    function wp_cache_supports(string $feature): bool
    {
        return match ($feature) {
            'add_multiple',
            'set_multiple',
            'get_multiple',
            'delete_multiple',
            'flush_runtime',
            'flush_group' => true,
            default => false,
        };
    }
}
