<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core;

/**
 * Allow to easy schedule a callback action
 */
class Actions
{
    public static string $nonce_action = 'shzn-actions-ajax';

    public static string $nonce_name = '_nonce';
    /**
     * A name for this cron.
     */
    public string $hook;

    /**
     * How often to run this cron in seconds.
     */
    public int $interval;

    /**
     * @var Closure|string|null $callback Optional. Anonymous function, function name or null to override with your own handle() method.
     */
    public $callback;

    /**
     * Optional. An array of arguments to pass into the callback.
     */
    public array $args;

    /**
     * How often the event should subsequently recur. See wp_get_schedules().
     */
    public string $recurrence;

    public int $timestamp;

    private function __construct($hook, $interval, $callback = null, $time = 0, $args = [])
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

        $this->recurrence = "shzn_cron_{$this->interval}_seconds";

        $this->schedule_event();

        // schedules handler
        add_filter('cron_schedules', [$this, 'add_schedule']);

        // cron handler
        add_action($this->hook, [$this, 'handle']);
    }

    /**
     * Request action structure:
     *  For the callback:
     *      action => hook
     *      hook => callback action
     *  Internals
     *      self::$nonce_name => wp_create_nonce(self::$nonce_action)
     */
    public static function request($hook, callable $callback = null, $args = [])
    {
        if (!isset($_REQUEST[$hook]) or !isset($_REQUEST['action']) or $_REQUEST['action'] !== $hook) {
            return;
        }

        if (!empty($_REQUEST[self::$nonce_name]) and !UtilEnv::verify_nonce(self::$nonce_action, $_REQUEST[self::$nonce_name])) {

            if (!wp_doing_ajax()) {
                return;
            }

            Ajax::response([
                'body'  => __('It seems that you are not allowed to do this request.', 'shzn'),
                'title' => __('Request error', 'shzn')
            ], 'error');
        }

        $response = call_user_func($callback, $_REQUEST[$hook], ...$args);

        if (!wp_doing_ajax()) {
            return;
        }

        Ajax::response([
            'body'  => $response ?: __('It seems that you are not allowed to do this request.', 'shzn'),
            'title' => $response ? __('Request response', 'shzn') : __('Request error', 'shzn')
        ], $response ? 'success' : 'error');
    }

    public static function get_ajax_url($hook, $value, $display = false): string
    {
        $rewriter_current_page = Rewriter::getInstance();
        $rewriter_current_page->remove_query_arg('_wp_http_referer');

        $rewriter = Rewriter::getInstance(admin_url('admin-ajax.php'));
        $rewriter->set_query_arg('action', $hook);
        $rewriter->set_query_arg($hook, $value);
        $rewriter->set_query_arg(self::$nonce_name, wp_create_nonce(self::$nonce_action));
        $rewriter->set_query_arg('_wp_http_referer', $rewriter_current_page->get_uri());

        $ajax_url = $rewriter->get_uri();

        if ($display) {
            echo $ajax_url;
        }

        return $ajax_url;
    }

    public static function get_action_button($hook, $action, $name, $classes = 'button-secondary')
    {
        return Graphic::generate_field(array(
            'id'      => $hook,
            'value'   => $action,
            'name'    => $name,
            'classes' => $classes,
            'context' => 'button'
        ), false);
    }

    public static function schedule($hook, $interval, $callback = null, $time = 0, $args = [])
    {
        new static($hook, $interval, $callback, $time, $args);
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