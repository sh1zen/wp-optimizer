<?php

$_wpopt_settings = WPOptimizer\core\Settings::get();

// prev -> 1.5.0
if (!isset($_wpopt_settings['ver'])) {

    require_once __DIR__ . "/upgrades/1.5.0.php";

    $_wpopt_settings['ver'] = "1.5.0";
}

wpopt_handle_upgrade($_wpopt_settings['ver'], WPOPT_VERSION);

$_wpopt_settings['ver'] = WPOPT_VERSION;

WPOptimizer\core\Settings::getInstance()->reset($_wpopt_settings);

unset($_wpopt_settings);