<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core\Drivers;

class CacheInterface
{
    protected $conn;
    protected bool $reset_expiry = false;

    /** Configure whether zero-expiry overwrites remove a previous backend TTL. */
    public function __construct(bool $reset_expiry = false)
    {
        $this->reset_expiry = $reset_expiry;
    }

    public static function initialize(bool $reset_expiry = false)
    {
        if (class_exists('Redis')) {
            require_once __DIR__ . '/redis.class.php';
            $driver = new Redis($reset_expiry);

            if ($driver->is_available()) {
                return $driver;
            }
        }

        if (class_exists('MemcacheD')) {
            require_once __DIR__ . '/memcached.class.php';
            $driver = new MemcacheD($reset_expiry);

            if ($driver->is_available()) {
                return $driver;
            }
        }

        return false;
    }

    public function is_available(): bool
    {
        return false;
    }

    public function get($key, $group, $default = false)
    {
        return $default;
    }

    /** Preserve single-read semantics for drivers without a native bulk implementation. */
    public function get_multiple(array $keys, $group, $default = false): array
    {
        $values = array();
        foreach ($keys as $key) { $values[$key] = $this->get($key, $group, $default); }
        return $values;
    }

    public function set($key, $value, $group, $force = false, $expire = 0): bool
    {
        return false;
    }

    public function has($key, $group): bool
    {
        return false;
    }

    public function dump($group = ''): array
    {
        return [];
    }

    public function delete($key, $group): bool
    {
        return false;
    }

    public function flush_group($group): bool
    {
        return false;
    }

    public function flush(): bool
    {
        return false;
    }

    public function has_group($group): bool
    {
        return false;
    }

    public function replace($key, $data, $group, $expire = 0): bool
    {
        return $this->set($key, $data, $group, true, $expire);
    }

    public function close(): bool
    {
        return false;
    }

    public function stats(): array
    {
        return ['hits' => 0, 'miss' => 0, 'total' => 0];
    }

    protected function co_group($key, $group): string
    {
        return "$group#$key";
    }
}
