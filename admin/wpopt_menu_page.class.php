<?php

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

    public function __construct($option_name = 'wpopt')
    {
        $this->option_name = $option_name;

        $this->options = get_option($this->option_name, array());

        add_action('admin_menu', array($this, 'add_plugin_pages'));
    }

    public function add_plugin_pages()
    {
        /**
         * Main page
         */
        add_menu_page(
            'WP Optimizer',
            'WP Optimizer',
            'manage_options',
            'wp-optimizer',
            array($this, 'render_main'),
            'dashicons-admin-site'
        );

        /**
         * Sub pages
         */
        foreach (wpopt_modules::getInstance()->modules as $class => $module) {
            add_submenu_page('wp-optimizer', $module['page_title'], $module['menu_title'], 'manage_options', $module['slug'], array($this, 'render_module'));
        }
    }


    public function render_module()
    {
        $page = $_GET['page'];

        $class = str_replace('-', '_', $page);

        $file_name = str_replace('wpopt-', '', $page);

        if (!class_exists($class)) {
            if (!file_exists(WPOPT_ABSPATH . "/modules/{$file_name}.class.php"))
                return;

            require WPOPT_ABSPATH . "/modules/{$file_name}.class.php";
        }

        $object = new $class();

        if (method_exists($object, "enqueue_scripts")) {
            $object->enqueue_scripts();
        }
        else {
            $this->enqueue_scripts();
        }

        if (method_exists($object, "render")) {
            $object->render();
        }
    }

    private function enqueue_scripts()
    {
        wp_enqueue_style('wpopt_css', plugin_dir_url(WPOPT_FILE) . "assets/style.css", array(), '1.0.0');
    }

    /**
     * This function renders the contents of the page associated with the menu
     * that invokes the render method. In the context of this plugin, this is the
     * menu class.
     */
    public function render_main()
    {
        $this->enqueue_scripts();

        $performer = wpopt_performer::getInstance();

        if (isset($_POST['wpopt-action'])) {

            if (!check_admin_referer('wpopt-nonce', md5(date("d/m/Y"))))
                return;

            $data = array();

            switch ($_POST['wpopt-action']) {

                case 'clear-db':
                    $data = $performer->clear_database_full();
                    break;

                case 'wpopt-do-cron':
                    $args = get_option('wpopt');
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

                        $data = $performer->optimize_images( $rel_path);
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

                <?php $this->output_results($data); ?>

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
                    <h2>Cron-Job setup:</h2>
                    <form method="POST" action="options.php">
                        <input type="hidden" name="<?php echo $this->option_name ?>[change]" value="settings">
                        <?php
                        settings_fields('wpopt');
                        do_settings_sections('wpopt');
                        ?>
                        <table>
                            <tr>
                                <td>
                                    <p class="xi-text">Auto Clear Time:</p>
                                </td>
                                <td>
                                    <label for="clear-time"></label>
                                    <input type="time" name="<?php echo $this->option_name ?>[clear-time]"
                                           id="clear-time"
                                           value="<?php echo $this->options['clear-time']; ?>">
                                </td>

                            </tr>
                            <tr>
                                <td>
                                    <p class="xi-text">Active:</p>
                                </td>
                                <td>
                                    <label for="active"></label>
                                    <input type="checkbox" name="<?php echo $this->option_name ?>[active]" id="active"
                                           value="1" <?php checked(1, $this->options['active'], true); ?> />
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <p class="xi-text">Auto optimize images ( daily uploads ) :</p>
                                </td>
                                <td>
                                    <label for="images"></label>
                                    <input type="checkbox" name="<?php echo $this->option_name ?>[images]" id="images"
                                           value="1" <?php checked(1, $this->options['images'], true); ?> />
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <p class="xi-text"> Auto optimize Database:</p>
                                </td>
                                <td>
                                    <label for="database"></label>
                                    <input type="checkbox" name="<?php echo $this->option_name ?>[database]"
                                           id="database"
                                           value="1" <?php checked(1, $this->options['database'], true); ?> />
                                </td>
                            </tr>
                            <br>
                            <tr>
                                <td>
                                    <p class="xi-text">Save optimization report:</p>
                                </td>
                                <td>
                                    <label for="save_report"></label>
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
                    <h2>Stats:</h2>
                    <p>
                        <?php
                        echo '<div>peak memory used: ' . wpopt_convert_size(memory_get_peak_usage(true)) . '</div><br>';
                        echo '<div>line memory used: ' . wpopt_convert_size(memory_get_usage(true)) . '</div><br>';
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

    private function output_option($option_name)
    {
        if (is_array($this->options[$option_name]))
            echo implode(PHP_EOL, $this->options[$option_name]);
        else
            echo $this->options[$option_name];
    }
}