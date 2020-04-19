<?php
/**
 * Creates the menu page for the plugin.
 *
 * @package Custom_Admin_Settings
 */


/**
 * Creates the menu page for the plugin.
 *
 * Provides the functionality necessary for rendering the page corresponding
 * to the menu with which this page is associated.
 *
 * @package Custom_Admin_Settings
 */
class wpopt_menu_page
{

    private $option_name;

    /**
     * Holds the values to be used in the fields callbacks
     */
    private $options;

    public function __construct($option_name = 'wp-opt')
    {
        $this->option_name = $option_name;

        $this->options = get_option($this->option_name, array());

        add_action('admin_menu', array($this, 'add_plugin_page'));

    }

    public function add_plugin_page()
    {
        add_menu_page(
            'WP Optimizer',
            'WP Optimizer',
            'manage_options',
            'wp-optimizer',
            array($this, 'render_main'),
            'dashicons-admin-site'
        );

        add_submenu_page('wp-optimizer', 'System Info', 'System Info', 'manage_options', 'wpopt-sysinfo', array($this, 'wpopt_info'));
    }

    public function wpopt_info()
    {
        $this->enqueue_scripts();

        require_once WP_OPT_PATH . '/include/wpopt-info.class.php';

        $info = new wpopt_info();
        $info->render_system_info_page();
    }

    private function enqueue_scripts()
    {
        wp_enqueue_style('wpopto_css', plugin_dir_url(WP_OPT_FILE) . "assets/style.css", array(), '1.0.0');
    }

    /**
     * This function renders the contents of the page associated with the menu
     * that invokes the render method. In the context of this plugin, this is the
     * menu class.
     */
    public function render_main()
    {
        $this->enqueue_scripts();

        if (isset($_POST['wpopt-action'])) {

            if (!check_admin_referer('wpopt-nonce', md5(date("d/m/Y"))))
                return;

            $data = array();

            switch ($_POST['wpopt-action']) {

                case 'clear-db':
                    $data = wpopt_clear_database();
                    break;

                case 'wpopt-do-cron':
                    $args = get_option('wp-opt');
                    if ($args) {
                        $data = wpopt_do_cron($args);
                    }
                    break;

                case 'for-images':

                    /**
                     * each function will use realpath to ensure path consistency
                     */
                    $rel_path = sanitize_text_field($_POST['wp-dir']);

                    if (isset($_POST['opti-all-images'])) {

                        $data = wpopt_optimize_images($rel_path);
                    }
                    elseif (isset($_POST['clear-orphimgs'])) {

                        $data = wpopt_clear_orphaned_images($rel_path);
                    }

                    break;
            }
        }

        settings_errors();
        ?>
        <section class="wpopt-wrap">
            <section class="wpopt">
                <div class="dn-wrap">
                    <div class="dn-title">Support this project, buy me a coffee.</div>
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
                <h1>Optimize your WordPress site:</h1>
                <block>
                    <form method="POST">
                        <?php wp_nonce_field('wpopt-nonce', md5(date("d/m/Y"))); ?>
                        <input type="hidden" name="wpopt-action" value="clear-db">
                        <input name="submit" type="submit" value="Clear Database"
                               class="button button-primary button-large">
                    </form>
                </block>
                <block>
                    <h2>Select a path in wp-content where the optimization will run</h2>
                    <pre>(is better to use bottom level paths due to high cpu usage)</pre>
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
                        <input name="submit" type="submit" value="Exec Cron-job now"
                               class="button button-primary button-large">
                    </form>
                </block>
                <block>
                    <h1>Cron-Job setup:</h1>
                    <form method="POST" action="options.php">
                        <input type="hidden" name="<?php echo $this->option_name ?>[change]" value="settings">
                        <?php
                        settings_fields('wp-opt');
                        do_settings_sections('wp-opt');
                        ?>
                        <table>
                            <tr>
                                <td>
                                    <div class="xi-text">Auto Clear Time:</div>
                                </td>
                                <td>
                                    <input type="time" name="<?php echo $this->option_name ?>[clear-time]"
                                           id="clear-time"
                                           value="<?php echo $this->options['clear-time']; ?>">
                                </td>

                            </tr>
                            <tr>
                                <td>
                                    <div class="xi-text">Active:</div>
                                </td>
                                <td>
                                    <input type="checkbox" name="<?php echo $this->option_name ?>[active]" id="active"
                                           value="1" <?php checked(1, $this->options['active'], true); ?> />
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="xi-text">
                                        Auto optimize images

                                        (
                                        daily uploads

                                        )
                                        :
                                    </div>
                                </td>
                                <td>
                                    <input type="checkbox" name="<?php echo $this->option_name ?>[images]"
                                           id="images"
                                           value="1" <?php checked(1, $this->options['images'], true); ?> />
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="xi-text">
                                        Auto optimize Database:
                                    </div>
                                </td>
                                <td>
                                    <input type="checkbox" name="<?php echo $this->option_name ?>[database]"
                                           id="database"
                                           value="1" <?php checked(1, $this->options['database'], true); ?> />
                                </td>
                            </tr>
                            <br>
                            <tr>
                                <td>
                                    <div class="xi-text">Save optimization report:</div>
                                </td>
                                <td>
                                    <input type="checkbox" name="<?php echo $this->option_name ?>[save_report]"
                                           id="save_report"
                                           value="1" <?php checked(1, $this->options['save_report'], true); ?> />
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>"/>
                        </p>
                    </form>
                </block>
                <block>

                    <h1>Stats:</h1>
                    <?php
                    if (isset($data)) {
                        print_r($data);
                        echo '<hr class="xi-hr">';
                    }
                    ?>
                    <p>
                        <?php
                        echo '<div>peak memory used: ' . $this->convert(memory_get_peak_usage(true)) . '</div><br>';
                        echo '<div>line memory used: ' . $this->convert(memory_get_usage(true)) . '</div><br>';
                        ?>
                    </p>
                </block>
            </section>
        </section>
        <?php
    }

    /**
     * This function renders the contents of the page associated with the menu
     * that invokes the render method. In the context of this plugin, this is the
     * menu class.
     */


    private function convert($size)
    {
        $unit = array('b', 'kb', 'mb', 'gb', 'tb', 'pb');
        return @round($size / pow(1024, ($i = floor(log($size, 1024)))), 2) . ' ' . $unit[$i];
    }

    private function output_option($option_name)
    {
        if (is_array($this->options[$option_name]))
            echo implode(PHP_EOL, $this->options[$option_name]);
        else
            echo $this->options[$option_name];
    }
}