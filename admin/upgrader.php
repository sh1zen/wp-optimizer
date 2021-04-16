<?php

use WPOptimizer\core\EnvUtil;

function wpopt_invert_settings(&$settings, $module_group)
{
    if (!isset($settings[$module_group]))
        return;

    foreach ($settings[$module_group] as &$setting) {
        if (is_bool($setting))
            $setting = !$setting;
    }
}

$_wpopt_settings = WPOptimizer\core\Settings::get();

// prev -> 1.5.0
if (!isset($_wpopt_settings['ver']) or version_compare($_wpopt_settings['ver'], "1.5.0", '<')) {

    WPOptimizer\core\Settings::getInstance()->reset();

    delete_option("wpopt-imgs--todo");

    // update to 1.5.0
    $_wpopt_settings['ver'] = "1.5.0";

    EnvUtil::create_db("wpopt_core", [
            "fields"      => [
                "id"    => "bigint NOT NULL AUTO_INCREMENT",
                "item"  => "varchar(255)",
                "value" => "longtext NOT NULL",
            ],
            "primary_key" => "id"
        ]
    );
}


if (version_compare($_wpopt_settings['ver'], "1.6.0", '<')) {
    // do stuff
}


$_wpopt_settings['ver'] = WPOPT_VERSION;

WPOptimizer\core\Settings::getInstance()->reset($_wpopt_settings);

unset($_wpopt_settings);