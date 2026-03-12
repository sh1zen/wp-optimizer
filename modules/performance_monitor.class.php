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
use WPS\core\RequestActions;
use WPS\core\Rewriter;
use WPS\modules\Module;

class Mod_Performance_Monitor extends Module
{
    public static ?string $name = 'Performance Monitor';

    public array $scopes = array('autoload', 'admin-page', 'settings');

    protected string $context = 'wpopt';

    private float $request_start_time = 0.0;

    private array $request_profile = array();

    private bool $response_detached = false;

    protected function init(): void
    {
        $message = sanitize_key((string)($_GET['message'] ?? ''));

        if ($message === 'wpopt-performance-history-reset') {
            $this->add_notices('success', __('Performance history has been reset.', 'wpopt'));
        }
        elseif ($message === 'wpopt-performance-history-cleaned') {
            $this->add_notices('success', __('Old performance history has been cleaned.', 'wpopt'));
        }

        if (!$this->option('monitor.active', true) || !$this->should_capture_request()) {
            return;
        }

        $this->request_start_time = $this->resolve_request_start_time();

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

    public function actions(): void
    {
        CronActions::schedule('WPOPT-PerformanceMonitorCleanup', DAY_IN_SECONDS, function () {
            $this->cleanup_history();
        }, '02:30');

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

    public function store_request_metrics(): void
    {
        global $wpdb;

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

        $duration_ms = max(0, round((microtime(true) - $this->request_start_time) * 1000, 3));
        $slow_threshold = max(1, absint($this->option('monitor.slow_request_ms', 1500)));

        $data = array(
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
            'is_slow'          => $duration_ms >= $slow_threshold ? 1 : 0,
            'created_at'       => current_time('mysql'),
            'created_at_gmt'   => current_time('mysql', true),
        );

        $wpdb->insert(
            WPOPT_TABLE_REQUEST_PERFORMANCE,
            $data,
            array('%d', '%s', '%s', '%s', '%s', '%d', '%f', '%d', '%d', '%d', '%d', '%s', '%s')
        );
    }

    protected function setting_fields($filter = ''): array
    {
        return $this->group_setting_fields(
            $this->group_setting_fields(
                $this->setting_field(__('Enable performance history monitoring', 'wpopt'), 'monitor.active', 'checkbox', array('default_value' => true)),
                $this->setting_field(__('Slow request threshold (ms)', 'wpopt'), 'monitor.slow_request_ms', 'numeric', array('default_value' => 1500, 'parent' => 'monitor.active')),
                $this->setting_field(__('Sampling rate (%)', 'wpopt'), 'monitor.sample_rate', 'numeric', array('default_value' => 100, 'parent' => 'monitor.active')),
                $this->setting_field(__('Keep history for (days)', 'wpopt'), 'monitor.retention_days', 'numeric', array('default_value' => 30, 'parent' => 'monitor.active'))
            ),
            $this->group_setting_fields(
                $this->setting_field(__('Track REST API endpoints', 'wpopt'), 'monitor.capture_rest', 'checkbox', array('default_value' => true, 'parent' => 'monitor.active')),
                $this->setting_field(__('Track admin-ajax requests', 'wpopt'), 'monitor.capture_ajax', 'checkbox', array('default_value' => false, 'parent' => 'monitor.active')),
                $this->setting_field(__('Track wp-admin requests', 'wpopt'), 'monitor.capture_admin', 'checkbox', array('default_value' => true, 'parent' => 'monitor.active')),
                $this->setting_field(__('Track wp-login requests', 'wpopt'), 'monitor.capture_login', 'checkbox', array('default_value' => false, 'parent' => 'monitor.active')),
                $this->setting_field(__('Track XML-RPC requests', 'wpopt'), 'monitor.capture_xmlrpc', 'checkbox', array('default_value' => false, 'parent' => 'monitor.active'))
            )
        );
    }

    protected function infos(): array
    {
        return array(
            'monitor.slow_request_ms' => __('Requests at or above this threshold are marked as slow in the dashboard.', 'wpopt'),
            'monitor.sample_rate'     => __('Use sampling to reduce writes on high-traffic sites. 100 means every matching request is stored.', 'wpopt'),
            'monitor.retention_days'  => __('Older rows are removed automatically by a daily cleanup task.', 'wpopt'),
            'monitor.capture_rest'    => __('Store performance history for WordPress REST API routes.', 'wpopt'),
            'monitor.capture_ajax'    => __('Store performance history for admin-ajax traffic.', 'wpopt'),
            'monitor.capture_admin'   => __('Store performance history for wp-admin pages.', 'wpopt'),
            'monitor.capture_login'   => __('Store performance history for wp-login.php requests.', 'wpopt'),
            'monitor.capture_xmlrpc'  => __('Store performance history for XML-RPC requests.', 'wpopt'),
        );
    }

    public function validate_settings($input, $filtering = false): array
    {
        $valid = parent::validate_settings($input, $filtering);

        $valid['monitor']['slow_request_ms'] = max(1, absint($valid['monitor']['slow_request_ms'] ?? 1500));
        $valid['monitor']['sample_rate'] = min(100, max(1, absint($valid['monitor']['sample_rate'] ?? 100)));
        $valid['monitor']['retention_days'] = max(1, absint($valid['monitor']['retention_days'] ?? 30));

        return $valid;
    }

    protected function render_sub_modules(): void
    {
        $range = $this->get_selected_range();
        $window = $this->get_range_windows()[$range];
        $from_gmt = gmdate('Y-m-d H:i:s', time() - $window['seconds']);

        $summary = $this->get_summary($from_gmt);
        $types = $this->get_request_type_breakdown($from_gmt);
        $snapshot_types = $this->normalize_type_rows($types);
        $labels = $this->get_request_label_breakdown($from_gmt);
        $recent = $this->get_recent_requests($from_gmt, 20);
        $type_series = $this->get_time_series($window, $from_gmt, array_column($snapshot_types, 'request_type'));
        $series = $this->get_time_series($window, $from_gmt, array_slice(array_column($types, 'request_type'), 0, 4));
        ?>
        <section class="wps-wrap">
            <style>
                .wpopt-perf-shell { display:grid; gap:20px; }
                .wpopt-perf-toolbar { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
                .wpopt-perf-toolbar form { margin:0; }
                .wpopt-perf-kpis { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:14px; }
                .wpopt-perf-kpi { border:1px solid rgba(15, 23, 42, 0.08); border-radius:14px; padding:16px; background:#fff; }
                .wpopt-perf-kpi span { display:block; font-size:12px; text-transform:uppercase; letter-spacing:.08em; color:#64748b; margin-bottom:8px; }
                .wpopt-perf-kpi strong { font-size:24px; color:#0f172a; }
                .wpopt-perf-grid { display:grid; grid-template-columns:1.6fr 1fr; gap:18px; }
                .wpopt-perf-chart { background:linear-gradient(180deg, #ffffff 0%, #f8fafc 100%); border:1px solid rgba(15, 23, 42, 0.08); border-radius:16px; padding:16px; overflow:auto; }
                .wpopt-perf-chart h3, .wpopt-perf-table h3 { margin-top:0; }
                .wpopt-perf-legend { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px; font-size:12px; color:#475569; }
                .wpopt-perf-legend span { display:inline-flex; align-items:center; gap:6px; }
                .wpopt-perf-swatch { width:10px; height:10px; border-radius:999px; display:inline-block; }
                .wpopt-perf-table { overflow:auto; }
                .wpopt-perf-empty { padding:18px; border:1px dashed #cbd5e1; border-radius:12px; color:#64748b; background:#f8fafc; }
                .wpopt-perf-actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
                .wpopt-perf-type-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; }
                .wpopt-perf-type-card { border:1px solid rgba(15, 23, 42, 0.08); border-radius:14px; padding:14px; background:#fff; }
                .wpopt-perf-type-top { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; margin-bottom:10px; }
                .wpopt-perf-type-top strong { display:block; color:#0f172a; }
                .wpopt-perf-type-top span { font-size:12px; color:#64748b; }
                .wpopt-perf-type-stats { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:8px; margin-top:10px; font-size:12px; }
                .wpopt-perf-type-stats b { display:block; color:#0f172a; font-size:14px; }
                @media (max-width: 980px) {
                    .wpopt-perf-grid { grid-template-columns:1fr; }
                }
            </style>
            <block class="wps">
                <section class="wps-header"><h1><?php _e('Performance Monitor', 'wpopt'); ?></h1></section>
                <div class="wpopt-perf-shell">
                    <div class="wpopt-perf-toolbar">
                        <p class="wpopt-muted"><?php echo esc_html(sprintf(__('Historical request performance split by type for %s.', 'wpopt'), $window['label'])); ?></p>
                        <form method="get" autocomplete="off">
                            <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page'] ?? 'wpopt-' . $this->slug); ?>">
                            <label for="wpopt-perf-range"><strong><?php _e('Window', 'wpopt'); ?></strong></label>
                            <select id="wpopt-perf-range" name="range">
                                <?php foreach ($this->get_range_windows() as $key => $config): ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($range, $key); ?>><?php echo esc_html($config['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="wps wps-button wpopt-btn is-info"><?php _e('Apply', 'wpopt'); ?></button>
                        </form>
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

                    <div class="wpopt-perf-grid">
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
                            <h3><?php _e('Maintenance', 'wpopt'); ?></h3>
                            <p><?php echo esc_html(sprintf(__('Retention is currently set to %s days.', 'wpopt'), number_format_i18n($this->option('monitor.retention_days', 30)))); ?></p>
                            <form method="post" class="wpopt-perf-actions">
                                <?php RequestActions::nonce_field($this->action_hook); ?>
                                <?php echo RequestActions::get_action_button($this->action_hook, 'cleanup_history', __('Run cleanup now', 'wpopt'), 'wps wps-button wpopt-btn is-neutral'); ?>
                                <?php echo RequestActions::get_action_button($this->action_hook, 'reset_history', __('Reset history', 'wpopt'), 'wps wps-button wpopt-btn is-danger'); ?>
                                <a class="wps wps-button wpopt-btn is-info" href="<?php echo esc_url(wps_module_setting_url('wpopt', $this->slug)); ?>"><?php _e('Open settings', 'wpopt'); ?></a>
                            </form>
                        </block>
                    </div>

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
            </block>
        </section>
        <?php
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

        return substr($uri, 0, 65000);
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

    private function cleanup_history(): int
    {
        global $wpdb;

        $retention_days = max(1, absint($this->option('monitor.retention_days', 30)));
        $threshold = gmdate('Y-m-d H:i:s', time() - ($retention_days * DAY_IN_SECONDS));

        return (int)$wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . WPOPT_TABLE_REQUEST_PERFORMANCE . ' WHERE created_at_gmt IS NOT NULL AND created_at_gmt < %s',
                $threshold
            )
        );
    }

    private function get_selected_range(): string
    {
        $range = sanitize_key((string)($_GET['range'] ?? '24h'));
        $windows = $this->get_range_windows();
        return isset($windows[$range]) ? $range : '24h';
    }

    private function get_range_windows(): array
    {
        return array(
            '24h' => array('label' => __('Last 24 hours', 'wpopt'), 'seconds' => DAY_IN_SECONDS, 'bucket' => 'hour', 'count' => 24),
            '7d'  => array('label' => __('Last 7 days', 'wpopt'), 'seconds' => 7 * DAY_IN_SECONDS, 'bucket' => 'day', 'count' => 7),
            '30d' => array('label' => __('Last 30 days', 'wpopt'), 'seconds' => 30 * DAY_IN_SECONDS, 'bucket' => 'day', 'count' => 30),
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
                <span><i class="wpopt-perf-swatch" style="background:<?php echo esc_attr($palette[$legend_index % count($palette)]); ?>"></i><?php echo esc_html($this->humanize_type($name)); ?></span>
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
                        <span style="color:<?php echo esc_attr($color); ?>"><?php echo esc_html('Avg ' . $this->format_ms($row['avg_ms'])); ?></span>
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

    private function limit_string(string $value, int $length): string
    {
        return substr($value, 0, $length);
    }

    private function format_ms($value): string
    {
        return number_format_i18n((float)$value, 1) . ' ms';
    }
}

return __NAMESPACE__;
