<?php

if (!defined('ABSPATH'))
    exit();

/**
 * Main class, used to boot the plugin
 *
 * since 1.0.0
 */
class WO
{
    private static $_instance;

    /**
     * Holds the plugin base name
     */
    public $plugin_basename;

    /**
     * Holds the WOMeter instance
     */
    public $monitor;

    /**
     * Holds the plugin base url
     */
    public $plugin_base_url;

    private $pages_handler;

    public function __construct()
    {
        $this->plugin_basename = plugin_basename(WPOPT_FILE);
        $this->plugin_base_url = plugin_dir_url(WPOPT_FILE);

        $this->register_actions();

        $this->load_textdomain('wpopt');
    }

    private function register_actions()
    {
        // Plugin Activation/Deactivation.
        register_activation_hook(WPOPT_FILE, array($this, 'plugin_activation'));
        register_deactivation_hook(WPOPT_FILE, array($this, 'plugin_deactivation'));

        add_filter("plugin_action_links_{$this->plugin_basename}", array($this, 'settings_plugin_link'), 10, 2);

        add_filter('plugin_row_meta', array($this, 'donate_link'), 10, 4);
    }

    /**
     * Loads text domain for the plugin.
     *
     * @param $domain
     * @return bool
     * @action plugins_loaded
     */
    private function load_textdomain($domain)
    {
        $locale = apply_filters('plugin_locale', get_locale(), $domain);

        $mo_file = $domain . '-' . $locale . '.mo';

        if (load_textdomain($domain, WP_LANG_DIR . '/plugins/wp-optimzer/' . $mo_file))
            return true;

        return load_textdomain($domain, WPOPT_ABSPATH . 'languages/' . $mo_file);
    }

    public static function getInstance()
    {
        if (!isset(self::$_instance)) {
            return self::Initialize();
        }

        return self::$_instance;
    }

    public static function Initialize()
    {
        $object = self::$_instance = new self();

        $object->monitor = new WOMonitor();

        /**
         * Keep Ajax requests fast:
         * if doing ajax : load only ajax handler and return
         */
        if (wp_doing_ajax()) {

            require_once WPOPT_ADMIN . 'WOAjax.class.php';

            /**
             * Set up the WP Optimizer ajax handler
             */
            WOAjax::Initialize();

            return self::$_instance;
        }

        /**
         * Instancing all active modules
         */
        WOModuleHandler::getInstance()->create_instances();

        if (is_admin()) {

            require_once WPOPT_ADMIN . 'WOPagesHandler.class.php';

            /**
             * Load the admin pages handler and store it here
             */
            $object->pages_handler = new WOPagesHandler();
        }

        return self::$_instance;
    }

    public function module_panel_url($module = '', $panel = '')
    {
        return admin_url("admin.php?page={$module}#{$panel}");
    }

    public function setting_panel_url($panel = '')
    {
        return admin_url("admin.php?page=wpopt-settings#settings-{$panel}");
    }

    /**
     * What to do when the plugin on plugin activation
     *
     * @param boolean $network_wide Is network wide.
     * @return void
     * @since 1.0.0
     *
     * @access public
     */
    public function plugin_activation($network_wide)
    {
        if (is_multisite() && $network_wide) {
            $ms_sites = (array)get_sites();

            if (0 < count($ms_sites)) {
                foreach ($ms_sites as $ms_site) {
                    switch_to_blog($ms_site->blog_id);
                    $this->activate();
                    restore_current_blog();
                }
            }
        }
        else {
            $this->activate();
        }
    }

    private function activate()
    {
        WOSettings::getInstance()->checkOption();

        $cron = WOModuleHandler::getInstance()->load_module('cron');

        $cron->activate();
    }

    /**
     * What to do when the plugin on plugin deactivation
     *
     * @param boolean $network_wide Is network wide.
     * @return void
     * @since 1.0.0
     *
     * @access public
     */
    public function plugin_deactivation($network_wide)
    {
        if (is_multisite() && $network_wide) {
            $ms_sites = (array)get_sites();

            if (0 < count($ms_sites)) {
                foreach ($ms_sites as $ms_site) {
                    switch_to_blog($ms_site->blog_id);
                    $this->deactivate();
                    restore_current_blog();
                }
            }
        }
        else {
            $this->deactivate();
        }
    }

    private function deactivate()
    {
        $cron = WOModuleHandler::getInstance()->load_module('cron');

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
    public function donate_link($plugin_meta, $plugin_file, $plugin_data, $status)
    {
        if ($plugin_file == $this->plugin_basename)
            $plugin_meta[] = '&hearts; <a target="_blank" href="https://www.paypal.me/sh1zen">' . __('Buy me a beer', 'wpopt') . ' :o)</a>';
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
            __('Settings', 'wpopt')
        );

        return $links;
    }
}
