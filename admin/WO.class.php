<?php

/**
 * Main class, used to boot the plugin
 */
class WO
{
    private static $_instance;

    /**
     * Holds the plugin base name
     */
    public $plugin_basename;

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

        add_filter("plugin_action_links_{$this->plugin_basename}", array($this, 'extra_plugin_link'), 10, 2);

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

            /**
             * Instancing all modules that need to interact in the Ajax process
             */
            WOModuleHandler::getInstance()->setup_modules('ajax');
        }
        elseif (wp_doing_cron()) {

            /**
             * Instancing all modules that need to interact in the cron process
             */
            WOModuleHandler::getInstance()->setup_modules('cron');
        }
        elseif (is_admin()) {

            require_once WPOPT_ADMIN . 'WOPagesHandler.class.php';

            /**
             * Load the admin pages handler and store it here
             */
            $object->pages_handler = new WOPagesHandler();

            /**
             * Instancing all modules that need to interact in admin area
             */
            WOModuleHandler::getInstance()->setup_modules('admin');
        }
        else {

            /**
             * Instancing all modules that need to interact only on the web-view
             */
            WOModuleHandler::getInstance()->setup_modules('web-view');
        }

        /**
         * Instancing all modules that need to be always loaded
         */
        WOModuleHandler::getInstance()->setup_modules('autoload');

        return self::$_instance;
    }

    /**
     * What to do when the plugin on plugin activation
     *
     * @param boolean $network_wide Is network wide.
     * @return void
     *
     * @access public
     */
    public function plugin_activation($network_wide)
    {
        if (is_multisite() and $network_wide) {
            $ms_sites = (array)get_sites();

            foreach ($ms_sites as $ms_site) {
                switch_to_blog($ms_site->blog_id);
                $this->activate();
                restore_current_blog();
            }
        }
        else {
            $this->activate();
        }
    }

    private function activate()
    {
        WOSettings::getInstance()->activate();

        WOCron::getInstance()->activate();

        /**
         * Hook for the plugin activation
         * @since 0.0.9
         */
        do_action('wpopt-activate');
    }

    /**
     * What to do when the plugin on plugin deactivation
     *
     * @param boolean $network_wide Is network wide.
     * @return void
     *
     * @access public
     */
    public function plugin_deactivation($network_wide)
    {
        if (is_multisite() and $network_wide) {
            $ms_sites = (array)get_sites();

            foreach ($ms_sites as $ms_site) {
                switch_to_blog($ms_site->blog_id);
                $this->deactivate();
                restore_current_blog();
            }
        }
        else {
            $this->deactivate();
        }
    }

    private function deactivate()
    {
        WOCron::getInstance()->deactivate();

        /**
         * Hook for the plugin deactivation
         * @since 0.0.9
         */
        do_action('wpopt-deactivate');
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
    public function extra_plugin_link($links, $file)
    {
        $links[] = sprintf(
            '<a href="%s">%s</a>',
            admin_url('admin.php?page=wpopt-settings'),
            __('Settings', 'wpopt')
        );

        return $links;
    }
}
