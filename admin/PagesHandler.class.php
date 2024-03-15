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

        //add_action('admin_notices', [$this, 'notice'], 10, 0);
    }

    public function notice()
    {
        global $pagenow;

        $user_id = wps_utils()->get_cuID();

        if (isset($_GET['wpopt-dismiss-notice'])) {

            wps('wpopt')->options->add($user_id, 'dismissed', true, 'admin-notice', MONTH_IN_SECONDS);
        }
        elseif ($pagenow == 'index.php' and !wps('wpopt')->options->get($user_id, 'dismissed', 'admin-notice', false)) {

            ?>
            <div class="notice notice-info is-dismissible">
                <h3>Help me to build <a href="<?php echo admin_url('admin.php?page=wp-optimizer'); ?>">WP-OPTIMIZER</a>.
                </h3>
                <p><?php echo sprintf(__("Buy me a coffe <a href='%s'>here</a> or leave a review <a href='%s'>here</a>.", 'wpopt'), "https://www.paypal.com/donate?business=dev.sh1zen%40outlook.it&item_name=Thank+you+in+advanced+for+the+kind+donations.+You+will+sustain+me+developing+WP-Optimizer.&currency_code=EUR", "https://wordpress.org/support/plugin/wp-optimizer/reviews/?filter=5"); ?></p>
                <a href="?wpopt-dismiss-notice"><?php echo __('Dismiss', 'wpopt') ?></a>
            </div>
            <?php
        }
    }

    public function add_plugin_pages()
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

            add_submenu_page('wp-optimizer', 'WPOPT ' . $module['name'], $module['name'], 'customize', $module['slug'], array($this, 'render_module'));
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
    }

    public function render_modules_settings()
    {
        $this->enqueue_scripts();

        if (WPOPT_DEBUG) {
            wps_utils()->meter->lap('Modules settings pre render');
        }

        wps('wpopt')->settings->render_modules_settings();

        if (WPOPT_DEBUG) {

            wps_utils()->meter->lap('Modules settings rendered');

            echo wps_utils()->meter->get_time() . ' - ' . wps_utils()->meter->get_memory();
        }
    }

    private function enqueue_scripts()
    {
        wp_enqueue_style('wpopt_css');
        wp_enqueue_script('vendor-wps-js');
    }

    public function render_core_settings()
    {
        $this->enqueue_scripts();

        wps('wpopt')->settings->render_core_settings();

        if (WPOPT_DEBUG) {
            echo wps_utils()->meter->get_time() . ' - ' . wps_utils()->meter->get_memory();
        }
    }

    public function render_module()
    {
        $module_slug = sanitize_text_field($_GET['page']);

        $object = wps('wpopt')->moduleHandler->get_module_instance($module_slug);

        if (is_null($object)) {
            return;
        }

        $this->enqueue_scripts();

        $object->render_admin_page();

        if (WPOPT_DEBUG) {
            wps_utils()->meter->lap($module_slug);
            echo wps_utils()->meter->get_time() . ' - ' . wps_utils()->meter->get_memory(true, true);
        }
    }

    public function register_assets()
    {
        $assets_url = wpopt()->plugin_base_url;

        $min = wps_utils()->online ? '.min' : '';

        wp_register_style("wpopt_css", "{$assets_url}assets/style{$min}.css", ['vendor-wps-css']);

        wps_localize([
            'text_na'            => __('N/A', 'wpopt'),
            'saved'              => __('Settings Saved', 'wpopt'),
            'error'              => __('Request fail', 'wpopt'),
            'success'            => __('Request succeed', 'wpopt'),
            'running'            => __('Running', 'wpopt'),
            'text_close_warning' => __('WP Optimizer is running an action. If you leave now, it may not be completed.', 'wpopt'),
        ]);
    }

    public function render_faqs()
    {
        $this->enqueue_scripts();
        ?>
        <section class="wps wps-wrap">
            <block class="wps">
                <section class='wps-header'><h1>FAQ</h1></section>
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
    public function render_main()
    {
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
        ?>
        <section class="wps-wrap-flex wps-wrap wps-home">
            <section class="wps">
                <?php

                if (!empty($data)) {
                    $this->output_results($data);
                }

                ?>
                <block class="wps">
                    <block class="wps-header">
                        <h1>WP Optimizer Dashboard</h1>
                    </block>
                    <h2><?php _e('Modules:', 'wpopt'); ?></h2>
                    <p>
                        <?php
                        echo '<div><b>' . __('This plugin uses modules, so you can disable non necessary one to not weigh down WordPress.', 'wpopt') . '</b></div><br>';
                        $modules = wps('wpopt')->moduleHandler->get_modules(array('excepts' => array('cron', 'modules_handler', 'settings')));
                        $modules = array_column($modules, 'name');
                        $modules = str_replace(' ', '_', $modules);
                        echo '<div><b>' . __('Currently active:', 'wpopt') . '</b> <code>' . implode('</code>, <code>', $modules) . '</code></div><br>';
                        echo '<div><b>' . sprintf(__('Manage modules: <a href="%s">here</a>.', 'wpopt'), admin_url('admin.php?page=wpopt-settings#settings-modules_handler')) . '</b></div><br>';
                        echo '<div><b>' . sprintf(__('Configure them: <a href="%s">here</a>.', 'wpopt'), admin_url('admin.php?page=wpopt-modules-settings')) . '</b></div><br>';
                        ?>
                    </p>
                    <h2><?php _e('WordPress performances:', 'wpopt'); ?></h2>
                    <p>
                        <?php
                        echo '<div>' . sprintf(__('Server load: %s %%', 'wpopt'), UtilEnv::get_server_load(false)) . '</div><br>';
                        echo '<div>' . sprintf(__('WordPress used memory: %s', 'wpopt'), wps_utils()->meter->get_memory(true, true)) . '</div><br>';
                        echo '<div>' . sprintf(__('Wordpress execution time: %s s', 'wpopt'), wps_utils()->meter->get_time('wp_start', 'now', 3)) . '</div><br>';
                        ?>
                    </p>
                </block>
                <?php if (!defined('WP_PERSISTENT_CACHE')): ?>
                    <block class="wps">
                        <h2><?php _e('Persistent cache:', 'wpopt'); ?></h2>
                        <p><?php _e('WP-Optimizer supports <b>Redis</b> and <b>Memcached</b> systems.', 'wpopt'); ?></p>
                        <p><?php _e('To activate persistent cache for your site copy this <b>define(\'WP_PERSISTENT_CACHE\', true);</b> in wp-config.php', 'wpopt'); ?></p>
                    </block>
                <?php endif; ?>
                <block class="wps">
                    <h2><?php _e('Fast actions:', 'wpopt'); ?></h2>
                    <h4><?php _e('Here is possible to run default optimizations as a separate cron job.', 'wpopt'); ?></h4>
                    <?php if (wps('wpopt')->settings->get('cron.running', false)): ?>
                        <h4><strong><?php _e('A cron job is running.', 'wpopt'); ?></strong></h4>
                    <?php endif; ?>
                    <form method="POST">
                        <?php wp_nonce_field('wpopt-nonce'); ?>
                        <input name="wpopt-cron-run" type="submit"
                               value="<?php _e('Auto optimize now', 'wpopt') ?>" <?php echo wps('wpopt')->settings->get('cron.running', false) ? "disabled" : "" ?>
                               class="button button-primary button-large">
                        <?php if (wps('wpopt')->settings->get('cron.running', false)): ?>
                            <input name="wpopt-cron-reset" type="submit"
                                   value="<?php _e('Reset cron status', 'wpopt') ?>"
                                   class="button button-primary button-large">
                        <?php endif; ?>
                    </form>
                </block>
            </section>
            <aside class="wps">
                <section class="wps-box">
                    <div class="wps-donation-wrap">
                        <div
                            class="wps-donation-title"><?php _e('Support this project, buy me a coffee.', 'wpopt'); ?></div>
                        <br>
                        <a href="https://www.paypal.com/donate?business=dev.sh1zen%40outlook.it&item_name=Thank+you+in+advanced+for+the+kind+donations.+You+will+sustain+me+developing+WP-Optimizer.&currency_code=EUR"
                           target="_blank">
                            <img src="https://www.paypalobjects.com/en_US/IT/i/btn/btn_donateCC_LG.gif"
                                 title="PayPal - The safer, easier way to pay online!" alt="Donate with PayPal button"/>
                        </a>
                        <div class="wps-donation-hr"></div>
                        <div class="dn-btc">
                            <div class="wps-donation-name">BTC:</div>
                            <p class="wps-donation-value">3QE5CyfTxb5kufKxWtx4QEw4qwQyr9J5eo</p>
                        </div>
                    </div>
                </section>
                <section class="wps-box">
                    <h3><?php _e('Want to support in other ways?', 'wpopt'); ?></h3>
                    <ul class="wps">
                        <li>
                            <a href="https://translate.wordpress.org/projects/wp-plugins/wp-optimizer/"><?php _e('Help me translating', 'wpopt'); ?></a>
                        </li>
                        <li>
                            <a href="https://wordpress.org/support/plugin/wp-optimizer/reviews/?filter=5"><?php _e('Leave a review', 'wpopt'); ?></a>
                        </li>
                    </ul>
                    <h3>WP-Optimizer:</h3>
                    <ul class="wps">
                        <li>
                            <a href="https://github.com/sh1zen/wp-optimizer/"><?php _e('Source code', 'wpopt'); ?></a>
                        </li>
                        <li>
                            <a href="https://sh1zen.github.io/"><?php _e('About me', 'wpopt'); ?></a>
                        </li>
                    </ul>
                </section>
            </aside>
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
}