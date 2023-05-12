<?php
/**
 * Plugin Name: WP Optimizer
 * Plugin URI: https://github.com/sh1zen/wp-optimizer
 * Description: Search Engine (SEO) & Performance Optimization plugin, support automatic image compression, integrated caching, database and server enhancements.
 * Author: sh1zen
 * Author URI: https://sh1zen.github.io/
 * Text Domain: wpopt
 * Domain Path: /languages
 * Version: 2.1.6
 */

const WPOPT_VERSION = '2.1.6';

const WPOPT_FILE = __FILE__;

const WPOPT_ABSPATH = __DIR__ . '/';
const WPOPT_INCPATH = WPOPT_ABSPATH . 'inc/';
const WPOPT_MODULES = WPOPT_ABSPATH . 'modules/';
const WPOPT_ADMIN = WPOPT_ABSPATH . 'admin/';
const WPOPT_SUPPORTERS = WPOPT_MODULES . 'supporters/';
const WPOPT_VENDORS = WPOPT_ABSPATH . 'vendors/';

// setup constants
require_once WPOPT_INCPATH . 'constants.php';

// essential
require_once WPOPT_INCPATH . 'functions.php';
require_once WPOPT_INCPATH . 'Report.class.php';


// shzn-framework commons
if (!defined('SHZN_FRAMEWORK')) {
    if (!file_exists(WPOPT_VENDORS . 'shzn-framework/loader.php')) {
        return;
    }
    require_once WPOPT_VENDORS . 'shzn-framework/loader.php';
}

shzn(
    'wpopt',
    [
        'path'         => WPOPT_MODULES,
        'table_name'   => "wpopt",
    ],
    [
        'meter'         => true,
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

shzn('wpopt')->meter->lap('wpopt-loaded');