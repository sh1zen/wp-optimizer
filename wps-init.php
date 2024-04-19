<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */


// wps-framework commons
if (!defined('WPS_FRAMEWORK')) {
    if (defined('WPS_FRAMEWORK_SOURCE') and file_exists(WPS_FRAMEWORK_SOURCE . 'loader.php')) {
        require_once WPS_FRAMEWORK_SOURCE . 'loader.php';
    }
    else {
        if (!file_exists(WPOPT_ABSPATH . 'vendors/wps-framework/loader.php')) {
            return;
        }
        require_once WPOPT_ABSPATH . 'vendors/wps-framework/loader.php';
    }
}

wps(
    'wpopt',
    [
        'modules_path' => WPOPT_MODULES,
        'table_name'   => "wp_wpopt",
    ],
    [
        'cache'         => true,
        'storage'       => true,
        'settings'      => true,
        'cron'          => true,
        'ajax'          => true,
        'moduleHandler' => true,
        'options'       => true
    ]
);