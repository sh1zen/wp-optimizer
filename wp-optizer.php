<?php
/**
 * Plugin Name: WP Optimizer
 * Plugin URI: https://github.com/sh1zen/wp-optimizer
 * Description: Speed up your website to better connect with your visitors. Includes image compression, database optimization, updates manager, lazy load, HTML & CSS compression and so on.
 * Author: sh1zen
 * Author URI: https://sh1zen.github.io/
 * Text Domain: gforms_file_uploader_plugin
 * Domain Path: /languages
 * Text Domain: wpopt
 * Version: 1.3.35
 */

define('WPOPT_FILE', __FILE__);
define('WPOPT_ABSPATH', dirname(__FILE__) . '/');
define('WPOPT_INCPATH', WPOPT_ABSPATH . 'inc/');
define('WPOPT_MODULES', WPOPT_ABSPATH . 'modules/');
define('WPOPT_ADMIN', WPOPT_ABSPATH . 'admin/');

define('WPOPT_DEBUG', $_SERVER["SERVER_ADDR"] === '127.0.0.1');

/**
 * Require essential
 */
require_once WPOPT_INCPATH . 'back-compat.php';
require_once WPOPT_INCPATH . 'functions.php';
require_once WPOPT_INCPATH . 'WOMeter.class.php';
require_once WPOPT_INCPATH . 'WOCache.class.php';
require_once WPOPT_INCPATH . 'WOStorage.class.php';
require_once WPOPT_ADMIN . 'WOSettings.class.php';
require_once WPOPT_INCPATH . 'WOMonitor.class.php';

require_once WPOPT_INCPATH . 'WO_Module.php';
require_once WPOPT_INCPATH . 'WOModuleHandler.class.php';
require_once WPOPT_INCPATH . 'WOPerformer.class.php';


$wo_meter = new WOMeter();

/**
 * Initialize framework classes
 */
WOStorage::getInstance();

WOCache::Initialize();

WOSettings::Initialize();

WOModuleHandler::Initialize();

/**
 * Load main class
 */
require_once WPOPT_ADMIN . 'WO.class.php';

/**
 * Starts the plugin.
 *
 * @since 1.0.0
 */
WO::Initialize();
