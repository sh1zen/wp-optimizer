<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

WPS\core\UtilEnv::db_create(
    WPOPT_TABLE_ACTIVITY_LOG,
    [
        "fields"      => [
            "id"         => "bigint NOT NULL AUTO_INCREMENT",
            "action"     => "varchar(255)",
            "context"    => "varchar(255)",
            "value"      => "longtext",
            "user_id"    => "bigint",
            "object_id"  => "bigint",
            "ip"         => "varchar(80)",
            "user_agent" => "text",
            "request"    => "text",
            "time"       => "int"
        ],
        "primary_key" => "id"
    ],
    true
);