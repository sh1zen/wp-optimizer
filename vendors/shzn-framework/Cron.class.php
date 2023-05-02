<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core;

/**
 * Generic Cron Handler for all WOModules
 */
class Cron
{
    private array $settings;

    private string $context;

    public function __construct($context)
    {
        $this->context = $context;

        $this->settings = shzn($context)->settings->get('cron', array(
            'execution-time' => '03:00:00',
            'active'         => true
        ));

        // cron job handler
        add_action("{$this->context}-cron", array($this, 'exec_cron'));

        if ($this->settings['active'] and wp_doing_cron()) {

            // function cron job handler
            add_action('init', array($this, 'cron_function_hook'), 10, 0);
        }
    }

    /**
     * Executes a cron event immediately.
     *
     * Executes an event by scheduling a new single event with the same arguments.
     *
     * @param string $hookname The hook name of the cron event to run.
     * @return bool Whether the execution was successful.
     */
    public static function run_event(string $hookname, $force = false)
    {
        $crons = _get_cron_array();

        foreach ($crons as $time => $cron) {

            if (!isset($cron[$hookname]))
                continue;

            foreach ($cron[$hookname] as $sig => $event) {

                delete_transient('doing_cron');

                if ($force) {
                    $event['args'] = array_merge($event['args'], ['force' => true]);
                }

                if (!self::schedule_single_event($hookname, $event['args'])) {
                    return false;
                }

                add_filter('cron_request', function (array $cron_request_array) {
                    $cron_request_array['url'] = add_query_arg('crontrol-single-event', 1, $cron_request_array['url']);
                    return $cron_request_array;
                });

                spawn_cron();

                usleep(400000);

                return true;
            }
        }

        return false;
    }

    /**
     * Forcibly schedules a single event for the purpose of manually running it.
     *
     * This is used instead of `wp_schedule_single_event()` to avoid the duplicate check that's otherwise performed.
     *
     * @param string $hook Action hook to execute when the event is run.
     * @param array $args Optional. Array containing each separate argument to pass to the hook's callback function.
     * @param int $timestamp
     * @param bool $clear
     * @return bool True if event successfully scheduled. False for failure.
     */
    public static function schedule_single_event($hook, $args = array(), $timestamp = 1, $clear = false)
    {
        if ($clear) {
            wp_clear_scheduled_hook($hook, $args);
        }

        $crons = (array)_get_cron_array();

        $key = md5(serialize($args));

        if (isset($crons[$timestamp][$hook][$key])) {
            return false;
        }

        $crons[$timestamp][$hook][$key] = array(
            'schedule' => false,
            'args'     => $args,
        );

        uksort($crons, 'strnatcasecmp');

        return _set_cron_array($crons);
    }

    /**
     * @param callable $function
     * @param array $args
     * @param int $timestamp
     * @return bool
     */
    public static function schedule_function($function, array $args = array(), int $timestamp = 1)
    {
        $events = get_option('cron.events', array());

        if (!is_array($events)) {
            $events = array();
        }

        $id = md5(serialize($function) . serialize($args));

        if (!isset($events[$id])) {

            $events[$id] = array(
                'function'      => $function,
                'accepted_args' => count($args)
            );

            update_option('cron.events', $events, 'no');
        }

        /**
         * Reschedule event before check if events already exist to ensure that
         * will be anyway executed -> must be a loop blocker
         */
        self::schedule_single_event($id, $args, $timestamp, true);

        return true;
    }

    /**
     * @param $function
     * @param array $args
     */
    public static function unschedule_function($function, array $args = array())
    {
        $events = get_option('cron.events', array());

        if (!is_array($events)) {
            return;
        }

        $id = md5(serialize($function) . serialize($args));

        wp_clear_scheduled_hook($id);

        if (!isset($events[$id])) {

            if (($key = array_search($events, $function)) !== false) {
                unset($events[$key]);
            }
        }
        else {
            unset($events[$id]);
        }

        update_option('cron.events', array_filter($events), 'no');
    }

    public function get_settings($module, $defaults = array())
    {
        $cron_settings = $this->settings;

        $module_cron_settings = (array)(isset($cron_settings[$module]) ? $cron_settings[$module] : array());

        return wp_parse_args(
            $module_cron_settings,
            $defaults
        );
    }

    public function cron_function_hook()
    {
        $events = get_option('cron.events', false);

        if (!$events) {
            return;
        }

        foreach ($events as $id => $event) {

            if ($id and is_callable($event['function'])) {
                add_action($id, $event['function'], 10, $event['accepted_args']);
            }
            else {
                unset($events[$id]);
            }
        }

        update_option('cron.events', array_filter($events), 'no');
    }

    public function cron_setting_validator($input, $valid)
    {
        $schedules = array_keys(wp_get_schedules());

        $valid['active'] = isset($input['active']);
        $valid['execution-time'] = sanitize_text_field($input['execution-time']);
        $valid['recurrence'] = in_array($input['recurrence'], $schedules) ? $input['recurrence'] : 'daily';

        $this->set_schedule($valid['active'] ? strtotime($valid['execution-time']) : false, $valid['recurrence']);

        /**
         * Filters all modules cron settings validation
         * @since 1.4.0
         */
        return apply_filters("{$this->context}_validate_cron_settings", $valid, $input);
    }

    public function set_schedule($time, $recurrence = 'daily')
    {
        $this->deactivate();

        if ($time) {

            $time = shzn_add_timezone($time);

            if ($time and !wp_next_scheduled("{$this->context}-cron")) {

                wp_schedule_event($time, $recurrence, "{$this->context}-cron");
            }
        }
    }

    public function deactivate()
    {
        wp_clear_scheduled_hook("{$this->context}-cron");
    }

    public function cron_setting_fields()
    {
        $cron_settings = [];

        $schedules = array_keys(wp_get_schedules());

        $cron_settings[] = array('type' => 'checkbox', 'name' => 'Active', 'id' => 'active', 'value' => Settings::check($this->settings, 'active'));
        $cron_settings[] = array('type' => 'time', 'name' => 'Auto Clear Time', 'id' => 'execution-time', 'value' => $this->settings['execution-time'], 'depend' => 'active');
        $cron_settings[] = array('type' => 'dropdown', 'name' => 'Schedule', 'id' => 'recurrence', 'list' => $schedules, 'value' => empty($this->settings['recurrence']) ? 'daily' : $this->settings['recurrence'], 'depend' => 'active');

        /**
         * Filters all modules cron settings
         * @since 1.4.0
         */
        return apply_filters("{$this->context}_cron_settings_fields", $cron_settings);
    }

    public function activate()
    {
        $this->set_schedule(strtotime($this->settings['execution-time']));
    }

    public function exec_cron($args = array())
    {
        if (!Settings::check($this->settings, 'active') or Settings::check($this->settings, 'running')) {
            return;
        }

        shzn($this->context)->settings->update('cron.running', true, true);

        shzn($this->context)->meter->lap('init_cron');

        do_action("{$this->context}_exec_cron", $args);

        shzn($this->context)->meter->lap('end_cron');

        $this->reset_status();
    }

    public function reset_status()
    {
        shzn($this->context)->settings->update('cron.running', false, true);
    }
}