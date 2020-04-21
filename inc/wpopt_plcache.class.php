<?php

class wpopt_plcache
{
    private static $_instance;
    private static $use_wp_cache = false;

    private $cache = array();

    public function __construct()
    {

    }

    public static function getInstance($use_wp_cache = false)
    {
        if (!defined('ABSPATH'))
            exit();

        if (!isset(self::$_instance)) {

            self::$use_wp_cache = $use_wp_cache;
            self::$_instance = new self();

        }

        return self::$_instance;
    }

    public function __get($key)
    {
        return $this->get_cache($key, 'wpopt_core');
    }

    public function __set($key, $data)
    {
        return $this->set_cache($key, $data, 'wpopt_core', true);
    }

    public function get_cache($key, $group)
    {
        if (self::$use_wp_cache)
            return wp_cache_get($key, $group);

        if ($this->cache_exists($key, $group)) {
            if (is_object($this->cache[$group][$key])) {
                return clone $this->cache[$group][$key];
            }
            else {
                return $this->cache[$group][$key];
            }
        }

        return false;
    }

    public function set_cache($key, $data, $group, $force = false)
    {
        if (self::$use_wp_cache)
            return wp_cache_add($key, $data, $group);

        if (!$force and $this->cache_exists($key, $group))
            return false;

        return $this->forceset_cache($key, $data, $group);
    }

    private function cache_exists($key, $group)
    {
        return isset($this->cache[$group]) && (isset($this->cache[$group][$key]) || array_key_exists($key, $this->cache[$group]));
    }

    public function delete_cache($key, $group)
    {
        if (self::$use_wp_cache)
            wp_cache_delete($key, $group);

        if (!$this->cache_exists($key, $group)) {
            return false;
        }

        unset($this->cache[$group][$key]);

        return true;
    }

    public function forceset_cache($key, $data, $group)
    {
        if (self::$use_wp_cache)
            return wp_cache_set($key, $data, $group);

        if (is_object($data)) {
            $data = clone $data;
        }

        $this->cache[$group][$key] = $data;

        return true;
    }
}