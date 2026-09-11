<?php

declare(strict_types=1);

namespace Ephpm\Cache\WordPress\Tests;

use Ephpm\Cache\WordPress\InMemoryKvOps;
use Ephpm\Cache\WordPress\KvOpsInterface;
use Ephpm\Cache\WordPress\ObjectCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObjectCache::class)]
#[CoversClass(InMemoryKvOps::class)]
final class ObjectCacheTest extends TestCase
{
    public function test_set_and_get_round_trip(): void
    {
        $cache = new ObjectCache(new InMemoryKvOps());
        self::assertTrue($cache->set('user:42', ['name' => 'Alice']));
        self::assertSame(['name' => 'Alice'], $cache->get('user:42'));
    }

    public function test_get_sets_found_by_reference(): void
    {
        $cache = new ObjectCache(new InMemoryKvOps());
        $cache->set('present', 'v');

        $found = null;
        self::assertSame('v', $cache->get('present', 'default', false, $found));
        self::assertTrue($found);

        $found = null;
        self::assertFalse($cache->get('absent', 'default', false, $found));
        self::assertFalse($found);
    }

    public function test_int_round_trips_as_int(): void
    {
        $cache = new ObjectCache(new InMemoryKvOps());
        // force=true to bypass the runtime layer and exercise (un)serialize.
        $cache->set('n', 42);
        $back = $cache->get('n', 'default', true);
        self::assertSame(42, $back);
    }

    public function test_object_round_trips(): void
    {
        $cache = new ObjectCache(new InMemoryKvOps());
        $obj = (object) ['name' => 'Bob', 'tags' => ['a', 'b']];
        $cache->set('obj', $obj);
        $back = $cache->get('obj', 'default', true);
        self::assertIsObject($back);
        self::assertSame('Bob', $back->name);
        self::assertSame(['a', 'b'], $back->tags);
    }

    public function test_add_only_when_absent(): void
    {
        $cache = new ObjectCache(new InMemoryKvOps());
        self::assertTrue($cache->add('k', 'first'));
        self::assertFalse($cache->add('k', 'second'));
        self::assertSame('first', $cache->get('k'));
    }

    public function test_add_is_atomic_via_setnx(): void
    {
        // Two independent ObjectCache instances over ONE shared backend model
        // two concurrent requests/nodes racing wp_cache_add() (which core uses
        // as a lock/cron mutex). Exactly one add() may win, and the losing
        // add() must not clobber the winner's value. A check-then-set add()
        // could let both observe "absent" and both write; setnx cannot.
        $shared = new InMemoryKvOps();
        $a = new ObjectCache($shared);
        $b = new ObjectCache($shared);

        self::assertTrue($a->add('lock', 'owner-a', 'locks'));
        self::assertFalse($b->add('lock', 'owner-b', 'locks'));

        // The store still holds the winner's value, unchanged.
        self::assertSame('owner-a', $b->get('lock', 'locks', true));
        self::assertSame('owner-a', $a->get('lock', 'locks', true));
    }

    public function test_add_persistent_path_uses_setnx_not_exists_then_set(): void
    {
        // Proves the atomic primitive is actually exercised on the persistent
        // path: a single setnx, with no separate exists()+set() race window.
        $spy = new SpyKvOps();
        $cache = new ObjectCache($spy);

        self::assertTrue($cache->add('k', 'v', 'options'));
        self::assertSame(['setnx'], $spy->calls);
    }

    public function test_replace_only_when_present(): void
    {
        $cache = new ObjectCache(new InMemoryKvOps());
        self::assertFalse($cache->replace('missing', 'v'));
        $cache->set('k', 'orig');
        self::assertTrue($cache->replace('k', 'new'));
        self::assertSame('new', $cache->get('k'));
    }

    public function test_delete_removes_from_runtime_and_store(): void
    {
        $ops = new InMemoryKvOps();
        $cache = new ObjectCache($ops);
        $cache->set('k', 'v');
        self::assertTrue($cache->delete('k'));
        self::assertFalse($cache->get('k'));
        // Deleting again is a miss.
        self::assertFalse($cache->delete('k'));
    }

    public function test_incr_and_decr(): void
    {
        $cache = new ObjectCache(new InMemoryKvOps());
        // WP core does not create a key on incr(); seed it first.
        $cache->set('hits', 0);
        self::assertSame(1, $cache->incr('hits'));
        self::assertSame(6, $cache->incr('hits', 5));
        self::assertSame(4, $cache->decr('hits', 2));
        self::assertSame(4, $cache->get('hits', 'default', true));
    }

    public function test_incr_returns_false_on_non_numeric(): void
    {
        $cache = new ObjectCache(new InMemoryKvOps());
        $cache->set('label', 'not-a-number');
        self::assertFalse($cache->incr('label'));
    }

    public function test_incr_on_missing_persistent_key_returns_false(): void
    {
        // WP core returns false for incr/decr on a missing key (it does NOT
        // create-at-delta the way the raw KV SAPI's incr_by would). Nothing
        // must be written to the store as a side effect.
        $ops = new InMemoryKvOps();
        $cache = new ObjectCache($ops);

        self::assertFalse($cache->incr('never-seen'));
        self::assertFalse($cache->decr('never-seen'));
        self::assertNull($ops->get($cache->build_key('never-seen')));
        self::assertFalse($cache->get('never-seen', 'default', true));
    }

    public function test_incr_on_missing_non_persistent_key_returns_false(): void
    {
        $cache = new ObjectCache(new InMemoryKvOps());
        $cache->add_non_persistent_groups('counts');
        self::assertFalse($cache->incr('misses', 1, 'counts'));
    }

    public function test_force_read_of_non_persistent_group_stays_local(): void
    {
        // force=true bypasses the local cache only for PERSISTENT groups (to
        // re-read the shared store). A runtime-only group has no store behind
        // it, so a forced read must still return the runtime value and never
        // reach the backend.
        $spy = new SpyKvOps();
        $cache = new ObjectCache($spy);
        $cache->add_non_persistent_groups('counts');
        $cache->set('comments', 5, 'counts');

        $found = null;
        self::assertSame(5, $cache->get('comments', 'counts', true, $found));
        self::assertTrue($found);

        $found = null;
        self::assertFalse($cache->get('absent', 'counts', true, $found));
        self::assertFalse($found);

        self::assertSame(
            [],
            $spy->calls,
            'a forced read of a non-persistent group must not touch the backend',
        );
    }

    public function test_get_multiple_and_set_multiple(): void
    {
        $cache = new ObjectCache(new InMemoryKvOps());
        $result = $cache->set_multiple(['a' => 1, 'b' => 'two', 'c' => [3]]);
        self::assertSame(['a' => true, 'b' => true, 'c' => true], $result);

        $got = $cache->get_multiple(['a', 'b', 'missing', 'c']);
        self::assertSame(1, $got['a']);
        self::assertSame('two', $got['b']);
        self::assertFalse($got['missing']);
        self::assertSame([3], $got['c']);
    }

    public function test_add_multiple_and_delete_multiple(): void
    {
        $cache = new ObjectCache(new InMemoryKvOps());
        $added = $cache->add_multiple(['x' => 1, 'y' => 2]);
        self::assertSame(['x' => true, 'y' => true], $added);

        $deleted = $cache->delete_multiple(['x', 'nope']);
        self::assertTrue($deleted['x']);
        self::assertFalse($deleted['nope']);
    }

    public function test_flush_clears_everything(): void
    {
        $ops = new InMemoryKvOps();
        $cache = new ObjectCache($ops);
        $cache->set('k', 'v');
        self::assertTrue($cache->flush());
        // Both runtime and store are empty: force read still misses.
        self::assertFalse($cache->get('k', 'default', true));
        self::assertNull($ops->get($cache->build_key('k')));
    }

    public function test_flush_runtime_clears_only_runtime(): void
    {
        $ops = new InMemoryKvOps();
        $cache = new ObjectCache($ops);
        $cache->set('k', 'persisted');

        self::assertTrue($cache->flush_runtime());
        // Store still has it...
        self::assertNotNull($ops->get($cache->build_key('k')));
        // ...and a forced read repopulates the runtime layer from the store.
        self::assertSame('persisted', $cache->get('k', 'default', true));
    }

    public function test_flush_group_runtime_only_for_persistent_group(): void
    {
        $ops = new InMemoryKvOps();
        $cache = new ObjectCache($ops);
        $cache->set('k', 'v', 'widgets');

        // Persistent group: honest false (store can't be selectively cleared).
        self::assertFalse($cache->flush_group('widgets'));
        // Runtime entry is gone, but the persistent copy remains.
        self::assertNotNull($ops->get($cache->build_key('k', 'widgets')));
        self::assertSame('v', $cache->get('k', 'widgets', true));
    }

    public function test_flush_group_true_for_non_persistent_group(): void
    {
        $cache = new ObjectCache(new InMemoryKvOps());
        $cache->add_non_persistent_groups('counts');
        $cache->set('comments', 5, 'counts');
        self::assertTrue($cache->flush_group('counts'));
        self::assertFalse($cache->get('comments', 'counts'));
    }

    public function test_non_persistent_group_never_touches_backend(): void
    {
        $spy = new SpyKvOps();
        $cache = new ObjectCache($spy);
        $cache->add_non_persistent_groups(['counts', 'plugins']);

        $cache->set('comments', 5, 'counts');
        self::assertSame(5, $cache->get('comments', 'counts'));
        self::assertFalse($cache->add('comments', 99, 'counts'));
        $cache->set('list', ['a'], 'plugins');
        self::assertSame(['a'], $cache->get('list', 'plugins'));
        self::assertTrue($cache->delete('comments', 'counts'));
        // Runtime incr on a non-persistent counter still works.
        $cache->set('n', 1, 'counts');
        self::assertSame(3, $cache->incr('n', 2, 'counts'));

        self::assertSame(
            [],
            $spy->calls,
            'non-persistent groups must never reach the KV backend',
        );
    }

    public function test_persistent_group_does_touch_backend(): void
    {
        $spy = new SpyKvOps();
        $cache = new ObjectCache($spy);
        $cache->set('k', 'v', 'options');
        self::assertContains('set', $spy->calls);
    }

    public function test_multisite_blog_prefixing(): void
    {
        $ops = new InMemoryKvOps();
        $cache = new ObjectCache($ops);

        $cache->switch_to_blog(2);
        $cache->set('opt', 'blog2-value', 'site');
        $key2 = $cache->build_key('opt', 'site');

        $cache->switch_to_blog(3);
        $key3 = $cache->build_key('opt', 'site');

        self::assertNotSame($key2, $key3);
        self::assertStringContainsString(':2:', $key2);
        self::assertStringContainsString(':3:', $key3);

        // Blog 3 doesn't see blog 2's value (different namespace).
        self::assertFalse($cache->get('opt', 'site', true));
        $cache->switch_to_blog(2);
        self::assertSame('blog2-value', $cache->get('opt', 'site', true));
    }

    public function test_global_group_is_not_blog_prefixed(): void
    {
        $cache = new ObjectCache(new InMemoryKvOps());
        $cache->add_global_groups('users');

        $cache->switch_to_blog(2);
        $cache->set('42', 'shared', 'users');
        $keyBlog2 = $cache->build_key('42', 'users');

        $cache->switch_to_blog(7);
        $keyBlog7 = $cache->build_key('42', 'users');

        // Global groups share one key across blogs.
        self::assertSame($keyBlog2, $keyBlog7);
        self::assertStringContainsString(':global:', $keyBlog2);
        self::assertSame('shared', $cache->get('42', 'users', true));
    }

    public function test_supports_close_and_stats_are_safe(): void
    {
        $cache = new ObjectCache(new InMemoryKvOps());
        self::assertTrue($cache->close());
        $cache->stats(); // no-op, must not throw
        self::assertSame(1, $cache->get_blog_id());
    }
}

/**
 * Spy backend that records every method invoked so tests can assert which
 * (if any) calls reach the KV store.
 */
final class SpyKvOps implements KvOpsInterface
{
    /** @var array<int, string> */
    public array $calls = [];

    private InMemoryKvOps $inner;

    public function __construct()
    {
        $this->inner = new InMemoryKvOps();
    }

    public function get(string $key): ?string
    {
        $this->calls[] = 'get';
        return $this->inner->get($key);
    }

    public function set(string $key, string $value, int $ttlSeconds = 0): bool
    {
        $this->calls[] = 'set';
        return $this->inner->set($key, $value, $ttlSeconds);
    }

    public function setnx(string $key, string $value, int $ttlSeconds = 0): bool
    {
        $this->calls[] = 'setnx';
        return $this->inner->setnx($key, $value, $ttlSeconds);
    }

    public function del(string $key): int
    {
        $this->calls[] = 'del';
        return $this->inner->del($key);
    }

    public function exists(string $key): bool
    {
        $this->calls[] = 'exists';
        return $this->inner->exists($key);
    }

    public function incrBy(string $key, int $delta): int
    {
        $this->calls[] = 'incrBy';
        return $this->inner->incrBy($key, $delta);
    }

    public function expire(string $key, int $ttlSeconds): bool
    {
        $this->calls[] = 'expire';
        return $this->inner->expire($key, $ttlSeconds);
    }

    public function ttl(string $key): int
    {
        $this->calls[] = 'ttl';
        return $this->inner->ttl($key);
    }

    public function pttl(string $key): int
    {
        $this->calls[] = 'pttl';
        return $this->inner->pttl($key);
    }

    public function flush(): bool
    {
        $this->calls[] = 'flush';
        return $this->inner->flush();
    }
}
