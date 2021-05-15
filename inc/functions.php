<?php

function wpopt_timestr2seconds($time = '')
{
    if (!$time)
        return 0;

    list($hour, $minute) = explode(':', $time);

    return $hour * HOUR_IN_SECONDS + $minute * MINUTE_IN_SECONDS;
}

function wpopt_add_timezone($timestamp = false)
{
    if (!$timestamp)
        $timestamp = time();

    $timezone = get_option('gmt_offset') * HOUR_IN_SECONDS;

    return $timestamp - $timezone;
}


/**
 * @return string
 */
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


function wpopt_module_panel_url($module = '', $panel = '')
{
    return admin_url("admin.php?page={$module}#{$panel}");
}

function wpopt_module_setting_url($panel = '')
{
    return admin_url("admin.php?page=wpopt-modules-settings#settings-{$panel}");
}

function wpopt_setting_panel_url($panel = '')
{
    return admin_url("admin.php?page=wpopt-settings#settings-{$panel}");
}

function wpopt_get_mysqlDump_command_path($mysqldump_locations = '')
{
    // Check shell_exec is available
    if (!WPOptimizer\core\UtilEnv::is_shell_exec_available())
        return false;

    if (!empty($mysqldump_locations)) {

        return @is_executable(WPOptimizer\core\UtilEnv::normalize_path($mysqldump_locations));
    }

    // check mysqldump command
    if (is_null(shell_exec('hash mysqldump 2>&1'))) {

        return 'mysqldump';
    }

    $mysqldump_locations = array(
        '/usr/local/bin/mysqldump',
        '/usr/local/mysql/bin/mysqldump',
        '/usr/mysql/bin/mysqldump',
        '/usr/bin/mysqldump',
        '/opt/local/lib/mysql6/bin/mysqldump',
        '/opt/local/lib/mysql5/bin/mysqldump',
        '/opt/local/lib/mysql4/bin/mysqldump',
        '/xampp/mysql/bin/mysqldump',
        '/Program Files/xampp/mysql/bin/mysqldump',
        '/Program Files/MySQL/MySQL Server 8.0/bin/mysqldump',
        '/Program Files/MySQL/MySQL Server 6.0/bin/mysqldump',
        '/Program Files/MySQL/MySQL Server 5.5/bin/mysqldump',
        '/Program Files/MySQL/MySQL Server 5.4/bin/mysqldump',
        '/Program Files/MySQL/MySQL Server 5.1/bin/mysqldump',
        '/Program Files/MySQL/MySQL Server 5.0/bin/mysqldump',
        '/Program Files/MySQL/MySQL Server 4.1/bin/mysqldump'
    );

    $mysqldump_command_path = '';

    // Find the one which works
    foreach ((array)$mysqldump_locations as $location) {
        if (@is_executable(WPOptimizer\core\UtilEnv::normalize_path($location)))
            $mysqldump_command_path = $location;
    }

    return empty($mysqldump_command_path) ? false : $mysqldump_command_path;
}

