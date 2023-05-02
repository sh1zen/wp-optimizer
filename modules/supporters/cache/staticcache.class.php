<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

use SHZN\core\Rewriter;

class StaticCache extends Cache_Dispatcher
{
    protected static $_Instance;

    private Rewriter $rewriter;

    private $is_cached_content = false;

    protected function __construct($args)
    {
        if (!parent::__construct($args)) {
            return false;
        }

        $this->rewriter = Rewriter::getInstance();

        ob_start([$this, "cache_buffer"]);

        add_action("parse_query", [$this, "cache_handler"], 100, 1);

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

    public function cache_handler(\WP_Query $wp_query)
    {
        if (!$wp_query->is_main_query() or is_user_logged_in()) {
            return;
        }

        $this->cache_active = $this->is_cacheable($wp_query);

        $this->cache_key = $this->generate_key($this->rewriter->request_path . $wp_query->query_vars_hash);

        $this->maybe_render_cache();
    }

    private function is_cacheable(\WP_Query $wp_query)
    {
        $cache_this_page = true;

        if ($wp_query->is_admin or is_login() or $wp_query->is_robots or $wp_query->is_feed or $wp_query->is_comment_feed or $wp_query->is_preview) {
            $cache_this_page = false;
        }
        elseif (wp_doing_ajax() or wp_doing_cron()) {
            $cache_this_page = false;
        }
        elseif (defined('DONOTCACHEPAGE') || (defined("WPOPT_DISABLE_CACHE") and WPOPT_DISABLE_CACHE)) {
            $cache_this_page = false;
        }
        elseif ($_SERVER["REQUEST_METHOD"] !== 'GET') {
            $cache_this_page = false;
        }
        elseif (isset($_GET['preview']) || isset($_GET['s'])) {
            $cache_this_page = false;
        }

        return apply_filters('wpopt_allow_static_cache', $cache_this_page, $wp_query);
    }

    private function maybe_render_cache()
    {
        if (!$this->cache_active) {
            return;
        }

        if ($data = parent::cache_get($this->cache_key)) {
            $this->is_cached_content = true;
            echo $data;
            exit();
        }
    }

    public function cache_buffer($buffer)
    {
        global $wp_the_query;

        if (!$this->is_cached_content and $this->cache_active and !$wp_the_query->is_404) {
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