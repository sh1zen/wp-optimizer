<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

class Cache
{
    private $driver = null;
    private array $cache = [];
    private int $cached_data = 0;
    private int $hits = 0;
    private int $miss = 0;
    private bool $is_multisite;
    private string $blog_prefix;
    private string $context;
    private array $global_groups = [];
    private array $non_persistent_groups = [];

    public function __construct($context, $use_memcache = false)
    {
        $this->is_multisite = is_multisite();

        $this->switch_to_blog();

        $this->context = $context;

        if ($use_memcache) {

            require_once WPS_DRIVERS_PATH . 'cache/CacheInterface.class.php';

            $this->driver = Drivers\CacheInterface::initialize();
        }
    }

    public function switch_to_blog($blog_id = 0): bool
    {
        if ($blog_id === 0) {
            $blog_id = get_current_blog_id();
        }

        $this->blog_prefix = $this->is_multisite ? "#$blog_id" : '';

        return $this->is_multisite;
    }

    public static function generate_key(...$args): string
    {
        return md5(serialize($args));
    }

    public function add_global_groups($groups)
    {
        $groups = (array)$groups;

        foreach ($groups as $group) {

            // filter group for current context and blog_id
            $group = $this->filter_group($group);

            $this->global_groups[$group] = true;
        }
    }

    private function filter_group($group): string
    {
        if (empty($group)) {
            $group = 'default';
        }

        if ($this->context) {
            $group = "$this->context/$group";
        }

        if ($this->is_multisite and !isset($this->global_groups[$group])) {
            $group = "$this->blog_prefix/$group";
        }

        return $group;
    }

    public function add_non_persistent_groups($groups)
    {
        $groups = (array)$groups;

        foreach ($groups as $group) {

            // filter group for current context and blog_id
            $group = $this->filter_group($group);

            $this->non_persistent_groups[$group] = true;
        }
    }

    public function get($key, $group = 'default', $default = false, $clone_objects = true)
    {
        $group = $this->filter_group($group);

        if ($this->has_volatile($key, $group)) {

            $this->hits++;

            if ($clone_objects and is_object($this->cache[$group][$key])) {
                return clone $this->cache[$group][$key];
            }

            return $this->cache[$group][$key];
        }

        if ($this->is_persistent()) {
            $res = $this->driver->get($key, $group, false);

            if ($res) {
                $this->set_volatile($key, $res, $group);
                return $res;
            }
        }

        $this->miss++;

        return $default;
    }

    private function has_volatile($key, $group): bool
    {
        return isset($this->cache[$group]) and isset($this->cache[$group][$key]);
    }

    public function is_persistent(): bool
    {
        return (bool)$this->driver;
    }

    private function set_volatile($key, $data, $group, $force = false): bool
    {
        if (!$force and $this->has_volatile($key, $group)) {
            return false;
        }

        if (is_object($data)) {
            $data = clone $data;
        }

        $this->cached_data++;

        if (!isset($this->cache[$group])) {
            $this->cache[$group] = [];
        }

        $this->cache[$group][$key] = $data;

        return true;
    }

    public function replace($key, $value, $group = 'default', $expire = false): bool
    {
        $group = $this->filter_group($group);

        if (!$this->is_persistent() or isset($this->non_persistent_groups[$group])) {
            $expire = false;
        }

        if ($expire !== false and !($value instanceof \Closure)) {
            return $this->driver->replace($key, $value, $group, $expire);
        }

        return $this->set_volatile($key, $value, $group, true);
    }

    /**
     * set new data in cache if expire = false use only volatile memory otherwise use persistent one if available
     */
    public function set($key, $value, $group = 'default', $force = false, $expire = false): bool
    {
        $group = $this->filter_group($group);

        if (!$this->is_persistent() or isset($this->non_persistent_groups[$group])) {
            $expire = false;
        }

        if ($expire !== false and !($value instanceof \Closure)) {
            return $this->driver->set($key, $value, $group, $force, $expire);
        }

        return $this->set_volatile($key, $value, $group, $force);
    }

    public function has($key, $group = 'default'): bool
    {
        $group = $this->filter_group($group);

        if ($this->is_persistent()) {
            return $this->driver->has($key, $group);
        }

        return $this->has_volatile($key, $group);
    }

    public function report(): array
    {
        return [
            'engine'       => $this->is_persistent() ? get_class($this->driver) : 'volatile',
            'local_stats'  => $this->stats(),
            'engine_stats' => $this->stats(true),
        ];
    }

    public function stats($memcache = false): array
    {
        if ($memcache) {
            return $this->is_persistent() ? $this->driver->stats() : ['hits' => 0, 'miss' => 0, 'total' => 0];
        }

        return ['hits' => $this->hits, 'miss' => $this->miss, 'total' => $this->cached_data];
    }

    public function dump($group = 'default', $memcache = false)
    {
        if ($memcache) {
            return $this->is_persistent() ? $this->driver->dump($group) : [];
        }

        if (empty($group)) {
            return $this->cache;
        }

        return $this->cache[$group];
    }

    public function delete($key, $group = 'default'): bool
    {
        $group = $this->filter_group($group);

        $res = false;

        if ($this->is_persistent()) {
            $res = $this->driver->delete($key, $group);
        }

        if (!$this->has_volatile($key, $group)) {
            return $res;
        }

        unset($this->cache[$group][$key]);

        $this->cached_data--;

        return $this->is_persistent() ? $res : true;
    }

    public function flush_group($group = 'default'): bool
    {
        $group = $this->filter_group($group);

        $res = false;

        if ($this->is_persistent()) {
            $res = $this->driver->flush_group($group);
        }

        if (!isset($this->cache[$group])) {
            return $res;
        }

        $this->cached_data -= count($this->cache[$group]);

        unset($this->cache[$group]);

        return $this->is_persistent() ? $res : true;
    }

    public function flush(): bool
    {
        $res = true;

        if ($this->is_persistent()) {
            $res = $this->driver->flush();
        }

        $this->cache = [];

        $this->cached_data = 0;
        $this->hits = 0;
        $this->miss = 0;

        return $res;
    }

    public function flush_volatile(): void
    {
        $this->cache = [];
    }

    public function close(): bool
    {
        if ($this->driver) {
            return $this->driver->close();
        }

        return true;
    }

    public function iterate(callable $callback): void
    {
        foreach ($this->cache as $group => $item) {

            if ($this->context) {
                $group = str_replace("$this->context/", '', $group);
            }

            foreach ($item as $key => $data) {
                call_user_func($callback, $data, $key, $group);
            }
        }
    }
}