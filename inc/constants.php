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

const WPOPT_INCPATH = WPOPT_ABSPATH . 'inc/';
const WPOPT_MODULES = WPOPT_ABSPATH . 'modules/';
const WPOPT_ADMIN = WPOPT_ABSPATH . 'admin/';
const WPOPT_SUPPORTERS = WPOPT_MODULES . 'supporters/';
const WPOPT_STORAGE = WP_CONTENT_DIR . '/wpopt/';


function wpopt_setup_db_table_constants(): void
{
    global $wpdb;

    // prevent double initialization
    if (defined('WPOPT_TABLE_LOG_MAILS')) {
        return;
    }

    define('WPOPT_TABLE_LOG_MAILS', "{$wpdb->prefix}wpopt_mails");
    define('WPOPT_TABLE_ACTIVITY_LOG', "{$wpdb->prefix}wpopt_activity_log");

}

wpopt_setup_db_table_constants();
