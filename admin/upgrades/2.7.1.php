<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2026.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

global $wpdb;

$table_name = wpopt_db_table_name('log_mails');
$table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));

if (!$table_exists) {
    return;
}

$indexes = (array)$wpdb->get_results('SHOW INDEX FROM ' . $table_name, ARRAY_A);

foreach ($indexes as $index) {
    if (($index['Key_name'] ?? '') === 'speeder') {
        $wpdb->query('ALTER TABLE ' . $table_name . ' DROP INDEX `speeder`');
        break;
    }
}
