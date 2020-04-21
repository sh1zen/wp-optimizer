<?php

/**
 * Main class
 * used to boot the plugin
 */
class wpopt
{
    private static $_instance;

    public $option_name = 'wpopt';

    public $data = array();
    public $modules = array();
    private $menu_page;
    private $monitor;

    public function __construct($environment = '')
    {
        if ($environment == 'cron')
            return;

        $this->option_name = 'wpopt';

        $this->data = wp_parse_args(get_option('wpopt'), array(
            'clear-time'  => '05:00:00',
            'active'      => true,
            'images'      => true,
            'database'    => false,
            'save_report' => false,
            'lock'        => false,
            'mime-types'  => array(
                'jpg|jpeg|jpe' => 'image/jpeg',
                'gif'          => 'image/gif',
                'png'          => 'image/png',
                'ico'          => 'image/x-icon',
                'pjpeg'        => 'image/pjpeg'
            )
        ));

        $this->register_actions();

    }

    private function register_actions()
    {
        // Admin sub-menu
        add_action('admin_init', array($this, 'admin_init'));

        // Listen for the activate event
        register_activation_hook(WPOPT_FILE, array($this, 'activate'));

        // Deactivation plugin
        register_deactivation_hook(WPOPT_FILE, array($this, 'deactivate'));

        // cron job
        add_action('wpopt-cron', array($this, 'cron'));
    }

    public static function getInstance()
    {
        if (!defined('ABSPATH'))
            exit();

        if (!isset(self::$_instance)) {
            $object = self::$_instance = new self();

            // enable cache support
            require_once WPOPT_ABSPATH . '/inc/wpopt_plcache.class.php';

            // todo move to modules
            require_once WPOPT_ABSPATH . '/inc/wpopt_performer.class.php';

            require_once WPOPT_ABSPATH . '/inc/wpopt_monitor.class.php';
            $object->monitor = new wpopt_monitor();

            if (is_admin()) {

                require_once WPOPT_ABSPATH . '/inc/wpopt_modules.class.php';
                wpopt_modules::getInstance();

                require_once WPOPT_ABSPATH . '/admin/wpopt_menu_page.class.php';
                $object->menu_page = new wpopt_menu_page($object->option_name);
            }
        }

        return self::$_instance;
    }

    public function cron()
    {
        $args = get_option($this->option_name);

        if ($args) {
            wpopt_do_cron($args);
        }
    }


    public function admin_init()
    {
        if (!is_admin() or !current_user_can('manage_options')) {
            return;
        }

        register_setting('wpopt', $this->option_name, array($this, 'validate'));
    }

    public function activate()
    {
        if (!get_option($this->option_name, false))
            update_option($this->option_name, $this->data);

        wp_clear_scheduled_hook('wpopt-cron');

        if (!wp_next_scheduled('wpopt-cron')) {
            wp_schedule_event(strtotime($this->data['clear-time']), 'daily', 'wpopt-cron');
        }
    }

    public function deactivate()
    {
        wp_clear_scheduled_hook('wpopt-cron');
    }

    public function validate($input)
    {
        $valid = (array)get_option($this->option_name);

        if ($input['change'] == 'settings') {

            $valid['clear-time'] = sanitize_text_field($input['clear-time']);

            wp_clear_scheduled_hook('wpopt-cron');
            wp_schedule_event(strtotime($valid['clear-time']), 'daily', 'wpopt-cron');

            $valid['active'] = (bool)sanitize_text_field($input['active']);
            $valid['images'] = (bool)sanitize_text_field($input['images']);
            $valid['database'] = (bool)sanitize_text_field($input['database']);
            $valid['save_report'] = (bool)sanitize_text_field($input['save_report']);
        }

        return $valid;
    }
}
