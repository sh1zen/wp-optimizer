# WP Optimizer External API

This document describes the public PHP functions that other plugins, themes, importers, cron jobs, and maintenance scripts can use to interact with WP Optimizer.

Public APIs are loaded from `extensions/wp-optimizer/inc/functions.php` and `extensions/wp-optimizer/inc/cache-api.php` when the WP Optimizer plugin is active. External code should always check `function_exists()` before calling these functions, because WP Optimizer may be disabled, not installed, or loaded later than the caller.

## General Rules

- Treat these functions as the supported integration layer. Do not instantiate WP Optimizer modules directly from external plugins.
- Always guard calls with `function_exists()`.
- Do not assume a cache layer is enabled just because WP Optimizer is installed.
- For bulk operations, suspend cache auto-purge before the operation and resume it once at the end.
- If a bulk operation fails before completion, resume without forcing a flush unless the caller can prove the operation committed valid data.

## Cache API

Use these functions when external code needs to coordinate with WP Optimizer cache without knowing which cache layers are active. The Cache module decides which active layers are affected.

The general API covers:

- Static Pages Cache
- WP_Query Cache
- WP DB Cache
- Object Cache

### `wpopt_is_cache_active(): bool`

Returns whether WP Optimizer Cache is available and at least one cache layer is active, including Object Cache.

Use this before calling cache control functions when cache integration is optional.

```php
if (function_exists('wpopt_is_cache_active') && wpopt_is_cache_active()) {
    // At least one WP Optimizer cache layer is active.
}
```

### `wpopt_suspend_cache_auto_purge(string $source = 'external'): bool`

Suspends cache auto-purge for all active cache layers in the current request.

This is useful during bulk writes where WordPress fires many cache invalidation hooks, such as imports, migrations, mass edits, or synchronization jobs. While suspended, purge requests mark active cache layers as dirty instead of scanning and deleting entries for each object.

For runtime cache layers that can affect read consistency during writes, WP Optimizer also bypasses stale cache reads for the current request:

- WP_Query Cache
- WP DB Cache
- Object Cache

WP DB Cache and WP_Query Cache also skip storing new cached query results while runtime cache is suspended. Object Cache write operations are not forced to fail, because WordPress may use them for request-local locks and transient bookkeeping. The final resume flush still clears dirty active cache layers once.

Returns `true` when suspension was applied. Returns `false` when WP Optimizer, the Cache module, or all cache layers are unavailable/inactive.

```php
$wpopt_cache_suspended = function_exists('wpopt_suspend_cache_auto_purge')
    && wpopt_suspend_cache_auto_purge('my-importer');
```

### `wpopt_resume_cache_auto_purge(bool $flush_if_dirty = true, string $source = 'external'): bool`

Resumes cache auto-purge for the current request.

If `$flush_if_dirty` is `true`, WP Optimizer flushes dirty active cache layers once. This is the recommended completion path for successful bulk operations.

Returns `true` when resume succeeded or a final flush was executed. Returns `false` when there was no active suspension or the cache layer is unavailable.

```php
if ($wpopt_cache_suspended && function_exists('wpopt_resume_cache_auto_purge')) {
    wpopt_resume_cache_auto_purge(true, 'my-importer');
}
```

### `wpopt_flush_cache(string $source = 'external'): bool`

Flushes all active WP Optimizer cache layers immediately, including Object Cache when enabled.

Use this when external code knows cached contents are stale and a full flush is safer than selective invalidation.

Returns `true` when at least one active cache layer was flushed. Returns `false` when WP Optimizer Cache is unavailable or all cache layers are inactive.

```php
if (function_exists('wpopt_flush_cache')) {
    wpopt_flush_cache('my-maintenance-task');
}
```

### `wpopt_cache_auto_purge_is_suspended(string $layer = ''): bool`

Returns whether cache auto-purge is currently suspended for the request.

This function is mainly for WP Optimizer cache layers and integration code that needs to avoid expensive selective invalidations during a bulk operation. Calling it with a layer name also marks that layer dirty for the final resume flush.

### `wpopt_cache_runtime_is_suspended(string $layer = ''): bool`

Returns whether runtime cache usage is currently suspended for the request.

This function is mainly for cache implementations. External bulk jobs should call `wpopt_suspend_cache_auto_purge()` and `wpopt_resume_cache_auto_purge()` instead of manually checking runtime state.

## Recommended Bulk Operation Pattern

Use this pattern when external code creates, updates, deletes, or retags many posts in one request.

```php
$wpopt_cache_suspended = function_exists('wpopt_is_cache_active')
    && wpopt_is_cache_active()
    && function_exists('wpopt_suspend_cache_auto_purge')
    && wpopt_suspend_cache_auto_purge('my-bulk-job');

try {
    // Run the bulk operation here.
    // WordPress may call clean_post_cache(), clean_term_cache(),
    // clean_object_term_cache(), and related hooks many times.

    if ($wpopt_cache_suspended && function_exists('wpopt_resume_cache_auto_purge')) {
        wpopt_resume_cache_auto_purge(true, 'my-bulk-job');
        $wpopt_cache_suspended = false;
    }
}
finally {
    if ($wpopt_cache_suspended && function_exists('wpopt_resume_cache_auto_purge')) {
        wpopt_resume_cache_auto_purge(false, 'my-bulk-job-aborted');
    }
}
```

The successful path resumes with `$flush_if_dirty = true`, so WP Optimizer performs one final flush of dirty active cache layers. The abort path resumes with `$flush_if_dirty = false`, avoiding an expensive final flush after an incomplete operation.

## Media API

### `wpopt_optimize_image(string $path, bool $replace = true, array $settings = [])`

Optimizes a single image file.

Returns the optimized file path on success or `false` on failure.

```php
if (function_exists('wpopt_optimize_image')) {
    $optimized_path = wpopt_optimize_image($absolute_path, true);
}
```

### `wpopt_optimize_media_path(string $path, array $settings = []): bool`

Scans a directory and optimizes media files according to WP Optimizer media settings merged with `$settings`.

Returns `true` after scheduling or running the scan.

```php
if (function_exists('wpopt_optimize_media_path')) {
    wpopt_optimize_media_path(WP_CONTENT_DIR . '/uploads/imported');
}
```

## Minify API

### `wpopt_minify_html($html, $options = [])`

Minifies an HTML string and returns the minified output.

### `wpopt_minify_css($css, $options = [])`

Minifies a CSS string and returns the minified output.

### `wpopt_minify_javascript($css, $options = [])`

Minifies a JavaScript string and returns the minified output.

```php
if (function_exists('wpopt_minify_html')) {
    $html = wpopt_minify_html($html);
}
```

## Cron Helpers

### `wpopt_remove_cron_hooks(array $hooks): bool`

Removes scheduled WordPress cron events and WPS cron metadata for the supplied hook names.

Returns `true` after cleanup is attempted.

```php
if (function_exists('wpopt_remove_cron_hooks')) {
    wpopt_remove_cron_hooks(array('my_custom_job'));
}
```

### `wpopt_cleanup_media_cron_hooks(): bool`

Removes WP Optimizer media optimization cron hooks.

This is intended for WP Optimizer cleanup and activation/deactivation flows, but it is available when an external maintenance script needs to stop pending WP Optimizer media jobs.

## Metrics API

### `wpopt_record_cache_metric(string $layer, string $outcome, int $count = 1): void`

Records request-local cache metrics.

`$layer` accepts `query` for WP_Query Cache metrics; every other value is treated as database cache. `$outcome` accepts `hit` or `miss`.

External code normally should not call this unless it is extending WP Optimizer cache internals.

### `wpopt_get_request_cache_metrics(): array`

Returns request-local cache metrics with these keys:

- `cache_hits`
- `cache_misses`
- `db_cache_hits`
- `db_cache_misses`
- `query_cache_hits`
- `query_cache_misses`

## Activity Log Encryption Helpers

These helpers are used by WP Optimizer Activity Log internals.

### `wpopt_encrypt_activity_log_password(string $password): array`

Encrypts a password payload for storage.

### `wpopt_decrypt_activity_log_password($payload): string`

Decrypts a password payload produced by `wpopt_encrypt_activity_log_password()`.

External plugins should not use these as a general-purpose encryption API.

## Error Handling

These functions return `false` when WP Optimizer is unavailable or the requested module/layer is inactive. They do not throw exceptions for missing modules.

External callers should decide whether `false` means "skip integration" or "abort current workflow".
