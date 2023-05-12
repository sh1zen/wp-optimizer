<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

// shzn-framework commons
if (!defined('SHZN_FRAMEWORK')) {

    if (file_exists(dirname(__FILE__, 4) . '/vendors/shzn-framework/loader.php')) {
        require_once dirname(__FILE__, 4) . '/vendors/shzn-framework/loader.php';
    }
    else {
        require_once dirname(__FILE__, 5) . '/flexy-seo/vendors/shzn-framework/loader.php';
    }
}

function wp_cache_report()
{
    global $wp_object_cache;
    shzn_var_dump($wp_object_cache->report());
}

function wp_cache_dump($group = '', $memcache = false, $echo = false)
{
    global $wp_object_cache;

    $dump = $wp_object_cache->dump($group, $memcache);

    if ($echo) {
        shzn_var_dump($dump);
    }

    return $dump;
}

function wp_cache_init()
{
    global $wp_object_cache;

    $wp_object_cache = new \SHZN\core\Cache(true);
}

function wp_cache_flush()
{
    global $wp_object_cache;

    return $wp_object_cache->flush();
}

function wp_cache_flush_group($group)
{
    global $wp_object_cache;

    return $wp_object_cache->flush_group($group);
}

function wp_cache_flush_runtime()
{
    global $wp_object_cache;

    return $wp_object_cache->flush_volatile();
}

function wp_cache_close()
{
    global $wp_object_cache;

    return $wp_object_cache->close();
}

function wp_cache_add($key, $data, $group = '', $expire = 0)
{
    global $wp_object_cache;

    return $wp_object_cache->set($key, $data, $group, false, $expire);
}

function wp_cache_set($key, $data, $group = '', $expire = 0)
{
    global $wp_object_cache;

    return $wp_object_cache->set($key, $data, $group, true, $expire);
}

function wp_cache_get($key, $group = '', $force = false, &$found = null)
{
    global $wp_object_cache;

    $res = $wp_object_cache->get($key, $group, false);

    if ($found) {
        $found = $res;
    }

    return $res;
}

function wp_cache_delete($key, $group = '')
{
    global $wp_object_cache;

    return $wp_object_cache->delete($key, $group);
}

function wp_cache_add_multiple(array $data, $group = '', $expire = 0)
{
    global $wp_object_cache;

    $values = array();

    foreach ($data as $key => $value) {
        $values[$key] = $wp_object_cache->set($key, $value, $group, false, $expire);
    }

    return $values;
}

function wp_cache_set_multiple(array $data, $group = '', $expire = 0)
{
    global $wp_object_cache;

    $values = array();

    foreach ($data as $key => $value) {
        $values[$key] = $wp_object_cache->set($key, $value, $group, true, $expire);
    }

    return $values;
}

function wp_cache_get_multiple(array $keys, $group = '')
{
    global $wp_object_cache;

    $values = array();

    foreach ($keys as $key) {
        $values[$key] = $wp_object_cache->get($key, $group, false);
    }

    return $values;
}

function wp_cache_delete_multiple(array $keys, $group = '')
{
    global $wp_object_cache;

    foreach ($keys as $key) {
        $wp_object_cache->delete($key, $group);
    }

    return true;
}

function wp_cache_replace($key, $data, $group = '', $expire = 0)
{
    global $wp_object_cache;

    return $wp_object_cache->replace($key, $data, $group, $expire);
}

function wp_cache_switch_to_blog($blog_id)
{
    global $wp_object_cache;

    return $wp_object_cache->switch_to_blog($blog_id);
}

function wp_cache_add_global_groups($groups)
{
    global $wp_object_cache;

    $wp_object_cache->add_global_groups($groups);
}

function wp_cache_add_non_persistent_groups($groups)
{
    global $wp_object_cache;

    $wp_object_cache->add_non_persistent_groups($groups);
}

function wp_cache_incr($key, $n = 1, $group = '')
{
    global $wp_object_cache;

    return $wp_object_cache->replace($key, ((int)$wp_object_cache->get($key, $group, 0)) + $n, $group, true);
}

function wp_cache_decr($key, $n = 1, $group = '')
{
    global $wp_object_cache;

    return $wp_object_cache->replace($key, ((int)$wp_object_cache->get($key, $group, 0)) - $n, $group, true);
}
