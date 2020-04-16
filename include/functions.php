<?php

if (!function_exists('convert_size')) {
    function convert_size($size)
    {
        $unit = array('b', 'kb', 'mb', 'gb', 'tb', 'pb');
        return @round($size / pow(1024, ($i = floor(log($size, 1024)))), 2) . ' ' . $unit[$i];
    }
}

if (!function_exists('get_calling_function')) {
    function get_calling_function()
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
}

function wpopt_do_cron(&$args = array())
{
    $start_time = microtime(true);
    $start_memory = memory_get_usage(true);

    $full_report = array();

    $default = array(
        'active'   => false,
        'images'   => false,
        'database' => false,
        'lock'     => false
    );

    $options = wp_parse_args($args, $default);

    if ((bool)$options['active'] == false)
        return false;

    if ($options['images']) {

        $images = json_decode(get_option('wp-opt--todo'), true);

        if (!empty($images)) {
            $full_report['images'] = wpopt_optimize_images($images);
            update_option('wp-opt--todo', '', false);
        }
    }

    if ($options['database'])
        $full_report['db'] = wpopt_clear_database();

    $end_time = microtime(true) - $start_time;

    $end_memory = memory_get_usage(true) - $start_memory;

    if ($options['save_report'])
        file_put_contents(WP_CONTENT_DIR . '/report.opt.txt', wpopt_generate_report($full_report, $start_time, $start_memory), FILE_APPEND);

    return array_merge(array('memory' => convert_size($end_memory), 'time' => $end_time), $full_report);
}

function wpopt_generate_report($data, $start_time = '', $start_memory = '')
{
    $end_time = microtime(true) - $start_time;
    $end_memory = memory_get_usage(true) - $start_memory;

    $report = PHP_EOL . PHP_EOL;
    $report .= ' Report:' . PHP_EOL . PHP_EOL;
    $report .= ' Time elapsed: ' . $end_time . PHP_EOL;
    $report .= ' Memory used: ' . convert_size($end_memory) . PHP_EOL;
    $report .= ' Images cleaned inserted: ' . count($data['images']) . PHP_EOL;
    $report .= ' ' . $data['db'] . PHP_EOL;
    $report .= ' Errors: ' . PHP_EOL;
    $report .= ' --------------------------------- ' . PHP_EOL;
    $report .= ' Number: ' . count($data['errors']) . PHP_EOL;

    foreach ($data['errors'] as $error)
        $report .= ' - ' . $error . PHP_EOL;

    return $report;
}