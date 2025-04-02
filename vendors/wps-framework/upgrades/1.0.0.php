<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

global $wpdb;

WPS\core\UtilEnv::db_create(
    wps()->options->table_name(),
    [
        "fields"      => [
            "id"         => "bigint NOT NULL AUTO_INCREMENT",
            "obj_id"     => "varchar(255)",
            "context"    => "varchar(255)",
            "item"       => "varchar(255)",
            "value"      => "longtext NOT NULL",
            "container"  => "VARCHAR(255) NULL DEFAULT NULL",
            "expiration" => "bigint NOT NULL DEFAULT 0"
        ],
        "primary_key" => "id"
    ],
    true
);

$wpdb->query("ALTER TABLE " . wps()->options->table_name() . " ADD UNIQUE speeder (context, item, obj_id) USING BTREE;");

$wpdb->query("ALTER TABLE " . wps()->options->table_name() . " ADD UNIQUE speeder_container (container, item, obj_id) USING BTREE;");