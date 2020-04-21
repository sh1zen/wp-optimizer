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

define('WPOPT_FILE', __FILE__);
define('WPOPT_ABSPATH', dirname(__FILE__));
define('WPOPT_MODULES', dirname(__FILE__) . '/modules');

require_once WPOPT_ABSPATH . '/inc/functions.php';

require_once WPOPT_ABSPATH . '/admin/wpopt.class.php';

/**
 * Starts the plugin.
 *
 * @since 1.0.0
 */
wpopt::getInstance();

