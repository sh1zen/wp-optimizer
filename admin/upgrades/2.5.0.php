<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2026.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

global $wpdb;

WPS\core\UtilEnv::db_create(
    WPOPT_TABLE_REQUEST_PERFORMANCE,
    [
        'fields'      => [
            'id'              => 'bigint unsigned NOT NULL AUTO_INCREMENT',
            'blog_id'         => 'bigint unsigned NOT NULL DEFAULT 0',
            'request_type'    => 'varchar(64) NOT NULL',
            'request_label'   => 'varchar(190) NOT NULL',
            'request_method'  => "varchar(10) NOT NULL DEFAULT 'GET'",
            'request_uri'     => 'text NOT NULL',
            'status_code'     => 'smallint unsigned NOT NULL DEFAULT 200',
            'response_time_ms'=> 'decimal(10,3) NOT NULL DEFAULT 0.000',
            'memory_peak'     => 'bigint unsigned NOT NULL DEFAULT 0',
            'memory_usage'    => 'bigint unsigned NOT NULL DEFAULT 0',
            'query_count'     => 'int unsigned NOT NULL DEFAULT 0',
            'is_slow'         => 'tinyint(1) NOT NULL DEFAULT 0',
            'created_at'      => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'created_at_gmt'  => 'datetime DEFAULT NULL',
        ],
        'primary_key' => 'id'
    ],
    true
);

$wpdb->query("ALTER TABLE " . WPOPT_TABLE_REQUEST_PERFORMANCE . " ADD INDEX `idx_request_type` (`request_type`) USING BTREE;");
$wpdb->query("ALTER TABLE " . WPOPT_TABLE_REQUEST_PERFORMANCE . " ADD INDEX `idx_created_at_gmt` (`created_at_gmt`) USING BTREE;");
$wpdb->query("ALTER TABLE " . WPOPT_TABLE_REQUEST_PERFORMANCE . " ADD INDEX `idx_is_slow` (`is_slow`) USING BTREE;");
$wpdb->query("ALTER TABLE " . WPOPT_TABLE_REQUEST_PERFORMANCE . " ADD INDEX `idx_type_created` (`request_type`, `created_at_gmt`) USING BTREE;");
