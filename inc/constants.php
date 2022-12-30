<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C)  2022
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

define('WPOPT_DEBUG', $_SERVER["SERVER_ADDR"] === '127.0.0.1');

const WPOPT_STORAGE = WP_CONTENT_DIR . '/wpopt/';

if (!defined("WPOPT_CACHE_DB_THRESHOLD_STORE")) {
    define('WPOPT_CACHE_DB_THRESHOLD_STORE', 0.001);
}

if (!defined("WPOPT_CACHE_DB_LIFETIME")) {
    define('WPOPT_CACHE_DB_LIFETIME', MINUTE_IN_SECONDS * 30);
}

const WPOPT_MARKER_BEGIN_WORDPRESS = '# BEGIN WordPress';
const WPOPT_MARKER_END_WORDPRESS = '# END WordPress';

const WPOPT_MARKER_BEGIN_MIME_TYPES = '# WPOPT_MARKER_BEGIN_SRV_MIME_TYPES';
const WPOPT_MARKER_END_MIME_TYPES = '# WPOPT_MARKER_END_SRV_MIME_TYPES';


