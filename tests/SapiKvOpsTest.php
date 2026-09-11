<?php

declare(strict_types=1);

/*
 * Global-namespace stubs for the ephpm_kv_* SAPI functions so SapiKvOps can be
 * exercised off the ePHPm runtime. Return values are controllable through the
 * $GLOBALS['__kv_*'] slots the tests below set. Guarded with function_exists so
 * this is a no-op if a future real runtime ever defines them in-process.
 */

namespace {
    if (!\function_exists('ephpm_kv_get')) {
        function ephpm_kv_get(string $key): ?string
        {
            return $GLOBALS['__kv_store'][$key] ?? null;
        }
    }
    if (!\function_exists('ephpm_kv_set')) {
        function ephpm_kv_set(string $key, string $value, int $ttl = 0): bool
        {
            $GLOBALS['__kv_store'][$key] = $value;
            return true;
        }
    }
    if (!\function_exists('ephpm_kv_setnx')) {
        function ephpm_kv_setnx(string $key, string $value, int $ttl = 0): bool
        {
            if (isset($GLOBALS['__kv_store'][$key])) {
                return false;
            }
            $GLOBALS['__kv_store'][$key] = $value;
            return true;
        }
    }
    if (!\function_exists('ephpm_kv_incr_by')) {
        /** @return int|false */
        function ephpm_kv_incr_by(string $key, int $delta)
        {
            // Controllable: tests set __kv_incr_result to false (non-integer
            // value) or to an int (the new value).
            return $GLOBALS['__kv_incr_result'] ?? false;
        }
    }
}

namespace Ephpm\Cache\WordPress\Tests {
    use Ephpm\Cache\WordPress\SapiKvOps;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;

    #[CoversClass(SapiKvOps::class)]
    final class SapiKvOpsTest extends TestCase
    {
        public function test_incr_by_throws_when_sapi_reports_non_integer(): void
        {
            // The SAPI returns false when the stored value is not an integer.
            // The old `(int) false === 0` masked this as a legitimate zero;
            // the fix must surface it as a RuntimeException.
            $GLOBALS['__kv_incr_result'] = false;
            $ops = new SapiKvOps();

            $this->expectException(\RuntimeException::class);
            $ops->incrBy('label', 1);
        }

        public function test_incr_by_returns_int_on_success(): void
        {
            $GLOBALS['__kv_incr_result'] = 7;
            $ops = new SapiKvOps();
            self::assertSame(7, $ops->incrBy('hits', 1));
        }

        public function test_incr_by_does_not_mask_a_real_zero(): void
        {
            // A genuine 0 result must pass through as int(0), not be confused
            // with the false sentinel.
            $GLOBALS['__kv_incr_result'] = 0;
            $ops = new SapiKvOps();
            self::assertSame(0, $ops->incrBy('counter', -1));
        }

        public function test_setnx_passes_through_to_sapi(): void
        {
            unset($GLOBALS['__kv_store']);
            $ops = new SapiKvOps();
            self::assertTrue($ops->setnx('lock', 'a'));
            self::assertFalse($ops->setnx('lock', 'b'));
            self::assertSame('a', $ops->get('lock'));
        }
    }
}
