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

    private const GROUP_BATCH_SIZE = 128;

    /** Atomic index maintenance and bounded group deletion, using Redis's own clock. */
    private const GROUP_SCRIPT = <<<'LUA'
local index, legacy, data = KEYS[1], KEYS[2], KEYS[3]
local mode, value = ARGV[1], ARGV[2]
local expire, force, limit = tonumber(ARGV[3]), ARGV[4] == '1', tonumber(ARGV[5])
local clock = redis.call('TIME')
local now = tonumber(clock[1]) * 1000 + math.floor(tonumber(clock[2]) / 1000)
-- Validate types before changing data or consuming legacy membership.
local itype, ltype = redis.call('TYPE', index).ok, redis.call('TYPE', legacy).ok
if itype ~= 'none' and itype ~= 'zset' then return redis.error_reply('Invalid WPS group index type') end
if ltype ~= 'none' and ltype ~= 'set' then return redis.error_reply('Invalid WPS legacy group type') end
local function index_data(key)
    local ttl = redis.call('PTTL', key)
    if ttl == -1 then redis.call('ZADD', index, '+inf', key)
    elseif ttl >= 0 then redis.call('ZADD', index, now + ttl, key) end
end
local function expire_index()
    local last = redis.call('ZRANGE', index, -1, -1, 'WITHSCORES')
    if #last == 0 then return end
    if last[2] == 'inf' then redis.call('PERSIST', index)
    else redis.call('PEXPIREAT', index, math.floor(tonumber(last[2]))) end
end
if mode == 'size' then return redis.call('ZCARD', index) + redis.call('SCARD', legacy) end
if mode == 'flush' then
    local keys = redis.call('ZRANGE', index, 0, limit - 1)
    if #keys > 0 then redis.call('ZREM', index, unpack(keys)) end
    local old = redis.call('SPOP', legacy, limit - #keys)
    for _, key in ipairs(old) do table.insert(keys, key) end
    if #keys > 0 then redis.call('UNLINK', unpack(keys)) end
    expire_index()
    return #keys
end
local result = 1
if mode == 'set' or mode == 'set_permanent' or mode == 'replace' then
    local options = {}
    if mode == 'replace' then table.insert(options, 'XX')
    elseif not force then table.insert(options, 'NX') end
    if expire ~= 0 then table.insert(options, 'EX'); table.insert(options, expire)
    elseif mode == 'set' then table.insert(options, 'KEEPTTL') end
    if not redis.call('SET', data, value, unpack(options)) then return 0 end
    if legacy == '' then return 1 end
    index_data(data)
elseif mode == 'delete' then
    result = redis.call('UNLINK', data)
    redis.call('ZREM', index, data)
    redis.call('SREM', legacy, data)
end
-- Hot groups shed expired names in bounded batches; idle indexes expire themselves.
local expired = redis.call('ZRANGEBYSCORE', index, '-inf', now, 'LIMIT', 0, limit)
if #expired > 0 then redis.call('ZREM', index, unpack(expired)) end
local old = redis.call('SPOP', legacy, limit)
for _, key in ipairs(old) do index_data(key) end
expire_index()
if mode == 'keys' then return redis.call('ZRANGEBYSCORE', index, '(' .. now, '+inf') end
if mode == 'maintain' then
    return {redis.call('ZCOUNT', index, '(' .. now, '+inf'), redis.call('SCARD', legacy)}
end
return result
LUA;

    public function __construct(bool $reset_expiry = false)
    {
        parent::__construct($reset_expiry);

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

    /** Fetch one ordered Redis MGET and decode values with the single-read contract. */
    public function get_multiple(array $keys, $group, $default = false): array
    {
        $values = array_fill_keys($keys, $default);
        if (!$this->conn || !$keys) { return $values; }
        $backend_keys = array();
        foreach ($keys as $key) { $backend_keys[] = $this->co_group($key, $group); }
        try { $results = $this->conn->mGet($backend_keys); }
        catch (\Throwable $e) { return $values; }
        if (!is_array($results)) { return $values; }
        foreach (array_values($keys) as $position => $key) {
            if (array_key_exists($position, $results) && $results[$position] !== false) {
                $values[$key] = $this->decode($results[$position], $default);
            }
        }
        return $values;
    }

    public function dump($group = ''): array
    {
        if (!$this->conn) {
            return [];
        }

        $res = [];

        try {

            if (empty($group)) {
                $keys = $this->conn->keys('*');
            } else {
                do {
                    $state = $this->group_operation('maintain', $group);
                    if (!is_array($state)) { return array(); }
                } while ($state[1] > 0);
                $keys = $this->group_operation('keys', $group);
                if (!is_array($keys)) { return array(); }
            }

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

            $res = $this->group_operation('delete', $group, $key);

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

            // A finite starting budget prevents concurrent writers prolonging a flush.
            $remaining = $this->group_operation('size', $group);
            if ($remaining === false) { return false; }
            while ($remaining > 0) {
                $removed = $this->group_operation('flush', $group, '', '', 0, false,
                    min(self::GROUP_BATCH_SIZE, $remaining));
                if ($removed === false) { return false; }
                if ($removed === 0) { break; }
                $remaining -= $removed;
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
            do {
                $state = $this->group_operation('maintain', $group);
                if (!is_array($state)) { return false; }
                if ($state[0] > 0) { return true; }
            } while ($state[1] > 0);
            $exist = false;
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

        try {
            return (bool)$this->group_operation('replace', $group, $key, $this->encode($data), $expire);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function set($key, $value, $group, $force = false, $expire = 0): bool
    {
        if (!$this->conn) {
            return false;
        }

        $key = $this->co_group($key, $group);
        try {
            $mode = $this->reset_expiry && (int)$expire === 0 ? 'set_permanent' : 'set';
            $res = (bool)$this->group_operation($mode, $group, $key, $this->encode($value), $expire, $force);
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

    /** Execute one server-side batch for the current index and its legacy set. */
    private function group_operation(string $operation, string $group, string $data_key = '', string $value = '',
        int $expire = 0, bool $force = false, int $limit = self::GROUP_BATCH_SIZE)
    {
        return $this->conn->eval(self::GROUP_SCRIPT, array(
            'wps-cache:group:v2:' . hash('sha256', $group), $group, $data_key,
            $operation, $value, $expire, $force ? '1' : '0', $limit
        ), 3);
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
