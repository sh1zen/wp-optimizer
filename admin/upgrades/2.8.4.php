<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2026.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

global $wpdb;

$wpopt_284_table_exists = static function (string $table) use ($wpdb): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }

    return (string)$wpdb->get_var(
        $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
    ) === $table;
};

$wpopt_284_get_table_indexes = static function (string $table) use ($wpdb, $wpopt_284_table_exists): array {
    if (!$wpopt_284_table_exists($table)) {
        return array();
    }

    $indexes = array();
    $rows = (array)$wpdb->get_results('SHOW INDEX FROM `' . $table . '`', ARRAY_A);

    foreach ($rows as $row) {
        $key_name = (string)($row['Key_name'] ?? '');

        if ($key_name !== '') {
            $indexes[$key_name] = true;
        }
    }

    return $indexes;
};

$wpopt_284_add_index = static function (string $table, string $name, string $definition) use ($wpdb, $wpopt_284_get_table_indexes): void {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        return;
    }

    $indexes = $wpopt_284_get_table_indexes($table);

    if (empty($indexes) || isset($indexes[$name])) {
        return;
    }

    $wpdb->query('ALTER TABLE `' . $table . '` ADD ' . $definition);
};

$wpopt_284_add_index(
    WPOPT_TABLE_REQUEST_PERFORMANCE,
    'idx_perf_window_type',
    'INDEX `idx_perf_window_type` (`created_at_gmt`, `request_type`) USING BTREE'
);
$wpopt_284_add_index(
    WPOPT_TABLE_REQUEST_PERFORMANCE,
    'idx_perf_window_label',
    'INDEX `idx_perf_window_label` (`created_at_gmt`, `request_type`, `request_label`(120)) USING BTREE'
);
$wpopt_284_add_index(
    WPOPT_TABLE_REQUEST_PERFORMANCE,
    'idx_perf_recent',
    'INDEX `idx_perf_recent` (`created_at_gmt`, `id`) USING BTREE'
);

$wpopt_284_add_index(
    WPOPT_TABLE_SLOW_QUERIES,
    'idx_slow_window_runtime',
    'INDEX `idx_slow_window_runtime` (`created_at_gmt`, `query_time_ms`) USING BTREE'
);
$wpopt_284_add_index(
    WPOPT_TABLE_SLOW_QUERIES,
    'idx_slow_signature_window',
    'INDEX `idx_slow_signature_window` (`sql_signature`, `created_at_gmt`, `query_time_ms`) USING BTREE'
);
$wpopt_284_add_index(
    WPOPT_TABLE_SLOW_QUERIES,
    'idx_slow_request_log',
    'INDEX `idx_slow_request_log` (`request_log_id`) USING BTREE'
);

$wpopt_284_store_wpopt_option = static function (string $obj_id, string $item, string $context, $value): bool {
    if (!function_exists('wps') || !isset(wps('wpopt')->options)) {
        return false;
    }

    $existing = wps('wpopt')->options->get($obj_id, $item, $context, null, false);

    if (maybe_serialize($existing) === maybe_serialize($value)) {
        return true;
    }

    return wps('wpopt')->options->update($obj_id, $item, $value, $context);
};

$wpopt_284_legacy_cache_metrics = get_option('wpopt_perf_cache_cumulative', array());

if (is_array($wpopt_284_legacy_cache_metrics) && !empty($wpopt_284_legacy_cache_metrics)) {
    if ($wpopt_284_store_wpopt_option('wpopt_perf_cache_cumulative', 'cumulative_cache_metrics', 'performance_monitor', $wpopt_284_legacy_cache_metrics)) {
        delete_option('wpopt_perf_cache_cumulative');
    }
}
else {
    delete_option('wpopt_perf_cache_cumulative');
}

$wpopt_284_legacy_backups = get_option('wpopt_configuration_backups', array());

if (is_array($wpopt_284_legacy_backups) && !empty($wpopt_284_legacy_backups)) {
    if ($wpopt_284_store_wpopt_option('wpopt_configuration_backups', 'configuration_backups', 'settings', $wpopt_284_legacy_backups)) {
        delete_option('wpopt_configuration_backups');
    }
}
else {
    delete_option('wpopt_configuration_backups');
}

delete_option('wpopt_perf_cache_metrics_ready');
delete_option('wpopt_perf_component_profile_ready');
