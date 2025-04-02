<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

class Storage
{
    private ?Cache $cache;

    private string $storage_name;

    private bool $is_multisite;

    private string $blog_prefix;

    private bool $active;

    /**
     * to disable autosave set this to false
     */
    private ?bool $autosave_action = null;

    public function __construct($context, $active = true)
    {
        $this->cache = new Cache('wps-storage');

        $this->is_multisite = is_multisite();

        $this->active = $active;

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

    public function autosave()
    {
        $this->cache->iterate(function ($data, $key, $context) {

            if ($data['expire'] and $data['expire'] < time()) {
                return;
            }

            $this->save($data, $key, $context, $data['force']);
        });
    }

    private function save($data, $key, $context, $force = false): bool
    {
        if (isset($data['write']) and !$data['write']) {
            return true;
        }

        $path = $this->generate_path($context);

        if (file_exists($path . $key) and !$force) {
            return false;
        }

        unset($data['write']);

        if (!($cached = serialize($data)) or !Disk::make_path($path)) {
            return false;
        }

        return (bool)@file_put_contents($path . $key, $cached);
    }

    private function generate_path($context, $key = ''): string
    {
        $context = str_replace('default', '', $context);

        return WP_CONTENT_DIR . "/$this->storage_name/$context/$key";
    }

    public function delete_old($context = 'default', $lifetime = 0, $blog_id = 0): int
    {
        return Disk::delete($this->get_path($context, '', $blog_id), $lifetime);
    }

    public function delete($context = 'default', $key = '', $blog_id = 0): int
    {
        $res = Disk::delete($this->get_path($context, $key, $blog_id), 0, '*');

        if ($this->cache) {
            if (empty($key)) {
                $this->cache->flush_group($context);
            }
            else {
                $this->cache->delete($key, $context);
            }
        }

        return $res;
    }

    public function get_path($context = 'default', $key = '', $blog_id = 0): string
    {
        return $this->generate_path($this->filter_context($context, $blog_id), $key);
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

    public function get($key = '', $context = 'default', $blog_id = 0)
    {
        if ($this->cache and ($res = $this->cache->get($key, $this->filter_context($context, $blog_id)))) {
            return $res['data'];
        }

        return $this->load($key, $context, true, $blog_id);
    }

    public function load($key, $context = 'default', $cache = false, $blog_id = 0)
    {
        $context = $this->filter_context($context, $blog_id);

        $path = $this->generate_path($context, $key);

        if (!$this->active or !file_exists($path)) {
            return false;
        }

        $data = unserialize(file_get_contents($path) ?: '');

        if (empty($data) or ($data['expire'] and $data['expire'] < time())) {
            @unlink($path);
            return false;
        }

        $data['write'] = false;

        if ($cache and $this->cache) {
            $this->cache->set($key, $data, $context, true, $data['expire']);
        }

        return $data['data'];
    }

    public function set($data, $key = 'main', $context = 'default', $expire = false, $force = false, $blog_id = 0): bool
    {
        if (empty($key)) {
            return false;
        }

        $context = $this->filter_context($context, $blog_id);

        if (!$force and $this->cache and $this->cache->has($key, $context)) {
            return false;
        }

        // convert duration time into timestamp from now
        $expire += time();

        $args = array(
            'expire' => $expire,
            'data'   => $data,
            'force'  => $force,
            'write'  => true
        );

        if ($this->cache) {
            $this->cache->set($key, $args, $context, true, $expire);
        }

        return $this->handle_autosave($args, $key, $context, $force);
    }

    private function handle_autosave($content, $key, $context, $force): bool
    {
        if (is_null($this->autosave_action)) {
            $this->autosave_action = add_action('shutdown', array($this, 'autosave'));
        }

        if (!$this->autosave_action) {
            return $this->save($content, $key, $context, $force);
        }

        return true;
    }

    public function get_size($contexts = '', $blog_id = 0): string
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

        return (string)size_format($size);
    }

    public function status(): bool
    {
        return $this->active and is_writeable($this->generate_path(''));
    }
}

