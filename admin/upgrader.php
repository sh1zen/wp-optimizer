<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C)  2022
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

$_wpopt_settings = shzn('wpopt')->settings->get('', ['ver' => '0.0.0']);

\SHZN\core\UtilEnv::handle_upgrade($_wpopt_settings['ver'], WPOPT_VERSION, WPOPT_ADMIN . "upgrades/");

$_wpopt_settings['ver'] = WPOPT_VERSION;

shzn('wpopt')->settings->reset($_wpopt_settings);

unset($_wpopt_settings);