<?php

/**
 * Generic Cron Handler for all WOModules
 */
class WOCron
{
    private static $_instance;

    private $settings;

    private function __construct()
    {
        $default = array(
            'clear-time'  => '05:00:00',
            'active'      => false,
            'save_report' => true,
            'images'      => array(
                'active' => false,
            ),
            'database'    => array(
                'active' => false,
            ),
        );

        $this->settings = WOSettings::getInstance()->get_settings('cron', $default);

        // cron job handler
        add_action('wpopt-cron', array($this, 'exec_cron'));

        /**
         * Hooks to handle cron settings -> external module
         */
        add_filter('wpopt_cron_settings_fields', array($this, 'base_cron_setting_fields'), 1, 1);
        add_filter('wpopt_validate_cron_settings', array($this, 'base_cron_setting_validator'), 1, 2);
    }

    public static function get_settings($module, $defaults = array())
    {
        $cron_settings = self::getInstance()->settings;

        $module_cron_settings = (array)(isset($cron_settings[$module]) ? $cron_settings[$module] : array());

        return wp_parse_args(
            $module_cron_settings,
            $defaults
        );
    }

    public static function getInstance()
    {
        if (!isset(self::$_instance)) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public function base_cron_setting_validator($valid, $input)
    {
        $valid['clear-time'] = sanitize_text_field($input['clear-time']);
        $valid['active'] = isset($input['active']);
        $valid['save_report'] = isset($input['save_report']);

        $this->set_schedule(strtotime($valid['clear-time']));

        return $valid;
    }

    public function set_schedule($time, $recurrence = 'daily')
    {
        $time = wpopt_add_timezone($time);

        $this->deactivate();

        if (!wp_next_scheduled('wpopt-cron')) {

            wp_schedule_event($time, $recurrence, 'wpopt-cron');
        }
    }

    public function deactivate()
    {
        wp_clear_scheduled_hook('wpopt-cron');
    }

    public function base_cron_setting_fields($cron_settings)
    {
        $cron_settings[] = array('type' => 'time', 'name' => 'Auto Clear Time', 'id' => 'clear-time', 'value' => $this->settings['clear-time']);
        $cron_settings[] = array('type' => 'checkbox', 'name' => 'Active', 'id' => 'active', 'value' => WOSettings::check($this->settings, 'active'));
        $cron_settings[] = array('type' => 'checkbox', 'name' => 'Save optimization report', 'id' => 'save_report', 'value' => WOSettings::check($this->settings, 'save_report'));

        return $cron_settings;
    }

    public function activate()
    {
        $this->set_schedule(strtotime($this->settings['clear-time']));
    }

    public function exec_cron()
    {
        global $wo_meter;

        if (!WOSettings::check($this->settings, 'active'))
            return false;

        $wo_meter->lap('init_cron');

        do_action("wpopt_exec_cron", $wo_meter);

        $performer = WOPerformer::getInstance();

        $full_report = array();

        if (WOSettings::check($this->settings, 'images')) {

            $images = get_option('wpopt-imgs--todo');

            if (!empty($images)) {
                $full_report['images'] = $performer->optimize_images($images);
                update_option('wpopt-imgs--todo', array(), false);
            }
        }

        $wo_meter->lap('end_cron');

        if (WOSettings::check($this->settings, 'save_report'))
            file_put_contents(WP_CONTENT_DIR . '/report.opt.txt', wpopt_generate_report($full_report), FILE_APPEND);

        if (wp_doing_cron())
            die();

        return array_merge(array('memory' => $wo_meter->get_memory(), 'time' => $wo_meter->get_time()), $full_report);
    }

}