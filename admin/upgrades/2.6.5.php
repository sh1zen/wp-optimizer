<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2026.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

global $wpdb;

$get_table_indexes = static function (string $table) use ($wpdb): array {
    $indexes = array();
    $rows = (array)$wpdb->get_results('SHOW INDEX FROM ' . $table, ARRAY_A);

    foreach ($rows as $row) {
        $key_name = (string)($row['Key_name'] ?? '');

        if ($key_name !== '') {
            $indexes[$key_name] = true;
        }
    }

    return $indexes;
};

$sync_final_indexes = static function (string $table, array $obsolete_indexes) use ($wpdb, $get_table_indexes): void {
    $indexes = $get_table_indexes($table);

    foreach ($obsolete_indexes as $index_name) {
        if (!isset($indexes[$index_name])) {
            continue;
        }

        $wpdb->query('ALTER TABLE ' . $table . ' DROP INDEX `' . $index_name . '`');
    }

    if (!isset($indexes['idx_created_at_gmt'])) {
        $wpdb->query('ALTER TABLE ' . $table . ' ADD INDEX `idx_created_at_gmt` (`created_at_gmt`) USING BTREE');
    }
};

WPS\core\UtilEnv::db_create(
    WPOPT_TABLE_REQUEST_PERFORMANCE,
    [
        'fields'      => [
            'id'                     => 'bigint unsigned NOT NULL AUTO_INCREMENT',
            'blog_id'                => 'bigint unsigned NOT NULL DEFAULT 0',
            'request_type'           => 'varchar(64) NOT NULL',
            'request_label'          => 'varchar(190) NOT NULL',
            'request_method'         => "varchar(10) NOT NULL DEFAULT 'GET'",
            'request_uri'            => 'text NOT NULL',
            'status_code'            => 'smallint unsigned NOT NULL DEFAULT 200',
            'response_time_ms'       => 'decimal(10,3) NOT NULL DEFAULT 0.000',
            'memory_peak'            => 'bigint unsigned NOT NULL DEFAULT 0',
            'memory_usage'           => 'bigint unsigned NOT NULL DEFAULT 0',
            'query_count'            => 'int unsigned NOT NULL DEFAULT 0',
            'cache_hits'             => 'int unsigned NOT NULL DEFAULT 0',
            'cache_misses'           => 'int unsigned NOT NULL DEFAULT 0',
            'db_cache_hits'          => 'int unsigned NOT NULL DEFAULT 0',
            'db_cache_misses'        => 'int unsigned NOT NULL DEFAULT 0',
            'query_cache_hits'       => 'int unsigned NOT NULL DEFAULT 0',
            'query_cache_misses'     => 'int unsigned NOT NULL DEFAULT 0',
            'component_profile_json' => 'longtext DEFAULT NULL',
            'is_slow'                => 'tinyint(1) NOT NULL DEFAULT 0',
            'created_at'             => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'created_at_gmt'         => 'datetime DEFAULT NULL',
        ],
        'primary_key' => 'id'
    ],
    false
);

$sync_final_indexes(
    WPOPT_TABLE_REQUEST_PERFORMANCE,
    array('idx_request_type', 'idx_is_slow', 'idx_type_created', 'idx_created_type')
);

WPS\core\UtilEnv::db_create(
    WPOPT_TABLE_SLOW_QUERIES,
    [
        'fields'      => [
            'id'              => 'bigint unsigned NOT NULL AUTO_INCREMENT',
            'request_log_id'  => 'bigint unsigned NOT NULL DEFAULT 0',
            'blog_id'         => 'bigint unsigned NOT NULL DEFAULT 0',
            'request_type'    => 'varchar(64) NOT NULL',
            'request_label'   => 'varchar(190) NOT NULL',
            'request_method'  => "varchar(10) NOT NULL DEFAULT 'GET'",
            'request_uri'     => 'text NOT NULL',
            'sql_signature'   => 'char(32) NOT NULL',
            'sql_fingerprint' => 'text NOT NULL',
            'sql_query'       => 'longtext NOT NULL',
            'query_time_ms'   => 'decimal(10,3) NOT NULL DEFAULT 0.000',
            'query_caller'    => 'text DEFAULT NULL',
            'created_at'      => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'created_at_gmt'  => 'datetime DEFAULT NULL',
        ],
        'primary_key' => 'id'
    ],
    false
);

$sync_final_indexes(
    WPOPT_TABLE_SLOW_QUERIES,
    array('idx_sql_signature', 'idx_query_time_ms', 'idx_request_log_id', 'idx_created_request')
);
