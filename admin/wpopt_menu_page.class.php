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

    private $option_name = 'wp-opt';

    /**
     * Holds the values to be used in the fields callbacks
     */
    private $options = array();

    public function __construct()
    {
        $this->options = get_option($this->option_name, array());
    }

    /**
     * This function renders the contents of the page associated with the menu
     * that invokes the render method. In the context of this plugin, this is the
     * menu class.
     */
    public function render_main()
    {
        if (isset($_POST['clear-db'])) {
            if (!check_admin_referer('wpopt-nonce', md5(date("d/m/Y"))))
                return;
            $data = wpopt_clear_database();
        }
        elseif (isset($_POST['opti-do-cron'])) {
            if (!check_admin_referer('wpopt-nonce', md5(date("d/m/Y"))))
                return;
            $data = wpopt_do_cron(get_option('wp-opt'));
        }
        elseif (isset($_POST['opti-all-images'])) {
            if (!check_admin_referer('wpopt-nonce', md5(date("d/m/Y"))))
                return;
            $data = wpopt_optimize_images($_POST['wp-dir']);
        }
        elseif (isset($_POST['clear-orphimgs'])) {
            if (!check_admin_referer('wpopt-nonce', md5(date("d/m/Y"))))
                return;
            $data = wpopt_clear_orphaned_images($_POST['wp-dir']);
        }
        settings_errors();
        ?>
        <div class="wrap">
            <br class="clearfix">
            <style>
                .dn-wrap{margin:auto;display:block;max-width:300px;padding:20px;align-content:center;text-align:center;background:#5a5a5a;color:#fff;-webkit-border-radius:3px;-moz-border-radius:3px;border-radius:3px;-webkit-box-shadow:0 0 8px 2px rgba(0,0,0,.75);-moz-box-shadow:0 0 8px 2px rgba(0,0,0,.75);box-shadow:0 0 8px 2px rgba(0,0,0,.75)}.dn-title{font-size:16px;font-family:sans-serif;font-weight:600}.dn-btc{display:flex;flex:1 1 0}.dn-name{font-weight:600}.dn-value{margin:0 0 0 20px;font-weight:600}.dn-hr{width:100%;background:#0a0a0a;height:1px;margin:22px 0 12px 0}
            </style>
            <div class="dn-wrap">
                <div class="dn-title">Support this project, buy me a coffee.</div>
                <br>
                <form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_top">
                    <input type="hidden" name="cmd" value="_s-xclick"/>
                    <input type="hidden" name="hosted_button_id" value="X5ASVPBFLR2JG"/>
                    <input type="image" src="https://www.paypalobjects.com/en_US/IT/i/btn/btn_donateCC_LG.gif" border="0"
                           name="submit" title="PayPal - The safer, easier way to pay online!"
                           alt="Donate with PayPal button"/>
                    <img alt="" border="0" src="https://www.paypal.com/en_IT/i/scr/pixel.gif" width="1" height="1"/>
                </form>
                <div class="dn-hr"></div>
                <div class="dn-btc"><div class="dn-name">BTC:</div><div class="dn-value">3QE5CyfTxb5kufKxWtx4QEw4qwQyr9J5eo</div></div>
            </div>
            <hr>
            <br class="clearfix">
            <h1>Optimize your wordpress</h1>
            <br class="clearfix"><br class="clearfix">
            <form method="POST">
                <?php wp_nonce_field('wpopt-nonce', md5(date("d/m/Y"))); ?>
                <input name="clear-db" type="submit" value="Clear Database"
                       class="button button-primary button-large">
            </form>
            <br class="clearfix"><br class="clearfix">
            <h2>Select a path in wp-content where the optimization will run</h2>
            <pre>(is better to use bottom level paths due to high cpu usage)</pre>
            <form method="POST">
                <?php wp_nonce_field('wpopt-nonce', md5(date("d/m/Y"))); ?>
                <input name="wp-dir" type="text"
                       value="<?php echo date("Y", strtotime('last month')) . '/' . date('m', strtotime('last month')); ?>">
                <input name="clear-orphimgs" type="submit" value="Clear Orphaned images"
                       class="button button-primary button-large">
                <input name="opti-all-images" type="submit" value="Optimize All Images"
                       class="button button-primary button-large">
            </form>
            <br class="clearfix"><br class="clearfix">
            <form method="POST">
                <?php wp_nonce_field('wpopt-nonce', md5(date("d/m/Y"))); ?>
                <input name="opti-do-cron" type="submit" value="Exec Cron-job now"
                       class="button button-primary button-large">
            </form>
            <br class="clearfix"> <br class="clearfix">
            <hr class="xi-hr">
            <h1>CronJob setup:</h1>
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
                            <input type="time" name="<?php echo $this->option_name ?>[clear-time]" id="clear-time"
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
                            <input type="checkbox" name="<?php echo $this->option_name ?>[database]" id="database"
                                   value="1" <?php checked(1, $this->options['database'], true); ?> />
                        </td>
                    </tr>
                    <br>
                    <tr>
                        <td>
                            <div class="xi-text">Save optimization report:</div>
                        </td>
                        <td>
                            <input type="checkbox" name="<?php echo $this->option_name ?>[save_report]" id="save_report"
                                   value="1" <?php checked(1, $this->options['save_report'], true); ?> />
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>"/>
                </p>
            </form>
            <hr class="xi-hr">
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
        </div>
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