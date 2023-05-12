<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

use SHZN\core\Disk;
use SHZN\core\UtilEnv;

Disk::make_path(WPOPT_STORAGE, true);
Disk::make_path(WPOPT_STORAGE . 'minify/js/', true);
Disk::make_path(WPOPT_STORAGE . 'minify/css/', true);

$_wpopt_settings = shzn('wpopt')->settings->get('', ['ver' => '0.0.0']);

UtilEnv::handle_upgrade($_wpopt_settings['ver'], WPOPT_VERSION, WPOPT_ADMIN . "upgrades/");

$_wpopt_settings['ver'] = WPOPT_VERSION;

shzn('wpopt')->settings->reset($_wpopt_settings);

unset($_wpopt_settings);

