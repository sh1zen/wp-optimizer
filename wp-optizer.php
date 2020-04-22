<?php
/**
 * Plugin Name: WP Optimizer
 * Plugin URI: https://github.com/sh1zen/wp-optimizer
 * Description: WordPress Database and Images optimizer
 * Author: sh1zen
 * Author URI: https://sh1zen.github.io/
 * Version: 1.1.0
 */

define('WPOPT_FILE', __FILE__);
define('WPOPT_ABSPATH', dirname(__FILE__));
define('WPOPT_INCPATH', WPOPT_ABSPATH . '/inc');
define('WPOPT_MODULES', WPOPT_ABSPATH . '/modules');

require_once WPOPT_INCPATH . '/functions.php';

require_once WPOPT_ABSPATH . '/admin/wpopt.class.php';

/**
 * Starts the plugin.
 *
 * @since 1.0.0
 */
wpopt::getInstance();
