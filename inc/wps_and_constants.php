<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

if (!defined("WPOPT_CACHE_DB_THRESHOLD_STORE")) {
    define('WPOPT_CACHE_DB_THRESHOLD_STORE', 0.001);
}

if (!defined("WPOPT_CACHE_DB_LIFETIME")) {
    define('WPOPT_CACHE_DB_LIFETIME', HOUR_IN_SECONDS);
}

if (!defined("WPOPT_CACHE_DB_OPTIONS")) {
    define('WPOPT_CACHE_DB_OPTIONS', false);
}

define("WPOPT_ABSPATH", dirname(__DIR__) . '/');
const WPOPT_INCPATH = WPOPT_ABSPATH . 'inc/';
const WPOPT_MODULES = WPOPT_ABSPATH . 'modules/';
const WPOPT_ADMIN = WPOPT_ABSPATH . 'admin/';
const WPOPT_SUPPORTERS = WPOPT_MODULES . 'supporters/';
const WPOPT_STORAGE = WP_CONTENT_DIR . '/wpopt/';


// wps-framework commons
if (!defined('WPS_FRAMEWORK')) {
    if (defined('WPS_FRAMEWORK_SOURCE') and file_exists(WPS_FRAMEWORK_SOURCE . 'loader.php')) {
        require_once WPS_FRAMEWORK_SOURCE . 'loader.php';
    }
    else {
        if (!file_exists(WPOPT_ABSPATH . 'vendors/wps-framework/loader.php')) {
            return;
        }
        require_once WPOPT_ABSPATH . 'vendors/wps-framework/loader.php';
    }
}

if ( ! function_exists('wp_cache_init') ) {
    $cache_file = ABSPATH . WPINC . '/cache.php';
    if ( file_exists($cache_file) ) {
        require_once $cache_file;
    }
}

global $wp_object_cache;
if ( function_exists('wp_cache_init') && ! $wp_object_cache ) {
    wp_cache_init();
}

wps(
    'wpopt',
    [
        'modules_path' => WPOPT_MODULES,
        'table_name'   => "wp_wpopt",
    ],
    [
        'cache'         => true,
        'storage'       => true,
        'settings'      => true,
        'cron'          => true,
        'ajax'          => true,
        'moduleHandler' => true,
        'options'       => true
    ]
);

define('WPOPT_DEBUG', !wps_core()->online);

function wpopt_db_table_name(string $table): string
{
    global $wpdb;

    static $tables = array(
        'log_mails'           => 'wpopt_mails',
        'activity_log'        => 'wpopt_activity_log',
        'request_performance' => 'wpopt_performance_monitor',
        'slow_queries'        => 'wpopt_performance_slow_queries',
        'cache_entries'       => 'wpopt_cache_entries',
    );

    return isset($tables[$table]) ? $wpdb->prefix . $tables[$table] : '';
}

function wpopt_setup_db_table_constants(): void
{
    // prevent double initialization
    if (defined('WPOPT_TABLE_LOG_MAILS')) {
        return;
    }

    define('WPOPT_TABLE_LOG_MAILS', wpopt_db_table_name('log_mails'));
    define('WPOPT_TABLE_ACTIVITY_LOG', wpopt_db_table_name('activity_log'));
    define('WPOPT_TABLE_REQUEST_PERFORMANCE', wpopt_db_table_name('request_performance'));
    define('WPOPT_TABLE_SLOW_QUERIES', wpopt_db_table_name('slow_queries'));
    define('WPOPT_TABLE_CACHE_ENTRIES', wpopt_db_table_name('cache_entries'));

}

wpopt_setup_db_table_constants();
