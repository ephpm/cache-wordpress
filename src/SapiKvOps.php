<?php

declare(strict_types=1);

namespace Ephpm\Cache\WordPress;

/**
 * Backend that calls the global `ephpm_kv_*` functions registered by
 * the ePHPm SAPI. Refuses to construct if those functions aren't present
 * so we fail fast outside the runtime instead of producing
 * "Call to undefined function" errors at request time.
 *
 * The batch methods use `ephpm_kv_mget()` / `ephpm_kv_mset()` when the
 * runtime has them (ePHPm >= v0.13.0) and fall back to one call per key
 * otherwise, so the drop-in keeps working on older runtimes.
 */
final class SapiKvOps implements BatchKvOpsInterface
{
    private bool $hasMget;

    private bool $hasMset;

    public function __construct()
    {
        if (!\function_exists('ephpm_kv_get')) {
            throw new \RuntimeException(
                'ephpm KV SAPI functions are not available. '
                . 'This object cache only works inside the ePHPm runtime; '
                . 'use Ephpm\\Cache\\WordPress\\InMemoryKvOps in tests.'
            );
        }
        $this->hasMget = \function_exists('ephpm_kv_mget');
        $this->hasMset = \function_exists('ephpm_kv_mset');
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

    public function setnx(string $key, string $value, int $ttlSeconds = 0): bool
    {
        return (bool) \ephpm_kv_setnx($key, $value, $ttlSeconds);
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
        // ephpm_kv_incr_by returns `false` when the stored value is not an
        // integer. A bare `(int) false === 0` would silently mask that as a
        // legitimate zero result, so capture and distinguish it: the
        // interface documents this method as throwing on a non-integer value.
        $result = \ephpm_kv_incr_by($key, $delta);
        if ($result === false) {
            throw new \RuntimeException("value at key '{$key}' is not an integer");
        }
        return (int) $result;
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

    public function mget(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        if ($this->hasMget) {
            $values = \ephpm_kv_mget($keys);
            // false = the batch as a whole failed (e.g. one non-UTF-8 key).
            // Re-read key by key so one bad key misses alone, exactly as
            // the per-key path would, instead of failing the whole batch.
            if (\is_array($values) && \count($values) === \count($keys)) {
                /** @var list<string|null> */
                return \array_values($values);
            }
        }
        $out = [];
        foreach ($keys as $key) {
            $out[] = $this->get($key);
        }
        return $out;
    }

    public function mset(array $values, int $ttlSeconds = 0): array
    {
        if ($values === []) {
            return [];
        }
        // mset stores every key or reports false with an unknown prefix
        // already written. Writes are idempotent, so on false we redo them
        // one by one to learn (and report) exactly which keys landed.
        if ($this->hasMset && \ephpm_kv_mset($values, $ttlSeconds) === true) {
            return \array_fill_keys(\array_keys($values), true);
        }
        $out = [];
        foreach ($values as $key => $value) {
            $out[(string) $key] = $this->set((string) $key, $value, $ttlSeconds);
        }
        return $out;
    }
}
