<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

/**
 * Generic Cron Handler for all WOModules
 */
class CronActions
{
    private static bool $suspended = false;
    private static array $schedules_to_register = [];
    /**
     * A name for this cron.
     */
    private string $hook;
    /**
     * How often to run this cron in seconds.
     */
    private int $interval;
    /**
     * @var callable $callback Optional. Anonymous function, function name or null to override with your own handle() method.
     */
    private $callback;
    private array $args;
    /**
     * How often the event should subsequently recur. See wp_get_schedules().
     */
    private string $schedule_name = '';

    // scheduled next execution time
    private int $timestamp;

    private bool $scheduled = false;

    private bool $check_reschedule_time = false;

    private function __construct($hook, $callback, $interval, $next_execution_time, $args, $check_reschedule_time)
    {
        $this->hook = trim($hook);

        if (is_string($interval) and !is_numeric($interval)) {

            $schedules = wp_get_schedules();

            if (!isset($schedules[$interval])) {
                return;
            }

            $this->schedule_name = $interval;
            $interval = $schedules[$interval]['interval'];
        }

        $this->check_reschedule_time = $check_reschedule_time;
        $this->interval = absint($interval);
        $this->callback = $callback;
        $this->args = $args ?: [];

        $this->timestamp = self::parse_timestamp($hook, $callback, $next_execution_time, $interval);

        if ($this->timestamp === false) {
            return;
        }

        if ($this->interval and empty($this->schedule_name)) {

            $this->schedule_name = "wps_cron_{$this->interval}_seconds";

            self::$schedules_to_register[$this->schedule_name] = [
                'interval' => $this->interval,
                'display'  => 'Every ' . $this->interval . ' seconds',
            ];
        }

        if (!wp_doing_cron() and !wp_doing_ajax()) {
            $this->scheduled = $this->schedule_event();
        }

        // cron handler
        if (is_closure($this->callback)) {
            add_action($this->hook, [$this, 'handleClosure']);
        }
        else {
            add_action($this->hook, $callback);
        }
    }

    private static function parse_timestamp($hook, $callback, $timestamp, $interval)
    {
        if (self::$suspended) {
            return false;
        }

        if (empty($hook) or !(is_closure($callback) or is_callable($callback))) {
            wps_log("CronAction:: invalid callback for $hook", 'wps-cron.log');
            return false;
        }

        if (empty($timestamp)) {
            $timestamp = time();
        }

        $timestamp = is_string($timestamp) ? \wps_str_to_time($timestamp) : absint($timestamp);

        if ($interval and $timestamp < time()) {
            // check if next schedule is before current time
            // if so set it to next recurrence time starting from $timestamp multiple of $interval
            $timestamp += $interval * ceil((time() - $timestamp) / $interval);
        }

        if (!$timestamp) {
            wps_log("CronAction:: invalid timestamp for $hook", 'wps-cron.log');
            return false;
        }

        return $timestamp;
    }

    private function schedule_event()
    {
        $cron_array_edited = false;
        $crons = _get_cron_array();

        // shorthand for empty array()
        $key = empty($this->args) ? '40cd750bba9870f18aada2478b24840a' : Cache::generate_key($this->args);

        /**
         * if not need to reset every cron event and there is already a scheduled event with the same hook return
         */
        if (!wps_core()->is_upgrading()) {

            foreach ($crons as $timestamp => $cron) {

                if (is_array($cron) and isset($cron[$this->hook])) {

                    /**
                     * checking if we need to reschedule
                     * if interval has changed or if timestamp has changed only if check_reschedule_time is set to true
                     */
                    if ((!isset($cron[$this->hook][$key]['interval']) || $cron[$this->hook][$key]['interval'] == $this->interval) && (!$this->check_reschedule_time || (($timestamp - $this->timestamp) % $this->interval) == 0)) {

                        if ($cron_array_edited) {
                            self::save_cron_array($crons);
                        }

                        return $timestamp;
                    }

                    // so we need to reschedule everything
                    unset($crons[$timestamp][$this->hook]);
                    $cron_array_edited = true;
                }
            }
        }

        $event = [
            'schedule' => $this->schedule_name,
            'args'     => $this->args
        ];

        if ($this->interval) {
            // support for single events
            $event['interval'] = $this->interval;
        }

        $crons[$this->timestamp][$this->hook][$key] = $event;

        return self::save_cron_array($crons);
    }

    private static function save_cron_array($crons): bool
    {
        $crons = array_filter($crons);

        ksort($crons);

        return (bool)_set_cron_array($crons);
    }

    public static function suspend(): void
    {
        self::$suspended = true;
    }

    /**
     * @param string $hook
     * @param int|string $interval could schedule string like daily or seconds
     * @param callable|null $callback
     * @param int $time specific time like 12:30 or time() + 50
     * @param bool $check_reschedule_time
     * @return bool
     */
    public static function schedule(string $hook, $interval, callable $callback = null, $time = 0, bool $check_reschedule_time = false): bool
    {
        if (self::$suspended) {
            return false;
        }

        if (is_string($time)) {
            $check_reschedule_time = true;
        }

        $instance = new self($hook, $callback, $interval, $time, [], $check_reschedule_time);

        return $instance->scheduled();
    }

    private function scheduled(): bool
    {
        return $this->scheduled;
    }

    /**
     * Executes a cron event immediately.
     * Executes an event by scheduling a new single event with the same arguments.
     *
     * @param string $hook The hook name of the cron event to run.
     * @return bool Whether the execution was successful.
     */
    public static function run_event(string $hook): bool
    {
        $crons = _get_cron_array();

        foreach ($crons as $time => $cron) {

            if (!isset($cron[$hook]))
                continue;

            foreach ($cron[$hook] as $sig => $event) {

                delete_transient('doing_cron');

                if (!self::schedule_single_event($hook, $event['args'])) {
                    return false;
                }

                add_filter('cron_request', function (array $cron_request_array) {
                    $cron_request_array['url'] = add_query_arg('crontrol-single-event', 1, $cron_request_array['url']);
                    return $cron_request_array;
                });

                if (!defined('WP_CRON_LOCK_TIMEOUT')) {
                    define('WP_CRON_LOCK_TIMEOUT', MINUTE_IN_SECONDS);
                }

                spawn_cron();

                usleep(1000);

                return true;
            }
        }

        return false;
    }

    /**
     * Forcibly schedules a single event for the purpose of manually running it.
     * This is used instead of `wp_schedule_single_event()` to avoid the duplicate check that's otherwise performed.
     *
     * @param string $hook Action hook to execute when the event is run.
     * @param array $args Optional. Array containing each separate argument to pass to the hook's callback function.
     * @param int $timestamp
     * @param bool $clear
     * @return bool True if event successfully scheduled. False for failure.
     */
    private static function schedule_single_event(string $hook, array $args = array(), int $timestamp = 0, bool $clear = false): bool
    {
        $crons = _get_cron_array();

        $key = Cache::generate_key($args);

        if (!$timestamp) {
            $timestamp = time();
        }

        if ($clear) {
            foreach ($crons as $cron_timestamp => $cron) {
                if (is_array($cron)) {
                    unset($crons[$cron_timestamp][$hook]);
                }
            }
        }
        elseif (isset($crons[$timestamp][$hook][$key])) {
            return false;
        }

        $crons[$timestamp][$hook][$key] = array(
            'schedule' => false,
            'args'     => $args,
        );

        return self::save_cron_array($crons);
    }

    public static function schedule_function(string $hook, callable $callback, $timestamp = 1, array ...$args): bool
    {
        $timestamp = self::parse_timestamp($hook, $callback, $timestamp, false);

        if ($timestamp === false) {
            return false;
        }

        $events = get_option('wps#cron-events', array());

        if (!is_array($events)) {
            $events = array();
        }

        if (!isset($events[$hook]['timestamp']) or $events[$hook]['timestamp'] != $timestamp) {

            $events[$hook] = array(
                'timestamp'     => $timestamp,
                'accepted_args' => count($args),
                'function'      => $callback
            );

            update_option('wps#cron-events', $events, 'no');

            return self::schedule_single_event($hook, $args, $timestamp, true);
        }

        // already scheduled nothing to do for now
        return true;
    }

    public static function get_scheduled_function($hook)
    {
        $events = get_option('wps#cron-events', array());

        if (empty($events) or !isset($events[$hook])) {
            return false;
        }

        return $events[$hook];
    }

    public static function unschedule_function(string $hook): bool
    {
        $events = get_option('wps#cron-events', array());

        if (!is_array($events)) {
            return true;
        }

        unset($events[$hook]);

        update_option('wps#cron-events', array_filter($events), 'no');

        return self::unschedule_event($hook);
    }

    public static function unschedule_event($hook): bool
    {
        $crons = _get_cron_array();

        foreach ($crons as $timestamp => $cron) {
            unset($crons[$timestamp][$hook]);
        }

        return self::save_cron_array($crons);
    }

    public static function Initialize(): void
    {
        // used to add CronActions custom schedules in one shot
        add_filter('cron_schedules', ['WPS\core\CronActions', 'add_schedule']);

        if (!($events = get_option('wps#cron-events', false))) {
            return;
        }

        foreach ($events as $id => $event) {
            if ($id and (is_closure($event['function']) or is_callable($event['function']))) {
                add_action($id, $event['function'], 10, $event['accepted_args']);
            }
            else {
                unset($events[$id]);
            }
        }

        update_option('wps#cron-events', array_filter($events), 'no');
    }

    public static function add_schedule($schedules)
    {
        foreach (self::$schedules_to_register as $schedule_name => $schedule_data) {
            if (!isset($schedules[$schedule_name])) {
                $schedules[$schedule_name] = $schedule_data;
            }
        }

        return $schedules;
    }

    public static function handle(string $hook, callable $callback): void
    {
        add_action($hook, function () use ($callback) {
            call_user_func($callback);
        });
    }

    public function handleClosure(): void
    {
        if (is_callable($this->callback)) {
            call_user_func($this->callback, ...$this->args);
        }
    }
}