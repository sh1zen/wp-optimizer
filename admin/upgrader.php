<?php

function wpopt_invert_settings(&$settings, $module_group)
{
    if (!isset($settings[$module_group]))
        return;

    foreach ($settings[$module_group] as &$setting) {
        if (is_bool($setting))
            $setting = !$setting;
    }
}

$_wpopt_settings = WOSettings::get();

// prev -> 1.5
if (!isset($_wpopt_settings['ver'])) {

    WOSettings::getInstance()->reset();

    delete_option("wpopt-imgs--todo");


    // update to 1.5
    $_wpopt_settings['ver'] = "1.5";
}


if (version_compare($_wpopt_settings['ver'], "1.6.0", '<')) {
    // do stuff
}



$_wpopt_settings['ver'] = WPOPT_VERSION;

WOSettings::getInstance()->reset($_wpopt_settings);

unset($_wpopt_settings);