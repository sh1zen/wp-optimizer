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
        $this->settings = WOSettings::get('cron', array(
            'clear-time' => '05:00:00',
            'active'     => true
        ));

        // cron job handler
        add_action('wpopt-cron', array($this, 'exec_cron'));

        if (wp_doing_cron()) {

            // function cron job handler
            add_action('init', array($this, 'cron_function_hook'), 10, 0);
        }

        if (is_admin()) {
            /**
             * Hooks to handle cron settings -> external module
             */
            add_filter('wpopt_cron_settings_fields', array($this, 'base_setting_fields'), 1, 1);
            add_filter('wpopt_validate_cron_settings', array($this, 'base_setting_validator'), 1, 2);
        }
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

    /**
     * Executes a cron event immediately.
     *
     * Executes an event by scheduling a new single event with the same arguments.
     *
     * @param string $hookname The hook name of the cron event to run.
     * @return bool Whether the execution was successful.
     */
    public static function run_event($hookname)
    {
        $crons = _get_cron_array();

        foreach ($crons as $time => $cron) {

            if (!isset($cron[$hookname]))
                continue;

            foreach ($cron[$hookname] as $sig => $event) {

                delete_transient('doing_cron');

                if (!self::schedule_single_event($hookname, $event['args'])) {
                    return false;
                }

                add_filter('cron_request', function (array $cron_request_array) {
                    $cron_request_array['url'] = add_query_arg('crontrol-single-event', 1, $cron_request_array['url']);
                    return $cron_request_array;
                });

                spawn_cron();

                sleep(0.2);

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
        $event = (object)array(
            'hook'      => $hook,
            'timestamp' => $timestamp,
            'schedule'  => false,
            'args'      => $args,
        );

        if ($clear)
            wp_clear_scheduled_hook($event->hook, $event->args);

        $crons = (array)_get_cron_array();
        $key = md5(serialize($event->args));

        if (isset($crons[$event->timestamp][$event->hook][$key]))
            return false;

        $crons[$event->timestamp][$event->hook][$key] = array(
            'schedule' => $event->schedule,
            'args'     => $event->args,
        );

        uksort($crons, 'strnatcasecmp');

        return _set_cron_array($crons);
    }

    /**
     * @param $function
     * @param array $args
     * @param int $timestamp
     * @return bool
     */
    public static function schedule_function($function, $args = array(), $timestamp = 1)
    {
        $events = WOOptions::get('cron.events', array());

        if (!is_array($events))
            $events = array();

        $id = md5(serialize($function) . serialize($args));

        /**
         * Reschedule event before check if events already exist to ensure that
         * will be anyway executed -> must be a loop blocker
         */
        self::schedule_single_event($id, $args, $timestamp, true);

        if (isset($events[$id])) {
            return false;
        }

        $events[$id] = array(
            'function'      => $function,
            'accepted_args' => count($args)
        );

        WOOptions::update('cron.events', $events);

        return true;
    }


    /**
     * @param $function
     * @param array $args
     */
    public static function unschedule_function($function, $args = array())
    {
        $events = WOOptions::get('cron.events', array());

        if (!is_array($events))
            return;

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

        WOOptions::update('cron.events', array_filter($events));
    }

    public function cron_function_hook()
    {
        $events = WOOptions::get('cron.events', false);

        if (!$events)
            return;

        foreach ($events as $id => $event) {

            if (isset($event['id']) and is_callable($event['function'])) {
                add_action($event['id'], $event['function'], 10, $event['accepted_args']);
            }
            else {
                unset($events[$id]);
                WOOptions::update('cron.events', array_filter($events));
            }
        }
    }

    public function base_setting_validator($valid, $input)
    {
        $valid['clear-time'] = sanitize_text_field($input['clear-time']);
        $valid['active'] = isset($input['active']);

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

    public function base_setting_fields($cron_settings)
    {
        $cron_settings[] = array('type' => 'time', 'name' => 'Auto Clear Time', 'id' => 'clear-time', 'value' => $this->settings['clear-time']);
        $cron_settings[] = array('type' => 'checkbox', 'name' => 'Active', 'id' => 'active', 'value' => WOSettings::check($this->settings, 'active'));

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
            return;

        $wo_meter->lap('init_cron');

        do_action("wpopt_exec_cron", array());

        $wo_meter->lap('end_cron');

        if (WPOPT_DEBUG)
            WOReport::export(true);
    }
}