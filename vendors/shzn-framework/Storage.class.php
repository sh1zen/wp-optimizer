<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C)  2022
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core;

class Storage
{
    private array $cache;

    private $storage_name;

    private $custom_storage_name;

    private bool $auto_save;

    private bool $autosave_action_set = false;

    public function __construct($autosave, $storage_name)
    {
        $this->cache = array();

        $this->storage_name = $storage_name;

        $this->auto_save = $autosave;
    }

    public static function generate_id($identifier, ...$args)
    {
        if (is_array($identifier) or is_object($identifier)) {
            return self::generate_key($identifier, ...$args);
        }

        return 'ID:' . $identifier . '_' . self::generate_key($identifier . serialize($args));
    }

    public static function generate_key(...$args)
    {
        return hash('md5', serialize($args));
    }

    public function disable_autosave()
    {
        $this->auto_save = false;
        if ($this->autosave_action_set) {
            remove_action('shutdown', array($this, 'autosave'));
        }
    }

    public function get($key = '', $context = 'default', $blog_id = 0)
    {
        $_context = $this->filter_context($context, $blog_id);

        if (isset($this->cache[$_context][$key])) {
            return $this->cache[$_context][$key]['data'];
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
            $this->cache[$context][$key] = $data;
        }

        return $data['data'];
    }

    private function generate_path($context, $key = '')
    {
        $context = str_replace('default', '', $context);

        $sub_folder = $this->custom_storage_name ? $this->custom_storage_name : $this->storage_name;

        return WP_CONTENT_DIR . "/{$sub_folder}/{$context}/{$key}";
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

        if (!empty($key)) {
            unset($this->cache[$context][$key]);
        }
        else {
            unset($this->cache[$context]);
        }
    }

    private function handle_autosave()
    {
        if ($this->auto_save and !$this->autosave_action_set) {
            $this->autosave_action_set = add_action('shutdown', array($this, 'autosave'));
        }
    }

    public function set($data, $context = 'default', $key = 'main', $expire = false, $force = false, $blog_id = 0)
    {
        $context = $this->filter_context($context, $blog_id);

        if (!$force and isset($this->cache[$context][$key])) {
            $force = self::generate_key($data) !== self::generate_key($this->cache[$context][$key]['data']);

            if (!$force) {
                return;
            }
        }

        // auto add time() to expire if passed just lifespan
        if ($expire <= time()) {
            $expire += time();
        }

        $args = array(
            'expire'    => $expire,
            'data'      => $data,
            'file_name' => $key,
            'context'   => $context,
            'force'     => $force
        );

        $this->cache[$context][$key] = $args;

        $this->handle_autosave();
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

    public function autosave()
    {
        foreach ($this->cache as $context) {

            foreach ($context as $element) {

                if ($element['expire'] and $element['expire'] < time()) {
                    continue;
                }

                $path = $this->generate_path($element['context']);

                if (file_exists($path . $element['file_name']) and !$element['force']) {
                    continue;
                }

                if (!($cached = serialize($element))) {
                    continue;
                }

                if (!file_exists($path)) {
                    mkdir($path, 0774, true);
                }

                file_put_contents($path . $element['file_name'], $cached);
            }
        }
    }

    public function get_path($context = 'default', $key = '', $blog_id = 0)
    {
        return $this->generate_path($this->filter_context($context, $blog_id), $key);
    }

    public function save($args = array(), $blog_id = 0)
    {
        $args = array_merge(array(
            'expire'    => false,
            'file_name' => 'main',
            'context'   => 'default',
            'force'     => false,
            'data'      => []
        ), $args);

        $args['context'] = $this->filter_context($args['context'], $blog_id);

        if ($args['expire'] <= time()) {
            $args['expire'] += time();
        }

        $path = $this->generate_path($args['context']);

        if (file_exists($path . $args['file_name']) and !$args['force']) {
            return false;
        }

        $cached = serialize($args);

        if (!$cached) {
            return false;
        }

        Disk::make_path($path);

        return file_put_contents($path . $args['file_name'], $cached);
    }

    public function enable_autosave()
    {
        $this->auto_save = true;
    }
}

