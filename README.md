# ephpm/cache-wordpress

WordPress [object-cache](https://developer.wordpress.org/reference/classes/wp_object_cache/)
drop-in backed by [ePHPm](https://ephpm.dev)'s in-process KV store via the
`ephpm_kv_*` SAPI functions. The same `wp_cache_get()` / `wp_cache_set()` /
`wp_cache_flush()` API WordPress core already uses, served from memory in the
same OS process — zero socket round-trips, zero RESP parsing, no Redis or
Memcached daemon to run.

```php
// Anywhere in WordPress, once wp-content/object-cache.php is in place:
wp_cache_set('user:42', ['name' => 'Alice'], 'users', 3600);
wp_cache_get('user:42', 'users');     // ['name' => 'Alice']
wp_cache_set('hits:home', 0);         // seed the counter first
wp_cache_incr('hits:home');           // 1  (incr on a missing key returns false, per WP core)
wp_cache_flush();                     // really clears the whole store
```

Each call resolves to a direct C function call into the Rust DashMap backing
ePHPm's KV store. There's no Redis daemon, no Memcached daemon, and there's no
socket even in-process; this is the same code path a Rust handler would take.

Unlike the other ePHPm cache adapters, **`wp_cache_flush()` actually works** —
the KV SAPI exposes `ephpm_kv_flush_all()`, so a flush clears the entire store
rather than being a documented no-op.

---

## Table of contents

- [Requirements](#requirements)
- [Install](#install)
- [How the drop-in finds its classes](#how-the-drop-in-finds-its-classes)
- [Supported features](#supported-features)
- [`wp_cache_flush()` actually works](#wp_cache_flush-actually-works)
- [Non-persistent groups](#non-persistent-groups)
- [Multisite](#multisite)
- [Verifying the cache is live](#verifying-the-cache-is-live)
- [Testing without ePHPm](#testing-without-ephpm)
- [Troubleshooting](#troubleshooting)
- [How it works](#how-it-works)
- [License](#license)

---

## Requirements

- **PHP 8.2+**
- **WordPress** with object-cache drop-in support (every supported WP release).
  This package does **not** depend on any WordPress core Composer package — it
  implements the `WP_Object_Cache` surface WordPress calls, and runs its tests
  on plain `php-cli`.
- **The ePHPm runtime, v0.1.2 or newer** (current release: v0.10.2). The
  `ephpm_kv_*` SAPI functions have shipped since ePHPm v0.1.0, but
  `ephpm_kv_flush_all()` — which backs `wp_cache_flush()` — arrived in v0.1.2.
  The functions are registered
  by ePHPm's embedded PHP. If you run WordPress under PHP-FPM, Apache mod_php, or
  the stock PHP CLI, those functions don't exist and `SapiKvOps::__construct()`
  throws. The drop-in degrades gracefully in that case (see
  [Troubleshooting](#troubleshooting)); for local development without ePHPm see
  [Testing without ePHPm](#testing-without-ephpm).

You can confirm the SAPI is present from any PHP file with:

```php
var_dump(function_exists('ephpm_kv_get'));   // expect bool(true)
```

If you get `false`, you're not running inside ePHPm.

---

## Install

ePHPm packages are distributed via their GitHub repositories, not Packagist.
Add this repo as a Composer `vcs` repository, then require the package
(`ephpm/cache-wordpress` is tagged `v0.1.0`, so `^0.1` resolves):

```bash
composer config repositories.ephpm/cache-wordpress vcs https://github.com/ephpm/cache-wordpress
composer require ephpm/cache-wordpress:^0.1
```

Then activate the drop-in by copying it into `wp-content/`:

```bash
cp vendor/ephpm/cache-wordpress/dropin/object-cache.php wp-content/object-cache.php
```

(Symlinking works too if your deployment allows it.) WordPress loads
`wp-content/object-cache.php` automatically during `wp_start_object_cache()` —
no plugin to activate, no `wp-config.php` edit required in the common case.

That's it. On the next request WordPress's entire object cache — post, term,
user, options, and transient lookups — is served from ePHPm's in-process KV
store.

---

## How the drop-in finds its classes

WordPress loads `object-cache.php` **very early** — before plugins and, usually,
before any Composer autoloader has run. The drop-in therefore loads the
package's classes itself, trying these locations in order:

1. A `EPHPM_CACHE_AUTOLOAD` constant pointing at a `vendor/autoload.php`. Define
   it in `wp-config.php` if your layout is unusual:
   ```php
   define('EPHPM_CACHE_AUTOLOAD', '/srv/app/vendor/autoload.php');
   ```
2. `WP_CONTENT_DIR/vendor/autoload.php`
3. `ABSPATH/vendor/autoload.php`
4. `vendor/autoload.php` relative to the drop-in (and a couple of parent climbs),
   covering the case where the drop-in still lives inside the installed package.
5. As a last resort, it `require_once`s the package's `src/*.php` files directly,
   resolved relative to the drop-in's own location.

If none of those resolve the `Ephpm\Cache\WordPress\ObjectCache` class, the
drop-in does **nothing** — WordPress falls back to its built-in non-persistent
runtime cache and a notice is written to the PHP error log, rather than fataling
the site. So a misconfigured autoload path degrades to "no persistent cache,"
never to a white screen.

Standard `composer require` layouts (WordPress at the project root with
`vendor/` alongside `wp-content/`) are picked up by case 2 or 3 with no extra
configuration.

---

## Supported features

The drop-in defines every `wp_cache_*` function WordPress core calls, each
delegating to the `ObjectCache` engine:

| Function(s)                                            | Backed by ePHPm KV |
|--------------------------------------------------------|--------------------|
| `wp_cache_get`, `wp_cache_set`, `wp_cache_add`, `wp_cache_replace` | yes (`add` is atomic via `ephpm_kv_setnx`) |
| `wp_cache_delete`                                      | yes                |
| `wp_cache_get_multiple`, `wp_cache_set_multiple`, `wp_cache_add_multiple`, `wp_cache_delete_multiple` | yes |
| `wp_cache_incr`, `wp_cache_decr`                       | yes (atomic via `ephpm_kv_incr_by`; miss returns `false`, per WP core) |
| `wp_cache_flush`                                       | **yes — clears the whole store** |
| `wp_cache_flush_runtime`                               | yes (runtime array only) |
| `wp_cache_flush_group`                                 | partial (see below) |
| `wp_cache_add_global_groups`, `wp_cache_add_non_persistent_groups` | yes    |
| `wp_cache_switch_to_blog`                              | yes (multisite)    |
| `wp_cache_close`, `wp_cache_init`                      | yes                |
| `wp_cache_reset` (deprecated)                          | alias of `flush_runtime` |
| `wp_cache_supports($feature)`                          | yes                |

`wp_cache_supports()` returns `true` for `add_multiple`, `set_multiple`,
`get_multiple`, `delete_multiple`, and `flush_runtime`, so core and well-behaved
plugins take their batched/fast paths. It returns `false` for `flush_group`:
the persistent tier cannot be selectively flushed (the KV SAPI has no
key-enumeration primitive), so advertising it would over-promise — see the
[`wp_cache_flush_group()` limitation](#wp_cache_flush_group-limitation) below.

Like core's own `WP_Object_Cache`, every value set during a request is served
back from an in-request runtime array for the rest of that request; the KV store
is consulted only on a runtime miss (or with `$force = true`).

### `wp_cache_flush_group()` limitation

The KV SAPI offers no key-enumeration or prefix-scan primitive, so a *persistent*
group cannot be selectively dropped from the store without tracking every key
ever written to it. `flush_group()` therefore:

- always clears that group from the **runtime** array, and
- returns `true` only for **non-persistent** groups (where the runtime array is
  the whole story);
- returns `false` for **persistent** groups — the in-store entries remain and
  age out via their TTLs (or a full `wp_cache_flush()`).

This is deliberately honest: rather than silently pretend a partial flush
happened, the return value tells the caller whether the persistent tier was
actually cleared.

---

## `wp_cache_flush()` actually works

Most object-cache drop-ins that sit on a key/value store with no `SCAN` or
`FLUSHALL` have to make `wp_cache_flush()` a no-op. This package doesn't have
that problem — and neither does the sibling `ephpm/cache-laravel` store, whose
`Cache::flush()` goes through the same mechanism. ePHPm's KV SAPI exposes
`ephpm_kv_flush_all()` (since v0.1.2), which clears the entire effective store
in one call.
`wp_cache_flush()` clears both the runtime array and the persistent store, so
plugins and `wp cache flush` behave exactly as WordPress expects.

> Note: `ephpm_kv_flush_all()` clears the **whole** KV store, not just keys this
> object cache wrote. If you also use the KV store directly (or via
> `ephpm/predis-connection`) inside the same ePHPm instance, a
> `wp_cache_flush()` will drop those keys too. Use separate ePHPm instances /
> stores if you need isolation.

---

## Non-persistent groups

WordPress marks some cache groups as *non-persistent* — cheap-to-recompute data
that shouldn't pollute a shared backend (`counts`, `plugins`, `theme_json`,
and others, plus whatever plugins register). For those groups this drop-in keeps
everything in the per-request runtime array and **never** writes to the KV
store:

```php
wp_cache_add_non_persistent_groups(['counts', 'plugins']);
wp_cache_set('comments', 5, 'counts');   // runtime only — no KV write
wp_cache_get('comments', 'counts');      // 5, from the runtime array
```

`get`/`set`/`add`/`replace`/`delete`/`incr`/`decr` on a non-persistent group
operate purely on the runtime array. The test suite asserts this with a spy
backend: a non-persistent group produces **zero** calls to the KV ops.

---

## Multisite

Non-global groups are namespaced by blog id so a network's per-site caches don't
collide; global groups (registered via `wp_cache_add_global_groups()`) share a
single namespace across the whole network. Stored keys look like:

```
wp:<blog_id>:<group>:<key>     // per-site
wp:global:<group>:<key>        // global group
```

WordPress calls `wp_cache_switch_to_blog()` as it switches sites; the drop-in
updates the blog prefix accordingly, so `switch_to_blog(2)` and
`switch_to_blog(3)` see independent caches while users/site-options stay shared.

---

## Verifying the cache is live

Drop a tiny check into any template or a must-use plugin:

```php
wp_cache_set('__healthcheck', '1', 'default', 5);
assert(wp_cache_get('__healthcheck', 'default') === '1');
```

If this round-trips you've confirmed the SAPI is loaded, the KV store is up, TTL
parsing works, and the drop-in is wired. You can also check that WordPress
considers the cache persistent:

```php
var_dump(wp_using_ext_object_cache());   // expect bool(true)
```

---

## Testing without ePHPm

The `ObjectCache` engine takes an optional `KvOpsInterface`, so you can exercise
it anywhere — including standard PHPUnit suites on plain `php-cli`, with no
WordPress and no ePHPm runtime:

```php
use Ephpm\Cache\WordPress\InMemoryKvOps;
use Ephpm\Cache\WordPress\ObjectCache;

$cache = new ObjectCache(new InMemoryKvOps());
$cache->set('foo', 'bar', 'default', 60);
assert($cache->get('foo', 'default') === 'bar');
```

`InMemoryKvOps` is for tests only — values live in PHP arrays, there is no
eviction policy, no memory limit, and TTL is best-effort lazy expiry. Don't use
it in production.

Run the suite with:

```bash
composer install
composer test
```

---

## Troubleshooting

### The site loads but nothing is being cached persistently

WordPress fell back to its built-in runtime cache because the drop-in couldn't
find the package classes. Check the PHP error log for
`ephpm object-cache.php: could not locate ...` and either install via Composer
in a standard layout or set `EPHPM_CACHE_AUTOLOAD` in `wp-config.php` (see
[How the drop-in finds its classes](#how-the-drop-in-finds-its-classes)).

### `RuntimeException: ephpm KV SAPI functions are not available`

You're not running under the ePHPm runtime, so `SapiKvOps` refused to construct.
Run WordPress through the `ephpm` binary, or for local development point the
engine at `InMemoryKvOps` (tests) — see
[Testing without ePHPm](#testing-without-ephpm).

### `wp_cache_flush()` cleared more than WordPress

`ephpm_kv_flush_all()` clears the entire KV store, including keys written by
other consumers (e.g. `ephpm/predis-connection`) on the same instance. If you
need isolation, give WordPress its own ePHPm instance/store.

### `wp cache flush-group <group>` didn't clear a persistent group

Expected — see the
[`wp_cache_flush_group()` limitation](#wp_cache_flush_group-limitation). Use a
full `wp_cache_flush()` or rely on TTLs for persistent groups.

### Cache values gone after `ephpm serve` restart

By design — ePHPm's KV is an in-process DashMap, not a durable store. Treat it as
a tier-1 cache. See the
[ePHPm KV docs](https://ephpm.dev/architecture/kv-store/) for the
durability/replication story.

---

## How it works

ePHPm runs PHP inside the same OS process as the KV store via the embed SAPI. The
store itself is a Rust [`DashMap`](https://docs.rs/dashmap/) plus TTL management.
ePHPm registers a small set of host functions (`ephpm_kv_get`, `ephpm_kv_set`,
`ephpm_kv_incr_by`, `ephpm_kv_expire`, `ephpm_kv_ttl`, `ephpm_kv_pttl`,
`ephpm_kv_del`, `ephpm_kv_exists`, `ephpm_kv_setnx`, `ephpm_kv_flush_all`) into
PHP's global function table. Calling one is a direct C function call into Rust — no socket, no
protocol parser, no value serialization beyond what userland code already does.

This package wraps those functions in a `WP_Object_Cache`-shaped engine
(`Ephpm\Cache\WordPress\ObjectCache`) and a thin `object-cache.php` drop-in that
defines the global `wp_cache_*()` functions WordPress core calls. The rest of
WordPress's caching stack — `WP_Query`, term/meta caches, the options API,
transients backed by the object cache — keeps working unchanged because it all
routes through those functions.

Integer-looking values are stored as their raw string so the SAPI's atomic
`incr`/`decr` keep operating on native integers; everything else is
PHP-`serialize()`d.

See [ephpm.dev/architecture/kv-store/](https://ephpm.dev/architecture/kv-store/)
for the architecture and [ephpm.dev/guides/kv-from-php/](https://ephpm.dev/guides/kv-from-php/)
for the underlying SAPI surface.

---

## License

MIT — see [LICENSE](LICENSE).
