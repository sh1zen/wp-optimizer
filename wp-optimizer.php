<?php
/**
 * Plugin Name: WP Optimizer
 * Plugin URI: https://github.com/sh1zen/wp-optimizer
 * Description: Search Engine (SEO) & Performance Optimization plugin, support automatic image compression, integrated caching, database cleanup and Server enhancements.
 * Author: sh1zen
 * Author URI: https://sh1zen.github.io/
 * Text Domain: wpopt
 * Domain Path: /languages
 * Version: 1.5.3
 */

const WPOPT_FILE = __FILE__;
define('WPOPT_ABSPATH', dirname(__FILE__) . '/');
const WPOPT_INCPATH = WPOPT_ABSPATH . 'inc/';
const WPOPT_MODULES = WPOPT_ABSPATH . 'modules/';
const WPOPT_ADMIN = WPOPT_ABSPATH . 'admin/';
const WPOPT_SUPPORTERS = WPOPT_MODULES . 'supporters/';

// setup constants
require_once WPOPT_INCPATH . 'constants.php';

// essential
require_once WPOPT_INCPATH . 'Disk.class.php';
require_once WPOPT_INCPATH . 'back-compat.php';
require_once WPOPT_INCPATH . 'functions.php';
require_once WPOPT_INCPATH . 'Report.class.php';
require_once WPOPT_INCPATH . 'PerformanceMeter.class.php';
require_once WPOPT_INCPATH . 'Cache.class.php';
require_once WPOPT_INCPATH . 'Storage.class.php';
require_once WPOPT_ADMIN . 'Options.class.php';
require_once WPOPT_ADMIN . 'Settings.class.php';
require_once WPOPT_ADMIN . 'Cron.class.php';
require_once WPOPT_INCPATH . 'Graphic.class.php';

// extensions
require_once WPOPT_INCPATH . 'UtilEnv.php';

// modules handlers
require_once WPOPT_INCPATH . 'Module.class.php';
require_once WPOPT_INCPATH . 'ModuleHandler.class.php';

// main class
require_once WPOPT_ADMIN . 'PluginInit.class.php';

$wo_meter = new WPOptimizer\core\PerformanceMeter('loading-wpopt');

/**
 * Initialize framework classes
 */
WPOptimizer\core\Storage::getInstance();

WPOptimizer\core\Cache::getInstance();

WPOptimizer\core\Settings::getInstance();

WPOptimizer\core\ModuleHandler::getInstance();

WPOptimizer\core\Cron::getInstance();

/**
 * Starts the plugin.
 */
WPOptimizer\core\PluginInit::Initialize();

