<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C)  2022
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

use SHZN\core\Rewriter;

class StaticCache extends Cache_Dispatcher
{
    protected static $_Instance;

    private Rewriter $rewriter;

    private $clear = true;

    protected function __construct($args)
    {
        if (!parent::__construct($args)) {
            return false;
        }

        ob_start([$this, "cache_buffer"]);

        $this->rewriter = Rewriter::getInstance();

        add_action("parse_query", [$this, "cache_handler"]);

        return true;
    }

    /**
     * Return a singleton instance of the current class
     *
     * @param array $args
     * @return Cache_Dispatcher
     */
    public static function Initialize($args = array())
    {
        if (!self::$_Instance) {
            self::$_Instance = new self($args);
        }

        return self::$_Instance;
    }

    public function cache_handler()
    {
        $this->cache_active = $this->active();

        $this->cache_key = $this->generate_key($this->rewriter->request_path . serialize($this->rewriter->request_args));

        $this->maybe_render_cache();
    }

    private function active()
    {
        global $wp_query;

        $cache_this_page = true;

        if (!$wp_query->is_main_query() || $wp_query->is_admin) {
            $cache_this_page = false;
        }
        if ($wp_query->is_404) {
            $cache_this_page = false;
        }
        elseif (defined('DONOTCACHEPAGE') || defined("WPOPT_DISABLE_CACHE")) {
            $cache_this_page = false;
        }
        elseif ($_SERVER["REQUEST_METHOD"] === 'POST' || !empty($_POST)) {
            $cache_this_page = false;
        }
        elseif ($_SERVER["REQUEST_METHOD"] === 'PUT') {
            $cache_this_page = false;
        }
        elseif ($_SERVER["REQUEST_METHOD"] === 'DELETE') {
            $cache_this_page = false;
        }
        elseif (isset($_GET['preview']) || isset($_GET['s'])) {
            $cache_this_page = false;
        }

        return apply_filters('wpopt_caching_pages', $cache_this_page, $wp_query);
    }

    private function maybe_render_cache()
    {
        if ($data = parent::cache_get($this->cache_key)) {
            $this->clear = false;
            echo $data;
            die();
        }
    }

    public function cache_buffer($buffer)
    {
        if ($this->clear) {
            parent::cache_set($this->cache_key, $buffer);
        }

        return $buffer;
    }

    /**
     * Reset query info
     */
    protected function reset()
    {
        parent::reset();
    }

}