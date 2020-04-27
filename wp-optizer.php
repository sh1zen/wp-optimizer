<?php
/**
 * Plugin Name: WP Optimizer
 * Plugin URI: https://github.com/sh1zen/wp-optimizer
 * Description: Speed up your website to better connect with your visitors. Includes image compression, database optimization, update manager, lazy load, HTML & CSS compression and so on.
 * Author: sh1zen
 * Author URI: https://sh1zen.github.io/
 * Text Domain: gforms_file_uploader_plugin
 * Domain Path: /languages
 * Text Domain: wpopt
 * Version: 1.1.7
 */

define('WPOPT_FILE', __FILE__);
define('WPOPT_ABSPATH', dirname(__FILE__));
define('WPOPT_INCPATH', WPOPT_ABSPATH . '/inc');
define('wpoptModules', WPOPT_ABSPATH . '/modules');
define('WPOPT_ADMIN', WPOPT_ABSPATH . '/admin');

define('WPOPT_DEBUG', $_SERVER["SERVER_ADDR"] == '127.0.0.1');

/**
 * Require essential
 */
require_once WPOPT_INCPATH . '/functions.php';
require_once WPOPT_INCPATH . '/WOTimer.class.php';
require_once WPOPT_INCPATH . '/WOPlCache.class.php';
require_once WPOPT_ADMIN . '/WOSettings.class.php';

require_once WPOPT_INCPATH . '/WOModuleHandler.class.php';

$wo_timer = new WOTimer();
$wo_timer->start();

/**
 * Initialize framework classes
 */

WOPlCache::Initialize();

WOSettings::Initialize();

WOModuleHandler::Initialize();

/**
 * Load WP CLI command(s) on demand.
 */
if (defined('WP_CLI') and WP_CLI) {
    require WPOPT_ADMIN . '/WO_CLI.php';
}

/**
 * Load main class
 */
require_once WPOPT_ADMIN . '/WO.class.php';

/**
 * Starts the plugin.
 *
 * @since 1.0.0
 */
WO::getInstance();

$wo_timer->stop();
/*
if(WPOPT_DEBUG) {
    var_dump($wo_timer->get_memory());
    var_dump($wo_timer->get_time());
}
*/

