<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2026.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use DateTimeImmutable;
use DateTimeZone;
use WPS\core\CronActions;
use WPS\core\Graphic;
use WPS\core\RequestActions;
use WPS\core\Rewriter;
use WPS\core\UtilEnv;
use WPS\modules\Module;

class Mod_Performance_Monitor extends Module
{
    public static ?string $name = 'Performance Monitor';

    private const SLOW_QUERY_CAPTURE_THRESHOLD_MS = 1.0;
    private const SLOW_QUERY_CAPTURE_LIMIT = 100;
    private const SLOW_QUERY_PER_SIGNATURE_LIMIT = 0;
    private const SLOW_QUERY_DISPLAY_LIMIT = 25;
    private const SLOW_QUERY_SQL_MAX_LENGTH = 1600;
    private const MAX_REQUEST_HISTORY_ROWS = 10000;
    private const MAX_SLOW_QUERY_ROWS = 10000;

    public array $scopes = array('autoload', 'admin-page', 'settings');

    protected string $context = 'wpopt';

    private float $request_start_time = 0.0;

    private array $request_profile = array();

    private bool $response_detached = false;

    private array $monitor_snapshot = array();

    private array $component_catalog = array();

    private array $component_matchers = array();

    private array $component_metrics = array();

    private array $callback_profile_stack = array();

    private array $active_callback_component_stack = array();

    private array $component_load_checkpoint = array();

    private array $instrumented_hook_signatures = array();

    private array $callable_component_cache = array();

    private array $file_component_cache = array();

    private array $file_size_cache = array();

    private bool $query_capture_enabled = false;

    private array $captured_query_entries = array();

    private array $captured_query_snapshot = array();

    private bool $component_profile_supported = false;

    private bool $component_profiler_enabled = false;

    protected function init(): void
    {
        $message = sanitize_key((string)($_GET['message'] ?? ''));

        if ($message === 'wpopt-performance-history-reset') {
            $this->add_notices('success', __('Performance history has been reset.', 'wpopt'));
        }
        elseif ($message === 'wpopt-performance-history-cleaned') {
            $this->add_notices('success', __('Old performance history has been cleaned.', 'wpopt'));
        }

        if (!$this->has_enabled_monitor_collectors() || !$this->should_capture_request()) {
            return;
        }

        $this->request_start_time = $this->resolve_request_start_time();
        $this->component_profile_supported = $this->is_components_panel_enabled() && $this->supports_component_profile_column();

        if (!$this->has_enabled_runtime_collectors()) {
            return;
        }

        if ($this->should_enable_query_capture()) {
            $this->enable_query_capture();
        }

        if ($this->query_capture_enabled) {
            add_filter('log_query_custom_data', array($this, 'filter_query_custom_data'), 10, 5);
            add_action('shutdown', array($this, 'snapshot_query_log'), 0, 0);
        }

        if ($this->component_profile_supported) {
            $this->bootstrap_component_profiler();
        }

        if ($this->requires_query_context()) {
            add_action('wp', array($this, 'bootstrap_request_profile'), 1, 0);
        }
        else {
            $this->bootstrap_request_profile();
        }

        add_action('shutdown', array($this, 'store_request_metrics'), 9999, 0);
    }

    public function bootstrap_request_profile(): void
    {
        $this->request_profile = $this->detect_request_profile();
    }

    private function bootstrap_component_profiler(): void
    {
        if ($this->component_profiler_enabled) {
            return;
        }

        $this->component_profiler_enabled = true;
        $this->get_component_catalog();
        $this->mark_component_load_checkpoint();

        add_action('plugin_loaded', array($this, 'capture_loaded_plugin_footprint'), PHP_INT_MAX, 1);
        add_action('setup_theme', array($this, 'mark_component_load_checkpoint'), PHP_INT_MAX, 0);
        add_action('after_setup_theme', array($this, 'capture_active_theme_footprint'), 1, 0);

        $this->instrument_pending_hook_callbacks();

        foreach (array('plugin_loaded', 'after_setup_theme', 'init', 'wp_loaded', 'admin_init', 'template_redirect', 'rest_api_init') as $hook_name) {
            add_action($hook_name, array($this, 'instrument_pending_hook_callbacks'), PHP_INT_MAX, 0);
        }
    }

    public function mark_component_load_checkpoint(): void
    {
        $this->component_load_checkpoint = array(
            'started_at' => microtime(true),
            'memory'     => memory_get_usage(true),
            'peak'       => memory_get_peak_usage(true),
            'files'      => $this->get_normalized_included_files(),
        );
    }

    public function capture_loaded_plugin_footprint($plugin): void
    {
        $plugin = function_exists('plugin_basename') ? plugin_basename((string)$plugin) : (string)$plugin;

        if ($plugin === '') {
            $this->mark_component_load_checkpoint();
            return;
        }

        $this->capture_component_load_footprint(array('plugin:' . $plugin));
    }

    public function capture_active_theme_footprint(): void
    {
        $theme_keys = array();

        foreach ($this->get_component_catalog() as $component) {
            if (($component['type'] ?? '') === 'theme' && !empty($component['is_active'])) {
                $theme_keys[] = (string)$component['key'];
            }
        }

        $this->capture_component_load_footprint($theme_keys);
    }

    public function instrument_pending_hook_callbacks(): void
    {
        global $wp_filter, $wp_current_filter;

        if (empty($wp_filter) || !is_array($wp_filter)) {
            return;
        }

        $running_hooks = is_array($wp_current_filter ?? null) ? array_filter(array_map('strval', $wp_current_filter)) : array();

        foreach ($wp_filter as $hook_name => $hook) {
            if (!is_object($hook) || !property_exists($hook, 'callbacks') || empty($hook->callbacks) || in_array((string)$hook_name, $running_hooks, true)) {
                continue;
            }

            foreach ((array)$hook->callbacks as $priority => $callbacks) {
                if (empty($callbacks) || !is_array($callbacks)) {
                    continue;
                }

                $original_callbacks = array();

                foreach ($callbacks as $callback_id => $callback) {
                    if ($this->is_profiler_wrapper_id((string)$callback_id)) {
                        continue;
                    }

                    $original_callbacks[$callback_id] = $callback;
                }

                if (empty($original_callbacks)) {
                    continue;
                }

                $signature_key = (string)$hook_name . '|' . (string)$priority;
                $signature = $this->build_hook_callbacks_signature($original_callbacks);

                if (($this->instrumented_hook_signatures[$signature_key] ?? '') === $signature) {
                    continue;
                }

                $rewritten_callbacks = array();
                $changed = count($original_callbacks) !== count($callbacks);

                foreach ($original_callbacks as $callback_id => $callback) {
                    if (!is_array($callback) || !isset($callback['function'])) {
                        $rewritten_callbacks[$callback_id] = $callback;
                        continue;
                    }

                    $component = $this->resolve_component_from_callable($callback['function']);

                    if (empty($component)) {
                        $rewritten_callbacks[$callback_id] = $callback;
                        continue;
                    }

                    $accepted_args = max(1, absint($callback['accepted_args'] ?? 1));
                    $before_id = $this->build_profiler_wrapper_id('before', (string)$hook_name, (string)$priority, (string)$callback_id);
                    $after_id = $this->build_profiler_wrapper_id('after', (string)$hook_name, (string)$priority, (string)$callback_id);

                    $rewritten_callbacks[$before_id] = array(
                        'function'      => function (...$args) use ($before_id, $component, $hook_name) {
                            $this->start_component_callback_profile($before_id, (string)$hook_name, $component);

                            return $args[0] ?? null;
                        },
                        'accepted_args' => $accepted_args,
                    );

                    $rewritten_callbacks[$callback_id] = $callback;

                    $rewritten_callbacks[$after_id] = array(
                        'function'      => function (...$args) use ($before_id, $component, $hook_name) {
                            $this->stop_component_callback_profile($before_id, (string)$hook_name, $component);

                            return $args[0] ?? null;
                        },
                        'accepted_args' => $accepted_args,
                    );

                    $changed = true;
                }

                if ($changed) {
                    $hook->callbacks[$priority] = $rewritten_callbacks;
                }

                $this->instrumented_hook_signatures[$signature_key] = $signature;
            }
        }
    }

    public function filter_query_custom_data($query_data, $query, $query_time, $query_callstack, $query_start): array
    {
        $query_data = is_array($query_data) ? $query_data : array();
        $query = (string)$query;
        $query_time = (float)$query_time;
        $query_callstack_raw = $query_callstack;
        $query_callstack = is_array($query_callstack) ? $query_callstack : array();
        $query_start = (float)$query_start;

        if (!$this->component_profiler_enabled) {
            $this->capture_query_entry($query, $query_time, (string)$query_callstack_raw, $query_start, $query_data);
            return $query_data;
        }

        $component = $this->resolve_component_for_query($query, $query_callstack);

        if (empty($component)) {
            $this->capture_query_entry($query, $query_time, (string)$query_callstack_raw, $query_start, $query_data);
            return $query_data;
        }

        $query_data['wpopt_component_key'] = $component['key'];
        $query_data['wpopt_component_type'] = $component['type'];
        $query_data['wpopt_component_label'] = $component['label'];
        $query_data['wpopt_component_version'] = $component['version'];
        $query_data['wpopt_hook'] = current_filter() ?: '';

        $this->capture_query_entry($query, $query_time, (string)$query_callstack_raw, $query_start, $query_data);

        return $query_data;
    }

    public function actions(): void
    {
        CronActions::schedule('WPOPT-PerformanceMonitorCleanup', HOUR_IN_SECONDS, function () {
            $this->cleanup_history();
        }, '00:00');

        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        RequestActions::request($this->action_hook, function ($action) {
            global $wpdb;

            switch ($action) {
                case 'cleanup_history':
                    $this->cleanup_history();

                    Rewriter::getInstance(admin_url('admin.php'))->add_query_args(array(
                        'page'    => 'wpopt-' . $this->slug,
                        'message' => 'wpopt-performance-history-cleaned',
                    ))->redirect();
                    break;

                case 'reset_history':
                    $wpdb->query('TRUNCATE TABLE ' . WPOPT_TABLE_REQUEST_PERFORMANCE);
                    $wpdb->query('TRUNCATE TABLE ' . WPOPT_TABLE_SLOW_QUERIES);
                    $this->reset_cumulative_cache_metrics();

                    Rewriter::getInstance(admin_url('admin.php'))->add_query_args(array(
                        'page'    => 'wpopt-' . $this->slug,
                        'message' => 'wpopt-performance-history-reset',
                    ))->redirect();
                    break;
            }
        });
    }

    public function restricted_access($context = ''): bool
    {
        switch ($context) {
            case 'settings':
            case 'render-admin':
            case 'ajax':
                return !current_user_can('manage_options');

            default:
                return false;
        }
    }

    public function enqueue_scripts(): void
    {
        parent::enqueue_scripts();

        wp_enqueue_script(
            'wpopt-performance-monitor-page',
            UtilEnv::path_to_url(WPOPT_ABSPATH) . 'modules/supporters/performance-monitor/performance-monitor.js',
            array('vendor-wps-js'),
            WPOPT_VERSION,
            true
        );
    }

    public function store_request_metrics(): void
    {
        if (!$this->request_start_time || !defined('WPOPT_TABLE_REQUEST_PERFORMANCE')) {
            return;
        }

        if (empty($this->request_profile)) {
            $this->request_profile = $this->detect_request_profile();
        }

        if (empty($this->request_profile)) {
            return;
        }

        $this->detach_response_for_background_work();
        $persistence_plan = $this->build_request_persistence_plan();

        if (
            empty($persistence_plan['request_row'])
            && empty($persistence_plan['slow_queries'])
            && empty($persistence_plan['cache_metrics'])
            && empty($persistence_plan['cleanup_history'])
        ) {
            return;
        }

        $this->flush_request_persistence($persistence_plan);
    }

    private function build_request_persistence_plan(): array
    {
        $duration_ms = max(0, round((microtime(true) - $this->request_start_time) * 1000, 3));
        $slow_threshold = max(1, absint($this->option('monitor.slow_request_ms', 1500)));
        $is_slow_request = $duration_ms >= $slow_threshold;
        $has_request_based_collectors = $this->is_overview_panel_enabled() || $this->is_cache_panel_enabled() || $this->is_components_panel_enabled();
        $persist_fast_request = $has_request_based_collectors ? $this->should_persist_fast_request_sample() : false;
        $collect_slow_queries = $this->is_slow_queries_panel_enabled() && $this->query_capture_enabled;
        $slow_queries = $collect_slow_queries
            ? $this->collect_slow_query_samples($duration_ms, $slow_threshold)
            : array();
        $should_persist_request = $is_slow_request || !empty($slow_queries) || $persist_fast_request;
        $collect_cache_metrics = $this->is_cache_panel_enabled() && $should_persist_request;
        $collect_components = $this->is_components_panel_enabled() && $this->component_profile_supported && $should_persist_request;
        $cache_metrics = $collect_cache_metrics && function_exists('wpopt_get_request_cache_metrics')
            ? wpopt_get_request_cache_metrics()
            : $this->get_empty_cache_metrics();
        $component_profile_json = $collect_components
            ? $this->build_request_component_profile_json()
            : '';
        $request_data = array(
            'blog_id'          => get_current_blog_id(),
            'request_type'     => $this->request_profile['type'],
            'request_label'    => $this->request_profile['label'],
            'request_method'   => strtoupper(sanitize_text_field((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'))),
            'request_uri'      => $this->sanitize_request_uri(),
            'status_code'      => $this->resolve_response_status(),
            'response_time_ms' => $duration_ms,
            'memory_peak'      => memory_get_peak_usage(true),
            'memory_usage'     => memory_get_usage(true),
            'query_count'      => function_exists('get_num_queries') ? get_num_queries() : 0,
            'is_slow'          => $is_slow_request ? 1 : 0,
            'created_at'       => current_time('mysql'),
            'created_at_gmt'   => current_time('mysql', true),
        );
        // Only keep the request-history row when an enabled feature really needs that shared row.
        $persist_request_row = ($this->is_overview_panel_enabled() || $component_profile_json !== '') && $should_persist_request;
        $data = array();
        $formats = array();

        if ($persist_request_row) {
            $data = array(
                'blog_id'          => $request_data['blog_id'],
                'request_type'     => $request_data['request_type'],
                'request_label'    => $request_data['request_label'],
                'request_method'   => $request_data['request_method'],
                'request_uri'      => $request_data['request_uri'],
                'status_code'      => $request_data['status_code'],
                'response_time_ms' => $request_data['response_time_ms'],
                'memory_peak'      => $request_data['memory_peak'],
                'memory_usage'     => $request_data['memory_usage'],
                'query_count'      => $request_data['query_count'],
            );

            $formats = array('%d', '%s', '%s', '%s', '%s', '%d', '%f', '%d', '%d', '%d');

            if ($component_profile_json !== '') {
                $data['component_profile_json'] = $component_profile_json;
                $formats[] = '%s';
            }

            $data['is_slow'] = $request_data['is_slow'];
            $data['created_at'] = $request_data['created_at'];
            $data['created_at_gmt'] = $request_data['created_at_gmt'];
            $formats = array_merge($formats, array('%d', '%s', '%s'));
        }

        return array(
            'request_data'                 => $request_data,
            'request_row'                  => $data,
            'request_formats'              => $formats,
            'slow_queries'                 => $slow_queries,
            'cache_metrics'                => $collect_cache_metrics && (($cache_metrics['cache_hits'] ?? 0) + ($cache_metrics['cache_misses'] ?? 0)) > 0 ? $cache_metrics : array(),
            'cleanup_history'              => false,
        );
    }

    private function flush_request_persistence(array $persistence_plan): void
    {
        global $wpdb;

        $request_log_id = 0;

        if (!empty($persistence_plan['request_row']) && !empty($persistence_plan['request_formats'])) {
            $wpdb->insert(
                WPOPT_TABLE_REQUEST_PERFORMANCE,
                $persistence_plan['request_row'],
                $persistence_plan['request_formats']
            );

            $request_log_id = (int)$wpdb->insert_id;

            if ($request_log_id > self::MAX_REQUEST_HISTORY_ROWS) {
                $this->prune_table_to_row_limit(WPOPT_TABLE_REQUEST_PERFORMANCE, self::MAX_REQUEST_HISTORY_ROWS);
            }
        }

        if (!empty($persistence_plan['slow_queries'])) {
            $slow_query_insert_id = $this->store_slow_query_samples($request_log_id, $persistence_plan['slow_queries'], $persistence_plan['request_data']);

            if ($slow_query_insert_id > self::MAX_SLOW_QUERY_ROWS) {
                $this->prune_table_to_row_limit(WPOPT_TABLE_SLOW_QUERIES, self::MAX_SLOW_QUERY_ROWS);
            }
        }

        if (!empty($persistence_plan['cache_metrics'])) {
            $this->persist_cumulative_cache_metrics($persistence_plan['cache_metrics']);
        }

        $this->maybe_cleanup_history();
    }

    protected function setting_fields($filter = ''): array
    {
        return $this->group_setting_fields(
            $this->group_setting_fields(
                $this->setting_field(__('Collected data', 'wpopt'), false, 'separator'),
                $this->setting_field(__('Collect request history and show Overview', 'wpopt'), 'monitor.sections.overview', 'checkbox', array('default_value' => true)),
                $this->setting_field(__('Collect cache hit / miss metrics', 'wpopt'), 'monitor.sections.cache', 'checkbox', array('default_value' => true)),
                $this->setting_field(__('Collect plugins & themes profiling', 'wpopt'), 'monitor.sections.components', 'checkbox', array('default_value' => true))
            ),
            $this->group_setting_fields(
                $this->setting_field(__('Shared request capture', 'wpopt'), false, 'separator'),
                $this->setting_field(__('Request capture sample rate (%)', 'wpopt'), 'monitor.sample_rate', 'numeric', array('default_value' => 100)),
                $this->setting_field(__('Slow request threshold (ms)', 'wpopt'), 'monitor.slow_request_ms', 'numeric', array('default_value' => 1500)),
                $this->setting_field(__('Fast request persistence rate (%)', 'wpopt'), 'monitor.fast_request_sample_rate', 'numeric', array('default_value' => 10)),
                $this->setting_field(__('Stored request URI max length', 'wpopt'), 'monitor.request_uri_max_length', 'numeric', array('default_value' => 1024))
            ),
            $this->group_setting_fields(
                $this->setting_field(__('Tracked request types', 'wpopt'), false, 'separator'),
                $this->setting_field(__('Capture REST API requests', 'wpopt'), 'monitor.capture_rest', 'checkbox', array('default_value' => true)),
                $this->setting_field(__('Capture admin-ajax requests', 'wpopt'), 'monitor.capture_ajax', 'checkbox', array('default_value' => false)),
                $this->setting_field(__('Capture wp-admin requests', 'wpopt'), 'monitor.capture_admin', 'checkbox', array('default_value' => true)),
                $this->setting_field(__('Capture wp-login requests', 'wpopt'), 'monitor.capture_login', 'checkbox', array('default_value' => false)),
                $this->setting_field(__('Capture XML-RPC requests', 'wpopt'), 'monitor.capture_xmlrpc', 'checkbox', array('default_value' => false))
            ),
            $this->group_setting_fields(
                $this->setting_field(__('Slow SQL', 'wpopt'), false, 'separator'),
                $this->setting_field(__('Collect and show Slow SQL queries', 'wpopt'), 'monitor.sections.slow_queries', 'checkbox', array('default_value' => true)),
                $this->setting_field(__('Duplicate request context in each SQL sample', 'wpopt'), 'monitor.slow_query_store_request_context', 'checkbox', array('default_value' => false, 'parent' => 'monitor.sections.slow_queries')),
                $this->setting_field(__('Store SQL caller backtrace', 'wpopt'), 'monitor.slow_query_store_callers', 'checkbox', array('default_value' => false, 'parent' => 'monitor.sections.slow_queries')),
                $this->setting_field(__('Stored SQL caller backtrace max length', 'wpopt'), 'monitor.slow_query_caller_max_length', 'numeric', array('default_value' => 600, 'parent' => 'monitor.slow_query_store_callers'))
            )
        );
    }

    protected function infos(): array
    {
        return array(
            'monitor.sections.overview' => __('When disabled, Performance Monitor stops collecting request history and the Overview tab has no data source.', 'wpopt'),
            'monitor.sections.cache' => __('When disabled, Performance Monitor stops collecting cache hit / miss metrics and the related tab is not populated.', 'wpopt'),
            'monitor.sections.components' => __('When disabled, callback and component profiling stop in background and the related tab is not populated.', 'wpopt'),
            'monitor.sections.slow_queries' => __('When disabled, slow SQL capture is fully turned off: no SQL samples are captured, stored or shown.', 'wpopt'),
            'monitor.slow_query_store_request_context' => __('Keep this disabled for lower write overhead. Enable it only if you want request context duplicated inside every saved SQL sample.', 'wpopt'),
            'monitor.slow_query_store_callers' => __('Keep this disabled for lower overhead. Enable it only if you need the SQL caller backtrace for debugging.', 'wpopt'),
            'monitor.slow_query_caller_max_length' => __('Maximum stored caller backtrace length when SQL caller capture is enabled.', 'wpopt'),
            'monitor.sample_rate'     => __('Shared setting. Sampling is applied before any enabled feature stores request data. 100 means every eligible request is captured.', 'wpopt'),
            'monitor.slow_request_ms' => __('Shared setting. Requests at or above this threshold are treated as slow. This affects request history and Slow SQL retention.', 'wpopt'),
            'monitor.fast_request_sample_rate' => __('Shared setting. Percent of non-slow requests persisted by enabled request-based collectors. Use 0 to skip fast requests entirely.', 'wpopt'),
            'monitor.request_uri_max_length' => __('Shared storage limit for saved request URIs. Applies to request history and to inline Slow SQL request context when that option is enabled.', 'wpopt'),
            'monitor.capture_rest'    => __('Shared capture scope. If disabled, no Performance Monitor feature collects WordPress REST API requests.', 'wpopt'),
            'monitor.capture_ajax'    => __('Shared capture scope. If disabled, no Performance Monitor feature collects admin-ajax requests.', 'wpopt'),
            'monitor.capture_admin'   => __('Shared capture scope. If disabled, no Performance Monitor feature collects wp-admin page requests.', 'wpopt'),
            'monitor.capture_login'   => __('Shared capture scope. If disabled, no Performance Monitor feature collects wp-login.php requests.', 'wpopt'),
            'monitor.capture_xmlrpc'  => __('Shared capture scope. If disabled, no Performance Monitor feature collects XML-RPC requests.', 'wpopt'),
        );
    }

    public function validate_settings($input, $filtering = false): array
    {
        $valid = parent::validate_settings($input, $filtering);
        $monitor = is_array($valid['monitor'] ?? null) ? $valid['monitor'] : array();
        $sections = is_array($monitor['sections'] ?? null) ? $monitor['sections'] : array();

        $valid['monitor']['slow_request_ms'] = max(1, absint($valid['monitor']['slow_request_ms'] ?? 1500));
        $valid['monitor']['sample_rate'] = min(100, max(1, absint($valid['monitor']['sample_rate'] ?? 100)));
        $valid['monitor']['fast_request_sample_rate'] = min(100, max(0, absint($valid['monitor']['fast_request_sample_rate'] ?? 10)));
        $valid['monitor']['request_uri_max_length'] = min(4096, max(128, absint($valid['monitor']['request_uri_max_length'] ?? 1024)));
        $valid['monitor']['slow_query_store_request_context'] = !empty($valid['monitor']['slow_query_store_request_context']);
        $valid['monitor']['slow_query_store_callers'] = !empty($valid['monitor']['slow_query_store_callers']);
        $valid['monitor']['slow_query_caller_max_length'] = min(4000, max(128, absint($valid['monitor']['slow_query_caller_max_length'] ?? 600)));
        unset(
            $valid['monitor']['slow_query_sql_max_length'],
            $valid['monitor']['slow_query_capture_ms'],
            $valid['monitor']['slow_query_capture_limit'],
            $valid['monitor']['slow_query_per_signature_limit'],
            $valid['monitor']['slow_query_display_limit']
        );
        $valid['monitor']['sections'] = array(
            'overview'     => !empty($sections['overview']),
            'slow_queries' => !empty($sections['slow_queries']),
            'cache'        => !empty($sections['cache']),
            'components'   => !empty($sections['components']),
            'maintenance'  => true,
        );

        if (empty($valid['monitor']['sections']['slow_queries'])) {
            $valid['monitor']['slow_query_store_request_context'] = false;
            $valid['monitor']['slow_query_store_callers'] = false;
        }

        return $valid;
    }

    private function is_monitor_section_enabled(string $section, bool $default = true): bool
    {
        return (bool)$this->option('monitor.sections.' . $section, $default);
    }

    private function is_overview_panel_enabled(): bool
    {
        return $this->is_monitor_section_enabled('overview', true);
    }

    private function is_slow_queries_panel_enabled(): bool
    {
        return $this->is_monitor_section_enabled('slow_queries', true);
    }

    private function is_cache_panel_enabled(): bool
    {
        return $this->is_monitor_section_enabled('cache', true);
    }

    private function is_components_panel_enabled(): bool
    {
        return $this->is_monitor_section_enabled('components', true);
    }

    private function is_maintenance_panel_enabled(): bool
    {
        return true;
    }

    private function has_enabled_monitor_collectors(): bool
    {
        return $this->is_overview_panel_enabled()
            || $this->is_slow_queries_panel_enabled()
            || $this->is_cache_panel_enabled()
            || $this->is_components_panel_enabled();
    }

    private function has_enabled_runtime_collectors(): bool
    {
        return $this->is_overview_panel_enabled()
            || $this->is_slow_queries_panel_enabled()
            || $this->is_cache_panel_enabled()
            || $this->component_profile_supported;
    }

    private function should_enable_query_capture(): bool
    {
        return $this->is_slow_queries_panel_enabled() || $this->component_profile_supported;
    }

    private function get_slow_query_capture_threshold_ms(int $slow_threshold): float
    {
        unset($slow_threshold);

        return self::SLOW_QUERY_CAPTURE_THRESHOLD_MS;
    }

    private function get_fast_request_sample_rate(): int
    {
        return min(100, max(0, absint($this->option('monitor.fast_request_sample_rate', 10))));
    }

    private function should_persist_fast_request_sample(): bool
    {
        $sample_rate = $this->get_fast_request_sample_rate();

        if ($sample_rate >= 100) {
            return true;
        }

        if ($sample_rate <= 0) {
            return false;
        }

        return mt_rand(1, 100) <= $sample_rate;
    }

    private function get_slow_query_capture_limit(): int
    {
        return self::SLOW_QUERY_CAPTURE_LIMIT;
    }

    private function get_slow_query_per_signature_limit(): int
    {
        return self::SLOW_QUERY_PER_SIGNATURE_LIMIT;
    }

    private function get_slow_query_display_limit(): int
    {
        return self::SLOW_QUERY_DISPLAY_LIMIT;
    }

    private function get_request_uri_max_length(): int
    {
        return min(4096, max(128, absint($this->option('monitor.request_uri_max_length', 1024))));
    }

    private function get_slow_query_sql_max_length(): int
    {
        return self::SLOW_QUERY_SQL_MAX_LENGTH;
    }

    private function should_store_slow_query_request_context(): bool
    {
        return (bool)$this->option('monitor.slow_query_store_request_context', false);
    }

    private function should_store_slow_query_callers(): bool
    {
        return (bool)$this->option('monitor.slow_query_store_callers', false);
    }

    private function get_slow_query_caller_max_length(): int
    {
        return min(4000, max(128, absint($this->option('monitor.slow_query_caller_max_length', 600))));
    }

    protected function render_sub_modules(): void
    {
        $panels = array();

        if ($this->is_overview_panel_enabled()) {
            $panels[] = array(
                'id'          => 'performance-overview',
                'panel-title' => __('Overview', 'wpopt'),
                'callback'    => array($this, 'render_monitor_overview_panel'),
            );
        }

        if ($this->is_slow_queries_panel_enabled()) {
            $panels[] = array(
                'id'          => 'performance-slow-sql',
                'panel-title' => __('Slow SQL queries', 'wpopt'),
                'callback'    => array($this, 'render_monitor_slow_queries_panel'),
            );
        }

        if ($this->is_cache_panel_enabled()) {
            $panels[] = array(
                'id'          => 'performance-cache-ratio',
                'panel-title' => __('Cache hit / miss', 'wpopt'),
                'callback'    => array($this, 'render_monitor_cache_panel'),
            );
        }

        if ($this->is_components_panel_enabled()) {
            $panels[] = array(
                'id'          => 'performance-components',
                'panel-title' => __('Plugins & themes', 'wpopt'),
                'callback'    => array($this, 'render_monitor_components_panel'),
            );
        }

        if ($this->is_maintenance_panel_enabled()) {
            $panels[] = array(
                'id'          => 'performance-maintenance',
                'panel-title' => __('Maintenance', 'wpopt'),
                'callback'    => array($this, 'render_monitor_maintenance_panel'),
            );
        }

        ?>
        <section class="wps-wrap">
            <block class="wps">
                <section class="wps-header"><h1><?php _e('Performance Monitor', 'wpopt'); ?></h1></section>
                <?php
                if (empty($panels)) {
                    ?>
                    <div class="wpopt-perf-shell">
                        <block class="wps wpopt-perf-table">
                            <h3><?php _e('No sections enabled', 'wpopt'); ?></h3>
                            <?php echo $this->render_empty_state(__('Enable at least one Performance Monitor section from settings to show data here.', 'wpopt')); ?>
                            <p><a class="wps wps-button wpopt-btn is-info" href="<?php echo esc_url(wps_module_setting_url('wpopt', $this->slug)); ?>"><?php _e('Open settings', 'wpopt'); ?></a></p>
                        </block>
                    </div>
                    <?php
                }
                else {
                    echo Graphic::generateHTML_tabs_panels($panels);
                }
                ?>
            </block>
        </section>
        <?php
    }

    public function render_monitor_overview_panel(): string
    {
        $snapshot = $this->get_monitor_snapshot(array('overview'));
        $window = $snapshot['window'];
        $summary = $snapshot['summary'];
        $types = $snapshot['types'];
        $snapshot_types = $snapshot['snapshot_types'];
        $labels = $snapshot['labels'];
        $recent = $snapshot['recent'];
        $type_series = $snapshot['type_series'];
        $series = $snapshot['series'];

        ob_start();
        ?>
        <div class="wpopt-perf-shell">
            <div class="wpopt-perf-toolbar">
                <p class="wpopt-muted"><?php echo esc_html(sprintf(__('Performance data is limited to %s and older entries are cleaned automatically.', 'wpopt'), strtolower($window['label']))); ?></p>
            </div>

            <div class="wpopt-perf-kpis">
                <div class="wpopt-perf-kpi"><span><?php _e('Requests', 'wpopt'); ?></span><strong><?php echo number_format_i18n((int)$summary['total_requests']); ?></strong></div>
                <div class="wpopt-perf-kpi"><span><?php _e('Average time', 'wpopt'); ?></span><strong><?php echo esc_html($this->format_ms($summary['avg_ms'])); ?></strong></div>
                <div class="wpopt-perf-kpi"><span><?php _e('Peak time', 'wpopt'); ?></span><strong><?php echo esc_html($this->format_ms($summary['max_ms'])); ?></strong></div>
                <div class="wpopt-perf-kpi"><span><?php _e('Slow requests', 'wpopt'); ?></span><strong><?php echo number_format_i18n((int)$summary['slow_hits']); ?></strong></div>
                <div class="wpopt-perf-kpi"><span><?php _e('Average memory peak', 'wpopt'); ?></span><strong><?php echo esc_html(size_format((int)round($summary['avg_memory_peak']))); ?></strong></div>
                <div class="wpopt-perf-kpi"><span><?php _e('Average queries', 'wpopt'); ?></span><strong><?php echo esc_html(number_format_i18n((float)$summary['avg_queries'], 1)); ?></strong></div>
            </div>

            <div class="wpopt-perf-grid">
                <div class="wpopt-perf-chart">
                    <h3><?php _e('Average response time trend', 'wpopt'); ?></h3>
                    <?php echo !empty($series['series']) ? $this->render_line_chart($series['labels'], $series['series']) : $this->render_empty_state(__('No data collected yet for this time window.', 'wpopt')); ?>
                </div>
                <div class="wpopt-perf-chart">
                    <h3><?php _e('By request type', 'wpopt'); ?></h3>
                    <?php echo !empty($types) ? $this->render_bar_chart($types) : $this->render_empty_state(__('Visit the site or call an API endpoint to start populating the chart.', 'wpopt')); ?>
                </div>
            </div>

            <block class="wps wpopt-perf-table">
                <h3><?php _e('Type snapshots', 'wpopt'); ?></h3>
                <?php echo $this->render_type_cards($snapshot_types, $type_series['labels'], $type_series['series']); ?>
            </block>

            <block class="wps wpopt-perf-table">
                <h3><?php _e('Top request groups', 'wpopt'); ?></h3>
                <?php if (empty($labels)): ?>
                    <?php echo $this->render_empty_state(__('No grouped request history available yet.', 'wpopt')); ?>
                <?php else: ?>
                    <table class="widefat wps">
                        <thead><tr><th><?php _e('Type', 'wpopt'); ?></th><th><?php _e('Group', 'wpopt'); ?></th><th><?php _e('Hits', 'wpopt'); ?></th><th><?php _e('Average', 'wpopt'); ?></th><th><?php _e('Peak', 'wpopt'); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($labels as $index => $row): ?>
                            <tr <?php echo $index % 2 ? "class='alternate'" : ''; ?>>
                                <td><?php echo esc_html($this->humanize_type($row['request_type'])); ?></td>
                                <td><code><?php echo esc_html($row['request_label']); ?></code></td>
                                <td><?php echo number_format_i18n((int)$row['hits']); ?></td>
                                <td><?php echo esc_html($this->format_ms($row['avg_ms'])); ?></td>
                                <td><?php echo esc_html($this->format_ms($row['max_ms'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </block>

            <block class="wps wpopt-perf-table">
                <h3><?php _e('Latest requests', 'wpopt'); ?></h3>
                <?php if (empty($recent)): ?>
                    <?php echo $this->render_empty_state(__('No requests stored in the selected window.', 'wpopt')); ?>
                <?php else: ?>
                    <table class="widefat wps">
                        <thead><tr><th><?php _e('Time', 'wpopt'); ?></th><th><?php _e('Type', 'wpopt'); ?></th><th><?php _e('Group', 'wpopt'); ?></th><th><?php _e('Method', 'wpopt'); ?></th><th><?php _e('Status', 'wpopt'); ?></th><th><?php _e('Duration', 'wpopt'); ?></th><th><?php _e('Memory', 'wpopt'); ?></th><th><?php _e('Queries', 'wpopt'); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($recent as $index => $row): ?>
                            <tr <?php echo $index % 2 ? "class='alternate'" : ''; ?>>
                                <td><?php echo esc_html(mysql2date('d M Y H:i', $row['created_at'])); ?></td>
                                <td><?php echo esc_html($this->humanize_type($row['request_type'])); ?></td>
                                <td title="<?php echo esc_attr($row['request_uri']); ?>"><code><?php echo esc_html($row['request_label']); ?></code></td>
                                <td><?php echo esc_html($row['request_method']); ?></td>
                                <td><?php echo number_format_i18n((int)$row['status_code']); ?></td>
                                <td><?php echo esc_html($this->format_ms($row['response_time_ms'])); ?></td>
                                <td><?php echo esc_html(size_format((int)$row['memory_peak'])); ?></td>
                                <td><?php echo number_format_i18n((int)$row['query_count']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </block>
        </div>
        <?php
        return (string)ob_get_clean();
    }

    public function render_monitor_slow_queries_panel(): string
    {
        $snapshot = $this->get_monitor_snapshot(array('slow_queries'));
        $window = $snapshot['window'];
        $slow_queries = $snapshot['slow_queries'];
        $display_limit = $this->get_slow_query_display_limit();

        ob_start();
        ?>
        <div class="wpopt-perf-shell">
            <div class="wpopt-perf-toolbar">
                <p class="wpopt-muted"><?php echo esc_html(sprintf(__('Slow SQL samples captured in %s. Showing up to the %d slowest captured queries in the selected window.', 'wpopt'), strtolower($window['label']), $display_limit)); ?></p>
            </div>

            <block class="wps wpopt-perf-table">
                <h3><?php _e('Slow SQL queries', 'wpopt'); ?></h3>
                <?php echo !empty($slow_queries) ? $this->render_slow_query_explorer($slow_queries) : $this->render_empty_state(__('No slow SQL queries captured yet in the selected window.', 'wpopt')); ?>
            </block>
        </div>
        <?php
        return (string)ob_get_clean();
    }

    public function render_monitor_cache_panel(): string
    {
        $snapshot = $this->get_monitor_snapshot(array('cache'));
        $cache = $snapshot['cache'];
        $summary = $cache['summary'];
        $layers = $cache['layers'];

        ob_start();
        ?>
        <div class="wpopt-perf-shell">
            <div class="wpopt-perf-toolbar">
                <p class="wpopt-muted"><?php _e('Cumulative cache counters collected since the last reset. Metrics are merged from Database cache and WP_Query cache without storing extra per-request cache rows.', 'wpopt'); ?></p>
            </div>

            <?php if ((int)($summary['total_operations'] ?? 0) <= 0): ?>
                <?php echo $this->render_empty_state(__('No cache hits or misses recorded yet. Enable Database cache or WP_Query cache and browse the site to populate this tab.', 'wpopt')); ?>
            <?php else: ?>
                <div class="wpopt-perf-cache-grid">
                    <?php echo $this->render_cache_summary_card($summary); ?>
                    <div class="wpopt-perf-chart">
                        <h3><?php _e('Cumulative hit / miss distribution', 'wpopt'); ?></h3>
                        <?php echo $this->render_cumulative_cache_chart($summary, $layers); ?>
                    </div>
                </div>

                <block class="wps wpopt-perf-table">
                    <h3><?php _e('Cache layers', 'wpopt'); ?></h3>
                    <?php echo $this->render_cache_layer_cards($layers); ?>
                </block>
            <?php endif; ?>
        </div>
        <?php
        return (string)ob_get_clean();
    }

    public function render_monitor_components_panel(): string
    {
        $snapshot = $this->get_monitor_snapshot(array('components'));
        $window = $snapshot['window'];
        $components = $snapshot['components'];
        $summary = $components['summary'];
        $rows = $components['rows'];

        ob_start();
        ?>
        <div class="wpopt-perf-shell">
            <div class="wpopt-perf-toolbar">
                <p class="wpopt-muted"><?php echo esc_html(sprintf(__('Average plugin and theme footprint per sampled request across %s. Sort the list alphabetically, by observed time or by memory footprint.', 'wpopt'), strtolower($window['label']))); ?></p>
            </div>

            <?php if (empty($rows)): ?>
                <?php echo $this->render_empty_state(__('No plugin or theme footprint has been recorded yet. Generate a few requests after the upgrade to populate this tab.', 'wpopt')); ?>
            <?php else: ?>
                <div class="wpopt-perf-kpis">
                    <div class="wpopt-perf-kpi"><span><?php _e('Components', 'wpopt'); ?></span><strong><?php echo number_format_i18n((int)$summary['components']); ?></strong></div>
                    <div class="wpopt-perf-kpi"><span><?php _e('Plugins', 'wpopt'); ?></span><strong><?php echo number_format_i18n((int)$summary['plugins']); ?></strong></div>
                    <div class="wpopt-perf-kpi"><span><?php _e('Themes', 'wpopt'); ?></span><strong><?php echo number_format_i18n((int)$summary['themes']); ?></strong></div>
                    <div class="wpopt-perf-kpi"><span><?php _e('Avg observed time', 'wpopt'); ?></span><strong><?php echo esc_html($this->format_ms((float)$summary['observed_time_ms'])); ?></strong></div>
                    <div class="wpopt-perf-kpi"><span><?php _e('Avg peak memory', 'wpopt'); ?></span><strong><?php echo esc_html(size_format((int)$summary['peak_memory'])); ?></strong></div>
                    <div class="wpopt-perf-kpi"><span><?php _e('Avg queries', 'wpopt'); ?></span><strong><?php echo esc_html($this->format_avg_count((float)$summary['query_count'])); ?></strong></div>
                </div>

                <block class="wps wpopt-perf-table">
                    <h3><?php _e('Component explorer', 'wpopt'); ?></h3>
                    <?php echo $this->render_component_explorer($rows); ?>
                </block>
            <?php endif; ?>
        </div>
        <?php
        return (string)ob_get_clean();
    }

    public function render_monitor_maintenance_panel(): string
    {
        ob_start();
        ?>
        <div class="wpopt-perf-shell">
            <div class="wpopt-perf-toolbar">
                <p class="wpopt-muted"><?php _e('Cleanup and reset tools for the fixed 24-hour Performance Monitor retention window.', 'wpopt'); ?></p>
            </div>

            <block class="wps wpopt-perf-table">
                <h3><?php _e('Maintenance', 'wpopt'); ?></h3>
                <p><?php echo esc_html(__('Only the latest 24 hours are kept. Older performance and slow-query records are removed automatically.', 'wpopt')); ?></p>
                <form method="post" class="wpopt-perf-actions">
                    <?php RequestActions::nonce_field($this->action_hook); ?>
                    <?php echo RequestActions::get_action_button($this->action_hook, 'reset_history', __('Reset history', 'wpopt'), 'wps wps-button wpopt-btn is-danger'); ?>
                    <a class="wps wps-button wpopt-btn is-info" href="<?php echo esc_url(wps_module_setting_url('wpopt', $this->slug)); ?>"><?php _e('Open settings', 'wpopt'); ?></a>
                </form>
            </block>
        </div>
        <?php
        return (string)ob_get_clean();
    }

    private function should_capture_request(): bool
    {
        if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return false;
        }

        $sample_rate = min(100, max(1, absint($this->option('monitor.sample_rate', 100))));
        if ($sample_rate < 100 && mt_rand(1, 100) > $sample_rate) {
            return false;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return (bool)$this->option('monitor.capture_rest', true);
        }

        if (wp_doing_ajax()) {
            return (bool)$this->option('monitor.capture_ajax', false);
        }

        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return (bool)$this->option('monitor.capture_xmlrpc', false);
        }

        $pagenow = $GLOBALS['pagenow'] ?? '';
        if ($pagenow === 'wp-login.php') {
            return (bool)$this->option('monitor.capture_login', false);
        }

        if (is_admin() || $this->is_admin_request_path()) {
            return (bool)$this->option('monitor.capture_admin', true);
        }

        return true;
    }

    private function requires_query_context(): bool
    {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }

        if (wp_doing_ajax()) {
            return false;
        }

        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return false;
        }

        $pagenow = $GLOBALS['pagenow'] ?? '';
        if ($pagenow === 'wp-login.php' || is_admin() || $this->is_admin_request_path()) {
            return false;
        }

        return true;
    }

    private function resolve_request_start_time(): float
    {
        global $timestart;

        if (isset($timestart) && is_numeric($timestart)) {
            return (float)$timestart;
        }

        return microtime(true);
    }

    private function detach_response_for_background_work(): void
    {
        if ($this->response_detached || wp_doing_ajax() || headers_sent()) {
            return;
        }

        ignore_user_abort(true);

        if (function_exists('session_status') && function_exists('session_write_close') && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            $this->response_detached = true;
            return;
        }

        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
            $this->response_detached = true;
            return;
        }

        if (ob_get_level() > 0) {
            @ob_flush();
        }

        @flush();
    }

    private function resolve_response_status(): int
    {
        $status_code = function_exists('http_response_code') ? (int)http_response_code() : 0;

        if ($status_code >= 100) {
            return $status_code;
        }

        if (function_exists('is_404') && is_404()) {
            return 404;
        }

        return 200;
    }

    private function sanitize_request_uri(): string
    {
        $uri = wp_unslash((string)($_SERVER['REQUEST_URI'] ?? '/'));
        $uri = trim($uri);

        if ($uri === '') {
            return '/';
        }

        return substr($uri, 0, $this->get_request_uri_max_length());
    }

    private function detect_request_profile(): array
    {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $route = $this->get_rest_route();
            $type = $this->is_media_route($route) ? 'media' : 'api';
            return array('type' => $type, 'label' => $this->limit_string($type . ':' . $route, 190));
        }

        if (wp_doing_ajax()) {
            $action = sanitize_key((string)($_REQUEST['action'] ?? 'admin_ajax'));
            $type = $this->is_media_ajax_action($action) ? 'media' : 'ajax';
            return array('type' => $type, 'label' => $this->limit_string($type . ':' . ($action ?: 'admin_ajax'), 190));
        }

        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return array('type' => 'xmlrpc', 'label' => 'xmlrpc');
        }

        $pagenow = $GLOBALS['pagenow'] ?? '';
        if ($pagenow === 'wp-login.php') {
            return array('type' => 'login', 'label' => 'login');
        }

        if (is_admin() || $this->is_admin_request_path()) {
            $screen_id = '';
            if (function_exists('get_current_screen')) {
                $screen = get_current_screen();
                $screen_id = is_object($screen) && !empty($screen->id) ? $screen->id : '';
            }

            $path = trim($this->get_request_path(), '/');
            $fallback = $path !== '' ? basename($path) : $pagenow;
            $screen = sanitize_key((string)($screen_id ?: ($_GET['page'] ?? $fallback ?: $pagenow)));
            return array('type' => 'admin', 'label' => $this->limit_string('admin:' . ($screen ?: 'dashboard'), 190));
        }

        if (is_front_page() || is_home()) {
            return array('type' => 'home', 'label' => is_front_page() ? 'front-page' : 'posts-home');
        }

        if (is_search()) {
            return array('type' => 'search', 'label' => 'search');
        }

        if (is_feed()) {
            return array('type' => 'feed', 'label' => 'feed');
        }

        if (is_404()) {
            return array('type' => '404', 'label' => '404');
        }

        if ($this->is_media_request_path()) {
            $path = trim($this->get_request_path(), '/');
            return array('type' => 'media', 'label' => $this->limit_string('media:' . ($path ?: 'asset'), 190));
        }

        if (is_attachment()) {
            $post = get_queried_object();
            $slug = is_object($post) && !empty($post->post_name) ? $post->post_name : 'attachment';
            return array('type' => 'media', 'label' => $this->limit_string('media:' . $slug, 190));
        }

        if (is_page()) {
            $post = get_queried_object();
            $slug = is_object($post) && !empty($post->post_name) ? $post->post_name : trim($this->get_request_path(), '/');
            return array('type' => 'page', 'label' => $this->limit_string('page:' . ($slug ?: 'page'), 190));
        }

        if (is_singular()) {
            $post_type = get_post_type() ?: 'single';
            return array('type' => 'single', 'label' => $this->limit_string('single:' . $post_type, 190));
        }

        if (is_archive()) {
            $archive_type = 'archive';

            if (is_category()) {
                $archive_type = 'archive:category';
            }
            elseif (is_tag()) {
                $archive_type = 'archive:tag';
            }
            elseif (is_tax()) {
                $queried = get_queried_object();
                $archive_type = 'archive:' . (is_object($queried) && !empty($queried->taxonomy) ? $queried->taxonomy : 'taxonomy');
            }
            elseif (is_post_type_archive()) {
                $post_type = get_query_var('post_type');
                $archive_type = 'archive:' . (is_array($post_type) ? reset($post_type) : $post_type);
            }
            elseif (is_author()) {
                $archive_type = 'archive:author';
            }
            elseif (is_date()) {
                $archive_type = 'archive:date';
            }

            return array('type' => 'archive', 'label' => $this->limit_string($archive_type, 190));
        }

        $path = trim($this->get_request_path(), '/');
        return array('type' => 'other', 'label' => $this->limit_string($path === '' ? 'other:root' : 'other:' . $path, 190));
    }

    private function get_rest_route(): string
    {
        $route = trim(sanitize_text_field((string)($_GET['rest_route'] ?? '')), '/');

        if ($route !== '') {
            return $this->limit_route_segments($route);
        }

        $path = trim($this->get_request_path(), '/');
        $path = preg_replace('#^wp-json/?#', '', $path);

        return $this->limit_route_segments($path === '' ? 'root' : $path);
    }

    private function get_request_path(): string
    {
        $uri = wp_parse_url($this->sanitize_request_uri(), PHP_URL_PATH);
        $uri = is_string($uri) ? $uri : '/';

        $base_path = wp_parse_url(home_url('/'), PHP_URL_PATH);
        $base_path = is_string($base_path) ? trim($base_path, '/') : '';

        $path = trim($uri, '/');
        if ($base_path !== '' && strpos($path, $base_path) === 0) {
            $path = ltrim(substr($path, strlen($base_path)), '/');
        }

        return '/' . $path;
    }

    private function limit_route_segments(string $route): string
    {
        $segments = array_values(array_filter(explode('/', trim($route, '/'))));
        return implode('/', array_slice($segments, 0, 4));
    }

    private function is_media_route(string $route): bool
    {
        return (bool)preg_match('#(^|/)(media|attachment|attachments)(/|$)#i', $route);
    }

    private function is_media_ajax_action(string $action): bool
    {
        if ($action === '') {
            return false;
        }

        return (bool)preg_match('#(upload|media|attachment|image|thumbnail|crop|edit-attachment)#i', $action);
    }

    private function is_media_request_path(): bool
    {
        $path = strtolower(trim($this->get_request_path(), '/'));

        if ($path === '') {
            return false;
        }

        if (strpos($path, 'wp-content/uploads/') === 0) {
            return true;
        }

        return (bool)preg_match('#\.(avif|bmp|gif|ico|jpeg|jpg|png|svg|webp|mp3|mp4|ogg|pdf|wav|webm)$#i', $path);
    }

    private function is_admin_request_path(): bool
    {
        $path = strtolower(trim($this->get_request_path(), '/'));

        if ($path === '') {
            return false;
        }

        return strpos($path, 'wp-admin/') === 0 || $path === 'wp-admin';
    }

    private function enable_query_capture(): void
    {
        global $wpdb;

        if (!defined('SAVEQUERIES')) {
            define('SAVEQUERIES', true);
        }

        if (!isset($wpdb) || !is_object($wpdb)) {
            return;
        }

        if (property_exists($wpdb, 'save_queries')) {
            $wpdb->save_queries = true;
        }

        if (property_exists($wpdb, 'queries') && !is_array($wpdb->queries)) {
            $wpdb->queries = array();
        }

        $this->query_capture_enabled = defined('SAVEQUERIES') && SAVEQUERIES;
    }

    private function collect_slow_query_samples(float $duration_ms, int $slow_threshold): array
    {
        $query_entries = $this->get_query_log_entries();

        if (!$this->query_capture_enabled || empty($query_entries)) {
            return array();
        }

        $all_queries = array();
        $threshold_hits = array();
        $capture_threshold = $this->get_slow_query_capture_threshold_ms($slow_threshold);
        $sample_limit = $this->get_slow_query_capture_limit();
        $per_signature_limit = $this->get_slow_query_per_signature_limit();
        $sql_query_limit = $this->get_slow_query_sql_max_length();
        $store_callers = $this->should_store_slow_query_callers();
        $caller_limit = $this->get_slow_query_caller_max_length();

        foreach ($query_entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $query = $this->normalize_sql_capture_string((string)($entry[0] ?? ''));
            $query_time_ms = max(0, round(((float)($entry[1] ?? 0)) * 1000, 3));

            if ($query === '' || $query_time_ms <= 0 || $this->should_ignore_monitored_query($query)) {
                continue;
            }

            $caller = trim((string)($entry[2] ?? ''));
            $caller = preg_replace('/\s+/', ' ', $caller);
            $fingerprint = $this->fingerprint_sql_query($query);

            $sample = array(
                'sql_signature'   => md5($fingerprint),
                'sql_fingerprint' => $fingerprint,
                'sql_query'       => $this->limit_string($query, $sql_query_limit),
                'query_time_ms'   => $query_time_ms,
                'query_caller'    => $store_callers ? $this->limit_string((string)$caller, $caller_limit) : '',
            );

            $this->push_slow_query_sample($all_queries, $sample, $sample_limit, $per_signature_limit);

            if ($capture_threshold <= 0 || $query_time_ms >= $capture_threshold) {
                $this->push_slow_query_sample($threshold_hits, $sample, $sample_limit, $per_signature_limit);
            }
        }

        if ($duration_ms >= $slow_threshold) {
            return $all_queries;
        }

        return $threshold_hits;
    }

    private function push_slow_query_sample(array &$samples, array $sample, int $limit = 5, int $per_signature_limit = 1): void
    {
        $signature = (string)($sample['sql_signature'] ?? '');
        $sample_time = (float)($sample['query_time_ms'] ?? 0);
        $same_signature_count = 0;
        $weakest_signature_index = null;
        $weakest_signature_time = null;

        foreach ($samples as $index => $existing) {
            if (($existing['sql_signature'] ?? '') === $signature) {
                $same_signature_count++;
                $existing_time = (float)($existing['query_time_ms'] ?? 0);

                if ($weakest_signature_time === null || $existing_time < $weakest_signature_time) {
                    $weakest_signature_index = $index;
                    $weakest_signature_time = $existing_time;
                }
            }
        }

        if ($signature !== '' && $per_signature_limit > 0 && $same_signature_count >= $per_signature_limit) {
            if ($weakest_signature_time !== null && $sample_time > $weakest_signature_time && $weakest_signature_index !== null) {
                array_splice($samples, $weakest_signature_index, 1);
            }
            else {
                return;
            }
        }

        $inserted = false;

        foreach ($samples as $index => $existing) {
            if ($sample_time > (float)($existing['query_time_ms'] ?? 0)) {
                array_splice($samples, $index, 0, array($sample));
                $inserted = true;
                break;
            }
        }

        if (!$inserted) {
            $samples[] = $sample;
        }

        if (count($samples) > $limit) {
            array_pop($samples);
        }
    }

    private function resolve_slow_query_threshold_ms(int $slow_threshold): float
    {
        return max(10.0, min(100.0, round($slow_threshold / 20, 3)));
    }

    private function normalize_sql_capture_string(string $query): string
    {
        $query = trim($query);
        $query = preg_replace('/\s+/', ' ', $query);

        return $this->limit_string((string)$query, 4000);
    }

    private function fingerprint_sql_query(string $query): string
    {
        $fingerprint = preg_replace("/'[^'\\\\]*(?:\\\\.[^'\\\\]*)*'/", '?', $query);
        $fingerprint = preg_replace('/"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"/', '?', (string)$fingerprint);
        $fingerprint = preg_replace('/\b0x[0-9a-f]+\b/i', '?', (string)$fingerprint);
        $fingerprint = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', (string)$fingerprint);
        $fingerprint = preg_replace('/\(\s*(?:\?\s*,\s*)+\?\s*\)/', '(?)', (string)$fingerprint);
        $fingerprint = preg_replace('/\s+/', ' ', trim((string)$fingerprint));

        return $this->limit_string((string)$fingerprint, 1500);
    }

    private function should_ignore_monitored_query(string $query): bool
    {
        $tables = array(
            defined('WPOPT_TABLE_REQUEST_PERFORMANCE') ? WPOPT_TABLE_REQUEST_PERFORMANCE : '',
            defined('WPOPT_TABLE_SLOW_QUERIES') ? WPOPT_TABLE_SLOW_QUERIES : '',
        );

        foreach (array_filter($tables) as $table) {
            if (stripos($query, $table) !== false) {
                return true;
            }
        }

        return false;
    }

    private function store_slow_query_samples(int $request_log_id, array $slow_queries, array $request_data): int
    {
        global $wpdb;

        if (empty($slow_queries) || !defined('WPOPT_TABLE_SLOW_QUERIES')) {
            return 0;
        }

        $chunk_size = 25;
        $insert_id = 0;
        $request_log_id = max(0, $request_log_id);
        $store_request_context_inline = $this->should_store_slow_query_request_context() || $request_log_id <= 0;
        $request_type = $store_request_context_inline ? (string)$request_data['request_type'] : '';
        $request_label = $store_request_context_inline ? (string)$request_data['request_label'] : '';
        $request_method = $store_request_context_inline ? (string)$request_data['request_method'] : '';
        $request_uri = $store_request_context_inline ? (string)$request_data['request_uri'] : '';

        foreach (array_chunk($slow_queries, $chunk_size) as $chunk) {
            $placeholders = array();
            $values = array();

            foreach ($chunk as $query) {
                $placeholders[] = '(%d, %d, %s, %s, %s, %s, %s, %s, %s, %f, %s, %s, %s)';
                array_push(
                    $values,
                    $request_log_id,
                    (int)$request_data['blog_id'],
                    $request_type,
                    $request_label,
                    $request_method,
                    $request_uri,
                    (string)$query['sql_signature'],
                    (string)$query['sql_fingerprint'],
                    (string)$query['sql_query'],
                    (float)$query['query_time_ms'],
                    (string)$query['query_caller'],
                    (string)$request_data['created_at'],
                    (string)$request_data['created_at_gmt']
                );
            }

            $sql = 'INSERT INTO ' . WPOPT_TABLE_SLOW_QUERIES . ' (request_log_id, blog_id, request_type, request_label, request_method, request_uri, sql_signature, sql_fingerprint, sql_query, query_time_ms, query_caller, created_at, created_at_gmt) VALUES ' . implode(', ', $placeholders);
            $wpdb->query($wpdb->prepare($sql, $values));
            $insert_id = max($insert_id, (int)$wpdb->insert_id);
        }

        return $insert_id;
    }

    private function prune_table_to_row_limit(string $table, int $max_rows): int
    {
        global $wpdb;

        if ($max_rows < 1 || $table === '') {
            return 0;
        }

        $cutoff_id = (int)$wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . $table . ' ORDER BY id DESC LIMIT 1 OFFSET %d',
                max(0, $max_rows - 1)
            )
        );

        if ($cutoff_id <= 0) {
            return 0;
        }

        return (int)$wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . $table . ' WHERE id < %d',
                $cutoff_id
            )
        );
    }

    private function cleanup_history(): int
    {
        global $wpdb;

        $threshold = $this->get_monitor_from_gmt();
        $deleted_rows = (int)$wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . WPOPT_TABLE_SLOW_QUERIES . ' WHERE created_at_gmt IS NOT NULL AND created_at_gmt < %s',
                $threshold
            )
        );

        $deleted_rows += (int)$wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . WPOPT_TABLE_REQUEST_PERFORMANCE . ' WHERE created_at_gmt IS NOT NULL AND created_at_gmt < %s',
                $threshold
            )
        );

        $deleted_rows += $this->prune_table_to_row_limit(WPOPT_TABLE_SLOW_QUERIES, self::MAX_SLOW_QUERY_ROWS);
        $deleted_rows += $this->prune_table_to_row_limit(WPOPT_TABLE_REQUEST_PERFORMANCE, self::MAX_REQUEST_HISTORY_ROWS);
        $this->mark_cleanup_history_checked();

        return $deleted_rows;
    }

    private function maybe_cleanup_history(): void
    {
        if (!$this->should_cleanup_history()) {
            return;
        }

        $this->cleanup_history();
    }

    private function should_cleanup_history(): bool
    {
        return false === get_transient('wpopt_perf_monitor_cleanup_lock');
    }

    private function mark_cleanup_history_checked(): void
    {
        set_transient('wpopt_perf_monitor_cleanup_lock', time(), HOUR_IN_SECONDS);
    }

    private function get_monitor_window(): array
    {
        return array('label' => __('Last 24 hours', 'wpopt'), 'seconds' => DAY_IN_SECONDS, 'bucket' => 'hour', 'count' => 24);
    }

    private function get_monitor_from_gmt(): string
    {
        return gmdate('Y-m-d H:i:s', time() - $this->get_monitor_window()['seconds']);
    }

    private function get_monitor_snapshot(array $sections = array()): array
    {
        if (!isset($this->monitor_snapshot['window'])) {
            $this->maybe_cleanup_history();

            $window = $this->get_monitor_window();
            $from_gmt = $this->get_monitor_from_gmt();

            $this->monitor_snapshot = array(
                'window'               => $window,
                'from_gmt'             => $from_gmt,
                'overview_enabled'     => $this->is_overview_panel_enabled(),
                'slow_queries_enabled' => $this->is_slow_queries_panel_enabled(),
                'cache_enabled'        => $this->is_cache_panel_enabled(),
                'components_enabled'   => $this->is_components_panel_enabled(),
            );
        }

        $sections = array_values(array_unique(array_filter(array_map('strval', $sections))));

        if (empty($sections)) {
            $sections = array('overview', 'slow_queries', 'cache', 'components');
        }

        foreach ($sections as $section) {
            switch ($section) {
                case 'overview':
                    if (isset($this->monitor_snapshot['summary'])) {
                        break;
                    }

                    if (empty($this->monitor_snapshot['overview_enabled'])) {
                        $this->monitor_snapshot['summary'] = $this->get_empty_summary();
                        $this->monitor_snapshot['types'] = array();
                        $this->monitor_snapshot['snapshot_types'] = array();
                        $this->monitor_snapshot['labels'] = array();
                        $this->monitor_snapshot['recent'] = array();
                        $this->monitor_snapshot['type_series'] = $this->get_empty_time_series();
                        $this->monitor_snapshot['series'] = $this->get_empty_time_series();
                        break;
                    }

                    $from_gmt = (string)$this->monitor_snapshot['from_gmt'];
                    $window = (array)$this->monitor_snapshot['window'];
                    $types = $this->get_request_type_breakdown($from_gmt);
                    $snapshot_types = $this->normalize_type_rows($types);

                    $this->monitor_snapshot['summary'] = $this->get_summary($from_gmt);
                    $this->monitor_snapshot['types'] = $types;
                    $this->monitor_snapshot['snapshot_types'] = $snapshot_types;
                    $this->monitor_snapshot['labels'] = $this->get_request_label_breakdown($from_gmt);
                    $this->monitor_snapshot['recent'] = $this->get_recent_requests($from_gmt, 20);
                    $this->monitor_snapshot['type_series'] = $this->get_time_series($window, $from_gmt, array_column($snapshot_types, 'request_type'));
                    $this->monitor_snapshot['series'] = $this->get_time_series($window, $from_gmt, array_slice(array_column($types, 'request_type'), 0, 4));
                    break;

                case 'slow_queries':
                    if (isset($this->monitor_snapshot['slow_queries'])) {
                        break;
                    }

                    $this->monitor_snapshot['slow_queries'] = !empty($this->monitor_snapshot['slow_queries_enabled'])
                        ? $this->get_slow_query_breakdown((string)$this->monitor_snapshot['from_gmt'], $this->get_slow_query_display_limit())
                        : array();
                    break;

                case 'cache':
                    if (isset($this->monitor_snapshot['cache'])) {
                        break;
                    }

                    $this->monitor_snapshot['cache'] = !empty($this->monitor_snapshot['cache_enabled'])
                        ? $this->get_cache_snapshot((array)$this->monitor_snapshot['window'], (string)$this->monitor_snapshot['from_gmt'])
                        : $this->get_empty_cache_snapshot();
                    break;

                case 'components':
                    if (isset($this->monitor_snapshot['components'])) {
                        break;
                    }

                    $this->monitor_snapshot['components'] = !empty($this->monitor_snapshot['components_enabled'])
                        ? $this->get_component_snapshot((string)$this->monitor_snapshot['from_gmt'])
                        : $this->get_empty_component_snapshot();
                    break;
            }
        }

        return $this->monitor_snapshot;
    }

    private function get_empty_summary(): array
    {
        return array(
            'total_requests'  => 0,
            'avg_ms'          => 0.0,
            'max_ms'          => 0.0,
            'avg_memory_peak' => 0.0,
            'avg_queries'     => 0.0,
            'slow_hits'       => 0,
        );
    }

    private function get_empty_time_series(): array
    {
        return array(
            'labels' => array(),
            'series' => array(),
        );
    }

    private function get_empty_cache_snapshot(): array
    {
        $summary = array(
            'tracked_requests' => 0,
            'total_hits'       => 0,
            'total_misses'     => 0,
            'total_operations' => 0,
            'hit_ratio'        => 0.0,
            'db_hits'          => 0,
            'db_misses'        => 0,
            'db_total'         => 0,
            'db_ratio'         => 0.0,
            'query_hits'       => 0,
            'query_misses'     => 0,
            'query_total'      => 0,
            'query_ratio'      => 0.0,
            'started_at'       => '',
            'updated_at'       => '',
        );

        return array(
            'summary' => $summary,
            'layers'  => $this->build_cache_layers($summary),
        );
    }

    private function get_empty_component_snapshot(): array
    {
        return array(
            'summary' => $this->calculate_component_snapshot_summary(array()),
            'rows'    => array(),
        );
    }

    private function get_summary(string $from_gmt): array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COUNT(*) AS total_requests, AVG(response_time_ms) AS avg_ms, MAX(response_time_ms) AS max_ms, AVG(memory_peak) AS avg_memory_peak, AVG(query_count) AS avg_queries, SUM(is_slow) AS slow_hits FROM ' . WPOPT_TABLE_REQUEST_PERFORMANCE . ' WHERE created_at_gmt >= %s',
                $from_gmt
            ),
            ARRAY_A
        );

        return array(
            'total_requests'  => absint($row['total_requests'] ?? 0),
            'avg_ms'          => (float)($row['avg_ms'] ?? 0),
            'max_ms'          => (float)($row['max_ms'] ?? 0),
            'avg_memory_peak' => (float)($row['avg_memory_peak'] ?? 0),
            'avg_queries'     => (float)($row['avg_queries'] ?? 0),
            'slow_hits'       => absint($row['slow_hits'] ?? 0),
        );
    }

    private function get_cache_snapshot(array $window, string $from_gmt): array
    {
        unset($window, $from_gmt);

        $summary = $this->get_cache_summary();

        return array(
            'summary' => $summary,
            'layers'  => $this->build_cache_layers($summary),
        );
    }

    private function get_component_snapshot(string $from_gmt): array
    {
        $catalog = $this->get_component_catalog();
        $rows = array();

        foreach ($catalog as $component) {
            $rows[$component['key']] = $this->create_component_metric_row($component);
        }

        if ($this->supports_component_profile_column()) {
            global $wpdb;

            $last_request_id = 0;
            $batch_size = 200;

            do {
                $profiles = (array)$wpdb->get_results(
                    $wpdb->prepare(
                        'SELECT id, component_profile_json FROM ' . WPOPT_TABLE_REQUEST_PERFORMANCE . ' WHERE created_at_gmt >= %s AND component_profile_json IS NOT NULL AND component_profile_json <> "" AND id > %d ORDER BY id ASC LIMIT %d',
                        $from_gmt,
                        $last_request_id,
                        $batch_size
                    ),
                    ARRAY_A
                );

                foreach ($profiles as $profile_row) {
                    $last_request_id = max($last_request_id, (int)($profile_row['id'] ?? 0));
                    $profile = json_decode((string)($profile_row['component_profile_json'] ?? ''), true);

                    if (!is_array($profile)) {
                        continue;
                    }

                    foreach ($profile as $component_key => $component_row) {
                        if (!is_array($component_row)) {
                            continue;
                        }

                        $component_key = (string)($component_row['key'] ?? $component_key);
                        $component = $rows[$component_key] ?? $this->create_component_metric_row($this->hydrate_component_from_metric_row($component_row));
                        $requests = max(1, absint($component_row['requests'] ?? 1));

                        $component['label'] = (string)($component_row['label'] ?? $component['label']);
                        $component['type'] = (string)($component_row['type'] ?? $component['type']);
                        $component['slug'] = (string)($component_row['slug'] ?? $component['slug']);
                        $component['version'] = (string)($component_row['version'] ?? $component['version']);
                        $component['author'] = (string)($component_row['author'] ?? $component['author']);
                        $component['author_url'] = (string)($component_row['author_url'] ?? $component['author_url']);
                        $component['site_url'] = (string)($component_row['site_url'] ?? $component['site_url']);
                        $component['description'] = (string)($component_row['description'] ?? $component['description']);
                        $component['load_time_ms'] += (float)($component_row['load_time_ms'] ?? 0);
                        $component['callback_time_ms'] += (float)($component_row['callback_time_ms'] ?? 0);
                        $component['sql_time_ms'] += (float)($component_row['sql_time_ms'] ?? 0);
                        $component['observed_time_ms'] += (float)($component_row['observed_time_ms'] ?? $this->calculate_component_observed_time($component_row));
                        $component['query_count'] += (float)absint($component_row['query_count'] ?? 0);
                        $component['callback_calls'] += (float)absint($component_row['callback_calls'] ?? 0);
                        $component['memory_allocated'] += (int)($component_row['memory_allocated'] ?? 0);
                        $component['peak_memory'] += max(0, (int)($component_row['peak_memory'] ?? 0));
                        $component['file_count'] = max((int)$component['file_count'], (int)($component_row['file_count'] ?? 0));
                        $component['file_bytes'] = max((int)$component['file_bytes'], (int)($component_row['file_bytes'] ?? 0));
                        $component['requests'] += $requests;
                        $rows[$component_key] = $component;
                    }
                }
            }
            while (count($profiles) === $batch_size);
        }

        foreach ($rows as &$row) {
            $row = $this->normalize_component_snapshot_row($row);
        }
        unset($row);

        $rows = array_filter($rows, static function (array $row): bool {
            if (($row['type'] ?? '') === 'plugin' && empty($row['is_active'])) {
                return false;
            }

            return true;
        });

        uasort($rows, static function (array $left, array $right): int {
            $time_compare = (float)$right['observed_time_ms'] <=> (float)$left['observed_time_ms'];

            if ($time_compare !== 0) {
                return $time_compare;
            }

            return strcasecmp((string)$left['label'], (string)$right['label']);
        });

        $summary = $this->calculate_component_snapshot_summary($rows);

        return array(
            'summary' => $summary,
            'rows'    => array_values($rows),
        );
    }

    private function get_cache_summary(): array
    {
        $metrics = $this->get_cumulative_cache_metrics();

        return array(
            'tracked_requests' => absint($metrics['tracked_requests'] ?? 0),
            'total_hits'       => absint($metrics['cache_hits'] ?? 0),
            'total_misses'     => absint($metrics['cache_misses'] ?? 0),
            'total_operations' => absint($metrics['cache_hits'] ?? 0) + absint($metrics['cache_misses'] ?? 0),
            'hit_ratio'        => $this->calculate_ratio(absint($metrics['cache_hits'] ?? 0), absint($metrics['cache_misses'] ?? 0)),
            'db_hits'          => absint($metrics['db_cache_hits'] ?? 0),
            'db_misses'        => absint($metrics['db_cache_misses'] ?? 0),
            'db_total'         => absint($metrics['db_cache_hits'] ?? 0) + absint($metrics['db_cache_misses'] ?? 0),
            'db_ratio'         => $this->calculate_ratio(absint($metrics['db_cache_hits'] ?? 0), absint($metrics['db_cache_misses'] ?? 0)),
            'query_hits'       => absint($metrics['query_cache_hits'] ?? 0),
            'query_misses'     => absint($metrics['query_cache_misses'] ?? 0),
            'query_total'      => absint($metrics['query_cache_hits'] ?? 0) + absint($metrics['query_cache_misses'] ?? 0),
            'query_ratio'      => $this->calculate_ratio(absint($metrics['query_cache_hits'] ?? 0), absint($metrics['query_cache_misses'] ?? 0)),
            'started_at'       => sanitize_text_field((string)($metrics['started_at'] ?? '')),
            'updated_at'       => sanitize_text_field((string)($metrics['updated_at'] ?? '')),
        );
    }

    private function build_cache_layers(array $summary): array
    {
        return array(
            array(
                'label'   => __('Database cache', 'wpopt'),
                'hits'    => $summary['db_hits'],
                'misses'  => $summary['db_misses'],
                'total'   => $summary['db_total'],
                'ratio'   => $summary['db_ratio'],
                'context' => __('WP DB drop-in results reused from storage.', 'wpopt'),
            ),
            array(
                'label'   => __('WP_Query cache', 'wpopt'),
                'hits'    => $summary['query_hits'],
                'misses'  => $summary['query_misses'],
                'total'   => $summary['query_total'],
                'ratio'   => $summary['query_ratio'],
                'context' => __('Cached post query results served before SQL execution.', 'wpopt'),
            ),
        );
    }

    private function get_empty_cache_metrics(): array
    {
        return array(
            'cache_hits'         => 0,
            'cache_misses'       => 0,
            'db_cache_hits'      => 0,
            'db_cache_misses'    => 0,
            'query_cache_hits'   => 0,
            'query_cache_misses' => 0,
        );
    }

    private function get_cumulative_cache_metrics(): array
    {
        $metrics = function_exists('get_option')
            ? get_option('wpopt_perf_cache_cumulative', array())
            : array();

        if (!is_array($metrics)) {
            $metrics = array();
        }

        $metrics = array_merge(
            $this->get_empty_cache_metrics(),
            array(
                'tracked_requests' => 0,
                'started_at'       => '',
                'updated_at'       => '',
            ),
            $metrics
        );

        $metrics['db_cache_hits'] = max(0, absint($metrics['db_cache_hits']));
        $metrics['db_cache_misses'] = max(0, absint($metrics['db_cache_misses']));
        $metrics['query_cache_hits'] = max(0, absint($metrics['query_cache_hits']));
        $metrics['query_cache_misses'] = max(0, absint($metrics['query_cache_misses']));
        $metrics['cache_hits'] = $metrics['db_cache_hits'] + $metrics['query_cache_hits'];
        $metrics['cache_misses'] = $metrics['db_cache_misses'] + $metrics['query_cache_misses'];
        $metrics['tracked_requests'] = max(0, absint($metrics['tracked_requests']));
        $metrics['started_at'] = sanitize_text_field((string)$metrics['started_at']);
        $metrics['updated_at'] = sanitize_text_field((string)$metrics['updated_at']);

        if ($metrics['started_at'] === '' && $metrics['cache_hits'] + $metrics['cache_misses'] > 0) {
            $metrics['started_at'] = $metrics['updated_at'];
        }

        return $metrics;
    }

    private function persist_cumulative_cache_metrics(array $request_metrics): void
    {
        if (!function_exists('update_option')) {
            return;
        }

        $request_metrics = array_merge($this->get_empty_cache_metrics(), $request_metrics);
        $request_metrics['db_cache_hits'] = max(0, absint($request_metrics['db_cache_hits']));
        $request_metrics['db_cache_misses'] = max(0, absint($request_metrics['db_cache_misses']));
        $request_metrics['query_cache_hits'] = max(0, absint($request_metrics['query_cache_hits']));
        $request_metrics['query_cache_misses'] = max(0, absint($request_metrics['query_cache_misses']));
        $request_metrics['cache_hits'] = $request_metrics['db_cache_hits'] + $request_metrics['query_cache_hits'];
        $request_metrics['cache_misses'] = $request_metrics['db_cache_misses'] + $request_metrics['query_cache_misses'];

        if (($request_metrics['cache_hits'] + $request_metrics['cache_misses']) <= 0) {
            return;
        }

        $metrics = $this->get_cumulative_cache_metrics();
        $now = current_time('mysql');

        $metrics['db_cache_hits'] += $request_metrics['db_cache_hits'];
        $metrics['db_cache_misses'] += $request_metrics['db_cache_misses'];
        $metrics['query_cache_hits'] += $request_metrics['query_cache_hits'];
        $metrics['query_cache_misses'] += $request_metrics['query_cache_misses'];
        $metrics['cache_hits'] = $metrics['db_cache_hits'] + $metrics['query_cache_hits'];
        $metrics['cache_misses'] = $metrics['db_cache_misses'] + $metrics['query_cache_misses'];
        $metrics['tracked_requests'] += 1;
        $metrics['started_at'] = $metrics['started_at'] !== '' ? $metrics['started_at'] : $now;
        $metrics['updated_at'] = $now;

        update_option('wpopt_perf_cache_cumulative', $metrics, false);
    }

    private function reset_cumulative_cache_metrics(): void
    {
        if (function_exists('delete_option')) {
            delete_option('wpopt_perf_cache_cumulative');
        }
    }

    private function supports_component_profile_column(): bool
    {
        static $supports = null;

        if (is_bool($supports)) {
            return $supports;
        }

        global $wpdb;

        if (!defined('WPOPT_TABLE_REQUEST_PERFORMANCE')) {
            $supports = false;
            return $supports;
        }

        $supports = !empty($wpdb->get_var(
            $wpdb->prepare(
                'SHOW COLUMNS FROM ' . WPOPT_TABLE_REQUEST_PERFORMANCE . ' LIKE %s',
                'component_profile_json'
            )
        ));

        return $supports;
    }

    private function build_request_component_profile_json(): string
    {
        if (!$this->component_profiler_enabled) {
            return '';
        }

        $profile = $this->build_request_component_profile();

        if (empty($profile)) {
            return '';
        }

        return (string)wp_json_encode($profile, JSON_UNESCAPED_SLASHES);
    }

    private function build_request_component_profile(): array
    {
        $this->merge_query_component_metrics();

        $profile = array();

        foreach ($this->component_metrics as $component_key => $row) {
            if (!is_array($row)) {
                continue;
            }

            $row['load_time_ms'] = round((float)($row['load_time_ms'] ?? 0), 3);
            $row['callback_time_ms'] = round((float)($row['callback_time_ms'] ?? 0), 3);
            $row['sql_time_ms'] = round((float)($row['sql_time_ms'] ?? 0), 3);
            $row['observed_time_ms'] = round($this->calculate_component_observed_time($row), 3);
            $row['query_count'] = absint($row['query_count'] ?? 0);
            $row['callback_calls'] = absint($row['callback_calls'] ?? 0);
            $row['memory_allocated'] = max(0, (int)($row['memory_allocated'] ?? 0));
            $row['peak_memory'] = max(0, (int)($row['peak_memory'] ?? 0));
            $row['file_count'] = max(0, (int)($row['file_count'] ?? 0));
            $row['file_bytes'] = max(0, (int)($row['file_bytes'] ?? 0));
            $row['requests'] = max(1, absint($row['requests'] ?? 1));

            if (
                $row['observed_time_ms'] <= 0
                && $row['query_count'] <= 0
                && $row['file_count'] <= 0
                && $row['memory_allocated'] <= 0
                && $row['peak_memory'] <= 0
            ) {
                continue;
            }

            $profile[$component_key] = $row;
        }

        uasort($profile, static function (array $left, array $right): int {
            $time_compare = (float)$right['observed_time_ms'] <=> (float)$left['observed_time_ms'];

            if ($time_compare !== 0) {
                return $time_compare;
            }

            return strcasecmp((string)$left['label'], (string)$right['label']);
        });

        return $profile;
    }

    private function calculate_component_observed_time(array $row): float
    {
        $load_time = max(0.0, (float)($row['load_time_ms'] ?? 0));
        $runtime_time = max(
            max(0.0, (float)($row['callback_time_ms'] ?? 0)),
            max(0.0, (float)($row['sql_time_ms'] ?? 0))
        );

        return round($load_time + $runtime_time, 3);
    }

    private function normalize_component_snapshot_row(array $row): array
    {
        $requests = max(0, absint($row['requests'] ?? 0));

        if ($requests <= 0) {
            $row['observed_time_ms'] = 0.0;
            $row['query_count'] = 0.0;
            $row['callback_calls'] = 0.0;
            $row['memory_allocated'] = 0;
            $row['peak_memory'] = 0;
            return $row;
        }

        $row['load_time_ms'] = round(((float)($row['load_time_ms'] ?? 0)) / $requests, 3);
        $row['callback_time_ms'] = round(((float)($row['callback_time_ms'] ?? 0)) / $requests, 3);
        $row['sql_time_ms'] = round(((float)($row['sql_time_ms'] ?? 0)) / $requests, 3);
        $row['observed_time_ms'] = round(((float)($row['observed_time_ms'] ?? 0)) / $requests, 3);
        $row['query_count'] = round(((float)($row['query_count'] ?? 0)) / $requests, 2);
        $row['callback_calls'] = round(((float)($row['callback_calls'] ?? 0)) / $requests, 2);
        $row['memory_allocated'] = max(0, (int)round(((float)($row['memory_allocated'] ?? 0)) / $requests));
        $row['peak_memory'] = max(0, (int)round(((float)($row['peak_memory'] ?? 0)) / $requests));

        return $row;
    }

    private function calculate_component_snapshot_summary(array $rows): array
    {
        $total_requests = 0;
        $observed_time_total = 0.0;
        $query_total = 0.0;
        $peak_memory_total = 0.0;

        foreach ($rows as $row) {
            $requests = max(0, absint($row['requests'] ?? 0));

            if ($requests <= 0) {
                continue;
            }

            $total_requests += $requests;
            $observed_time_total += (float)($row['observed_time_ms'] ?? 0) * $requests;
            $query_total += (float)($row['query_count'] ?? 0) * $requests;
            $peak_memory_total += (float)($row['peak_memory'] ?? 0) * $requests;
        }

        return array(
            'components'       => count($rows),
            'plugins'          => count(array_filter($rows, static fn(array $row): bool => $row['type'] === 'plugin')),
            'themes'           => count(array_filter($rows, static fn(array $row): bool => $row['type'] === 'theme')),
            'observed_time_ms' => $total_requests > 0 ? round($observed_time_total / $total_requests, 3) : 0.0,
            'query_count'      => $total_requests > 0 ? round($query_total / $total_requests, 2) : 0.0,
            'peak_memory'      => $total_requests > 0 ? (int)round($peak_memory_total / $total_requests) : 0,
        );
    }

    private function merge_query_component_metrics(): void
    {
        $query_entries = $this->get_query_log_entries();

        if (empty($query_entries)) {
            return;
        }

        $catalog = $this->get_component_catalog();

        foreach ($query_entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $query = (string)($entry[0] ?? '');
            $query_data = is_array($entry[4] ?? null) ? $entry[4] : array();
            $component_key = (string)($query_data['wpopt_component_key'] ?? '');

            if ($component_key === '' || $this->should_ignore_monitored_query($query)) {
                continue;
            }

            $component = $catalog[$component_key] ?? $this->hydrate_component_from_metric_row($query_data);
            $this->touch_component_metric($component);

            $this->component_metrics[$component_key]['sql_time_ms'] += round(((float)($entry[1] ?? 0)) * 1000, 3);
            $this->component_metrics[$component_key]['query_count'] += 1;
            $this->component_metrics[$component_key]['requests'] = max(1, (int)$this->component_metrics[$component_key]['requests']);
        }
    }

    public function snapshot_query_log(): void
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !isset($wpdb->queries) || !is_array($wpdb->queries) || empty($wpdb->queries)) {
            return;
        }

        $this->captured_query_snapshot = array_values((array)$wpdb->queries);
    }

    private function capture_query_entry(string $query, float $query_time, string $query_callstack, float $query_start, array $query_data = array()): void
    {
        if (!$this->query_capture_enabled) {
            return;
        }

        $this->captured_query_entries[] = array(
            $query,
            $query_time,
            $query_callstack,
            $query_start,
            $query_data,
        );
    }

    private function get_query_log_entries(): array
    {
        global $wpdb;

        if (!empty($this->captured_query_entries)) {
            return $this->captured_query_entries;
        }

        if (!empty($this->captured_query_snapshot)) {
            return $this->captured_query_snapshot;
        }

        if (isset($wpdb) && is_object($wpdb) && isset($wpdb->queries) && is_array($wpdb->queries) && !empty($wpdb->queries)) {
            return array_values((array)$wpdb->queries);
        }

        return array();
    }

    private function apply_included_component_footprint(): void
    {
        foreach ((array)get_included_files() as $file) {
            $component = $this->resolve_component_from_file((string)$file);

            if (empty($component)) {
                continue;
            }

            $component_key = $component['key'];
            $this->touch_component_metric($component);
            $this->component_metrics[$component_key]['file_count'] += 1;

            $normalized_file = $this->normalize_path_for_matching((string)$file);

            if ($normalized_file !== '' && is_file($normalized_file) && is_readable($normalized_file)) {
                $this->component_metrics[$component_key]['file_bytes'] += (int)filesize($normalized_file);
            }
        }
    }

    private function capture_component_load_footprint(array $preferred_keys = array()): void
    {
        if (empty($this->component_load_checkpoint)) {
            $this->mark_component_load_checkpoint();
            return;
        }

        $current_files = $this->get_normalized_included_files();
        $previous_files = is_array($this->component_load_checkpoint['files'] ?? null)
            ? $this->component_load_checkpoint['files']
            : array();
        $new_files = array_values(array_diff($current_files, $previous_files));
        $current_memory = memory_get_usage(true);
        $current_peak = memory_get_peak_usage(true);
        $started_at = (float)($this->component_load_checkpoint['started_at'] ?? microtime(true));
        $baseline_memory = (int)($this->component_load_checkpoint['memory'] ?? $current_memory);
        $baseline_peak = (int)($this->component_load_checkpoint['peak'] ?? $current_peak);
        $elapsed_ms = max(0, round((microtime(true) - $started_at) * 1000, 3));
        $delta_memory = max(0, $current_memory - $baseline_memory);
        $delta_peak = max($delta_memory, max(0, $current_peak - $baseline_peak));
        $preferred_keys = array_values(array_unique(array_filter(array_map('strval', $preferred_keys))));
        $resolved_components = array();
        $has_ambiguous_files = false;

        foreach ($new_files as $file) {
            $component = $this->resolve_component_from_file((string)$file);

            if (empty($component)) {
                $has_ambiguous_files = true;
                break;
            }

            $component_key = (string)($component['key'] ?? '');

            if ($component_key === '' || (!empty($preferred_keys) && !in_array($component_key, $preferred_keys, true))) {
                $has_ambiguous_files = true;
                break;
            }

            $resolved_components[$component_key] = $component;
        }

        if (!$has_ambiguous_files && count($resolved_components) === 1) {
            $component_key = (string)array_key_first($resolved_components);
            $component = $resolved_components[$component_key];

            $this->touch_component_metric($component);
            $this->component_metrics[$component_key]['load_time_ms'] += $elapsed_ms;
            $this->component_metrics[$component_key]['memory_allocated'] += $delta_memory;
            $this->component_metrics[$component_key]['peak_memory'] = max(
                (int)$this->component_metrics[$component_key]['peak_memory'],
                $delta_peak,
                $delta_memory
            );
            $this->component_metrics[$component_key]['file_count'] += count($new_files);
            $this->component_metrics[$component_key]['file_bytes'] += $this->sum_file_sizes($new_files);
            $this->component_metrics[$component_key]['requests'] = max(1, (int)$this->component_metrics[$component_key]['requests']);
        }

        $this->component_load_checkpoint = array(
            'started_at' => microtime(true),
            'memory'     => $current_memory,
            'peak'       => $current_peak,
            'files'      => $current_files,
        );
    }

    private function get_normalized_included_files(): array
    {
        $files = array();

        foreach ((array)get_included_files() as $file) {
            $normalized_file = $this->normalize_path_for_matching((string)$file);

            if ($normalized_file !== '') {
                $files[] = $normalized_file;
            }
        }

        return array_values(array_unique($files));
    }

    private function sum_file_sizes(array $files): int
    {
        $total = 0;

        foreach ($files as $file) {
            $total += $this->get_file_size((string)$file);
        }

        return $total;
    }

    private function get_file_size(string $file): int
    {
        $file = $this->normalize_path_for_matching($file);

        if ($file === '') {
            return 0;
        }

        if (array_key_exists($file, $this->file_size_cache)) {
            return (int)$this->file_size_cache[$file];
        }

        $size = @filesize($file);
        $this->file_size_cache[$file] = $size !== false ? max(0, (int)$size) : 0;

        return (int)$this->file_size_cache[$file];
    }

    private function create_component_metric_row(array $component): array
    {
        return array(
            'key'              => (string)($component['key'] ?? ''),
            'type'             => (string)($component['type'] ?? 'plugin'),
            'slug'             => (string)($component['slug'] ?? ''),
            'label'            => (string)($component['label'] ?? ''),
            'version'          => (string)($component['version'] ?? ''),
            'author'           => (string)($component['author'] ?? ''),
            'author_url'       => esc_url_raw((string)($component['author_url'] ?? '')),
            'site_url'         => esc_url_raw((string)($component['site_url'] ?? '')),
            'description'      => sanitize_text_field((string)($component['description'] ?? '')),
            'is_active'        => !empty($component['is_active']) ? 1 : 0,
            'load_time_ms'     => 0.0,
            'callback_time_ms' => 0.0,
            'sql_time_ms'      => 0.0,
            'observed_time_ms' => 0.0,
            'query_count'      => 0,
            'callback_calls'   => 0,
            'memory_allocated' => 0,
            'peak_memory'      => 0,
            'file_count'       => 0,
            'file_bytes'       => 0,
            'requests'         => 0,
        );
    }

    private function hydrate_component_from_metric_row(array $row): array
    {
        return array(
            'key'       => (string)($row['key'] ?? ''),
            'type'      => (string)($row['type'] ?? 'plugin'),
            'slug'      => (string)($row['slug'] ?? ''),
            'label'     => (string)($row['label'] ?? $row['slug'] ?? __('Unknown component', 'wpopt')),
            'version'   => (string)($row['version'] ?? ''),
            'author'    => sanitize_text_field((string)($row['author'] ?? '')),
            'author_url'=> esc_url_raw((string)($row['author_url'] ?? '')),
            'site_url'  => esc_url_raw((string)($row['site_url'] ?? '')),
            'description' => sanitize_text_field((string)($row['description'] ?? '')),
            'is_active' => !empty($row['is_active']) ? 1 : 0,
        );
    }

    private function touch_component_metric(array $component): void
    {
        $component_key = (string)($component['key'] ?? '');

        if ($component_key === '') {
            return;
        }

        if (!isset($this->component_metrics[$component_key])) {
            $this->component_metrics[$component_key] = $this->create_component_metric_row($component);
        }
    }

    private function get_component_catalog(): array
    {
        if (!empty($this->component_catalog)) {
            return $this->component_catalog;
        }

        $catalog = array();
        $matchers = array();

        foreach ($this->get_active_plugin_components() as $component) {
            $catalog[$component['key']] = $component;
            if (!empty($component['is_active'])) {
                $matchers[] = array(
                    'key'  => $component['key'],
                    'mode' => $component['match_mode'],
                    'path' => $component['match_path'],
                );
            }
        }

        foreach ($this->get_active_theme_components() as $component) {
            $catalog[$component['key']] = $component;
            if (!empty($component['is_active'])) {
                $matchers[] = array(
                    'key'  => $component['key'],
                    'mode' => $component['match_mode'],
                    'path' => $component['match_path'],
                );
            }
        }

        usort($matchers, static function (array $left, array $right): int {
            return strlen((string)$right['path']) <=> strlen((string)$left['path']);
        });

        $this->component_catalog = $catalog;
        $this->component_matchers = $matchers;

        return $this->component_catalog;
    }

    private function get_active_plugin_components(): array
    {
        if (!function_exists('get_plugins') && defined('ABSPATH')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = function_exists('get_plugins') ? (array)get_plugins() : array();
        $active_plugins = array_map('strval', (array)get_option('active_plugins', array()));

        if (function_exists('wp_get_active_network_plugins')) {
            foreach ((array)wp_get_active_network_plugins() as $network_plugin) {
                $active_plugins[] = plugin_basename((string)$network_plugin);
            }
        }

        $active_plugins = array_values(array_unique(array_filter($active_plugins)));
        $components = array();

        foreach ($all_plugins as $plugin_file => $metadata) {
            $metadata = $all_plugins[$plugin_file] ?? array();
            $main_file = $this->normalize_path_for_matching(WP_PLUGIN_DIR . '/' . ltrim($plugin_file, '/\\'));
            $relative_dir = trim(str_replace('\\', '/', dirname($plugin_file)), './');
            $plugin_slug = $relative_dir !== '' ? $relative_dir : sanitize_title(pathinfo($plugin_file, PATHINFO_FILENAME));
            $is_single_file = $relative_dir === '';

            $components[] = array(
                'key'        => 'plugin:' . $plugin_file,
                'type'       => 'plugin',
                'slug'       => $plugin_slug,
                'label'      => (string)($metadata['Name'] ?? ucwords(str_replace(array('-', '_'), ' ', $plugin_slug))),
                'version'    => (string)($metadata['Version'] ?? ''),
                'author'     => sanitize_text_field((string)($metadata['AuthorName'] ?? wp_strip_all_tags((string)($metadata['Author'] ?? '')))),
                'author_url' => esc_url_raw((string)($metadata['AuthorURI'] ?? '')),
                'site_url'   => esc_url_raw((string)($metadata['PluginURI'] ?? '')),
                'description'=> sanitize_text_field(wp_strip_all_tags((string)($metadata['Description'] ?? ''))),
                'is_active'  => in_array($plugin_file, $active_plugins, true) ? 1 : 0,
                'match_mode' => $is_single_file ? 'file' : 'dir',
                'match_path' => $is_single_file ? $main_file : trailingslashit($this->normalize_path_for_matching(dirname($main_file))),
            );
        }

        return $components;
    }

    private function get_active_theme_components(): array
    {
        if (!function_exists('wp_get_theme')) {
            return array();
        }

        $active_theme = wp_get_theme();

        if (!$active_theme || !$active_theme->exists()) {
            return array();
        }

        $themes = function_exists('wp_get_themes') ? wp_get_themes() : array();
        $components = array();

        foreach ($themes as $stylesheet => $theme) {
            $components[] = array(
                'key'        => 'theme:' . $stylesheet,
                'type'       => 'theme',
                'slug'       => (string)$stylesheet,
                'label'      => (string)($theme->get('Name') ?: $stylesheet),
                'version'    => (string)$theme->get('Version'),
                'author'     => sanitize_text_field((string)$theme->get('Author')),
                'author_url' => esc_url_raw((string)$theme->get('AuthorURI')),
                'site_url'   => esc_url_raw((string)$theme->get('ThemeURI')),
                'description'=> sanitize_text_field(wp_strip_all_tags((string)$theme->get('Description'))),
                'is_active'  => in_array($stylesheet, array($active_theme->get_stylesheet(), $active_theme->get_template()), true) ? 1 : 0,
                'match_mode' => 'dir',
                'match_path' => trailingslashit($this->normalize_path_for_matching($theme->get_stylesheet_directory())),
            );
        }

        return $components;
    }

    private function resolve_component_from_callable($callable): ?array
    {
        $cache_key = $this->build_callable_component_cache_key($callable);

        if ($cache_key !== '' && array_key_exists($cache_key, $this->callable_component_cache)) {
            return $this->callable_component_cache[$cache_key];
        }

        $file = $this->resolve_callable_file($callable);
        $component = $file !== '' ? $this->resolve_component_from_file($file) : null;

        if ($cache_key !== '') {
            $this->callable_component_cache[$cache_key] = $component;
        }

        return $component;
    }

    private function resolve_callable_file($callable): string
    {
        try {
            if (is_array($callable) && isset($callable[0], $callable[1])) {
                $reflection = new \ReflectionMethod($callable[0], (string)$callable[1]);
                return $this->normalize_path_for_matching((string)$reflection->getFileName());
            }

            if (is_string($callable) && strpos($callable, '::') !== false) {
                $reflection = new \ReflectionMethod($callable);
                return $this->normalize_path_for_matching((string)$reflection->getFileName());
            }

            if (is_string($callable) || $callable instanceof \Closure) {
                $reflection = new \ReflectionFunction($callable);
                return $this->normalize_path_for_matching((string)$reflection->getFileName());
            }
        }
        catch (\ReflectionException $exception) {
            return '';
        }

        return '';
    }

    private function resolve_component_from_backtrace(array $trace): ?array
    {
        $monitor_file = $this->normalize_path_for_matching(__FILE__);

        foreach ($trace as $frame) {
            $file = $this->normalize_path_for_matching((string)($frame['file'] ?? ''));

            if ($file === '' || $file === $monitor_file) {
                continue;
            }

            $component = $this->resolve_component_from_file($file);

            if (!empty($component)) {
                return $component;
            }
        }

        return null;
    }

    private function resolve_component_candidates_from_backtrace(array $trace): array
    {
        $monitor_file = $this->normalize_path_for_matching(__FILE__);
        $candidates = array();
        $seen = array();

        foreach ($trace as $frame) {
            $file = $this->normalize_path_for_matching((string)($frame['file'] ?? ''));

            if ($file === '' || $file === $monitor_file) {
                continue;
            }

            $component = $this->resolve_component_from_file($file);
            $component_key = (string)($component['key'] ?? '');

            if (empty($component) || $component_key === '' || isset($seen[$component_key])) {
                continue;
            }

            $seen[$component_key] = true;
            $candidates[] = array(
                'component' => $component,
                'file'      => $file,
            );
        }

        return $candidates;
    }

    private function resolve_component_for_query(string $query, $query_callstack): ?array
    {
        $current_component = $this->get_current_profiled_component();
        $candidates = $this->resolve_component_candidates_from_query_callstack($query_callstack);
        $trace_component = $this->select_component_from_query_trace($candidates, $query);

        if (!empty($current_component)) {
            if ($this->is_wp_optimizer_component($current_component)) {
                if (!empty($trace_component) && !$this->is_wp_optimizer_component($trace_component)) {
                    return $trace_component;
                }

                if ($this->query_looks_wp_optimizer_owned($query)) {
                    return $current_component;
                }

                return $trace_component;
            }

            return $current_component;
        }

        return $trace_component;
    }

    private function resolve_component_from_file(string $file): ?array
    {
        $file = $this->normalize_path_for_matching($file);

        if ($file === '') {
            return null;
        }

        if (array_key_exists($file, $this->file_component_cache)) {
            return $this->file_component_cache[$file];
        }

        $catalog = $this->get_component_catalog();

        foreach ($this->component_matchers as $matcher) {
            $match_path = (string)($matcher['path'] ?? '');

            if ($match_path === '') {
                continue;
            }

            $matched = ($matcher['mode'] ?? 'dir') === 'file'
                ? $file === $match_path
                : strpos($file, $match_path) === 0;

            if ($matched && isset($catalog[$matcher['key']])) {
                $this->file_component_cache[$file] = $catalog[$matcher['key']];

                return $this->file_component_cache[$file];
            }
        }

        $this->file_component_cache[$file] = null;

        return null;
    }

    private function normalize_path_for_matching(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        return wp_normalize_path($path);
    }

    private function resolve_component_candidates_from_query_callstack($query_callstack): array
    {
        if (!is_array($query_callstack) || empty($query_callstack)) {
            return array();
        }

        if (isset($query_callstack['file'])) {
            return $this->resolve_component_candidates_from_backtrace(array($query_callstack));
        }

        $first_frame = reset($query_callstack);

        if (is_array($first_frame)) {
            return $this->resolve_component_candidates_from_backtrace($query_callstack);
        }

        return array();
    }

    private function select_component_from_query_trace(array $candidates, string $query): ?array
    {
        $wp_optimizer_candidate = null;

        foreach ($candidates as $candidate) {
            $component = is_array($candidate['component'] ?? null) ? $candidate['component'] : array();
            $file = (string)($candidate['file'] ?? '');

            if (empty($component)) {
                continue;
            }

            if (!$this->is_wp_optimizer_component($component)) {
                return $component;
            }

            if ($wp_optimizer_candidate === null && !$this->is_wp_optimizer_infrastructure_trace_file($file)) {
                $wp_optimizer_candidate = $component;
            }
        }

        if ($wp_optimizer_candidate !== null && $this->query_looks_wp_optimizer_owned($query)) {
            return $wp_optimizer_candidate;
        }

        return null;
    }

    private function is_wp_optimizer_component(array $component): bool
    {
        $key = (string)($component['key'] ?? '');
        $slug = (string)($component['slug'] ?? '');

        return $slug === 'wp-optimizer'
            || strpos($key, 'plugin:wp-optimizer/') === 0;
    }

    private function is_wp_optimizer_infrastructure_trace_file(string $file): bool
    {
        $file = strtolower($this->normalize_path_for_matching($file));
        $plugin_root = strtolower($this->normalize_path_for_matching(WPOPT_ABSPATH));

        if ($file === '' || $plugin_root === '' || strpos($file, $plugin_root) !== 0) {
            return false;
        }

        $relative = ltrim(substr($file, strlen($plugin_root)), '/');

        return $relative === 'modules/cache.class.php'
            || $relative === 'admin/plugininit.class.php'
            || strpos($relative, 'modules/supporters/cache/') === 0
            || strpos($relative, 'vendors/wps-framework/') === 0
            || strpos($relative, 'inc/') === 0;
    }

    private function query_looks_wp_optimizer_owned(string $query): bool
    {
        global $wpdb;

        $query = strtolower($query);
        $known_tables = array(
            defined('WPOPT_TABLE_LOG_MAILS') ? WPOPT_TABLE_LOG_MAILS : '',
            defined('WPOPT_TABLE_ACTIVITY_LOG') ? WPOPT_TABLE_ACTIVITY_LOG : '',
            defined('WPOPT_TABLE_REQUEST_PERFORMANCE') ? WPOPT_TABLE_REQUEST_PERFORMANCE : '',
            defined('WPOPT_TABLE_SLOW_QUERIES') ? WPOPT_TABLE_SLOW_QUERIES : '',
        );

        foreach (array_filter($known_tables) as $table) {
            if (strpos($query, strtolower((string)$table)) !== false) {
                return true;
            }
        }

        $prefix = isset($wpdb) && is_object($wpdb) ? strtolower((string)$wpdb->prefix) : 'wp_';

        return strpos($query, $prefix . 'wpopt_') !== false
            || strpos($query, $prefix . 'wps_') !== false
            || (strpos($query, 'option_name') !== false && strpos($query, 'wpopt') !== false);
    }

    private function build_callable_component_cache_key($callable): string
    {
        if (is_array($callable) && isset($callable[0], $callable[1])) {
            $target = is_object($callable[0]) ? get_class($callable[0]) : (string)$callable[0];

            return 'method:' . $target . '::' . (string)$callable[1];
        }

        if (is_string($callable)) {
            return 'function:' . $callable;
        }

        if ($callable instanceof \Closure) {
            return 'closure:' . spl_object_hash($callable);
        }

        return '';
    }

    private function build_hook_callbacks_signature(array $callbacks): string
    {
        $parts = array();

        foreach ($callbacks as $callback_id => $callback) {
            $parts[] = (string)$callback_id . ':' . absint($callback['accepted_args'] ?? 0);
        }

        return md5(implode('|', $parts));
    }

    private function build_profiler_wrapper_id(string $type, string $hook_name, string $priority, string $callback_id): string
    {
        return '__wpopt_prof_' . $type . '__' . md5($hook_name . '|' . $priority . '|' . $callback_id);
    }

    private function is_profiler_wrapper_id(string $callback_id): bool
    {
        return strpos($callback_id, '__wpopt_prof_') === 0;
    }

    private function start_component_callback_profile(string $profile_key, string $hook_name, array $component): void
    {
        $frame = array(
            'started_at' => microtime(true),
            'memory'     => memory_get_usage(true),
            'peak'       => memory_get_peak_usage(true),
            'component'  => $component,
        );

        $this->callback_profile_stack[$profile_key][] = $frame;
        $this->active_callback_component_stack[] = array(
            'profile_key' => $profile_key,
            'component'   => $component,
        );
    }

    private function stop_component_callback_profile(string $profile_key, string $hook_name, array $component): void
    {
        if (empty($this->callback_profile_stack[$profile_key])) {
            return;
        }

        $frame = array_pop($this->callback_profile_stack[$profile_key]);
        $this->remove_active_callback_profile($profile_key);

        if (!empty($frame['component']) && is_array($frame['component'])) {
            $component = $frame['component'];
        }

        $component_key = (string)($component['key'] ?? '');

        if ($component_key === '') {
            return;
        }

        $this->touch_component_metric($component);

        $elapsed_ms = max(0, round((microtime(true) - (float)$frame['started_at']) * 1000, 3));
        $current_memory = memory_get_usage(true);
        $current_peak = memory_get_peak_usage(true);
        $delta_memory = max(0, $current_memory - (int)$frame['memory']);
        $delta_peak = max($delta_memory, max(0, $current_peak - (int)$frame['peak']));

        $this->component_metrics[$component_key]['callback_time_ms'] += $elapsed_ms;
        $this->component_metrics[$component_key]['callback_calls'] += 1;
        $this->component_metrics[$component_key]['memory_allocated'] += $delta_memory;
        $this->component_metrics[$component_key]['peak_memory'] = max(
            (int)$this->component_metrics[$component_key]['peak_memory'],
            $delta_peak,
            $delta_memory
        );
        $this->component_metrics[$component_key]['requests'] = max(1, (int)$this->component_metrics[$component_key]['requests']);
    }

    private function get_current_profiled_component(): ?array
    {
        if (empty($this->active_callback_component_stack)) {
            return null;
        }

        $frame = $this->active_callback_component_stack[count($this->active_callback_component_stack) - 1] ?? null;

        return !empty($frame['component']) && is_array($frame['component'])
            ? $frame['component']
            : null;
    }

    private function remove_active_callback_profile(string $profile_key): void
    {
        if (empty($this->active_callback_component_stack)) {
            return;
        }

        $last_index = count($this->active_callback_component_stack) - 1;

        if (($this->active_callback_component_stack[$last_index]['profile_key'] ?? '') === $profile_key) {
            array_pop($this->active_callback_component_stack);
            return;
        }

        for ($index = $last_index; $index >= 0; $index--) {
            if (($this->active_callback_component_stack[$index]['profile_key'] ?? '') !== $profile_key) {
                continue;
            }

            array_splice($this->active_callback_component_stack, $index, 1);
            return;
        }
    }

    private function get_request_type_breakdown(string $from_gmt): array
    {
        global $wpdb;

        return (array)$wpdb->get_results(
            $wpdb->prepare(
                'SELECT request_type, COUNT(*) AS hits, AVG(response_time_ms) AS avg_ms, MAX(response_time_ms) AS max_ms, SUM(is_slow) AS slow_hits FROM ' . WPOPT_TABLE_REQUEST_PERFORMANCE . ' WHERE created_at_gmt >= %s GROUP BY request_type ORDER BY hits DESC, avg_ms DESC',
                $from_gmt
            ),
            ARRAY_A
        );
    }

    private function get_request_label_breakdown(string $from_gmt): array
    {
        global $wpdb;

        return (array)$wpdb->get_results(
            $wpdb->prepare(
                'SELECT request_type, request_label, COUNT(*) AS hits, AVG(response_time_ms) AS avg_ms, MAX(response_time_ms) AS max_ms FROM ' . WPOPT_TABLE_REQUEST_PERFORMANCE . ' WHERE created_at_gmt >= %s GROUP BY request_type, request_label ORDER BY hits DESC, avg_ms DESC LIMIT 10',
                $from_gmt
            ),
            ARRAY_A
        );
    }

    private function get_recent_requests(string $from_gmt, int $limit = 20): array
    {
        global $wpdb;

        return (array)$wpdb->get_results(
            $wpdb->prepare(
                'SELECT created_at, request_type, request_label, request_method, request_uri, status_code, response_time_ms, memory_peak, query_count FROM ' . WPOPT_TABLE_REQUEST_PERFORMANCE . ' WHERE created_at_gmt >= %s ORDER BY created_at_gmt DESC LIMIT %d',
                $from_gmt,
                $limit
            ),
            ARRAY_A
        );
    }

    private function get_slow_query_breakdown(string $from_gmt, int $limit = 10): array
    {
        global $wpdb;

        if (!defined('WPOPT_TABLE_SLOW_QUERIES')) {
            return array();
        }

        $limit = max(1, $limit);
        $rows = (array)$wpdb->get_results(
            $wpdb->prepare(
                'SELECT slow.sql_signature,
                        slow.sql_fingerprint,
                        slow.sql_query,
                        slow.query_time_ms,
                        slow.query_caller,
                        COALESCE(NULLIF(req.request_type, ""), slow.request_type) AS request_type,
                        COALESCE(NULLIF(req.request_label, ""), slow.request_label) AS request_label,
                        COALESCE(NULLIF(req.request_method, ""), slow.request_method) AS request_method,
                        COALESCE(NULLIF(req.request_uri, ""), slow.request_uri) AS request_uri,
                        COALESCE(req.created_at, slow.created_at) AS created_at
                 FROM ' . WPOPT_TABLE_SLOW_QUERIES . ' slow
                 LEFT JOIN ' . WPOPT_TABLE_REQUEST_PERFORMANCE . ' req ON req.id = slow.request_log_id
                 WHERE slow.created_at_gmt >= %s
                 ORDER BY slow.query_time_ms DESC, slow.created_at_gmt DESC
                 LIMIT %d',
                $from_gmt,
                $limit
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            return array();
        }

        foreach ($rows as $index => &$row) {
            $row['row_key'] = md5(implode('|', array(
                (string)($row['sql_signature'] ?? ''),
                (string)($row['request_uri'] ?? ''),
                (string)($row['created_at'] ?? ''),
                (string)($row['query_time_ms'] ?? ''),
                (string)$index,
            )));
            $row['hits'] = 1;
            $row['avg_ms'] = (float)($row['query_time_ms'] ?? 0);
            $row['max_ms'] = (float)($row['query_time_ms'] ?? 0);
            $row['sample_query'] = $row['sql_query'] ?? $row['sql_fingerprint'];
            $row['query_caller'] = $row['query_caller'] ?? '';
            $row['request_type'] = $row['request_type'] ?? 'other';
            $row['request_label'] = $row['request_label'] ?? '';
            $row['request_method'] = $row['request_method'] ?? 'GET';
            $row['request_uri'] = $row['request_uri'] ?? '';
            $row['observed_at'] = $row['created_at'] ?? '';
        }
        unset($row);

        return $rows;
    }

    private function get_slow_query_samples(string $from_gmt, array $signatures): array
    {
        global $wpdb;

        $signatures = array_values(array_unique(array_filter(array_map('strval', $signatures))));

        if (empty($signatures)) {
            return array();
        }

        $placeholders = implode(', ', array_fill(0, count($signatures), '%s'));
        $sql = 'SELECT slow.sql_signature,
                       slow.sql_query,
                       slow.query_caller,
                       COALESCE(NULLIF(req.request_type, ""), slow.request_type) AS request_type,
                       COALESCE(NULLIF(req.request_label, ""), slow.request_label) AS request_label,
                       COALESCE(NULLIF(req.request_method, ""), slow.request_method) AS request_method,
                       COALESCE(NULLIF(req.request_uri, ""), slow.request_uri) AS request_uri,
                       COALESCE(req.created_at, slow.created_at) AS created_at
                FROM ' . WPOPT_TABLE_SLOW_QUERIES . ' slow
                LEFT JOIN ' . WPOPT_TABLE_REQUEST_PERFORMANCE . ' req ON req.id = slow.request_log_id
                WHERE slow.created_at_gmt >= %s AND slow.sql_signature IN (' . $placeholders . ')
                ORDER BY slow.query_time_ms DESC, slow.created_at_gmt DESC';

        $rows = (array)$wpdb->get_results(
            $wpdb->prepare($sql, array_merge(array($from_gmt), $signatures)),
            ARRAY_A
        );

        $samples = array();

        foreach ($rows as $row) {
            if (isset($samples[$row['sql_signature']])) {
                continue;
            }

            $samples[$row['sql_signature']] = $row;
        }

        return $samples;
    }

    private function get_time_series(array $window, string $from_gmt, array $request_types): array
    {
        global $wpdb;

        if (empty($request_types)) {
            return array('labels' => array(), 'series' => array());
        }

        list($bucket_keys, $labels) = $this->build_buckets($window);
        $series = array();
        foreach ($request_types as $type) {
            $series[$type] = array_fill(0, count($bucket_keys), 0);
        }

        $bucket_format = $window['bucket'] === 'hour' ? '%Y-%m-%d %H:00:00' : '%Y-%m-%d 00:00:00';
        $placeholders = implode(', ', array_fill(0, count($request_types), '%s'));
        $sql = 'SELECT DATE_FORMAT(created_at_gmt, %s) AS bucket_key, request_type, AVG(response_time_ms) AS avg_ms
                FROM ' . WPOPT_TABLE_REQUEST_PERFORMANCE . '
                WHERE created_at_gmt >= %s AND request_type IN (' . $placeholders . ')
                GROUP BY bucket_key, request_type
                ORDER BY bucket_key ASC';

        $rows = (array)$wpdb->get_results(
            $wpdb->prepare($sql, array_merge(array($bucket_format, $from_gmt), $request_types)),
            ARRAY_A
        );

        $bucket_index = array_flip($bucket_keys);

        foreach ($rows as $row) {
            if (!isset($series[$row['request_type']], $bucket_index[$row['bucket_key']])) {
                continue;
            }

            $series[$row['request_type']][$bucket_index[$row['bucket_key']]] = round((float)$row['avg_ms'], 3);
        }

        return array('labels' => $labels, 'series' => $series);
    }

    private function build_buckets(array $window): array
    {
        $timezone = new DateTimeZone('UTC');
        $format = $window['bucket'] === 'hour' ? 'Y-m-d H:00:00' : 'Y-m-d 00:00:00';
        $display_format = $window['bucket'] === 'hour' ? 'H:i' : 'd M';
        $step = $window['bucket'] === 'hour' ? HOUR_IN_SECONDS : DAY_IN_SECONDS;

        $now = time();
        $start = $window['bucket'] === 'hour'
            ? strtotime(gmdate('Y-m-d H:00:00', $now - (($window['count'] - 1) * HOUR_IN_SECONDS)))
            : strtotime(gmdate('Y-m-d 00:00:00', $now - (($window['count'] - 1) * DAY_IN_SECONDS)));

        $bucket_keys = array();
        $labels = array();

        for ($i = 0; $i < $window['count']; $i++) {
            $timestamp = $start + ($i * $step);
            $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
            $bucket_keys[] = $date->format($format);
            $labels[] = wp_date($display_format, $timestamp);
        }

        return array($bucket_keys, $labels);
    }

    private function render_bar_chart(array $rows): string
    {
        $rows = array_slice($rows, 0, 6);
        $max_value = 1;

        foreach ($rows as $row) {
            $max_value = max($max_value, (float)$row['avg_ms']);
        }

        $width = 420;
        $height = 280;
        $padding_top = 20;
        $padding_right = 18;
        $padding_bottom = 70;
        $padding_left = 48;
        $plot_width = $width - $padding_left - $padding_right;
        $plot_height = $height - $padding_top - $padding_bottom;
        $count = max(1, count($rows));
        $slot = $plot_width / $count;
        $bar_width = min(42, $slot * 0.58);
        $palette = $this->chart_palette();

        ob_start();
        ?>
        <svg viewBox="0 0 <?php echo $width; ?> <?php echo $height; ?>" width="100%" height="280" role="img" aria-label="<?php esc_attr_e('Average response time by request type', 'wpopt'); ?>">
            <line x1="<?php echo $padding_left; ?>" y1="<?php echo $padding_top; ?>" x2="<?php echo $padding_left; ?>" y2="<?php echo $padding_top + $plot_height; ?>" stroke="#cbd5e1" stroke-width="1"/>
            <line x1="<?php echo $padding_left; ?>" y1="<?php echo $padding_top + $plot_height; ?>" x2="<?php echo $padding_left + $plot_width; ?>" y2="<?php echo $padding_top + $plot_height; ?>" stroke="#cbd5e1" stroke-width="1"/>
            <text x="10" y="<?php echo $padding_top + 10; ?>" fill="#64748b" font-size="11"><?php echo esc_html($this->format_ms($max_value)); ?></text>
            <text x="14" y="<?php echo $padding_top + $plot_height; ?>" fill="#64748b" font-size="11">0</text>
            <?php foreach ($rows as $index => $row): ?>
                <?php
                $value = (float)$row['avg_ms'];
                $bar_height = $plot_height * ($value / $max_value);
                $x = $padding_left + ($slot * $index) + (($slot - $bar_width) / 2);
                $y = $padding_top + $plot_height - $bar_height;
                $color = $palette[$index % count($palette)];
                ?>
                <rect x="<?php echo round($x, 2); ?>" y="<?php echo round($y, 2); ?>" width="<?php echo round($bar_width, 2); ?>" height="<?php echo round($bar_height, 2); ?>" rx="10" fill="<?php echo esc_attr($color); ?>" opacity="0.92"></rect>
                <text x="<?php echo round($x + ($bar_width / 2), 2); ?>" y="<?php echo max(12, round($y - 6, 2)); ?>" text-anchor="middle" fill="#0f172a" font-size="11"><?php echo esc_html($this->format_ms($value)); ?></text>
                <text x="<?php echo round($x + ($bar_width / 2), 2); ?>" y="<?php echo $padding_top + $plot_height + 18; ?>" text-anchor="middle" fill="#334155" font-size="11"><?php echo esc_html($this->short_label($this->humanize_type($row['request_type']), 10)); ?></text>
                <text x="<?php echo round($x + ($bar_width / 2), 2); ?>" y="<?php echo $padding_top + $plot_height + 34; ?>" text-anchor="middle" fill="#64748b" font-size="10"><?php echo esc_html(sprintf(_n('%s hit', '%s hits', (int)$row['hits'], 'wpopt'), number_format_i18n((int)$row['hits']))); ?></text>
                <text x="<?php echo round($x + ($bar_width / 2), 2); ?>" y="<?php echo $padding_top + $plot_height + 48; ?>" text-anchor="middle" fill="#64748b" font-size="10"><?php echo esc_html(sprintf(_n('%s slow', '%s slow', (int)$row['slow_hits'], 'wpopt'), number_format_i18n((int)$row['slow_hits']))); ?></text>
            <?php endforeach; ?>
        </svg>
        <?php
        return ob_get_clean();
    }

    private function render_line_chart(array $labels, array $series): string
    {
        $palette = $this->chart_palette();
        $width = 760;
        $height = 280;
        $padding_top = 24;
        $padding_right = 24;
        $padding_bottom = 42;
        $padding_left = 48;
        $plot_width = $width - $padding_left - $padding_right;
        $plot_height = $height - $padding_top - $padding_bottom;
        $max_value = 1;

        foreach ($series as $values) {
            foreach ($values as $value) {
                $max_value = max($max_value, (float)$value);
            }
        }

        ob_start();
        ?>
        <div class="wpopt-perf-legend">
            <?php $legend_index = 0; ?>
            <?php foreach ($series as $name => $values): ?>
                <span><i class="wpopt-perf-swatch" data-wpopt-style-bg="<?php echo esc_attr($palette[$legend_index % count($palette)]); ?>"></i><?php echo esc_html($this->humanize_type($name)); ?></span>
                <?php $legend_index++; ?>
            <?php endforeach; ?>
        </div>
        <svg viewBox="0 0 <?php echo $width; ?> <?php echo $height; ?>" width="100%" height="280" role="img" aria-label="<?php esc_attr_e('Response time trend', 'wpopt'); ?>">
            <line x1="<?php echo $padding_left; ?>" y1="<?php echo $padding_top; ?>" x2="<?php echo $padding_left; ?>" y2="<?php echo $padding_top + $plot_height; ?>" stroke="#cbd5e1" stroke-width="1"/>
            <line x1="<?php echo $padding_left; ?>" y1="<?php echo $padding_top + $plot_height; ?>" x2="<?php echo $padding_left + $plot_width; ?>" y2="<?php echo $padding_top + $plot_height; ?>" stroke="#cbd5e1" stroke-width="1"/>
            <?php for ($i = 0; $i < 4; $i++): ?>
                <?php
                $grid_y = $padding_top + (($plot_height / 3) * $i);
                $axis_value = $max_value - (($max_value / 3) * $i);
                ?>
                <line x1="<?php echo $padding_left; ?>" y1="<?php echo round($grid_y, 2); ?>" x2="<?php echo $padding_left + $plot_width; ?>" y2="<?php echo round($grid_y, 2); ?>" stroke="#e2e8f0" stroke-width="1" stroke-dasharray="4 4"/>
                <text x="6" y="<?php echo round($grid_y + 4, 2); ?>" fill="#64748b" font-size="11"><?php echo esc_html($this->format_ms($axis_value)); ?></text>
            <?php endfor; ?>
            <?php
            $point_count = max(1, count($labels) - 1);
            $series_index = 0;
            foreach ($series as $name => $values):
                $color = $palette[$series_index % count($palette)];
                $points = array();
                foreach ($values as $index => $value) {
                    $x = $padding_left + (($plot_width / $point_count) * $index);
                    $y = $padding_top + $plot_height - (($plot_height * ((float)$value / $max_value)));
                    $points[] = round($x, 2) . ',' . round($y, 2);
                }
                ?>
                <polyline fill="none" stroke="<?php echo esc_attr($color); ?>" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" points="<?php echo esc_attr(implode(' ', $points)); ?>"></polyline>
                <?php foreach ($values as $index => $value): ?>
                    <?php $x = $padding_left + (($plot_width / $point_count) * $index); $y = $padding_top + $plot_height - (($plot_height * ((float)$value / $max_value))); ?>
                    <circle cx="<?php echo round($x, 2); ?>" cy="<?php echo round($y, 2); ?>" r="4" fill="<?php echo esc_attr($color); ?>"></circle>
                <?php endforeach; ?>
                <?php $series_index++; ?>
            <?php endforeach; ?>
            <?php foreach ($labels as $index => $label): ?>
                <?php $x = $padding_left + (($plot_width / $point_count) * $index); ?>
                <text x="<?php echo round($x, 2); ?>" y="<?php echo $padding_top + $plot_height + 18; ?>" text-anchor="middle" fill="#64748b" font-size="10"><?php echo esc_html($label); ?></text>
            <?php endforeach; ?>
        </svg>
        <?php
        return ob_get_clean();
    }

    private function render_cache_summary_card(array $summary): string
    {
        $display_format = trim(get_option('date_format') . ' ' . get_option('time_format'));
        $started_at = !empty($summary['started_at']) && function_exists('mysql2date')
            ? mysql2date($display_format, (string)$summary['started_at'], true)
            : '';
        $updated_at = !empty($summary['updated_at']) && function_exists('mysql2date')
            ? mysql2date($display_format, (string)$summary['updated_at'], true)
            : '';

        ob_start();
        ?>
        <div class="wpopt-perf-cache-summary">
            <span class="wpopt-perf-cache-badge"><?php _e('Cumulative cache ratio', 'wpopt'); ?></span>
            <div class="wpopt-perf-cache-ring-wrap">
                <div class="wpopt-perf-cache-ring-box">
                    <div class="wpopt-perf-cache-ring" data-wpopt-style-ratio="<?php echo esc_attr(round((float)$summary['hit_ratio'], 3)); ?>"></div>
                    <div class="wpopt-perf-cache-ring-copy">
                        <div>
                            <strong><?php echo esc_html($this->format_percent((float)$summary['hit_ratio'])); ?></strong>
                            <span><?php _e('Hit ratio', 'wpopt'); ?></span>
                        </div>
                    </div>
                </div>

                <div class="wpopt-perf-cache-mini-grid">
                    <div class="wpopt-perf-cache-mini">
                        <span><?php _e('Hits', 'wpopt'); ?></span>
                        <strong><?php echo number_format_i18n((int)$summary['total_hits']); ?></strong>
                    </div>
                    <div class="wpopt-perf-cache-mini">
                        <span><?php _e('Misses', 'wpopt'); ?></span>
                        <strong><?php echo number_format_i18n((int)$summary['total_misses']); ?></strong>
                    </div>
                    <div class="wpopt-perf-cache-mini">
                        <span><?php _e('Tracked requests', 'wpopt'); ?></span>
                        <strong><?php echo number_format_i18n((int)$summary['tracked_requests']); ?></strong>
                    </div>
                    <div class="wpopt-perf-cache-mini">
                        <span><?php _e('Operations', 'wpopt'); ?></span>
                        <strong><?php echo number_format_i18n((int)$summary['total_operations']); ?></strong>
                    </div>
                </div>
            </div>
            <p><?php _e('The ratio is computed from cumulative cache reads that returned a saved result versus reads that had to fall back to origin work.', 'wpopt'); ?></p>
            <?php if ($started_at !== '' || $updated_at !== ''): ?>
                <p class="wpopt-muted wpopt-perf-meta-note">
                    <?php
                    $meta = array();

                    if ($started_at !== '') {
                        $meta[] = sprintf(__('Since %s', 'wpopt'), $started_at);
                    }

                    if ($updated_at !== '') {
                        $meta[] = sprintf(__('Last update %s', 'wpopt'), $updated_at);
                    }

                    echo esc_html(implode(' | ', $meta));
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
        return (string)ob_get_clean();
    }

    private function render_cumulative_cache_chart(array $summary, array $layers): string
    {
        $rows = array_merge(array(
            array(
                'label'  => __('Overall cache', 'wpopt'),
                'hits'   => (int)$summary['total_hits'],
                'misses' => (int)$summary['total_misses'],
                'total'  => (int)$summary['total_operations'],
                'ratio'  => (float)$summary['hit_ratio'],
            ),
        ), $layers);

        $width = 860;
        $height = max(230, 110 + (count($rows) * 58));
        $padding_top = 26;
        $padding_right = 74;
        $padding_bottom = 24;
        $padding_left = 148;
        $plot_width = $width - $padding_left - $padding_right;
        $plot_height = $height - $padding_top - $padding_bottom;
        $max_total = 1;

        foreach ($rows as $row) {
            $max_total = max($max_total, (int)($row['total'] ?? 0));
        }

        $row_gap = count($rows) > 1 ? ($plot_height / count($rows)) : $plot_height;
        $row_gap = max(56, $row_gap);
        $bar_height = 18;

        ob_start();
        ?>
        <div class="wpopt-perf-legend">
            <span><i class="wpopt-perf-swatch" data-wpopt-style-bg="#0f766e"></i><?php _e('Hits', 'wpopt'); ?></span>
            <span><i class="wpopt-perf-swatch" data-wpopt-style-bg="#ea580c"></i><?php _e('Misses', 'wpopt'); ?></span>
            <span><i class="wpopt-perf-swatch" data-wpopt-style-bg="#cbd5e1"></i><?php _e('Relative operations volume', 'wpopt'); ?></span>
        </div>
        <svg viewBox="0 0 <?php echo $width; ?> <?php echo $height; ?>" width="100%" height="<?php echo esc_attr($height); ?>" role="img" aria-label="<?php esc_attr_e('Cumulative cache hits and misses by layer', 'wpopt'); ?>">
            <line x1="<?php echo $padding_left; ?>" y1="<?php echo $padding_top; ?>" x2="<?php echo $padding_left; ?>" y2="<?php echo $padding_top + $plot_height; ?>" stroke="#cbd5e1" stroke-width="1"/>
            <line x1="<?php echo $padding_left; ?>" y1="<?php echo $padding_top + $plot_height; ?>" x2="<?php echo $padding_left + $plot_width; ?>" y2="<?php echo $padding_top + $plot_height; ?>" stroke="#cbd5e1" stroke-width="1"/>
            <?php for ($i = 0; $i < 4; $i++): ?>
                <?php
                $grid_y = $padding_top + (($plot_height / 3) * $i);
                $ops_label = $max_total - (($max_total / 3) * $i);
                ?>
                <line x1="<?php echo $padding_left; ?>" y1="<?php echo round($grid_y, 2); ?>" x2="<?php echo $padding_left + $plot_width; ?>" y2="<?php echo round($grid_y, 2); ?>" stroke="#e2e8f0" stroke-width="1" stroke-dasharray="4 4"/>
                <text x="8" y="<?php echo round($grid_y + 4, 2); ?>" fill="#64748b" font-size="11"><?php echo esc_html(number_format_i18n($ops_label)); ?></text>
            <?php endfor; ?>

            <?php foreach ($rows as $index => $row): ?>
                <?php
                $hit = (int)($row['hits'] ?? 0);
                $miss = (int)($row['misses'] ?? 0);
                $total = max(0, (int)($row['total'] ?? 0));
                $ratio = (float)($row['ratio'] ?? 0);
                $track_y = $padding_top + ($index * $row_gap) + 18;
                $track_width = $plot_width;
                $active_width = $total > 0 ? max(2, ($plot_width * $total) / $max_total) : 0;
                $hit_width = $total > 0 ? $active_width * ($hit / $total) : 0;
                $miss_width = max(0, $active_width - $hit_width);
                ?>
                <text x="12" y="<?php echo round($track_y + 5, 2); ?>" fill="#0f172a" font-size="13" font-weight="700"><?php echo esc_html((string)$row['label']); ?></text>
                <text x="12" y="<?php echo round($track_y + 22, 2); ?>" fill="#64748b" font-size="11"><?php echo esc_html(sprintf(__('%1$s ops | %2$s hit ratio', 'wpopt'), number_format_i18n($total), $this->format_percent($ratio))); ?></text>
                <rect x="<?php echo $padding_left; ?>" y="<?php echo round($track_y, 2); ?>" width="<?php echo round($track_width, 2); ?>" height="<?php echo $bar_height; ?>" rx="9" fill="#e2e8f0"/>
                <?php if ($hit_width > 0): ?>
                    <rect x="<?php echo $padding_left; ?>" y="<?php echo round($track_y, 2); ?>" width="<?php echo round($hit_width, 2); ?>" height="<?php echo $bar_height; ?>" rx="9" fill="#0f766e">
                        <title><?php echo esc_html(sprintf(__('Hits: %s', 'wpopt'), number_format_i18n($hit))); ?></title>
                    </rect>
                <?php endif; ?>
                <?php if ($miss_width > 0): ?>
                    <rect x="<?php echo round($padding_left + $hit_width, 2); ?>" y="<?php echo round($track_y, 2); ?>" width="<?php echo round($miss_width, 2); ?>" height="<?php echo $bar_height; ?>" rx="9" fill="#ea580c">
                        <title><?php echo esc_html(sprintf(__('Misses: %s', 'wpopt'), number_format_i18n($miss))); ?></title>
                    </rect>
                <?php endif; ?>
                <?php if ($total > 0): ?>
                    <text x="<?php echo round($padding_left + min($plot_width + 12, $active_width + 12), 2); ?>" y="<?php echo round($track_y + 13, 2); ?>" fill="#334155" font-size="12"><?php echo esc_html($this->format_percent($ratio)); ?></text>
                <?php endif; ?>
            <?php endforeach; ?>
        </svg>
        <?php
        return (string)ob_get_clean();
    }

    private function render_cache_layer_cards(array $layers): string
    {
        ob_start();
        ?>
        <div class="wpopt-perf-cache-layer-grid">
            <?php foreach ($layers as $layer): ?>
                <?php
                $hit_width = $layer['total'] > 0 ? round(($layer['hits'] / $layer['total']) * 100, 3) : 0;
                $miss_width = $layer['total'] > 0 ? round(($layer['misses'] / $layer['total']) * 100, 3) : 0;
                ?>
                <div class="wpopt-perf-cache-layer">
                    <div class="wpopt-perf-cache-layer-head">
                        <div>
                            <strong><?php echo esc_html((string)$layer['label']); ?></strong>
                            <span><?php echo esc_html((string)$layer['context']); ?></span>
                        </div>
                        <span class="wpopt-perf-cache-layer-ratio"><?php echo esc_html($this->format_percent((float)$layer['ratio'])); ?></span>
                    </div>
                    <div class="wpopt-perf-cache-meter">
                        <span class="is-hit" data-wpopt-style-width="<?php echo esc_attr($hit_width); ?>"></span>
                        <span class="is-miss" data-wpopt-style-width="<?php echo esc_attr($miss_width); ?>"></span>
                    </div>
                    <div class="wpopt-perf-cache-layer-stats">
                        <div>
                            <span><?php _e('Hits', 'wpopt'); ?></span>
                            <b><?php echo number_format_i18n((int)$layer['hits']); ?></b>
                        </div>
                        <div>
                            <span><?php _e('Misses', 'wpopt'); ?></span>
                            <b><?php echo number_format_i18n((int)$layer['misses']); ?></b>
                        </div>
                        <div>
                            <span><?php _e('Ops', 'wpopt'); ?></span>
                            <b><?php echo number_format_i18n((int)$layer['total']); ?></b>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return (string)ob_get_clean();
    }

    private function render_component_explorer(array $rows): string
    {
        $rows = array_values($rows);

        if (empty($rows)) {
            return $this->render_empty_state(__('No plugin or theme data available yet.', 'wpopt'));
        }

        $max_time = max(1.0, ...array_map(static fn(array $row): float => max(0.0, (float)$row['observed_time_ms']), $rows));
        $first_key = (string)($rows[0]['key'] ?? '');
        $payload = array();

        foreach ($rows as $row) {
            $payload[$row['key']] = array(
                'details' => $this->render_component_details($row),
            );
        }

        $json = wp_json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        ob_start();
        ?>
        <div class="wpopt-perf-component-shell">
            <div class="wpopt-perf-component-controls">
                <button type="button" class="wpopt-perf-component-sort is-active" data-sort-mode="time"><?php _e('Sort by time', 'wpopt'); ?></button>
                <button type="button" class="wpopt-perf-component-sort" data-sort-mode="alpha"><?php _e('Sort A-Z', 'wpopt'); ?></button>
                <button type="button" class="wpopt-perf-component-sort" data-sort-mode="memory"><?php _e('Sort by memory', 'wpopt'); ?></button>
            </div>
            <div class="wpopt-perf-component-grid">
                <div class="wpopt-perf-component-list" role="list">
                    <?php foreach ($rows as $index => $row): ?>
                        <?php $width = min(100, max(6, round((((float)$row['observed_time_ms']) / $max_time) * 100, 2))); ?>
                        <button
                            type="button"
                            class="wpopt-perf-component-card"
                            data-component-target="<?php echo esc_attr((string)$row['key']); ?>"
                            data-active="<?php echo $row['key'] === $first_key ? '1' : '0'; ?>"
                            data-sort-time="<?php echo esc_attr(number_format((float)$row['observed_time_ms'], 3, '.', '')); ?>"
                            data-sort-alpha="<?php echo esc_attr(function_exists('mb_strtolower') ? mb_strtolower((string)$row['label'], 'UTF-8') : strtolower((string)$row['label'])); ?>"
                            data-sort-memory="<?php echo esc_attr((string)((int)$row['peak_memory'])); ?>"
                        >
                            <span class="wpopt-perf-component-rank"><?php echo number_format_i18n($index + 1); ?></span>
                            <span class="wpopt-perf-component-copy">
                                <span class="wpopt-perf-component-head">
                                    <span>
                                        <?php if (!empty($row['version'])): ?>
                                            <span class="wpopt-perf-component-kicker"><?php echo esc_html('v' . (string)$row['version']); ?></span>
                                        <?php endif; ?>
                                        <span class="wpopt-perf-component-title"><?php echo esc_html((string)$row['label']); ?></span>
                                        <span class="wpopt-perf-component-sub"><?php echo esc_html((string)$row['slug']); ?></span>
                                    </span>
                                    <span class="wpopt-perf-component-main">
                                        <strong><?php echo esc_html($this->format_ms((float)$row['observed_time_ms'])); ?></strong>
                                        <small><?php _e('Avg observed time', 'wpopt'); ?></small>
                                    </span>
                                </span>
                                <span class="wpopt-perf-component-meter"><span data-wpopt-style-width="<?php echo esc_attr($width); ?>"></span></span>
                                <span class="wpopt-perf-component-chips">
                                    <span class="wpopt-perf-component-chip"><b><?php echo esc_html(size_format((int)$row['peak_memory'])); ?></b><?php _e('Avg peak memory', 'wpopt'); ?></span>
                                    <span class="wpopt-perf-component-chip"><b><?php echo esc_html($this->format_avg_count((float)$row['query_count'])); ?></b><?php _e('Avg queries', 'wpopt'); ?></span>
                                    <span class="wpopt-perf-component-chip"><b><?php echo number_format_i18n((int)$row['file_count']); ?></b><?php _e('Files', 'wpopt'); ?></span>
                                </span>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="wpopt-perf-component-detail"><?php echo $first_key && isset($payload[$first_key]['details']) ? $payload[$first_key]['details'] : ''; ?></div>
            </div>
            <script type="application/json" class="wpopt-perf-component-data"><?php echo $json ?: '{}'; ?></script>
            <script>
                (function () {
                    var root = document.currentScript.parentElement;
                    var detailNode = root.querySelector('.wpopt-perf-component-detail');
                    var listNode = root.querySelector('.wpopt-perf-component-list');
                    var dataNode = root.querySelector('.wpopt-perf-component-data');
                    var sortButtons = root.querySelectorAll('[data-sort-mode]');
                    var payload = {};

                    if (!detailNode || !listNode || !dataNode) {
                        return;
                    }

                    try {
                        payload = JSON.parse(dataNode.textContent || '{}');
                    } catch (error) {
                        return;
                    }

                    var getCards = function () {
                        return Array.prototype.slice.call(listNode.querySelectorAll('[data-component-target]'));
                    };

                    var activate = function (key) {
                        if (!payload[key]) {
                            return;
                        }

                        detailNode.innerHTML = payload[key].details || '';

                        getCards().forEach(function (node) {
                            var active = node.getAttribute('data-component-target') === key;
                            node.setAttribute('data-active', active ? '1' : '0');
                        });
                    };

                    var reindex = function () {
                        getCards().forEach(function (node, index) {
                            var rank = node.querySelector('.wpopt-perf-component-rank');
                            if (rank) {
                                rank.textContent = String(index + 1);
                            }
                        });
                    };

                    var sortCards = function (mode) {
                        var cards = getCards();

                        cards.sort(function (left, right) {
                            if (mode === 'alpha') {
                                return String(left.getAttribute('data-sort-alpha') || '').localeCompare(String(right.getAttribute('data-sort-alpha') || ''));
                            }

                            var leftValue = parseFloat(left.getAttribute(mode === 'memory' ? 'data-sort-memory' : 'data-sort-time') || '0');
                            var rightValue = parseFloat(right.getAttribute(mode === 'memory' ? 'data-sort-memory' : 'data-sort-time') || '0');

                            if (rightValue !== leftValue) {
                                return rightValue - leftValue;
                            }

                            return String(left.getAttribute('data-sort-alpha') || '').localeCompare(String(right.getAttribute('data-sort-alpha') || ''));
                        });

                        cards.forEach(function (card) {
                            listNode.appendChild(card);
                        });

                        reindex();

                        var first = cards[0];
                        if (first) {
                            activate(first.getAttribute('data-component-target'));
                        }
                    };

                    getCards().forEach(function (node) {
                        node.addEventListener('click', function () {
                            activate(node.getAttribute('data-component-target'));
                        });
                    });

                    sortButtons.forEach(function (button) {
                        button.addEventListener('click', function () {
                            sortButtons.forEach(function (candidate) {
                                candidate.classList.toggle('is-active', candidate === button);
                            });

                            sortCards(button.getAttribute('data-sort-mode') || 'time');
                        });
                    });

                    reindex();
                    activate('<?php echo esc_js($first_key); ?>');
                }());
            </script>
        </div>
        <?php
        return (string)ob_get_clean();
    }

    private function render_component_details(array $row): string
    {
        ob_start();
        ?>
        <div class="wpopt-perf-component-detail-head">
            <h4><?php echo esc_html((string)$row['label']); ?></h4>
            <p><?php echo esc_html(__('Average load time, callback time, SQL time and memory footprint per sampled request for this component within the last 24 hours. Values are delta-based for the component, not cumulative from request start.', 'wpopt')); ?></p>
        </div>
        <div class="wpopt-perf-component-metrics">
            <div><span><?php _e('Avg observed time', 'wpopt'); ?></span><strong><?php echo esc_html($this->format_ms((float)$row['observed_time_ms'])); ?></strong></div>
            <div><span><?php _e('Avg load time', 'wpopt'); ?></span><strong><?php echo esc_html($this->format_ms((float)($row['load_time_ms'] ?? 0))); ?></strong></div>
            <div><span><?php _e('Avg callback time', 'wpopt'); ?></span><strong><?php echo esc_html($this->format_ms((float)$row['callback_time_ms'])); ?></strong></div>
            <div><span><?php _e('Avg SQL time', 'wpopt'); ?></span><strong><?php echo esc_html($this->format_ms((float)$row['sql_time_ms'])); ?></strong></div>
            <div><span><?php _e('Avg peak memory', 'wpopt'); ?></span><strong><?php echo esc_html(size_format((int)$row['peak_memory'])); ?></strong></div>
            <div><span><?php _e('Avg allocated', 'wpopt'); ?></span><strong><?php echo esc_html(size_format((int)$row['memory_allocated'])); ?></strong></div>
            <div><span><?php _e('Requests', 'wpopt'); ?></span><strong><?php echo number_format_i18n((int)$row['requests']); ?></strong></div>
        </div>
        <div class="wpopt-perf-component-meta">
            <div class="wpopt-perf-component-section">
                <div class="wpopt-perf-component-section-head">
                    <span><?php _e('Component profile', 'wpopt'); ?></span>
                    <strong><?php _e('Details', 'wpopt'); ?></strong>
                </div>
                <div class="wpopt-perf-component-facts">
                    <div class="wpopt-perf-component-fact">
                        <span><?php _e('Version', 'wpopt'); ?></span>
                        <strong><?php echo esc_html((string)($row['version'] !== '' ? $row['version'] : __('Not available', 'wpopt'))); ?></strong>
                    </div>
                    <div class="wpopt-perf-component-fact">
                        <span><?php _e('Author', 'wpopt'); ?></span>
                        <strong><?php echo esc_html((string)(($row['author'] ?? '') !== '' ? $row['author'] : __('Not available', 'wpopt'))); ?></strong>
                    </div>
                    <?php if (!empty($row['site_url'])): ?>
                        <a class="wpopt-perf-component-link" href="<?php echo esc_url((string)$row['site_url']); ?>" target="_blank" rel="noopener noreferrer">
                            <span><?php _e('Site', 'wpopt'); ?></span>
                            <strong><?php echo esc_html($this->format_component_link_label((string)$row['site_url'])); ?></strong>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($row['author_url'])): ?>
                        <a class="wpopt-perf-component-link" href="<?php echo esc_url((string)$row['author_url']); ?>" target="_blank" rel="noopener noreferrer">
                            <span><?php _e('Author site', 'wpopt'); ?></span>
                            <strong><?php echo esc_html($this->format_component_link_label((string)$row['author_url'])); ?></strong>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($row['description'])): ?>
                        <div class="wpopt-perf-component-description">
                            <span><?php _e('Description', 'wpopt'); ?></span>
                            <p><?php echo esc_html((string)$row['description']); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="wpopt-perf-component-section wpopt-perf-component-section-workload">
                <div class="wpopt-perf-component-section-head">
                    <span><?php _e('Runtime footprint', 'wpopt'); ?></span>
                    <strong><?php _e('Observed workload', 'wpopt'); ?></strong>
                </div>
                <div class="wpopt-perf-component-workload">
                    <div class="wpopt-perf-component-workload-item">
                        <span><?php _e('Avg queries', 'wpopt'); ?></span>
                        <strong><?php echo esc_html($this->format_avg_count((float)$row['query_count'])); ?></strong>
                    </div>
                    <div class="wpopt-perf-component-workload-item">
                        <span><?php _e('Avg callback calls', 'wpopt'); ?></span>
                        <strong><?php echo esc_html($this->format_avg_count((float)$row['callback_calls'])); ?></strong>
                    </div>
                    <div class="wpopt-perf-component-workload-item">
                        <span><?php _e('Loaded PHP files', 'wpopt'); ?></span>
                        <strong><?php echo number_format_i18n((int)$row['file_count']); ?></strong>
                    </div>
                    <div class="wpopt-perf-component-workload-item">
                        <span><?php _e('PHP size on disk', 'wpopt'); ?></span>
                        <strong><?php echo esc_html(size_format((int)$row['file_bytes'])); ?></strong>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return (string)ob_get_clean();
    }

    private function render_slow_query_explorer(array $rows): string
    {
        $rows = array_values($rows);
        $max_value = 1;
        $first_signature = $rows[0]['row_key'] ?? $rows[0]['sql_signature'] ?? '';
        $payload = array();

        foreach ($rows as $row) {
            $max_value = max($max_value, (float)$row['max_ms']);
            $payload[$row['row_key'] ?? $row['sql_signature']] = array(
                'details' => $this->render_slow_query_details($row),
            );
        }

        $json = wp_json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        ob_start();
        ?>
        <div class="wpopt-perf-slow-shell">
            <div class="wpopt-perf-slow-chart-list" role="list">
                <?php foreach ($rows as $index => $row): ?>
                    <?php $width = min(100, max(8, round((((float)$row['max_ms']) / $max_value) * 100, 2))); ?>
                    <?php $row_key = (string)($row['row_key'] ?? $row['sql_signature'] ?? ''); ?>
                    <button type="button" class="wpopt-perf-slow-bar" data-slow-query-target="<?php echo esc_attr($row_key); ?>" data-active="<?php echo $row_key === $first_signature ? '1' : '0'; ?>">
                        <span class="wpopt-perf-slow-rank"><?php echo number_format_i18n($index + 1); ?></span>
                        <span class="wpopt-perf-slow-bar-copy">
                            <span class="wpopt-perf-slow-bar-head">
                                <span>
                                    <span class="wpopt-perf-slow-bar-context"><?php echo esc_html($this->humanize_type((string)$row['request_type']) . ' / ' . $this->short_label((string)$row['request_label'], 30)); ?></span>
                                    <span class="wpopt-perf-slow-bar-title"><?php echo esc_html($this->short_sql_label($row['sql_fingerprint'], 110)); ?></span>
                                </span>
                                <span class="wpopt-perf-slow-bar-peak">
                                    <strong><?php echo esc_html($this->format_ms($row['max_ms'])); ?></strong>
                                    <small><?php _e('Peak', 'wpopt'); ?></small>
                                </span>
                            </span>
                            <span class="wpopt-perf-slow-meter"><span data-wpopt-style-width="<?php echo esc_attr($width); ?>"></span></span>
                            <span class="wpopt-perf-slow-bar-stats">
                                <span class="wpopt-perf-slow-chip"><b><?php echo number_format_i18n((int)$row['hits']); ?></b><?php echo esc_html(_n('sample', 'samples', (int)$row['hits'], 'wpopt')); ?></span>
                                <span class="wpopt-perf-slow-chip"><b><?php echo esc_html($this->format_ms($row['avg_ms'])); ?></b><?php _e('Avg', 'wpopt'); ?></span>
                                <span class="wpopt-perf-slow-chip"><b><?php echo esc_html($this->short_label((string)$row['request_method'], 8)); ?></b><?php echo esc_html($this->short_label((string)$row['request_label'], 22)); ?></span>
                            </span>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>
            <div class="wpopt-perf-slow-details"><?php echo $first_signature && isset($payload[$first_signature]['details']) ? $payload[$first_signature]['details'] : ''; ?></div>
            <script type="application/json" class="wpopt-perf-slow-data"><?php echo $json ?: '{}'; ?></script>
            <script>
                (function () {
                    var root = document.currentScript.parentElement;
                    var dataNode = root.querySelector('.wpopt-perf-slow-data');
                    var detailNode = root.querySelector('.wpopt-perf-slow-details');
                    var targets = root.querySelectorAll('[data-slow-query-target]');
                    var payload = {};

                    if (!dataNode || !detailNode || !targets.length) {
                        return;
                    }

                    try {
                        payload = JSON.parse(dataNode.textContent || '{}');
                    } catch (error) {
                        return;
                    }

                    var activate = function (key) {
                        if (!payload[key]) {
                            return;
                        }

                        detailNode.innerHTML = payload[key].details || '';

                        Array.prototype.forEach.call(targets, function (node) {
                            var active = node.getAttribute('data-slow-query-target') === key;
                            node.setAttribute('data-active', active ? '1' : '0');
                            node.classList.toggle('is-active', active);
                        });
                    };

                    Array.prototype.forEach.call(targets, function (node) {
                        node.addEventListener('click', function () {
                            activate(node.getAttribute('data-slow-query-target'));
                        });

                        node.addEventListener('keydown', function (event) {
                            if (event.key === 'Enter' || event.key === ' ') {
                                event.preventDefault();
                                activate(node.getAttribute('data-slow-query-target'));
                            }
                        });
                    });

                    activate(targets[0].getAttribute('data-slow-query-target'));
                }());
            </script>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_slow_query_details(array $row): string
    {
        ob_start();
        ?>
        <div class="wpopt-perf-slow-detail-head">
            <span class="wpopt-perf-slow-detail-kicker"><?php _e('Selected Query', 'wpopt'); ?></span>
            <h4><?php echo esc_html($this->short_sql_label((string)$row['sql_fingerprint'], 140)); ?></h4>
            <p><?php echo esc_html(__('Inspect the selected captured SQL sample and its request context.', 'wpopt')); ?></p>
        </div>
        <div class="wpopt-perf-slow-metrics">
            <div><span><?php _e('Duration', 'wpopt'); ?></span><strong><?php echo esc_html($this->format_ms($row['max_ms'])); ?></strong></div>
            <div><span><?php _e('Fingerprint', 'wpopt'); ?></span><strong><?php echo esc_html($this->short_label((string)$row['sql_signature'], 12)); ?></strong></div>
            <div><span><?php _e('Captured', 'wpopt'); ?></span><strong><?php echo number_format_i18n((int)$row['hits']); ?></strong></div>
            <div><span><?php _e('Observed', 'wpopt'); ?></span><strong><?php echo esc_html(!empty($row['observed_at']) ? mysql2date('d M Y H:i', $row['observed_at']) : __('Unavailable', 'wpopt')); ?></strong></div>
        </div>
        <div class="wpopt-perf-slow-meta">
            <div class="wpopt-perf-slow-section">
                <strong><?php _e('Example request', 'wpopt'); ?></strong>
                <p><b><?php echo esc_html($this->humanize_type((string)$row['request_type'])); ?></b> | <code><?php echo esc_html((string)$row['request_label']); ?></code> | <?php echo esc_html((string)$row['request_method']); ?></p>
                <p><code><?php echo esc_html((string)$row['request_uri']); ?></code></p>
            </div>
            <div class="wpopt-perf-slow-section">
                <strong><?php _e('Query fingerprint', 'wpopt'); ?></strong>
                <pre><code><?php echo esc_html((string)$row['sql_fingerprint']); ?></code></pre>
            </div>
            <div class="wpopt-perf-slow-section">
                <strong><?php _e('Captured SQL sample', 'wpopt'); ?></strong>
                <pre><code><?php echo esc_html((string)$row['sample_query']); ?></code></pre>
            </div>
            <?php if (!empty($row['query_caller'])): ?>
                <div class="wpopt-perf-slow-section">
                    <strong><?php _e('Caller', 'wpopt'); ?></strong>
                    <pre><code><?php echo esc_html((string)$row['query_caller']); ?></code></pre>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_type_cards(array $rows, array $labels, array $series): string
    {
        $palette = $this->chart_palette();

        ob_start();
        ?>
        <div class="wpopt-perf-type-grid">
            <?php foreach ($rows as $index => $row): ?>
                <?php
                $type = $row['request_type'];
                $color = $palette[$index % count($palette)];
                $values = $series[$type] ?? array();
                ?>
                <div class="wpopt-perf-type-card">
                    <div class="wpopt-perf-type-top">
                        <div>
                            <strong><?php echo esc_html($this->humanize_type($type)); ?></strong>
                            <span><?php echo esc_html(sprintf(_n('%s hit', '%s hits', (int)$row['hits'], 'wpopt'), number_format_i18n((int)$row['hits']))); ?></span>
                        </div>
                        <span class="wpopt-perf-type-accent" data-wpopt-style-color="<?php echo esc_attr($color); ?>"><?php echo esc_html('Avg ' . $this->format_ms($row['avg_ms'])); ?></span>
                    </div>
                    <?php echo $this->render_sparkline($labels, $values, $color); ?>
                    <div class="wpopt-perf-type-stats">
                        <div>
                            <span><?php _e('Avg', 'wpopt'); ?></span>
                            <b><?php echo esc_html($this->format_ms($row['avg_ms'])); ?></b>
                        </div>
                        <div>
                            <span><?php _e('Peak', 'wpopt'); ?></span>
                            <b><?php echo esc_html($this->format_ms($row['max_ms'])); ?></b>
                        </div>
                        <div>
                            <span><?php _e('Slow', 'wpopt'); ?></span>
                            <b><?php echo number_format_i18n((int)$row['slow_hits']); ?></b>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function normalize_type_rows(array $rows): array
    {
        $indexed = array();

        foreach ($rows as $row) {
            $indexed[$row['request_type']] = $row;
        }

        $normalized = array();

        foreach ($this->get_known_request_types() as $type) {
            $normalized[] = $indexed[$type] ?? array(
                'request_type' => $type,
                'hits'         => 0,
                'avg_ms'       => 0,
                'max_ms'       => 0,
                'slow_hits'    => 0,
            );
        }

        return $normalized;
    }

    private function get_known_request_types(): array
    {
        return array('home', 'archive', 'single', 'page', 'media', 'api', 'ajax', 'search', 'feed', '404', 'admin', 'login', 'xmlrpc', 'other');
    }

    private function render_sparkline(array $labels, array $values, string $color): string
    {
        if (empty($values)) {
            return '';
        }

        $width = 180;
        $height = 48;
        $padding = 4;
        $plot_width = $width - ($padding * 2);
        $plot_height = $height - ($padding * 2);
        $max_value = max(1, ...array_map('floatval', $values));
        $point_count = max(1, count($values) - 1);
        $points = array();

        foreach ($values as $index => $value) {
            $x = $padding + (($plot_width / $point_count) * $index);
            $y = $padding + $plot_height - (($plot_height * ((float)$value / $max_value)));
            $points[] = round($x, 2) . ',' . round($y, 2);
        }

        ob_start();
        ?>
        <svg viewBox="0 0 <?php echo $width; ?> <?php echo $height; ?>" width="100%" height="48" role="img" aria-label="<?php esc_attr_e('Small response time chart', 'wpopt'); ?>">
            <line x1="<?php echo $padding; ?>" y1="<?php echo $height - $padding; ?>" x2="<?php echo $width - $padding; ?>" y2="<?php echo $height - $padding; ?>" stroke="#e2e8f0" stroke-width="1"/>
            <polyline fill="none" stroke="<?php echo esc_attr($color); ?>" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" points="<?php echo esc_attr(implode(' ', $points)); ?>"></polyline>
            <circle cx="<?php echo esc_attr(explode(',', end($points))[0]); ?>" cy="<?php echo esc_attr(explode(',', end($points))[1]); ?>" r="3" fill="<?php echo esc_attr($color); ?>"></circle>
            <text x="<?php echo $padding; ?>" y="10" fill="#64748b" font-size="9"><?php echo esc_html(reset($labels) ?: ''); ?></text>
            <text x="<?php echo $width - $padding; ?>" y="10" text-anchor="end" fill="#64748b" font-size="9"><?php echo esc_html(end($labels) ?: ''); ?></text>
        </svg>
        <?php
        return ob_get_clean();
    }

    private function render_empty_state(string $message): string
    {
        return '<div class="wpopt-perf-empty">' . esc_html($message) . '</div>';
    }

    private function calculate_ratio(int $hits, int $misses): float
    {
        $total = max(0, $hits + $misses);

        if ($total === 0) {
            return 0.0;
        }

        return round(($hits / $total) * 100, 2);
    }

    private function chart_palette(): array
    {
        return array('#0f766e', '#ea580c', '#2563eb', '#dc2626', '#7c3aed', '#059669');
    }

    private function humanize_type(string $type): string
    {
        if ($type === 'api') {
            return 'API';
        }

        if ($type === 'xmlrpc') {
            return 'XML-RPC';
        }

        if ($type === '404') {
            return '404';
        }

        return ucwords(str_replace(array('_', '-'), ' ', $type));
    }

    private function short_label(string $label, int $length = 12): string
    {
        if (strlen($label) <= $length) {
            return $label;
        }

        return substr($label, 0, max(1, $length - 1)) . '...';
    }

    private function short_sql_label(string $label, int $length = 84): string
    {
        $label = preg_replace('/\s+/', ' ', trim($label));

        return $this->short_label((string)$label, $length);
    }

    private function limit_string(string $value, int $length): string
    {
        return substr($value, 0, $length);
    }

    private function format_ms($value): string
    {
        return number_format_i18n((float)$value, 1) . ' ms';
    }

    private function format_percent(float $value): string
    {
        return number_format_i18n($value, 1) . '%';
    }

    private function format_avg_count(float $value, int $max_decimals = 1): string
    {
        $value = round($value, $max_decimals);
        $decimals = abs($value - round($value)) > 0.001 ? $max_decimals : 0;

        return number_format_i18n($value, $decimals);
    }

    private function format_component_link_label(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        $label = preg_replace('#^https?://#i', '', $url);
        $label = rtrim((string)$label, '/');

        return $this->short_label((string)$label, 48);
    }
}

return __NAMESPACE__;
