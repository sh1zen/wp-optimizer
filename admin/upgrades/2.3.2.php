<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

global $wpdb;

WPS\core\UtilEnv::db_create(
    WPOPT_TABLE_LOG_MAILS,
    [
        "fields"      => [
            "id"               => "bigint NOT NULL AUTO_INCREMENT",
            "to_email"         => "varchar(255) NOT NULL",
            "subject"          => "varchar(255) NOT NULL",
            "message"          => "longtext NOT NULL",
            "headers"          => "TEXT NOT NULL",
            "sent_date"        => "DATETIME DEFAULT CURRENT_TIMESTAMP",
            "sent_date_gmt"    => "DATETIME DEFAULT NULL",
            "attachments_file" => "TEXT",
        ],
        "primary_key" => "id"
    ],
    true
);

