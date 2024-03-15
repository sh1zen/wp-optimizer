<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

/**
 * Plugin Name: WP Optimizer
 * Plugin URI: https://github.com/sh1zen/wp-optimizer
 * Description: Search Engine (SEO) & Performance Optimization plugin, support automatic image compression, integrated caching, database and server enhancements.
 * Author: sh1zen
 * Author URI: https://sh1zen.github.io/
 * Text Domain: wpopt
 * Domain Path: /languages
 * Version: 2.2.4
 */

const WPOPT_VERSION = '2.2.4';

const WPOPT_FILE = __FILE__;

const WPOPT_ABSPATH = __DIR__ . '/';
const WPOPT_INCPATH = WPOPT_ABSPATH . 'inc/';
const WPOPT_MODULES = WPOPT_ABSPATH . 'modules/';
const WPOPT_ADMIN = WPOPT_ABSPATH . 'admin/';
const WPOPT_SUPPORTERS = WPOPT_MODULES . 'supporters/';

// setup constants
require_once WPOPT_INCPATH . 'constants.php';

// essential
require_once WPOPT_INCPATH . 'functions.php';
require_once WPOPT_INCPATH . 'Report.class.php';


// wps-framework commons
if (!defined('WPS_FRAMEWORK')) {
    if (defined('WPS_FRAMEWORK_SOURCE') and file_exists(WPS_FRAMEWORK_SOURCE . 'loader.php')) {
        require_once WPS_FRAMEWORK_SOURCE . 'loader.php';
    }
    else {
        if (!file_exists( WPOPT_ABSPATH . 'vendors/wps-framework/loader.php')) {
            return;
        }
        require_once  WPOPT_ABSPATH . 'vendors/wps-framework/loader.php';
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

// initializer class
require_once WPOPT_ADMIN . 'PluginInit.class.php';

/**
 * Initialize the plugin.
 */
WPOptimizer\core\PluginInit::Initialize();

wps_utils()->meter->lap('wpopt-loaded');