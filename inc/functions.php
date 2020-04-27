<?php

function wpopt_convert_size($size)
{
    $unit = array('b', 'kb', 'mb', 'gb', 'tb', 'pb');
    return @round($size / pow(1024, ($i = floor(log($size, 1024)))), 2) . ' ' . $unit[$i];
}

/**
 *
 * @param int $current Current number.
 * @param int $total Total number.
 * @return string Number in percentage
 * @since 1.0.2
 *
 * @access public
 */
function wpopt_format_percentage($current, $total)
{
    if ($total == 0)
        return 0;

    return ($total > 0 ? round(($current / $total) * 100, 2) : 0) . '%';
}


function wpopt_get_calling_function()
{
    $caller = debug_backtrace();
    $caller = $caller[2];
    $r = $caller['function'] . '()';
    if (isset($caller['class'])) {
        $r .= ' in ' . $caller['class'];
    }
    if (isset($caller['object'])) {
        $r .= ' (' . get_class($caller['object']) . ')';
    }
    return $r;
}


function wpopt_write_log($log)
{
    if (true === WP_DEBUG) {
        if (is_array($log) || is_object($log)) {
            error_log(print_r($log, true));
        }
        else {
            error_log($log);
        }
    }
}

function wpopt_generate_report($data, wpoptTimer $timer = null)
{

    $report = PHP_EOL . PHP_EOL;
    $report .= ' ' . __('Report', 'wpopt') . ': ' . PHP_EOL . PHP_EOL;

    if (!is_null($timer)) {
        $report .= ' ' . __('Time elapsed', 'wpopt') . ': ' . $timer->get_time() . PHP_EOL;
        $report .= ' ' . __('Memory used', 'wpopt') . ': ' . $timer->get_memory() . PHP_EOL;
    }

    $report .= ' ' . __('Images cleaned inserted', 'wpopt') . ': ' . count($data['images']) . PHP_EOL;
    $report .= ' ' . $data['db'] . PHP_EOL;
    $report .= ' ' . __('Errors', 'wpopt') . ': ' . PHP_EOL;
    $report .= ' --------------------------------- ' . PHP_EOL;
    $report .= ' ' . __('Number', 'wpopt') . ': ' . count($data['errors']) . PHP_EOL;

    foreach ($data['errors'] as $error)
        $report .= ' - ' . $error . PHP_EOL;

    return $report;
}
