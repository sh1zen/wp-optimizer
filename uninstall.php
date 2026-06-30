<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

/**
 * Uninstall Procedure
 */
global $wpdb;

// if uninstall.php is not called by WordPress, die
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die();
}

// setup constants
require_once __DIR__ . '/inc/wps_and_constants.php';
require_once WPOPT_INCPATH . 'functions.php';

wpopt_cleanup_media_cron_hooks();

// Leave no trail
$option_names = array('wpopt', 'wpopt.media.todo', 'wpopt_activated_at', 'wpopt_welcome_seen');

foreach ($option_names as $option_name) {
    delete_option($option_name);
}

$wpdb->query("DROP TABLE IF EXISTS " . wps('wpopt')->options->table_name());
$wpdb->query("DROP TABLE IF EXISTS " . WPOPT_TABLE_ACTIVITY_LOG);
$wpdb->query("DROP TABLE IF EXISTS " . WPOPT_TABLE_LOG_MAILS);
$wpdb->query("DROP TABLE IF EXISTS " . WPOPT_TABLE_REQUEST_PERFORMANCE);

wps_uninstall();
