<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

/**
 * Uninstall Procedure
 */
// if uninstall.php is not called by WordPress, die
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die();
}

// setup constants
require_once __DIR__ . '/inc/wps_and_constants.php';
require_once WPOPT_INCPATH . 'functions.php';
require_once WPOPT_INCPATH . 'uninstall.php';

wpopt_uninstall_network_data();
