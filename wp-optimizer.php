<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

/**
 * Plugin Name: WP Optimizer
 * Plugin URI: https://github.com/sh1zen/wp-optimizer/
 * Description: Page cache, lazy load, minify CSS/JS/HTML, image optimization & CDN, LCP preload and more — everything you need to improve Core Web Vitals, in one plugin.
 * Author: sh1zen
 * Author URI: https://sh1zen.github.io/
 * Text Domain: wpopt
 * Domain Path: /languages
 * Version: 2.8.6
 */

const WPOPT_VERSION = '2.8.6';
const WPOPT_FILE = __FILE__;

// setup constants
require_once __DIR__ . '/inc/wps_and_constants.php';

// recovery must be registered before modules can trigger runtime errors
require_once WPOPT_INCPATH . 'Recovery.class.php';
WPOptimizer\core\Recovery::bootstrap();

// essential
require_once WPOPT_INCPATH . 'functions.php';
require_once WPOPT_INCPATH . 'Compatibility.class.php';
require_once WPOPT_INCPATH . 'cache-api.php';
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
    wps_error_handler('wp-optimizer', null, true, [
        'plugin_name'    => 'WP Optimizer',
        'plugin_version' => WPOPT_VERSION,
    ]);
}
