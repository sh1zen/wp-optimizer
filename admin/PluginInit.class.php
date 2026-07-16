<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\core;

use WPS\core\Rewriter;
use WPS\core\StringHelper;
use WPS\core\UtilEnv;

/**
 * Main class, used to set up the plugin
 */
class PluginInit
{
    private const WELCOME_SEEN_OPTION = 'wpopt_welcome_seen';
    private const DEACTIVATION_FEEDBACK_TRANSIENT = 'wpopt_deactivation_feedback_';

    private static ?PluginInit $_instance;

    /**
     * Holds the plugin base name
     */
    private string $plugin_basename;

    public ?PagesHandler $pages_handler = null;

    private function __construct()
    {
        $this->plugin_basename = UtilEnv::plugin_basename(WPOPT_FILE);

        if (is_admin()) {
            $this->register_actions();

            if (self::should_do_welcome()) {
                $this->do_welcome();
            }
        }

        if (did_action('init')) {
            $this->load_textdomain();
        }
        else {
            add_action('init', array($this, 'load_textdomain'), 0);
        }

        wps_maybe_upgrade('wpopt', WPOPT_VERSION, WPOPT_ADMIN . "upgrades/");
    }

    public static function should_do_welcome(): bool
    {
        return false;
    }

    private function register_actions(): void
    {
        // Plugin Activation/Deactivation.
        register_activation_hook(WPOPT_FILE, array($this, 'plugin_activation'));
        register_deactivation_hook(WPOPT_FILE, array($this, 'plugin_deactivation'));

        add_action('wp_ajax_wpopt_page_test_prepare', array($this, 'ajax_prepare_page_test'));
        add_action('wp_ajax_wpopt_page_test_diagnostics', array($this, 'ajax_page_test_diagnostics'));
        add_action('wp_ajax_wpopt_submit_deactivation_feedback', array($this, 'ajax_submit_deactivation_feedback'));
        add_action('activated_plugin', array($this, 'refresh_after_woocommerce_activation'), 10, 1);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_deactivation_feedback_assets'), 30, 1);
        add_action('admin_footer-plugins.php', array($this, 'render_deactivation_feedback_dialog'));
        add_action('admin_footer-plugins-network.php', array($this, 'render_deactivation_feedback_dialog'));

        foreach (array('woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id') as $option) {
            add_action("update_option_{$option}", array($this, 'refresh_compatibility_runtime'), 10, 0);
        }

        add_filter("plugin_action_links_$this->plugin_basename", array($this, 'extra_plugin_link'), 10, 2);
        add_filter('plugin_row_meta', array($this, 'donate_link'), 10, 4);
    }

    public function refresh_after_woocommerce_activation(string $plugin): void
    {
        if (strtolower(wp_normalize_path($plugin)) !== 'woocommerce/woocommerce.php') {
            return;
        }

        $this->refresh_compatibility_runtime();
    }

    public function refresh_compatibility_runtime(): void
    {
        require_once WPOPT_SUPPORTERS . 'cache/staticcache_direct.class.php';

        \WPOptimizer\modules\supporters\StaticCacheDirectAccess::refresh_installed_runtime();
    }

    /**
     * Loads text domain for the plugin.
     */
    public function load_textdomain(): void
    {
        $locale = apply_filters('wpopt_plugin_locale', get_locale(), 'wpopt');

        $mo_file = "wpopt-$locale.mo";

        if (load_textdomain('wpopt', WP_LANG_DIR . '/plugins/wp-optimizer/' . $mo_file)) {
            return;
        }

        load_textdomain('wpopt', UtilEnv::normalize_path(WPOPT_ABSPATH . 'languages/', true) . $mo_file);
    }

    public static function getInstance(): PluginInit
    {
        if (!isset(self::$_instance)) {
            return self::Initialize();
        }

        return self::$_instance;
    }

    public static function Initialize(): PluginInit
    {
        if (isset(self::$_instance)) {
            return self::$_instance;
        }

        self::$_instance = new static();
        self::$_instance->setup_modules_for_current_request();

        return self::$_instance;
    }

    private function setup_modules_for_current_request(): void
    {
        $module_handler = wps('wpopt')->moduleHandler;
        $page_test_mode = self::get_page_test_request_mode();

        if ($page_test_mode !== '') {
            self::register_page_test_runtime_headers($page_test_mode);

            if ($page_test_mode === 'warmup') {
                self::register_page_test_warmup_diagnostics(self::get_page_test_run_id());
            }

            if ($page_test_mode === 'disabled') {
                add_action('send_headers', static function (): void {
                    header('X-WP-Optimizer-Test: disabled-modules');
                }, 0);

                return;
            }
        }

        /**
         * Keep Ajax requests fast:
         * if doing ajax : load only ajax handler and return
         */
        if (wp_doing_ajax()) {

            /**
             * Instancing all modules that need to interact in the Ajax process
             */
            $module_handler->setup_modules('ajax');
        }
        elseif (wp_doing_cron()) {

            /**
             * Instancing all modules that need to interact in the cron process
             */
            $module_handler->setup_modules('cron');
        }
        elseif (is_admin()) {

            require_once WPOPT_ADMIN . 'PagesHandler.class.php';

            /**
             * Load the admin pages handler and store it here
             */
            self::$_instance->pages_handler = new PagesHandler();

            /**
             * Instancing all modules that need to interact in admin area
             */
            $module_handler->setup_modules('admin');
        }
        else {

            /**
             * Instancing all modules that need to interact only on the web-view
             */
            $module_handler->setup_modules('web-view');
        }

        /**
         * Instancing all modules that need to be always loaded
         */
        $module_handler->setup_modules('autoload');
    }

    public function ajax_prepare_page_test(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('You are not allowed to run this test.', 'wpopt'),
            ), 403);
        }

        check_ajax_referer('wpopt-page-test', 'nonce');

        $raw_url = isset($_POST['url']) ? (string)wp_unslash($_POST['url']) : '';
        $url = $this->normalize_page_test_url($raw_url);

        if (!$url) {
            wp_send_json_error(array(
                'message' => __('Enter a valid URL from this WordPress site.', 'wpopt'),
            ), 400);
        }

        $expires = time() + 5 * MINUTE_IN_SECONDS;
        $run_id = wp_generate_uuid4();

        wp_send_json_success(array(
            'url'          => $url,
            'disabled_url' => self::build_page_test_url($url, 'disabled', $expires, $run_id),
            'warmup_url'   => self::build_page_test_url($url, 'warmup', $expires, $run_id),
            'active_url'   => self::build_page_test_url($url, 'active', $expires, $run_id),
            'run_id'       => $run_id,
            'expires'      => $expires,
        ));
    }

    public function ajax_page_test_diagnostics(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('You are not allowed to read this test report.', 'wpopt'),
            ), 403);
        }

        check_ajax_referer('wpopt-page-test', 'nonce');

        $run_id = isset($_POST['run_id']) ? sanitize_text_field(wp_unslash($_POST['run_id'])) : '';

        if (!self::is_valid_page_test_run_id($run_id)) {
            wp_send_json_error(array(
                'message' => __('Invalid page test report id.', 'wpopt'),
            ), 400);
        }

        $diagnostics = get_transient(self::page_test_diagnostics_key($run_id));

        if (!is_array($diagnostics)) {
            wp_send_json_success(array(
                'ready' => false,
            ));
        }

        wp_send_json_success(array(
            'ready'       => true,
            'diagnostics' => $diagnostics,
        ));
    }

    private static function build_page_test_url(string $url, string $mode, int $expires, string $run_id): string
    {
        $test_url = add_query_arg(array(
            'wpopt_page_test'         => $mode,
            'wpopt_page_test_expires' => $expires,
            'wpopt_page_test_run'     => $run_id,
        ), $url);
        $signature = self::page_test_signature(self::page_test_signature_subject($test_url), $expires);

        return esc_url_raw(add_query_arg('wpopt_page_test_signature', $signature, $test_url));
    }

    private function normalize_page_test_url(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if (strpos($url, '//') === 0) {
            $url = (is_ssl() ? 'https:' : 'http:') . $url;
        }
        elseif (!preg_match('#^https?://#i', $url)) {
            $url = home_url('/' . ltrim($url, '/'));
        }

        $parts = wp_parse_url($url);

        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));

        if (!in_array($scheme, array('http', 'https'), true)) {
            return '';
        }

        $allowed_hosts = array_filter(array_map('strtolower', array(
            (string)wp_parse_url(home_url(), PHP_URL_HOST),
            (string)wp_parse_url(site_url(), PHP_URL_HOST),
            isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', strtolower((string)wp_unslash($_SERVER['HTTP_HOST']))) : '',
        )));
        $allowed_hosts = array_values(array_unique($allowed_hosts));

        if (!in_array(strtolower((string)$parts['host']), $allowed_hosts, true)) {
            return '';
        }

        $port = isset($parts['port']) ? ':' . absint($parts['port']) : '';
        $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        return esc_url_raw($scheme . '://' . $parts['host'] . $port . $path . $query);
    }

    private static function get_page_test_request_mode(): string
    {
        $mode = isset($_GET['wpopt_page_test']) ? sanitize_key((string)wp_unslash($_GET['wpopt_page_test'])) : '';

        if (!in_array($mode, array('disabled', 'warmup', 'active'), true)) {
            return '';
        }

        $expires = isset($_GET['wpopt_page_test_expires']) ? absint($_GET['wpopt_page_test_expires']) : 0;
        $signature = isset($_GET['wpopt_page_test_signature']) ? sanitize_text_field(wp_unslash($_GET['wpopt_page_test_signature'])) : '';

        if ($expires < time() || $signature === '') {
            return '';
        }

        $subject = self::page_test_signature_subject(self::current_page_test_url());
        $expected = self::page_test_signature($subject, $expires);

        return hash_equals($expected, $signature) ? $mode : '';
    }

    private static function get_page_test_run_id(): string
    {
        $run_id = isset($_GET['wpopt_page_test_run']) ? sanitize_text_field(wp_unslash($_GET['wpopt_page_test_run'])) : '';

        return self::is_valid_page_test_run_id($run_id) ? $run_id : '';
    }

    private static function is_valid_page_test_run_id(string $run_id): bool
    {
        return (bool)preg_match('/^[a-f0-9-]{32,40}$/i', $run_id);
    }

    private static function page_test_diagnostics_key(string $run_id): string
    {
        return 'wpopt_page_test_diag_' . md5($run_id);
    }

    private static function register_page_test_runtime_headers(string $mode): void
    {
        if (!function_exists('header_register_callback')) {
            return;
        }

        header_register_callback(static function () use ($mode): void {
            if (headers_sent()) {
                return;
            }

            header('X-WP-Optimizer-Test-Mode: ' . $mode);
            header('X-WP-Optimizer-Memory-Usage: ' . memory_get_usage(true));
            header('X-WP-Optimizer-Memory-Peak: ' . memory_get_peak_usage(true));
        });
    }

    private static function register_page_test_warmup_diagnostics(string $run_id): void
    {
        if ($run_id === '') {
            return;
        }

        global $wpdb;

        if (isset($wpdb) && is_object($wpdb) && property_exists($wpdb, 'save_queries')) {
            $wpdb->save_queries = true;
        }

        $hook_samples = array();
        $hook_starts = array();
        $hooks = array(
            'plugins_loaded',
            'setup_theme',
            'after_setup_theme',
            'init',
            'wp_loaded',
            'parse_request',
            'send_headers',
            'parse_query',
            'pre_get_posts',
            'wp',
            'template_redirect',
            'wp_head',
            'loop_start',
            'the_post',
            'loop_end',
            'wp_footer',
        );

        foreach ($hooks as $hook_name) {
            add_action($hook_name, static function () use (&$hook_starts, $hook_name): void {
                $hook_starts[$hook_name] = array(
                    'time'   => microtime(true),
                    'memory' => memory_get_usage(true),
                );
            }, -999999, 0);

            add_action($hook_name, static function () use (&$hook_starts, &$hook_samples, $hook_name): void {
                if (empty($hook_starts[$hook_name])) {
                    return;
                }

                $started = $hook_starts[$hook_name];
                $duration_ms = max(0, round((microtime(true) - (float)$started['time']) * 1000, 3));
                $memory_delta = memory_get_usage(true) - (int)$started['memory'];

                $hook_samples[] = array(
                    'hook'           => $hook_name,
                    'duration_ms'    => $duration_ms,
                    'memory_delta'   => $memory_delta,
                    'callback_count' => self::count_hook_callbacks($hook_name),
                    'callbacks'      => self::sample_hook_callbacks($hook_name, 5),
                );
            }, 999999, 0);
        }

        add_action('shutdown', static function () use ($run_id, &$hook_samples): void {
            self::store_page_test_diagnostics($run_id, $hook_samples);
        }, PHP_INT_MAX, 0);
    }

    private static function store_page_test_diagnostics(string $run_id, array $hook_samples): void
    {
        global $wpdb;

        $queries = isset($wpdb->queries) && is_array($wpdb->queries) ? $wpdb->queries : array();
        $slow_queries = self::extract_page_test_slow_queries($queries);
        $duplicate_queries = self::extract_page_test_duplicate_queries($queries);
        $hook_samples = self::normalize_page_test_hook_samples($hook_samples);

        set_transient(self::page_test_diagnostics_key($run_id), array(
            'created_at'        => current_time('mysql'),
            'total_queries'     => count($queries),
            'slow_queries'      => $slow_queries,
            'duplicate_queries' => $duplicate_queries,
            'hooks'             => $hook_samples,
            'suggestions'       => self::build_page_test_suggestions($slow_queries, $duplicate_queries, $hook_samples, count($queries)),
            'runtime'           => array(
                'memory_peak' => memory_get_peak_usage(true),
                'memory_used' => memory_get_usage(true),
            ),
        ), 10 * MINUTE_IN_SECONDS);
    }

    private static function extract_page_test_slow_queries(array $queries): array
    {
        $rows = array();

        foreach ($queries as $query) {
            if (!is_array($query) || !isset($query[0], $query[1])) {
                continue;
            }

            $rows[] = array(
                'sql'       => self::trim_page_test_text((string)$query[0], 260),
                'time_ms'   => round((float)$query[1] * 1000, 3),
                'caller'    => self::trim_page_test_text((string)($query[2] ?? ''), 180),
            );
        }

        usort($rows, static function (array $a, array $b): int {
            return $b['time_ms'] <=> $a['time_ms'];
        });

        return array_slice($rows, 0, 8);
    }

    private static function extract_page_test_duplicate_queries(array $queries): array
    {
        $groups = array();

        foreach ($queries as $query) {
            if (!is_array($query) || !isset($query[0], $query[1])) {
                continue;
            }

            $signature = self::normalize_page_test_query_signature((string)$query[0]);

            if (!isset($groups[$signature])) {
                $groups[$signature] = array(
                    'query'    => self::trim_page_test_text((string)$query[0], 220),
                    'count'    => 0,
                    'time_ms'  => 0,
                );
            }

            $groups[$signature]['count']++;
            $groups[$signature]['time_ms'] += (float)$query[1] * 1000;
        }

        $groups = array_filter($groups, static function (array $group): bool {
            return $group['count'] > 1;
        });

        usort($groups, static function (array $a, array $b): int {
            if ($a['count'] === $b['count']) {
                return $b['time_ms'] <=> $a['time_ms'];
            }

            return $b['count'] <=> $a['count'];
        });

        foreach ($groups as &$group) {
            $group['time_ms'] = round((float)$group['time_ms'], 3);
        }
        unset($group);

        return array_slice(array_values($groups), 0, 6);
    }

    private static function normalize_page_test_hook_samples(array $hook_samples): array
    {
        usort($hook_samples, static function (array $a, array $b): int {
            return $b['duration_ms'] <=> $a['duration_ms'];
        });

        return array_slice($hook_samples, 0, 10);
    }

    private static function build_page_test_suggestions(array $slow_queries, array $duplicate_queries, array $hooks, int $total_queries): array
    {
        $suggestions = array();

        if (!empty($slow_queries)) {
            $suggestions[] = array(
                'type'  => 'query',
                'title' => __('Review slow database queries', 'wpopt'),
                'text'  => sprintf(__('The warmup request recorded %s slow query samples. Check the callers and add indexes, caching, or reduce repeated lookups where possible.', 'wpopt'), number_format_i18n(count($slow_queries))),
            );
        }

        if (!empty($duplicate_queries)) {
            $suggestions[] = array(
                'type'  => 'duplicates',
                'title' => __('Reduce repeated queries', 'wpopt'),
                'text'  => __('Repeated SQL signatures were detected during warmup. Object/query caching or moving repeated lookups outside loops can reduce this cost.', 'wpopt'),
            );
        }

        if ($total_queries > 100) {
            $suggestions[] = array(
                'type'  => 'queries',
                'title' => __('High query count', 'wpopt'),
                'text'  => sprintf(__('The warmup request executed %s queries. Review autoloaded options, widgets, page builders and template loops.', 'wpopt'), number_format_i18n($total_queries)),
            );
        }

        if (!empty($hooks)) {
            $suggestions[] = array(
                'type'  => 'callbacks',
                'title' => __('Inspect heavy hooks and callbacks', 'wpopt'),
                'text'  => __('The hooks below consumed the most warmup time. Disable unused modules/plugins or move expensive callbacks behind stricter conditions.', 'wpopt'),
            );
        }

        return array_slice($suggestions, 0, 6);
    }

    private static function normalize_page_test_query_signature(string $query): string
    {
        $query = preg_replace("/'[^']*'/", '?', $query);
        $query = preg_replace('/"[^"]*"/', '?', (string)$query);
        $query = preg_replace('/\b\d+\b/', '?', (string)$query);
        $query = preg_replace('/\s+/', ' ', (string)$query);

        return strtolower(trim((string)$query));
    }

    private static function trim_page_test_text(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));

        if (strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, max(0, $limit - 3)) . '...';
    }

    private static function count_hook_callbacks(string $hook_name): int
    {
        global $wp_filter;

        if (empty($wp_filter[$hook_name]) || !is_object($wp_filter[$hook_name]) || !property_exists($wp_filter[$hook_name], 'callbacks')) {
            return 0;
        }

        $count = 0;

        foreach ((array)$wp_filter[$hook_name]->callbacks as $priority => $callbacks) {
            if (in_array((int)$priority, array(-999999, 999999), true)) {
                continue;
            }

            $count += is_array($callbacks) ? count($callbacks) : 0;
        }

        return $count;
    }

    private static function sample_hook_callbacks(string $hook_name, int $limit): array
    {
        global $wp_filter;

        if (empty($wp_filter[$hook_name]) || !is_object($wp_filter[$hook_name]) || !property_exists($wp_filter[$hook_name], 'callbacks')) {
            return array();
        }

        $samples = array();

        foreach ((array)$wp_filter[$hook_name]->callbacks as $priority => $callbacks) {
            if (in_array((int)$priority, array(-999999, 999999), true)) {
                continue;
            }

            foreach ((array)$callbacks as $callback) {
                $samples[] = 'p' . (string)$priority . ': ' . self::page_test_callback_label($callback['function'] ?? null);

                if (count($samples) >= $limit) {
                    return $samples;
                }
            }
        }

        return $samples;
    }

    private static function page_test_callback_label($callback): string
    {
        if (is_string($callback)) {
            return $callback;
        }

        if (is_array($callback)) {
            $owner = $callback[0] ?? '';
            $method = (string)($callback[1] ?? '');

            if (is_object($owner)) {
                return get_class($owner) . '::' . $method;
            }

            return (string)$owner . '::' . $method;
        }

        if ($callback instanceof \Closure) {
            return 'Closure';
        }

        return 'Callback';
    }

    private static function current_page_test_url(): string
    {
        $host = isset($_SERVER['HTTP_HOST']) ? (string)wp_unslash($_SERVER['HTTP_HOST']) : (string)wp_parse_url(home_url(), PHP_URL_HOST);
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string)wp_unslash($_SERVER['REQUEST_URI']) : '/';

        return (is_ssl() ? 'https://' : 'http://') . $host . $request_uri;
    }

    private static function page_test_signature_subject(string $url): string
    {
        $unsigned_url = remove_query_arg('wpopt_page_test_signature', $url);
        $parts = wp_parse_url($unsigned_url);

        if (!is_array($parts)) {
            return '/';
        }

        $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
        $query_args = array();

        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $query_args);
            ksort($query_args);
        }

        return $path . (empty($query_args) ? '' : '?' . http_build_query($query_args, '', '&', PHP_QUERY_RFC3986));
    }

    private static function page_test_signature(string $subject, int $expires): string
    {
        return hash_hmac('sha256', $expires . '|' . $subject, wp_salt('auth'));
    }

    /**
     * What to do when the plugin on plugin activation
     *
     * @param boolean $network_wide Is network wide.
     * @return void
     */
    public function plugin_activation($network_wide)
    {
        $had_module_settings = $this->has_module_settings();

        if (!get_option('wpopt_activated_at', false)) {
            add_option('wpopt_activated_at', time(), '', 'no');
        }

        wps('wpopt')->settings->activate();

        $this->set_initial_module_defaults($had_module_settings);
        wps('wpopt')->moduleHandler->activate_modules();

        wpopt_cleanup_media_cron_hooks();

        wps('wpopt')->cron->activate();

        /**
         * Hook for the plugin activation
         */
        do_action('wpopt-activate');

        if (!get_option(self::WELCOME_SEEN_OPTION, false)) {
            wps('wpopt')->settings->update('do_welcome', time(), true);
        }
    }

    private function has_module_settings(): bool
    {
        $settings = wps('wpopt')->settings->get('', array());

        return !empty($settings['modules_handler']) && is_array($settings['modules_handler']);
    }

    private function set_initial_module_defaults(bool $had_module_settings): void
    {
        if ($had_module_settings) {
            return;
        }

        $inactive_by_default = array(
            'activitylog',
            'performance_monitor',
            'wp_mail',
            'wp_updates',
            'wp_info',
        );

        $module_defaults = array();
        $modules = wps('wpopt')->moduleHandler->get_modules(array('excepts' => array('cloudflare', 'modules_handler', 'settings', 'tracking')), false);

        foreach ($modules as $module) {
            $slug = $module['slug'];
            $module_defaults[$slug] = !in_array($slug, $inactive_by_default, true);
        }

        $settings = wps('wpopt')->settings->get('', array());
        $settings['modules_handler'] = $module_defaults;

        wps('wpopt')->settings->reset($settings);
    }

    /**
     * What to do when the plugin on plugin deactivation
     */
    public function plugin_deactivation($network_wide): void
    {
        global $wp_version;

        $tracking_enabled = (bool)wps('wpopt')->settings->get('tracking.usage', true);
        $feedback = get_transient(self::DEACTIVATION_FEEDBACK_TRANSIENT . get_current_user_id());
        $feedback_lines = array();

        if (is_array($feedback) && !empty($feedback['reason'])) {
            $feedback_lines[] = 'Deactivation reason: ' . $feedback['reason'];

            if (!empty($feedback['details'])) {
                $feedback_lines[] = 'Deactivation details: ' . $feedback['details'];
            }
        }

        if ($tracking_enabled || $feedback_lines) {
            $mail_lines = array_merge(array("Details:"), $feedback_lines);

            if ($tracking_enabled) {
                $mail_lines[] = "Settings: " . maybe_serialize(wps('wpopt')->settings->get());
                $mail_lines[] = "Conf: PHP:" . PHP_VERSION . ", WP:$wp_version";
            }

            $mail_lines[] = "\nAutomatically sent message by wps framework.";
            $mail_content = StringHelper::stringBuilder(...$mail_lines);

            if (wps_core()->online) {
                wp_mail('dev.sh1zen@outlook.it', 'WPOPT uninstall report ' . wps_domain(), $mail_content);
            }
        }

        delete_transient(self::DEACTIVATION_FEEDBACK_TRANSIENT . get_current_user_id());

        wpopt_cleanup_media_cron_hooks();

        wps('wpopt')->moduleHandler->cleanup_modules(null, false);

        wps('wpopt')->cron->deactivate();

        /**
         * Hook for the plugin deactivation
         */
        do_action('wpopt-deactivate');
    }

    public function enqueue_deactivation_feedback_assets(string $hook_suffix): void
    {
        if (!in_array($hook_suffix, array('plugins.php', 'plugins-network.php'), true) || !current_user_can('activate_plugins')) {
            return;
        }

        wp_enqueue_style('wpopt_css');

        $asset = UtilEnv::resolve_asset(WPOPT_ABSPATH, 'assets/deactivation-feedback.js', wps_core()->online);
        $version = $asset['version'] ?: (file_exists(WPOPT_ABSPATH . 'assets/deactivation-feedback.js') ? filemtime(WPOPT_ABSPATH . 'assets/deactivation-feedback.js') : WPOPT_VERSION);

        wp_enqueue_script('wpopt_deactivation_feedback', $asset['url'], array(), $version, true);
        wp_localize_script('wpopt_deactivation_feedback', 'wpoptDeactivationFeedback', array(
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('wpopt-deactivation-feedback'),
            'pluginFile' => $this->plugin_basename,
        ));
    }

    public function ajax_submit_deactivation_feedback(): void
    {
        if (!current_user_can('activate_plugins')) {
            wp_send_json_error(array('message' => __('You are not allowed to deactivate plugins.', 'wpopt')), 403);
        }

        check_ajax_referer('wpopt-deactivation-feedback', 'nonce');

        $reasons = $this->get_deactivation_feedback_reasons();
        $reason_key = isset($_POST['reason']) ? sanitize_key(wp_unslash($_POST['reason'])) : '';

        if (!isset($reasons[$reason_key])) {
            wp_send_json_error(array('message' => __('Select a reason before continuing.', 'wpopt')), 400);
        }

        $details = '';

        if ($reason_key === 'other' && isset($_POST['details'])) {
            $details = wp_html_excerpt(sanitize_textarea_field(wp_unslash($_POST['details'])), 1000, '');
        }

        set_transient(
            self::DEACTIVATION_FEEDBACK_TRANSIENT . get_current_user_id(),
            array(
                'reason'  => $reasons[$reason_key],
                'details' => $details,
            ),
            10 * MINUTE_IN_SECONDS
        );

        wp_send_json_success();
    }

    public function render_deactivation_feedback_dialog(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        $reasons = $this->get_deactivation_feedback_reasons();
        ?>
        <div class="wpopt-advertise-overlay wpopt-deactivation-feedback" data-wpopt-deactivation-dialog hidden role="dialog" aria-modal="true" aria-labelledby="wpopt-deactivation-title">
            <div class="wpopt-advertise-page is-welcome is-deactivation">
                <button type="button" class="wpopt-advertise-close" data-wpopt-deactivation-close aria-label="<?php esc_attr_e('Close', 'wpopt'); ?>">&times;</button>
                <section class="wpopt-advertise-hero">
                    <span class="wpopt-advertise-eyebrow"><?php esc_html_e('Before you go', 'wpopt'); ?></span>
                    <h1 id="wpopt-deactivation-title"><?php esc_html_e('Help us improve WP Optimizer', 'wpopt'); ?></h1>
                    <p><?php esc_html_e('Your answer helps us improve the plugin. Choose the option that best describes your experience.', 'wpopt'); ?></p>
                </section>

                <form class="wpopt-deactivation-form" data-wpopt-deactivation-form>
                    <fieldset class="wpopt-deactivation-reasons">
                        <legend class="screen-reader-text"><?php esc_html_e('Deactivation reason', 'wpopt'); ?></legend>
                        <?php foreach ($reasons as $value => $label) : ?>
                            <label class="wpopt-deactivation-reason">
                                <input type="radio" name="reason" value="<?php echo esc_attr($value); ?>">
                                <span><?php echo esc_html($label); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>

                    <div class="wpopt-deactivation-other" data-wpopt-deactivation-other hidden>
                        <label for="wpopt-deactivation-details"><?php esc_html_e('Tell us more', 'wpopt'); ?></label>
                        <textarea id="wpopt-deactivation-details" name="details" rows="5" maxlength="1000" placeholder="<?php esc_attr_e('Describe the reason for deactivating the plugin...', 'wpopt'); ?>"></textarea>
                    </div>

                    <div class="wpopt-advertise-actions">
                        <button type="submit" class="wpopt-advertise-button is-primary" disabled><?php esc_html_e('Send feedback and deactivate', 'wpopt'); ?></button>
                        <button type="button" class="wpopt-advertise-button is-muted" data-wpopt-deactivation-skip><?php esc_html_e('Skip and deactivate', 'wpopt'); ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    private function get_deactivation_feedback_reasons(): array
    {
        return array(
            'temporary'       => __('I am deactivating it temporarily', 'wpopt'),
            'not_needed'      => __('I no longer need the plugin', 'wpopt'),
            'too_complicated' => __('It is too complicated; I need a tutorial', 'wpopt'),
            'missing_feature' => __('A feature I need is missing', 'wpopt'),
            'technical_issue' => __('I encountered a technical issue', 'wpopt'),
            'alternative'     => __('I found a better alternative', 'wpopt'),
            'other'           => __('Other', 'wpopt'),
        );
    }

    /**
     * Add donate link to plugin description in /wp-admin/plugins.php
     *
     * @param array $plugin_meta
     * @param string $plugin_file
     * @param string $plugin_data
     * @param string $status
     * @return array
     */
    public function donate_link($plugin_meta, $plugin_file, $plugin_data, $status): array
    {
        if ($plugin_file == $this->plugin_basename) {
            $plugin_meta[] = '&hearts; <a target="_blank" href="https://www.paypal.com/donate/?hosted_button_id=8G8VR4APG9JRU">' . __('Buy me a beer', 'wpopt') . ' :o)</a>';
        }

        return $plugin_meta;
    }

    /**
     * Add link to settings in Plugins list page
     *
     * @wp-hook plugin_action_links
     * @param $links
     * @param $file
     * @return mixed
     */
    public function extra_plugin_link($links, $file)
    {
        $links[] = sprintf(
            '<a href="%s">%s</a>',
            wps_module_setting_url('wpopt'),
            __('Settings', 'wpopt')
        );

        return $links;
    }

    private function do_welcome()
    {
        if (wp_doing_ajax() || wp_doing_cron() || !current_user_can('customize')) {
            return;
        }

        $should_show_welcome = wps('wpopt')->settings->get('do_welcome', false);

        if (!$should_show_welcome) {
            return;
        }

        if (get_option(self::WELCOME_SEEN_OPTION, false)) {
            wps('wpopt')->settings->update('do_welcome', false, true);
            return;
        }

        wps('wpopt')->settings->update('do_welcome', false, true);
        update_option(self::WELCOME_SEEN_OPTION, time(), 'no');
        Rewriter::getInstance()->redirect(admin_url('admin.php?page=wp-optimizer&wps-page=welcome&do_welcome=true'));
    }
}
