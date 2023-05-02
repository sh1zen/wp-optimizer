<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

use SHZN\core\Cache;

class Cache_Dispatcher
{
    /**
     * @var null|string Cache key
     */
    protected $cache_key;

    /**
     * @var bool cache status
     */
    protected $cache_active;

    /**
     * current instance options
     */
    protected $args = array();

    protected function __construct($args)
    {
        $this->args = array_merge(array(
            'lifespan' => HOUR_IN_SECONDS,
        ), $args);

        $this->reset();

        return !is_admin();
    }

    /**
     * Reset query info
     */
    protected function reset()
    {
        $this->cache_key = null;
        $this->cache_active = false;
    }

    public static function clear_cache()
    {
        shzn('wpopt')->storage->delete(self::get_cache_group());
    }

    protected static function get_cache_group()
    {
        return "cache/" . shzn('wpopt')->moduleHandler->module_slug(get_called_class(), true);
    }

    protected function generate_key($query, $context = '')
    {
        if (!$this->cache_key) {
            $this->cache_key = Cache::generate_key($query, $context);
        }

        return $this->cache_key;
    }

    /**
     * Get posts from cache
     *
     * @param $cached_data
     * @return object
     */
    protected function objectify_cached($cached_data)
    {
        if (is_array($cached_data) and !empty($cached_data)) {
            $cached_data = json_decode(json_encode($cached_data)); //(object)$cached_data;
        }
        else {
            $cached_data = null;
        }

        return $cached_data;
    }

    /**
     * Get value from cache
     *
     * @param string $key Cache key
     * @return mixed
     */
    protected function cache_get($key)
    {
        return shzn('wpopt')->storage->load($key, self::get_cache_group());
    }

    public static function activate()
    {
    }

    public static function deactivate()
    {
        self::clear_cache();
    }

    /**
     * Set cache value
     *
     * @param string $key Cache key
     * @param mixed $value Cache value
     * @param int|null $expires Cache expiration
     */
    protected function cache_set($key, $value, $expires = null)
    {
        if (is_null($expires)) {
            $expires = $this->args['lifespan'];
        }

        shzn('wpopt')->storage->set($value, $key, self::get_cache_group(), $expires, true);
    }
}
