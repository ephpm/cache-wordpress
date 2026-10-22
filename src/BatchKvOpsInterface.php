<?php

declare(strict_types=1);

namespace Ephpm\Cache\WordPress;

/**
 * Optional batch extension of {@see KvOpsInterface}.
 *
 * {@see ObjectCache} uses these methods for `get_multiple()` /
 * `set_multiple()` when its backend implements this interface, and falls
 * back to one `get()` / `set()` per key otherwise. Keeping it a separate
 * interface means existing third-party `KvOpsInterface` implementations keep
 * working unchanged.
 *
 * Production ({@see SapiKvOps}) backs these with `ephpm_kv_mget()` /
 * `ephpm_kv_mset()` (ePHPm >= v0.13.0) — one call into the store instead of
 * N — and degrades to per-key calls on older runtimes.
 */
interface BatchKvOpsInterface extends KvOpsInterface
{
    /**
     * Read several keys at once.
     *
     * @param list<string> $keys
     *
     * @return list<string|null> aligned with `$keys` (same order and count);
     *                           null marks a missing key
     */
    public function mget(array $keys): array;

    /**
     * Write several keys at once, all with the same TTL.
     *
     * @param array<string, string> $values     key => value
     * @param int                   $ttlSeconds 0 means no expiry
     *
     * @return array<string, bool> key => whether that key was written
     */
    public function mset(array $values, int $ttlSeconds = 0): array;
}
