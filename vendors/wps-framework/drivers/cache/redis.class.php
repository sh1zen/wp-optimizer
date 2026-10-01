<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core\Drivers;

class Redis extends CacheInterface
{
    private const VALUE_PREFIX = 'wps-cache:v1:';

    public function __construct()
    {
        parent::__construct();

        $this->conn = new \Redis();

        try {
            $host = defined('WPS_REDIS_HOST') ? (string)WPS_REDIS_HOST : '127.0.0.1';
            $port = defined('WPS_REDIS_PORT') ? (int)WPS_REDIS_PORT : 6379;

            if (!$this->conn->connect($host, $port)) {
                $this->conn = null;
                return;
            }

            if (defined('WPS_REDIS_PASSWORD')) {
                $this->conn->auth(WPS_REDIS_PASSWORD);
            }

            if (defined('WPS_REDIS_DATABASE')) {
                $this->conn->select((int)WPS_REDIS_DATABASE);
            }

        } catch (\Throwable $e) {
            $this->conn = null;
        }
    }

    public function is_available(): bool
    {
        return $this->conn !== null;
    }

    public function get($key, $group, $default = false)
    {
        if (!$this->conn) {
            return $default;
        }

        $key = $this->co_group($key, $group);

        try {
            $value = $this->conn->get($key);
        } catch (\Throwable $e) {
            return $default;
        }

        if ($value === false) {
            return $default;
        }

        return $this->decode($value, $default);
    }

    public function dump($group = ''): array
    {
        if (!$this->conn) {
            return [];
        }

        $res = [];

        try {

            $keys = empty($group) ? $this->conn->keys('*') : $this->conn->sMembers($group);

            foreach ($keys as $key) {
                $res[$key] = $this->conn->dump($key);
            }

        } catch (\Throwable $e) {
            $res = [];
        }

        return $res;
    }

    public function delete($key, $group): bool
    {
        if (!$this->conn) {
            return false;
        }

        $key = $this->co_group($key, $group);

        try {

            $this->conn->sRem($group, $key);

            $res = $this->conn->unlink($key);

        } catch (\Throwable $e) {
            $res = false;
        }

        return $res;
    }

    public function flush_group($group): bool
    {
        if (!$this->conn) {
            return false;
        }

        try {

            foreach ($this->conn->sMembers($group) as $key) {
                $this->conn->unlink($key);
                $this->conn->sRem($group, $key);
            }

            return true;

        } catch (\Throwable $e) {
            return false;
        }
    }

    public function has_group($group): bool
    {
        if (!$this->conn) {
            return false;
        }

        try {
            $exist = (bool)$this->conn->sCard($group);
        } catch (\Throwable $e) {
            $exist = false;
        }

        return $exist;
    }

    public function flush(): bool
    {
        if (!$this->conn) {
            return false;
        }

        try {
            return $this->conn->flushAll();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function replace($key, $data, $group, $expire = 0): bool
    {
        if (!$this->conn) {
            return false;
        }

        $key = $this->co_group($key, $group);

        $options = ['XX'];

        if ($expire) {
            $options['EX'] = $expire;
        }

        try {
            $updated = $this->conn->set($key, $this->encode($data), $options);

            if ($updated && $group) {
                $this->conn->sAdd($group, $key);
            }

            return $updated;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function set($key, $value, $group, $force = false, $expire = 0): bool
    {
        if (!$this->conn) {
            return false;
        }

        if ($this->has($key, $group) and !$force) {
            return false;
        }

        $key = $this->co_group($key, $group);

        $options = $expire ? ['EX' => $expire] : ['KEEPTTL' => true];

        try {
            $res = $this->conn->set($key, $this->encode($value), $options);

            if ($res && $group) {
                $this->conn->sAdd($group, $key);
            }
        } catch (\Throwable $e) {
            $res = false;
        }

        return $res;
    }

    public function has($key, $group): bool
    {
        if (!$this->conn) {
            return false;
        }

        $key = $this->co_group($key, $group);

        try {
            $exist = $this->conn->exists($key);
        } catch (\Throwable $e) {
            $exist = false;
        }

        return $exist;
    }

    public function stats(): array
    {
        if (!$this->conn) {
            return parent::stats();
        }

        try {

            $info = $this->conn->info('stats');

            $stats = ['hits' => $info['keyspace_hits'], 'miss' => $info['keyspace_misses'], 'total' => $this->conn->dbSize()];

        } catch (\Throwable $e) {
            $stats = parent::stats();
        }

        return $stats;
    }

    public function close(): bool
    {
        if (!$this->conn) {
            return true;
        }

        try {
            return $this->conn->close();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function encode($value): string
    {
        return self::VALUE_PREFIX . serialize($value);
    }

    private function decode($value, $default = false)
    {
        if (!is_string($value) || strpos($value, self::VALUE_PREFIX) !== 0) {
            // Keep entries written by older framework versions readable.
            return $value;
        }

        $serialized = substr($value, strlen(self::VALUE_PREFIX));
        $decoded = @unserialize($serialized);

        if ($decoded === false && $serialized !== 'b:0;') {
            return $default;
        }

        return $decoded;
    }
}
