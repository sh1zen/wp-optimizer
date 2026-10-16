<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

class Cache
{
    public int $hits = 0;
    public int $miss = 0;

    private $driver = null;
    private $storage = null;
    private array $cache = [];
    private array $expirations = [];
    private int $cached_data = 0;
    private bool $is_multisite;
    private string $blog_prefix = '';
    private string $context;
    private bool $use_storage_fallback;
    private bool $persist_zero_expiry;
    private array $global_groups = [];
    private array $non_persistent_groups = [];

    public function __construct(
        $context,
        $use_persistent_cache = false,
        $use_multisite_prefix = true,
        $use_storage_fallback = true,
        $persist_zero_expiry = false
    )
    {
        $this->is_multisite = $use_multisite_prefix && is_multisite();
        $this->use_storage_fallback = (bool)$use_storage_fallback;
        $this->persist_zero_expiry = (bool)$persist_zero_expiry;

        if ($this->is_multisite) {
            $this->switch_to_blog();
        }

        $this->context = $context;

        if ($use_persistent_cache) {

            require_once WPS_DRIVERS_PATH . 'cache/CacheInterface.class.php';

            $this->driver = Drivers\CacheInterface::initialize($this->persist_zero_expiry);
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
            $this->global_groups[$this->normalize_group($group)] = true;
        }
    }

    private function filter_group($group): string
    {
        $group = $this->normalize_group($group);

        if ($this->is_multisite and !isset($this->global_groups[$group])) {
            $group = "$this->blog_prefix/$group";
        }

        return $group;
    }

    private function normalize_group($group): string
    {
        if (empty($group)) {
            $group = 'default';
        }

        if ($this->context) {
            $group = "$this->context/$group";
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

    /** Return the value and its hit status from a single lookup through the cache layers. */
    public function get($key, $group = 'default', $default = false, $clone_objects = true, &$found = null)
    {
        $found = false;
        $group = $this->filter_group($group);

        if ($this->has_volatile($key, $group)) {

            $this->hits++;
            $found = true;

            if ($clone_objects and is_object($this->cache[$group][$key])) {
                return clone $this->cache[$group][$key];
            }

            return $this->cache[$group][$key];
        }

        $not_found = new \stdClass();
        $res = $not_found;
        if ($this->is_persistent() && !isset($this->non_persistent_groups[$group])) {
            $res = $this->driver->get($key, $group, $not_found);
        }

        return $this->resolve_loaded_value($key, $group, $res, $not_found, $default, $clone_objects, $found);
    }

    /** Read memory first, then fetch all remaining remote keys in one backend call. */
    public function get_multiple(array $keys, $group = 'default', $default = false, $clone_objects = true): array
    {
        $group = $this->filter_group($group);
        $pending = array();
        $local = array();
        foreach ($keys as $key) {
            if ($this->has_volatile($key, $group)) { $local[$key] = $this->cache[$group][$key]; }
            else { $pending[$key] = $key; }
        }
        $not_found = new \stdClass();
        $remote = array();
        if ($pending && $this->is_persistent() && !isset($this->non_persistent_groups[$group])) {
            $remote = $this->driver->get_multiple(array_values($pending), $group, $not_found);
        }
        $values = array();
        foreach ($keys as $key) {
            if (array_key_exists($key, $local)) {
                $this->hits++;
                $value = $local[$key];
                $values[$key] = $clone_objects && is_object($value) ? clone $value : $value;
            } else {
                $value = array_key_exists($key, $remote) ? $remote[$key] : $not_found;
                $values[$key] = $this->resolve_loaded_value($key, $group, $value, $not_found, $default, $clone_objects);
            }
        }
        return $values;
    }

    /** Resolve remote misses through Storage and warm memory with identical read semantics. */
    private function resolve_loaded_value($key, string $group, $res, $not_found, $default, bool $clone_objects, &$found = null)
    {
        $found = false;
        $remaining_lifetime = 0;
        $storage_hit = false;
        if ($res === $not_found) {
            $res = $this->get_from_storage($key, $group, $storage_hit, $remaining_lifetime);
            if (!$storage_hit) {
                $this->miss++;
                return $default;
            }
        }
        $this->hits++;
        $found = true;
        $this->set_volatile($key, $res, $group, true, $remaining_lifetime);
        if ($storage_hit && $this->is_persistent() && !isset($this->non_persistent_groups[$group])) {
            if ($this->driver->set($key, $res, $group, true, $remaining_lifetime)) {
                $this->delete_storage($key, $group);
            }
        }
        return $clone_objects && is_object($this->cache[$group][$key])
            ? clone $this->cache[$group][$key] : $this->cache[$group][$key];
    }

    private function has_volatile($key, $group): bool
    {
        if (!isset($this->cache[$group]) || !array_key_exists($key, $this->cache[$group])) {
            return false;
        }

        if (!empty($this->expirations[$group][$key]) && $this->expirations[$group][$key] <= time()) {
            unset($this->cache[$group][$key], $this->expirations[$group][$key]);
            $this->cached_data--;
            return false;
        }

        return true;
    }

    public function is_persistent(): bool
    {
        return (bool)$this->driver;
    }

    private function set_volatile($key, $data, $group, $force = false, int $expire = 0): bool
    {
        if (!$force and $this->has_volatile($key, $group)) {
            return false;
        }

        if (is_object($data)) {
            $data = clone $data;
        }

        if (!$this->has_volatile($key, $group)) {
            $this->cached_data++;
        }

        if (!isset($this->cache[$group])) {
            $this->cache[$group] = [];
        }

        $this->cache[$group][$key] = $data;

        if ($expire > 0) {
            $this->expirations[$group][$key] = time() + $expire;
        }
        else {
            unset($this->expirations[$group][$key]);
        }

        return true;
    }

    public function replace($key, $value, $group = 'default', $expire = false): bool
    {
        $group = $this->filter_group($group);
        $expire = max(0, (int)$expire);
        $persist = $expire > 0 || $this->persist_zero_expiry;
        $use_persistent = $persist && $this->is_persistent() && !isset($this->non_persistent_groups[$group]);
        $volatile_exists = $this->has_volatile($key, $group);
        $persistent_exists = $use_persistent && $this->driver->has($key, $group);
        $storage_exists = false;

        if ($persist && !$volatile_exists && !$persistent_exists) {
            $remaining_lifetime = 0;
            $this->get_from_storage($key, $group, $storage_exists, $remaining_lifetime);
        }

        if (!$volatile_exists && !$persistent_exists && !$storage_exists) {
            return false;
        }

        $stored = $this->set_volatile($key, $value, $group, true, $expire);

        $this->persist_value($key, $value, $group, $expire, $persistent_exists);

        return $stored;
    }

    public function add($key, $value, $group = 'default', $expire = false): bool
    {
        return $this->set($key, $value, $group, false, $expire);
    }

    /**
     * Serve add checks from memory first; persist through the remote driver or disk fallback.
     */
    public function set($key, $value, $group = 'default', $force = false, $expire = false): bool
    {
        $group = $this->filter_group($group);
        $expire = max(0, (int)$expire);
        $persist = $expire > 0 || $this->persist_zero_expiry;
        $use_persistent = $persist && $this->is_persistent() && !isset($this->non_persistent_groups[$group]);
        $storage_exists = false;

        if (!$force && ($this->has_volatile($key, $group)
                || ($use_persistent && $this->driver->has($key, $group))
            )) {
            return false;
        }

        if (!$force && $persist) {
            $remaining_lifetime = 0;
            $this->get_from_storage($key, $group, $storage_exists, $remaining_lifetime);
            if ($storage_exists) {
                return false;
            }
        }

        $stored = $this->set_volatile($key, $value, $group, true, $expire);
        $this->persist_value($key, $value, $group, $expire);

        return $stored;
    }

    /** Prefer remote persistence; retire old fallback entries after successful writes. */
    private function persist_value($key, $value, $group, int $expire, bool $replace = false): void
    {
        if (($expire === 0 && !$this->persist_zero_expiry)
            || isset($this->non_persistent_groups[$group]) || $value instanceof \Closure) {
            return;
        }

        $remote_stored = false;
        if ($this->is_persistent()) {
            $remote_stored = $replace
                ? $this->driver->replace($key, $value, $group, $expire)
                : $this->driver->set($key, $value, $group, true, $expire);
        }

        if ($remote_stored) {
            $this->delete_storage($key, $group);
        }
        else {
            // Runtime caching remains usable if both persistent backends fail.
            $this->set_storage($key, $value, $group, $expire);
        }
    }

    public function has($key, $group = 'default'): bool
    {
        $group = $this->filter_group($group);

        if ($this->has_volatile($key, $group)) {
            return true;
        }

        if ($this->is_persistent() && !isset($this->non_persistent_groups[$group])) {
            if ($this->driver->has($key, $group)) {
                return true;
            }
        }

        $storage_hit = false;
        $remaining_lifetime = 0;
        $this->get_from_storage($key, $group, $storage_hit, $remaining_lifetime);

        return $storage_hit;
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

        return $this->cache[$group] ?? [];
    }

    public function delete($key, $group = 'default'): bool
    {
        $group = $this->filter_group($group);

        $persistent_deleted = false;

        if ($this->is_persistent() && !isset($this->non_persistent_groups[$group])) {
            $persistent_deleted = $this->driver->delete($key, $group);
        }

        $storage_deleted = $this->delete_storage($key, $group);

        if (!$this->has_volatile($key, $group)) {
            return $persistent_deleted || $storage_deleted;
        }

        unset($this->cache[$group][$key]);
        unset($this->expirations[$group][$key]);

        $this->cached_data--;

        return true;
    }

    public function flush_group($group = 'default'): bool
    {
        $group = $this->filter_group($group);

        $persistent_flushed = false;

        if ($this->is_persistent() && !isset($this->non_persistent_groups[$group])) {
            $persistent_flushed = $this->driver->flush_group($group);
        }

        $storage_flushed = $this->flush_storage_group($group);

        if (!isset($this->cache[$group])) {
            return $persistent_flushed || $storage_flushed;
        }

        $this->cached_data -= count($this->cache[$group]);

        unset($this->cache[$group]);
        unset($this->expirations[$group]);

        return true;
    }

    public function flush(): bool
    {
        $res = true;

        if ($this->is_persistent()) {
            $res = $this->driver->flush();
        }

        $this->flush_storage_context();

        $this->cache = [];
        $this->expirations = [];

        $this->cached_data = 0;
        $this->hits = 0;
        $this->miss = 0;

        return $res;
    }

    public function flush_volatile(): void
    {
        $this->cache = [];
        $this->expirations = [];
        $this->cached_data = 0;
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

    private function storage()
    {
        if (!$this->use_storage_fallback || $this->storage === false) {
            return false;
        }

        if ($this->storage === null) {
            if (!class_exists(Storage::class)) {
                $this->storage = false;
                return false;
            }

            // Cache owns namespacing; this Storage instance is a disk-only backend.
            $this->storage = new Storage('cache', true, false, false, $this->persist_zero_expiry);
        }

        return $this->storage;
    }

    private function set_storage($key, $value, $group, int $retention): bool
    {
        if (isset($this->non_persistent_groups[$group])) {
            return false;
        }

        $storage = $this->storage();

        if (!$storage) {
            return false;
        }

        return $storage->set($value, $this->storage_key($key), $group, $retention, true);
    }

    private function get_from_storage($key, $group, bool &$found, int &$remaining_lifetime)
    {
        $found = false;
        $remaining_lifetime = 0;

        if (isset($this->non_persistent_groups[$group])) {
            return false;
        }

        $storage = $this->storage();

        if (!$storage) {
            return false;
        }

        $expires_at = null;
        $value = $storage->get($this->storage_key($key), $group, 0, $expires_at);

        if ($expires_at === null) {
            return false;
        }

        if ($expires_at === 0 && $this->persist_zero_expiry) {
            $found = true;
            return $value;
        }

        $remaining_lifetime = (int)$expires_at - time();

        if ($remaining_lifetime <= 0) {
            // Storage owns expiry cleanup; this read may already have been replaced.
            return false;
        }

        $found = true;

        return $value;
    }

    private function delete_storage($key, $group): bool
    {
        if (isset($this->non_persistent_groups[$group])) {
            return false;
        }

        $storage = $this->storage();

        if (!$storage) {
            return false;
        }

        return (bool)$storage->delete($group, $this->storage_key($key));
    }

    private function flush_storage_group($group): bool
    {
        if (isset($this->non_persistent_groups[$group])) {
            return false;
        }

        $storage = $this->storage();

        if (!$storage) {
            return false;
        }

        return (bool)$storage->delete($group);
    }

    private function flush_storage_context(): void
    {
        $storage = $this->storage();

        if (!$storage || empty($this->context)) {
            return;
        }

        $storage->delete($this->context);

        if ($this->is_multisite) {
            $storage->delete("$this->blog_prefix/$this->context");
        }
    }

    private function storage_key($key): string
    {
        return self::generate_key($key);
    }
}
