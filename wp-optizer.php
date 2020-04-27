<?php
/**
 * Plugin Name: WP Optimizer
 * Plugin URI: https://github.com/sh1zen/wp-optimizer
 * Description: Speed up your website to better connect with your visitors. Includes image compression, database optimization, update manager, lazy load, HTML & CSS compression and so on.
 * Author: sh1zen
 * Author URI: https://sh1zen.github.io/
 * Text Domain: gforms_file_uploader_plugin
 * Domain Path: /languages/
 * Text Domain: wpopt
 * Version: 1.1.3
 */

define('WPOPT_FILE', __FILE__);
define('WPOPT_ABSPATH', dirname(__FILE__));
define('WPOPT_INCPATH', WPOPT_ABSPATH . '/inc');
define('wpoptModules', WPOPT_ABSPATH . '/modules');
define('WPOPT_ADMIN', WPOPT_ABSPATH . '/admin');

/**
 * Require essential
 */
require_once WPOPT_INCPATH . '/functions.php';
require_once WPOPT_INCPATH . '/wpoptTimer.class.php';
require_once WPOPT_INCPATH . '/wpoptPlCache.class.php';
require_once WPOPT_ADMIN . '/wpoptSettings.class.php';

require_once WPOPT_INCPATH . '/wpoptModuleHandler.class.php';

$wpopt_timer = new wpoptTimer();

$wpopt_timer->start();

/**
 * Initialize framework classes
 */

wpoptPlCache::Initialize();

wpoptSettings::Initialize();

wpoptModuleHandler::Initialize();


/**
 * Load WP CLI command(s) on demand.
 */
if (defined('WP_CLI') and WP_CLI) {
    require WPOPT_ADMIN . '/wpoptCLI.php';
}

/**
 * Load main class
 */
require_once WPOPT_ADMIN . '/wpopt.class.php';

/**
 * Starts the plugin.
 *
 * @since 1.0.0
 */
wpopt::getInstance();

$wpopt_timer->stop();

/*
var_dump($wpopt_timer->get_memory());
var_dump($wpopt_timer->get_time());
*/