<?php

if (!defined('ABSPATH'))
    exit();

class WOMod_Cron extends WO_Module
{
    public $scopes = array('settings', 'cron', 'autoload');

    public function __construct()
    {
        $default = array(
            'clear-time'  => '05:00:00',
            'active'      => false,
            'images'      => false,
            'database'    => false,
            'save_report' => true
        );

        parent::__construct(
            array(
                'settings' => $default,
            )
        );

        // cron job
        add_action('wpopt-cron', array($this, 'exec_cron'));
    }

    public function setting_fields()
    {
        return array(
            array('type' => 'time', 'name' => 'Auto Clear Time', 'id' => 'clear-time', 'value' => $this->settings['clear-time']),
            array('type' => 'checkbox', 'name' => 'Active', 'id' => 'active', 'value' => WOSettings::check($this->settings, 'active')),
            array('type' => 'checkbox', 'name' => 'Auto optimize images ( daily uploads )', 'id' => 'images', 'value' => WOSettings::check($this->settings, 'images')),
            array('type' => 'checkbox', 'name' => 'Auto optimize Database', 'id' => 'database', 'value' => WOSettings::check($this->settings, 'database')),
            array('type' => 'checkbox', 'name' => 'Save optimization report', 'id' => 'save_report', 'value' => WOSettings::check($this->settings, 'save_report'))
        );
    }

    public function validate_settings($input, $valid)
    {
        $valid['clear-time'] = sanitize_text_field($input['clear-time']);

        $this->set_schedule(strtotime($valid['clear-time']));

        $valid['active'] = isset($input['active']);
        $valid['images'] = isset($input['images']);
        $valid['database'] = isset($input['database']);
        $valid['save_report'] = isset($input['save_report']);

        return $valid;
    }

    public function set_schedule($time, $recurrence = 'daily')
    {
        $this->deactivate();

        if (!wp_next_scheduled('wpopt-cron')) {

            wp_schedule_event($time + 60, $recurrence, 'wpopt-cron');
        }
    }

    public function deactivate()
    {
        wp_clear_scheduled_hook('wpopt-cron');
    }

    public function activate()
    {
        $this->set_schedule(strtotime($this->settings['clear-time']));
    }

    public function exec_cron()
    {
        $timer = WO::getInstance()->monitor;

        $full_report = array();

        $performer = WOPerformer::getInstance();

        if (!WOSettings::check($this->settings, 'active'))
            return false;

        if (WOSettings::check($this->settings, 'images')) {

            $images = get_option('wpopt-imgs--todo');

            if (!empty($images)) {
                $full_report['images'] = $performer->optimize_images($images);
                update_option('wpopt-imgs--todo', array(), false);
            }
        }

        if (WOSettings::check($this->settings, 'database'))
            $full_report['db'] = $performer->clear_database_full();

        $timer->lap();

        if (WOSettings::check($this->settings, 'save_report'))
            file_put_contents(WP_CONTENT_DIR . '/report.opt.txt', wpopt_generate_report($full_report, $timer), FILE_APPEND);

        if (defined('DOING_CRON') and DOING_CRON)
            die();

        return array_merge(array('memory' => $timer->get_memory(), 'time' => $timer->get_time()), $full_report);
    }
}