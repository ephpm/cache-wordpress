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
            $GLOBALS['__kv_calls'][] = 'get';
            return $GLOBALS['__kv_store'][$key] ?? null;
        }
    }
    if (!\function_exists('ephpm_kv_set')) {
        function ephpm_kv_set(string $key, string $value, int $ttl = 0): bool
        {
            $GLOBALS['__kv_calls'][] = 'set';
            if (isset($GLOBALS['__kv_set_refuse'][$key])) {
                return false;
            }
            $GLOBALS['__kv_store'][$key] = $value;
            $GLOBALS['__kv_ttls'][$key] = $ttl;
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
    if (!\function_exists('ephpm_kv_mget')) {
        /**
         * @param array<int, string> $keys
         *
         * @return array<int, string|null>|false
         */
        function ephpm_kv_mget(array $keys)
        {
            $GLOBALS['__kv_calls'][] = 'mget';
            if (($GLOBALS['__kv_mget_fail'] ?? false) === true) {
                return false;
            }
            $out = [];
            foreach ($keys as $key) {
                $out[] = $GLOBALS['__kv_store'][$key] ?? null;
            }
            return $out;
        }
    }
    if (!\function_exists('ephpm_kv_mset')) {
        /**
         * @param array<string, string> $values
         */
        function ephpm_kv_mset(array $values, int $ttl = 0): bool
        {
            $GLOBALS['__kv_calls'][] = 'mset';
            foreach ($values as $key => $value) {
                // Mirrors the SAPI: a refusal stops the write and returns
                // false with the earlier keys already stored.
                if (isset($GLOBALS['__kv_set_refuse'][$key])) {
                    return false;
                }
                $GLOBALS['__kv_store'][$key] = (string) $value;
                $GLOBALS['__kv_ttls'][$key] = $ttl;
            }
            return true;
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
        protected function setUp(): void
        {
            $GLOBALS['__kv_store'] = [];
            $GLOBALS['__kv_ttls'] = [];
            $GLOBALS['__kv_calls'] = [];
            $GLOBALS['__kv_set_refuse'] = [];
            $GLOBALS['__kv_mget_fail'] = false;
        }

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
            $ops = new SapiKvOps();
            self::assertTrue($ops->setnx('lock', 'a'));
            self::assertFalse($ops->setnx('lock', 'b'));
            self::assertSame('a', $ops->get('lock'));
        }

        public function test_mget_uses_one_native_call(): void
        {
            $GLOBALS['__kv_store'] = ['a' => '1', 'c' => '3'];
            $ops = new SapiKvOps();
            self::assertSame(['1', null, '3'], $ops->mget(['a', 'b', 'c']));
            self::assertSame(['mget'], $GLOBALS['__kv_calls']);
        }

        public function test_mget_falls_back_per_key_when_the_batch_fails(): void
        {
            // false = the whole batch failed (e.g. one non-UTF-8 key); a
            // per-key re-read makes only the bad key miss.
            $GLOBALS['__kv_store'] = ['a' => '1'];
            $GLOBALS['__kv_mget_fail'] = true;
            $ops = new SapiKvOps();
            self::assertSame(['1', null], $ops->mget(['a', 'b']));
            self::assertSame(['mget', 'get', 'get'], $GLOBALS['__kv_calls']);
        }

        public function test_mget_without_native_function_uses_per_key_get(): void
        {
            $GLOBALS['__kv_store'] = ['a' => '1'];
            $ops = self::legacyOps();
            self::assertSame(['1', null], $ops->mget(['a', 'b']));
            self::assertSame(['get', 'get'], $GLOBALS['__kv_calls']);
        }

        public function test_batch_of_nothing_makes_no_call(): void
        {
            $ops = new SapiKvOps();
            self::assertSame([], $ops->mget([]));
            self::assertSame([], $ops->mset([]));
            self::assertSame([], $GLOBALS['__kv_calls']);
        }

        public function test_mset_uses_one_native_call_with_shared_ttl(): void
        {
            $ops = new SapiKvOps();
            self::assertSame(['a' => true, 'b' => true], $ops->mset(['a' => 'x', 'b' => 'y'], 60));
            self::assertSame(['mset'], $GLOBALS['__kv_calls']);
            self::assertSame(['a' => 'x', 'b' => 'y'], $GLOBALS['__kv_store']);
            self::assertSame(['a' => 60, 'b' => 60], $GLOBALS['__kv_ttls']);
        }

        public function test_mset_refusal_reports_per_key_results(): void
        {
            // The native mset stops at the refused key; the per-key retry
            // reports exactly which keys were written.
            $GLOBALS['__kv_set_refuse'] = ['b' => true];
            $ops = new SapiKvOps();
            self::assertSame(
                ['a' => true, 'b' => false, 'c' => true],
                $ops->mset(['a' => 'x', 'b' => 'y', 'c' => 'z']),
            );
            self::assertSame(['mset', 'set', 'set', 'set'], $GLOBALS['__kv_calls']);
            self::assertSame(['a' => 'x', 'c' => 'z'], $GLOBALS['__kv_store']);
        }

        public function test_mset_without_native_function_uses_per_key_set(): void
        {
            $ops = self::legacyOps();
            self::assertSame(['a' => true, 'b' => true], $ops->mset(['a' => 'x', 'b' => 'y'], 5));
            self::assertSame(['set', 'set'], $GLOBALS['__kv_calls']);
            self::assertSame(['a' => 5, 'b' => 5], $GLOBALS['__kv_ttls']);
        }

        /**
         * Simulate a pre-v0.13.0 runtime (no ephpm_kv_mget / ephpm_kv_mset).
         * The stubs above are process-global and cannot be undefined, so flip
         * the capability SapiKvOps detected at construction instead.
         */
        private static function legacyOps(): SapiKvOps
        {
            $ops = new SapiKvOps();
            (new \ReflectionProperty(SapiKvOps::class, 'hasMget'))->setValue($ops, false);
            (new \ReflectionProperty(SapiKvOps::class, 'hasMset'))->setValue($ops, false);
            return $ops;
        }
    }
}
