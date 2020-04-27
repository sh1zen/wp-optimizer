<?php

if (!defined('ABSPATH'))
    exit();

/**
 * Main class
 * used to boot the plugin
 */
class wpopt
{
    private static $_instance;

    public $option_name = 'wpopt';

    private $menu_page;
    private $monitor;

    public function __construct()
    {
        $this->option_name = 'wpopt';

        $this->register_actions();

        $this->load_textdomain('wpopt');

        $this->register_wpopt_actions();
    }

    private function register_wpopt_actions()
    {

    }

    private function register_actions()
    {
        // cron job
        add_action('wpopt-cron', array($this, 'cron'));

        // Listen for the activate event
        register_activation_hook(WPOPT_FILE, array($this, 'activate'));

        // Deactivation plugin
        register_deactivation_hook(WPOPT_FILE, array($this, 'deactivate'));
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

    public static function getInstance()
    {
        if (!isset(self::$_instance)) {

            $object = self::$_instance = new self();

            require_once WPOPT_INCPATH . '/wpoptMonitor.class.php';
            $object->monitor = new wpoptMonitor();

            /**
             * Instancing all active modules
             */
            wpoptModuleHandler::getInstance()->create_instances();

            // todo move to modules
            require_once WPOPT_INCPATH . '/wpoptPerformer.class.php';

            if (is_admin()) {

                require_once WPOPT_ADMIN . '/wpoptPagesHandler.class.php';

                /**
                 * Set up the admin page handler
                 */
                $object->menu_page = new wpoptPagesHandler();
            }
        }

        return self::$_instance;
    }

    public function cron()
    {
        $timer = new wpoptTimer();

        $timer->start();

        $full_report = array();

        $performer = wpoptPerformer::getInstance();

        $default = array(
            'active'      => false,
            'images'      => false,
            'database'    => false,
            'save_report' => false
        );

        $options = wpoptSettings::getInstance()->get_settings('cron', $default);

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
            file_put_contents(WP_CONTENT_DIR . '/report.opt.txt', wpopt_generate_report($full_report, $timer = null), FILE_APPEND);

        return array_merge(array('memory' => wpopt_convert_size($timer->get_memory()), 'time' => $timer->get_time()), $full_report);
    }

    public function activate()
    {
        wpoptSettings::getInstance()->checkOption();

        $cron = wpoptModuleHandler::getInstance()->load_module('wpopt-cron');

        $cron->activate();
    }

    public function deactivate()
    {
        $cron = wpoptModuleHandler::getInstance()->load_module('wpopt-cron');

        $cron->deactivate();
    }
}
