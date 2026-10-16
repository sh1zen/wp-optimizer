# WP Optimizer
Welcome to the WP Optimizer repository on GitHub.

[![Author](https://img.shields.io/badge/author-sh1zen-brightgreen.svg)](https://sh1zen.github.io/)
![License: CC-NC](https://img.shields.io/badge/License-CCNC-orange.svg)
[![Donate](https://img.shields.io/badge/Donate-PayPal-blue.svg)](https://www.paypal.com/donate/?hosted_button_id=8G8VR4APG9JRU)
[![Repo Link](https://img.shields.io/badge/Repo-Link-black.svg)](https://github.com/sh1zen/wp-optimizer)


Here you can browse the source, look at open issues and keep track of development.

If you are not a developer, please use the [WP Optimizer plugin page](https://wordpress.org/plugins/wp-optimizer/) on WordPress.org.

## Description

WP Optimizer is a modular WordPress optimization toolkit for performance, maintenance, diagnostics and site control.
It brings together the common tools needed to keep a WordPress installation fast and easy to maintain: cache, media optimization, database managment, Page Test diagnostics, update controls, configuration backups and module resets.

### Performance and cache

- **Per-page Used CSS:** the PageSpeed module builds reduced local stylesheets through WordPress Cron. WP Optimizer owns the engine and its bundled, isolated CSS parser. See [Used CSS](docs/used-css.md) for loading, safety rules and verification.
- **Frontend engines:** WP Optimizer owns LCP learning, image priority and script defer/delay, including their browser runtimes and asset policy. See [frontend performance](docs/frontend-performance.md) for loading, configuration and verification.
- **Local asset minification:** the Minify module queues cold CSS/JS assets for WordPress Cron and serves their original URLs until generated files are ready. Its worker uses a nonblocking per-asset lock and publishes complete files atomically. See [performance pipeline](PIPELINE.md) for processing and failure behavior.
- **Layered cache storage:** the shared WPS cache uses request memory first, Redis (or Memcached) when available, and WPS `Storage` as the durable fallback under `WP_CONTENT_DIR/cache`. Durable writes use the remote backend first and write disk only if that backend is absent or its write fails. Successful remote writes remove any previous fallback copy; remote-only entries are rebuilt on cache misses during an outage. The WordPress object-cache drop-in persists its default zero-expiry entries until invalidation. Positive lifetimes apply to the selected backend. Ordinary WPS callers keep zero lifetimes request-local. WP Optimizer's static page, `WP_Query` and database-query caches use their dedicated WPS Storage groups and independent lifetime, purge and exclusion rules.
- **Bulk object-cache reads:** WPS fetches request-memory misses in one Redis or Memcached bulk call. Redis group indexes follow member lifetimes, migrate existing indexes incrementally and flush in batches. Redis requires permission to run Lua scripts and their cache/index commands. See [performance processing](PIPELINE.md#object-cache).
- **Static hit maintenance:** PHP-served hits retain exact rule counters and persist them during shutdown after completing the response on FastCGI/LiteSpeed. Other SAPIs flush output but may keep the connection attached. Direct index files are written only when missing or changed. See [performance processing](PIPELINE.md#static-cache-hits).
- **Safe compatibility defaults:** compatible with WooCommerce and with editing and preview flows from Elementor, Beaver Builder, Divi, Gutenberg, Bricks, Oxygen and Breakdance. Builder requests bypass cache and output optimization, preserving generated assets and markup.
- **Protected and extensible behavior:** built-in exclusions cannot be removed, but filters can add project-specific routes, request signatures and assets. Invalid or missing direct-cache configuration is regenerated in a disabled fail-safe state.

### Maintenance and diagnostics

- **Database and scheduling tools:** maintain tables, create database backups, review `wp_options` autoload data and manage WordPress cron events and custom schedules. On WordPress Multisite, site administrators retain Database Manager access, while SQL script execution requires the `manage_network_options` capability.
- **Media metadata storage:** the plugin owns reversible media storage and publishes generic lifecycle events for external integrations. See [media metadata](docs/media-metadata.md) for storage and integration contracts.
- **Multisite lifecycle:** network-wide activation, upgrades and deactivation are applied independently to every site while preserving each site's settings, cron state and database-table prefix.
- **Four-stage Page Test:** scans a site URL with a signed optimization/cache-bypass request, an empty current-configuration pass, a diagnostic warmup and a final measured signed request using the current configuration.
- **Actionable diagnostics:** the warmup identifies slow or repeated queries, heavier hooks, callback samples and memory/query totals. Runtime HTML transformations use ordered handlers in the WPS `html_output_buffer` service, with PageSpeed processing before final HTML minification.
- **Performance Monitor storage:** configure **Maximum stored entries per table** under Shared request capture (default: 10,000; minimum: 1). Request history and Slow SQL each keep the newest entries within this limit and the 24-hour retention window.

### Configuration safety

- **Automatic backups:** configuration snapshots are created before settings changes, throttled to prevent duplicate autosaves and limited to the 50 newest entries.
- **Safe module resets:** after confirmation, individual modules can be restored to factory settings from the modules screen, including their cleanup lifecycle.
- **Multisite direct-cache isolation:** each blog has its own runtime configuration, index directory and request signature; the shared bootstrap routes by hostname and the most specific registered site path.
- **Network-owned cache drop-ins:** on Multisite, only users with `manage_network_options` can configure, install or remove the global `object-cache.php` and `db.php` drop-ins. Subsite administrators retain access to WP_Query and static-page cache settings.

### Web server compatibility

- **Automatic detection:** identifies Apache, Nginx, LiteSpeed Enterprise and OpenLiteSpeed. Apache and LiteSpeed Enterprise use generated `.htaccess` directives, while Nginx uses a generated `nginx.conf` include file.
- **Portable directory protection:** generated Apache-style security rules use `Options -Indexes`, avoiding the invalid mixed `Options All -Indexes` syntax that can make LiteSpeed reject the site `.htaccess`.
- **OpenLiteSpeed support:** because `.htaccess` accepts only Apache `mod_rewrite` syntax, WP Optimizer writes compatible rules for direct cache delivery, redirects and rewrite-based security controls. Enable **Auto Load from .htaccess** for the virtual host; configure compression, response headers, MIME types and other non-rewrite options in WebAdmin, then restart OpenLiteSpeed after rewrite changes.

### Developer integrations

External plugins, themes, importers and maintenance scripts can use the documented public PHP functions in [EXTERNAL-API.md](EXTERNAL-API.md).

### Module architecture

WP Optimizer registers modules through the explicit catalog in `inc/module-catalog.php`. The catalog declares one relative module directory and namespace; each module entry contains only its display name and scopes. The entry slug deterministically produces `<slug>.class.php` and `WPOptimizer\modules\Mod_<slug>`, so file paths and classes are never duplicated per module. The bundled WPS framework normalizes the catalog into one compact, request-shared registry and loads module files on demand. New top-level module classes must follow this convention and be registered explicitly; unregistered files are ignored and there is no discovery fallback.


## Cache deletion regression check

From the CMS root, run `php -n mini-test/disk-delete.php` with PHP 8.3 or newer.
The isolated check exercises the shared WPS `Disk` and `Storage` classes for
single-entry deletion, missing targets, concurrent removal or replacement,
recursive group purges and expiry purges. It uses temporary cache fixtures and
requires no WordPress bootstrap, database or network.

## Error tracking and storage cleanup regression check

From the CMS root, run `php -n mini-test/wps-error-handler.php` with PHP 8.0 or newer.
WPS error tracking respects PHP's active `error_reporting()` mask, including `@`
suppression, before invoking callbacks, recording notifications or chaining handlers.
The isolated check covers native missing-file warnings after a competing removal,
suppressed unserialize warnings for trailing bytes and malformed records, visible
warnings, stacked handlers, expired and invalid cache entries, zero-retention cleanup,
single-entry deletion, group and expiry purges, and public media cleanup.
It requires no WordPress bootstrap, database or network.

## Storage publication regression check

From the CMS root, run `php -n tests/storage-publication.php` and
`php -n tests/storage-publication.php --native` with PHP 7.4 or newer.
Storage writes each serialized record to a unique temporary file beside the entry,
checks the complete write and flush, closes it and renames it into place. Concurrent
readers see a complete published entry or a cache miss. Write and publication
failures return false and leave an existing entry intact. The record format and
expiry policies are shared by immediate writes, shutdown autosave and Cache's
disk fallback. See [storage publication](PIPELINE.md#storage-publication).

The isolated tests reproduce the extra-byte corruption from overlapping direct
writes, then interleave real Storage calls through a filesystem boundary wrapper
backed by native temporary files. They cover competing writers, concurrent readers,
open/short-write/flush/rename failures, temporary-file cleanup, exceptions, autosave
and disk fallback. The native mode verifies publication directly on the local
filesystem. No WordPress bootstrap, database, remote backend or network is required.

## Storage race regression check

Run `php -n tests/storage-races.php` from the CMS root with PHP 7.4 or newer and
CLI process creation enabled. This isolated check covers stale expired snapshots,
memory hydration after replacement, directory detachment and recreation, multisite
invalidation, and independent PHP processes contending on publication and age purges.
`storage-publication.php` also covers malformed snapshots, failed reads and lock
failures. No WordPress bootstrap, database or network is required.

Storage coordinates mutations with a persistent lock per namespace under
`WP_CONTENT_DIR/.wps-storage-locks`. This directory must be writable by PHP and
remain outside cache-group purges. Lock setup/acquisition failures raise a contextual
exception. Ordinary disk reads are lock-free; snapshot cleanup and memory hydration
verify the current bytes under the mutation lock. See
[storage publication](PIPELINE.md#storage-publication) for ordering and purge behavior.

## Indexed cache and Used CSS cleanup regression checks

From the CMS root, run `php -n tests/cache-cleanup.php` and
`php -n tests/used-css-cleanup.php` with PHP 7.4 or newer. The isolated checks
cover batched rule, path and dependency purges, disk/index expiry reconciliation,
recent writes, site isolation and SQL errors. They also exercise the registered
Used CSS worker, its real parser and publication, the 30-day retention policy,
the 100-deletion limit and progress past recent files. They use temporary disk
fixtures and WordPress/SQL boundary fakes; no database or network is required.

## HTML minifier regression check

HTML minification uses `xxh3` for temporary placeholder identifiers when available
and `md5` otherwise. From the CMS root, run `php -n mini-test/minify-html.php`
with PHP 7.4 and a current PHP runtime to check both algorithm paths. The isolated
check covers whitespace minification, protected content, placeholder restoration
and comment options without a WordPress bootstrap, database or network.

## Performance regression check

From the CMS root, run `php -n tests/wp-optimizer-performance.php` with PHP 7.4
or newer. The isolated suite exercises the database drop-in with real WPS disk
storage, scheduled CSS/JS minification, lock contention, atomic publication,
failure reporting and retry, scheduled-job cleanup and diagnostic sampling.
It uses WordPress and SQL boundary fakes and requires no database or network.

## Object-cache regression check

From `tests`, install the development-only Lua interpreter with
`pnpm install --frozen-lockfile --ignore-scripts --node-linker=hoisted --store-dir=.pnpm-store --config.enable-global-virtual-store=false`.
Then, from the CMS root, run `php -n tests/object-cache.php` with PHP 7.4 or newer and Node.js on PATH.
`WPOPT_TEST_NODE` can specify the Node executable explicitly. The suite exercises
the actual drop-in, WPS Cache, Storage and Redis/Memcached drivers, including
command counts, false/null values, cloning, expiry, group isolation and fallback.
Default-expiry writes are checked across cold request instances with Redis,
Memcached and disk-only storage, including replacement and nonpersistent groups.
It also checks repeated memory-hit adds without persistent I/O, disk write
counts, failed remote writes, recovery and retirement of stale fallback copies.
Fengari executes the production Redis Lua script against an in-memory command
fake to test index expiry, legacy migration and batched deletion. Installation
requires registry access; the tests themselves require no network, cache server
or credentials. The manifest and lockfile under `tests` are development-only.

## Query-cache and monitoring regression check

From the CMS root, run `php -n tests/cache-monitor.php` with PHP 7.4 or newer.
The suite uses the adjacent WordPress hook dispatcher and query/post classes with
isolated settings, term-query, storage and SQL boundaries. It checks dependency
contents, term lookup counts, bounded index inserts, callback ordering and late
registration, recursive dispatch, row limits, ID gaps/resets and failure handling.
It covers 128 consecutive full-table inserts without a full recount, cumulative
metrics through real WPS Options, and subprocess cache-hit/shutdown paths with
response-completion, disk-write and SQL counts. Boundary fakes isolate the server
finish API, platform writability flags and database; index files use real I/O.
It also checks targeted post-save purges and shared frontend invalidation.
It requires no WordPress bootstrap, database, cache server or network.

## LCP fingerprint regression check

From the CMS root, run `php -n tests/frontend-versions.php` with PHP 7.4 or newer.
The suite uses real WordPress HTML parsing and WPS disk cache fixtures to check
duplicate candidates, digest reuse across cold requests, the 1 MiB hashing limit,
metadata/revision changes, hash failures, URL boundaries and learned preloads.
It requires no WordPress bootstrap, database or network.

## Planning and summary regression checks

From the CMS root, run `php -n tests/optimizer-planning.php` with PHP 7.4 or
newer. The offline suite checks prepared CSS/script rules, multi-file CSS jobs,
late script dependencies, cache-rule precedence and normalization counts,
nested/flat and dotted settings, shared layer validation, schema/field order,
layer-specific invalidation, query-type ordering and the single-statement
autoload summary. It uses the real CSS parser, WordPress hook/script registry and WPS
settings with isolated configuration and SQL boundaries.

Run `python tests/autoload-summary.py` to execute the generated aggregate
against in-memory SQLite fixtures, comparing all four totals with the existing
reference queries. Python 3.8 or newer is required; `--php /path/to/php` selects a
PHP executable outside PATH. Both checks require no live database or network.

## Support
This repository is not suitable for support. Please don't use our issue tracker for support requests. Support can take 
place through the appropriate channel on [our community forum on wp.org](https://wordpress.org/support/plugin/wp-optimizer/).

Support requests in issues on this repository will be closed on sight.
