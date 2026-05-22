<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

use WPS\core\Rewriter;
use WPS\core\Settings;

class StaticCache extends Cache_Dispatcher
{
    // fix child invoking
    protected static ?Cache_Dispatcher $_Instance;

    private string $current_rule_id = '';
    private string $current_request_path = '';

    protected static function get_cache_group(): string
    {
        return "cache/static";
    }

    public static function get_static_cache_group(): string
    {
        return static::get_cache_group();
    }

    public static function flush($lifetime = false, $blog_id = 0): void
    {
        parent::flush($lifetime, $blog_id);

        if (!$lifetime) {
            StaticCacheRules::clear_all_entries();
        }
    }

    public function cache_handler(\WP_Query $wp_query)
    {
        if (!$wp_query->is_main_query()) {
            return;
        }

        $this->check_cacheability($wp_query);

        $this->current_request_path = Rewriter::getInstance()->get_request_path();
        $this->cache_key = $this->generate_key($this->current_request_path . $wp_query->query_vars_hash);

        $this->maybe_render_cache();
    }

    private function check_cacheability(\WP_Query $wp_query)
    {
        $this->is_cacheable = true;

        if ($rules = Settings::get_option($this->options, 'excluded', [])) {

            $rewriter = Rewriter::getInstance();

            foreach (array_filter($rules) as $rule) {
                if ($rewriter->match($rule)) {
                    $this->is_cacheable = false;
                    return;
                }
            }
        }

        $this->check_rule_cacheability();

        if (is_user_logged_in()) {
            $this->is_cacheable = false;
        }
        elseif ($wp_query->is_admin or is_login() or $wp_query->is_robots or $wp_query->is_feed or $wp_query->is_comment_feed or $wp_query->is_preview) {
            $this->is_cacheable = false;
        }
        elseif (wp_doing_ajax() or wp_doing_cron()) {
            $this->is_cacheable = false;
        }
        elseif (defined('DONOTCACHEPAGE') || (defined("WPOPT_DISABLE_CACHE") and WPOPT_DISABLE_CACHE)) {
            $this->is_cacheable = false;
        }
        elseif ($_SERVER["REQUEST_METHOD"] !== 'GET') {
            $this->is_cacheable = false;
        }
        elseif (isset($_GET['preview']) || isset($_GET['s'])) {
            $this->is_cacheable = false;
        }

        $this->is_cacheable = apply_filters('wpopt_allow_static_cache', $this->is_cacheable, $wp_query);
    }

    private function check_rule_cacheability(): void
    {
        $this->current_rule_id = '';

        $rules = StaticCacheRules::get_active_rules(Settings::get_option($this->options, 'rules', []));
        if (empty($rules)) {
            return;
        }

        $matched_rule = StaticCacheRules::match_rule($rules, Rewriter::getInstance()->get_request_path());
        if (empty($matched_rule)) {
            $this->is_cacheable = false;
            return;
        }

        $this->current_rule_id = $matched_rule['id'];
    }

    private function maybe_render_cache()
    {
        if (!$this->is_cacheable) {
            return;
        }

        if ($data = parent::cache_get($this->cache_key)) {
            $this->is_cached_content = true;
            StaticCacheRules::record_hit($this->current_rule_id);
            echo $data;
            exit();
        }

        StaticCacheRules::record_miss($this->current_rule_id);
    }

    public function cache_buffer($buffer)
    {
        global $wp_the_query;

        if (!$this->is_cached_content and $this->is_cacheable and !$wp_the_query->is_404) {

            parent::cache_set($this->cache_key, $buffer);
            StaticCacheRules::record_write($this->current_rule_id, $this->cache_key, $this->current_request_path, strlen($buffer));
        }

        return $buffer;
    }

    protected function launcher()
    {
        // reset cacheability to ensure if correctly set by parse_query action
        $this->is_cacheable = false;

        ob_start([$this, "cache_buffer"]);

        add_action("parse_query", [$this, "cache_handler"], 100, 1);
    }
}
