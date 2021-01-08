<?php
/**
 * @package   wp-optimizer
 * @author    sh1zen
 * @copyright Copyright (C) 2020
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

class WOStorage
{
    private static $_instance;

    private $cache;

    private function __construct()
    {
        $this->cache = array();
        $this->enable_autosave();
    }

    public function enable_autosave()
    {
        add_action('shutdown', array($this, 'autosave'));
    }

    public static function getInstance()
    {
        if (!isset(self::$_instance)) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public static function generate_identifier($identifier, ...$args)
    {
        if (!is_array($identifier) or is_object($identifier))
            return self::generate_key($identifier, ...$args);

        return 'ID:' . $identifier . '_' . crc32($identifier . serialize($args));
    }

    public static function generate_key(...$args)
    {
        return md5(serialize($args));
    }

    public function disable_autosave()
    {
        remove_action('shutdown', array($this, 'autosave'));
    }

    public function get($key = '', $context = 'default', $blog_id = 0)
    {
        $_context = $this->filter_context($context, $blog_id);

        if (isset($this->cache[$_context][$key]))
            return $this->cache[$_context][$key]['data'];

        return $this->load($key, $context, true, $blog_id);
    }

    private function filter_context($context, $_blog_id = 0)
    {
        $blog_id = '';

        if (is_multisite()) {

            if ($_blog_id)
                $blog_id = "blog_{$_blog_id}/";
            else
                $blog_id = "blog_" . get_current_blog_id() . "/";
        }

        return $blog_id . $context;
    }

    public function load($key, $context = 'default', $cache = false, $blog_id = 0)
    {
        $context = $this->filter_context($context, $blog_id);

        $path = $this->generate_path($context, $key);

        if (!file_exists($path))
            return false;

        $data = file_get_contents($path);

        if (!$data)
            return false;

        if (!($data = unserialize($data)))
            return false;

        if (boolval($data['expire']) and $data['expire'] < time())
            return false;

        if ($cache) {
            $this->cache[$context][$key] = $data;
        }

        return $data['data'];
    }

    private function generate_path($context, $key = '')
    {
        $context = str_replace('default', '', $context);

        return WP_CONTENT_DIR . "/wpopt-storage/{$context}/{$key}";
    }

    public function delete($context = 'default', $key = '', $blog_id = 0)
    {
        $this->remove($context, $key, $blog_id);

        $context = $this->filter_context($context, $blog_id);

        $identifier = $key;
        if ($key and (strpos($key, 'ID:') !== false)) {
            $key = '';
        }

        $path = $this->generate_path($context, $key);

        if (!file_exists($path))
            return false;

        WODisk::delete_files($path, $identifier);

        return true;
    }

    public function remove($context = 'default', $key = '', $blog_id = 0)
    {
        $context = $this->filter_context($context, $blog_id);

        if (!empty($key))
            unset($this->cache[$context][$key]);
        else
            unset($this->cache[$context]);
    }

    public function set($data, $context = 'default', $key = 'main', $expire = false, $force = false, $blog_id = 0)
    {
        $context = $this->filter_context($context, $blog_id);

        if (!$force and isset($this->cache[$context][$key])) {
            $force = md5(serialize($data)) !== md5(serialize($this->cache[$context][$key]['data']));

            if (!$force)
                return;
        }

        // auto add time() to expire if passed just lifespan
        if ($expire <= YEAR_IN_SECONDS) {
            $expire += time();
        }

        $args = array(
            'expire'    => $expire,
            'data'      => $data,
            'file_name' => $key,
            'context'   => $context,
            'force'     => $force,
        );

        $this->cache[$context][$key] = $args;
    }

    public function get_size($contexts = '', $blog_id = 0)
    {
        $size = 0;
        foreach ((array)$contexts as $context) {

            $context = $this->filter_context($context, $blog_id);

            $path = $this->generate_path($context);

            if (!file_exists($path))
                continue;

            $size += WODisk::calc_size($path);
        }

        return wpopt_bytes2size($size);
    }

    public function autosave()
    {
        foreach ($this->cache as $context) {

            foreach ($context as $element) {

                if ($element['expire'] and $element['expire'] < time())
                    continue;

                $path = $this->generate_path($element['context']);

                if (file_exists($path . $element['file_name']) and !$element['force'])
                    continue;

                if (!($cached = serialize($element)))
                    continue;

                if (!file_exists($path))
                    mkdir($path, 0777, true);

                file_put_contents($path . $element['file_name'], $cached);
            }
        }
    }

    public function save($args = array(), $blog_id = 0)
    {
        $args = array_merge(array(
            'expire'    => false,
            'file_name' => 'main',
            'context'   => 'default',
            'force'     => false,
            'data'      => array(),
        ), $args);

        $args['context'] = $this->filter_context($args['context'], $blog_id);

        if ($args['expire'] <= YEAR_IN_SECONDS) {
            $args['expire'] += time();
        }

        $path = $this->generate_path($args['context']);

        if (file_exists($path . $args['file_name']) and !$args['force'])
            return false;

        $cached = serialize($args);

        if (!$cached)
            return false;

        if (!file_exists($path))
            mkdir($path, 0777, true);

        return file_put_contents($path . $args['file_name'], $cached);
    }
}

