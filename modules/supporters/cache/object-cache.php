<?php
/**
 * Object Cache Drop-in (single-file, bootstrap-safe)
 *
 * - Tries to use \WPS\core\Cache(true) if available
 * - Falls back to WP_Object_Cache (in-memory) otherwise
 *
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

if (!defined('ABSPATH')) {
    return;
}

// If something already declared wp_cache_init, do not redeclare.
if (function_exists('wp_cache_init')) {
    return;
}

/**
 * Attempt to load wps-framework loader (best-effort, never fatal).
 */
(function (): void {
    if (defined('WPS_FRAMEWORK')) {
        return;
    }

    // Preferred explicit source
    if (defined('WPS_FRAMEWORK_SOURCE')) {
        $loader = rtrim((string)WPS_FRAMEWORK_SOURCE, "/\\") . DIRECTORY_SEPARATOR . 'loader.php';
        if (is_file($loader)) {
            require_once $loader;
            return;
        }
    }

    // Optional: fallback to a plugin/extension constant if you have it
    if (defined('WPOPT_DB_ABSPATH')) {
        $loader = rtrim((string)WPOPT_DB_ABSPATH, "/\\") . DIRECTORY_SEPARATOR . 'vendors/wps-framework/loader.php';
        if (is_file($loader)) {
            require_once $loader;
            return;
        }
    }

    // No loader found -> silently continue with fallback cache.
})();

/**
 * Ensures global $wp_object_cache is always a valid object.
 * Prefers \WPS\core\Cache(true); else uses WP_Object_Cache.
 */
function wpopt_ensure_cache_object(): void
{
    global $wp_object_cache;

    if (is_object($wp_object_cache)) {
        return;
    }

    // Prefer WPS cache if available
    if (class_exists('\WPS\core\Cache')) {
        try {
            $wp_object_cache = new \WPS\core\Cache(true);
            return;
        } catch (\Throwable $e) {
            error_log('object-cache.php: failed to init \\WPS\\core\\Cache: ' . $e->getMessage());
            // fall through
        }
    }

    // Fallback to WordPress in-memory cache
    if (!class_exists('WP_Object_Cache')) {
        require_once ABSPATH . WPINC . '/class-wp-object-cache.php';
    }

    $wp_object_cache = new WP_Object_Cache();
}

/**
 * Helper: safe debug dump.
 */
function wpopt_dump($value): void
{
    if (function_exists('wps_var_dump')) {
        wps_var_dump($value);
    }
    else {
        var_dump($value);
    }
}

/**
 * WordPress calls this from wp_start_object_cache()
 */
function wp_cache_init(): void
{
    wpopt_ensure_cache_object();
}

function wp_cache_report(): void
{
    wpopt_ensure_cache_object();
    global $wp_object_cache;

    if (method_exists($wp_object_cache, 'report')) {
        $report = $wp_object_cache->report();
        // Some backends return report; WP core expects echo from some implementations.
        if ($report !== null) {
            wpopt_dump($report);
        }
        return;
    }

    error_log('wp_cache_report(): backend does not implement report()');
}

function wp_cache_dump($group = '', $memcache = false, $echo = false)
{
    wpopt_ensure_cache_object();
    global $wp_object_cache;

    if (!method_exists($wp_object_cache, 'dump')) {
        $dump = [];
    }
    else {
        $dump = $wp_object_cache->dump($group, $memcache);
    }

    if ($echo) {
        wpopt_dump($dump);
    }

    return $dump;
}

function wp_cache_close()
{
    wpopt_ensure_cache_object();
    global $wp_object_cache;

    return method_exists($wp_object_cache, 'close') ? $wp_object_cache->close() : true;
}

function wp_cache_flush()
{
    wpopt_ensure_cache_object();
    global $wp_object_cache;

    return method_exists($wp_object_cache, 'flush') ? $wp_object_cache->flush() : true;
}

function wp_cache_flush_group($group)
{
    wpopt_ensure_cache_object();
    global $wp_object_cache;

    return method_exists($wp_object_cache, 'flush_group') ? $wp_object_cache->flush_group($group) : true;
}

function wp_cache_flush_runtime()
{
    wpopt_ensure_cache_object();
    global $wp_object_cache;

    return method_exists($wp_object_cache, 'flush_volatile') ? $wp_object_cache->flush_volatile() : true;
}

function wp_cache_add($key, $data, $group = '', $expire = 0)
{
    wpopt_ensure_cache_object();
    global $wp_object_cache;

    // WP semantics: add fails if key exists
    return $wp_object_cache->add($key, $data, $group, $expire);
}

function wp_cache_set($key, $data, $group = '', $expire = 0)
{
    wpopt_ensure_cache_object();
    global $wp_object_cache;

    return $wp_object_cache->set($key, $data, $group, $expire);
}

function wp_cache_get($key, $group = '', $force = false, &$found = null)
{
    wpopt_ensure_cache_object();
    global $wp_object_cache;

    /**
     * WP semantics:
     * - $found must be boolean, by-ref
     * - if value stored is false, $found must still be true
     */
    if (method_exists($wp_object_cache, 'get')) {
        return $wp_object_cache->get($key, $group, $force, $found);
    }

    // Very defensive fallback
    $found = false;
    return false;
}

function wp_cache_delete($key, $group = '')
{
    wpopt_ensure_cache_object();
    global $wp_object_cache;

    return $wp_object_cache->delete($key, $group);
}

function wp_cache_replace($key, $data, $group = '', $expire = 0)
{
    wpopt_ensure_cache_object();
    global $wp_object_cache;

    return $wp_object_cache->replace($key, $data, $group, $expire);
}

function wp_cache_add_multiple(array $data, $group = '', $expire = 0)
{
    wpopt_ensure_cache_object();
    global $wp_object_cache;

    if (method_exists($wp_object_cache, 'add_multiple')) {
        return $wp_object_cache->add_multiple($data, $group, $expire);
    }

    $values = [];
    foreach ($data as $key => $value) {
        $values[$key] = $wp_object_cache->add($key, $value, $group, $expire);
    }
    return $values;
}

function wp_cache_set_multiple(array $data, $group = '', $expire = 0)
{
    wpopt_ensure_cache_object();
    global $wp_object_cache;

    if (method_exists($wp_object_cache, 'set_multiple')) {
        return $wp_object_cache->set_multiple($data, $group, $expire);
    }

    $values = [];
    foreach ($data as $key => $value) {
        $values[$key] = $wp_object_cache->set($key, $value, $group, $expire);
    }
    return $values;
}

function wp_cache_get_multiple(array $keys, $group = '')
{
    wpopt_ensure_cache_object();
    global $wp_object_cache;

    if (method_exists($wp_object_cache, 'get_multiple')) {
        return $wp_object_cache->get_multiple($keys, $group);
    }

    $values = [];
    foreach ($keys as $key) {
        $found = null;
        $values[$key] = wp_cache_get($key, $group, false, $found);
    }
    return $values;
}

function wp_cache_delete_multiple(array $keys, $group = '')
{
    wpopt_ensure_cache_object();
    global $wp_object_cache;

    if (method_exists($wp_object_cache, 'delete_multiple')) {
        return $wp_object_cache->delete_multiple($keys, $group);
    }

    foreach ($keys as $key) {
        $wp_object_cache->delete($key, $group);
    }
    return true;
}

function wp_cache_switch_to_blog($blog_id)
{
    wpopt_ensure_cache_object();
    global $wp_object_cache;

    return $wp_object_cache->switch_to_blog($blog_id);
}

function wp_cache_add_global_groups($groups)
{
    wpopt_ensure_cache_object();
    global $wp_object_cache;

    if (method_exists($wp_object_cache, 'add_global_groups')) {
        $wp_object_cache->add_global_groups($groups);
        return;
    }

    // Fallback for older/minimal WP_Object_Cache: store groups in property if present
    if (property_exists($wp_object_cache, 'global_groups')) {
        $wp_object_cache->global_groups = array_unique(array_merge((array)$wp_object_cache->global_groups, (array)$groups));
    }
}


function wp_cache_add_non_persistent_groups($groups)
{
    wpopt_ensure_cache_object();
    global $wp_object_cache;

    if (method_exists($wp_object_cache, 'add_non_persistent_groups')) {
        $wp_object_cache->add_non_persistent_groups($groups);
        return;
    }

    // Fallback: treat them as "non persistent" by storing in a local property
    if (property_exists($wp_object_cache, 'non_persistent_groups')) {
        $wp_object_cache->non_persistent_groups = array_unique(array_merge((array)$wp_object_cache->non_persistent_groups, (array)$groups));
    }
    elseif (property_exists($wp_object_cache, 'non_persistent_groups') === false) {
        // create property dynamically (works unless class uses typed properties)
        $wp_object_cache->non_persistent_groups = (array)$groups;
    }
}

function wp_cache_incr($key, $n = 1, $group = '')
{
    wpopt_ensure_cache_object();
    global $wp_object_cache;

    if (method_exists($wp_object_cache, 'incr')) {
        return $wp_object_cache->incr($key, $n, $group);
    }

    // Fallback: emulate incr via get/set
    $found = null;
    $value = wp_cache_get($key, $group, false, $found);
    if (!$found) {
        $value = 0;
    }
    $value = (int)$value + (int)$n;
    wp_cache_set($key, $value, $group);
    return $value;
}

function wp_cache_decr($key, $n = 1, $group = '')
{
    wpopt_ensure_cache_object();
    global $wp_object_cache;

    if (method_exists($wp_object_cache, 'decr')) {
        return $wp_object_cache->decr($key, $n, $group);
    }

    // Fallback: emulate decr via get/set
    $found = null;
    $value = wp_cache_get($key, $group, false, $found);
    if (!$found) {
        $value = 0;
    }
    $value = (int)$value - (int)$n;
    wp_cache_set($key, $value, $group);
    return $value;
}