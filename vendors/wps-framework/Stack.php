<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

class Stack
{
    private static array $_instance;
    private array $cache = [];

    private array $queueMap = [];

    public static function getInstance($context = 'wps'): Stack
    {
        if (!isset(self::$_instance[$context])) {
            self::$_instance[$context] = new self();
        }

        return self::$_instance[$context];
    }

    public function push($item, $value): bool
    {
        return $this->set(($this->queueMap[$item] ?? 0) + 1, $item, $value);
    }

    public function set($index, $item, $value): bool
    {
        $this->queueMap[$item] = $index;
        $this->cache[$item][$index] = $value;
        return true;
    }

    public function pop($item, $default = null)
    {
        if (!isset($this->queueMap[$item])) {
            return $default;
        }

        $value = $this->get($this->queueMap[$item], $item, $default);

        unset($this->cache[$item][$this->queueMap[$item]]);
        $this->queueMap[$item]--;

        if ($this->queueMap[$item] == 0) {
            unset($this->queueMap[$item]);
        }

        return $value;
    }

    public function get($index, $item, $default = null)
    {
        return $this->cache[$item][$index] ?? $default;
    }

    public function dump(): array
    {
        return $this->cache;
    }
}