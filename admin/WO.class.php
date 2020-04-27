<?php

if (!defined('ABSPATH'))
    exit();

/**
 * Main class
 * used to boot the plugin
 */
class WO
{
    private static $_instance;
    public $plugin_basename;
    private $menu_page;
    private $monitor;

    public function __construct()
    {
        $this->plugin_basename = plugin_basename(WPOPT_FILE);

        $this->register_actions();

        $this->load_textdomain('wpopt');

        $this->register_wpopt_actions();
    }

    private function register_actions()
    {
        // cron job
        add_action('wpopt-cron', array($this, 'cron'));

        // Listen for the activate event
        register_activation_hook(WPOPT_FILE, array($this, 'activate'));

        // Deactivation plugin
        register_deactivation_hook(WPOPT_FILE, array($this, 'deactivate'));

        add_filter("plugin_action_links_{$this->plugin_basename}", array($this, 'settings_plugin_link'), 10, 2);

        add_filter( 'plugin_row_meta', array( $this, 'donate_link' ), 10, 4 );
    }

    /**
     * Loads text domain for the plugin.
     *
     * @param $domain
     * @return bool
     * @action plugins_loaded
     *
     * @access private
     */
    private function load_textdomain($domain)
    {
        $locale = apply_filters('plugin_locale', get_locale(), $domain);

        $mo_file = $domain . '-' . $locale . '.mo';

        if (load_textdomain($domain, WP_LANG_DIR . '/plugins/wp-optimzer/' . $mo_file))
            return true;

        return load_textdomain($domain, WPOPT_ABSPATH . '/languages/' . $mo_file);
    }

    private function register_wpopt_actions()
    {

    }

    public static function getInstance()
    {
        if (!isset(self::$_instance)) {

            $object = self::$_instance = new self();

            require_once WPOPT_INCPATH . '/WOMonitor.class.php';
            $object->monitor = new WOMonitor();

            /**
             * Instancing all active modules
             */
            WOModuleHandler::getInstance()->create_instances();

            // todo move to modules
            require_once WPOPT_INCPATH . '/WOPerformer.class.php';

            if (is_admin()) {

                require_once WPOPT_ADMIN . '/WOPagesHandler.class.php';

                /**
                 * Set up the admin page handler
                 */
                $object->menu_page = new WOPagesHandler();
            }
        }

        return self::$_instance;
    }

    public function cron()
    {
        $timer = new WOTimer();

        $timer->start();

        $full_report = array();

        $performer = WOPerformer::getInstance();

        $default = array(
            'active'      => false,
            'images'      => false,
            'database'    => false,
            'save_report' => false
        );

        $options = WOSettings::getInstance()->get_settings('cron', $default);

        if ((bool)$options['active'] == false)
            return false;

        if ($options['images']) {

            $images = get_option('wpopt-imgs--todo');

            if (!empty($images)) {
                $full_report['images'] = $performer->optimize_images($images);
                update_option('wpopt-imgs--todo', array(), false);
            }
        }

        if ($options['database'])
            $full_report['db'] = $performer->clear_database_full();

        $timer->stop();

        if ($options['save_report'])
            file_put_contents(WP_CONTENT_DIR . '/report.opt.txt', wpopt_generate_report($full_report, $timer), FILE_APPEND);

        return array_merge(array('memory' => $timer->get_memory(), 'time' => $timer->get_time()), $full_report);
    }

    public function activate()
    {
        WOSettings::getInstance()->checkOption();

        $cron = WOModuleHandler::getInstance()->load_module('womod-cron');

        $cron->activate();
    }

    public function deactivate()
    {
        $cron = WOModuleHandler::getInstance()->load_module('womod-cron');

        $cron->deactivate();
    }

    /**
     * Add donate link to plugin description in /wp-admin/plugins.php
     *
     * @param array $plugin_meta
     * @param string $plugin_file
     * @param string $plugin_data
     * @param string $status
     * @return array
     */
    public function donate_link( $plugin_meta, $plugin_file, $plugin_data, $status ) {
        if( $plugin_file == $this->plugin_basename )
            $plugin_meta[] = '&hearts; <a target="_blank" href="https://www.paypal.me/sh1zen">'. __('Buy me a beer', 'wpopt') .' :o)</a>';
        return $plugin_meta;
    }

    /**
     * Add link to settings in Plugins list page
     *
     * @wp-hook plugin_action_links
     * @param $links
     * @param $file
     * @return mixed
     */
    public function settings_plugin_link($links, $file)
    {
        $links[] = sprintf(
            '<a href="%s">%s</a>',
            admin_url('admin.php?page=wpopt-settings'),
            __('Settings')
        );

        return $links;
    }
}
