<?php
/**
 * @package   Flex and Go
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

    public function disable_autosave()
    {
        remove_action('shutdown', array($this, 'autosave'));
    }

    public function get($key = '', $context = 'default')
    {
        if (isset($this->cache[$context][$key]))
            return $this->cache[$context][$key]['data'];

        return $this->load($key, $context, true);
    }

    public function load($key, $context = 'default', $cache = false)
    {
        $path = $this->generate_path($context, $key);

        if (!file_exists($path))
            return false;

        $data = file_get_contents($path);

        if (!$data)
            return false;

        if (!($data = json_decode($data, true)))
            return false;

        $expire = $data['expire'];

        if (boolval($expire) and $expire < time())
            return false;

        if ($cache) {
            $this->cache[$context][$key] = $data;
        }

        return $data['data'];
    }

    private function generate_path($context = 'default', $key = '')
    {
        if ($context === 'default') {
            $context = '';
        }

        return WP_CONTENT_DIR . '/wpopt/storage/' . $context . '/' . $key;
    }

    public function clear($context = 'default', $key = '')
    {
        if (!empty($key))
            unset($this->cache[$context][$key]);
        else
            unset($this->cache[$context]);
    }

    public function remove($context = 'default', $key = '')
    {
        $path = $this->generate_path($context, $key);

        wpopt_delete_files($path);
    }

    public function set($data, $context = 'default', $key = 'main', $expire = false, $force = false)
    {
        // auto add time() to expire if passed just lifespan
        if ($expire <= YEAR_IN_SECONDS) {
            $expire += time();
        }

        if (!$force and isset($this->cache[$context][$key])) {
            $force = md5(serialize($data)) !== md5(serialize($this->cache[$context][$key]['data']));

            if(!$force)
                return;
        }

        $args = array(
            'expire'    => $expire,
            'data'      => $data,
            'file_name' => $key,
            'context'   => $context,
            'force'     => $force
        );

        $this->cache[$context][$key] = $args;
    }

    public function get_size($context = '', $key = '')
    {
        $path = $this->generate_path($context, $key);

        if(!file_exists($path))
            return "0 B";

        return wpopt_bytes2size(wpopt_calc_folder_size($path));
    }

    public function autosave()
    {
        foreach ($this->cache as $context) {

            foreach ($context as $element) {
                if ($element['expire'] and $element['expire'] < time())
                    continue;

                $this->save($element);
            }
        }
    }

    public function save($args = array())
    {
        $args = array_merge(array(
            'expire'    => false,
            'file_name' => 'main',
            'context'   => 'default',
            'force'     => false,
            'data'      => array()
        ), $args);

        if ($args['expire'] <= YEAR_IN_SECONDS) {
            $args['expire'] += time();
        }

        $path = $this->generate_path($args['context']);

        if (file_exists($path . $args['file_name']) and !$args['force'])
            return false;

        $cached = json_encode($args);

        if (!$cached)
            return false;

        if (!file_exists($path))
            mkdir($path, 0777, true);

        return file_put_contents($path . $args['file_name'], $cached);
    }
}

