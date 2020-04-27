<?php

/**
 * Creates the menu page for the plugin.
 *
 * Provides the functionality necessary for rendering the page corresponding
 * to the menu with which this page is associated.
 *
 */
class wpoptPagesHandler
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
        foreach (wpoptModuleHandler::getInstance()->get_modules(array('methods' => 'render')) as $module) {

            add_submenu_page('wp-optimizer', $module['page_title'], $module['menu_title'], 'manage_options', $module['slug'], array($this, 'render_module'));

        }

        /**
         * Last: the setting page
         */
        add_submenu_page('wp-optimizer', __('Settings', 'wpopt'), __('Settings', 'wpopt'), 'manage_options', 'wpopt-settings', array($this, 'render_settings'));
    }


    public function render_settings()
    {
        $this->enqueue_scripts();

        wpoptSettings::getInstance()->render_page();
    }

    private function enqueue_scripts()
    {
        wp_enqueue_style('wpopt_css');

        wp_enqueue_script('wpopt_js');
    }

    public function render_module()
    {
        $moduleHandler = wpoptModuleHandler::getInstance();

        /**
         * Another check: Just to be sure
         */
        if (!$moduleHandler->module_has_method($_GET['page'], "render"))
            return;

        $object = $moduleHandler->load_module($_GET['page']);

        if ($object == null)
            return;

        if (method_exists($object, "enqueue_scripts")) {
            $object->enqueue_scripts();
        }
        else {
            $this->enqueue_scripts();
        }

        $object->render();
    }

    public function register_assets()
    {
        $assets_url = plugin_dir_url(WPOPT_FILE);

        wp_register_style('wpopt_css', $assets_url . 'assets/style.css');

        wp_register_script('wpopt_js', plugin_dir_url(WPOPT_FILE) . 'assets/settings.js', array('jquery'));

        wp_localize_script('wpopt_js', 'WPOPT', array(
            'strings' => array(
                'saved' => __('Settings Saved', 'wpopt'),
                'error' => __('Error', 'wpopt')
            ),
            'api'     => array(
                'url'   => esc_url_raw(rest_url('apex-api/v1/settings')),
                'nonce' => wp_create_nonce('wp_rest')
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
        global $wpopt_timer;

        $this->enqueue_scripts();

        $performer = wpoptPerformer::getInstance();

        $data = array();

        if (isset($_POST['wpopt-action'])) {

            if (!check_admin_referer('wpopt-nonce', md5(date("d/m/Y"))))
                return;

            switch ($_POST['wpopt-action']) {

                case 'clear-db':
                    $data = $performer->clear_database_full();
                    break;

                case 'wpopt-do-cron':

                    $data = wpopt::getInstance()->cron();

                    break;

                case 'for-images':

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
        <section class="wpopt-wrap">
            <section class="wpopt">
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

            <section class="wpopt">
                <h1><?php _e('WP Optimizer', 'wpopt'); ?></h1>
                <?php

                if (!empty($data))
                    $this->output_results($data);

                ?>
                <block>
                    <form method="POST">
                        <?php wp_nonce_field('wpopt-nonce', md5(date("d/m/Y"))); ?>
                        <input type="hidden" name="wpopt-action" value="clear-db">
                        <input name="submit" type="submit" value="Clear Database"
                               class="button button-primary button-large">
                    </form>
                </block>
                <block>
                    <h2><?php _e('Specify a path in wp-content where the optimization will run', 'wpopt'); ?></h2>
                    <pre>(<?php _e('is better to use bottom level paths due to high cpu usage', 'wpopt'); ?>)</pre>
                    <form method="POST">
                        <?php wp_nonce_field('wpopt-nonce', md5(date("d/m/Y"))); ?>
                        <input name="wp-dir" type="text"
                               value="<?php echo date("Y", strtotime('last month')) . '/' . date('m', strtotime('last month')); ?>">
                        <input type="hidden" name="wpopt-action" value="for-images">
                        <input name="clear-orphimgs" type="submit" value="Clear Orphaned images"
                               class="button button-primary button-large">
                        <input name="opti-all-images" type="submit" value="Optimize All Images"
                               class="button button-primary button-large">
                    </form>
                </block>
                <block>
                    <form method="POST">
                        <?php wp_nonce_field('wpopt-nonce', md5(date("d/m/Y"))); ?>
                        <input type="hidden" name="wpopt-action" value="wpopt-do-cron">
                        <input name="submit" type="submit" value="<?php _e('Auto optimize now', 'wpopt') ?>"
                               class="button button-primary button-large">
                    </form>
                </block>
                <block>
                    <h2>Stats:</h2>
                    <p>
                        <?php
                        $wpopt_timer->stop();
                        echo '<div>' . __('Memory used', 'wpopt') . ': ' . wpopt_convert_size(memory_get_peak_usage()) . '</div><br>';
                        echo '<div>' . __('Elapsed time', 'wpopt') . ': ' . number_format_i18n($wpopt_timer->get_time(), 4) . ' s</div><br>';
                        ?>
                    </p>
                </block>
            </section>
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