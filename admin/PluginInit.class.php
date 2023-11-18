<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C)  2022
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\core;

use SHZN\core\UtilEnv;

/**
 * Main class, used to setup the plugin
 */
class PluginInit
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

    public $pages_handler;

    public function __construct()
    {
        $this->plugin_basename = UtilEnv::plugin_basename(WPOPT_FILE);
        $this->plugin_base_url = UtilEnv::path_to_url(WPOPT_ABSPATH);

        if (is_admin()) {

            $this->register_actions();
        }

        $this->load_textdomain('wpopt');

        $this->maybe_upgrade();
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
        $locale = apply_filters('wpopt_plugin_locale', get_locale(), $domain);

        $mo_file = "{$domain}-{$locale}.mo";

        if (load_textdomain($domain, WP_LANG_DIR . '/plugins/wp-optimizer/' . $mo_file)) {
            return true;
        }

        return load_textdomain($domain, UtilEnv::normalize_path(WPOPT_ABSPATH . 'languages/' ). $mo_file);
    }

    private function maybe_upgrade()
    {
        $version = shzn('wpopt')->settings->get('ver', false);

        // need upgrade
        if (!$version or version_compare($version, WPOPT_VERSION, '<')) {
            require_once dirname(__FILE__) . '/upgrader.php';
        }
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

            /**
             * Instancing all modules that need to interact in the Ajax process
             */
            shzn('wpopt')->moduleHandler->setup_modules('ajax');
        }
        elseif (wp_doing_cron()) {

            /**
             * Instancing all modules that need to interact in the cron process
             */
            shzn('wpopt')->moduleHandler->setup_modules('cron');
        }
        elseif (is_admin()) {

            require_once WPOPT_ADMIN . 'PagesHandler.class.php';

            /**
             * Load the admin pages handler and store it here
             */
            $object->pages_handler = new PagesHandler();

            /**
             * Instancing all modules that need to interact in admin area
             */
            shzn('wpopt')->moduleHandler->setup_modules('admin');
        }
        else {

            /**
             * Instancing all modules that need to interact only on the web-view
             */
            shzn('wpopt')->moduleHandler->setup_modules('web-view');
        }

        /**
         * Instancing all modules that need to be always loaded
         */
        shzn('wpopt')->moduleHandler->setup_modules('autoload');

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
        shzn('wpopt')->settings->activate();

        shzn('wpopt')->cron->activate();

        /**
         * Hook for the plugin activation
         * @since 1.4.0
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
        shzn('wpopt')->cron->deactivate();

        /**
         * Hook for the plugin deactivation
         * @since 1.4.0
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
        if ($plugin_file == $this->plugin_basename) {
            $plugin_meta[] = '&hearts; <a target="_blank" href="https://www.paypal.com/donate/?business=dev.sh1zen%40outlook.it&item_name=Thank+you+in+advanced+for+the+kind+donations.+You+will+sustain+me+developing+WP-Optimizer.&currency_code=EUR">' . __('Buy me a beer', 'wpopt') . ' :o)</a>';
        }

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
            admin_url('admin.php?page=wpopt-modules-settings'),
            __('Settings', 'wpopt')
        );

        return $links;
    }
}
