<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core;

class Storage
{
    private Cache $cache;

    private $storage_name;

    private $custom_storage_name;

    public function __construct($context)
    {
        $this->cache = shzn($context)->cache;

        $this->storage_name = $context;
    }

    public static function generate_id($identifier, ...$args)
    {
        if (is_array($identifier) or is_object($identifier)) {
            return Cache::generate_key($identifier, ...$args);
        }

        return 'ID:' . $identifier . '_' . Cache::generate_key($identifier . serialize($args));
    }

    public function get($key = '', $context = 'default', $blog_id = 0)
    {
        $context = $this->filter_context($context, $blog_id);

        if ($res = $this->cache->get($key, $context)) {
            return $res['data'];
        }

        return $this->load($key, $context, true, $blog_id);
    }

    private function filter_context($context, $_blog_id = 0)
    {
        $blog_id = '';

        if (is_multisite()) {

            if ($_blog_id) {
                $blog_id = "blog_{$_blog_id}/";
            }
            else {
                $blog_id = "blog_" . get_current_blog_id() . "/";
            }
        }

        return $blog_id . $context;
    }

    public function load($key, $context = 'default', $cache = false, $blog_id = 0)
    {
        $context = $this->filter_context($context, $blog_id);

        $path = $this->generate_path($context, $key);

        if (!file_exists($path)) {
            return false;
        }

        $data = file_get_contents($path);

        if (!$data) {
            return false;
        }

        if (!($data = unserialize($data))) {
            @unlink($path);
            return false;
        }

        if (boolval($data['expire']) and $data['expire'] < time()) {
            @unlink($path);
            return false;
        }

        if ($cache) {
            $this->cache->set($key, $data, $context, true);
        }

        return $data['data'];
    }

    private function generate_path($context, $key = '')
    {
        $context = str_replace('default', '', $context);

        $sub_folder = $this->custom_storage_name ?: $this->storage_name;

        return WP_CONTENT_DIR . "/{$sub_folder}/{$context}/{$key}";
    }

    public function set($data, $key = 'main', $context = 'default', $expire = false, $force = false, $blog_id = 0)
    {
        $context = $this->filter_context($context, $blog_id);

        if (!$force and $this->cache->has($key, $context)) {
            $force = Cache::generate_key($data) !== Cache::generate_key($this->cache->get($key, $context)['data'] ?? '');

            if (!$force) {
                return;
            }
        }

        // auto add time() to expire if passed just lifespan
        if ($expire <= time()) {
            $expire += time();
        }

        $args = array(
            'expire' => $expire,
            'data'   => $data,
            'force'  => $force
        );

        $this->cache->set($key, $args, $context, true);

        $this->save($data, $key, $context, $expire, $force, $blog_id);
    }

    //$key, $value, $group, $expires, true
    public function save($data, $key, $context = 'default', $expire = false, $force = false, $blog_id = 0)
    {
        $context = $this->filter_context($context, $blog_id);

        // auto add time() to expire if passed just lifespan
        if ($expire <= time()) {
            $expire += time();
        }

        $args = array(
            'expire' => $expire,
            'force'  => $force,
            'data'   => $data
        );

        $path = $this->generate_path($context);

        if (file_exists($path . $key) and !$force) {
            return false;
        }

        $cached = serialize($args);

        if (!$cached) {
            return false;
        }

        Disk::make_path($path);

        return file_put_contents($path . $key, $cached);
    }

    public function use_custom_storage_name($name)
    {
        $this->custom_storage_name = $name;
    }

    public function delete($context = 'default', $key = '', $blog_id = 0)
    {
        $this->remove($context, $key, $blog_id);

        $context = $this->filter_context($context, $blog_id);

        $identifier = $key;
        if ($key and (str_contains($key, 'ID:'))) {
            $key = '';
        }

        $path = $this->generate_path($context, $key);

        if (!file_exists($path)) {
            return false;
        }

        Disk::delete_files($path, $identifier);

        return true;
    }

    public function remove($context = 'default', $key = '', $blog_id = 0)
    {
        $context = $this->filter_context($context, $blog_id);

        return empty($key) ? $this->cache->flush_group($context) : $this->cache->delete($key, $context);
    }

    public function get_size($contexts = '', $blog_id = 0)
    {
        $size = 0;
        foreach ((array)$contexts as $context) {

            $context = $this->filter_context($context, $blog_id);

            $path = $this->generate_path($context);

            if (!file_exists($path)) {
                continue;
            }

            $size += Disk::calc_size($path);
        }

        return size_format($size);
    }

    public function get_path($context = 'default', $key = '', $blog_id = 0)
    {
        return $this->generate_path($this->filter_context($context, $blog_id), $key);
    }
}

