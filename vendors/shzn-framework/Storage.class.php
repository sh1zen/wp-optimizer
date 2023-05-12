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

    private string $storage_name;

    private bool $is_multisite;
    private string $blog_prefix;

    public function __construct($context)
    {
        $this->cache = shzn($context)->cache;

        $this->is_multisite = is_multisite();

        $this->switch_to_blog();

        $this->storage_name = $context;
    }

    public function switch_to_blog($blog_id = 0): bool
    {
        if ($blog_id === 0) {
            $blog_id = get_current_blog_id();
        }

        $this->blog_prefix = $this->is_multisite ? "#$blog_id" : '';

        return $this->is_multisite;
    }

    public function get($key = '', $context = 'default', $blog_id = 0)
    {
        if ($res = $this->cache->get($key, $this->filter_context($context, $blog_id))) {
            return $res['data'];
        }

        return $this->load($key, $context, true, $blog_id);
    }

    private function filter_context($context, $blog_id = 0)
    {
        $this->switch_to_blog($blog_id);

        if (empty($context)) {
            $context = 'default';
        }

        if ($this->is_multisite) {
            $context = "$context/$this->blog_prefix";
        }

        return $context;
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

    private function generate_path($context, $key = ''): string
    {
        $context = str_replace('default', '', $context);

        return WP_CONTENT_DIR . "/$this->storage_name/$context/$key";
    }

    public function set($data, $key = 'main', $context = 'default', $expire = false, $force = false, $blog_id = 0)
    {
        if (empty($key)) {
            return false;
        }

        $context = $this->filter_context($context, $blog_id);

        if (!$force and $this->cache->has($key, $context)) {
            return false;
        }

        // todo check
        if ($expire < YEAR_IN_SECONDS) {
            $expire += time();
        }

        $args = array(
            'expire' => $expire,
            'data'   => $data,
            'force'  => $force
        );

        $this->cache->set($key, $args, $context, true);

        return $this->save($args, $key, $context, $force);
    }

    private function save($content, $key, $context, $force = false)
    {
        $path = $this->generate_path($context);

        if (file_exists($path . $key) and !$force) {
            return false;
        }

        if (!$cached = serialize($content)) {
            return false;
        }

        if (!Disk::make_path($path)) {
            return false;
        }

        return file_put_contents($path . $key, $cached);
    }

    public function delete_old($context = 'default', $lifetime = 0, $blog_id = 0): int
    {
        return Disk::delete($this->get_path($context, '', $blog_id), $lifetime);
    }

    public function delete($context = 'default', $key = '', $blog_id = 0): int
    {
        $this->remove($context, $key, $blog_id);

        return Disk::delete($this->get_path($context, $key, $blog_id));
    }

    public function remove($context = 'default', $key = '', $blog_id = 0): bool
    {
        $context = $this->filter_context($context, $blog_id);

        return empty($key) ? $this->cache->flush_group($context) : $this->cache->delete($key, $context);
    }

    public function get_path($context = 'default', $key = '', $blog_id = 0): string
    {
        return $this->generate_path($this->filter_context($context, $blog_id), $key);
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
}

