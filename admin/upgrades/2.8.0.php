<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2026.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

$hooks = array(
    'orphaned_media_scanner_cron_handler',
    'ipc_scanner_cron_handler',
    'wpopt_optimize_media_path',
);

$removed = false;
$crons = _get_cron_array();

if (!is_array($crons)) {
    _set_cron_array(array());
    $crons = array();
    $removed = true;
}

foreach ($hooks as $hook) {
    $hook = (string)$hook;

    if ('' === $hook) {
        continue;
    }

    foreach ($crons as $timestamp => $cron) {
        if (!is_array($cron) || !isset($cron[$hook])) {
            continue;
        }

        unset($crons[$timestamp][$hook]);
        $removed = true;

        if (empty($crons[$timestamp])) {
            unset($crons[$timestamp]);
        }
    }
}

if ($removed) {
    ksort($crons);
    _set_cron_array($crons);
}

$events = get_option('wps#cron-events', array());

if (is_array($events)) {
    foreach ($hooks as $hook) {
        unset($events[(string)$hook]);
    }

    update_option('wps#cron-events', array_filter($events), 'no');
}
