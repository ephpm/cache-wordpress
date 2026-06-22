<?php

declare(strict_types=1);

namespace Ephpm\Cache\WordPress;

/**
 * Backend that calls the global `ephpm_kv_*` functions registered by
 * the ePHPm SAPI. Refuses to construct if those functions aren't present
 * so we fail fast outside the runtime instead of producing
 * "Call to undefined function" errors at request time.
 */
final class SapiKvOps implements KvOpsInterface
{
    public function __construct()
    {
        if (!\function_exists('ephpm_kv_get')) {
            throw new \RuntimeException(
                'ephpm KV SAPI functions are not available. '
                . 'This object cache only works inside the ePHPm runtime; '
                . 'use Ephpm\\Cache\\WordPress\\InMemoryKvOps in tests.'
            );
        }
    }

    public function get(string $key): ?string
    {
        /** @var string|null */
        return \ephpm_kv_get($key);
    }

    public function set(string $key, string $value, int $ttlSeconds = 0): bool
    {
        return (bool) \ephpm_kv_set($key, $value, $ttlSeconds);
    }

    public function del(string $key): int
    {
        return (int) \ephpm_kv_del($key);
    }

    public function exists(string $key): bool
    {
        return (bool) \ephpm_kv_exists($key);
    }

    public function incrBy(string $key, int $delta): int
    {
        return (int) \ephpm_kv_incr_by($key, $delta);
    }

    public function expire(string $key, int $ttlSeconds): bool
    {
        return (bool) \ephpm_kv_expire($key, $ttlSeconds);
    }

    public function ttl(string $key): int
    {
        return (int) \ephpm_kv_ttl($key);
    }

    public function pttl(string $key): int
    {
        return (int) \ephpm_kv_pttl($key);
    }

    public function flush(): bool
    {
        // ephpm_kv_flush_all() was added after the original SAPI surface
        // (ePHPm v0.1.2). Guard it so the drop-in still works on older
        // runtimes: flush is simply unavailable there (wp_cache_flush()
        // returns false and entries age out via TTL) rather than fataling
        // the request with "Call to undefined function".
        if (!\function_exists('ephpm_kv_flush_all')) {
            return false;
        }
        return (bool) \ephpm_kv_flush_all();
    }
}
