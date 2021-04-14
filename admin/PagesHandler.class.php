<?php

namespace WPOptimizer\core;

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
        add_action('admin_enqueue_scripts', array($this, 'register_assets'));
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
        foreach (ModuleHandler::getInstance()->get_modules(array('scopes' => 'admin-page')) as $module) {

            add_submenu_page('wp-optimizer', 'WPOPT ' . $module['name'], $module['name'], 'customize', $module['slug'], array($this, 'render_module'));
        }

        /**
         * Modules options page
         */
        add_submenu_page('wp-optimizer', __('WPOPT Modules Options', 'wpopt'), __('Modules Options', 'wpopt'), 'manage_options', 'wpopt-modules-settings', array($this, 'render_modules_settings'));

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
        global $wo_meter;

        $this->enqueue_scripts();

        $wo_meter->lap('Modules settings pre render');

        Settings::getInstance()->render_modules_settings();

        $wo_meter->lap('Modules settings rendered');

        if (WPOPT_DEBUG)
            echo $wo_meter->get_time() . ' - ' . $wo_meter->get_memory();
    }

    private function enqueue_scripts()
    {
        wp_enqueue_style('wpopt_css');

        wp_enqueue_script('wpopt_js');
    }

    public function render_core_settings()
    {
        global $wo_meter;

        $this->enqueue_scripts();

        $wo_meter->lap('Core settings pre render');

        Settings::render_core_settings();

        $wo_meter->lap('Core settings rendered');

        if (WPOPT_DEBUG)
            echo $wo_meter->get_time() . ' - ' . $wo_meter->get_memory();
    }

    public function render_module()
    {
        global $wo_meter;

        $module_slug = sanitize_text_field($_GET['page']);

        $object = ModuleHandler::get_module_instance($module_slug);

        if (is_null($object))
            return;

        $this->enqueue_scripts();

        $object->render_admin_page();

        $wo_meter->lap($module_slug);

        if (WPOPT_DEBUG)
            echo $wo_meter->get_time() . ' - ' . $wo_meter->get_memory(true, true);
    }

    public function register_assets()
    {
        $assets_url = PluginInit::getInstance()->plugin_base_url;

        wp_register_style('wpopt_css', $assets_url . 'assets/style.css');

        wp_register_script('wpopt_js', $assets_url . 'assets/settings.js', array('jquery'));

        wp_localize_script('wpopt_js', 'WPOPT', array(
            'strings' => array(
                'text_na'            => __('N/A', 'wpopt'),
                'saved'              => __('Settings Saved', 'wpopt'),
                'error'              => __('Request fail', 'wpopt'),
                'success'            => __('Request succeed', 'wpopt'),
                'running'            => __('Running', 'wpopt'),
                'text_close_warning' => __('WP Optimizer is running an action. If you leave now, it will not be completed.', 'wpopt'),
            )
        ));
    }

    public function render_faqs()
    {
        $this->enqueue_scripts();
        ?>
        <section class="wpopt wpopt-wrap">
            <block class="wpopt">
                <section class='wpopt-header'><h1>FAQ</h1></section>
                <br>
                <div class="wpopt-faq-list">
                    <div class="wpopt-faq-item">
                        <div class="wpopt-faq-question-wrapper ">
                            <div class="wpopt-faq-question wpopt-collapse-handler"><?= __('Where can I configure optimization parameters?', 'wpopt') ?>
                                <icon class="wpopt-collapse-icon">+</icon>
                            </div>
                            <div class="wpopt-faq-answer wpopt-collapse">
                                <p><?= sprintf(__('Any module option is configurable in <a href="%s">Modules Options panel</a>.', 'wpopt'), admin_url('admin.php?page=wpopt-modules-settings#media')); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="wpopt-faq-item">
                        <div class="wpopt-faq-question-wrapper ">
                            <div class="wpopt-faq-question wpopt-collapse-handler"><?= __('How optimization works?', 'wpopt') ?>
                                <icon class="wpopt-collapse-icon">+</icon>
                            </div>
                            <div class="wpopt-faq-answer wpopt-collapse">
                                <p><?= __('Optimization when launched from here will run in background for any found on the specified path on this server.', 'wpopt'); ?></p>
                                <p><?= __('Optimization when launched from here will run in background for any found on the specified path on this server.', 'wpopt'); ?></p>
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
        global $wo_meter;

        $this->enqueue_scripts();

        $data = array();

        if (isset($_POST['wpopt-action'])) {

            if (!wpopt_verify_nonce('wpopt-nonce'))
                return;

            switch ($_POST['wpopt-action']) {

                case 'wpopt-do-cron':
                    Cron::run_event('wpopt-cron');
                    break;
            }
        }

        settings_errors();
        ?>
        <section class="wpopt-wrap-flex wpopt-wrap wpopt-home">
            <section class="wpopt">
                <?php

                if (!empty($data))
                    $this->output_results($data);

                ?>
                <block class="wpopt-header">
                    <h1>WP Optimizer Dashboard</h1>
                    <h3><strong><?php _e('Optimize your WordPress site in few and easy steps.', 'wpopt'); ?></strong></h3>
                </block>
                <block class="wpopt">
                    <h2><?php _e('Modules:', 'wpopt'); ?></h2>
                    <p>
                        <?php
                        echo '<div><b>' . __('This plugin uses modules, so you can disable non necessary one to not weigh down WordPress.', 'wpopt') . '</b></div><br>';
                        $modules = ModuleHandler::getInstance()->get_modules(array('excepts' => array('cron', 'modules_handler', 'settings')));
                        $modules = array_column($modules, 'name');
                        $modules = str_replace(' ', '_', $modules);
                        echo '<div><b>' . __('Currently active:', 'wpopt') . '</b> <code>' . implode(', ', $modules) . '</code></div><br>';
                        echo '<div><b>' . sprintf(__('Manage modules: <a href="%s">here</a>.', 'wpopt'), admin_url('admin.php?page=wpopt-settings#settings-modules_handler')) . '</b></div><br>';
                        echo '<div><b>' . sprintf(__('Configure them: <a href="%s">here</a>.', 'wpopt'), admin_url('admin.php?page=wpopt-modules-settings')) . '</b></div><br>';
                        ?>
                    </p>
                    <h2><?php _e('Stats:', 'wpopt'); ?></h2>
                    <p>
                        <?php
                        $wo_meter->lap();
                        echo '<div>' . sprintf(__('WordPress used memory: %s', 'wpopt'), size_format(memory_get_peak_usage())) . '</div><br>';
                        echo '<div>' . sprintf(__('Wordpress boot time: %s s', 'wpopt'), number_format_i18n(microtime(true) - WP_START_TIMESTAMP, 4)) . '</div><br>';
                        ?>
                    </p>
                </block>
                <block class="wpopt">
                    <h2><?php _e('Fast actions:', 'wpopt'); ?></h2>
                    <form method="POST">
                        <?php wp_nonce_field('wpopt-nonce'); ?>
                        <input type="hidden" name="wpopt-action" value="wpopt-do-cron">
                        <input name="submit" type="submit" value="<?php _e('Auto optimize now', 'wpopt') ?>"
                               class="button button-primary button-large">
                    </form>
                </block>
            </section>
            <aside class="wpopt">
                <section class="wpopt-box">
                    <div class="dn-wrap">
                        <div class="dn-title"><?php _e('Support this project, buy me a coffee.', 'wpopt'); ?></div>
                        <br>
                        <a href="https://www.paypal.me/sh1zen">
                            <img src="https://www.paypalobjects.com/en_US/IT/i/btn/btn_donateCC_LG.gif"
                                 title="PayPal - The safer, easier way to pay online!" alt="Donate with PayPal button"/>
                        </a>
                        <div class="dn-hr"></div>
                        <div class="dn-btc">
                            <div class="dn-name">BTC:</div>
                            <p class="dn-value">3QE5CyfTxb5kufKxWtx4QEw4qwQyr9J5eo</p>
                        </div>
                    </div>
                </section>
                <section class="wpopt-box">
                    <h3><?php _e('Want to support in other ways?', 'wpopt'); ?></h3>
                    <ul class="wpopt">
                        <li>
                            <a href="https://translate.wordpress.org/projects/wp-plugins/wp-optimizer/"><?php _e('Help me translating', 'wpopt'); ?></a>
                        </li>
                        <li>
                            <a href="https://wordpress.org/support/plugin/wp-optimizer/reviews/?filter=5"><?php _e('Leave a review', 'wpopt'); ?></a>
                        </li>
                    </ul>
                    <h3>WP-Optimizer:</h3>
                    <ul class="wpopt">
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