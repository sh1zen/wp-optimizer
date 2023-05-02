<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core\Drivers;

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

    public function set($key, $value, $group, $force = false, $expire = false)
    {
        return false;
    }

    public function has($key, $group)
    {
        return false;
    }

    public function dump($group = '')
    {
        return [];
    }

    public function delete($key, $group)
    {
        return false;
    }

    public function flush_group($group)
    {
        return false;
    }

    public function flush()
    {
        return false;
    }

    public function has_group($group)
    {
        return false;
    }

    public function replace($key, $data, $group, $expire = false)
    {
        return $this->set($key, $data, $group, true, $expire);
    }

    public function close()
    {
        return false;
    }

    public function stats()
    {
        return ['hits' => 0, 'miss' => 0, 'total' => 0];
    }

    protected function co_group($key, $group)
    {
        return "{$key}#{$group}";
    }
}