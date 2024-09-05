<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

/**
 * Plugin Name: WP Optimizer
 * Plugin URI: https://github.com/sh1zen/wp-optimizer
 * Description: Performance Optimization plugin, support automatic image compression, integrated caching, deep WordPress and server enhancements .
 * Author: sh1zen
 * Author URI: https://sh1zen.github.io/
 * Text Domain: wpopt
 * Domain Path: /languages
 * Version: 2.3.5
 */

const WPOPT_VERSION = '2.3.5';
const WPOPT_FILE = __FILE__;

// setup constants
require_once __DIR__ . '/inc/wps_and_constants.php';

// essential
require_once WPOPT_INCPATH . 'functions.php';
require_once WPOPT_INCPATH . 'Report.class.php';

// initializer class
require_once WPOPT_ADMIN . 'PluginInit.class.php';

/**
 * Initialize the plugin.
 */
WPOptimizer\core\PluginInit::Initialize();

wps_core()->meter->lap('wpopt-loaded');

if (wps('wpopt')->settings->get('tracking.errors', true)) {
    // on user consent allow error tracking report
    wps_error_handler('wp-optimizer');
}
