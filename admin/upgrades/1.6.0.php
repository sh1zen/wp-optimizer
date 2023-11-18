<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

global $wpdb;

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wpopt_core");

WPS\core\UtilEnv::db_create(
    wps('wpopt')->options->table_name(),
    [
        "fields"      => [
            "id"         => "bigint NOT NULL AUTO_INCREMENT",
            "obj_id"     => "varchar(255)",
            "context"    => "varchar(255)",
            "item"       => "varchar(255)",
            "value"      => "longtext NOT NULL",
            "expiration" => "bigint NOT NULL DEFAULT 0"
        ],
        "primary_key" => "id"
    ],
    true
);

$wpdb->query("ALTER TABLE " . wps('wpopt')->options->table_name() . " ADD UNIQUE speeder (context, item, obj_id) USING BTREE;");
