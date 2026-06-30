<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\core;

use WPS\core\CronActions;
use WPS\core\Graphic;
use WPS\core\UtilEnv;

/**
 * Creates the menu page for the plugin.
 *
 * Provides the functionality necessary for rendering the page corresponding
 * to the menu with which this page is associated.
 */
class PagesHandler
{
    private const DASHBOARD_CACHE_TTL = 30;
    private const ACTIVATED_AT_OPTION = 'wpopt_activated_at';
    private const DASHBOARD_HEALTH_OPTIONAL_MODULES = array(
        'activitylog',
        'performance_monitor',
        'wp_mail',
        'wp_updates',
        'widget',
        'wp_info',
    );

    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_plugin_pages'));
        add_action('admin_enqueue_scripts', array($this, 'register_assets'), 20, 1);
        add_filter('admin_body_class', array($this, 'admin_body_classes'));
        add_filter('wps_wpopt_core_settings_pages', array($this, 'register_core_setting_pages'), 10, 2);

        add_action('admin_init', [$this, 'handle_notice_actions'], 5, 0);
        add_action('admin_footer', [$this, 'render_monthly_summary_overlay'], 10, 0);
    }

    public function handle_notice_actions(): void
    {
        if (!get_option(self::ACTIVATED_AT_OPTION, false)) {
            $file_timestamp = file_exists(WPOPT_FILE) ? (int)filemtime(WPOPT_FILE) : 0;
            $activated_at = $file_timestamp > 0 ? min($file_timestamp, time()) : time();

            add_option(self::ACTIVATED_AT_OPTION, $activated_at, '', 'no');
        }

        $user_id = wps_core()->get_cuID();
        $dismiss_nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

        if (isset($_GET['wpopt-already-did-notice']) && $dismiss_nonce && wp_verify_nonce($dismiss_nonce, 'wpopt-already-did-notice')) {

            wps('wpopt')->options->add($user_id, 'dismissed', true, 'admin-notice', 6 * MONTH_IN_SECONDS);
            wp_safe_redirect(remove_query_arg(array(
                'wpopt-already-did-notice',
                'wpopt-dismiss-notice',
                '_wpnonce',
            )));
            exit;
        }
        elseif (isset($_GET['wpopt-dismiss-notice']) && $dismiss_nonce && wp_verify_nonce($dismiss_nonce, 'wpopt-dismiss-notice')) {

            wps('wpopt')->options->add($user_id, 'dismissed', true, 'admin-notice', MONTH_IN_SECONDS);
            wp_safe_redirect(remove_query_arg(array(
                'wpopt-already-did-notice',
                'wpopt-dismiss-notice',
                '_wpnonce',
            )));
            exit;
        }
    }

    public function render_monthly_summary_overlay(): void
    {
        if (!current_user_can('customize')) {
            return;
        }

        $user_id = wps_core()->get_cuID();

        if (wps('wpopt')->options->get($user_id, 'dismissed', 'admin-notice', false)) {
            return;
        }

        $activated_at = absint(get_option(self::ACTIVATED_AT_OPTION, 0));

        if ($activated_at <= 0 || time() < ($activated_at + MONTH_IN_SECONDS)) {
            return;
        }

        $seen_once_key = 'wpopt_monthly_summary_seen_once';
        $seen_once = (bool)get_user_meta($user_id, $seen_once_key, true);

        if ($seen_once && !$this->is_wp_optimizer_admin_screen('')) {
            return;
        }

        if (!$seen_once) {
            update_user_meta($user_id, $seen_once_key, time());
        }

        global $wpdb;

        $dashboard = $this->get_dashboard_view_model();
        $days_active = max(30, (int)floor((time() - $activated_at) / DAY_IN_SECONDS));
        $usage_period_label = $days_active < 60
            ? __('Your first month with WP Optimizer', 'wpopt')
            : sprintf(__('Your WP Optimizer report after %s days', 'wpopt'), number_format_i18n($days_active));
        $module_handler = wps('wpopt')->moduleHandler;
        $excluded_modules = array('cron', 'cloudflare', 'modules_handler', 'settings', 'tracking');
        $all_modules = $module_handler->get_modules(array('excepts' => $excluded_modules), false);
        $active_modules = array();

        foreach ($all_modules as $module) {
            if ($module_handler->module_is_active($module['slug'])) {
                $active_modules[] = $module['name'];
            }
        }

        $active_modules_count = count($active_modules);
        $media_scanned_id = absint(wps('wpopt')->options->get('last_scanned_postID', 'scan_media', 'media', 0));
        $optimized_images_count = $media_scanned_id > 0
            ? (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->posts WHERE ID <= %d AND post_type = 'attachment' AND post_mime_type LIKE %s", $media_scanned_id, '%image%'))
            : 0;
        $all_media_count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'attachment' AND post_mime_type LIKE %s", '%image%'));
        $media_left_count = max(0, $all_media_count - $optimized_images_count);
        list($optimized_size, $original_size, $last_media_stat_id) = wps('wpopt')->options->get('size_prevsize', 'stats', 'media', [0, 0, 0]);
        $last_media_stat_id = absint($last_media_stat_id);
        $pending_media_rows = $wpdb->get_results($wpdb->prepare("SELECT value FROM " . wps('wpopt')->options->table_name() . " WHERE item = %s AND context = %s AND id > %d ORDER BY id LIMIT 10000", 'optimized_images', 'media', $last_media_stat_id), ARRAY_A);

        foreach ($pending_media_rows as $pending_media_row) {
            $media_value = maybe_unserialize($pending_media_row['value']);

            if (!is_array($media_value)) {
                continue;
            }

            $original_size += absint($media_value['prev_size'] ?? 0);
            $optimized_size += absint($media_value['size'] ?? 0);
        }

        $saved_bytes = max(0, $original_size - $optimized_size);
        $saved_space_percent = $original_size > 0 ? min(round(($saved_bytes / $original_size) * 100, 2), 100) : 0;
        $processed_percent = ($all_media_count > 0 && $optimized_images_count > 0) ? min(round(($optimized_images_count / $all_media_count) * 100, 2), 100) : 0;
        $format_percent = static function (float $value): string {
            $precision = abs($value - round($value)) < 0.005 ? 0 : (abs(($value * 10) - round($value * 10)) < 0.005 ? 1 : 2);

            return number_format_i18n($value, $precision) . '%';
        };

        $performance_summary = array(
            'total_requests' => 0,
            'avg_ms'         => 0,
            'slow_hits'      => 0,
        );

        if (defined('WPOPT_TABLE_REQUEST_PERFORMANCE')) {
            $performance_table = WPOPT_TABLE_REQUEST_PERFORMANCE;

            if (preg_match('/^[A-Za-z0-9_]+$/', $performance_table)) {
                $performance_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $performance_table));

                if ($performance_table_exists === $performance_table) {
                    $performance_row = $wpdb->get_row('SELECT COUNT(*) AS total_requests, AVG(response_time_ms) AS avg_ms, SUM(is_slow) AS slow_hits FROM ' . $performance_table, ARRAY_A);

                    if (is_array($performance_row)) {
                        $performance_summary = array(
                            'total_requests' => absint($performance_row['total_requests'] ?? 0),
                            'avg_ms'         => (float)($performance_row['avg_ms'] ?? 0),
                            'slow_hits'      => absint($performance_row['slow_hits'] ?? 0),
                        );
                    }
                }
            }
        }

        $cache_metrics = wps('wpopt')->options->get('wpopt_perf_cache_cumulative', 'cumulative_cache_metrics', 'performance_monitor', array(), false);
        $cache_metrics = is_array($cache_metrics) ? $cache_metrics : array();
        $db_cache_hits = absint($cache_metrics['db_cache_hits'] ?? 0);
        $db_cache_misses = absint($cache_metrics['db_cache_misses'] ?? 0);
        $query_cache_hits = absint($cache_metrics['query_cache_hits'] ?? 0);
        $query_cache_misses = absint($cache_metrics['query_cache_misses'] ?? 0);
        $cache_hits = $db_cache_hits + $query_cache_hits;
        $cache_misses = $db_cache_misses + $query_cache_misses;
        $cache_operations = $cache_hits + $cache_misses;
        $cache_hit_ratio = $cache_operations > 0 ? round(($cache_hits / $cache_operations) * 100, 2) : 0;
        $static_cache_stats = array(
            'hits'    => 0,
            'misses'  => 0,
            'writes'  => 0,
            'entries' => 0,
            'bytes'   => 0,
        );

        foreach ((array)wps('wpopt')->options->get_all('static_cache_rule_stats', 'cache', []) as $static_cache_row) {
            $rule_stats = (array)($static_cache_row['value'] ?? array());
            $static_cache_stats['hits'] += absint($rule_stats['hits'] ?? 0);
            $static_cache_stats['misses'] += absint($rule_stats['misses'] ?? 0);
            $static_cache_stats['writes'] += absint($rule_stats['writes'] ?? 0);
        }

        $static_cache_stats['entries'] = $this->count_static_cache_entries();
        $static_cache_stats['bytes'] = $this->get_static_cache_storage_bytes();

        $dynamic_cards = array();
        $fallback_cards = array();
        $add_card = static function (array &$cards, array $card): void {
            foreach ($cards as $existing_card) {
                if (($existing_card['label'] ?? '') === ($card['label'] ?? '')) {
                    return;
                }
            }

            $cards[] = $card;
        };

        if ($performance_summary['total_requests'] > 0) {
            $add_card($dynamic_cards, array(
                'label' => __('Requests monitored', 'wpopt'),
                'value' => number_format_i18n($performance_summary['total_requests']),
                'note'  => sprintf(__('Average response: %1$s ms, slow requests: %2$s.', 'wpopt'), number_format_i18n($performance_summary['avg_ms'], 0), number_format_i18n($performance_summary['slow_hits'])),
            ));
        }

        if ($cache_operations > 0) {
            $add_card($dynamic_cards, array(
                'label' => __('Cache hits', 'wpopt'),
                'value' => number_format_i18n($cache_hits),
                'note'  => sprintf(__('Hit ratio: %1$s, tracked operations: %2$s.', 'wpopt'), $format_percent((float)$cache_hit_ratio), number_format_i18n($cache_operations)),
            ));
        }

        if (($static_cache_stats['hits'] + $static_cache_stats['misses'] + $static_cache_stats['writes'] + $static_cache_stats['entries']) > 0) {
            $add_card($dynamic_cards, array(
                'label' => __('Static cache', 'wpopt'),
                'value' => number_format_i18n($static_cache_stats['hits']),
                'note'  => sprintf(__('Entries: %1$s, writes: %2$s, storage: %3$s.', 'wpopt'), number_format_i18n($static_cache_stats['entries']), number_format_i18n($static_cache_stats['writes']), size_format($static_cache_stats['bytes'], 2)),
            ));
        }

        if ($optimized_images_count > 0) {
            $add_card($dynamic_cards, array(
                'label' => __('Images optimized', 'wpopt'),
                'value' => number_format_i18n($optimized_images_count),
                'note'  => sprintf(__('%1$s processed, %2$s images left.', 'wpopt'), $format_percent((float)$processed_percent), number_format_i18n($media_left_count)),
            ));
        }

        if ($saved_bytes > 0) {
            $add_card($dynamic_cards, array(
                'label' => __('Media speedup', 'wpopt'),
                'value' => $format_percent((float)$saved_space_percent),
                'note'  => sprintf(__('Estimated space saved: %s.', 'wpopt'), size_format($saved_bytes, 2)),
            ));
        }

        if ($dashboard['persistent_cache']) {
            $add_card($dynamic_cards, array(
                'label' => __('Persistent cache', 'wpopt'),
                'value' => __('Enabled', 'wpopt'),
                'note'  => __('Persistent cache layer detected.', 'wpopt'),
            ));
        }

        if ($dashboard['tracking_enabled']) {
            $add_card($fallback_cards, array(
                'label' => __('Diagnostics', 'wpopt'),
                'value' => __('Enabled', 'wpopt'),
                'note'  => __('Diagnostics and tracking controls are configured.', 'wpopt'),
            ));
        }

        $add_card($fallback_cards, array(
            'label' => __('Scheduler', 'wpopt'),
            'value' => $dashboard['is_cron_running'] ? __('Running', 'wpopt') : __('Ready', 'wpopt'),
            'note'  => $dashboard['is_cron_running'] ? __('A scheduled optimization task is currently running.', 'wpopt') : __('Scheduled optimization tasks are ready.', 'wpopt'),
        ));

        if ($all_media_count > 0) {
            $add_card($fallback_cards, array(
                'label' => __('Media library', 'wpopt'),
                'value' => number_format_i18n($all_media_count),
                'note'  => __('Images detected and available for optimization.', 'wpopt'),
            ));
        }

        if (!empty($active_modules)) {
            $add_card($fallback_cards, array(
                'label' => __('Enabled tools', 'wpopt'),
                'value' => number_format_i18n($active_modules_count),
                'note'  => implode(', ', array_slice($active_modules, 0, 4)),
            ));
        }

        foreach ($fallback_cards as $fallback_card) {
            if (count($dynamic_cards) >= 3) {
                break;
            }

            $add_card($dynamic_cards, $fallback_card);
        }

        $dynamic_cards = array_slice($dynamic_cards, 0, 3);
        $donation_url = $this->get_donation_url();
        $review_url = $this->get_review_url();
        $already_did_url = wp_nonce_url(add_query_arg('wpopt-already-did-notice', '1'), 'wpopt-already-did-notice');
        $dismiss_url = wp_nonce_url(add_query_arg('wpopt-dismiss-notice', '1'), 'wpopt-dismiss-notice');

        $summary_cards = array_merge(array(
            array(
                'label' => __('Days active', 'wpopt'),
                'value' => sprintf(_n('%s day', '%s days', $days_active, 'wpopt'), number_format_i18n($days_active)),
                'note'  => __('First month threshold reached.', 'wpopt'),
            ),
            array(
                'label' => __('Active modules', 'wpopt'),
                'value' => number_format_i18n($active_modules_count),
                'note'  => __('Optimization tools currently enabled.', 'wpopt'),
            ),
            array(
                'label' => __('Optimization health', 'wpopt'),
                'value' => sprintf('%s%%', number_format_i18n($dashboard['health_score'])),
                'note'  => $dashboard['health_score'] >= 80 ? __('Current setup looks healthy.', 'wpopt') : __('Some settings may need review.', 'wpopt'),
            ),
        ), $dynamic_cards);

        $completed_items = array(
            $dashboard['tracking_enabled'] ? __('Diagnostics and tracking controls are configured.', 'wpopt') : __('Diagnostics are available but currently disabled.', 'wpopt'),
            $dashboard['is_cron_running'] ? __('A scheduled optimization task is currently running.', 'wpopt') : __('Scheduled optimization tasks are ready.', 'wpopt'),
            $dashboard['persistent_cache'] ? __('Persistent cache support is active.', 'wpopt') : __('Persistent cache support is ready to be configured.', 'wpopt'),
        );

        if (!empty($active_modules)) {
            $completed_items[] = sprintf(__('Enabled tools: %s.', 'wpopt'), implode(', ', array_slice($active_modules, 0, 6)));
        }

        if (!wp_style_is('wpopt_css', 'done')) {
            wp_print_styles('wpopt_css');
        }

        ?>
        <div class="wpopt-advertise-overlay" role="dialog" aria-modal="true" aria-labelledby="wpopt-advertise-title">
            <div class="wpopt-advertise-page" data-wpopt-advertise-report>
                <button class="wpopt-advertise-close" type="button" data-wpopt-advertise-close aria-label="<?php esc_attr_e('Close', 'wpopt'); ?>">&times;</button>

                <section class="wpopt-advertise-hero">
                    <span class="wpopt-advertise-eyebrow"><?php echo esc_html($usage_period_label); ?></span>
                    <h1 id="wpopt-advertise-title"><?php esc_html_e('Here is what WP Optimizer has prepared for your site.', 'wpopt'); ?></h1>
                </section>

                <section class="wpopt-advertise-grid" aria-label="<?php esc_attr_e('Monthly optimization summary', 'wpopt'); ?>">
                    <?php foreach ($summary_cards as $card) : ?>
                        <article class="wpopt-advertise-card">
                            <span><?php echo esc_html($card['label']); ?></span>
                            <strong><?php echo esc_html($card['value']); ?></strong>
                            <small><?php echo esc_html($card['note']); ?></small>
                        </article>
                    <?php endforeach; ?>
                </section>

                <section class="wpopt-advertise-recap">
                    <div>
                        <h2><?php esc_html_e('Summary of what is active', 'wpopt'); ?></h2>
                        <ul>
                            <?php foreach ($completed_items as $item) : ?>
                                <li><?php echo esc_html($item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="wpopt-advertise-meter" aria-label="<?php esc_attr_e('Optimization health', 'wpopt'); ?>">
                        <span><?php esc_html_e('Optimization health', 'wpopt'); ?></span>
                        <strong><?php echo esc_html(sprintf('%s%%', number_format_i18n($dashboard['health_score']))); ?></strong>
                        <em><b style="--wpopt-meter-width: <?php echo esc_attr($dashboard['health_score']); ?>%; width: <?php echo esc_attr($dashboard['health_score']); ?>%"></b></em>
                    </div>
                </section>
            </div>
            <div class="wpopt-advertise-support-screen" data-wpopt-advertise-support-screen>
                <section class="wpopt-advertise-support">
                    <div class="wpopt-advertise-support-copy">
                        <h2><?php esc_html_e('Enjoying WP Optimizer?', 'wpopt'); ?></h2>
                        <p><strong><?php esc_html_e('If WP Optimizer is helping you save time and keep your site faster, buying me a coffee is the simplest way to support updates, fixes, and new features.', 'wpopt'); ?></strong></p>
                    </div>
                    <div class="wpopt-advertise-actions">
                        <a class="wpopt-advertise-button is-primary" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($donation_url); ?>"><?php esc_html_e('Buy me a coffe', 'wpopt'); ?></a>
                        <a class="wpopt-advertise-button" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($review_url); ?>"><?php esc_html_e('Leave a review', 'wpopt'); ?></a>
                        <a class="wpopt-advertise-button" href="<?php echo esc_url($already_did_url); ?>"><?php esc_html_e('Already did', 'wpopt'); ?></a>
                        <a class="wpopt-advertise-button is-muted" href="<?php echo esc_url($dismiss_url); ?>"><?php esc_html_e('No Thanks', 'wpopt'); ?></a>
                    </div>
                </section>
            </div>
        </div>
        <script>
            (function () {
                var closeButton = document.querySelector('[data-wpopt-advertise-close]');

                if (!closeButton) {
                    return;
                }

                closeButton.addEventListener('click', function () {
                    var overlay = closeButton.closest('.wpopt-advertise-overlay');

                    if (!overlay) {
                        return;
                    }

                    var report = overlay.querySelector('[data-wpopt-advertise-report]');
                    var supportScreen = overlay.querySelector('[data-wpopt-advertise-support-screen]');

                    if (report) {
                        report.classList.add('is-hidden');
                    }

                    if (supportScreen) {
                        supportScreen.classList.add('is-visible');
                    }
                });
            })();
        </script>
        <?php
    }

    public function add_plugin_pages(): void
    {
        add_menu_page(
            'WP Optimizer',
            'WP Optimizer',
            'customize',
            'wp-optimizer',
            array($this, 'render_main'),
            'dashicons-admin-site'
        );

        $this->add_plugin_submenu_pages('wp-optimizer', 'wpopt', 'wpopt');

        foreach (wps('wpopt')->moduleHandler->get_modules(array('scopes' => 'admin-page'), true) as $module) {
            wps('wpopt')->moduleHandler->get_module_instance($module)->register_panel(null);
        }

        add_submenu_page(null, __('WPOPT Settings', 'wpopt'), __('Settings', 'wpopt'), 'manage_options', 'wpopt-settings', array($this, 'render_core_settings'));
        add_submenu_page(null, __('WPOPT FAQ', 'wpopt'), __('FAQ', 'wpopt'), 'edit_posts', 'wpopt-faqs', array($this, 'render_faqs'));

        add_action('wpopt_enqueue_panel_scripts', [$this, 'enqueue_scripts']);
    }

    private function get_logo_url(): string
    {
        $logo_asset = UtilEnv::resolve_asset(WPOPT_ABSPATH, 'assets/logo.png', false);

        if (!file_exists($logo_asset['path'])) {
            return '';
        }

        return $logo_asset['url'];
    }

    private function add_plugin_submenu_pages(string $parent_slug, string $context, string $text_domain): void
    {
        add_submenu_page(
            $parent_slug,
            __('Dashboard', $text_domain),
            __('Dashboard', $text_domain),
            'customize',
            $parent_slug,
            array($this, 'render_main')
        );

        $labels = array(
            'modules_handler' => __('Modules', $text_domain),
            'cron'            => __('Schedule', $text_domain),
            'cloudflare'      => __('Cloudflare', $text_domain),
            'settings'        => __('Settings', $text_domain),
            'tracking'        => __('Tracking', $text_domain),
        );

        foreach (wps($context)->settings->get_core_setting_pages() as $page) {
            if (!isset($labels[$page['id']])) {
                continue;
            }

            add_submenu_page(
                $parent_slug,
                $labels[$page['id']],
                $labels[$page['id']],
                'manage_options',
                $parent_slug . '&wps-page=setting-' . $page['id'],
                array($this, 'render_main')
            );
        }
    }

    public function register_core_setting_pages(array $pages, $settings = null): array
    {
        $definitions = array(
            'modules_handler' => array(
                'label'       => __('Modules', 'wpopt'),
                'icon'        => 'grid',
                'description' => __('Choose which WP Optimizer tools are available in the workspace.', 'wpopt'),
            ),
            'cron'            => array(
                'label'       => __('Schedule', 'wpopt'),
                'icon'        => 'calendar',
                'description' => __('Configure automatic optimization tasks and scheduled maintenance.', 'wpopt'),
            ),
            'cloudflare'      => array(
                'label'       => __('Cloudflare', 'wpopt'),
                'icon'        => 'server',
                'description' => __('Manage Cloudflare integration settings for this WordPress installation.', 'wpopt'),
            ),
            'settings'        => array(
                'label'       => __('Settings', 'wpopt'),
                'icon'        => 'settings',
                'description' => __('Manage import, export, reset and shared plugin options.', 'wpopt'),
            ),
            'tracking'        => array(
                'label'       => __('Tracking', 'wpopt'),
                'icon'        => 'chart',
                'description' => __('Control diagnostics and anonymous usage tracking.', 'wpopt'),
            ),
        );

        foreach ($definitions as $module_slug => $definition) {
            $object = wps('wpopt')->moduleHandler->get_module_instance($module_slug);

            if (!$object) {
                continue;
            }

            $pages[] = array_merge($definition, array(
                'id'       => $module_slug,
                'callback' => array($object, 'render_settings'),
            ));
        }

        return $pages;
    }

    public function render_modules_settings(): void
    {
        $this->enqueue_scripts();

        if (WPOPT_DEBUG) {
            wps_core()->meter->lap('Modules settings pre render');
        }

        wps('wpopt')->settings->render_modules_settings();

        if (WPOPT_DEBUG) {
            wps_core()->meter->lap('Modules settings rendered');
        }
    }

    public function enqueue_scripts(): void
    {
        wp_enqueue_style('wpopt_css');
        wp_enqueue_script('vendor-wps-js');
        wp_enqueue_script('wpopt_admin_js');
    }

    public function render_core_settings(): void
    {
        wp_safe_redirect(wps_admin_route_url('wpopt', 'setting-settings'));
        exit;
    }

    public function register_assets($hook_suffix = ''): void
    {
        $style_asset = UtilEnv::resolve_asset(WPOPT_ABSPATH, 'assets/style.css', wps_core()->online);
        $script_asset = UtilEnv::resolve_asset(WPOPT_ABSPATH, 'assets/admin.js', wps_core()->online);

        $style_version = $style_asset['version'] ?: (file_exists(WPOPT_ABSPATH . 'assets/style.css') ? filemtime(WPOPT_ABSPATH . 'assets/style.css') : WPOPT_VERSION);

        wp_register_style("wpopt_css", $style_asset['url'], ['vendor-wps-css'], $style_version);
        wp_register_script("wpopt_admin_js", $script_asset['url'], ['vendor-wps-js'], $script_asset['version'] ?: WPOPT_VERSION, true);

        if ($this->is_wp_optimizer_admin_screen($hook_suffix)) {
            $this->enqueue_scripts();
        }

        wps_localize([
            'text_na'            => __('N/A', 'wpopt'),
            'saved'              => __('Settings Saved', 'wpopt'),
            'error'              => __('Request fail', 'wpopt'),
            'success'            => __('Request succeed', 'wpopt'),
            'running'            => __('Running', 'wpopt'),
            'autosaving'         => __('Autosaving...', 'wpopt'),
            'autosaved'          => __('All changes saved', 'wpopt'),
            'autosave_failed'    => __('Autosave failed', 'wpopt'),
            'wpopt_ajax_nonce'   => wp_create_nonce('wpopt-ajax-nonce'),
            'text_close_warning' => __('WP Optimizer is running an action. If you leave now, it may not be completed.', 'wpopt'),
        ]);
    }

    private function is_wp_optimizer_admin_screen($hook_suffix): bool
    {
        $hook_suffix = (string)$hook_suffix;

        if (strpos($hook_suffix, 'wp-optimizer') !== false || strpos($hook_suffix, 'wpopt-') !== false) {
            return true;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        return $page === 'wp-optimizer' || strpos($page, 'wpopt-') === 0;
    }

    public function admin_body_classes(string $classes): string
    {
        if (!$this->is_wp_optimizer_admin_screen('')) {
            return $classes;
        }

        return trim($classes . ' wps-admin-screen wpopt-admin-screen');
    }

    public function render_faqs(): void
    {
        $this->enqueue_scripts();
        ?>
        <section class="wps-wrap wps-plugin-faq-page wpopt-faq-shell">
            <block class="wps">
                <section class="wps wpopt-faq-content">
                    <div class="wps-faq-list">
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wpopt-faq-toggle"><?php echo $this->faq_icon('tools'); ?><span class="wpopt-faq-question-text"><?php echo __('What can this plugin do and how does it work?', 'wpopt') ?></span>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p>
                                    <b><?php echo __('This plugin is privacy-oriented: optimization runs on your server and does not require sending your site data to third-party services.', 'wpopt'); ?></b>
                                </p>
                                <span><?php echo __('WP Optimizer is modular, so you can enable only the features you need to keep the plugin overhead low.', 'wpopt'); ?></span>
                                <ul class="wps-list">
                                    <li><?php _e('Server enhancements such as compression, browser caching, and server rules.', 'wpopt'); ?></li>
                                    <li><?php _e('Caching layers for queries, database results, objects, and static pages.', 'wpopt'); ?></li>
                                    <li><?php _e('Minification for HTML, CSS, and JavaScript.', 'wpopt'); ?></li>
                                    <li><?php _e('Local image optimization and conversion workflows.', 'wpopt'); ?></li>
                                    <li><?php _e('Database cleanup, optimization, and backups.', 'wpopt'); ?></li>
                                    <li><?php _e('Security hardening and WordPress behavior toggles.', 'wpopt'); ?></li>
                                    <li><?php _e('Update controls, diagnostics, logs, and admin utilities.', 'wpopt'); ?></li>
                                </ul>
                                <p><?php echo __('Because modules can affect different parts of WordPress, the safest approach is to enable one feature at a time and test the front end after each change.', 'wpopt'); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wpopt-faq-toggle"><?php echo $this->faq_icon('external'); ?><span class="wpopt-faq-question-text"><?php echo __('What should I enable first on a live site?', 'wpopt') ?></span>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php echo __('Start with lower-risk optimizations, then move to the more aggressive ones only after testing.', 'wpopt'); ?></p>
                                <ul class="wps-list">
                                    <li><?php echo sprintf(__('Begin with <a href="%s">browser cache and compression settings</a> because they usually improve delivery without changing page logic.', 'wpopt'), wps_module_setting_url('wpopt', 'wp_optimizer')); ?></li>
                                    <li><?php echo sprintf(__('Configure <a href="%s">media optimization</a> for new uploads before launching large bulk jobs.', 'wpopt'), wps_module_setting_url('wpopt', 'media')); ?></li>
                                    <li><?php echo sprintf(__('Create a <a href="%s">database backup</a> before cleanup tasks or bigger tuning sessions.', 'wpopt'), wps_module_panel_url('database', 'db-backup')); ?></li>
                                    <li><?php echo sprintf(__('Add <a href="%s">cache</a> and <a href="%s">minify</a> last, enabling one option at a time and verifying the front end after each step.', 'wpopt'), wps_module_setting_url('wpopt', 'cache'), wps_module_setting_url('wpopt', 'minify')); ?></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wpopt-faq-toggle"><?php echo $this->faq_icon('sliders'); ?><span class="wpopt-faq-question-text"><?php echo __('Where can I configure optimization parameters?', 'wpopt') ?></span>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php echo sprintf(__('Most optimization options are configurable in the <a href="%s">Modules Options panel</a>.', 'wpopt'), wps_module_setting_url('wpopt')); ?></p>
                                <p><?php echo sprintf(__('Global tasks such as cron, module activation, telemetry, reset, export, and restore are available from the <a href="%s">Settings page</a>.', 'wpopt'), wps_admin_route_url('wpopt', 'setting-settings')); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wpopt-faq-toggle"><?php echo $this->faq_icon('box'); ?><span class="wpopt-faq-question-text"><?php echo __('Can cache or minify break my layout?', 'wpopt') ?></span>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php echo __('Yes. Aggressive caching and front-end minification can expose conflicts in themes or plugins that rely on specific asset order, inline scripts, or dynamic output.', 'wpopt'); ?></p>
                                <ul class="wps-list">
                                    <li><?php echo sprintf(__('Enable <a href="%s">minify</a> gradually: HTML, CSS, and JavaScript do not need to be turned on all at once.', 'wpopt'), wps_module_setting_url('wpopt', 'minify')); ?></li>
                                    <li><?php echo sprintf(__('After each change, clear or reset <a href="%s">cache</a> and test important pages while logged out as well.', 'wpopt'), wps_module_setting_url('wpopt', 'cache')); ?></li>
                                    <li><?php _e('If something breaks, disable the last option you enabled first. That is usually the fastest way to isolate the conflict.', 'wpopt'); ?></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wpopt-faq-toggle"><?php echo $this->faq_icon('image'); ?><span class="wpopt-faq-question-text"><?php echo __('How does the media optimizer work?', 'wpopt') ?></span>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php echo __('Media optimizer works in three different ways:', 'wpopt'); ?></p>
                                <ul class="wps-list">
                                    <li><?php echo sprintf(__("By a scheduled event it's able to collect and optimize any media uploaded daily. <a href='%s'>Here</a> you can configure all schedule related settings.", 'wpopt'), wps_admin_route_url('wpopt', 'setting-cron')); ?></li>
                                    <li><?php _e('By a specific path scanner, Media optimizer will run a background activity to optimize all images present in the input path.', 'wpopt'); ?></li>
                                    <li><?php _e('By a whole database scanner, Media optimizer will run a background activity to check all images saved in your WordPress library optimizing each image and every thumbnail associated.', 'wpopt'); ?></li>
                                </ul>
                                <p><?php echo sprintf(__('Any image optimization will be run following your settings set <a href="%s">Here</a>.', 'wpopt'), wps_module_setting_url('wpopt', 'media')); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wpopt-faq-toggle"><?php echo $this->faq_icon('clock'); ?><span class="wpopt-faq-question-text"><?php echo __('Why are image optimization jobs not starting?', 'wpopt') ?></span>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php echo __('Bulk image optimization runs in background, so it depends on the plugin scheduler and on image libraries available on the server.', 'wpopt'); ?></p>
                                <ul class="wps-list">
                                    <li><?php echo sprintf(__('Verify that <a href="%s">cron is active</a>; background jobs will not progress if scheduled tasks are not running.', 'wpopt'), wps_admin_route_url('wpopt', 'setting-cron')); ?></li>
                                    <li><?php _e('Check that Imagick or GD is available in PHP. Imagick gives the best coverage, but GD can still handle basic processing.', 'wpopt'); ?></li>
                                    <li><?php echo sprintf(__('Review your <a href="%s">media settings</a> before starting a full-library scan so the job uses the correct quality and format options.', 'wpopt'), wps_module_setting_url('wpopt', 'media')); ?></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wpopt-faq-toggle"><?php echo $this->faq_icon('database'); ?><span class="wpopt-faq-question-text"><?php echo __('Do I need Redis or Memcached for object cache?', 'wpopt') ?></span>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php echo __('Only the object cache feature requires Redis or Memcached. The other optimization modules can still be used without them.', 'wpopt'); ?></p>
                                <ul class="wps-list">
                                    <li><?php echo sprintf(__('If your server does not provide Redis or Memcached, leave <a href="%s">object cache</a> disabled.', 'wpopt'), wps_module_setting_url('wpopt', 'cache')); ?></li>
                                    <li><?php _e('Static page cache, database/query cache, browser cache, compression, and minify do not depend on an external object cache server.', 'wpopt'); ?></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wpopt-faq-toggle"><?php echo $this->faq_icon('database'); ?><span class="wpopt-faq-question-text"><?php echo __('How can I clean the database safely?', 'wpopt') ?></span>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php echo __('Database cleanup is most useful when you do it conservatively and with a rollback path ready.', 'wpopt'); ?></p>
                                <ul class="wps-list">
                                    <li><?php echo sprintf(__('Create a fresh <a href="%s">database backup</a> before deleting revisions, transients, orphaned data, or other leftovers.', 'wpopt'), wps_module_panel_url('database', 'db-backup')); ?></li>
                                    <li><?php echo sprintf(__('Run cleanup tasks from the <a href="%s">Database module</a> when the site is stable and no import, migration, or major update is running.', 'wpopt'), wps_module_setting_url('wpopt', 'database')); ?></li>
                                    <li><?php _e('If the result is not what you expected, restore the backup first instead of guessing which rows changed.', 'wpopt'); ?></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wpopt-faq-toggle"><?php echo $this->faq_icon('external'); ?><span class="wpopt-faq-question-text"><?php echo __('Can I export settings before major changes?', 'wpopt') ?></span>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php echo sprintf(__('Yes. The <a href="%s">Settings page</a> lets you export, import, reset, or restore plugin options.', 'wpopt'), wps_admin_route_url('wpopt', 'setting-settings')); ?></p>
                                <p><?php _e('Use an export before testing aggressive cache, minify, or security changes so you can quickly return to a known-good configuration.', 'wpopt'); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wpopt-faq-toggle"><?php echo $this->faq_icon('settings'); ?><span class="wpopt-faq-question-text"><?php echo __('Does WP Optimizer work on multisite?', 'wpopt') ?></span>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php _e('Yes. The plugin supports multisite and can be network-activated.', 'wpopt'); ?></p>
                                <p><?php echo sprintf(__('Even on multisite, test optimization changes gradually from the <a href="%s">module settings</a> because each subsite may use different themes, plugins, and traffic patterns.', 'wpopt'), wps_module_setting_url('wpopt')); ?></p>
                            </div>
                        </div>
                    </div>
                    </div>
                </section>
            </block>
        </section>
        <?php
    }

    private function faq_icon(string $icon): string
    {
        return '<span class="wpopt-faq-row-icon">' . Graphic::icon($icon) . '</span>';
    }

    /**
     * This function renders the contents of the page associated with the menu
     * that invokes the render method. In the context of this plugin, this is the
     * menu class.
     */
    public function render_main(): void
    {
        $this->enqueue_scripts();

        $dashboard_cache_dirty = false;

        if (isset($_POST['wpopt-cron-run'])) {

            if (UtilEnv::verify_nonce('wpopt-nonce')) {
                CronActions::run_event('wpopt-cron');
                $dashboard_cache_dirty = true;
            }
        }
        elseif (isset($_POST['wpopt-cron-reset'])) {

            if (UtilEnv::verify_nonce('wpopt-nonce')) {
                wps('wpopt')->cron->reset_status();
                $dashboard_cache_dirty = true;
            }
        }

        if ($dashboard_cache_dirty) {
            $this->clear_dashboard_cache();
        }

        settings_errors();
        $this->render_admin_app();
    }

    private function get_dashboard_view_model(): array
    {
        $cache_key = $this->dashboard_cache_key();
        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        $module_handler = wps('wpopt')->moduleHandler;
        $excluded_modules = array('cron', 'cloudflare', 'modules_handler', 'settings', 'tracking');
        $all_modules = $module_handler->get_modules(array('excepts' => $excluded_modules), false);
        $module_icons = $this->dashboard_module_icons();
        $active_modules_count = 0;
        $module_cards = array();

        foreach ($all_modules as $index => $module) {
            $module_slug = $module['slug'];
            $module_active = $module_handler->module_is_active($module_slug);

            if ($module_active) {
                $active_modules_count++;
            }

            if ($index >= 5) {
                continue;
            }

            $module_cards[] = array(
                'name'   => $module['name'],
                'slug'   => $module_slug,
                'url'    => $module_handler->module_has_scope($module, 'admin-page') ? wps_module_panel_url($module_slug) : wps_module_setting_url('wpopt', $module_slug),
                'active' => $module_active,
                'icon'   => $module_icons[$module_slug] ?? 'dashicons-admin-tools',
            );
        }

        $is_cron_running = wps('wpopt')->settings->get('cron.running', false);
        $tracking_enabled = wps('wpopt')->settings->get('tracking.errors', true) || wps('wpopt')->settings->get('tracking.usage', true);
        $persistent_cache = defined('WP_PERSISTENT_CACHE');
        $server_load = UtilEnv::get_server_load();
        $cron_overview = $this->get_dashboard_cron_overview();
        $health_score = $this->calculate_dashboard_health_score(
            $all_modules,
            $module_handler,
            (bool)$tracking_enabled,
            $persistent_cache,
            (string)$server_load
        );

        $view_model = array(
            'active_modules_count' => $active_modules_count,
            'is_cron_running'      => $is_cron_running,
            'tracking_enabled'     => $tracking_enabled,
            'persistent_cache'     => $persistent_cache,
            'server_load'          => $server_load,
            'health_score'         => $health_score,
            'cron_overview'        => $cron_overview,
            'module_cards'         => $module_cards,
            'last_updated'         => date_i18n(get_option('time_format')),
        );

        set_transient($cache_key, $view_model, self::DASHBOARD_CACHE_TTL);

        return $view_model;
    }

    private function get_static_cache_storage_bytes(): int
    {
        $class = '\\WPOptimizer\\modules\\supporters\\StaticCache';

        if (!class_exists($class) && defined('WPOPT_SUPPORTERS')) {
            $loader = WPOPT_SUPPORTERS . 'cache/staticcache_runtime.class.php';

            if (is_file($loader)) {
                require_once $loader;
            }
        }

        if (!class_exists($class) || !method_exists($class, 'get_storage_size')) {
            return 0;
        }

        try {
            return max(0, (int)$class::get_storage_size());
        } catch (\Throwable $exception) {
            return 0;
        }
    }

    private function count_static_cache_entries(): int
    {
        global $wpdb;

        if (!defined('WPOPT_TABLE_CACHE_ENTRIES')) {
            return 0;
        }

        $table = (string)WPOPT_TABLE_CACHE_ENTRIES;

        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return 0;
        }

        $existing_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        if ($existing_table !== $table) {
            return 0;
        }

        return absint($wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE namespace = %s AND dependency_type = %s',
            'static',
            '__entry'
        )));
    }

    private function get_dashboard_cron_overview(): array
    {
        $cron_settings = (array)wps('wpopt')->settings->get('cron', array());
        $recurrence = sanitize_key((string)($cron_settings['recurrence'] ?? 'daily'));
        $schedules = wp_get_schedules();
        $next_run = wp_next_scheduled('wpopt-cron');

        return array(
            'active'          => !empty($cron_settings['active']),
            'running'         => !empty($cron_settings['running']),
            'execution_time'  => (string)($cron_settings['execution-time'] ?? '01:00'),
            'recurrence'      => $recurrence,
            'recurrence_name' => isset($schedules[$recurrence]['display']) ? (string)$schedules[$recurrence]['display'] : $recurrence,
            'next_run'        => $next_run ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int)$next_run) : '',
            'tasks'           => array_filter(array(
                !empty($cron_settings['database']['active']) ? __('Database optimization', 'wpopt') : '',
                !empty($cron_settings['media']['active']) ? __('Image optimization', 'wpopt') : '',
            )),
        );
    }

    private function calculate_dashboard_health_score(array $modules, $module_handler, bool $tracking_enabled, bool $persistent_cache, string $server_load): int
    {
        $recommended_modules_count = 0;
        $active_recommended_modules_count = 0;

        foreach ($modules as $module) {
            $module_slug = (string)($module['slug'] ?? '');

            if ('' === $module_slug || in_array($module_slug, self::DASHBOARD_HEALTH_OPTIONAL_MODULES, true)) {
                continue;
            }

            $recommended_modules_count++;

            if ($module_handler->module_is_active($module_slug)) {
                $active_recommended_modules_count++;
            }
        }

        $recommended_modules_score = $recommended_modules_count > 0
            ? (int)round(($active_recommended_modules_count / $recommended_modules_count) * 40)
            : 0;

        $scheduler_score = $module_handler->module_is_active('cron') ? 20 : 0;
        $tracking_score = $tracking_enabled ? 10 : 0;
        $persistent_cache_score = $persistent_cache ? 15 : 0;
        $server_load_score = $this->calculate_dashboard_server_load_score($server_load);

        return max(0, min(100, $recommended_modules_score + $scheduler_score + $tracking_score + $persistent_cache_score + $server_load_score));
    }

    private function calculate_dashboard_server_load_score(string $server_load): int
    {
        if ('' === $server_load || !is_numeric($server_load)) {
            return 8;
        }

        $load = max(0, (float)$server_load);

        if ($load <= 50) {
            return 15;
        }

        if ($load <= 75) {
            return 10;
        }

        if ($load <= 90) {
            return 5;
        }

        return 0;
    }

    private function dashboard_cache_key(): string
    {
        return 'wpopt_dashboard_' . get_current_blog_id() . '_' . WPOPT_VERSION;
    }

    private function clear_dashboard_cache(): void
    {
        delete_transient($this->dashboard_cache_key());
    }

    private function dashboard_module_icons(): array
    {
        return array(
            'database'      => 'dashicons-database',
            'media'         => 'dashicons-format-gallery',
            'wp_customizer' => 'dashicons-admin-customizer',
            'wp_optimizer'  => 'dashicons-performance',
            'wp_security'   => 'dashicons-shield',
            'cache'         => 'dashicons-admin-network',
            'minify'        => 'dashicons-editor-contract',
            'activitylog'   => 'dashicons-list-view',
            'wp_mail'       => 'dashicons-email-alt',
            'wp_updates'    => 'dashicons-update',
            'wp_info'       => 'dashicons-info',
            'cloudflare'    => 'dashicons-admin-site-alt3',
            'cron'          => 'dashicons-clock',
            'pagespeed'     => 'dashicons-dashboard',
            'performance_monitor' => 'dashicons-chart-line',
            'widget'        => 'dashicons-screenoptions',
        );
    }

    private function render_dashboard_hero_art(): void
    {
        ?>
        <svg class="wpopt-hero-svg" xmlns="http://www.w3.org/2000/svg" width="362" height="117" viewBox="0 0 362 117" fill="none" role="img" aria-label="<?php esc_attr_e('Dashboard illustration with speedometer', 'wpopt'); ?>">
            <defs>
                <linearGradient id="outerBlue" x1="196" y1="40" x2="309" y2="96" gradientUnits="userSpaceOnUse">
                    <stop offset="0" stop-color="#79B4FF"/>
                    <stop offset="0.42" stop-color="#3E8DFF"/>
                    <stop offset="1" stop-color="#075FDD"/>
                </linearGradient>
                <linearGradient id="outerHighlight" x1="207" y1="43" x2="274" y2="39" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#A5CEFF" stop-opacity="0.75"/>
                    <stop offset="1" stop-color="#FFFFFF" stop-opacity="0"/>
                </linearGradient>
                <linearGradient id="innerBlue" x1="218" y1="89" x2="284" y2="57" gradientUnits="userSpaceOnUse">
                    <stop offset="0" stop-color="#83BAFF"/>
                    <stop offset="0.62" stop-color="#4C96FF"/>
                    <stop offset="1" stop-color="#287AF1"/>
                </linearGradient>
                <linearGradient id="needleBlue" x1="250" y1="86" x2="272" y2="76" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#2F85FF"/>
                    <stop offset="1" stop-color="#176FE8"/>
                </linearGradient>
                <linearGradient id="pieBlue" x1="142" y1="71" x2="156" y2="85" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#4D93FF"/>
                    <stop offset="1" stop-color="#0C65E5"/>
                </linearGradient>
                <radialGradient id="centerGlow" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(200 58) rotate(90) scale(76 137)">
                    <stop stop-color="#EAF3FF"/>
                    <stop offset="1" stop-color="#EAF3FF" stop-opacity="0"/>
                </radialGradient>
            </defs>

            <ellipse cx="91" cy="42" rx="38" ry="27" fill="#EAF3FF" opacity="0.88"/>
            <ellipse cx="108" cy="104" rx="76" ry="61" fill="#EAF3FF" opacity="0.76"/>
            <ellipse cx="197" cy="58" rx="72" ry="53" fill="#EAF3FF" opacity="0.80"/>
            <ellipse cx="265" cy="61" rx="42" ry="36" fill="#EAF3FF" opacity="0.72"/>
            <ellipse cx="264" cy="112" rx="67" ry="57" fill="#EAF3FF" opacity="0.68"/>
            <circle cx="35" cy="73" r="3.1" fill="#B8D2FF"/>
            <circle cx="96" cy="25" r="2" fill="#78AFFF"/>
            <circle cx="287" cy="7" r="1.4" fill="#83B7FF"/>
            <circle cx="295" cy="42" r="1.4" fill="#C5DAFF" opacity="0.9"/>
            <circle cx="324" cy="68" r="3.1" fill="#AFCBFF"/>

            <circle cx="194" cy="35" r="11" fill="#FFFFFF"/>
            <path d="M194 28.5L196.25 32.8L200.5 35L196.25 37.2L194 41.5L191.75 37.2L187.5 35L191.75 32.8L194 28.5Z" fill="#3A8BFF"/>
            <path d="M224 12.5L225.25 16.2L228.5 17.5L225.25 18.85L224 22.5L222.75 18.85L219.5 17.5L222.75 16.2L224 12.5Z" fill="#8EBBFF" opacity="0.82"/>
            <path d="M315 18.5L316.55 22.1L320 23.5L316.55 24.9L315 28.5L313.45 24.9L310 23.5L313.45 22.1L315 18.5Z" fill="#9DC4FF" opacity="0.9"/>

            <rect x="68" y="46" width="98" height="67" rx="10" fill="#D8E7FF" opacity="0.22"/>
            <rect x="66" y="41" width="100" height="70" rx="10" fill="#FFFFFF"/>
            <rect x="83" y="50" width="58" height="6" rx="3" fill="#E7F0FF"/>
            <rect x="83" y="66" width="53" height="4" rx="2" fill="#E7F0FF"/>
            <rect x="83" y="77" width="37" height="4" rx="2" fill="#E7F0FF"/>
            <rect x="83" y="88" width="48" height="4" rx="2" fill="#E7F0FF"/>
            <circle cx="142" cy="85" r="15" fill="#E6F0FF"/>
            <path d="M142 85V71C149.73 71 156 77.27 156 85H142Z" fill="url(#pieBlue)"/>

            <g id="tachimetro">
                <path d="M193.5 95.5A57.5 57.5 0 0 1 308.5 95.5" stroke="#D9E9FF" stroke-width="16" stroke-linecap="round" opacity="0.82"/>
                <path d="M193.5 95.5A57.5 57.5 0 0 1 308.5 95.5" stroke="#BFD9FF" stroke-width="10" stroke-linecap="round" opacity="0.22"/>
                <path d="M195 95.5A56 56 0 0 1 307 95.5" stroke="url(#outerBlue)" stroke-width="7.8" stroke-linecap="round"/>
                <path d="M202.4 72.2A56 56 0 0 1 278.7 44.6" stroke="url(#outerHighlight)" stroke-width="3.1" stroke-linecap="round" opacity="0.45"/>

                <path d="M216.5 90.5A34.5 34.5 0 0 1 285.5 90.5" stroke="#D9E9FF" stroke-width="11.4" stroke-linecap="round" opacity="0.84"/>
                <path d="M216.5 90.5A34.5 34.5 0 0 1 285.5 90.5" stroke="url(#innerBlue)" stroke-width="6.7" stroke-linecap="round"/>
            </g>

            <path d="M191 117C193.4 107 203.8 100.7 217.8 101.5C222.2 95.2 232.4 91 244.4 91C261.3 91 274.3 100.5 277.8 112.7C288.6 111.1 300.3 112.8 307.5 117H191Z" fill="#FFFFFF"/>

            <path d="M251 85.4L270.8 76.2" stroke="#D6E8FF" stroke-width="7.4" stroke-linecap="round" opacity="0.45"/>
            <path d="M251 85.4L270.8 76.2" stroke="url(#needleBlue)" stroke-width="5" stroke-linecap="round"/>
            <circle cx="251" cy="85.4" r="7.1" fill="#E8F2FF" opacity="0.55"/>
            <circle cx="251" cy="85.4" r="5.4" fill="#2E84FF"/>
        </svg>
        <?php
    }

    private function render_sidebar(): void
    {
        $donation_url = $this->get_donation_url();
        $review_url = $this->get_review_url();
        ?>
        <aside class="wps wpopt-sidebar">
            <section class="wps-box wpopt-support-card-primary">
                <div class="wps-donation-wrap">
                    <span class="wpopt-support-icon"><?php echo Graphic::icon('star', 'wpopt-support-icon-svg'); ?></span>
                    <div class="wps-donation-title"><?php _e('WP Optimizer saves you time', 'wpopt'); ?></div>
                    <p class="wpopt-muted"><?php _e('Support maintenance, fixes, and new features with a donation.', 'wpopt'); ?></p>
                    <div class="wpopt-inline-actions wpopt-support-cta">
                        <a class="wps wps-button wpopt-btn is-success" href="<?php echo esc_url($donation_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo Graphic::icon('heart-fill', 'wpopt-btn-icon'); ?><?php _e('Buy me a coffe', 'wpopt'); ?></a>
                        <a class="wps wps-button wpopt-btn is-neutral" href="<?php echo esc_url($review_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo Graphic::icon('star-outline', 'wpopt-btn-icon'); ?><?php _e('Leave a review', 'wpopt'); ?></a>
                    </div>
                </div>
            </section>
            <section class="wps-box">
                <h3><?php echo Graphic::icon('headphones', 'wpopt-sidebar-title-icon'); ?><?php _e('Need help?', 'wpopt'); ?></h3>
                <ul class="wps wpopt-link-list">
                    <li>
                        <a href="https://translate.wordpress.org/projects/wp-plugins/wp-optimizer/"><?php echo Graphic::icon('translate', 'wpopt-link-icon'); ?><?php _e('Help me translating', 'wpopt'); ?></a>
                    </li>
                </ul>
            </section>
            <section class="wps-box">
                <h3><?php echo Graphic::icon('box', 'wpopt-sidebar-title-icon'); ?>WP Optimizer</h3>
                <ul class="wps wpopt-link-list">
                    <li>
                        <a href="<?php echo esc_url(wps_admin_route_url('wpopt', 'welcome', array('do_welcome' => 'true'))); ?>"><?php echo Graphic::icon('pagespeed', 'wpopt-link-icon'); ?><?php _e('Launch wizard', 'wpopt'); ?></a>
                    </li>
                    <li>
                        <a href="https://github.com/sh1zen/wp-optimizer/"><?php echo Graphic::icon('code', 'wpopt-link-icon'); ?><?php _e('Source code', 'wpopt'); ?></a>
                    </li>
                    <li>
                        <a href="https://sh1zen.github.io/"><?php echo Graphic::icon('user', 'wpopt-link-icon'); ?><?php _e('About me', 'wpopt'); ?></a>
                    </li>
                </ul>
            </section>
        </aside>
        <?php
    }

    private function render_admin_app(): void
    {
        $route = $this->get_app_route();
        $route_label = $this->get_app_route_label($route);

        Graphic::render_admin_app([
            'title'      => 'WP Optimizer',
            'page_title' => $route === 'dashboard' ? __('WP Optimizer Dashboard', 'wpopt') : $route_label,
            'version'    => 'v' . WPOPT_VERSION,
            'context'    => 'wpopt',
            'active'     => $route,
            'breadcrumb' => $route_label,
            'status'     => __('Healthy', 'wpopt'),
            'brand_icon' => 'gauge',
            'brand_logo' => $this->get_logo_url(),
            'nav'        => $this->get_app_nav(),
            'help'       => '<strong>' . esc_html__('Need help?', 'wpopt') . '</strong><p><a href="https://wordpress.org/support/plugin/wp-optimizer/" target="_blank" rel="noopener noreferrer">' . esc_html__('Open support', 'wpopt') . '</a></p>',
            'content'    => function () use ($route) {
                $this->render_app_content($route);
            },
        ]);
    }

    private function get_app_route(): string
    {
        $route = isset($_GET['wps-page']) ? sanitize_key(wp_unslash($_GET['wps-page'])) : '';

        if ($route !== '') {
            return $route;
        }

        $direct_welcome = isset($_GET['do_welcome']) ? sanitize_text_field(wp_unslash($_GET['do_welcome'])) : '';

        if (in_array(strtolower((string)$direct_welcome), array('1', 'true', 'yes'), true)) {
            return 'welcome';
        }

        return 'dashboard';
    }

    private function get_app_nav(): array
    {
        $tools = array();

        foreach (wps('wpopt')->moduleHandler->get_modules(array('scopes' => 'admin-page'), true) as $module) {
            $tools[] = array(
                'id'    => 'module-' . $module['slug'],
                'label' => $module['name'],
                'icon'  => $this->get_app_module_icon($module['slug']),
                'url'   => wps_module_panel_url($module['slug']),
            );
        }

        $settings_items = $this->get_settings_nav_items('wpopt');
        $nav = array(
            array(
                'label' => __('Overview', 'wpopt'),
                'items' => array_merge(
                    array(array('id' => 'dashboard', 'label' => __('Dashboard', 'wpopt'), 'icon' => 'gauge', 'url' => wps_admin_route_url('wpopt'))),
                    $this->get_core_settings_nav_items('wpopt'),
                    array(array('id' => 'page-test', 'label' => __('Page test', 'wpopt'), 'icon' => 'chart', 'url' => wps_admin_route_url('wpopt', 'page-test'))),
                    array(array('id' => 'faq', 'label' => __('FAQ', 'wpopt'), 'icon' => 'info', 'url' => wps_admin_route_url('wpopt', 'faq')))
                ),
            ),
        );

        if (!empty($settings_items)) {
            $nav[] = array(
                'label' => __('Settings', 'wpopt'),
                'items' => $settings_items,
            );
        }

        $nav[] = array(
            'label' => __('Tools', 'wpopt'),
            'items' => $tools,
        );

        return $nav;
    }

    private function get_app_route_label(string $route): string
    {
        $labels = array(
            'dashboard' => __('Dashboard', 'wpopt'),
            'welcome'   => __('Welcome', 'wpopt'),
            'page-test' => __('Page test', 'wpopt'),
            'faq'       => __('FAQ', 'wpopt'),
        );

        if (isset($labels[$route])) {
            return $labels[$route];
        }

        if (str_starts_with($route, 'module-setting-')) {
            $setting_id = substr($route, 15);

            foreach (wps('wpopt')->settings->get_module_setting_pages() as $page) {
                if ($page['id'] === $setting_id) {
                    return $page['label'];
                }
            }
        }

        if (str_starts_with($route, 'module-')) {
            $slug = substr($route, 7);

            foreach (wps('wpopt')->moduleHandler->get_modules(array('scopes' => 'admin-page'), true) as $module) {
                if ($module['slug'] === $slug) {
                    return $module['name'];
                }
            }
        }

        if (str_starts_with($route, 'conf-')) {
            $parts = explode('-', $route, 3);
            $module_slug = sanitize_key((string)($parts[1] ?? ''));
            $target = sanitize_key((string)($parts[2] ?? ''));
            $object = $module_slug ? wps('wpopt')->moduleHandler->get_module_instance($module_slug) : null;

            if ($object && method_exists($object, 'get_configuration_page_label')) {
                return (string)$object->get_configuration_page_label($target);
            }

            return __('Configuration', 'wpopt');
        }

        if (str_starts_with($route, 'setting-')) {
            $setting_id = substr($route, 8);

            foreach (wps('wpopt')->settings->get_core_setting_pages() as $page) {
                if ($page['id'] === $setting_id) {
                    return $page['label'];
                }
            }
        }

        return __('Dashboard', 'wpopt');
    }

    private function render_app_content(string $route): void
    {
        if ($route === 'core-settings') {
            $this->render_first_core_setting_page('wpopt');
            return;
        }

        if (str_starts_with($route, 'setting-')) {
            wps('wpopt')->settings->render_core_setting_page(substr($route, 8), false);
            return;
        }

        if (str_starts_with($route, 'module-setting-')) {
            wps('wpopt')->settings->render_module_setting_page(substr($route, 15), false);
            return;
        }

        if (str_starts_with($route, 'conf-')) {
            $parts = explode('-', $route, 3);
            $module_slug = sanitize_key((string)($parts[1] ?? ''));
            $target = sanitize_key((string)($parts[2] ?? ''));
            $object = $module_slug ? wps('wpopt')->moduleHandler->get_module_instance($module_slug) : null;

            if ($object && method_exists($object, 'render_configuration_page')) {
                $this->render_app_panel(function () use ($object, $target) {
                    $object->render_configuration_page($target);
                });
                return;
            }
        }

        if ($route === 'faq') {
            $this->render_app_panel(array($this, 'render_faqs'));
            return;
        }

        if ($route === 'page-test') {
            $this->render_page_test();
            return;
        }

        if ($route === 'welcome') {
            $this->render_welcome_page();
            return;
        }

        if (str_starts_with($route, 'module-')) {
            $object = wps('wpopt')->moduleHandler->get_module_instance(substr($route, 7));

            if ($object) {
                $this->render_app_panel(function () use ($object) {
                    $object->render_admin_page(false);
                });
                return;
            }
        }

        $this->render_app_dashboard();
    }

    private function render_welcome_page(): void
    {
        $modules = $this->get_welcome_modules();
        $value_cards = array(
            array(
                'title'       => __('Fewer plugins', 'wpopt'),
                'description' => __('Group cache, media, database, security and diagnostics in one workspace.', 'wpopt'),
            ),
            array(
                'title'       => __('Focused modules', 'wpopt'),
                'description' => __('Each module has a precise purpose and can be configured independently.', 'wpopt'),
            ),
            array(
                'title'       => __('Lower overhead', 'wpopt'),
                'description' => __('Disable what this site does not need and keep the optimization flow lean.', 'wpopt'),
            ),
            array(
                'title'       => __('On/off control', 'wpopt'),
                'description' => __('Activate only the modules that match the needs of this site.', 'wpopt'),
            ),
            array(
                'title'       => __('One workflow', 'wpopt'),
                'description' => __('Manage optimization tools from one coordinated admin area.', 'wpopt'),
            ),
        );
        ?>
        <div class="wpopt-advertise-overlay" role="dialog" aria-modal="true" aria-labelledby="wpopt-advertise-welcome-title">
            <div class="wpopt-advertise-page is-welcome">
                <a class="wpopt-advertise-close" href="<?php echo esc_url(wps_admin_route_url('wpopt')); ?>" aria-label="<?php esc_attr_e('Close', 'wpopt'); ?>">&times;</a>
                <section class="wpopt-advertise-hero">
                    <span class="wpopt-advertise-eyebrow"><?php esc_html_e('Welcome to WP Optimizer', 'wpopt'); ?></span>
                    <h1 id="wpopt-advertise-welcome-title"><?php esc_html_e('One modular workspace for a faster, cleaner WordPress site.', 'wpopt'); ?></h1>
                </section>

                <section class="wpopt-advertise-value-grid" aria-label="<?php esc_attr_e('WP Optimizer strengths', 'wpopt'); ?>">
                    <?php foreach ($value_cards as $value_card) : ?>
                        <article class="wpopt-advertise-value-card">
                            <strong><?php echo esc_html($value_card['title']); ?></strong>
                            <small><?php echo esc_html($value_card['description']); ?></small>
                        </article>
                    <?php endforeach; ?>
                </section>

                <section>
                    <div class="wpopt-panel-head">
                        <h2><span class="dashicons dashicons-screenoptions" aria-hidden="true"></span><?php esc_html_e('What each module does', 'wpopt'); ?></h2>
                        <span><?php esc_html_e('Quick overview', 'wpopt'); ?></span>
                    </div>
                    <div class="wpopt-advertise-grid">
                        <?php foreach ($modules as $module) : ?>
                            <a class="wpopt-advertise-card is-module" href="<?php echo esc_url($module['url']); ?>">
                                <span><?php echo esc_html($module['name']); ?></span>
                                <strong><?php echo esc_html($module['short']); ?></strong>
                                <small><?php echo esc_html($module['description']); ?></small>
                                <em class="<?php echo $module['active'] ? 'is-active' : 'is-inactive'; ?>">
                                    <?php echo $module['active'] ? esc_html__('Active', 'wpopt') : esc_html__('Inactive', 'wpopt'); ?>
                                </em>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="wpopt-advertise-support">
                    <div class="wpopt-advertise-support-copy">
                        <h2><?php esc_html_e('Start with the modules manager.', 'wpopt'); ?></h2>
                        <p><?php esc_html_e('Review the active modules, keep only what is useful, then configure each module from its dedicated settings page.', 'wpopt'); ?></p>
                    </div>
                    <div class="wpopt-advertise-actions">
                        <a class="wpopt-advertise-button is-primary" href="<?php echo esc_url(wps_admin_route_url('wpopt', 'setting-modules_handler')); ?>"><?php esc_html_e('Manage modules', 'wpopt'); ?></a>
                        <a class="wpopt-advertise-button is-muted" href="<?php echo esc_url(wps_admin_route_url('wpopt')); ?>"><?php esc_html_e('Open dashboard', 'wpopt'); ?></a>
                    </div>
                </section>
            </div>
        </div>
        <?php
    }

    private function get_welcome_modules(): array
    {
        $module_handler = wps('wpopt')->moduleHandler;
        $excluded_modules = array('modules_handler', 'settings', 'tracking');
        $modules = $module_handler->get_modules(array('excepts' => $excluded_modules), false);
        $descriptions = $this->get_welcome_module_descriptions();
        $short_labels = $this->get_welcome_module_short_labels();
        $welcome_modules = array();

        foreach ($modules as $module) {
            $slug = sanitize_key((string)($module['slug'] ?? ''));

            if ($slug === '') {
                continue;
            }

            $active = $module_handler->module_is_active($slug);

            if (!$active) {
                $url = wps_admin_route_url('wpopt', 'setting-modules_handler');
            }
            elseif ($module_handler->module_has_scope($module, 'admin-page')) {
                $url = wps_module_panel_url($slug);
            }
            elseif ($module_handler->module_has_scope($module, 'core-settings')) {
                $url = wps_admin_route_url('wpopt', 'setting-' . $slug);
            }
            elseif ($module_handler->module_has_scope($module, 'settings')) {
                $url = wps_module_setting_url('wpopt', $slug);
            }
            else {
                $url = wps_admin_route_url('wpopt', 'setting-modules_handler');
            }

            $welcome_modules[] = array(
                'slug'        => $slug,
                'name'        => (string)($module['name'] ?? $slug),
                'short'       => $short_labels[$slug] ?? __('Focused tool', 'wpopt'),
                'description' => $descriptions[$slug] ?? sprintf(__('Gestisce le funzioni del modulo %s mantenendo separata la relativa configurazione.', 'wpopt'), (string)($module['name'] ?? $slug)),
                'active'      => $active,
                'url'         => $url,
            );
        }

        return $welcome_modules;
    }

    private function get_welcome_module_short_labels(): array
    {
        return array(
            'activitylog'         => __('Activity visibility', 'wpopt'),
            'cache'               => __('Cache layers', 'wpopt'),
            'cloudflare'          => __('Cloudflare tools', 'wpopt'),
            'cron'                => __('Scheduled tasks', 'wpopt'),
            'database'            => __('Database care', 'wpopt'),
            'media'               => __('Media weight', 'wpopt'),
            'minify'              => __('Asset cleanup', 'wpopt'),
            'pagespeed'           => __('PageSpeed checks', 'wpopt'),
            'performance_monitor' => __('Request metrics', 'wpopt'),
            'widget'              => __('Dashboard data', 'wpopt'),
            'wp_customizer'       => __('WordPress toggles', 'wpopt'),
            'wp_info'             => __('System details', 'wpopt'),
            'wp_mail'             => __('Mail logging', 'wpopt'),
            'wp_optimizer'        => __('Server tuning', 'wpopt'),
            'wp_security'         => __('Hardening', 'wpopt'),
            'wp_updates'          => __('Update control', 'wpopt'),
        );
    }

    private function get_welcome_module_descriptions(): array
    {
        return array(
            'activitylog'         => __('Registra le attivita rilevanti di utenti, contenuti e tassonomie per aiutare controllo e diagnosi.', 'wpopt'),
            'cache'               => __('Gestisce cache statica, oggetti, WP_Query e query database con regole, scadenze e pulizia dedicate.', 'wpopt'),
            'cloudflare'          => __('Gestisce configurazioni e azioni Cloudflare collegate a questa installazione WordPress.', 'wpopt'),
            'cron'                => __('Programma le ottimizzazioni automatiche e centralizza le attivita ricorrenti del plugin.', 'wpopt'),
            'database'            => __('Pulisce, ottimizza e salva backup del database, inclusa la revisione delle opzioni autoload.', 'wpopt'),
            'media'               => __('Ottimizza immagini, conversioni e pulizia media per ridurre peso e banda usata dagli upload.', 'wpopt'),
            'minify'              => __('Riduce HTML, CSS e JavaScript per alleggerire le pagine e limitare asset non necessari.', 'wpopt'),
            'pagespeed'           => __('Aiuta a configurare e verificare ottimizzazioni PageSpeed quando disponibili sul server.', 'wpopt'),
            'performance_monitor' => __('Monitora richieste lente, tempi di risposta e metriche utili per capire dove intervenire.', 'wpopt'),
            'widget'              => __('Aggiunge widget diagnostici alla dashboard per informazioni rapide su server e installazione.', 'wpopt'),
            'wp_customizer'       => __('Permette di disattivare o regolare funzioni WordPress e admin non necessarie al progetto.', 'wpopt'),
            'wp_info'             => __('Mostra informazioni tecniche su WordPress, server e ambiente per supportare il debug.', 'wpopt'),
            'wp_mail'             => __('Registra le email inviate da WordPress per controllare contenuti, stato e tracciabilita.', 'wpopt'),
            'wp_optimizer'        => __('Applica ottimizzazioni server e WordPress come compressione, browser cache e regole locali.', 'wpopt'),
            'wp_security'         => __('Indurisce impostazioni WordPress e server per ridurre superfici di rischio comuni.', 'wpopt'),
            'wp_updates'          => __('Gestisce il comportamento degli aggiornamenti di core, plugin e temi secondo le tue regole.', 'wpopt'),
        );
    }

    private function render_page_test(): void
    {
        wp_localize_script('wpopt_admin_js', 'wpoptPageTest', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('wpopt-page-test'),
            'homeUrl' => home_url('/'),
            'scanPauseMs' => 250,
            'labels'  => array(
                'preparing'     => __('Preparing signed test URL...', 'wpopt'),
                'baseline'      => __('Measuring without WP Optimizer configuration...', 'wpopt'),
                'activeEmpty'   => __('Scanning current WP Optimizer configuration without diagnostics...', 'wpopt'),
                'warmup'        => __('Warming up current WP Optimizer configuration...', 'wpopt'),
                'active'        => __('Measuring with current WP Optimizer configuration...', 'wpopt'),
                'complete'      => __('Test complete.', 'wpopt'),
                'failed'        => __('The test could not be completed.', 'wpopt'),
                'running'       => __('Running', 'wpopt'),
                'done'          => __('Done', 'wpopt'),
                'failedStatus'  => __('Failed', 'wpopt'),
                'notAvailable'  => __('N/A', 'wpopt'),
                'sameSiteError' => __('Enter a valid URL from this WordPress site.', 'wpopt'),
                'pass'          => __('Pass', 'wpopt'),
                'status'        => __('Status', 'wpopt'),
                'total'         => __('Total', 'wpopt'),
                'ttfb'          => __('TTFB', 'wpopt'),
                'cache'         => __('Cache', 'wpopt'),
                'memory'        => __('Memory', 'wpopt'),
                'size'          => __('Size', 'wpopt'),
                'baselineValue' => __('Baseline', 'wpopt'),
                'speedChange'   => __('Speed change', 'wpopt'),
                'ttfbChange'    => __('TTFB change', 'wpopt'),
                'memoryChange'  => __('Memory change', 'wpopt'),
                'sizeChange'    => __('Size change', 'wpopt'),
                'currentVsBase' => __('Current vs baseline', 'wpopt'),
                'diagnosticsEmpty'      => __('No actionable warmup diagnostics were captured for this run.', 'wpopt'),
                'heavyHooks'            => __('Heavy hooks and callbacks', 'wpopt'),
                'slowQueries'           => __('Slow queries', 'wpopt'),
                'repeatedQueries'       => __('Repeated queries', 'wpopt'),
                'callbacks'             => __('Callbacks', 'wpopt'),
                'callbackSamples'       => __('Callback samples', 'wpopt'),
                'time'                  => __('Time', 'wpopt'),
                'count'                 => __('Count', 'wpopt'),
                'caller'                => __('Caller', 'wpopt'),
                'query'                 => __('Query', 'wpopt'),
            ),
        ));
        ?>
        <section class="wps-wrap wpopt-shell wpopt-page-test-page">
            <div class="wpopt-page-test-main">
                <block class="wps wpopt-page-test-panel">
                    <div class="wpopt-page-test-stack">
                        <div class="wpopt-page-test-head">
                            <div>
                                <h2><?php esc_html_e('Test a page', 'wpopt'); ?></h2>
                                <p><?php esc_html_e('Compare a measured request without WP Optimizer configuration against a warmed measured request using the current configuration. The first current-configuration scan is empty, then the diagnostic warmup collects slow queries and hooks before the final measurement.', 'wpopt'); ?></p>
                            </div>
                            <span class="wpopt-page-test-badge"><?php esc_html_e('Live test', 'wpopt'); ?></span>
                        </div>

                        <form class="wpopt-page-test-form" data-wpopt-page-test-form>
                            <label class="screen-reader-text" for="wpopt-page-test-url"><?php esc_html_e('Page URL', 'wpopt'); ?></label>
                            <input id="wpopt-page-test-url" type="url" inputmode="url" placeholder="<?php echo esc_attr(home_url('/')); ?>" value="<?php echo esc_attr(home_url('/')); ?>" data-wpopt-page-test-url>
                            <button type="submit" class="wps wps-button wpopt-btn is-info" data-wpopt-page-test-submit>
                                <span class="wpopt-page-test-button-progress" aria-hidden="true"></span>
                                <span class="dashicons dashicons-performance"></span>
                                <span data-wpopt-page-test-button-text><?php esc_html_e('Test now', 'wpopt'); ?></span>
                            </button>
                        </form>

                        <div class="wpopt-page-test-status" data-wpopt-page-test-status aria-live="polite">
                            <?php esc_html_e('Ready to test.', 'wpopt'); ?>
                        </div>

                        <div class="wpopt-page-test-steps" data-wpopt-page-test-steps>
                            <div class="wpopt-page-test-step" data-step="disabled">
                                <span class="dashicons dashicons-hidden"></span>
                                <strong><?php esc_html_e('Without WP Optimizer config', 'wpopt'); ?></strong>
                                <small><?php esc_html_e('Measured signed request with WP Optimizer modules bypassed for this load.', 'wpopt'); ?></small>
                                <div class="wpopt-page-test-step-metrics">
                                    <span><b><?php esc_html_e('Speed', 'wpopt'); ?></b><em data-wpopt-page-test-speed>--</em></span>
                                    <span><b><?php esc_html_e('Memory', 'wpopt'); ?></b><em data-wpopt-page-test-memory>--</em></span>
                                </div>
                            </div>
                            <div class="wpopt-page-test-step" data-step="active_empty">
                                <span class="dashicons dashicons-update"></span>
                                <strong><?php esc_html_e('Current config empty scan', 'wpopt'); ?></strong>
                                <small><?php esc_html_e('Unmeasured request with the current configuration before diagnostic warmup.', 'wpopt'); ?></small>
                            </div>
                            <div class="wpopt-page-test-step" data-step="warmup">
                                <span class="dashicons dashicons-search"></span>
                                <strong><?php esc_html_e('Current config diagnostic warmup', 'wpopt'); ?></strong>
                                <small><?php esc_html_e('Unmeasured request that scans slow queries, repeated queries, hooks and callbacks.', 'wpopt'); ?></small>
                            </div>
                            <div class="wpopt-page-test-step" data-step="active">
                                <span class="dashicons dashicons-chart-line"></span>
                                <strong><?php esc_html_e('Current config measurement', 'wpopt'); ?></strong>
                                <small><?php esc_html_e('Measured request with the plugin active exactly as configured now.', 'wpopt'); ?></small>
                                <div class="wpopt-page-test-step-metrics">
                                    <span><b><?php esc_html_e('Speed', 'wpopt'); ?></b><em data-wpopt-page-test-speed>--</em></span>
                                    <span><b><?php esc_html_e('Memory', 'wpopt'); ?></b><em data-wpopt-page-test-memory>--</em></span>
                                </div>
                            </div>
                        </div>

                        <div class="wpopt-page-test-results" data-wpopt-page-test-results hidden>
                            <div class="wpopt-page-test-result-summary" data-wpopt-page-test-summary>
                                <div class="wpopt-page-test-result-card" data-summary-card="speed">
                                    <span class="dashicons dashicons-performance"></span>
                                    <small><?php esc_html_e('Speed change', 'wpopt'); ?></small>
                                    <strong data-summary-value>--</strong>
                                    <em data-summary-detail><?php esc_html_e('Current vs baseline', 'wpopt'); ?></em>
                                </div>
                                <div class="wpopt-page-test-result-card" data-summary-card="ttfb">
                                    <span class="dashicons dashicons-clock"></span>
                                    <small><?php esc_html_e('TTFB change', 'wpopt'); ?></small>
                                    <strong data-summary-value>--</strong>
                                    <em data-summary-detail><?php esc_html_e('Current vs baseline', 'wpopt'); ?></em>
                                </div>
                                <div class="wpopt-page-test-result-card" data-summary-card="memory">
                                    <span class="dashicons dashicons-database"></span>
                                    <small><?php esc_html_e('Memory change', 'wpopt'); ?></small>
                                    <strong data-summary-value>--</strong>
                                    <em data-summary-detail><?php esc_html_e('Current vs baseline', 'wpopt'); ?></em>
                                </div>
                                <div class="wpopt-page-test-result-card" data-summary-card="size">
                                    <span class="dashicons dashicons-media-code"></span>
                                    <small><?php esc_html_e('Size change', 'wpopt'); ?></small>
                                    <strong data-summary-value>--</strong>
                                    <em data-summary-detail><?php esc_html_e('Current vs baseline', 'wpopt'); ?></em>
                                </div>
                            </div>
                            <div class="wpopt-page-test-results-list" data-wpopt-page-test-result-body></div>
                        </div>

                        <div class="wpopt-page-test-diagnostics" data-wpopt-page-test-diagnostics hidden>
                            <h3 class="wpopt-page-test-diagnostics-title"><?php esc_html_e('Optimization opportunities found during warmup', 'wpopt'); ?></h3>

                            <div class="wpopt-page-test-diagnostics-grid">
                                <section class="wpopt-page-test-diagnostics-card" data-wpopt-page-test-diagnostics-hooks>
                                    <h4><?php esc_html_e('Heavy hooks and callbacks', 'wpopt'); ?></h4>
                                    <div class="wpopt-page-test-diagnostics-content"></div>
                                </section>
                                <section class="wpopt-page-test-diagnostics-card" data-wpopt-page-test-diagnostics-queries>
                                    <h4><?php esc_html_e('Slow queries', 'wpopt'); ?></h4>
                                    <div class="wpopt-page-test-diagnostics-content"></div>
                                </section>
                                <section class="wpopt-page-test-diagnostics-card" data-wpopt-page-test-diagnostics-duplicates>
                                    <h4><?php esc_html_e('Repeated queries', 'wpopt'); ?></h4>
                                    <div class="wpopt-page-test-diagnostics-content"></div>
                                </section>
                            </div>
                        </div>
                    </div>
                </block>
            </div>
        </section>
        <?php
    }

    private function render_app_dashboard(): void
    {
        $dashboard = $this->get_dashboard_view_model();
        $active_modules_count = $dashboard['active_modules_count'];
        $is_cron_running = $dashboard['is_cron_running'];
        $tracking_enabled = $dashboard['tracking_enabled'];
        $persistent_cache = $dashboard['persistent_cache'];
        $server_load = $dashboard['server_load'];
        $memory_load = wps_core()->meter->get_memory(true, true);
        $execution_time = wps_core()->meter->get_time('wp_start', 'now', 3);
        $health_score = $dashboard['health_score'];
        $cron_overview = $dashboard['cron_overview'] ?? array();
        $overview_cards = array(
            array(
                'class'  => 'is-blue',
                'icon'   => 'dashicons-admin-plugins',
                'label'  => __('Active modules', 'wpopt'),
                'value'  => (string)$active_modules_count,
                'note'   => __('All systems operational', 'wpopt'),
                'detail' => sprintf(
                    _n(
                        '%s optimization module is currently enabled and available from the modules panel.',
                        '%s optimization modules are currently enabled and available from the modules panel.',
                        $active_modules_count,
                        'wpopt'
                    ),
                    number_format_i18n($active_modules_count)
                ),
            ),
            array(
                'class'  => 'is-green',
                'icon'   => 'dashicons-clock',
                'label'  => __('Cron status', 'wpopt'),
                'value'  => $is_cron_running ? __('Running', 'wpopt') : __('Idle', 'wpopt'),
                'note'   => $is_cron_running ? __('Optimization job in progress', 'wpopt') : __('Background scheduler ready', 'wpopt'),
                'detail' => $is_cron_running
                    ? __('A background optimization job is currently running. Avoid launching overlapping tasks until it finishes.', 'wpopt')
                    : __('The background scheduler is ready and no optimization job is currently running.', 'wpopt'),
            ),
            array(
                'class'  => 'is-purple',
                'icon'   => 'dashicons-chart-area',
                'label'  => __('Tracking', 'wpopt'),
                'value'  => $tracking_enabled ? __('Enabled', 'wpopt') : __('Disabled', 'wpopt'),
                'note'   => $tracking_enabled ? __('Monitoring is active', 'wpopt') : __('Monitoring is disabled', 'wpopt'),
                'detail' => $tracking_enabled
                    ? __('Tracking sends small pieces of information when errors occur to help improve the plugin.', 'wpopt')
                    : __('Tracking sends small pieces of information when errors occur to help improve the plugin.', 'wpopt'),
            ),
            array(
                'class'  => 'is-orange',
                'icon'   => 'dashicons-database',
                'label'  => __('Persistent cache', 'wpopt'),
                'value'  => $persistent_cache ? __('Enabled', 'wpopt') : __('Not configured', 'wpopt'),
                'note'   => $persistent_cache ? __('Persistent layer detected', 'wpopt') : __('Setup recommended', 'wpopt'),
                'detail' => $persistent_cache
                    ? __('A persistent object cache layer is detected for this WordPress installation.', 'wpopt')
                    : __('No persistent object cache layer is detected. Configure Redis or Memcached support to reduce repeated database work. To activate persistent cache for your site copy this define(\'WP_PERSISTENT_CACHE\', true); in wp-config.php', 'wpopt'),
            ),
            array(
                'class'        => 'is-mint',
                'icon'         => 'dashicons-shield-alt',
                'label'        => __('Optimization health', 'wpopt'),
                'value'        => $health_score >= 80 ? __('Good', 'wpopt') : __('Needs review', 'wpopt'),
                'note'         => sprintf('%s%%', number_format_i18n($health_score)),
                'detail'       => sprintf(
                    __('Current health score is %s%%. It is calculated from recommended module coverage, scheduler readiness, tracking status, persistent cache availability, and current server load.', 'wpopt'),
                    number_format_i18n($health_score)
                ),
                'health_meter' => true,
                'health_score' => $health_score,
            ),
        );
        ?>
        <section class="wps-wrap wpopt-shell wpopt-dashboard-shell wpopt-app-dashboard">
            <div class="wpopt-app-dashboard-main">
                <block class="wps wpopt-hero wpopt-dashboard-hero">
                    <div class="wpopt-hero-copy">
                        <h2><?php esc_html_e('Optimize. Accelerate. Simplify.', 'wpopt'); ?></h2>
                        <p class="wpopt-hero-subtitle"><?php esc_html_e('Centralize your optimization workflow with quick actions, live metrics, and direct module access to keep your site fast and healthy.', 'wpopt'); ?></p>
                        <div class="wpopt-actions">
                            <a class="wps wps-button wpopt-btn is-info" href="<?php echo esc_url(wps_admin_route_url('wpopt', 'setting-modules_handler')); ?>">
                                <span class="dashicons dashicons-screenoptions"></span><?php esc_html_e('Manage Modules', 'wpopt'); ?>
                            </a>
                            <a class="wps wps-button wpopt-btn is-neutral" href="<?php echo esc_url(wps_module_setting_url('wpopt')); ?>">
                                <span class="dashicons dashicons-admin-generic"></span><?php esc_html_e('Configure Modules', 'wpopt'); ?>
                            </a>
                        </div>
                    </div>
                    <div class="wpopt-hero-art" aria-hidden="true">
                        <?php $this->render_dashboard_hero_art(); ?>
                    </div>
                </block>
                <block class="wps wpopt-panel wpopt-overview-panel">
                    <div class="wpopt-panel-head">
                        <h2><span class="dashicons dashicons-chart-line"></span><?php esc_html_e('System Overview', 'wpopt'); ?></h2>
                        <span><?php echo esc_html(sprintf(__('Last updated: %s', 'wpopt'), $dashboard['last_updated'])); ?></span>
                    </div>
                    <div class="wpopt-scroll-x" role="region" aria-label="<?php esc_attr_e('System overview cards', 'wpopt'); ?>">
                        <div class="wpopt-kpi-grid">
                            <?php foreach ($overview_cards as $overview_card) : ?>
                                <button
                                        type="button"
                                        class="wpopt-kpi-card <?php echo esc_attr($overview_card['class']); ?>"
                                        data-wpopt-kpi-popup
                                        data-title="<?php echo esc_attr($overview_card['label']); ?>"
                                        data-detail="<?php echo esc_attr($overview_card['detail']); ?>"
                                        aria-haspopup="dialog"
                                >
                                    <span class="wpopt-kpi-icon dashicons <?php echo esc_attr($overview_card['icon']); ?>"></span>
                                    <span class="wpopt-kpi-label"><?php echo esc_html($overview_card['label']); ?></span>
                                    <strong class="wpopt-kpi-value"><?php echo esc_html($overview_card['value']); ?></strong>
                                    <?php if (!empty($overview_card['health_meter'])) : ?>
                                        <small class="wpopt-health-meter"><span style="width: <?php echo esc_attr($overview_card['health_score']); ?>%"></span><b><?php echo esc_html($overview_card['note']); ?></b></small>
                                    <?php else : ?>
                                        <small><?php echo esc_html($overview_card['note']); ?></small>
                                    <?php endif; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </block>
                <block class="wps wpopt-panel wpopt-modules-panel">
                    <div class="wpopt-panel-head">
                        <h2><span class="dashicons dashicons-admin-plugins"></span><?php esc_html_e('Modules', 'wpopt'); ?></h2>
                        <span><?php esc_html_e('Manage and access your optimization tools', 'wpopt'); ?></span>
                    </div>
                    <div class="wpopt-module-grid">
                        <?php foreach ($dashboard['module_cards'] as $module_card) : ?>
                            <a class="wpopt-module-card" href="<?php echo esc_url($module_card['url']); ?>">
                                <span class="wpopt-module-icon dashicons <?php echo esc_attr($module_card['icon']); ?>"></span>
                                <strong><?php echo esc_html($module_card['name']); ?></strong>
                                <small><?php echo $module_card['active'] ? esc_html__('Ready for optimization tasks.', 'wpopt') : esc_html__('Module currently disabled.', 'wpopt'); ?></small>
                                <span class="wpopt-module-foot">
                                    <em class="<?php echo $module_card['active'] ? 'is-active' : 'is-inactive'; ?>"><?php echo $module_card['active'] ? esc_html__('Active', 'wpopt') : esc_html__('Inactive', 'wpopt'); ?></em>
                                    <span class="dashicons dashicons-arrow-right-alt2"></span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="wpopt-view-all">
                        <a href="<?php echo esc_url(wps_admin_route_url('wpopt', 'setting-modules_handler')); ?>">
                            <span class="dashicons dashicons-screenoptions"></span><?php esc_html_e('View all modules', 'wpopt'); ?>
                        </a>
                    </div>
                </block>
                <?php if (!empty($cron_overview['active'])) : ?>
                    <block class="wps wpopt-panel wpopt-cron-overview-panel">
                        <div class="wpopt-panel-head">
                            <h2><span class="dashicons dashicons-clock"></span><?php esc_html_e('WP Optimizer cron', 'wpopt'); ?></h2>
                            <div class="wpopt-cron-overview-head-actions">
                                <form method="POST" class="wpopt-cron-action-form">
                                    <?php wp_nonce_field('wpopt-nonce'); ?>
                                    <?php if (!empty($cron_overview['running'])) : ?>
                                        <button name="wpopt-cron-reset" type="submit" class="wps wps-button wpopt-btn is-danger">
                                            <span class="dashicons dashicons-controls-pause"></span><?php esc_html_e('Pause cron', 'wpopt'); ?>
                                        </button>
                                    <?php else : ?>
                                        <button name="wpopt-cron-run" type="submit" class="wps wps-button wpopt-btn is-neutral">
                                            <span class="dashicons dashicons-controls-play"></span><?php esc_html_e('Run now', 'wpopt'); ?>
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                        <div class="wpopt-cron-overview-grid">
                            <div class="wpopt-cron-overview-summary">
                                <span class="wpopt-cron-overview-icon dashicons dashicons-update"></span>
                                <div>
                                    <strong><?php esc_html_e('Automatic optimizations', 'wpopt'); ?></strong>
                                    <small><?php esc_html_e('WP Optimizer will run enabled optimization tasks using the configured schedule.', 'wpopt'); ?></small>
                                </div>
                            </div>
                            <div class="wpopt-cron-overview-stat">
                                <span><?php esc_html_e('Execution time', 'wpopt'); ?></span>
                                <strong><?php echo esc_html($cron_overview['execution_time']); ?></strong>
                            </div>
                            <div class="wpopt-cron-overview-stat">
                                <span><?php esc_html_e('Schedule', 'wpopt'); ?></span>
                                <strong><?php echo esc_html($cron_overview['recurrence_name']); ?></strong>
                            </div>
                            <div class="wpopt-cron-overview-stat">
                                <span><?php esc_html_e('Next run', 'wpopt'); ?></span>
                                <strong><?php echo esc_html($cron_overview['next_run'] ?: __('Pending schedule', 'wpopt')); ?></strong>
                            </div>
                        </div>
                        <div class="wpopt-cron-task-list">
                            <span><?php esc_html_e('Enabled tasks', 'wpopt'); ?></span>
                            <?php if (!empty($cron_overview['tasks'])) : ?>
                                <?php foreach ($cron_overview['tasks'] as $cron_task) : ?>
                                    <b><?php echo esc_html($cron_task); ?></b>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <em><?php esc_html_e('No optimization tasks enabled.', 'wpopt'); ?></em>
                            <?php endif; ?>
                        </div>
                    </block>
                <?php endif; ?>
                <block class="wps wpopt-panel wpopt-tracking-panel">
                    <div class="wpopt-panel-head">
                        <h2><span class="dashicons dashicons-chart-area"></span><?php esc_html_e('Status', 'wpopt'); ?></h2>
                    </div>
                    <div class="wpopt-tracking-grid">
                        <div class="wpopt-tracking-stat">
                            <span><?php esc_html_e('Active modules', 'wpopt'); ?></span>
                            <strong><?php echo esc_html($active_modules_count); ?></strong>
                            <small><?php echo esc_html(sprintf('%s%%', $health_score)); ?></small>
                        </div>
                        <div class="wpopt-tracking-stat">
                            <span><?php esc_html_e('Memory peak', 'wpopt'); ?></span>
                            <strong><?php echo esc_html($memory_load); ?></strong>
                            <small><?php esc_html_e('live', 'wpopt'); ?></small>
                        </div>
                        <div class="wpopt-tracking-stat">
                            <span><?php esc_html_e('Server load', 'wpopt'); ?></span>
                            <strong><?php echo esc_html($server_load); ?></strong>
                            <small><?php esc_html_e('live', 'wpopt'); ?></small>
                        </div>
                        <div class="wpopt-tracking-stat">
                            <span><?php esc_html_e('Execution time', 'wpopt'); ?></span>
                            <strong><?php echo esc_html(sprintf('%s s', $execution_time)); ?></strong>
                            <small><?php esc_html_e('live', 'wpopt'); ?></small>
                        </div>
                    </div>
                </block>
            </div>
            <?php $this->render_sidebar(); ?>
        </section>
        <?php
    }

    private function get_app_module_icon(string $slug): string
    {
        $icons = array(
            'database'            => 'database',
            'media'               => 'image',
            'wp_customizer'       => 'sliders',
            'wp_optimizer'        => 'gauge',
            'wp_security'         => 'shield',
            'cache'               => 'server',
            'minify'              => 'tools',
            'activitylog'         => 'list',
            'wp_mail'             => 'mail',
            'wp_updates'          => 'repeat',
            'wp_info'             => 'info',
            'pagespeed'           => 'pagespeed',
            'performance_monitor' => 'chart',
        );

        return $icons[$slug] ?? 'tools';
    }

    private function get_settings_nav_items(string $context): array
    {
        $items = array();

        foreach (wps($context)->settings->get_module_setting_pages() as $page) {
            $items[] = array(
                'id'    => 'module-setting-' . $page['id'],
                'label' => $page['label'],
                'icon'  => $page['icon'],
                'url'   => wps_admin_route_url($context, 'module-setting-' . $page['id']),
            );
        }

        return $items;
    }

    private function get_core_settings_nav_items(string $context): array
    {
        $items = array();

        foreach (wps($context)->settings->get_core_setting_pages() as $page) {
            $items[] = array(
                'id'    => 'setting-' . $page['id'],
                'label' => $page['label'],
                'icon'  => $page['icon'],
                'url'   => wps_admin_route_url($context, 'setting-' . $page['id']),
            );
        }

        return $items;
    }

    private function render_first_core_setting_page(string $context): void
    {
        $pages = wps($context)->settings->get_core_setting_pages();
        $page_id = $pages[0]['id'] ?? '';

        wps($context)->settings->render_core_setting_page($page_id, false);
    }

    private function render_app_panel(callable $callback): void
    {
        ob_start();
        call_user_func($callback);
        $content = ob_get_clean();

        echo $content;
    }

    private function get_donation_url(): string
    {
        return 'https://www.paypal.com/donate/?hosted_button_id=8G8VR4APG9JRU';
    }

    private function get_review_url(): string
    {
        return 'https://wordpress.org/support/plugin/wp-optimizer/reviews/';
    }
}

