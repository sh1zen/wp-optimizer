<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

/**
 * Generic Cron Handler for all WPS Modules
 */
class CronForModules
{
    private array $settings;

    private string $context;

    private array $modules;

    public function __construct($context)
    {
        $this->context = $context;

        $this->settings = wps($context)->settings->get('cron', array(
            'execution-time' => '01:00:00',
            'active'         => false,
            'recurrence'     => 'daily'
        ));

        $this->modules = wps($this->context)->moduleHandler->get_modules('cron', true);

        CronActions::handle("$this->context-cron", [$this, 'exec_cron']);
    }

    public function cron_setting_validator($input, $filtering): array
    {
        $schedules = array_keys(wp_get_schedules());

        $valid = [];

        if ($filtering) {
            return $this->settings;
        }

        $valid['active'] = isset($input['active']);
        $valid['execution-time'] = StringHelper::sanitize_text($input['execution-time']);
        $valid['recurrence'] = in_array($input['recurrence'], $schedules) ? $input['recurrence'] : 'daily';

        $this->set_schedule($valid['execution-time'], $valid['recurrence']);

        foreach ($this->modules as $module) {
            $module_object = wps($this->context)->moduleHandler->get_module_instance($module);
            $valid = array_merge($valid, $module_object->cron_validate_settings($input, $filtering));
        }

        return $valid;
    }

    private function set_schedule($time, $recurrence = 'daily'): void
    {
        if (!$time) {
            return;
        }

        CronActions::schedule("$this->context-cron", $recurrence, [$this, 'exec_cron'], $time, true);
    }

    public function deactivate(): void
    {
        CronActions::unschedule_event("$this->context-cron");
    }

    public function cron_setting_fields(): array
    {
        $cron_settings = [];

        $schedules = array_keys(wp_get_schedules());

        $cron_settings[] = array('type' => 'checkbox', 'name' => __('Active', $this->context), 'id' => 'active', 'value' => $this->option('active'));
        $cron_settings[] = array('type' => 'time', 'name' => __('Execution Time', $this->context), 'id' => 'execution-time', 'value' => $this->option('execution-time', '01:00'), 'depend' => 'active');
        $cron_settings[] = array('type' => 'dropdown', 'name' => __('Schedule', $this->context), 'id' => 'recurrence', 'list' => $schedules, 'value' => $this->option('recurrence', 'daily') ?: 'daily', 'depend' => 'active');

        foreach ($this->modules as $module) {
            $module_object = wps($this->context)->moduleHandler->get_module_instance($module);
            $cron_settings = array_merge($cron_settings, $module_object->cron_setting_fields());
        }

        return $cron_settings;
    }

    public function option($path_name = '', $default = false)
    {
        return Settings::get_option($this->settings, $path_name, $default);
    }

    public function activate(): void
    {
        $this->set_schedule($this->settings['execution-time'], $this->settings['recurrence']);
    }

    public function exec_cron($args = array()): void
    {
        if (!$this->option('active') or $this->option('running')) {
            return;
        }

        wps($this->context)->settings->update('cron.running', true, true);

        if (wps_utils()->debug) {
            wps_utils()->meter->lap('init_cron');
        }

        do_action("{$this->context}_exec_cron", $args);

        if (wps_utils()->debug) {
            wps_utils()->meter->lap('end_cron');
        }

        $this->reset_status();
    }

    public function reset_status()
    {
        wps($this->context)->settings->update('cron.running', false, true);
    }

    public function is_active($module)
    {
        return $this->option("$module.active");
    }
}