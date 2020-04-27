<?php

class wpoptCron
{
    public $inneropt_name;

    public function __construct()
    {
        $this->inneropt_name = 'cron';
    }

    public function setting_fields()
    {
        $settings = wpoptSettings::getInstance()->get_settings($this->inneropt_name);

        return array(
            array('type' => 'time', 'name' => 'Auto Clear Time', 'id' => 'clear-time', 'value' => $settings['clear-time']),
            array('type' => 'checkbox', 'name' => 'Active', 'id' => 'active', 'value' => $settings['active']),
            array('type' => 'checkbox', 'name' => 'Auto optimize images ( daily uploads )', 'id' => 'images', 'value' => $settings['images']),
            array('type' => 'checkbox', 'name' => 'Auto optimize Database', 'id' => 'database', 'value' => $settings['database']),
            array('type' => 'checkbox', 'name' => 'Save optimization report', 'id' => 'save_report', 'value' => $settings['save_report'])
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

    public function activate()
    {
        $settings = wpoptSettings::getInstance()->get_settings($this->inneropt_name);

        $this->set_schedule(strtotime($settings['clear-time']));
    }

    public function set_schedule($time, $recurrence = 'daily')
    {
        $this->deactivate();

        if (!wp_next_scheduled('wpopt-cron')) {

            wp_schedule_event($time, $recurrence, 'wpopt-cron');
        }
    }

    public function deactivate()
    {
        wp_clear_scheduled_hook('wpopt-cron');
    }
}