<?php
/**
 * Object Cache API: WPOPT_Object_Cache class
 */

use WPOptimizer\core\Storage;


/**
 * Adds data to the cache, if the cache key doesn't already exist.
 *
 * @param int|string $key The cache key to use for retrieval later.
 * @param mixed $data The data to add to the cache.
 * @param string $group Optional. The group to add the cache to. Enables the same key
 *                           to be used across groups. Default empty.
 * @param int $expire Optional. When the cache data should expire, in seconds.
 *                           Default 0 (no expiration).
 * @return bool True on success, false if cache key and group already exist.
 * @global WPOPT_Object_Cache $WPOPT_Object_Cache Object cache global instance.
 *
 * @since 2.0.0
 *
 * @see WPOPT_Object_Cache::add()
 */
function wp_cache_add($key, $data, $group = '', $expire = 0)
{
    global $wpopt_object_cache;

    return $wpopt_object_cache->add($key, $data, $group, (int)$expire);
}

/**
 * Closes the cache.
 *
 * This function has ceased to do anything since WordPress 2.5. The
 * functionality was removed along with the rest of the persistent cache.
 *
 * This does not mean that plugins can't implement this function when they need
 * to make sure that the cache is cleaned up after WordPress no longer needs it.
 *
 * @return true Always returns true.
 * @since 2.0.0
 *
 */
function wp_cache_close()
{
    return true;
}

/**
 * Decrements numeric cache item's value.
 *
 * @param int|string $key The cache key to decrement.
 * @param int $offset Optional. The amount by which to decrement the item's value. Default 1.
 * @param string $group Optional. The group the key is in. Default empty.
 * @return int|false The item's new value on success, false on failure.
 * @see WPOPT_Object_Cache::decr()
 * @global WPOPT_Object_Cache $WPOPT_Object_Cache Object cache global instance.
 *
 * @since 3.3.0
 *
 */
function wp_cache_decr($key, $offset = 1, $group = '')
{
    global $wpopt_object_cache;

    return $wpopt_object_cache->decr($key, $offset, $group);
}

/**
 * Removes the cache contents matching key and group.
 *
 * @param int|string $key What the contents in the cache are called.
 * @param string $group Optional. Where the cache contents are grouped. Default empty.
 * @return bool True on successful removal, false on failure.
 * @since 2.0.0
 *
 * @see WPOPT_Object_Cache::delete()
 * @global WPOPT_Object_Cache $WPOPT_Object_Cache Object cache global instance.
 *
 */
function wp_cache_delete($key, $group = '')
{
    global $wpopt_object_cache;

    return $wpopt_object_cache->delete($key, $group);
}

/**
 * Removes all cache items.
 *
 * @return bool True on success, false on failure.
 * @see WPOPT_Object_Cache::flush()
 * @global WPOPT_Object_Cache $WPOPT_Object_Cache Object cache global instance.
 *
 * @since 2.0.0
 *
 */
function wp_cache_flush()
{
    global $wpopt_object_cache;

    return $wpopt_object_cache->flush();
}

/**
 * Retrieves the cache contents from the cache by key and group.
 *
 * @param int|string $key The key under which the cache contents are stored.
 * @param string $group Optional. Where the cache contents are grouped. Default empty.
 * @param bool $force Optional. Whether to force an update of the local cache
 *                          from the persistent cache. Default false.
 * @param bool $found Optional. Whether the key was found in the cache (passed by reference).
 *                          Disambiguates a return of false, a storable value. Default null.
 * @return mixed|false The cache contents on success, false on failure to retrieve contents.
 * @global WPOPT_Object_Cache $WPOPT_Object_Cache Object cache global instance.
 *
 * @since 2.0.0
 *
 * @see WPOPT_Object_Cache::get()
 */
function wp_cache_get($key, $group = '', $force = false, &$found = null)
{
    global $wpopt_object_cache;

    return $wpopt_object_cache->get($key, $group, $force, $found);
}

/**
 * Retrieves multiple values from the cache in one call.
 *
 * @param array $keys Array of keys under which the cache contents are stored.
 * @param string $group Optional. Where the cache contents are grouped. Default empty.
 * @param bool $force Optional. Whether to force an update of the local cache
 *                      from the persistent cache. Default false.
 * @return array Array of values organized into groups.
 * @see WPOPT_Object_Cache::get_multiple()
 * @global WPOPT_Object_Cache $WPOPT_Object_Cache Object cache global instance.
 *
 * @since 5.5.0
 *
 */
function wp_cache_get_multiple($keys, $group = '', $force = false)
{
    global $wpopt_object_cache;

    return $wpopt_object_cache->get_multiple($keys, $group, $force);
}

/**
 * Increment numeric cache item's value
 *
 * @param int|string $key The key for the cache contents that should be incremented.
 * @param int $offset Optional. The amount by which to increment the item's value. Default 1.
 * @param string $group Optional. The group the key is in. Default empty.
 * @return int|false The item's new value on success, false on failure.
 * @see WPOPT_Object_Cache::incr()
 * @global WPOPT_Object_Cache $WPOPT_Object_Cache Object cache global instance.
 *
 * @since 3.3.0
 *
 */
function wp_cache_incr($key, $offset = 1, $group = '')
{
    global $wpopt_object_cache;

    return $wpopt_object_cache->incr($key, $offset, $group);
}

/**
 * Sets up Object Cache Global and assigns it.
 *
 * @since 2.0.0
 *
 * @global WPOPT_Object_Cache $WPOPT_Object_Cache
 */
function wp_cache_init()
{
    $GLOBALS['wpopt_object_cache'] = new WPOPT_Object_Cache();
}

/**
 * Replaces the contents of the cache with new data.
 *
 * @param int|string $key The key for the cache data that should be replaced.
 * @param mixed $data The new data to store in the cache.
 * @param string $group Optional. The group for the cache data that should be replaced.
 *                           Default empty.
 * @param int $expire Optional. When to expire the cache contents, in seconds.
 *                           Default 0 (no expiration).
 * @return bool False if original value does not exist, true if contents were replaced
 * @global WPOPT_Object_Cache $WPOPT_Object_Cache Object cache global instance.
 *
 * @since 2.0.0
 *
 * @see WPOPT_Object_Cache::replace()
 */
function wp_cache_replace($key, $data, $group = '', $expire = 0)
{
    global $wpopt_object_cache;

    return $wpopt_object_cache->replace($key, $data, $group, (int)$expire);
}

/**
 * Saves the data to the cache.
 *
 * Differs from wp_cache_add() and wp_cache_replace() in that it will always write data.
 *
 * @param int|string $key The cache key to use for retrieval later.
 * @param mixed $data The contents to store in the cache.
 * @param string $group Optional. Where to group the cache contents. Enables the same key
 *                           to be used across groups. Default empty.
 * @param int $expire Optional. When to expire the cache contents, in seconds.
 *                           Default 0 (no expiration).
 * @return bool True on success, false on failure.
 * @global WPOPT_Object_Cache $WPOPT_Object_Cache Object cache global instance.
 *
 * @since 2.0.0
 *
 * @see WPOPT_Object_Cache::set()
 */
function wp_cache_set($key, $data, $group = '', $expire = 0)
{
    global $wpopt_object_cache;

    return $wpopt_object_cache->set($key, $data, $group, (int)$expire);
}

/**
 * Switches the internal blog ID.
 *
 * This changes the blog id used to create keys in blog specific groups.
 *
 * @param int $blog_id Site ID.
 * @see WPOPT_Object_Cache::switch_to_blog()
 * @global WPOPT_Object_Cache $WPOPT_Object_Cache Object cache global instance.
 *
 * @since 3.5.0
 *
 */
function wp_cache_switch_to_blog($blog_id)
{
    global $wpopt_object_cache;

    $wpopt_object_cache->switch_to_blog($blog_id);
}

/**
 * Adds a group or set of groups to the list of global groups.
 *
 * @param string|array $groups A group or an array of groups to add.
 * @see WPOPT_Object_Cache::add_global_groups()
 * @global WPOPT_Object_Cache $WPOPT_Object_Cache Object cache global instance.
 *
 * @since 2.6.0
 *
 */
function wp_cache_add_global_groups($groups)
{
    global $wpopt_object_cache;

    $wpopt_object_cache->add_global_groups($groups);
}

/**
 * Adds a group or set of groups to the list of non-persistent groups.
 *
 * @param string|array $groups A group or an array of groups to add.
 * @since 2.6.0
 *
 */
function wp_cache_add_non_persistent_groups($groups)
{
    // Default cache doesn't persist so nothing to do here.
}

/**
 * Reset internal cache keys and structures.
 *
 * If the cache back end uses global blog or site IDs as part of its cache keys,
 * this function instructs the back end to reset those keys and perform any cleanup
 * since blog or site IDs have changed since cache init.
 *
 * This function is deprecated. Use wp_cache_switch_to_blog() instead of this
 * function when preparing the cache for a blog switch. For clearing the cache
 * during unit tests, consider using wp_cache_init(). wp_cache_init() is not
 * recommended outside of unit tests as the performance penalty for using it is
 * high.
 *
 * @since 2.6.0
 * @deprecated 3.5.0 WPOPT_Object_Cache::reset()
 * @see WPOPT_Object_Cache::reset()
 *
 * @global WPOPT_Object_Cache $WPOPT_Object_Cache Object cache global instance.
 */
function wp_cache_reset()
{
    global $wpopt_object_cache;

    $wpopt_object_cache->reset();
}


/**
 * Core class that implements an object cache.
 *
 * The WordPress Object Cache is used to save on trips to the database. The
 * Object Cache stores all of the cache data to memory and makes the cache
 * contents available by using a key, which is used to name and later retrieve
 * the cache contents.
 *
 * The Object Cache can be replaced by other caching mechanisms by placing files
 * in the wp-content folder which is looked at in wp-settings. If that file
 * exists, then this file will not be included.
 *
 * @since 2.0.0
 */
class WPOPT_Object_Cache
{
    /**
     * The amount of times the cache data was already stored in the cache.
     *
     * @since 2.5.0
     * @var int
     */
    public $cache_hits = 0;
    /**
     * Amount of times the cache did not have the request in cache.
     *
     * @since 2.0.0
     * @var int
     */
    public $cache_misses = 0;
    /**
     * List of global cache groups.
     *
     * @since 3.0.0
     * @var array
     */
    protected $global_groups = array();
    /**
     * Holds the cached objects.
     *
     * @since 2.0.0
     * @var array
     */
    private $cache = array();
    /**
     * The blog prefix to prepend to keys in non-global groups.
     *
     * @since 3.5.0
     * @var string
     */
    private $blog_prefix;
    /**
     * Holds the value of is_multisite().
     *
     * @since 3.5.0
     * @var bool
     */
    private $multisite;

    /**
     * Sets up object properties; PHP 5 style constructor.
     *
     * @since 2.0.8
     */
    public function __construct()
    {
        $this->multisite = is_multisite();
        $this->blog_prefix = $this->multisite ? get_current_blog_id() . ':' : '';
    }

    private static function get_cache_group()
    {
        return 'cache/object-cache';
    }

    public static function clear_cache()
    {
        Storage::getInstance()->delete(self::get_cache_group());
    }

    /**
     * Makes private properties readable for backward compatibility.
     *
     * @param string $name Property to get.
     * @return mixed Property.
     * @since 4.0.0
     *
     */
    public function __get($name)
    {
        return $this->$name;
    }

    /**
     * Makes private properties settable for backward compatibility.
     *
     * @param string $name Property to set.
     * @param mixed $value Property value.
     * @return mixed Newly-set property.
     * @since 4.0.0
     *
     */
    public function __set($name, $value)
    {
        return $this->$name = $value;
    }

    /**
     * Makes private properties checkable for backward compatibility.
     *
     * @param string $name Property to check if set.
     * @return bool Whether the property is set.
     * @since 4.0.0
     *
     */
    public function __isset($name)
    {
        return isset($this->$name);
    }

    /**
     * Makes private properties un-settable for backward compatibility.
     *
     * @param string $name Property to unset.
     * @since 4.0.0
     *
     */
    public function __unset($name)
    {
        unset($this->$name);
    }

    /**
     * Adds data to the cache if it doesn't already exist.
     *
     * @param int|string $key What to call the contents in the cache.
     * @param mixed $data The contents to store in the cache.
     * @param string $group Optional. Where to group the cache contents. Default 'default'.
     * @param int $expire Optional. When to expire the cache contents. Default 0 (no expiration).
     * @return bool True on success, false if cache key and group already exist.
     * @uses WPOPT_Object_Cache::set()     Sets the data after the checking the cache
     *                                  contents existence.
     *
     * @since 2.0.0
     *
     * @uses WPOPT_Object_Cache::_exists() Checks to see if the cache already has data.
     */
    public function add($key, $data, $group = 'default', $expire = 0)
    {
        if (wp_suspend_cache_addition()) {
            return false;
        }

        if (empty($group)) {
            $group = 'default';
        }

        $id = $key;
        if ($this->multisite and !isset($this->global_groups[$group])) {
            $id = $this->blog_prefix . $key;
        }

        if ($this->_exists($id, $group)) {
            return false;
        }

        return $this->set($key, $data, $group, (int)$expire);
    }

    /**
     * Serves as a utility function to determine whether a key exists in the cache.
     *
     * @param int|string $key Cache key to check for existence.
     * @param string $group Cache group for the key existence check.
     * @return bool Whether the key exists in the cache for the given group.
     * @since 3.4.0
     *
     */
    protected function _exists($key, $group)
    {
        return isset($this->cache[$group]) and (isset($this->cache[$group][$key]) || array_key_exists($key, $this->cache[$group]));
    }

    /**
     * Sets the data contents into the cache.
     *
     * The cache contents are grouped by the $group parameter followed by the
     * $key. This allows for duplicate IDs in unique groups. Therefore, naming of
     * the group should be used with care and should follow normal function
     * naming guidelines outside of core WordPress usage.
     *
     * The $expire parameter is not used, because the cache will automatically
     * expire for each time a page is accessed and PHP finishes. The method is
     * more for cache plugins which use files.
     *
     * @param int|string $key What to call the contents in the cache.
     * @param mixed $data The contents to store in the cache.
     * @param string $group Optional. Where to group the cache contents. Default 'default'.
     * @param int $expire Not Used.
     * @return true Always returns true.
     * @since 2.0.0
     *
     */
    public function set($key, $data, $group = 'default', $expire = 0)
    {
        if (empty($group)) {
            $group = 'default';
        }

        if ($this->multisite and !isset($this->global_groups[$group])) {
            $key = $this->blog_prefix . $key;
        }

        if (is_object($data)) {
            $data = clone $data;
        }

        $this->cache[$group][$key] = $data;
        return true;
    }

    /**
     * Sets the list of global cache groups.
     *
     * @param array $groups List of groups that are global.
     * @since 3.0.0
     *
     */
    public function add_global_groups($groups)
    {
        $groups = (array)$groups;

        $groups = array_fill_keys($groups, true);
        $this->global_groups = array_merge($this->global_groups, $groups);
    }

    /**
     * Decrements numeric cache item's value.
     *
     * @param int|string $key The cache key to decrement.
     * @param int $offset Optional. The amount by which to decrement the item's value. Default 1.
     * @param string $group Optional. The group the key is in. Default 'default'.
     * @return int|false The item's new value on success, false on failure.
     * @since 3.3.0
     *
     */
    public function decr($key, $offset = 1, $group = 'default')
    {
        if (empty($group)) {
            $group = 'default';
        }

        if ($this->multisite and !isset($this->global_groups[$group])) {
            $key = $this->blog_prefix . $key;
        }

        if (!$this->_exists($key, $group)) {
            return false;
        }

        if (!is_numeric($this->cache[$group][$key])) {
            $this->cache[$group][$key] = 0;
        }

        $offset = (int)$offset;

        $this->cache[$group][$key] -= $offset;

        if ($this->cache[$group][$key] < 0) {
            $this->cache[$group][$key] = 0;
        }

        return $this->cache[$group][$key];
    }

    /**
     * Removes the contents of the cache key in the group.
     *
     * If the cache key does not exist in the group, then nothing will happen.
     *
     * @param int|string $key What the contents in the cache are called.
     * @param string $group Optional. Where the cache contents are grouped. Default 'default'.
     * @param bool $deprecated Optional. Unused. Default false.
     * @return bool False if the contents weren't deleted and true on success.
     * @since 2.0.0
     *
     */
    public function delete($key, $group = 'default', $deprecated = false)
    {
        if (empty($group)) {
            $group = 'default';
        }

        if ($this->multisite and !isset($this->global_groups[$group])) {
            $key = $this->blog_prefix . $key;
        }

        if (!$this->_exists($key, $group)) {
            return false;
        }

        unset($this->cache[$group][$key]);
        return true;
    }

    /**
     * Clears the object cache of all data.
     *
     * @return true Always returns true.
     * @since 2.0.0
     *
     */
    public function flush()
    {
        $this->cache = array();

        return true;
    }

    /**
     * Retrieves multiple values from the cache in one call.
     *
     * @param array $keys Array of keys under which the cache contents are stored.
     * @param string $group Optional. Where the cache contents are grouped. Default 'default'.
     * @param bool $force Optional. Whether to force an update of the local cache
     *                      from the persistent cache. Default false.
     * @return array Array of values organized into groups.
     * @since 5.5.0
     *
     */
    public function get_multiple($keys, $group = 'default', $force = false)
    {
        $values = array();

        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $group, $force);
        }

        return $values;
    }

    /**
     * Retrieves the cache contents, if it exists.
     *
     * The contents will be first attempted to be retrieved by searching by the
     * key in the cache group. If the cache is hit (success) then the contents
     * are returned.
     *
     * On failure, the number of cache misses will be incremented.
     *
     * @param int|string $key The key under which the cache contents are stored.
     * @param string $group Optional. Where the cache contents are grouped. Default 'default'.
     * @param bool $force Optional. Unused. Whether to force an update of the local cache
     *                          from the persistent cache. Default false.
     * @param bool $found Optional. Whether the key was found in the cache (passed by reference).
     *                          Disambiguates a return of false, a storable value. Default null.
     * @return mixed|false The cache contents on success, false on failure to retrieve contents.
     * @since 2.0.0
     *
     */
    public function get($key, $group = 'default', $force = false, &$found = null)
    {
        if (empty($group)) {
            $group = 'default';
        }

        if ($this->multisite and !isset($this->global_groups[$group])) {
            $key = $this->blog_prefix . $key;
        }

        if ($this->_exists($key, $group)) {
            $found = true;
            $this->cache_hits += 1;
            if (is_object($this->cache[$group][$key])) {
                return clone $this->cache[$group][$key];
            }
            else {
                return $this->cache[$group][$key];
            }
        }

        $found = false;
        $this->cache_misses += 1;
        return false;
    }

    /**
     * Increments numeric cache item's value.
     *
     * @param int|string $key The cache key to increment
     * @param int $offset Optional. The amount by which to increment the item's value. Default 1.
     * @param string $group Optional. The group the key is in. Default 'default'.
     * @return int|false The item's new value on success, false on failure.
     * @since 3.3.0
     *
     */
    public function incr($key, $offset = 1, $group = 'default')
    {
        if (empty($group)) {
            $group = 'default';
        }

        if ($this->multisite and !isset($this->global_groups[$group])) {
            $key = $this->blog_prefix . $key;
        }

        if (!$this->_exists($key, $group)) {
            return false;
        }

        if (!is_numeric($this->cache[$group][$key])) {
            $this->cache[$group][$key] = 0;
        }

        $offset = (int)$offset;

        $this->cache[$group][$key] += $offset;

        if ($this->cache[$group][$key] < 0) {
            $this->cache[$group][$key] = 0;
        }

        return $this->cache[$group][$key];
    }

    /**
     * Replaces the contents in the cache, if contents already exist.
     *
     * @param int|string $key What to call the contents in the cache.
     * @param mixed $data The contents to store in the cache.
     * @param string $group Optional. Where to group the cache contents. Default 'default'.
     * @param int $expire Optional. When to expire the cache contents. Default 0 (no expiration).
     * @return bool False if not exists, true if contents were replaced.
     * @see WPOPT_Object_Cache::set()
     *
     * @since 2.0.0
     *
     */
    public function replace($key, $data, $group = 'default', $expire = 0)
    {
        if (empty($group)) {
            $group = 'default';
        }

        $id = $key;
        if ($this->multisite and !isset($this->global_groups[$group])) {
            $id = $this->blog_prefix . $key;
        }

        if (!$this->_exists($id, $group)) {
            return false;
        }

        return $this->set($key, $data, $group, (int)$expire);
    }

    /**
     * Resets cache keys.
     *
     * @since 3.0.0
     *
     * @deprecated 3.5.0 Use switch_to_blog()
     * @see switch_to_blog()
     */
    public function reset()
    {
        _deprecated_function(__FUNCTION__, '3.5.0', 'switch_to_blog()');

        // Clear out non-global caches since the blog ID has changed.
        foreach (array_keys($this->cache) as $group) {
            if (!isset($this->global_groups[$group])) {
                unset($this->cache[$group]);
            }
        }
    }

    /**
     * Echoes the stats of the caching.
     *
     * Gives the cache hits, and cache misses. Also prints every cached group,
     * key and the data.
     *
     * @since 2.0.0
     */
    public function stats()
    {
        echo '<p>';
        echo "<strong>Cache Hits:</strong> {$this->cache_hits}<br />";
        echo "<strong>Cache Misses:</strong> {$this->cache_misses}<br />";
        echo '</p>';
        echo '<ul>';
        foreach ($this->cache as $group => $cache) {
            echo '<li><strong>Group:</strong> ' . esc_html($group) . ' - ( ' . number_format(strlen(serialize($cache)) / KB_IN_BYTES, 2) . 'k )</li>';
        }
        echo '</ul>';
    }

    /**
     * Switches the internal blog ID.
     *
     * This changes the blog ID used to create keys in blog specific groups.
     *
     * @param int $blog_id Blog ID.
     * @since 3.5.0
     *
     */
    public function switch_to_blog($blog_id)
    {
        $blog_id = (int)$blog_id;
        $this->blog_prefix = $this->multisite ? $blog_id . ':' : '';
    }
}
