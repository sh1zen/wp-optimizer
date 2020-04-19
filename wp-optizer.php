<?php

/**
 * Plugin Name: WP Optimizer
 * Plugin URI: https://github.com/sh1zen/wp-optimizer
 * Description: WordPress Database and Images optimizer
 * Author: sh1zen
 * Author URI: https://sh1zen.github.io/
 * WC requires at least: 4.0.1
 * WC tested up to: 5.4
 * Version: 1.1.0
 */

define('WP_OPT_FILE', __FILE__);
define('WP_OPT_PATH', dirname(__FILE__));

include_once WP_OPT_PATH . '/include/clear_functions.php';
include_once WP_OPT_PATH . '/include/functions.php';

include_once WP_OPT_PATH . '/admin/wpopt_setup.class.php';
include_once WP_OPT_PATH . '/admin/wpopt_menu_page.class.php';


/**
 * Starts the plugin.
 *
 * @since 1.0.0
 */
new wpopt_setup();


