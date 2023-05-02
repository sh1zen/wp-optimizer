<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core\Drivers;

class Redis extends CacheInterface
{
    public function __construct()
    {
        parent::__construct();

        $this->conn = new \Redis();

        try {
            $this->conn->connect('localhost', 6379);

            if (defined('SHZN_REDIS_PASSWORD')) {
                $this->conn->auth(SHZN_REDIS_PASSWORD);
            }

            $memory_size = 128;
            if (defined('SHZN_REDIS_MAX_MEMORY')) {
                $memory_size = SHZN_REDIS_MAX_MEMORY;
            }

            $this->conn->config('set', 'maxmemory', $memory_size * 1024 * 1024);

        } catch (\RedisException $e) {
            $this->conn = null;
        }
    }

    public function get($key, $group, $default = false)
    {
        if (!$this->conn) {
            return false;
        }

        $key = $this->co_group($key, $group);

        try {
            $value = $this->conn->get($key);
        } catch (\RedisException $e) {
            $value = false;
        }

        return $value ?: $default;
    }

    public function dump($group = '')
    {
        if (!$this->conn) {
            return false;
        }

        $res = [];

        try {

            $keys = empty($group) ? $this->conn->keys('*') : $this->conn->sMembers($group);

            foreach ($keys as $key) {
                $res[$key] = $this->conn->dump($key);
            }

        } catch (\RedisException $e) {
            $res = [];
        }

        return $res;
    }

    public function delete($key, $group)
    {
        if (!$this->conn) {
            return false;
        }

        $key = $this->co_group($key, $group);

        try {

            $this->conn->sRem($group, $key);

            $res = $this->conn->unlink($key);

        } catch (\RedisException $e) {
            $res = false;
        }

        return $res;
    }

    public function flush_group($group)
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

        } catch (\RedisException $e) {
            return false;
        }
    }

    public function has_group($group)
    {
        if (!$this->conn) {
            return false;
        }

        try {
            $exist = (bool)$this->conn->sCard($group);
        } catch (\RedisException $e) {
            $exist = false;
        }

        return $exist;
    }

    public function flush()
    {
        if (!$this->conn) {
            return false;
        }

        try {
            return $this->conn->flushAll();
        } catch (\RedisException $e) {
            return false;
        }
    }

    public function replace($key, $data, $group, $expire = false)
    {
        if (!$this->conn) {
            return false;
        }

        $key = $this->co_group($key, $group);

        try {
            return $this->conn->set($key, $data, ['XX', 'EX' => $expire]);
        } catch (\RedisException $e) {
            return false;
        }
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

        if ($group) {
            $this->conn->sAdd($group, $key);
        }

        $options = ['KEEPTTL' => true];

        if ($expire) {
            $options['EX'] = $expire;
        }

        try {
            $res = $this->conn->set($key, $value, $options);
        } catch (\RedisException $e) {
            $res = false;
        }

        return $res;
    }

    public function has($key, $group)
    {
        if (!$this->conn) {
            return false;
        }

        $key = $this->co_group($key, $group);

        try {
            $exist = $this->conn->exists($key);
        } catch (\RedisException $e) {
            $exist = false;
        }

        return $exist;
    }

    public function stats()
    {
        if (!$this->conn) {
            return parent::stats();
        }

        try {

            $info = $this->conn->info('stats');

            $stats = ['hits' => $info['keyspace_hits'], 'miss' => $info['keyspace_misses'], 'total' => $this->conn->dbSize()];

        } catch (\RedisException $e) {
            $stats = parent::stats();
        }

        return $stats;
    }

    public function close()
    {
        try {
            return $this->conn->close();
        } catch (\RedisException $e) {
            return false;
        }
    }
}