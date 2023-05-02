<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core;

class Cache
{
    private $driver = null;
    private array $cache = array();

    private int $cached_data = 0;
    private int $hits = 0;
    private int $miss = 0;

    private bool $multisite;
    private string $blog_prefix;
    private string $context = '';
    private array $global_groups = [];
    private array $non_persistent_groups = [];

    public function __construct($use_memcache = false)
    {
        $this->multisite = is_multisite();
        $this->blog_prefix = $this->multisite ? get_current_blog_id() . ':' : '';

        if ($use_memcache) {

            require_once SHZN_DRIVERS . 'cache/CacheInterface.class.php';

            $this->driver = Drivers\CacheInterface::initialize();
        }
    }

    public static function generate_key(...$args)
    {
        return md5(serialize($args));
    }

    public function switch_to_blog($blog_id)
    {
        $this->blog_prefix = $this->multisite ? $blog_id . ':' : '';

        return $this->multisite;
    }

    public function set_context($context)
    {
        $this->context = $context;

        return true;
    }

    public function add_global_groups($groups)
    {
        $groups = (array)$groups;

        $groups = array_fill_keys($groups, true);
        $this->global_groups = array_merge($this->global_groups, $groups);
    }

    public function add_non_persistent_groups($groups)
    {
        $groups = (array)$groups;

        $groups = array_fill_keys($groups, true);
        $this->non_persistent_groups = array_merge($this->non_persistent_groups, $groups);
    }

    public function get($key, $group = 'default', $default = false)
    {
        list($key, $group) = $this->filter_key_group($key, $group);

        if ($this->has_volatile($key, $group)) {

            $this->hits++;

            if (is_object($this->cache[$group][$key])) {
                return clone $this->cache[$group][$key];
            }

            return $this->cache[$group][$key];
        }

        if ($this->driver) {
            $res = $this->driver->get($key, $group, false);

            if ($res) {
                $this->set_volatile($key, $res, $group);
                return $res;
            }
        }

        $this->miss++;

        return $default;
    }

    private function filter_key_group($key, $group)
    {
        if (empty($group)) {
            $group = 'default';
        }

        if ($this->context) {
            $group = "{$this->context}:{$group}";
        }

        if ($this->multisite and !isset($this->global_groups[$group])) {
            $key = $this->blog_prefix . $key;
        }

        return [$key, $group];
    }

    private function has_volatile($key, $group)
    {
        return isset($this->cache[$group]) and isset($this->cache[$group][$key]);
    }

    private function set_volatile($key, $data, $group, $force = false)
    {
        if (!$force and $this->has_volatile($key, $group)) {
            return false;
        }

        if (is_object($data)) {
            $data = clone $data;
        }

        $this->cached_data++;

        $this->cache[$group][$key] = $data;

        return true;
    }

    public function replace($key, $data, $group = 'default', $expire = 0)
    {
        list($key, $group) = $this->filter_key_group($key, $group);

        if ($this->driver) {
            return $this->driver->replace($key, $data, $group, $expire);
        }

        return $this->set($key, $data, $group, true, $expire);
    }

    /**
     * set new data in cache if expire = 0 use only volatile memory otherwise if persistent one is available try to use that one
     */
    public function set($key, $value, $group = 'default', $force = false, $expire = false)
    {
        list($key, $group) = $this->filter_key_group($key, $group);

        if (isset($this->non_persistent_groups[$group])) {
            $expire = 0;
        }

        if ($expire !== 0 and $this->driver and !($value instanceof \Closure)) {
            return $this->driver->set($key, $value, $group, $force, $expire);
        }

        return $this->set_volatile($key, $value, $group, $force);
    }

    public function has($key, $group = 'default')
    {
        list($key, $group) = $this->filter_key_group($key, $group);

        if ($this->driver) {
            return $this->driver->has($key, $group);
        }

        return $this->has_volatile($key, $group);
    }

    public function report()
    {
        return [
            'engine'       => $this->driver ? get_class($this->driver) : 'volatile',
            'local_stats'  => $this->stats(),
            'engine_stats' => $this->stats(true),
        ];
    }

    public function stats($memcache = false)
    {
        if ($memcache) {
            return $this->driver ? $this->driver->stats() : ['hits' => 0, 'miss' => 0, 'total' => 0];
        }

        return ['hits' => $this->hits, 'miss' => $this->miss, 'total' => $this->cached_data];
    }

    public function dump($group = 'default', $memcache = false)
    {
        if ($memcache) {
            return $this->driver ? $this->driver->dump($group) : [];
        }

        if (empty($group)) {
            return $this->cache;
        }

        return $this->cache[$group];
    }

    public function delete($key, $group = 'default')
    {
        list($key, $group) = $this->filter_key_group($key, $group);

        $res = true;

        if ($this->driver) {
            $res = $this->driver->delete($key, $group);
        }

        if (!$this->has_volatile($key, $group)) {
            return $res;
        }

        unset($this->cache[$group][$key]);

        $this->cached_data--;

        return $res;
    }

    public function flush_group($group = 'default')
    {
        list($key, $group) = $this->filter_key_group('', $group);

        $res = false;

        if ($this->driver) {
            $res = $this->driver->flush_group($group);
        }

        if (!isset($this->cache[$group])) {
            return $res;
        }

        $this->cached_data -= count($this->cache[$group]);

        unset($this->cache[$group]);

        return $res;
    }

    public function flush()
    {
        $res = true;

        if ($this->driver) {
            $res = $this->driver->flush();
        }

        $this->cache = [];

        $this->cached_data = 0;
        $this->hits = 0;
        $this->miss = 0;

        return $res;
    }

    public function close()
    {
        if ($this->driver) {
            return $this->driver->close();
        }

        return true;
    }
}