<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

use WPS\core\Disk;
use WPS\core\Rewriter;
use WPS\core\Settings;

require_once __DIR__ . '/staticcache_direct.class.php';

class StaticCache extends Cache_Dispatcher
{
    // fix child invoking
    protected static ?Cache_Dispatcher $_Instance;

    private const DEFAULT_USER_SCOPE = 'not_logged_in';
    private const USER_SCOPES = array('both', 'logged_in', 'not_logged_in');
    private const STATUS_CACHE_GROUPS = array('2xx', '3xx', '4xx', '5xx');
    private const DEFAULT_STATUS_CACHE_POLICY = array('2xx', '4xx', '5xx');
    private const QUERY_ARG_EXCLUSIONS = array(
        'preview',
        's',
        'wpopt_page_test',
        'wpopt_page_test_expires',
        'wpopt_page_test_run',
        'wpopt_page_test_signature',
    );

    private string $static_rule_id = '';
    private string $static_request_path = '';
    private string $bypass_reason = '';
    private bool $admin_request_context = false;
    private ?CacheRequestPolicy $request_policy = null;

    protected static function get_cache_group(): string
    {
        return "cache/static";
    }

    public static function get_static_cache_group(): string
    {
        return static::get_cache_group();
    }

    public static function get_storage_size(): int
    {
        $storage = wps('wpopt')->storage;
        $cache_group = static::get_cache_group();
        $storage_size = method_exists($storage, 'get_size_bytes')
            ? (int)$storage->get_size_bytes($cache_group)
            : (method_exists($storage, 'get_path') ? Disk::calc_size($storage->get_path($cache_group)) : 0);

        return $storage_size + StaticCacheDirectAccess::get_index_size();
    }

    public static function get_storage_file_count(): int
    {
        $storage = wps('wpopt')->storage;
        $cache_group = static::get_cache_group();
        $path = method_exists($storage, 'get_path') ? $storage->get_path($cache_group) : '';

        return Disk::count_files($path) + StaticCacheDirectAccess::get_index_file_count();
    }

    public static function flush($lifetime = false, $blog_id = 0): void
    {
        parent::flush($lifetime, $blog_id);

        if (!$lifetime) {
            StaticCacheRules::clear_all_entries();
            StaticCacheDirectAccess::clear_index();
        }
    }

    public function cache_handler(\WP_Query $wp_query)
    {
        if ($this->admin_request_context || is_admin()) {
            return;
        }

        if (!$wp_query->is_main_query()) {
            return;
        }

        $this->check_cacheability($wp_query);

        $this->static_request_path = Rewriter::getInstance()->get_request_path();
        $this->cache_key = $this->generate_key($this->static_request_path . $this->get_query_cache_fragment() . $wp_query->query_vars_hash . $this->get_user_cache_fragment());

        $this->maybe_render_cache();
    }

    public function admin_cache_handler(): void
    {
        $this->admin_request_context = true;
        $this->check_admin_cacheability();

        $this->static_request_path = $this->get_admin_request_path();
        $this->cache_key = $this->generate_key($this->static_request_path . $this->get_admin_query_cache_fragment() . $this->get_user_cache_fragment());

        $this->maybe_render_cache();
    }

    private function check_cacheability(\WP_Query $wp_query)
    {
        $this->is_cacheable = true;
        $this->bypass_reason = '';

        $automatic_bypass_reason = $this->request_policy()->automatic_bypass_reason();
        if ($automatic_bypass_reason !== '') {
            $this->mark_not_cacheable($automatic_bypass_reason);
            return;
        }

        $this->check_rule_cacheability();
        if (!$this->is_cacheable) {
            $this->is_cacheable = (bool)apply_filters('wpopt_allow_static_cache', false, $wp_query);
            return;
        }

        $this->check_common_cacheability();

        if ($this->is_cacheable && (($this->admin_requests_are_disabled() && $wp_query->is_admin) or is_login() or $wp_query->is_robots or $wp_query->is_feed or $wp_query->is_comment_feed or $wp_query->is_preview)) {
            $this->mark_not_cacheable('wp_context');
        }
        elseif ($this->is_cacheable && (wp_doing_ajax() or wp_doing_cron())) {
            $this->mark_not_cacheable('ajax_or_cron');
        }
        elseif ($this->is_cacheable && ($wp_query->is_search || isset($_GET['preview']) || isset($_GET['s']))) {
            $this->mark_not_cacheable('search_or_preview');
        }

        $this->is_cacheable = apply_filters('wpopt_allow_static_cache', $this->is_cacheable, $wp_query);
    }

    private function check_admin_cacheability(): void
    {
        $this->is_cacheable = true;
        $this->bypass_reason = '';

        $automatic_bypass_reason = $this->request_policy()->automatic_bypass_reason();
        if ($automatic_bypass_reason !== '') {
            $this->mark_not_cacheable($automatic_bypass_reason);
            return;
        }

        if ($this->admin_requests_are_disabled()) {
            $this->mark_not_cacheable('admin_disabled');
        }
        else {
            $this->check_common_cacheability();
        }

        if ($this->is_cacheable && (wp_doing_ajax() or wp_doing_cron() or is_login())) {
            $this->mark_not_cacheable('ajax_cron_or_login');
        }
        elseif ($this->is_cacheable && (isset($_GET['preview']) || isset($_GET['s']))) {
            $this->mark_not_cacheable('search_or_preview');
        }

        $this->is_cacheable = apply_filters('wpopt_allow_static_admin_cache', $this->is_cacheable, $this);
    }

    private function check_common_cacheability(): void
    {
        $user_scope = $this->get_user_scope();
        $is_logged_in = is_user_logged_in();

        if ($this->request_is_page_test_disabled()) {
            $this->mark_not_cacheable('page_test_disabled');
        }
        elseif ($this->request_policy()->has_no_cache_cookie(true)) {
            $this->mark_not_cacheable('cookies');
        }
        elseif ($this->request_policy()->user_agent_is_excluded()) {
            $this->mark_not_cacheable('user_agent');
        }
        elseif ($user_scope === 'logged_in' && !$is_logged_in) {
            $this->mark_not_cacheable('requires_logged_in');
        }
        elseif ($user_scope === 'not_logged_in' && $is_logged_in) {
            $this->mark_not_cacheable('requires_logged_out');
        }
        elseif (defined('DONOTCACHEPAGE') || (defined("WPOPT_DISABLE_CACHE") and WPOPT_DISABLE_CACHE)) {
            $this->mark_not_cacheable('constant');
        }
        elseif (($_SERVER["REQUEST_METHOD"] ?? 'GET') !== 'GET') {
            $this->mark_not_cacheable('method');
        }
    }

    private function mark_not_cacheable(string $reason): void
    {
        $this->is_cacheable = false;
        $this->bypass_reason = $reason;
    }

    private function get_user_cache_fragment(): string
    {
        if (!is_user_logged_in()) {
            return '';
        }

        return '|user:' . get_current_user_id();
    }

    private function admin_requests_are_disabled(): bool
    {
        return (bool)Settings::get_option($this->options, 'disable_admin_cache', true);
    }

    private function get_user_scope(): string
    {
        $scope = isset($this->options['user_scope']) && is_scalar($this->options['user_scope'])
            ? sanitize_key((string)$this->options['user_scope'])
            : '';
        if (in_array($scope, self::USER_SCOPES, true)) {
            return $scope;
        }

        return self::DEFAULT_USER_SCOPE;
    }

    private function request_is_page_test_disabled(): bool
    {
        return isset($_GET['wpopt_page_test'])
            && sanitize_key((string)wp_unslash($_GET['wpopt_page_test'])) === 'disabled';
    }

    private function request_policy(): CacheRequestPolicy
    {
        if (!$this->request_policy) {
            $this->request_policy = new CacheRequestPolicy($this->options);
        }

        return $this->request_policy;
    }

    private function get_query_cache_fragment(): string
    {
        if (empty($this->options['cache_query_args'])) {
            return '';
        }

        return '|' . CacheRequestPolicy::normalized_query_string(self::QUERY_ARG_EXCLUSIONS);
    }

    private function get_admin_query_cache_fragment(): string
    {
        $query_string = CacheRequestPolicy::normalized_query_string(self::QUERY_ARG_EXCLUSIONS);

        return $query_string === '' ? '' : '|admin-query:' . $query_string;
    }

    private function get_admin_request_path(): string
    {
        return CacheRequestPolicy::normalize_request_path() ?: 'wp-admin';
    }

    private function check_rule_cacheability(): void
    {
        $this->static_rule_id = '';

        $result = $this->request_policy()->rule_cacheability(Rewriter::getInstance()->get_request_path());
        $this->static_rule_id = (string)$result['rule_id'];

        if (!$result['cacheable']) {
            $this->mark_not_cacheable((string)$result['reason']);
        }
    }

    private function maybe_render_cache()
    {
        if (!$this->is_cacheable) {
            return;
        }

        if ($data = parent::cache_get($this->cache_key)) {
            $this->is_cached_content = true;
            StaticCacheRules::record_hit($this->static_rule_id);
            $this->record_static_direct_index();
            $this->send_debug_header('HIT');
            echo $this->prepare_cached_output($data);
            exit();
        }

        StaticCacheRules::record_miss($this->static_rule_id);
    }

    public function cache_buffer($buffer)
    {
        if (!$this->is_cached_content && !$this->is_cacheable) {
            $this->send_debug_header('BYPASS', $this->bypass_reason ?: 'not_cacheable');
            return $buffer;
        }

        if (!$this->is_cached_content and $this->is_cacheable and $this->response_status_is_cacheable()) {
            $payload = $this->create_cache_payload($buffer);

            if ($payload['body'] !== '' || $this->payload_has_location_header($payload)) {
                parent::cache_set($this->cache_key, $payload);
                $this->send_debug_header('STORE');
                $this->record_static_direct_index();
                StaticCacheRules::record_write($this->static_rule_id, $this->cache_key, $this->static_request_path, strlen($buffer));
            }
            else {
                $this->send_debug_header('BYPASS', 'empty_body');
            }
        }
        elseif (!$this->is_cached_content && $this->is_cacheable) {
            $this->send_debug_header('BYPASS', 'status_policy');
        }

        return $buffer;
    }

    private function record_static_direct_index(): void
    {
        StaticCacheDirectAccess::record(
            $this->static_request_path,
            $this->get_static_direct_query_string(),
            $this->cache_key,
            static::get_cache_group(),
            $this->options
        );
    }

    private function get_static_direct_query_string(): string
    {
        if (empty($this->options['cache_query_args'])) {
            return '';
        }

        return CacheRequestPolicy::normalized_query_string(self::QUERY_ARG_EXCLUSIONS);
    }

    private function send_debug_header(string $status, string $reason = ''): void
    {
        if (headers_sent()) {
            return;
        }

        $value = $reason === '' ? $status : "{$status}; reason={$reason}";
        header("X-WPOpt-Static-Cache: {$value}", true);
    }

    private function prepare_cached_output($data): string
    {
        if (!is_array($data)) {
            return (string)$data;
        }

        $status = isset($data['status']) ? absint($data['status']) : 200;
        if ($status >= 100 && $status <= 599 && !headers_sent()) {
            status_header($status);
        }

        if (!headers_sent() && !empty($data['headers']) && is_array($data['headers'])) {
            foreach ($data['headers'] as $header) {
                if (is_string($header) && $this->header_is_cacheable($header)) {
                    header($header, true);
                }
            }
        }

        return (string)($data['body'] ?? '');
    }

    private function create_cache_payload(string $buffer): array
    {
        return array(
            'body' => $buffer,
            'status' => $this->get_current_response_status(),
            'headers' => $this->get_cacheable_response_headers(),
        );
    }

    private function get_cacheable_response_headers(): array
    {
        if (!function_exists('headers_list')) {
            return array();
        }

        return array_values(array_filter(headers_list(), function ($header): bool {
            return is_string($header) && $this->header_is_cacheable($header);
        }));
    }

    private function header_is_cacheable(string $header): bool
    {
        $header_name = strtolower(trim(strtok($header, ':') ?: ''));
        $allowed_headers = array(
            'content-type',
            'location',
            'x-robots-tag',
        );

        return in_array($header_name, $allowed_headers, true);
    }

    private function payload_has_location_header(array $payload): bool
    {
        foreach ((array)($payload['headers'] ?? array()) as $header) {
            if (is_string($header) && strtolower(trim(strtok($header, ':') ?: '')) === 'location') {
                return true;
            }
        }

        return false;
    }

    private function response_status_is_cacheable(): bool
    {
        $policy = $this->get_status_cache_policy();
        if (empty($policy)) {
            return false;
        }

        return in_array($this->get_current_response_status_group(), $policy, true);
    }

    private function get_current_response_status_group(): string
    {
        $status = $this->get_current_response_status();

        return (int)floor($status / 100) . 'xx';
    }

    private function get_current_response_status(): int
    {
        $status = function_exists('http_response_code') ? (int)http_response_code() : 200;

        if ($status < 100 || $status > 599) {
            return 200;
        }

        return $status;
    }

    private function get_status_cache_policy(): array
    {
        $policy = Settings::get_option($this->options, 'status_cache_policy', $this->default_status_cache_policy());
        if (!is_array($policy)) {
            return $this->default_status_cache_policy();
        }

        return array_values(array_intersect(self::STATUS_CACHE_GROUPS, array_map('strval', $policy)));
    }

    private function default_status_cache_policy(): array
    {
        return self::DEFAULT_STATUS_CACHE_POLICY;
    }

    protected function launcher()
    {
        // reset cacheability to ensure if correctly set by parse_query action
        $this->is_cacheable = false;

        ob_start([$this, "cache_buffer"]);

        if (is_admin() && !wp_doing_ajax()) {
            $this->admin_cache_handler();
        }

        add_action("parse_query", [$this, "cache_handler"], 100, 1);
    }
}
