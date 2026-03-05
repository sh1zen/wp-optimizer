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

        if (isset($_GET['wpopt-dismiss-notice'])) {

            wps('wpopt')->options->add($user_id, 'dismissed', true, 'admin-notice', MONTH_IN_SECONDS);
        }
        elseif ($pagenow == 'index.php' and !wps('wpopt')->options->get($user_id, 'dismissed', 'admin-notice', false)) {

            ?>
            <div class="notice notice-info is-dismissible">
                <h3>Help me to build <a href="<?php echo admin_url('admin.php?page=wp-optimizer'); ?>">WP-Optimizer</a>.
                </h3>
                <p><?php echo sprintf(__("Buy me a coffe <a target='_blank' href='%s'>here</a> or leave a review <a target='_blank' href='%s'>here</a>.", 'wpopt'), "https://www.paypal.com/donate?business=dev.sh1zen%40outlook.it&item_name=Thank+you+in+advanced+for+the+kind+donations.+You+will+sustain+me+developing+WP-Optimizer.&currency_code=EUR", "https://wordpress.org/support/plugin/wp-optimizer/reviews/?filter=5"); ?></p>
                <a href="?wpopt-dismiss-notice"><?php echo __('Dismiss', 'wpopt') ?></a>
                <br><br>
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
                                    class="wps-faq-question wps-collapse-handler"><?php echo __('What this plugin can do and how does it work?', 'wpopt') ?>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p>
                                    <b><?php echo __('This plugin is privacy oriented: every data stay on your server, is not necessary to send data to other servers.'); ?></b>
                                </p>
                                <span><?php echo __('WPOPT has been designed to improve the performance of your site, covering many aspects (if actived):'); ?></span>
                                <ul class="wps-list">
                                    <li><?php _e("Server enhancements: from basic .htaccess rules media compression (gzip, brotli)."); ?></li>
                                    <li><?php _e("Cron enhancements: can reduce the WordPres cron execution to custom intervals."); ?></li>
                                    <li><?php _e("Database enhancements: from query caching to session storage."); ?></li>
                                    <li><?php _e("Security enhancements: from WordPress api to HTTP requests."); ?></li>
                                    <li><?php _e("Browser caching system."); ?></li>
                                    <li><?php _e("Server caching: supporting query caching, static pages caching and database caching."); ?></li>
                                    <li><?php _e("Media (CSS, JavaScript, HTML) minification."); ?></li>
                                    <li><?php _e("Local image compression and resizing, with custom specs."); ?></li>
                                    <li><?php _e("Some most requested WordPress customizations."); ?></li>
                                    <li><?php _e("WordPress and plugins updates blocker."); ?></li>
                                </ul>
                                <p><?php
                                    echo __('This plugin has been developed in modules so that you can activate only essential ones based on your necessity to reduce the overload of the plugin itself.'); ?></p>
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
                                <p><?php echo sprintf(__('Any module option is configurable in <a href="%s">Modules Options panel</a>.', 'wpopt'), admin_url('admin.php?page=wpopt-modules-settings#media')); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wps-collapse-handler"><?php echo __('How media optimizer works?', 'wpopt') ?>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php echo __('Media optimizer works in two different ways:', 'wpopt'); ?></p>
                                <ul class="wps-list">
                                    <li><?php echo sprintf(__("By a scheduled event it's able to collect and optimize any media uploaded daily. <a href='%s'>Here</a> you can configure all schedule related settings.", 'wpopt'), admin_url('admin.php?page=wpopt-settings#settings-cron')); ?></li>
                                    <li><?php _e("By a specific path scanner, Media optimizer will run a background activity to optimize all images present in the input path."); ?></li>
                                    <li><?php _e("By a whole database scanner, Media optimizer will run a background activity to check all images saved in your WordPress library optimizing each image and every thumbnail associated."); ?></li>
                                </ul>
                                <p><?php echo sprintf(__('Any image optimization will be run following your settings set <a href="%s">Here</a>.', 'wpopt'), admin_url('admin.php?page=wpopt-modules-settings#settings-media')); ?></p>
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
        ?>
        <aside class="wps wpopt-sidebar">
            <section class="wps-box">
                <div class="wps-donation-wrap">
                    <div class="wps-donation-title"><?php _e('Support this project, buy me a coffee.', 'wpopt'); ?></div>
                    <p class="wpopt-muted"><?php _e('Your support helps maintain and improve WP Optimizer.', 'wpopt'); ?></p>
                    <a href="https://www.paypal.com/donate?business=dev.sh1zen%40outlook.it&item_name=Thank+you+in+advanced+for+the+kind+donations.+You+will+sustain+me+developing+WP-Optimizer.&currency_code=EUR"
                       target="_blank">
                        <img src="https://www.paypalobjects.com/en_US/IT/i/btn/btn_donateCC_LG.gif"
                             title="PayPal - The safer, easier way to pay online!" alt="Donate with PayPal button"/>
                    </a>
                </div>
            </section>
            <section class="wps-box">
                <h3><?php _e('Want to support in other ways?', 'wpopt'); ?></h3>
                <ul class="wps wpopt-link-list">
                    <li>
                        <a href="https://translate.wordpress.org/projects/wp-plugins/wp-optimizer/"><?php _e('Help me translating', 'wpopt'); ?></a>
                    </li>
                    <li>
                        <a href="https://wordpress.org/support/plugin/wp-optimizer/reviews/?filter=5"><?php _e('Leave a review', 'wpopt'); ?></a>
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
}

