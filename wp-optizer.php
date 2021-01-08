<?php
/**
 * Plugin Name: WP Optimizer
 * Plugin URI: https://github.com/sh1zen/wp-optimizer
 * Description: Search Engine (SEO) & Performance Optimization plugin, support automatic image compression, integrated caching, database cleanup and Server enhancements.
 * Author: sh1zen
 * Author URI: https://sh1zen.github.io/
 * Text Domain: wpopt
 * Domain Path: /languages
 * Text Domain: wpopt
 * Version: 1.5.0
 */

define('WPOPT_FILE', __FILE__);
define('WPOPT_ABSPATH', dirname(__FILE__) . '/');
define('WPOPT_INCPATH', WPOPT_ABSPATH . 'inc/');
define('WPOPT_MODULES', WPOPT_ABSPATH . 'modules/');
define('WPOPT_ADMIN', WPOPT_ABSPATH . 'admin/');
define('WPOPT_EXTENSIONS', WPOPT_ABSPATH . 'extensions/');

// setup constants
require_once WPOPT_INCPATH . 'constants.php';

// essential
require_once WPOPT_INCPATH . 'WODisk.class.php';
require_once WPOPT_INCPATH . 'back-compat.php';
require_once WPOPT_INCPATH . 'functions.php';
require_once WPOPT_INCPATH . 'WOReport.class.php';
require_once WPOPT_INCPATH . 'WOMeter.class.php';
require_once WPOPT_INCPATH . 'WOCache.class.php';
require_once WPOPT_INCPATH . 'WOStorage.class.php';
require_once WPOPT_ADMIN . 'WOOptions.class.php';
require_once WPOPT_ADMIN . 'WOSettings.class.php';
require_once WPOPT_ADMIN . 'WOCron.class.php';

// extensions
require_once WPOPT_INCPATH . 'WO_UtilEnv.php';

// modules handlers
require_once WPOPT_INCPATH . 'WOModule.class.php';
require_once WPOPT_INCPATH . 'WOModuleHandler.class.php';

// main class
require_once WPOPT_ADMIN . 'WO.class.php';

$wo_meter = new WOMeter('loading-wpopt');

/**
 * Initialize framework classes
 */
WOStorage::getInstance();

WOCache::getInstance();

WOSettings::getInstance();

WOModuleHandler::getInstance();

WOCron::getInstance();

/**
 * Starts the plugin.
 */
WO::Initialize();

