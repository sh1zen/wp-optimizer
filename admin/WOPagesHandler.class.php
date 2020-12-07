<?php

/**
 * Creates the menu page for the plugin.
 *
 * Provides the functionality necessary for rendering the page corresponding
 * to the menu with which this page is associated.
 */
class WOPagesHandler
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
            'manage_options',
            'wp-optimizer',
            array($this, 'render_main'),
            'dashicons-admin-site'
        );

        /**
         * Modules - sub pages
         */
        foreach (WOModuleHandler::getInstance()->get_modules(array('scopes' => 'admin-page')) as $module) {

            add_submenu_page('wp-optimizer', $module['name'], $module['name'], 'manage_options', $module['slug'], array($this, 'render_module'));
        }

        /**
         * Modules options page
         */
        add_submenu_page('wp-optimizer', __('Modules Options', 'wpopt'), __('Modules Options', 'wpopt'), 'manage_options', 'wpopt-modules-settings', array($this, 'render_modules_settings'));

        /**
         * Plugin core settings
         */
        add_submenu_page('wp-optimizer', __('Settings', 'wpopt'), __('Settings', 'wpopt'), 'manage_options', 'wpopt-settings', array($this, 'render_core_settings'));
    }

    public function render_modules_settings()
    {
        global $wo_meter;

        $this->enqueue_scripts();

        $wo_meter->lap('Modules settings pre render');

        WOSettings::getInstance()->render_modules_settings();

        $wo_meter->lap('Modules settings rendered');

        if (WPOPT_DEBUG)
            echo $wo_meter->get_time() . ' - ' . $wo_meter->get_memory(true);
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

        WOSettings::getInstance()->render_core_settings();

        $wo_meter->lap('Core settings rendered');

        if (WPOPT_DEBUG)
            echo $wo_meter->get_time() . ' - ' . $wo_meter->get_memory(true);
    }

    public function render_module()
    {
        global $wo_meter;

        $module_slug = sanitize_text_field($_GET['page']);

        $object = WOModuleHandler::get_module_instance($module_slug);

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
        $assets_url = WO::getInstance()->plugin_base_url;

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

                    $data = WOCron::getInstance()->exec_cron();
                    break;

                case 'for-images':

                    $performer = WOPerformer::getInstance();

                    /**
                     * each function will use realpath to ensure path consistency
                     */
                    $rel_path = sanitize_text_field($_POST['wp-dir']);

                    if (isset($_POST['opti-all-images'])) {

                        $data = $performer->optimize_images($rel_path);
                    }
                    elseif (isset($_POST['clear-orphimgs'])) {

                        $data = $performer->clear_orphaned_images($rel_path);
                    }
                    break;
            }
        }

        settings_errors();
        ?>
        <section class="wpopt-home wpopt-wrap">
            <section class="wpopt">
                <?php

                if (!empty($data))
                    $this->output_results($data);

                ?>
                <block class="wpopt">
                    <h1>WP Optimizer Dashboard</h1>
                    <p><strong><?php _e('Optimize your WordPress site in few and easy steps.', 'wpopt'); ?></strong></p>
                </block>
                <block class="wpopt">
                    <h2><?php _e('Specify a path in wp-content where the optimization will run', 'wpopt'); ?></h2>
                    <pre>(<?php _e('is better to use bottom level paths due to high cpu usage', 'wpopt'); ?>)</pre>
                    <form method="POST">
                        <?php wp_nonce_field('wpopt-nonce'); ?>
                        <input name="wp-dir" type="text"
                               value="<?php echo date("Y", strtotime('last month')) . '/' . date('m', strtotime('last month')); ?>">
                        <input type="hidden" name="wpopt-action" value="for-images">
                        <input name="clear-orphimgs" type="submit" value="Clear Orphaned images"
                               class="button button-primary button-large">
                        <input name="opti-all-images" type="submit" value="Optimize All Images"
                               class="button button-primary button-large">
                    </form>
                </block>
                <block class="wpopt">
                    <form method="POST">
                        <?php wp_nonce_field('wpopt-nonce'); ?>
                        <input type="hidden" name="wpopt-action" value="wpopt-do-cron">
                        <input name="submit" type="submit" value="<?php _e('Auto optimize now', 'wpopt') ?>"
                               class="button button-primary button-large">
                    </form>
                </block>
                <block class="wpopt">
                    <h2>Stats:</h2>
                    <p>
                        <?php
                        $wo_meter->lap();
                        echo '<div>' . __('WordPress memory used', 'wpopt') . ': ' . wpopt_bytes2size(memory_get_peak_usage()) . '</div><br>';
                        echo '<div>' . __('Wordpress boot time', 'wpopt') . ': ' . number_format_i18n($wo_meter->get_time(), 4) . ' s</div><br>';
                        ?>
                    </p>
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