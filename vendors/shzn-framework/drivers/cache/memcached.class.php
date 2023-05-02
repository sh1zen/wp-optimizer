<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core\Drivers;

class MemcacheD extends CacheInterface
{
    public function __construct()
    {
        parent::__construct();
        $this->conn = new \Memcached();
        $this->conn->addServer('localhost', 11211, 1);
    }

    public function set($key, $value, $group, $force = false, $expire = false)
    {
        if (!$this->conn) {
            return false;
        }

        if ($this->has($key, $group) and !$force) {
            return false;
        }

        $key = $this->co_group($key, $group);

        return $this->conn->set($key, $value, $expire);
    }

    public function has($key, $group)
    {
        if (!$this->conn) {
            return false;
        }

        $key = $this->co_group($key, $group);

        return (bool)$this->conn->get($key);
    }

    public function get($key, $group, $default = false)
    {
        if (!$this->conn) {
            return false;
        }

        return $this->conn->get($this->co_group($key, $group)) ?: $default;
    }

    public function dump($group = '')
    {
        $dump = [];
        foreach ($this->conn->getAllKeys() as $key) {
            if (str_contains($key, "#$group")) {
                $dump[$key] = $this->conn->get($key);
            }
        }

        return $dump;
    }

    public function delete_group($group)
    {
        foreach ($this->conn->getAllKeys() as $key) {
            if (str_contains($key, "#$group")) {
                $this->conn->delete($key, 0);
            }
        }
        return false;
    }

    public function delete($key, $group)
    {
        if (!$this->conn) {
            return false;
        }

        $key = $this->co_group($key, $group);

        return $this->conn->delete($key, 0);
    }

    public function replace($key, $data, $group, $expire = false)
    {
        if (!$this->conn) {
            return false;
        }

        $key = $this->co_group($key, $group);

        return $this->conn->replace($key, $data, $expire);
    }

    public function has_group($group)
    {
        foreach ($this->conn->getAllKeys() as $key) {
            if (str_contains($key, "#$group")) {
                return true;
            }
        }
        return false;
    }

    public function flush()
    {
        return $this->conn->flush();
    }

    public function flush_group($group)
    {
        foreach ($this->conn->getAllKeys() as $key) {
            if (str_contains($key, "#$group")) {
                $this->conn->delete($key, 0);
            }
        }
    }

    public function stats()
    {
        $info = $this->conn->getStats()['localhost:11211'] ?? [];
        return ['hits' => $info['get_hits'] ?? 0, 'miss' => $info['get_misses'] ?? 0, 'total' => $info['total_items'] ?? 0];
    }

    public function close()
    {
        return $this->conn->quit();
    }
}