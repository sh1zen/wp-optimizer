<?php

/**
 * Plugin Name: WP Optimizer
 * Plugin URI:
 * Description: WordPress Database and Images optimizer
 * Author: sh1zen
 * Version: 1.0.0
 *
 * @package WP_OPT
 * @author Andrea Frolli
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


