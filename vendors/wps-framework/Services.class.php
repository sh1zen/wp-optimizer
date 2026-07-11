<?php
/**
 * Lightweight service registry for framework-owned runtime services.
 */

namespace WPS\core;

use InvalidArgumentException;

class Services
{
    private array $definitions = array();

    private array $instances = array();

    public function register(string $id, callable $factory): void
    {
        $id = trim($id);

        if ($id === '') {
            throw new InvalidArgumentException('A WPS service id cannot be empty.');
        }

        $this->definitions[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function set(string $id, object $service): void
    {
        $id = trim($id);

        if ($id === '') {
            throw new InvalidArgumentException('A WPS service id cannot be empty.');
        }

        $this->instances[$id] = $service;
        unset($this->definitions[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->definitions[$id]);
    }

    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (!isset($this->definitions[$id])) {
            throw new InvalidArgumentException("WPS service '{$id}' is not registered.");
        }

        $service = ($this->definitions[$id])($this);

        if (!is_object($service)) {
            throw new InvalidArgumentException("WPS service '{$id}' must resolve to an object.");
        }

        $this->instances[$id] = $service;

        return $service;
    }
}
