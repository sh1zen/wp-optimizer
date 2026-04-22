<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\core;

use WPS\core\CronActions;
use WPS\core\UtilEnv;

/**
 * Creates the menu page for the plugin.
 *
 * Provides the functionality necessary for rendering the page corresponding
 * to the menu with which this page is associated.
 */
class PagesHandler
{
    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_plugin_pages'));
        add_action('admin_enqueue_scripts', array($this, 'register_assets'), 20, 0);

        add_action('admin_notices', [$this, 'notice'], 10, 0);
    }

    public function notice(): void
    {
        global $pagenow;

        $user_id = wps_core()->get_cuID();
        $dismiss_nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

        if (isset($_GET['wpopt-dismiss-notice']) && $dismiss_nonce && wp_verify_nonce($dismiss_nonce, 'wpopt-dismiss-notice')) {

            wps('wpopt')->options->add($user_id, 'dismissed', true, 'admin-notice', WEEK_IN_SECONDS);
        }
        elseif ($pagenow == 'index.php' and !wps('wpopt')->options->get($user_id, 'dismissed', 'admin-notice', false)) {
            $donation_url = $this->get_donation_url();
            $review_url = $this->get_review_url();
            $dismiss_url = wp_nonce_url(add_query_arg('wpopt-dismiss-notice', '1'), 'wpopt-dismiss-notice');

            ?>
            <div class="notice notice-info notice-alt is-dismissible">
                <h3><?php _e('Enjoying WP Optimizer?', 'wpopt'); ?></h3>
                <p><?php _e('If the plugin is saving you time or improving site performance, a donation helps fund maintenance and new features. A 5-star review helps more users discover it.', 'wpopt'); ?></p>
                <p>
                    <a class="button button-primary" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($donation_url); ?>"><?php _e('Support development', 'wpopt'); ?></a>
                    <a class="button button-secondary" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($review_url); ?>"><?php _e('Leave a 5-star review', 'wpopt'); ?></a>
                    <a class="button-link" href="<?php echo esc_url($dismiss_url); ?>"><?php _e('Dismiss', 'wpopt'); ?></a>
                </p>
            </div>
            <?php
        }
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

        /**
         * Modules - sub pages
         */
        foreach (wps('wpopt')->moduleHandler->get_modules(array('scopes' => 'admin-page')) as $module) {
            wps('wpopt')->moduleHandler->get_module_instance($module)->register_panel('wp-optimizer');
        }

        /**
         * Modules options page
         */
        add_submenu_page('wp-optimizer', __('WPOPT Modules Options', 'wpopt'), __('Modules', 'wpopt'), 'manage_options', 'wpopt-modules-settings', array($this, 'render_modules_settings'));

        /**
         * Plugin core settings
         */
        add_submenu_page('wp-optimizer', __('WPOPT Settings', 'wpopt'), __('Settings', 'wpopt'), 'manage_options', 'wpopt-settings', array($this, 'render_core_settings'));

        /**
         * Plugin core settings
         */
        add_submenu_page('wp-optimizer', __('WPOPT FAQ', 'wpopt'), __('FAQ', 'wpopt'), 'edit_posts', 'wpopt-faqs', array($this, 'render_faqs'));

        add_action('wpopt_enqueue_panel_scripts', [$this, 'enqueue_scripts']);
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
            echo wps_core()->meter->get_time() . ' - ' . wps_core()->meter->get_memory();
        }
    }

    public function enqueue_scripts(): void
    {
        wp_enqueue_style('wpopt_css');
        wp_enqueue_script('vendor-wps-js');
    }

    public function render_core_settings(): void
    {
        $this->enqueue_scripts();

        wps('wpopt')->settings->render_core_settings();

        if (WPOPT_DEBUG) {
            echo wps_core()->meter->get_time() . ' - ' . wps_core()->meter->get_memory();
        }
    }

    public function register_assets(): void
    {
        $assets_url = UtilEnv::path_to_url(WPOPT_ABSPATH);

        $min = wps_core()->online ? '.min' : '';
        $style_path = WPOPT_ABSPATH . 'assets/style' . $min . '.css';

        if (!file_exists($style_path)) {
            $min = '';
        }

        wp_register_style("wpopt_css", "{$assets_url}assets/style{$min}.css", ['vendor-wps-css']);

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

    public function render_faqs(): void
    {
        $this->enqueue_scripts();
        ?>
        <section class="wps wps-wrap wpopt-faq-shell">
            <block class="wps wpopt-hero">
                <section class='wps-header'>
                    <h1><?php _e('Frequently Asked Questions', 'wpopt'); ?></h1>
                </section>
                <p class="wpopt-hero-subtitle"><?php _e('Find quick answers about optimization workflows, privacy, and configuration paths.', 'wpopt'); ?></p>
                <div class="wpopt-actions">
                    <a class="wps wps-button wpopt-btn is-info" href="<?php echo admin_url('admin.php?page=wp-optimizer'); ?>"><?php _e('Back to Dashboard', 'wpopt'); ?></a>
                    <a class="wps wps-button wpopt-btn is-neutral" href="<?php echo admin_url('admin.php?page=wpopt-modules-settings'); ?>"><?php _e('Open Modules Options', 'wpopt'); ?></a>
                </div>
            </block>
            <block class="wps">
                <div class="wps-faq-list">
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wps-collapse-handler"><?php echo __('What can this plugin do and how does it work?', 'wpopt') ?>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p>
                                    <b><?php echo __('This plugin is privacy-oriented: optimization runs on your server and does not require sending your site data to third-party services.', 'wpopt'); ?></b>
                                </p>
                                <span><?php echo __('WP Optimizer is modular, so you can enable only the features you need to keep the plugin overhead low.', 'wpopt'); ?></span>
                                <ul class="wps-list">
                                    <li><?php _e('Server enhancements such as compression, browser caching, and .htaccess rules.', 'wpopt'); ?></li>
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
                                    class="wps-faq-question wps-collapse-handler"><?php echo __('What should I enable first on a live site?', 'wpopt') ?>
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
                                    class="wps-faq-question wps-collapse-handler"><?php echo __('Where can I configure optimization parameters?', 'wpopt') ?>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php echo sprintf(__('Most optimization options are configurable in the <a href="%s">Modules Options panel</a>.', 'wpopt'), admin_url('admin.php?page=wpopt-modules-settings')); ?></p>
                                <p><?php echo sprintf(__('Global tasks such as cron, module activation, telemetry, reset, export, and restore are available from the <a href="%s">Settings page</a>.', 'wpopt'), admin_url('admin.php?page=wpopt-settings')); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wps-collapse-handler"><?php echo __('Can cache or minify break my layout?', 'wpopt') ?>
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
                                    class="wps-faq-question wps-collapse-handler"><?php echo __('How does the media optimizer work?', 'wpopt') ?>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php echo __('Media optimizer works in three different ways:', 'wpopt'); ?></p>
                                <ul class="wps-list">
                                    <li><?php echo sprintf(__("By a scheduled event it's able to collect and optimize any media uploaded daily. <a href='%s'>Here</a> you can configure all schedule related settings.", 'wpopt'), admin_url('admin.php?page=wpopt-settings#settings-cron')); ?></li>
                                    <li><?php _e('By a specific path scanner, Media optimizer will run a background activity to optimize all images present in the input path.', 'wpopt'); ?></li>
                                    <li><?php _e('By a whole database scanner, Media optimizer will run a background activity to check all images saved in your WordPress library optimizing each image and every thumbnail associated.', 'wpopt'); ?></li>
                                </ul>
                                <p><?php echo sprintf(__('Any image optimization will be run following your settings set <a href="%s">Here</a>.', 'wpopt'), admin_url('admin.php?page=wpopt-modules-settings#settings-media')); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wps-collapse-handler"><?php echo __('Why are image optimization jobs not starting?', 'wpopt') ?>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php echo __('Bulk image optimization runs in background, so it depends on the plugin scheduler and on image libraries available on the server.', 'wpopt'); ?></p>
                                <ul class="wps-list">
                                    <li><?php echo sprintf(__('Verify that <a href="%s">cron is active</a>; background jobs will not progress if scheduled tasks are not running.', 'wpopt'), admin_url('admin.php?page=wpopt-settings#settings-cron')); ?></li>
                                    <li><?php _e('Check that Imagick or GD is available in PHP. Imagick gives the best coverage, but GD can still handle basic processing.', 'wpopt'); ?></li>
                                    <li><?php echo sprintf(__('Review your <a href="%s">media settings</a> before starting a full-library scan so the job uses the correct quality and format options.', 'wpopt'), wps_module_setting_url('wpopt', 'media')); ?></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wps-collapse-handler"><?php echo __('Do I need Redis or Memcached for object cache?', 'wpopt') ?>
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
                                    class="wps-faq-question wps-collapse-handler"><?php echo __('How can I clean the database safely?', 'wpopt') ?>
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
                                    class="wps-faq-question wps-collapse-handler"><?php echo __('Can I export settings before major changes?', 'wpopt') ?>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php echo sprintf(__('Yes. The <a href="%s">Settings page</a> lets you export, import, reset, or restore plugin options.', 'wpopt'), admin_url('admin.php?page=wpopt-settings')); ?></p>
                                <p><?php _e('Use an export before testing aggressive cache, minify, or security changes so you can quickly return to a known-good configuration.', 'wpopt'); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wps-collapse-handler"><?php echo __('Does WP Optimizer work on multisite?', 'wpopt') ?>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php _e('Yes. The plugin supports multisite and can be network-activated.', 'wpopt'); ?></p>
                                <p><?php echo sprintf(__('Even on multisite, test optimization changes gradually from the <a href="%s">module settings</a> because each subsite may use different themes, plugins, and traffic patterns.', 'wpopt'), admin_url('admin.php?page=wpopt-modules-settings')); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </block>
        </section>
        <?php
    }

    /**
     * This function renders the contents of the page associated with the menu
     * that invokes the render method. In the context of this plugin, this is the
     * menu class.
     */
    public function render_main(): void
    {
        if (wps_get_page_args('do_welcome')) {
            $this->render_welcome();
            return;
        }

        $this->enqueue_scripts();

        $data = array();

        if (isset($_POST['wpopt-cron-run'])) {

            if (UtilEnv::verify_nonce('wpopt-nonce')) {
                CronActions::run_event('wpopt-cron');
            }
        }
        elseif (isset($_POST['wpopt-cron-reset'])) {

            if (UtilEnv::verify_nonce('wpopt-nonce')) {
                wps('wpopt')->cron->reset_status();
            }
        }

        settings_errors();

        $modules = wps('wpopt')->moduleHandler->get_modules(array('excepts' => array('cron', 'modules_handler', 'settings', 'tracking')));
        $active_modules_count = count($modules);
        $is_cron_running = wps('wpopt')->settings->get('cron.running', false);
        $tracking_enabled = wps('wpopt')->settings->get('tracking.errors', true) || wps('wpopt')->settings->get('tracking.usage', true);
        $persistent_cache = defined('WP_PERSISTENT_CACHE');
        $server_load = UtilEnv::get_server_load(false);
        $memory_load = wps_core()->meter->get_memory(true, true);
        $execution_time = wps_core()->meter->get_time('wp_start', 'now', 3);
        ?>
        <section class="wps-wrap-flex wps-wrap wps-home wpopt-shell">
            <section class="wps wpopt-main">
                <?php

                if (!empty($data)) {
                    $this->output_results($data);
                }
                ?>
                <block class="wps wpopt-hero">
                    <block class="wps-header">
                        <h1>WP Optimizer Dashboard</h1>
                    </block>
                    <p class="wpopt-hero-subtitle"><?php _e('Centralize your optimization workflow with quick actions, live metrics, and direct module access.', 'wpopt'); ?></p>
                    <div class="wpopt-actions">
                        <?php echo sprintf(__('<a class="wps wps-button wpopt-btn is-info" href="%s">Manage Modules</a>', 'wpopt'), admin_url('admin.php?page=wpopt-settings#settings-modules_handler')); ?>
                        <?php echo sprintf(__('<a class="wps wps-button wpopt-btn is-info" href="%s">Configure Modules</a>', 'wpopt'), admin_url('admin.php?page=wpopt-modules-settings')); ?>
                    </div>
                </block>
                <block class="wps">
                    <h2><?php _e('System overview', 'wpopt'); ?></h2>
                    <div class="wpopt-scroll-x" role="region" aria-label="<?php esc_attr_e('System overview cards', 'wpopt'); ?>">
                        <div class="wpopt-kpi-grid">
                            <div class="wpopt-kpi-card">
                                <span class="wpopt-kpi-label"><?php _e('Active modules', 'wpopt'); ?></span>
                                <strong class="wpopt-kpi-value"><?php echo $active_modules_count; ?></strong>
                            </div>
                            <div class="wpopt-kpi-card">
                                <span class="wpopt-kpi-label"><?php _e('Cron status', 'wpopt'); ?></span>
                                <strong class="wpopt-kpi-value"><?php echo $is_cron_running ? __('Running', 'wpopt') : __('Idle', 'wpopt'); ?></strong>
                            </div>
                            <div class="wpopt-kpi-card">
                                <span class="wpopt-kpi-label"><?php _e('Tracking', 'wpopt'); ?></span>
                                <strong class="wpopt-kpi-value"><?php echo $tracking_enabled ? __('Enabled', 'wpopt') : __('Disabled', 'wpopt'); ?></strong>
                            </div>
                            <div class="wpopt-kpi-card">
                                <span class="wpopt-kpi-label"><?php _e('Persistent cache', 'wpopt'); ?></span>
                                <strong class="wpopt-kpi-value"><?php echo $persistent_cache ? __('Enabled', 'wpopt') : __('Not configured', 'wpopt'); ?></strong>
                            </div>
                        </div>
                    </div>
                </block>
                <block class="wps">
                    <h2><?php _e('Modules:', 'wpopt'); ?></h2>
                    <?php
                    echo '<b>' . __('Currently WP-Optimizer active modules:', 'wpopt') . '</b><br><br>';
                    echo '<div class="wps-gridRow wpopt-chip-grid">';
                    foreach ($modules as $module) {
                        echo "<a class='wps-code' target='_blank' href='" . (wps('wpopt')->moduleHandler->get_module_instance($module)->has_panel() ? wps_module_panel_url($module['slug']) : wps_module_setting_url('wpopt', $module['slug'])) . "'>{$module['name']}</a>";
                    }
                    echo '</div>';
                    ?>
                </block>
                <?php if ($tracking_enabled): ?>
                    <block class="wps wpopt-notice-card">
                        <h2><?php _e('Tracking status', 'wpopt'); ?></h2>
                        <strong><?php echo sprintf(__('A tracking option is enabled, see more <a href="%s">here</a>.', 'wpopt'), admin_url('admin.php?page=wpopt-settings#settings-tracking')); ?></strong>
                        <br><br>
                        <strong><?php echo __('No personal data is collected, only plugin error details and optional anonymous usage statistics.', 'wpopt'); ?></strong>
                    </block>
                <?php else: ?>
                    <block class="wps wpopt-notice-card">
                        <h2><?php _e('Tracking status', 'wpopt'); ?></h2>
                        <strong><?php echo sprintf(__('If you run into problems, enable <a href="%s">this</a> feature so the developer can receive useful diagnostics.', 'wpopt'), admin_url('admin.php?page=wpopt-settings#settings-tracking')); ?></strong>
                        <br><br>
                        <strong><?php echo __('No personal data will be collected.', 'wpopt'); ?></strong>
                    </block>
                <?php endif; ?>
                <block class="wps">
                    <h2><?php _e('Fast actions:', 'wpopt'); ?></h2>
                    <h4><?php _e('Run now the optimization process.', 'wpopt'); ?></h4>
                    <?php if ($is_cron_running): ?>
                        <h4><strong><?php _e('A cron job is running.', 'wpopt'); ?></strong></h4>
                    <?php endif; ?>
                    <form method="POST" class="wpopt-actions wpopt-actions-form">
                        <?php wp_nonce_field('wpopt-nonce'); ?>
                        <input name="wpopt-cron-run" type="submit"
                               value="<?php _e('Auto optimize now', 'wpopt') ?>" <?php echo $is_cron_running ? "disabled" : "" ?>
                               class="wps wps-button wpopt-btn is-success">
                        <?php if ($is_cron_running): ?>
                            <input name="wpopt-cron-reset" type="submit"
                                   value="<?php _e('Reset cron status', 'wpopt') ?>"
                                   class="wps wps-button wpopt-btn is-danger">
                        <?php endif; ?>
                    </form>
                </block>
                <?php if (!defined('WP_PERSISTENT_CACHE')): ?>
                    <block class="wps">
                        <h2><?php _e('Persistent cache:', 'wpopt'); ?></h2>
                        <p><?php _e('WP-Optimizer supports <b>Redis</b> and <b>Memcached</b> systems.', 'wpopt'); ?></p>
                        <p><?php _e('To activate persistent cache for your site copy this <b>define(\'WP_PERSISTENT_CACHE\', true);</b> in wp-config.php', 'wpopt'); ?></p>
                    </block>
                <?php endif; ?>
                <block class="wps">
                    <h2><?php _e('WordPress performances:', 'wpopt'); ?></h2>
                    <div class="wpopt-metrics-grid">
                        <div class="wpopt-metric-item">
                            <span><?php _e('Server load', 'wpopt'); ?></span>
                            <strong><?php echo sprintf('%s %%', $server_load); ?></strong>
                        </div>
                        <div class="wpopt-metric-item">
                            <span><?php _e('WordPress memory load', 'wpopt'); ?></span>
                            <strong><?php echo $memory_load; ?></strong>
                        </div>
                        <div class="wpopt-metric-item">
                            <span><?php _e('WordPress execution time', 'wpopt'); ?></span>
                            <strong><?php echo sprintf('%s s', $execution_time); ?></strong>
                        </div>
                    </div>
                </block>
            </section>
            <?php $this->render_sidebar(); ?>
        </section>
        <?php
    }

    private function output_results($result = array())
    {
        if (isset($result)) {
            echo "<block>";
            echo " <h2>Operation results:</h2>";
            echo "<hr class='xi-hr'>";
            print_r($result);
            echo "</block>";
        }
    }

    private function render_welcome()
    {
        $this->enqueue_scripts();

        $modules = wps('wpopt')->moduleHandler->get_modules(array('excepts' => array('cron', 'modules_handler', 'settings', 'tracking')), false);
        ?>
        <section class="wps-wrap-flex wps-wrap wps-home wpopt-shell">
            <section class="wps wpopt-main">
                <block class="wps wpopt-hero">
                    <block class="wps-header">
                        <h1>Welcome to WP Optimizer</h1>
                    </block>
                    <p class="wpopt-hero-subtitle"><?php _e('Build a faster WordPress stack using modular optimization, automation, and targeted performance tools.', 'wpopt'); ?></p>
                    <div class="wpopt-actions">
                        <?php
                        echo sprintf(__('<a class="wps wps-button wpopt-btn is-info" href="%s">Open Dashboard</a>', 'wpopt'), admin_url('admin.php?page=wp-optimizer'));
                        echo sprintf(__('<a class="wps wps-button wpopt-btn is-info" href="%s">Manage Modules</a>', 'wpopt'), admin_url('admin.php?page=wpopt-settings#settings-modules_handler'));
                        ?>
                    </div>
                </block>
                <block class="wps">
                    <h2><?php _e('All available modules', 'wpopt'); ?></h2>
                    <p class="wpopt-muted"><?php _e('Activate only what you need to keep your stack lean and efficient.', 'wpopt'); ?></p>
                    <div class="wps-gridRow wpopt-chip-grid">
                        <?php
                        foreach ($modules as $module) {
                            echo "<span class='wps-code'>{$module['name']}</span>";
                        }
                        ?>
                    </div>
                </block>
                <block class="wps">
                    <h2><?php echo __('Try to explore this plugin:', 'wpopt'); ?></h2>
                    <div class="wpopt-actions">
                        <?php
                        echo sprintf(__('<a class="wps wps-button wpopt-btn is-info" href="%s">Home</a>', 'wpopt'), admin_url('admin.php?page=wp-optimizer'));
                        echo sprintf(__('<a class="wps wps-button wpopt-btn is-info" href="%s">Manage Modules</a>', 'wpopt'), admin_url('admin.php?page=wpopt-settings#settings-modules_handler'));
                        echo sprintf(__('<a class="wps wps-button wpopt-btn is-info" href="%s">Configure Modules</a>', 'wpopt'), admin_url('admin.php?page=wpopt-modules-settings'));
                        echo sprintf(__('<a class="wps wps-button wpopt-btn is-info" href="%s">FAQ</a>', 'wpopt'), admin_url('admin.php?page=wpopt-faqs'));
                        ?>
                    </div>
                </block>
            </section>
            <?php $this->render_sidebar(); ?>
        </section>
        <?php
    }

    private function render_sidebar(): void
    {
        $donation_url = $this->get_donation_url();
        $review_url = $this->get_review_url();
        ?>
        <aside class="wps wpopt-sidebar">
            <section class="wps-box">
                <div class="wps-donation-wrap">
                    <div class="wps-donation-title"><?php _e('WP Optimizer saving you time?', 'wpopt'); ?></div>
                    <p class="wpopt-muted"><?php _e('Support maintenance, fixes, and new features with a donation, or help more users discover the plugin with a 5-star review.', 'wpopt'); ?></p>
                    <div class="wpopt-inline-actions wpopt-support-cta">
                        <a class="wps wps-button wpopt-btn is-success" href="<?php echo esc_url($donation_url); ?>" target="_blank" rel="noopener noreferrer"><?php _e('Donate with PayPal', 'wpopt'); ?></a>
                        <a class="wps wps-button wpopt-btn is-info" href="<?php echo esc_url($review_url); ?>" target="_blank" rel="noopener noreferrer"><?php _e('Leave a 5-star review', 'wpopt'); ?></a>
                    </div>
                </div>
            </section>
            <section class="wps-box">
                <h3><?php _e('Want to support in other ways?', 'wpopt'); ?></h3>
                <ul class="wps wpopt-link-list">
                    <li>
                        <a href="https://translate.wordpress.org/projects/wp-plugins/wp-optimizer/"><?php _e('Help me translating', 'wpopt'); ?></a>
                    </li>
                </ul>
                <h3>WP-Optimizer</h3>
                <ul class="wps wpopt-link-list">
                    <li>
                        <a href="https://github.com/sh1zen/wp-optimizer/"><?php _e('Source code', 'wpopt'); ?></a>
                    </li>
                    <li>
                        <a href="https://sh1zen.github.io/"><?php _e('About me', 'wpopt'); ?></a>
                    </li>
                </ul>
            </section>
        </aside>
        <?php
    }

    private function get_donation_url(): string
    {
        return 'https://www.paypal.com/donate?business=dev.sh1zen%40outlook.it&item_name=Thank+you+in+advanced+for+the+kind+donations.+You+will+sustain+me+developing+WP-Optimizer.&currency_code=EUR';
    }

    private function get_review_url(): string
    {
        return 'https://wordpress.org/support/plugin/wp-optimizer/reviews/?filter=5';
    }
}

