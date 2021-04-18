<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C)  2021
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

use WPOptimizer\core\EnvUtil;

WPOptimizer\core\Settings::getInstance()->reset();

delete_option("wpopt-imgs--todo");

EnvUtil::create_db("wpopt_core", [
        "fields"      => [
            "id"    => "bigint NOT NULL AUTO_INCREMENT",
            "item"  => "varchar(255)",
            "value" => "longtext NOT NULL",
        ],
        "primary_key" => "id"
    ]
);