<?php

declare(strict_types=1);

namespace Ephpm\Cache\WordPress;

/**
 * Framework-agnostic WordPress object-cache engine backed by ePHPm's
 * in-process KV store.
 *
 * This class implements the surface WordPress core actually calls on its
 * `$GLOBALS['wp_object_cache']` instance (the `WP_Object_Cache` contract).
 * The thin `dropin/object-cache.php` shim defines the global
 * `wp_cache_*()` functions and delegates each to an instance of this class.
 *
 * Two layers of caching are maintained, matching WordPress core's own
 * `WP_Object_Cache` behaviour:
 *
 *  1. A per-request runtime array (`$cache[$group][$key]`). WordPress
 *     guarantees that a value set during a request is served from memory
 *     for the rest of that request, so every read consults this first.
 *  2. The persistent KV store (via {@see KvOpsInterface}), shared across
 *     requests and (in clustered ePHPm) across nodes.
 *
 * Groups can be marked:
 *
 *  - **global** — not prefixed with the current blog id (shared across a
 *    multisite network). E.g. `users`, `useremail`, `site-options`.
 *  - **non-persistent** — kept ONLY in the runtime array and never written
 *    to the KV store. WordPress adds groups like `counts` and `plugins`
 *    here because they're cheap to recompute and pollute a shared cache.
 *
 * Serialization mirrors the sibling stores' approach: integer-looking
 * scalars are stored as their raw string so the SAPI's atomic
 * `incr`/`decr` keep operating on native integers; everything else is
 * PHP-`serialize()`d.
 */
final class ObjectCache
{
    private KvOpsInterface $ops;

    /**
     * Per-request runtime cache: `$cache[$group][$key] = $value`.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $cache = [];

    /**
     * Groups shared across a multisite network (not blog-prefixed).
     *
     * @var array<string, bool>
     */
    private array $globalGroups = [];

    /**
     * Groups kept only in the runtime array, never written to the store.
     *
     * @var array<string, bool>
     */
    private array $nonPersistentGroups = [];

    /**
     * Multisite key prefix, derived from the current blog id. Empty on a
     * single-site install (blog id 1 still yields "1", which is harmless).
     */
    private string $blogPrefix;

    private int $blogId;

    public function __construct(?KvOpsInterface $ops = null)
    {
        $this->ops = $ops ?? new SapiKvOps();

        $blogId = 1;
        if (\function_exists('get_current_blog_id')) {
            /** @var int $blogId */
            $blogId = (int) \get_current_blog_id();
        }
        $this->blogId = $blogId;
        $this->blogPrefix = (string) $blogId;
    }

    /**
     * Mark one or more groups as global (shared across a multisite network
     * and therefore not blog-prefixed).
     *
     * @param string|array<int, string> $groups
     */
    public function add_global_groups(array|string $groups): void
    {
        foreach ((array) $groups as $group) {
            $this->globalGroups[$group] = true;
        }
    }

    /**
     * Mark one or more groups as non-persistent (runtime-only; never
     * written to the KV store).
     *
     * @param string|array<int, string> $groups
     */
    public function add_non_persistent_groups(array|string $groups): void
    {
        foreach ((array) $groups as $group) {
            $this->nonPersistentGroups[$group] = true;
        }
    }

    /**
     * Switch the active blog id used to namespace non-global groups.
     * Mirrors WordPress's `WP_Object_Cache::switch_to_blog()`.
     */
    public function switch_to_blog(int $blog_id): void
    {
        $this->blogId = $blog_id;
        $this->blogPrefix = (string) $blog_id;
    }

    /**
     * Build the stable string key used in the KV store for a
     * (group, key) pair. Non-global groups are namespaced by blog id so a
     * multisite network's per-site caches don't collide.
     *
     * Shape: `wp:{global|<blog_id>}:{group}:{key}`.
     */
    public function build_key(int|string $key, string $group = 'default'): string
    {
        if ($group === '') {
            $group = 'default';
        }
        $scope = isset($this->globalGroups[$group]) ? 'global' : $this->blogPrefix;
        return 'wp:' . $scope . ':' . $group . ':' . $key;
    }

    /**
     * Add a value only if its key is not already present (runtime or store).
     *
     * @param int|string $key
     */
    public function add($key, mixed $data, string $group = 'default', int $ttl = 0): bool
    {
        if ($group === '') {
            $group = 'default';
        }
        if (\function_exists('wp_suspend_cache_addition') && \wp_suspend_cache_addition()) {
            return false;
        }
        if ($this->existsInRuntime($key, $group) || $this->existsInStore($key, $group)) {
            return false;
        }
        return $this->set($key, $data, $group, $ttl);
    }

    /**
     * Add multiple values, each only if absent.
     *
     * @param array<int|string, mixed> $data
     *
     * @return array<int|string, bool>
     */
    public function add_multiple(array $data, string $group = 'default', int $ttl = 0): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = $this->add($key, $value, $group, $ttl);
        }
        return $result;
    }

    /**
     * Replace a value only if its key already exists (runtime or store).
     *
     * @param int|string $key
     */
    public function replace($key, mixed $data, string $group = 'default', int $ttl = 0): bool
    {
        if ($group === '') {
            $group = 'default';
        }
        if (!$this->existsInRuntime($key, $group) && !$this->existsInStore($key, $group)) {
            return false;
        }
        return $this->set($key, $data, $group, $ttl);
    }

    /**
     * Set a value, writing through to the runtime array and (for
     * persistent groups) the KV store.
     *
     * @param int|string $key
     */
    public function set($key, mixed $data, string $group = 'default', int $ttl = 0): bool
    {
        if ($group === '') {
            $group = 'default';
        }

        if (\is_object($data)) {
            $data = clone $data;
        }
        $this->cache[$group][(string) $key] = $data;

        if ($this->isNonPersistent($group)) {
            return true;
        }

        return $this->ops->set(
            $this->build_key($key, $group),
            $this->serialize($data),
            \max(0, $ttl),
        );
    }

    /**
     * Set multiple values.
     *
     * @param array<int|string, mixed> $data
     *
     * @return array<int|string, bool>
     */
    public function set_multiple(array $data, string $group = 'default', int $ttl = 0): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = $this->set($key, $value, $group, $ttl);
        }
        return $result;
    }

    /**
     * Get a value. Serves from the runtime array first; on a runtime miss
     * (or when `$force` is true) consults the persistent store and hydrates
     * the runtime array.
     *
     * @param int|string $key
     * @param bool       $found set by reference to whether the key was found
     */
    public function get($key, string $group = 'default', bool $force = false, ?bool &$found = null): mixed
    {
        if ($group === '') {
            $group = 'default';
        }
        $skey = (string) $key;

        if (!$force && isset($this->cache[$group]) && \array_key_exists($skey, $this->cache[$group])) {
            $found = true;
            $value = $this->cache[$group][$skey];
            return \is_object($value) ? clone $value : $value;
        }

        if ($this->isNonPersistent($group)) {
            $found = false;
            return false;
        }

        $raw = $this->ops->get($this->build_key($key, $group));
        if ($raw === null) {
            $found = false;
            return false;
        }

        $value = $this->unserialize($raw);
        $this->cache[$group][$skey] = $value;
        $found = true;
        return \is_object($value) ? clone $value : $value;
    }

    /**
     * Get multiple values at once.
     *
     * @param array<int, int|string> $keys
     *
     * @return array<int|string, mixed> map of key => value (false on miss)
     */
    public function get_multiple(array $keys, string $group = 'default', bool $force = false): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $group, $force);
        }
        return $result;
    }

    /**
     * Delete a key from both the runtime array and the store.
     *
     * @param int|string $key
     */
    public function delete($key, string $group = 'default'): bool
    {
        if ($group === '') {
            $group = 'default';
        }
        unset($this->cache[$group][(string) $key]);

        if ($this->isNonPersistent($group)) {
            return true;
        }

        return $this->ops->del($this->build_key($key, $group)) > 0;
    }

    /**
     * Delete multiple keys.
     *
     * @param array<int, int|string> $keys
     *
     * @return array<int|string, bool>
     */
    public function delete_multiple(array $keys, string $group = 'default'): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->delete($key, $group);
        }
        return $result;
    }

    /**
     * Atomically increment a numeric value, returning the new value (or
     * false if the existing value isn't numeric).
     *
     * @param int|string $key
     */
    public function incr($key, int $offset = 1, string $group = 'default'): int|false
    {
        if ($group === '') {
            $group = 'default';
        }

        if ($this->isNonPersistent($group)) {
            return $this->incrRuntime($key, $offset, $group);
        }

        try {
            $value = $this->ops->incrBy($this->build_key($key, $group), $offset);
        } catch (\RuntimeException) {
            return false;
        }
        $this->cache[$group][(string) $key] = $value;
        return $value;
    }

    /**
     * Atomically decrement a numeric value, returning the new value (or
     * false if the existing value isn't numeric).
     *
     * @param int|string $key
     */
    public function decr($key, int $offset = 1, string $group = 'default'): int|false
    {
        return $this->incr($key, -$offset, $group);
    }

    /**
     * Flush the *entire* cache: the persistent KV store AND the runtime
     * array. This is a real flush — unlike the sibling adapters, the KV
     * SAPI exposes `ephpm_kv_flush_all()`, so `wp_cache_flush()` actually
     * clears everything.
     */
    public function flush(): bool
    {
        $this->cache = [];
        return $this->ops->flush();
    }

    /**
     * Flush only the per-request runtime array, leaving the persistent
     * store intact. Backs `wp_cache_flush_runtime()`.
     */
    public function flush_runtime(): bool
    {
        $this->cache = [];
        return true;
    }

    /**
     * Best-effort flush of a single group.
     *
     * The KV SAPI offers no key-enumeration or prefix-scan primitive, so a
     * persistent group cannot be selectively cleared without tracking every
     * key we've written. We therefore clear the group from the runtime
     * array (always possible) and report success only for non-persistent
     * groups, where the runtime array IS the whole story. For persistent
     * groups this returns false to stay honest: the in-store entries
     * remain and will age out via their TTLs (or a full `flush()`).
     */
    public function flush_group(string $group): bool
    {
        if ($group === '') {
            $group = 'default';
        }
        unset($this->cache[$group]);

        return $this->isNonPersistent($group);
    }

    /**
     * Close the cache. The KV store is process-resident and needs no
     * teardown, so this is a no-op that exists for API completeness.
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * Cache statistics. The SAPI doesn't expose hit/miss counters, so this
     * is intentionally a no-op for parity with WordPress's
     * `WP_Object_Cache::stats()`.
     */
    public function stats(): void
    {
        // No-op: ePHPm's KV does not surface per-cache hit/miss counters.
    }

    /**
     * The blog id currently used to namespace non-global groups.
     */
    public function get_blog_id(): int
    {
        return $this->blogId;
    }

    private function isNonPersistent(string $group): bool
    {
        return isset($this->nonPersistentGroups[$group]);
    }

    /**
     * @param int|string $key
     */
    private function existsInRuntime($key, string $group): bool
    {
        return isset($this->cache[$group]) && \array_key_exists((string) $key, $this->cache[$group]);
    }

    /**
     * @param int|string $key
     */
    private function existsInStore($key, string $group): bool
    {
        if ($this->isNonPersistent($group)) {
            return false;
        }
        return $this->ops->exists($this->build_key($key, $group));
    }

    /**
     * Increment a runtime-only (non-persistent) counter.
     *
     * @param int|string $key
     */
    private function incrRuntime($key, int $offset, string $group): int|false
    {
        $skey = (string) $key;
        $current = $this->cache[$group][$skey] ?? 0;
        if (!\is_numeric($current)) {
            return false;
        }
        $next = (int) $current + $offset;
        if ($next < 0) {
            $next = 0;
        }
        $this->cache[$group][$skey] = $next;
        return $next;
    }

    /**
     * Mirror the sibling stores' serialization: integer-looking scalars
     * pass through as their raw string so the SAPI's atomic incr/decr keep
     * operating on native integers. Everything else is PHP-serialized.
     */
    private function serialize(mixed $value): string
    {
        if (\is_int($value)) {
            return (string) $value;
        }
        if (\is_string($value) && \preg_match('/^-?\d+$/', $value) === 1) {
            return $value;
        }
        return \serialize($value);
    }

    private function unserialize(string $value): mixed
    {
        if (\preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        return \unserialize($value);
    }
}
