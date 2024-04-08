<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

define('WPOPT_DEBUG', !wps_core()->online);

if (!defined("WPOPT_CACHE_DB_THRESHOLD_STORE")) {
    define('WPOPT_CACHE_DB_THRESHOLD_STORE', 0.001);
}

if (!defined("WPOPT_CACHE_DB_LIFETIME")) {
    define('WPOPT_CACHE_DB_LIFETIME', HOUR_IN_SECONDS);
}

if (!defined("WPOPT_CACHE_DB_OPTIONS")) {
    define('WPOPT_CACHE_DB_OPTIONS', false);
}
