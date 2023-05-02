<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core;

/**
 * Allow to easy schedule a callback action
 *
 */
class Actions
{
    /** @var string $hook A name for this cron. */
    public string $hook;

    /** @var int $interval How often to run this cron in seconds. */
    public int $interval;

    /** @var Closure|string|null $callback Optional. Anonymous function, function name or null to override with your own handle() method. */
    public $callback;

    /** @var array $args Optional. An array of arguments to pass into the callback. */
    public array $args;

    /** @var string $recurrence How often the event should subsequently recur. See wp_get_schedules(). */
    public string $recurrence;

    public int $timestamp;

    private function __construct($hook, $interval, $callback = null, $args = [], $time = 0)
    {
        $this->hook = trim($hook);
        $this->interval = absint($interval);
        $this->callback = $callback;
        $this->args = is_array($args) ? $args : [];

        if (empty($this->interval) or empty($this->hook)) {
            return;
        }

        if (!$time) {
            $this->timestamp = time();
        }
        else {

            $next_run_local = strtotime($time, current_time('timestamp'));

            if (false === $next_run_local) {
                return;
            }

            $this->timestamp = $next_run_local;
        }

        $this->recurrence = "flex_cron_{$this->interval}_seconds";

        add_action('wp', [$this, 'schedule_event']);
        add_filter('cron_schedules', [$this, 'add_schedule']);
        add_action($this->hook, [$this, 'handle']);
    }

    public static function schedule($hook, $interval, $callback = null, $args = [])
    {
        if (did_action('wp_loaded')) {
            new static($hook, $interval, $callback, $args);
        }

        add_action('wp_loaded', function () use ($hook, $interval, $callback, $args) {

            new static($hook, $interval, $callback, $args);

        }, 10, 0);
    }

    public function handle()
    {
        if (is_callable($this->callback)) {
            call_user_func_array($this->callback, $this->args);
        }
    }

    public function schedule_event()
    {
        if (!wp_next_scheduled($this->hook, $this->args)) {
            wp_schedule_event($this->timestamp, $this->recurrence, $this->hook, $this->args);
        }
    }

    public function add_schedule($schedules)
    {
        if (isset($schedules[$this->recurrence])) {
            return $schedules;
        }

        $schedules[$this->recurrence] = [
            'interval' => $this->interval,
            'display'  => 'Every ' . $this->interval . ' seconds',
        ];

        return $schedules;
    }
}
