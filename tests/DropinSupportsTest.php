<?php

declare(strict_types=1);

namespace Ephpm\Cache\WordPress\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Exercises the global wp_cache_supports() capability advertised by the
 * drop-in. The package classes are already autoloaded under PHPUnit, so the
 * drop-in's own loader block is skipped and it simply defines the wp_cache_*
 * shims (each guarded by function_exists, so the require is idempotent).
 */
final class DropinSupportsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!\defined('ABSPATH')) {
            // The drop-in bails unless it looks like it is loaded by WordPress.
            \define('ABSPATH', \sys_get_temp_dir() . '/');
        }
        require_once \dirname(__DIR__) . '/dropin/object-cache.php';
    }

    public function test_flush_group_is_not_advertised(): void
    {
        // The persistent tier cannot be selectively flushed (no key scan), so
        // advertising flush_group would over-promise. It must report false.
        self::assertFalse(\wp_cache_supports('flush_group'));
    }

    public function test_batch_and_flush_runtime_remain_advertised(): void
    {
        self::assertTrue(\wp_cache_supports('add_multiple'));
        self::assertTrue(\wp_cache_supports('set_multiple'));
        self::assertTrue(\wp_cache_supports('get_multiple'));
        self::assertTrue(\wp_cache_supports('delete_multiple'));
        self::assertTrue(\wp_cache_supports('flush_runtime'));
    }

    public function test_unknown_capability_is_false(): void
    {
        self::assertFalse(\wp_cache_supports('teleportation'));
    }
}
