<?php

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
     * @var int|null Found posts
     */
    protected $total_found;

    /**
     * @var array|null Query result
     */
    protected $query_result;

    /**
     * current instance options
     */
    protected $args = array();

    protected function __construct($args)
    {
        $this->args = array_merge(array(
            'lifespan' => MINUTE_IN_SECONDS * 15,
        ), $args);

        $this->check_cached_hooks();
        $this->reset();
    }

    /**
     * Hook into filters
     */
    protected function check_cached_hooks()
    {
    }

    /**
     * Reset query info
     */
    protected function reset()
    {
        $this->cache_key = null;
        $this->total_found = 0;
        $this->query_result = array();
        $this->cache_active = false;
    }

    public static function check_status()
    {
    }

    protected function generate_key($query, $context = '')
    {
        if (!$this->cache_key) {
            $this->cache_key = WOStorage::generate_key($query, $context);
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
        $group = self::get_cache_group();

        if (function_exists('pods_cache_get')) {
            $value = pods_cache_get($key, $group);
        }
        else {
            $value = WOStorage::getInstance()->load($key, $group);
        }

        return $value;
    }

    protected static function get_cache_group()
    {
        return "cache/" . get_called_class();
    }

    /**
     * Set cache value
     *
     * @param string $key Cache key
     * @param mixed $value Cache value
     * @param int|null $expires Cache expiration
     */
    protected function cache_set($key, $value, $expires)
    {
        $group = self::get_cache_group();

        if (function_exists('pods_cache_set')) {
            pods_cache_set($key, $value, $group, $expires);
        }
        else {
            // WOStorage::getInstance()->set($value, $group, $key, $expires, true);

            WOStorage::getInstance()->save(array(
                'expire'    => $expires,
                'file_name' => $key,
                'context'   => $group,
                'force'     => true,
                'data'      => $value
            ));
        }
    }

    /**
     * Clear cache
     *
     * @param string $key Cache key
     */
    protected function flush($key = '')
    {
        if (function_exists('pods_cache_clear')) {
            pods_cache_clear($key, self::get_cache_group());
        }
        else {
            WOStorage::getInstance()->delete(self::get_cache_group(), $key);
        }
    }

}
