<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2026.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

global $wpdb;

$table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', WPOPT_TABLE_LOG_MAILS));

if (!$table_exists) {
    return;
}

$indexes = (array)$wpdb->get_results('SHOW INDEX FROM ' . WPOPT_TABLE_LOG_MAILS, ARRAY_A);

foreach ($indexes as $index) {
    if (($index['Key_name'] ?? '') === 'speeder') {
        $wpdb->query('ALTER TABLE ' . WPOPT_TABLE_LOG_MAILS . ' DROP INDEX `speeder`');
        break;
    }
}
