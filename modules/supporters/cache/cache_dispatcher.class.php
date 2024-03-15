<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

use WPS\core\Cache;

class Cache_Dispatcher
{
    protected static ?Cache_Dispatcher $_Instance;

    protected string $cache_key = '';

    protected int $lifetime = 0;

    protected array $options = array();

    protected bool $is_cacheable;
    protected bool $is_cached_content = false;

    protected function __construct($lifetime, array $options = [])
    {
        $this->options = $options;

        $this->lifetime = self::parse_lifetime($lifetime);

        $this->is_cacheable = !(is_admin() or wp_doing_cron() or (defined('REST_REQUEST') && REST_REQUEST) or wp_doing_ajax());

        $this->reset();

        if ($this->is_cacheable) {
            $this->launcher();
        }
    }

    private static function parse_lifetime($lifetime)
    {
        return is_numeric($lifetime) ? $lifetime : wps_timestr2seconds($lifetime);
    }

    protected function reset(): void
    {
        $this->cache_key = '';
        $this->is_cached_content = false;
    }

    protected function launcher()
    {
    }

    public static function getInstance($lifetime = false, $options = []): Cache_Dispatcher
    {
        if (!isset(static::$_Instance)) {
            static::$_Instance = new static($lifetime, $options);
        }

        return static::$_Instance;
    }

    public static function activate()
    {
    }

    public static function deactivate(): void
    {
        static::flush();
    }

    public static function flush($lifetime = false, $blog_id = 0): void
    {
        if ($lifetime) {
            wps('wpopt')->storage->delete_old(static::get_cache_group(), self::parse_lifetime($lifetime), $blog_id);
        }
        else {
            wps('wpopt')->storage->delete(static::get_cache_group(), '', $blog_id);
        }
    }

    protected static function get_cache_group(): string
    {
        return "cache/" . wps('wpopt')->moduleHandler->module_slug(get_called_class(), true);
    }

    protected function generate_key($query, $context = ''): string
    {
        if (!$this->cache_key) {
            $this->cache_key = Cache::generate_key($query, $context);
        }

        return $this->cache_key;
    }

    /**
     * Get posts from cache
     */
    protected function restore_cached_object($cached_data)
    {
        if (is_array($cached_data) and !empty($cached_data)) {
            $cached_data = json_decode(json_encode($cached_data));
        }
        else {
            $cached_data = null;
        }

        return $cached_data;
    }

    /**
     * Get value from cache
     */
    protected function cache_get(string $key)
    {
        return wps('wpopt')->storage->get($key, static::get_cache_group());
    }

    /**
     * Set cache value
     */
    protected function cache_set(string $key, $value, int $expires = 0)
    {
        if (empty($key) or empty($value)) {
            return;
        }

        $expires = $expires ?: $this->lifetime;

        wps('wpopt')->storage->set($value, $key, static::get_cache_group(), $expires, true);
    }
}
