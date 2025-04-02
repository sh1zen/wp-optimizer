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

    public function __construct()
    {
    }

    public static function initialize()
    {
        if (class_exists('Redis')) {
            require_once __DIR__ . '/redis.class.php';
            return new Redis();
        }
        elseif (class_exists('MemcacheD')) {
            require_once __DIR__ . '/memcached.class.php';
            return new MemcacheD();
        }

        return false;
    }

    public function get($key, $group, $default = false)
    {
        return $default;
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