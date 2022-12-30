<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C)  2022
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core;

class Cache
{
    private $use_wp_cache;

    private array $cache = array();

    private int $cached_data = 0;

    public function __construct($use_wp_cache = false)
    {
        $this->use_wp_cache = $use_wp_cache;
    }

    public function get($key, $group = 'core', $default = false)
    {
        if ($this->use_wp_cache) {
            return wp_cache_get($key, $group);
        }

        if ($this->has($key, $group)) {
            if (is_object($this->cache[$group][$key])) {
                return clone $this->cache[$group][$key];
            }
            else {
                return $this->cache[$group][$key];
            }
        }

        return $default;
    }

    public function set($key, $data, $group = 'core', $force = false)
    {
        if ($this->use_wp_cache)
            return wp_cache_add($key, $data, $group);

        if (!$force and $this->has($key, $group))
            return false;

        return $this->force_set($key, $data, $group);
    }

    public function has($key, $group)
    {
        return isset($this->cache[$group]) and (array_key_exists($key, $this->cache[$group]));
    }

    public static function generate_key(...$args)
    {
        return md5(serialize($args));
    }

    public function force_set($key, $data, $group)
    {
        if ($this->use_wp_cache) {
            return wp_cache_set($key, $data, $group);
        }

        if (is_object($data)) {
            $data = clone $data;
        }

        $this->cached_data++;

        $this->cache[$group][$key] = $data;

        return true;
    }

    public function dump($group = 'core')
    {
        if ($this->use_wp_cache) {
            return null;
        }

        if (empty($group)) {
            return $this->cache;
        }

        if (is_object($this->cache[$group])) {
            return clone $this->cache[$group];
        }
        else {
            return $this->cache[$group];
        }
    }

    public function delete($key, $group = false)
    {
        if ($this->use_wp_cache) {
            wp_cache_delete($key, $group);
        }

        if ($group === false) {
            $this->cached_data -= count($this->cache[$key]);
            unset($this->cache[$key]);
            return true;
        }

        if (!$this->has($key, $group)) {
            return false;
        }

        unset($this->cache[$group][$key]);

        $this->cached_data--;

        return true;
    }
}