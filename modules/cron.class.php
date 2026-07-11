<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use WPS\core\List_Table;
use WPS\modules\Module;

class Mod_Cron extends Module
{
    public static ?string $name = 'Cron Manager';

    public array $scopes = array('core-settings', 'admin-page', 'cron');

    protected string $context = 'wpopt';

    private const ACTION_NONCE = 'wpopt-cron-manager-action';

    private bool $cron_modules_loaded = false;

    private ?array $custom_schedules = null;

    private array $callback_lookup = array();

    private array $formatted_intervals = array();

    public function cleanup(array $settings = array(), array $all_settings = array()): bool
    {
        wps('wpopt')->cron->deactivate();

        return true;
    }

    public function activate(array $settings = array(), array $all_settings = array()): bool
    {
        $settings = !empty($settings) ? $settings : wps('wpopt')->settings->get('cron', array());

        if (!empty($settings['active'])) {
            wps('wpopt')->cron->activate();
        }
        else {
            wps('wpopt')->cron->deactivate();
        }

        return true;
    }

    public function validate_settings($input, $filtering = false): array
    {
        return wps('wpopt')->cron->cron_setting_validator($input, $filtering);
    }

    public function restricted_access($context = ''): bool
    {
        switch ($context) {

            case 'settings':
            case 'render-admin':
                return !current_user_can('manage_options');

            default:
                return false;
        }
    }

    protected function init(): void
    {
        add_filter('cron_schedules', array($this, 'register_custom_schedules'));
    }

    protected function setting_fields($filter = ''): array
    {
        /**
         * Load here all module cron settings.
         */
        $this->load_cron_modules();

        return wps('wpopt')->cron->cron_setting_fields();
    }

    protected function infos(): array
    {
        return array(
            'active'         => __('Enable or disable the Cron Manager. When active, scheduled tasks will run automatically.', 'wpopt'),
            'execution-time' => __('Set the time of day when the cron task should be executed.', 'wpopt'),
            'recurrence'     => __('Choose how often the optimization tasks should run.', 'wpopt'),
            'database.active' => __('Automatically clean and optimize your database on the scheduled interval.', 'wpopt'),
            'media.active'   => __('Automatically optimize uploaded images on the scheduled interval.', 'wpopt'),
        );
    }

    public function register_custom_schedules(array $schedules): array
    {
        foreach ($this->get_custom_schedules() as $slug => $schedule) {
            $schedules[$slug] = array(
                'interval' => (int)$schedule['interval'],
                'display'  => (string)$schedule['display'],
            );
        }

        return $schedules;
    }

    public function render_sub_modules(bool $standalone = true): void
    {
        $this->load_cron_modules();
        $this->handle_cron_request();

        $search = $this->get_request_text('s');
        $events = $this->get_cron_events($search);
        $summary = $this->get_event_summary($events);
        $schedules = wp_get_schedules();
        $custom_schedules = $this->get_custom_schedules();
        ?>
        <section class="wps-wrap wpopt-cron-manager">
            <div class="wpopt-cron-summary-block">
                <?php settings_errors('wpopt-cron-manager'); ?>
                <?php echo $this->render_summary($summary); ?>
            </div>

            <div class="wpopt-cron-events-block">
                <?php echo $this->render_events_table($events, $schedules, $search); ?>
            </div>

            <?php echo $this->render_create_event_modal($schedules); ?>

            <div class="wpopt-cron-schedules-block">
                <h2><?php _e('Custom schedules', 'wpopt'); ?></h2>
                <?php echo $this->render_schedules_table($schedules, $custom_schedules); ?>
            </div>
        </section>
        <?php
    }

    private function load_cron_modules(): void
    {
        if ($this->cron_modules_loaded) {
            return;
        }

        foreach (wps('wpopt')->moduleHandler->get_modules(array('scopes' => 'cron', 'excepts' => array('cron'))) as $module) {
            wps('wpopt')->moduleHandler->get_module_instance($module);
        }

        $this->cron_modules_loaded = true;
    }

    private function handle_cron_request(): void
    {
        if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '') || empty($_POST['wpopt_cron_action'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            $this->add_cron_notice(__('You are not allowed to manage cron events.', 'wpopt'), 'error');
            return;
        }

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), self::ACTION_NONCE)) {
            $this->add_cron_notice(__('Security check failed.', 'wpopt'), 'error');
            return;
        }

        $action = sanitize_key(wp_unslash($_POST['wpopt_cron_action']));

        switch ($action) {
            case 'create_event':
                $this->create_event();
                break;

            case 'run_event':
                $this->run_event();
                break;

            case 'delete_event':
                $this->delete_event();
                break;

            case 'reschedule_event':
                $this->reschedule_event();
                break;

            case 'add_schedule':
                $this->add_schedule();
                break;

            case 'delete_schedule':
                $this->delete_schedule();
                break;
        }
    }

    private function create_event(): void
    {
        $hook = $this->sanitize_hook((string)wp_unslash($_POST['event_hook'] ?? ''));
        $timestamp = $this->parse_local_datetime((string)wp_unslash($_POST['event_timestamp'] ?? ''));
        $recurrence = sanitize_key(wp_unslash($_POST['event_recurrence'] ?? 'single'));
        $args = $this->parse_json_args((string)wp_unslash($_POST['event_args'] ?? ''), $valid_args);

        if ('' === $hook || !$this->is_valid_hook($hook)) {
            $this->add_cron_notice(__('Use a valid hook name. Allowed characters are letters, numbers, underscore, dash, dot, slash and colon.', 'wpopt'), 'error');
            return;
        }

        if (!$timestamp) {
            $this->add_cron_notice(__('Use a valid date and time.', 'wpopt'), 'error');
            return;
        }

        if (!$valid_args) {
            $this->add_cron_notice(__('Arguments must be a valid JSON array.', 'wpopt'), 'error');
            return;
        }

        if ('single' === $recurrence || '' === $recurrence) {
            $scheduled = wp_schedule_single_event($timestamp, $hook, $args);
        }
        elseif (isset(wp_get_schedules()[$recurrence])) {
            $scheduled = wp_schedule_event($timestamp, $recurrence, $hook, $args);
        }
        else {
            $this->add_cron_notice(__('Invalid recurrence selected.', 'wpopt'), 'error');
            return;
        }

        if (false === $scheduled) {
            $this->add_cron_notice(__('Event was not scheduled. WordPress may already have an identical event at that timestamp.', 'wpopt'), 'error');
            return;
        }

        $this->add_cron_notice(__('Cron event scheduled.', 'wpopt'), 'updated');
    }

    private function run_event(): void
    {
        $event = $this->get_posted_event();

        if (!$event) {
            $this->add_cron_notice(__('Cron event not found.', 'wpopt'), 'error');
            return;
        }

        if (!has_action($event['hook'])) {
            $this->add_cron_notice(__('This event has no registered callback in the current request, so it cannot be run safely from the manager.', 'wpopt'), 'error');
            return;
        }

        do_action_ref_array($event['hook'], array_values($event['args']));

        $this->add_cron_notice(__('Cron event executed. Its original schedule was preserved.', 'wpopt'), 'updated');
    }

    private function delete_event(): void
    {
        $event = $this->get_posted_event();

        if (!$event) {
            $this->add_cron_notice(__('Cron event not found.', 'wpopt'), 'error');
            return;
        }

        $deleted = wp_unschedule_event($event['timestamp'], $event['hook'], $event['args']);

        if (!$deleted) {
            $this->add_cron_notice(__('Cron event could not be deleted.', 'wpopt'), 'error');
            return;
        }

        $this->add_cron_notice(__('Cron event deleted.', 'wpopt'), 'updated');
    }

    private function reschedule_event(): void
    {
        $event = $this->get_posted_event();

        if (!$event) {
            $this->add_cron_notice(__('Cron event not found.', 'wpopt'), 'error');
            return;
        }

        $new_timestamp = $this->parse_local_datetime((string)wp_unslash($_POST['new_timestamp'] ?? ''));
        $new_recurrence = sanitize_key(wp_unslash($_POST['new_recurrence'] ?? 'single'));
        $new_hook = $this->sanitize_hook((string)wp_unslash($_POST['new_hook'] ?? $event['hook']));
        $raw_new_args = (string)wp_unslash($_POST['new_args'] ?? '');
        $new_args = $event['args'];

        if (!$new_timestamp) {
            $this->add_cron_notice(__('Use a valid date and time.', 'wpopt'), 'error');
            return;
        }

        if ('' === $new_hook || !$this->is_valid_hook($new_hook)) {
            $this->add_cron_notice(__('Use a valid hook name. Allowed characters are letters, numbers, underscore, dash, dot, slash and colon.', 'wpopt'), 'error');
            return;
        }

        if ('single' !== $new_recurrence && !isset(wp_get_schedules()[$new_recurrence])) {
            $this->add_cron_notice(__('Invalid recurrence selected.', 'wpopt'), 'error');
            return;
        }

        if ('' !== trim($raw_new_args) && trim($raw_new_args) !== $this->format_args_json($event['args'])) {
            $new_args = $this->parse_json_args($raw_new_args, $valid_args);

            if (!$valid_args) {
                $this->add_cron_notice(__('Arguments must be a valid JSON array.', 'wpopt'), 'error');
                return;
            }
        }

        $original = $event;
        $deleted = wp_unschedule_event($event['timestamp'], $event['hook'], $event['args']);

        if (!$deleted) {
            $this->add_cron_notice(__('Cron event could not be rescheduled.', 'wpopt'), 'error');
            return;
        }

        if ('single' === $new_recurrence) {
            $scheduled = wp_schedule_single_event($new_timestamp, $new_hook, $new_args);
        }
        else {
            $scheduled = wp_schedule_event($new_timestamp, $new_recurrence, $new_hook, $new_args);
        }

        if (false === $scheduled) {
            $this->restore_event($original);
            $this->add_cron_notice(__('Cron event could not be rescheduled. The original event was restored.', 'wpopt'), 'error');
            return;
        }

        $this->add_cron_notice(__('Cron event updated.', 'wpopt'), 'updated');
    }

    private function add_schedule(): void
    {
        $slug = sanitize_key(wp_unslash($_POST['schedule_slug'] ?? ''));
        $display = sanitize_text_field(wp_unslash($_POST['schedule_display'] ?? ''));
        $interval = absint(wp_unslash($_POST['schedule_interval'] ?? 0));

        if ('' === $slug || '' === $display || $interval < MINUTE_IN_SECONDS) {
            $this->add_cron_notice(__('Custom schedules need a slug, label and interval of at least 60 seconds.', 'wpopt'), 'error');
            return;
        }

        $schedules = wp_get_schedules();
        $custom_schedules = $this->get_custom_schedules();

        if (isset($schedules[$slug]) && !isset($custom_schedules[$slug])) {
            $this->add_cron_notice(__('This schedule slug is reserved by WordPress or another plugin.', 'wpopt'), 'error');
            return;
        }

        $custom_schedules[$slug] = array(
            'interval' => $interval,
            'display'  => $display,
        );

        if (!$this->save_custom_schedules($custom_schedules)) {
            $this->add_cron_notice(__('Custom schedule could not be saved.', 'wpopt'), 'error');
            return;
        }

        $this->add_cron_notice(__('Custom schedule saved.', 'wpopt'), 'updated');
    }

    private function delete_schedule(): void
    {
        $slug = sanitize_key(wp_unslash($_POST['schedule_slug'] ?? ''));
        $custom_schedules = $this->get_custom_schedules();

        if (!isset($custom_schedules[$slug])) {
            $this->add_cron_notice(__('Custom schedule not found.', 'wpopt'), 'error');
            return;
        }

        unset($custom_schedules[$slug]);

        if (!$this->save_custom_schedules($custom_schedules)) {
            $this->add_cron_notice(__('Custom schedule could not be deleted.', 'wpopt'), 'error');
            return;
        }

        $this->add_cron_notice(__('Custom schedule deleted. Events already using it should be rescheduled before the next run.', 'wpopt'), 'updated');
    }

    private function get_posted_event(): array
    {
        $timestamp = absint(wp_unslash($_POST['event_timestamp'] ?? 0));
        $hook = $this->sanitize_hook((string)wp_unslash($_POST['event_hook'] ?? ''));
        $signature = sanitize_text_field(wp_unslash($_POST['event_signature'] ?? ''));

        return $this->get_event($timestamp, $hook, $signature);
    }

    private function get_event(int $timestamp, string $hook, string $signature): array
    {
        $crons = (array)_get_cron_array();

        if (!isset($crons[$timestamp][$hook][$signature])) {
            return array();
        }

        $event = $crons[$timestamp][$hook][$signature];
        $args = is_array($event['args'] ?? null) ? $event['args'] : array();

        return array(
            'timestamp' => $timestamp,
            'hook'      => $hook,
            'signature' => $signature,
            'schedule'  => $event['schedule'] ?? '',
            'args'      => $args,
            'interval'  => absint($event['interval'] ?? 0),
        );
    }

    private function restore_event(array $event): void
    {
        if (empty($event['schedule'])) {
            wp_schedule_single_event($event['timestamp'], $event['hook'], $event['args']);
            return;
        }

        wp_schedule_event($event['timestamp'], $event['schedule'], $event['hook'], $event['args']);
    }

    private function get_cron_events(string $search = ''): array
    {
        $crons = (array)_get_cron_array();
        $events = array();
        $search = strtolower(trim($search));
        $now = time();

        foreach ($crons as $timestamp => $hooks) {
            $timestamp = (int)$timestamp;

            foreach ((array)$hooks as $hook => $instances) {
                $hook = (string)$hook;

                foreach ((array)$instances as $signature => $event) {
                    $args = is_array($event['args'] ?? null) ? $event['args'] : array();
                    $args_json = $this->format_args_json($args);

                    if ('' !== $search && false === stripos($hook . ' ' . $args_json, $search)) {
                        continue;
                    }

                    $events[] = array(
                        'timestamp'      => $timestamp,
                        'hook'           => $hook,
                        'signature'      => (string)$signature,
                        'schedule'       => (string)($event['schedule'] ?? ''),
                        'args'           => $args,
                        'args_json'      => $args_json,
                        'interval'       => absint($event['interval'] ?? 0),
                        'callback_found' => $this->has_callback($hook),
                        'is_due'         => $timestamp <= $now,
                    );
                }
            }
        }

        usort($events, static function ($a, $b) {
            return $a['timestamp'] <=> $b['timestamp'];
        });

        return $events;
    }

    private function get_event_summary(array $events): array
    {
        $summary = array(
            'total'             => count($events),
            'due'               => 0,
            'recurring'         => 0,
            'single'            => 0,
            'missing_callbacks' => 0,
            'next_event'        => null,
        );

        foreach ($events as $event) {
            $summary['due'] += $event['is_due'] ? 1 : 0;
            $summary['recurring'] += $event['schedule'] ? 1 : 0;
            $summary['single'] += $event['schedule'] ? 0 : 1;
            $summary['missing_callbacks'] += $event['callback_found'] ? 0 : 1;

            if (!$summary['next_event'] && !$event['is_due']) {
                $summary['next_event'] = $event;
            }
        }

        return $summary;
    }

    private function render_summary(array $summary): string
    {
        $next_event = $summary['next_event'];

        ob_start();
        ?>
        <style>
            .wpopt-cron-manager {
                color:#172033;
                display:flex;
                flex-direction:column;
                gap:16px;
            }

            .wpopt-cron-manager > .wpopt-cron-summary-block,
            .wpopt-cron-manager > .wpopt-cron-schedules-block {
                display:block;
                margin:0;
                padding:0;
            }

            .wpopt-cron-manager > .wpopt-cron-events-block {
                display:flex;
                flex-direction:column;
                gap:14px;
                margin:0;
                padding:0;
            }

            .wpopt-cron-kpis {
                display:grid;
                grid-template-columns:repeat(auto-fit, minmax(190px, 1fr));
                gap:16px;
                margin:0 0 14px;
            }

            .wpopt-cron-kpi {
                position:relative;
                overflow:hidden;
                min-height:82px;
                padding:18px 20px 18px 22px;
                border:1px solid #d8e5f3;
                border-radius:14px;
                background:linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
                box-shadow:0 12px 26px rgba(15, 44, 82, .07);
            }

            .wpopt-cron-kpi:before {
                content:"";
                position:absolute;
                inset:0 auto 0 0;
                width:4px;
                background:linear-gradient(180deg, #2271b1, #72aee6);
            }

            .wpopt-cron-kpi:nth-child(2):before { background:linear-gradient(180deg, #d97706, #fbbf24); }
            .wpopt-cron-kpi:nth-child(3):before { background:linear-gradient(180deg, #0f766e, #2dd4bf); }
            .wpopt-cron-kpi:nth-child(4):before { background:linear-gradient(180deg, #b42318, #f87171); }

            .wpopt-cron-kpi span {
                display:block;
                color:#607086;
                font-size:11px;
                font-weight:800;
                letter-spacing:.05em;
                line-height:1.2;
                text-transform:uppercase;
                margin-bottom:10px;
            }

            .wpopt-cron-kpi strong {
                display:block;
                color:#0f172a;
                font-size:29px;
                font-weight:800;
                line-height:1;
            }

            .wpopt-cron-health {
                display:flex;
                gap:9px;
                flex-wrap:wrap;
                margin:10px 0 0;
                padding-bottom:0;
            }

            .wpopt-cron-badge {
                display:inline-flex;
                align-items:center;
                justify-content:center;
                max-width:100%;
                min-height:26px;
                padding:4px 10px;
                border:1px solid transparent;
                border-radius:999px;
                font-size:12px;
                font-weight:700;
                line-height:1.3;
                background:#eef2f7;
                color:#475569;
                white-space:nowrap;
            }

            .wpopt-cron-badge.is-ok { border-color:#bbf7d0; background:#dcfce7; color:#166534; }
            .wpopt-cron-badge.is-warn { border-color:#fde68a; background:#fffbeb; color:#92400e; }
            .wpopt-cron-badge.is-bad { border-color:#fecaca; background:#fff1f2; color:#991b1b; }

            .wpopt-cron-events-header {
                display:flex;
                flex-wrap:wrap;
                align-items:center;
                justify-content:space-between;
                gap:20px;
                margin:0;
                padding:0 0 8px;
                border-bottom:1px solid #edf2f7;
            }

            .wpopt-cron-events-title {
                display:flex;
                align-items:center;
                gap:14px;
                min-width:260px;
            }

            .wpopt-cron-events-title-icon,
            .wpopt-cron-row-icon {
                display:inline-flex;
                align-items:center;
                justify-content:center;
                flex:0 0 auto;
                border:1px solid #d7e7fb;
                background:#eef6ff;
                color:#1d4ed8;
            }

            .wpopt-cron-events-title-icon {
                width:44px;
                height:44px;
                border-radius:12px;
                box-shadow:0 8px 18px rgba(29, 78, 216, .09);
            }

            .wpopt-cron-events-title h2 {
                margin:0;
                padding:0;
                border:0;
                color:#0f172a;
                font-size:20px;
                font-weight:800;
                line-height:1.2;
            }

            .wpopt-cron-events-title p {
                margin:5px 0 0;
                color:#64748b;
                font-size:13px;
            }

            .wpopt-cron-toolbar {
                flex:1 1 520px;
                min-width:0;
                margin:0;
            }

            .wpopt-cron-toolbar .search-box {
                display:flex;
                align-items:center;
                justify-content:flex-end;
                gap:10px;
                float:none;
                margin:0;
                width:100%;
            }

            .wpopt-cron-toolbar input[type="search"] {
                flex:1 1 280px;
                max-width:360px;
                box-sizing:border-box;
                height:40px;
                min-height:40px;
                margin:0;
                padding:0 14px;
                border-color:#d6dee8;
                border-radius:9px;
                background:#fff;
                box-shadow:0 1px 2px rgba(15, 23, 42, .03);
                line-height:38px;
            }

            .wpopt-cron-toolbar input[type="search"]:focus {
                border-color:#2271b1;
                box-shadow:0 0 0 3px rgba(34, 113, 177, .14);
                outline:2px solid transparent;
            }

            .wpopt-cron-toolbar .button {
                box-sizing:border-box;
                height:40px;
                min-height:40px;
                margin:0;
                padding:0 18px;
                border-radius:9px;
                background:#0b57d0;
                border-color:#0b57d0;
                color:#fff;
                font-weight:700;
                box-shadow:0 8px 16px rgba(11, 87, 208, .18);
            }

            .wpopt-cron-toolbar .button:hover,
            .wpopt-cron-toolbar .button:focus {
                background:#0649b8;
                border-color:#0649b8;
                color:#fff;
            }

            .wpopt-cron-toolbar .wpopt-cron-create-button {
                display:inline-flex;
                align-items:center;
                gap:7px;
                text-decoration:none;
                white-space:nowrap;
            }

            .wpopt-cron-toolbar .wpopt-cron-create-button .dashicons {
                font-size:16px;
                width:16px;
                height:16px;
                line-height:16px;
            }

            .wpopt-cron-events {
                display:flex;
                flex-direction:column;
                gap:14px;
                margin:0;
            }

            .wpopt-cron-event-row {
                position:relative;
                padding:0;
                border:1px solid #dce6f1;
                border-radius:14px;
                background:#fff;
                box-shadow:0 10px 24px rgba(15, 44, 82, .06);
                overflow:hidden;
                transition:border-color .16s ease, box-shadow .16s ease;
            }

            .wpopt-cron-event-row:before {
                content:"";
                position:absolute;
                top:16px;
                bottom:16px;
                left:0;
                width:4px;
                border-radius:0 999px 999px 0;
                background:#2271b1;
                opacity:.78;
            }

            .wpopt-cron-event-row:hover {
                border-color:#bdd4ed;
                box-shadow:0 16px 32px rgba(15, 44, 82, .10);
            }

            .wpopt-cron-event-row.is-due {
                border-color:#f2d184;
                background:#fffdf7;
            }

            .wpopt-cron-event-row.is-due:before {
                background:#d97706;
            }

            .wpopt-cron-event-summary {
                display:flex;
                flex-wrap:wrap;
                gap:16px;
                align-items:center;
                padding:18px 18px 16px 20px;
                cursor:pointer;
                list-style:none;
            }

            .wpopt-cron-event-summary > * {
                min-width:0;
            }

            .wpopt-cron-event-summary > .wpopt-cron-row-icon {
                flex:0 0 36px;
            }

            .wpopt-cron-event-summary > div:nth-of-type(1) {
                flex:1 1 160px;
            }

            .wpopt-cron-event-summary > div:nth-of-type(2) {
                flex:1 1 190px;
            }

            .wpopt-cron-event-summary > div:nth-of-type(3) {
                flex:1.1 1 180px;
            }

            .wpopt-cron-event-summary > div:nth-of-type(4) {
                flex:.8 1 130px;
            }

            .wpopt-cron-event-summary > .wpopt-cron-status {
                flex:1 1 210px;
            }

            .wpopt-cron-event-summary::-webkit-details-marker {
                display:none;
            }

            .wpopt-cron-event-summary::marker {
                content:"";
            }

            .wpopt-cron-row-icon {
                width:36px;
                height:36px;
                border-radius:10px;
            }

            .wpopt-cron-cell-label {
                display:block;
                color:#64748b;
                font-size:10px;
                font-weight:800;
                letter-spacing:.04em;
                line-height:1.2;
                text-transform:uppercase;
                margin-bottom:6px;
            }

            .wpopt-cron-time strong {
                display:block;
                color:#0f172a;
                line-height:1.35;
            }

            .wpopt-cron-time small {
                display:block;
                margin-top:4px;
                color:#64748b;
                font-size:12px;
            }

            .wpopt-cron-hook {
                display:inline-block;
                max-width:100%;
                padding:5px 8px;
                border:1px solid #d8e2ed;
                border-radius:7px;
                background:#f8fafc;
                color:#0f172a;
                overflow-wrap:anywhere;
                white-space:normal;
            }

            .wpopt-cron-status {
                display:flex;
                align-items:flex-start;
                gap:7px;
                flex-wrap:wrap;
            }

            .wpopt-cron-status .wpopt-cron-cell-label {
                flex:0 0 100%;
            }

            .wpopt-cron-chevron {
                display:flex;
                flex:0 0 24px;
                align-items:center;
                justify-content:center;
                margin-left:auto;
                color:#475569;
            }

            .wpopt-cron-event-row[open] .wpopt-cron-chevron {
                color:#0b57d0;
            }

            .wpopt-cron-schedule {
                display:flex;
                align-items:center;
                gap:7px;
                color:#172033;
            }

            .wpopt-cron-schedule .dashicons {
                color:#0b57d0;
                font-size:16px;
                width:16px;
                height:16px;
            }

            .wpopt-cron-event-actions {
                position:relative;
                padding:14px 18px 16px 62px;
                border-top:1px solid #edf2f7;
                border-radius:0 0 14px 14px;
                background:linear-gradient(180deg, #fbfdff 0%, #f8fafc 100%);
            }

            .wpopt-cron-event-actions:has(.wpopt-cron-edit[open]) {
                padding-bottom:178px;
            }

            .wpopt-cron-actions {
                display:grid;
                grid-template-columns:minmax(190px, 240px) minmax(220px, 300px) minmax(220px, 1fr) auto;
                gap:12px;
                align-items:end;
                grid-template-areas:"date schedule edit buttons";
            }

            .wpopt-cron-control {
                display:flex;
                flex-direction:column;
                gap:6px;
            }

            .wpopt-cron-control label {
                color:#475569;
                font-size:11px;
                font-weight:800;
            }

            .wpopt-cron-actions input[type="datetime-local"],
            .wpopt-cron-actions select,
            .wpopt-cron-form-grid input,
            .wpopt-cron-form-grid select,
            .wpopt-cron-form-grid textarea {
                width:100%;
                min-height:38px;
                border-color:#cbd6e2;
                border-radius:8px;
                background:#fff;
                box-shadow:0 1px 2px rgba(15, 23, 42, .03);
            }

            .wpopt-cron-actions input[type="datetime-local"]:focus,
            .wpopt-cron-actions select:focus,
            .wpopt-cron-edit input:focus,
            .wpopt-cron-edit textarea:focus,
            .wpopt-cron-form-grid input:focus,
            .wpopt-cron-form-grid select:focus,
            .wpopt-cron-form-grid textarea:focus {
                border-color:#2271b1;
                box-shadow:0 0 0 3px rgba(34, 113, 177, .14);
                outline:2px solid transparent;
            }

            .wpopt-cron-control.is-date { grid-area:date; }
            .wpopt-cron-control.is-schedule { grid-area:schedule; }

            .wpopt-cron-action-buttons {
                grid-area:buttons;
                display:flex;
                gap:10px;
                flex-wrap:nowrap;
            }

            .wpopt-cron-action-buttons .wps-button {
                display:inline-flex;
                align-items:center;
                justify-content:center;
                gap:6px;
                min-width:84px;
                min-height:38px;
                padding:0 13px;
                line-height:36px;
                text-align:center;
            }

            .wpopt-cron-action-buttons .dashicons,
            .wpopt-cron-edit summary .dashicons {
                font-size:16px;
                width:16px;
                height:16px;
                line-height:16px;
            }

            .wpopt-cron-action-buttons .wpopt-btn {
                box-shadow:none !important;
                border-radius:8px;
                font-weight:700;
                transition:background-color .12s ease, border-color .12s ease, color .12s ease, box-shadow .12s ease;
            }

            .wpopt-cron-action-buttons .wpopt-btn.is-info {
                background:var(--wps-btn-info-bg, #2271b1);
                border:1px solid var(--wps-btn-info-border, #135e96);
                color:var(--wps-btn-info-text, #ffffff);
            }

            .wpopt-cron-action-buttons .wpopt-btn.is-info:hover {
                background:var(--wps-btn-info-bg-hover, #1c67a4);
                border-color:var(--wps-btn-info-border-hover, #0f5a90);
                color:var(--wps-btn-info-text, #ffffff);
                box-shadow:var(--wps-btn-info-shadow-hover, 0 8px 16px rgb(19 94 150 / 28%)) !important;
            }

            .wpopt-cron-action-buttons .wpopt-btn.is-success {
                background:var(--wps-btn-success-bg, #16a34a);
                border:1px solid var(--wps-btn-success-border, #166534);
                color:var(--wps-btn-success-text, #ffffff);
            }

            .wpopt-cron-action-buttons .wpopt-btn.is-success:hover {
                background:#15803d;
                border-color:#14532d;
                color:var(--wps-btn-success-text, #ffffff);
                box-shadow:0 8px 16px rgb(21 128 61 / 26%) !important;
            }

            .wpopt-cron-action-buttons .wpopt-btn.is-danger {
                background:var(--wps-btn-danger-bg, #b54d33);
                border:1px solid var(--wps-btn-danger-border, #7a2f1f);
                color:var(--wps-btn-danger-text, #ffffff);
            }

            .wpopt-cron-action-buttons .wpopt-btn.is-danger:hover {
                background:#963c28;
                border-color:#6f2a1d;
                color:var(--wps-btn-danger-text, #ffffff);
                box-shadow:0 8px 16px rgb(140 55 36 / 28%) !important;
            }

            .wpopt-cron-action-buttons .wpopt-btn:disabled {
                background:#f1f5f9;
                border-color:#dce3eb;
                color:#94a3b8;
                box-shadow:none;
            }

            .wpopt-cron-edit {
                grid-area:edit;
                position:static;
                width:100%;
                min-width:0;
                padding:8px 11px;
                border:1px solid #d8e2ed;
                border-radius:8px;
                background:#fff;
                box-sizing:border-box;
            }

            .wpopt-cron-edit:hover {
                border-color:#b9c9db;
                background:#fff;
            }

            .wpopt-cron-edit summary {
                display:flex;
                align-items:center;
                gap:7px;
                cursor:pointer;
                color:#135e96;
                font-weight:700;
                line-height:20px;
            }

            .wpopt-cron-edit[open] summary {
                color:#0f172a;
            }

            .wpopt-cron-edit-fields {
                position:absolute;
                top:82px;
                left:62px;
                right:18px;
                display:grid;
                grid-template-columns:minmax(220px, .8fr) minmax(360px, 1.2fr);
                gap:12px;
                padding:16px;
                border:1px solid #d7e1ec;
                border-radius:12px;
                background:#fff;
                box-shadow:0 14px 32px rgba(15, 23, 42, .12);
                box-sizing:border-box;
            }

            .wpopt-cron-edit-fields:before {
                content:"";
                position:absolute;
                top:-6px;
                left:calc(190px + 300px + 28px);
                width:10px;
                height:10px;
                transform:rotate(45deg);
                background:#fff;
                border-left:1px solid #d7e1ec;
                border-top:1px solid #d7e1ec;
            }

            .wpopt-cron-edit-fields label {
                display:flex;
                flex-direction:column;
                gap:6px;
                color:#172033;
                font-weight:700;
            }

            .wpopt-cron-edit-fields input,
            .wpopt-cron-edit-fields textarea {
                border-color:#cbd6e2;
                border-radius:8px;
                background:#fff;
            }

            .wpopt-cron-edit-fields textarea {
                min-height:96px;
                font-family:Consolas, Monaco, monospace;
                resize:vertical;
            }

            .wpopt-cron-args {
                max-width:100%;
                max-height:62px;
                margin:0;
                padding:7px 9px;
                overflow:auto;
                white-space:pre-wrap;
                font-size:12px;
                line-height:1.35;
                background:#f8fafc;
                border:1px solid #d8e2ed;
                border-radius:7px;
            }

            .wpopt-cron-empty {
                padding:22px;
                border:1px dashed #cbd5e1;
                border-radius:12px;
                background:#f8fafc;
                color:#64748b;
                font-weight:600;
            }

            .wpopt-cron-form-grid {
                display:grid;
                grid-template-columns:repeat(2, minmax(220px, 1fr));
                gap:16px;
                max-width:860px;
            }

            .wpopt-cron-form-grid label {
                display:flex;
                flex-direction:column;
                gap:7px;
                color:#172033;
                font-weight:700;
            }

            .wpopt-cron-form-grid textarea {
                min-height:126px;
                font-family:Consolas, Monaco, monospace;
            }

            .wpopt-cron-full {
                grid-column:1 / -1;
                display:flex;
                align-items:center;
                gap:12px;
                flex-wrap:wrap;
            }

            .wpopt-cron-muted { color:#64748b; }

            html:has(#wpopt-cron-create-event:target),
            body:has(#wpopt-cron-create-event:target) { overflow:hidden; }
            body:has(#wpopt-cron-create-event:target) #wpwrap { height:100vh; overflow:hidden; }

            .wpopt-cron-modal {
                display:none;
                position:fixed;
                inset:0;
                width:100vw;
                height:100vh;
                place-items:center;
                padding:clamp(16px, 4vh, 40px);
                overflow:hidden;
                z-index:2147483000;
            }

            .wpopt-cron-modal:target { display:grid; }
            .wpopt-cron-modal-backdrop { position:fixed; inset:0; background:rgba(15, 23, 42, .58); backdrop-filter:blur(2px); }

            .wpopt-cron-modal-panel {
                position:relative;
                z-index:1;
                width:min(920px, calc(100vw - 32px));
                max-height:min(760px, calc(100vh - 64px));
                overflow:auto;
                margin:auto;
                padding:0 26px 26px;
                border:1px solid #d9e5f1;
                border-radius:16px;
                background:#fff;
                box-shadow:0 28px 80px rgba(15, 23, 42, .34);
            }

            .wpopt-cron-modal-head {
                position:sticky;
                top:0;
                z-index:2;
                display:flex;
                align-items:center;
                justify-content:space-between;
                gap:16px;
                margin:0 -26px 22px;
                padding:19px 26px;
                border-bottom:1px solid #d9e5f1;
                background:#fff;
            }

            .wpopt-cron-modal-head h2 {
                margin:0;
                padding:0;
                border:0;
                color:#0f172a;
                font-size:20px;
                font-weight:800;
                line-height:1.25;
            }

            .wpopt-cron-modal-close {
                display:inline-flex;
                align-items:center;
                justify-content:center;
                width:36px;
                height:36px;
                border:1px solid #cbd5e1;
                border-radius:9px;
                background:#f8fafc;
                color:#172033;
                text-decoration:none;
            }

            .wpopt-cron-modal-close:hover,
            .wpopt-cron-modal-close:focus {
                background:#eef4ff;
                border-color:#aac8f0;
                color:#0b57d0;
            }

            .wpopt-cron-modal .wpopt-cron-form-grid { max-width:none; }

            .wpopt-cron-schedules-wrap {
                width:100%;
                max-width:none;
                margin-right:auto;
                margin-left:0;
                overflow:hidden;
                border:1px solid #d7e4f2;
                border-radius:12px;
                background:#ffffff;
                box-shadow:0 10px 26px rgba(15, 44, 82, .07);
                box-sizing:border-box;
            }

            .wpopt-cron-schedules {
                table-layout:fixed;
                width:100%;
                margin:0;
                overflow:hidden;
                border:0;
                border-radius:0;
                box-shadow:none;
                border-collapse:separate;
                border-spacing:0;
            }

            .wpopt-cron-schedules,
            .wpopt-cron-schedules *,
            .wpopt-cron-schedules-wrap + h3 + .wpopt-cron-actions,
            .wpopt-cron-schedules-wrap + h3 + .wpopt-cron-actions * {
                box-sizing:border-box;
            }

            .wpopt-cron-schedules thead th {
                padding:13px 14px;
                border:0;
                border-bottom:1px solid #d7e4f2;
                background:#f5f8fc;
                color:#3f536c;
                font-size:11px;
                font-weight:800;
                letter-spacing:.04em;
                text-transform:uppercase;
            }

            .wpopt-cron-schedules tbody td {
                padding:13px 14px;
                border:0;
                border-bottom:1px solid #edf2f7;
                color:#263247;
                vertical-align:middle;
                overflow-wrap:anywhere;
            }

            .wpopt-cron-schedules tbody tr:nth-child(even) td {
                background:#fbfdff;
            }

            .wpopt-cron-schedules tbody tr:hover td {
                background:#f3f8ff;
            }

            .wpopt-cron-schedules tbody tr:last-child td {
                border-bottom:0;
            }

            .wpopt-cron-schedules th:nth-child(1),
            .wpopt-cron-schedules td:nth-child(1) { width:26%; }
            .wpopt-cron-schedules th:nth-child(2),
            .wpopt-cron-schedules td:nth-child(2) { width:38%; }
            .wpopt-cron-schedules th:nth-child(3),
            .wpopt-cron-schedules td:nth-child(3) { width:13%; }
            .wpopt-cron-schedules th:nth-child(4),
            .wpopt-cron-schedules td:nth-child(4) { width:13%; }
            .wpopt-cron-schedules th:nth-child(5),
            .wpopt-cron-schedules td:nth-child(5) { width:10%; text-align:right; }

            .wpopt-cron-schedules code {
                display:inline-block;
                max-width:100%;
                padding:5px 7px;
                border:1px solid #dce6f2;
                border-radius:7px;
                background:#f6f8fb;
                color:#172033;
                font-size:12px;
                line-height:1.25;
                white-space:normal;
                overflow-wrap:anywhere;
            }

            .wpopt-cron-schedule-label {
                display:block;
                max-width:100%;
                color:#172033;
                line-height:1.35;
                overflow-wrap:anywhere;
            }

            .wpopt-cron-pill {
                display:inline-flex;
                align-items:center;
                max-width:100%;
                min-height:28px;
                padding:0 10px;
                border:1px solid #d8e6f5;
                border-radius:999px;
                background:#f8fbff;
                color:#2f5278;
                font-size:12px;
                font-weight:800;
                line-height:1;
                white-space:normal;
            }

            .wpopt-cron-pill.is-source-custom {
                border-color:#b7dfc3;
                background:#effaf2;
                color:#166534;
            }

            .wpopt-cron-pill.is-readonly {
                border-color:#d9e2ec;
                background:#f8fafc;
                color:#52637a;
            }

            .wpopt-cron-schedules td form {
                margin:0;
            }

            .wpopt-cron-schedules-wrap + h3 {
                width:100%;
                max-width:1120px;
                margin:22px 0 12px;
                color:#0f172a;
                font-size:16px;
                font-weight:800;
            }

            .wpopt-cron-schedules-wrap + h3 + .wpopt-cron-actions {
                display:grid;
                grid-template-columns:minmax(0, 1fr) minmax(0, 1.2fr) minmax(120px, .55fr) auto;
                grid-template-areas:none;
                gap:12px;
                align-items:center;
                padding:16px;
                width:100%;
                max-width:1120px;
                border:1px solid #d7e4f2;
                border-radius:12px;
                background:#f8fbff;
                box-sizing:border-box;
            }

            .wpopt-cron-schedules-wrap + h3 + .wpopt-cron-actions input {
                width:100%;
                min-width:0;
                min-height:38px;
                border-color:#cbd6e2;
                border-radius:8px;
                background:#fff;
                box-sizing:border-box;
            }

            .wpopt-cron-schedules-wrap + h3 + .wpopt-cron-actions .wpopt-btn {
                width:100%;
                min-height:38px;
                border-radius:8px;
                font-weight:700;
                white-space:nowrap;
            }

            @media (max-width: 1280px) {
                .wpopt-cron-actions { grid-template-columns:minmax(180px, 1fr) minmax(200px, 1fr) minmax(220px, 1fr); grid-template-areas:"date schedule buttons" "edit edit edit"; }
                .wpopt-cron-edit-fields { top:126px; left:62px; right:18px; }
                .wpopt-cron-edit-fields:before { left:24px; }
                .wpopt-cron-event-actions:has(.wpopt-cron-edit[open]) { padding-bottom:218px; }
            }

            @media (max-width: 960px) {
                .wpopt-cron-events-header { align-items:flex-start; }
                .wpopt-cron-toolbar, .wpopt-cron-toolbar .search-box, .wpopt-cron-toolbar input[type="search"] { width:100%; max-width:none; }
                .wpopt-cron-toolbar .search-box { flex-wrap:wrap; justify-content:flex-start; }
                .wpopt-cron-toolbar .wpopt-cron-create-button { justify-content:center; }
                .wpopt-cron-schedules-wrap + h3 + .wpopt-cron-actions { grid-template-columns:1fr 1fr; }
                .wpopt-cron-schedules th:nth-child(1),
                .wpopt-cron-schedules td:nth-child(1) { width:30%; }
                .wpopt-cron-schedules th:nth-child(2),
                .wpopt-cron-schedules td:nth-child(2) { width:34%; }
                .wpopt-cron-schedules th:nth-child(3),
                .wpopt-cron-schedules td:nth-child(3) { width:14%; }
                .wpopt-cron-schedules th:nth-child(4),
                .wpopt-cron-schedules td:nth-child(4) { width:12%; }
                .wpopt-cron-schedules th:nth-child(5),
                .wpopt-cron-schedules td:nth-child(5) { width:10%; }
                .wpopt-cron-chevron { display:none; }
                .wpopt-cron-event-actions { padding-left:18px; }
                .wpopt-cron-actions { grid-template-columns:1fr 1fr; grid-template-areas:"date schedule" "edit edit" "buttons buttons"; }
                .wpopt-cron-edit-fields { top:144px; left:18px; right:18px; }
                .wpopt-cron-event-actions:has(.wpopt-cron-edit[open]) { padding-bottom:238px; }
            }

            @media (max-width: 782px) {
                .wpopt-cron-kpis { grid-template-columns:1fr; }
                .wpopt-cron-form-grid { grid-template-columns:1fr; }
                .wpopt-cron-modal { padding:16px; }
                .wpopt-cron-modal-panel { max-height:calc(100vh - 32px); padding-right:18px; padding-left:18px; }
                .wpopt-cron-modal-head { margin-right:-18px; margin-left:-18px; padding-right:18px; padding-left:18px; }
                .wpopt-cron-events-header {
                    gap:14px;
                }
                .wpopt-cron-events-title {
                    min-width:0;
                    width:100%;
                    gap:12px;
                }
                .wpopt-cron-events-title h2 { font-size:19px; }
                .wpopt-cron-events-title p { line-height:1.35; }
                .wpopt-cron-toolbar .search-box {
                    display:grid;
                    grid-template-columns:1fr auto;
                    gap:10px;
                }
                .wpopt-cron-toolbar .wpopt-cron-create-button {
                    grid-column:1 / -1;
                    width:100%;
                }
                .wpopt-cron-toolbar input[type="search"] {
                    min-width:0;
                }
                .wpopt-cron-toolbar .button:not(.wpopt-cron-create-button) {
                    width:auto;
                    padding:0 14px;
                }
                .wpopt-cron-events {
                    gap:12px;
                }
                .wpopt-cron-event-row {
                    border-radius:12px;
                }
                .wpopt-cron-event-row:before {
                    top:12px;
                    bottom:12px;
                }
                .wpopt-cron-event-summary {
                    gap:12px;
                    align-items:start;
                    padding:16px 14px 14px 18px;
                }
                .wpopt-cron-event-summary > div {
                    flex:1 1 100%;
                    min-width:0;
                }
                .wpopt-cron-event-summary > .wpopt-cron-row-icon {
                    flex:0 0 36px;
                }
                .wpopt-cron-event-summary > .wpopt-cron-time {
                    flex:1 1 calc(100% - 48px);
                }
                .wpopt-cron-hook,
                .wpopt-cron-args {
                    display:block;
                    width:100%;
                    box-sizing:border-box;
                }
                .wpopt-cron-hook {
                    overflow-wrap:anywhere;
                    word-break:normal;
                    white-space:normal;
                }
                .wpopt-cron-args {
                    max-height:none;
                    overflow:visible;
                }
                .wpopt-cron-status {
                    align-items:center;
                }
                .wpopt-cron-schedules-wrap + h3 + .wpopt-cron-actions { grid-template-columns:1fr; }
                .wpopt-cron-schedules thead { display:none; }
                .wpopt-cron-schedules,
                .wpopt-cron-schedules tbody,
                .wpopt-cron-schedules tr,
                .wpopt-cron-schedules td { display:block; width:100%; }
                .wpopt-cron-schedules tr { padding:12px 14px; border-bottom:1px solid #edf2f7; }
                .wpopt-cron-schedules tr:last-child { border-bottom:0; }
                .wpopt-cron-schedules tbody td {
                    display:grid;
                    grid-template-columns:96px minmax(0, 1fr);
                    gap:12px;
                    align-items:center;
                    padding:7px 0;
                    border:0;
                    background:transparent !important;
                    text-align:left !important;
                }
                .wpopt-cron-schedules td::before {
                    content:attr(data-label);
                    color:#64748b;
                    font-size:10px;
                    font-weight:800;
                    letter-spacing:.04em;
                    text-transform:uppercase;
                }
                .wpopt-cron-schedules td form { justify-self:start; }
                .wpopt-cron-actions { grid-template-columns:1fr; grid-template-areas:"date" "schedule" "edit" "buttons"; }
                .wpopt-cron-action-buttons { flex-wrap:wrap; }
                .wpopt-cron-action-buttons .wps-button { flex:1 1 120px; }
                .wpopt-cron-event-actions:has(.wpopt-cron-edit[open]) { padding-bottom:268px; }
                .wpopt-cron-edit-fields { top:186px; grid-template-columns:1fr; }
            }

            @media (max-width: 520px) {
                .wpopt-cron-toolbar .search-box {
                    grid-template-columns:1fr;
                }
                .wpopt-cron-toolbar .button:not(.wpopt-cron-create-button) {
                    width:100%;
                }
                .wpopt-cron-event-summary {
                    padding-right:12px;
                    padding-left:16px;
                }
                .wpopt-cron-event-actions {
                    padding-right:14px;
                    padding-left:14px;
                }
            }
        </style>
        <div class="wpopt-cron-kpis">
            <div class="wpopt-cron-kpi"><span><?php _e('Events', 'wpopt'); ?></span><strong><?php echo number_format_i18n($summary['total']); ?></strong></div>
            <div class="wpopt-cron-kpi"><span><?php _e('Due now', 'wpopt'); ?></span><strong><?php echo number_format_i18n($summary['due']); ?></strong></div>
            <div class="wpopt-cron-kpi"><span><?php _e('Recurring', 'wpopt'); ?></span><strong><?php echo number_format_i18n($summary['recurring']); ?></strong></div>
            <div class="wpopt-cron-kpi"><span><?php _e('Missing callbacks', 'wpopt'); ?></span><strong><?php echo number_format_i18n($summary['missing_callbacks']); ?></strong></div>
        </div>
        <div class="wpopt-cron-health">
            <span class="wpopt-cron-badge <?php echo $summary['due'] ? 'is-warn' : 'is-ok'; ?>"><?php echo $summary['due'] ? esc_html__('Some events are overdue', 'wpopt') : esc_html__('No overdue events', 'wpopt'); ?></span>
            <span class="wpopt-cron-badge <?php echo $summary['missing_callbacks'] ? 'is-bad' : 'is-ok'; ?>"><?php echo $summary['missing_callbacks'] ? esc_html__('Callbacks need attention', 'wpopt') : esc_html__('Callbacks loaded', 'wpopt'); ?></span>
            <span class="wpopt-cron-badge"><?php echo $next_event ? esc_html(sprintf(__('Next: %s', 'wpopt'), $this->format_datetime($next_event['timestamp']))) : esc_html__('No upcoming event', 'wpopt'); ?></span>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_event_form(array $schedules): string
    {
        $default_time = $this->format_datetime_input(time() + HOUR_IN_SECONDS);

        ob_start();
        ?>
        <form method="POST" class="wpopt-cron-form-grid">
            <?php wp_nonce_field(self::ACTION_NONCE); ?>
            <input type="hidden" name="wpopt_cron_action" value="create_event">
            <label>
                <?php _e('Hook', 'wpopt'); ?>
                <input type="text" name="event_hook" placeholder="my_plugin_cron_hook" required>
            </label>
            <label>
                <?php _e('First run', 'wpopt'); ?>
                <input type="datetime-local" name="event_timestamp" value="<?php echo esc_attr($default_time); ?>" required>
            </label>
            <label>
                <?php _e('Recurrence', 'wpopt'); ?>
                <?php echo $this->render_schedule_select('event_recurrence', 'single', $schedules); ?>
            </label>
            <label class="wpopt-cron-full">
                <?php _e('Arguments as JSON', 'wpopt'); ?>
                <textarea name="event_args" spellcheck="false">[]</textarea>
            </label>
            <div class="wpopt-cron-full">
                <button type="submit" class="wps wps-button wpopt-btn is-success"><?php _e('Schedule event', 'wpopt'); ?></button>
                <span class="wpopt-cron-muted"><?php _e('Arguments are decoded as JSON and passed to the hook callback in order.', 'wpopt'); ?></span>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    private function render_create_event_modal(array $schedules): string
    {
        ob_start();
        ?>
        <div id="wpopt-cron-create-event" class="wpopt-cron-modal" role="dialog" aria-modal="true" aria-labelledby="wpopt-cron-create-event-title">
            <a class="wpopt-cron-modal-backdrop" href="#" aria-label="<?php esc_attr_e('Close create event dialog', 'wpopt'); ?>"></a>
            <div class="wpopt-cron-modal-panel">
                <div class="wpopt-cron-modal-head">
                    <h2 id="wpopt-cron-create-event-title"><?php _e('Create event', 'wpopt'); ?></h2>
                    <a class="wpopt-cron-modal-close" href="#" aria-label="<?php esc_attr_e('Close create event dialog', 'wpopt'); ?>">&times;</a>
                </div>
                <?php echo $this->render_event_form($schedules); ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_events_table(array $events, array $schedules, string $search): string
    {
        ob_start();
        ?>
        <div class="wpopt-cron-events-header">
            <div class="wpopt-cron-events-title">
                <span class="wpopt-cron-events-title-icon dashicons dashicons-calendar-alt" aria-hidden="true"></span>
                <div>
                    <h2><?php _e('Scheduled events', 'wpopt'); ?></h2>
                    <p><?php _e('Manage and monitor your scheduled hooks and automated tasks.', 'wpopt'); ?></p>
                </div>
            </div>
            <form method="GET" class="wpopt-cron-toolbar">
                <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page'] ?? 'wpopt-cron'); ?>">
                <p class="search-box">
                    <a href="#wpopt-cron-create-event" class="button button-primary wpopt-cron-create-button"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php _e('Create event', 'wpopt'); ?></a>
                    <label class="screen-reader-text" for="wpopt-cron-search-input"><?php _e('Search events', 'wpopt'); ?></label>
                    <input type="search" id="wpopt-cron-search-input" name="s" placeholder="<?php esc_attr_e('Search events', 'wpopt'); ?>" value="<?php echo esc_attr($search); ?>">
                    <?php submit_button(__('Search', 'wpopt'), 'button button-primary', false, false, array('id' => 'search-submit')); ?>
                </p>
            </form>
        </div>
        <div class="wpopt-cron-events">
            <?php if (empty($events)): ?>
                <div class="wpopt-cron-empty"><?php _e('No cron events found.', 'wpopt'); ?></div>
            <?php endif; ?>
            <?php foreach ($events as $event): ?>
                <details class="wpopt-cron-event-row <?php echo $event['is_due'] ? 'is-due' : ''; ?>">
                    <summary class="wpopt-cron-event-summary">
                        <span class="wpopt-cron-row-icon dashicons dashicons-calendar-alt" aria-hidden="true"></span>
                        <div class="wpopt-cron-time">
                            <span class="wpopt-cron-cell-label"><?php _e('Next run', 'wpopt'); ?></span>
                            <strong><?php echo esc_html($this->format_datetime($event['timestamp'])); ?></strong>
                            <small><?php echo esc_html($this->format_relative_time($event['timestamp'])); ?></small>
                        </div>
                        <div>
                            <span class="wpopt-cron-cell-label"><?php _e('Hook', 'wpopt'); ?></span>
                            <code class="wpopt-cron-hook"><?php echo esc_html($event['hook']); ?></code>
                        </div>
                        <div>
                            <span class="wpopt-cron-cell-label"><?php _e('Schedule', 'wpopt'); ?></span>
                            <span class="wpopt-cron-schedule">
                                <span class="dashicons dashicons-update-alt" aria-hidden="true"></span>
                                <?php echo esc_html($this->format_schedule_label($event, $schedules)); ?>
                            </span>
                        </div>
                        <div>
                            <span class="wpopt-cron-cell-label"><?php _e('Arguments', 'wpopt'); ?></span>
                            <pre class="wpopt-cron-args"><?php echo esc_html($event['args_json']); ?></pre>
                        </div>
                        <div class="wpopt-cron-status">
                            <span class="wpopt-cron-cell-label"><?php _e('Status', 'wpopt'); ?></span>
                            <span class="wpopt-cron-badge <?php echo $event['is_due'] ? 'is-warn' : 'is-ok'; ?>"><?php echo $event['is_due'] ? esc_html__('Due', 'wpopt') : esc_html__('Scheduled', 'wpopt'); ?></span>
                            <span class="wpopt-cron-badge <?php echo $event['callback_found'] ? 'is-ok' : 'is-bad'; ?>"><?php echo $event['callback_found'] ? esc_html__('Callback found', 'wpopt') : esc_html__('Missing callback', 'wpopt'); ?></span>
                        </div>
                        <span class="wpopt-cron-chevron dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                    </summary>
                    <div class="wpopt-cron-event-actions">
                        <form method="POST" class="wpopt-cron-actions">
                            <?php wp_nonce_field(self::ACTION_NONCE); ?>
                            <?php echo $this->render_event_identity_fields($event); ?>
                            <div class="wpopt-cron-control is-date">
                                <label><?php _e('Next run', 'wpopt'); ?></label>
                                <input type="datetime-local" name="new_timestamp" value="<?php echo esc_attr($this->format_datetime_input($event['timestamp'])); ?>">
                            </div>
                            <div class="wpopt-cron-control is-schedule">
                                <label><?php _e('Recurrence', 'wpopt'); ?></label>
                                <?php echo $this->render_schedule_select('new_recurrence', $event['schedule'] ?: 'single', $schedules); ?>
                            </div>
                            <details class="wpopt-cron-edit">
                                <summary><span class="dashicons dashicons-edit" aria-hidden="true"></span><?php _e('Edit hook and arguments', 'wpopt'); ?></summary>
                                <div class="wpopt-cron-edit-fields">
                                    <label>
                                        <?php _e('Hook', 'wpopt'); ?>
                                        <input type="text" name="new_hook" value="<?php echo esc_attr($event['hook']); ?>" required>
                                    </label>
                                    <label>
                                        <?php _e('Arguments as JSON array', 'wpopt'); ?>
                                        <textarea name="new_args" spellcheck="false"><?php echo esc_textarea($event['args_json']); ?></textarea>
                                    </label>
                                </div>
                            </details>
                            <div class="wpopt-cron-action-buttons">
                                <button type="submit" name="wpopt_cron_action" value="reschedule_event" class="wps wps-button wpopt-btn is-info"><span class="dashicons dashicons-saved" aria-hidden="true"></span><?php _e('Save', 'wpopt'); ?></button>
                                <button type="submit" name="wpopt_cron_action" value="run_event" class="wps wps-button wpopt-btn is-success" <?php disabled(!$event['callback_found']); ?>><span class="dashicons dashicons-controls-play" aria-hidden="true"></span><?php _e('Run now', 'wpopt'); ?></button>
                                <button type="submit" name="wpopt_cron_action" value="delete_event" class="wps wps-button wpopt-btn is-danger" data-wps-confirm="<?php echo esc_attr__('Delete this cron event?', 'wpopt'); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span><?php _e('Delete', 'wpopt'); ?></button>
                            </div>
                        </form>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_schedules_table(array $schedules, array $custom_schedules): string
    {
        $rows = array();

        foreach ($schedules as $slug => $schedule) {
            $is_custom_schedule = isset($custom_schedules[$slug]);
            $actions = '';

            if ($is_custom_schedule) {
                ob_start();
                ?>
                <form method="POST">
                    <?php wp_nonce_field(self::ACTION_NONCE); ?>
                    <input type="hidden" name="wpopt_cron_action" value="delete_schedule">
                    <input type="hidden" name="schedule_slug" value="<?php echo esc_attr($slug); ?>">
                    <button type="submit" class="wps wps-button wpopt-btn is-danger" data-wps-confirm="<?php echo esc_attr__('Delete this custom schedule?', 'wpopt'); ?>"><?php _e('Delete', 'wpopt'); ?></button>
                </form>
                <?php
                $actions = ob_get_clean();
            }
            else {
                $actions = '<span class="wpopt-cron-pill is-readonly">' . esc_html__('Read only', 'wpopt') . '</span>';
            }

            $rows[] = array(
                'slug' => '<code>' . esc_html($slug) . '</code>',
                'label' => '<span class="wpopt-cron-schedule-label">' . esc_html($schedule['display'] ?? $slug) . '</span>',
                'interval' => '<span class="wpopt-cron-pill">' . esc_html($this->format_interval(absint($schedule['interval'] ?? 0))) . '</span>',
                'source' => '<span class="wpopt-cron-pill ' . ($is_custom_schedule ? 'is-source-custom' : '') . '">' . ($is_custom_schedule ? esc_html__('WP Optimizer', 'wpopt') : esc_html__('WordPress/plugin', 'wpopt')) . '</span>',
                'actions' => $actions,
            );
        }

        ob_start();
        ?>
        <div class="wpopt-cron-schedules-wrap">
            <?php
            echo List_Table::generateHTML_table(array(
                'class' => 'striped wpopt-cron-schedules',
                'columns' => array(
                    'slug' => __('Slug', 'wpopt'),
                    'label' => __('Label', 'wpopt'),
                    'interval' => __('Interval', 'wpopt'),
                    'source' => __('Source', 'wpopt'),
                    'actions' => __('Actions', 'wpopt'),
                ),
                'rows' => $rows,
                'empty' => __('No schedules available.', 'wpopt'),
            ));
            ?>
        </div>
        <h3><?php _e('Add custom schedule', 'wpopt'); ?></h3>
        <form method="POST" class="wpopt-cron-actions">
            <?php wp_nonce_field(self::ACTION_NONCE); ?>
            <input type="hidden" name="wpopt_cron_action" value="add_schedule">
            <input type="text" name="schedule_slug" placeholder="every_10_minutes" required>
            <input type="text" name="schedule_display" placeholder="<?php esc_attr_e('Every 10 minutes', 'wpopt'); ?>" required>
            <input type="number" name="schedule_interval" min="60" step="1" placeholder="600" required>
            <button type="submit" class="wps wps-button wpopt-btn is-success"><?php _e('Add schedule', 'wpopt'); ?></button>
        </form>
        <?php
        return ob_get_clean();
    }

    private function render_event_identity_fields(array $event): string
    {
        return sprintf(
            '<input type="hidden" name="event_timestamp" value="%d"><input type="hidden" name="event_hook" value="%s"><input type="hidden" name="event_signature" value="%s">',
            (int)$event['timestamp'],
            esc_attr($event['hook']),
            esc_attr($event['signature'])
        );
    }

    private function render_schedule_select(string $name, string $selected, array $schedules): string
    {
        ob_start();
        ?>
        <select name="<?php echo esc_attr($name); ?>">
            <option value="single" <?php selected($selected, 'single'); ?>><?php _e('Single event', 'wpopt'); ?></option>
            <?php foreach ($schedules as $slug => $schedule): ?>
                <option value="<?php echo esc_attr($slug); ?>" <?php selected($selected, $slug); ?>>
                    <?php echo esc_html(sprintf('%s (%s)', $schedule['display'] ?? $slug, $this->format_interval(absint($schedule['interval'] ?? 0)))); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
        return ob_get_clean();
    }

    private function get_custom_schedules(): array
    {
        if ($this->custom_schedules !== null) {
            return $this->custom_schedules;
        }

        $module_settings = wps('wpopt')->settings->get($this->slug, array());
        $raw_schedules = $module_settings['custom_schedules'] ?? $this->option('custom_schedules', array());

        if (!is_array($raw_schedules)) {
            $this->custom_schedules = array();
            return $this->custom_schedules;
        }

        $schedules = array();

        foreach ($raw_schedules as $slug => $schedule) {
            if (!is_array($schedule)) {
                continue;
            }

            $slug = sanitize_key($slug);
            $interval = absint($schedule['interval'] ?? 0);
            $display = sanitize_text_field((string)($schedule['display'] ?? ''));

            if ('' === $slug || $interval < MINUTE_IN_SECONDS || '' === $display) {
                continue;
            }

            $schedules[$slug] = array(
                'interval' => $interval,
                'display'  => $display,
            );
        }

        $this->custom_schedules = $schedules;

        return $this->custom_schedules;
    }

    private function save_custom_schedules(array $custom_schedules): bool
    {
        $settings = wps('wpopt')->settings->get('', array());

        if (!is_array($settings)) {
            $settings = array();
        }

        if (!isset($settings[$this->slug]) || !is_array($settings[$this->slug])) {
            $settings[$this->slug] = array();
        }

        $settings[$this->slug]['custom_schedules'] = $custom_schedules;

        $saved = (bool)wps('wpopt')->settings->reset($settings);

        if ($saved) {
            $this->custom_schedules = $custom_schedules;
        }

        return $saved;
    }

    private function parse_json_args(string $raw_args, ?bool &$valid): array
    {
        $raw_args = trim($raw_args);

        if ('' === $raw_args) {
            $valid = true;
            return array();
        }

        $decoded = json_decode($raw_args, true);

        if (JSON_ERROR_NONE !== json_last_error() || !is_array($decoded) || !$this->is_list_array($decoded)) {
            $valid = false;
            return array();
        }

        $valid = true;
        return $decoded;
    }

    private function is_list_array(array $value): bool
    {
        if (array() === $value) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    private function parse_local_datetime(string $value): int
    {
        $value = trim(sanitize_text_field($value));

        if ('' === $value) {
            return 0;
        }

        try {
            $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone(wp_timezone_string() ?: 'UTC');
            $datetime = new \DateTimeImmutable($value, $timezone);
            return $datetime->getTimestamp();
        }
        catch (\Exception $exception) {
            $timestamp = strtotime($value);
            return $timestamp ? (int)$timestamp : 0;
        }
    }

    private function sanitize_hook(string $hook): string
    {
        return trim(sanitize_text_field($hook));
    }

    private function is_valid_hook(string $hook): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9_\-\/.:]+$/', $hook);
    }

    private function get_request_text(string $key): string
    {
        $value = $_GET[$key] ?? '';

        if (!is_scalar($value)) {
            return '';
        }

        return sanitize_text_field(wp_unslash((string)$value));
    }

    private function format_datetime(int $timestamp): string
    {
        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    private function format_datetime_input(int $timestamp): string
    {
        return wp_date('Y-m-d\TH:i', $timestamp);
    }

    private function format_relative_time(int $timestamp): string
    {
        $now = time();

        if ($timestamp <= $now) {
            return sprintf(__('%s overdue', 'wpopt'), human_time_diff($timestamp, $now));
        }

        return sprintf(__('in %s', 'wpopt'), human_time_diff($now, $timestamp));
    }

    private function format_schedule_label(array $event, array $schedules): string
    {
        if (empty($event['schedule'])) {
            return __('Single event', 'wpopt');
        }

        $schedule = $schedules[$event['schedule']] ?? array();
        $display = $schedule['display'] ?? $event['schedule'];
        $interval = absint($event['interval'] ?: ($schedule['interval'] ?? 0));

        if (!$interval) {
            return (string)$display;
        }

        return sprintf('%s, %s', $display, $this->format_interval($interval));
    }

    private function format_interval(int $seconds): string
    {
        if (isset($this->formatted_intervals[$seconds])) {
            return $this->formatted_intervals[$seconds];
        }

        if ($seconds < MINUTE_IN_SECONDS) {
            return $this->formatted_intervals[$seconds] = sprintf(_n('%s second', '%s seconds', $seconds, 'wpopt'), number_format_i18n($seconds));
        }

        if ($seconds < HOUR_IN_SECONDS) {
            $minutes = (int)round($seconds / MINUTE_IN_SECONDS);
            return $this->formatted_intervals[$seconds] = sprintf(_n('%s minute', '%s minutes', $minutes, 'wpopt'), number_format_i18n($minutes));
        }

        if ($seconds < DAY_IN_SECONDS) {
            $hours = round($seconds / HOUR_IN_SECONDS, 1);
            return $this->formatted_intervals[$seconds] = sprintf(_n('%s hour', '%s hours', (int)ceil($hours), 'wpopt'), number_format_i18n($hours, 1));
        }

        $days = round($seconds / DAY_IN_SECONDS, 1);
        return $this->formatted_intervals[$seconds] = sprintf(_n('%s day', '%s days', (int)ceil($days), 'wpopt'), number_format_i18n($days, 1));
    }

    private function has_callback(string $hook): bool
    {
        if (!array_key_exists($hook, $this->callback_lookup)) {
            $this->callback_lookup[$hook] = has_action($hook) !== false;
        }

        return $this->callback_lookup[$hook];
    }

    private function format_args_json(array $args): string
    {
        $json = wp_json_encode($args, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (is_string($json)) {
            return $json;
        }

        return maybe_serialize($args);
    }

    private function add_cron_notice(string $message, string $type = 'updated'): void
    {
        add_settings_error('wpopt-cron-manager', sanitize_key(md5($message)), $message, $type);
    }
}

return __NAMESPACE__;
