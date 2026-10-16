<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core\Drivers;

class MemcacheD extends CacheInterface
{
    public function __construct(bool $reset_expiry = false)
    {
        parent::__construct($reset_expiry);
        $this->conn = new \Memcached();
        $this->conn->addServer('localhost', 11211, 1);
    }

    public function is_available(): bool
    {
        if (!$this->conn) {
            return false;
        }

        foreach ((array)$this->conn->getStats() as $stats) {
            if (is_array($stats)) {
                return true;
            }
        }

        return false;
    }

    public function set($key, $value, $group, $force = false, $expire = 0): bool
    {
        if (!$this->conn) {
            return false;
        }

        if (!$force && $this->has($key, $group)) {
            return false;
        }

        $key = $this->co_group($key, $group);

        return $this->conn->set($key, $value, $expire);
    }

    public function has($key, $group): bool
    {
        if (!$this->conn) {
            return false;
        }

        $this->conn->get($this->co_group($key, $group));

        return $this->conn->getResultCode() !== \Memcached::RES_NOTFOUND;
    }

    public function get($key, $group, $default = false)
    {
        if (!$this->conn) {
            return $default;
        }

        $value = $this->conn->get($this->co_group($key, $group));

        if ($this->conn->getResultCode() === \Memcached::RES_NOTFOUND) {
            return $default;
        }

        return $value;
    }

    /** Preserve false/null hits while fetching all backend keys in one getMulti call. */
    public function get_multiple(array $keys, $group, $default = false): array
    {
        $values = array_fill_keys($keys, $default);
        if (!$this->conn || !$keys) { return $values; }
        $backend_keys = array();
        foreach ($keys as $key) { $backend_keys[$key] = $this->co_group($key, $group); }
        $results = $this->conn->getMulti(array_values($backend_keys));
        if (!is_array($results)) { return $values; }
        foreach ($backend_keys as $key => $backend_key) {
            if (array_key_exists($backend_key, $results)) { $values[$key] = $results[$backend_key]; }
        }
        return $values;
    }

    public function dump($group = ''): array
    {
        $dump = [];
        foreach ($this->conn->getAllKeys() as $key) {
            if (str_starts_with($key, $group)) {
                $dump[$key] = $this->conn->get($key);
            }
        }

        return $dump;
    }

    public function delete_group($group): bool
    {
        foreach ($this->conn->getAllKeys() as $key) {
            if (str_starts_with($key, $group)) {
                $this->conn->delete($key, 0);
            }
        }
        return false;
    }

    public function delete($key, $group): bool
    {
        if (!$this->conn) {
            return false;
        }

        $key = $this->co_group($key, $group);

        return $this->conn->delete($key, 0);
    }

    public function replace($key, $data, $group, $expire = 0): bool
    {
        if (!$this->conn) {
            return false;
        }

        $key = $this->co_group($key, $group);

        return $this->conn->replace($key, $data, $expire);
    }

    public function has_group($group): bool
    {
        foreach ($this->conn->getAllKeys() as $key) {
            if (str_starts_with($key, $group)) {
                return true;
            }
        }
        return false;
    }

    public function flush(): bool
    {
        return $this->conn->flush();
    }

    public function flush_group($group): bool
    {
        foreach ($this->conn->getAllKeys() as $key) {
            if (str_starts_with($key, $group)) {
                $this->conn->delete($key, 0);
            }
        }
        return true;
    }

    public function stats(): array
    {
        $info = $this->conn->getStats()['localhost:11211'] ?? [];
        return ['hits' => $info['get_hits'] ?? 0, 'miss' => $info['get_misses'] ?? 0, 'total' => $info['total_items'] ?? 0];
    }

    public function close(): bool
    {
        return $this->conn->quit();
    }
}
