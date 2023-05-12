<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

define('WPOPT_DEBUG', $_SERVER["SERVER_ADDR"] === '127.0.0.1');

const WPOPT_STORAGE = WP_CONTENT_DIR . '/wpopt/';

if (!defined("WPOPT_CACHE_DB_THRESHOLD_STORE")) {
    define('WPOPT_CACHE_DB_THRESHOLD_STORE', 0.001);
}

if (!defined("WPOPT_CACHE_DB_LIFETIME")) {
    define('WPOPT_CACHE_DB_LIFETIME', HOUR_IN_SECONDS);
}

if (!defined("WPOPT_CACHE_DB_OPTIONS")) {
    define('WPOPT_CACHE_DB_OPTIONS', false);
}
