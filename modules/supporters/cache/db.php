<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

use SHZN\core\Cache;

define('WPOPT_DB_ABSPATH', dirname(__FILE__, 4) . '/');

if (file_exists(ABSPATH . WPINC . '/class-wpdb.php')) {
    require_once ABSPATH . WPINC . '/class-wpdb.php';
}
else {
    require_once ABSPATH . WPINC . '/wp-db.php';
}

require_once WPOPT_DB_ABSPATH . 'inc/constants.php';

// no caching during activation or if is admin
if (!((defined('WP_INSTALLING') and WP_INSTALLING) or is_admin())) {
    $GLOBALS['wpdb'] = new WPOPT_DB(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
}

// shzn-framework commons
if (!defined('SHZN_FRAMEWORK')) {

    if (file_exists(WPOPT_DB_ABSPATH . 'vendors/shzn-framework/loader.php')) {
        require_once WPOPT_DB_ABSPATH . 'vendors/shzn-framework/loader.php';
    }
    else {
        require_once dirname(__FILE__, 5) . '/flexy-seo/vendors/shzn-framework/loader.php';
    }
}

shzn(
    'wpopt',
    [
        'use_memcache' => true
    ],
    [
        'cache'   => true,
        'storage' => true,
    ]
);

class WPOPT_DB extends wpdb
{
    /**
     * Class constructor
     * @param $dbuser
     * @param $dbpassword
     * @param $dbname
     * @param $dbhost
     */
    public function __construct($dbuser, $dbpassword, $dbname, $dbhost)
    {
        parent::__construct($dbuser, $dbpassword, $dbname, $dbhost);
    }

    private static function get_cache_group()
    {
        return 'cache/db';
    }

    public function get_var($query = null, $x = 0, $y = 0)
    {
        if ($this->cache_disabled($query)) {
            return parent::get_var($query, $x, $y);
        }

        $key = $this->generate_key($query, $x, $y);

        $result = shzn('wpopt')->storage->get($key, self::get_cache_group());

        if (!$result) {
            $this->timer_start();

            $result = parent::get_var($query, $x, $y);

            if ($this->timer_stop() > WPOPT_CACHE_DB_THRESHOLD_STORE) {
                shzn('wpopt')->storage->set($result, $key, self::get_cache_group(), WPOPT_CACHE_DB_LIFETIME);
            }
        }

        return $result;
    }

    private function cache_disabled($query)
    {
        if ($query and str_contains($query, $this->options)) {
            return true;
        }

        return false;
    }

    private function generate_key($query, ...$args)
    {
        return Cache::generate_key(preg_replace("#{[^}]+}#", "", $query ?: ''), $args);
    }

    public function get_results($query = null, $output = OBJECT)
    {
        if ($this->cache_disabled($query)) {
            return parent::get_results($query, $output);
        }

        $key = $this->generate_key($query);

        $result = shzn('wpopt')->storage->get($key, self::get_cache_group());

        if (!$result) {
            $this->timer_start();

            $result = parent::get_results($query, $output);

            if ($this->timer_stop() > WPOPT_CACHE_DB_THRESHOLD_STORE) {
                shzn('wpopt')->storage->set($result, $key, self::get_cache_group(), WPOPT_CACHE_DB_LIFETIME);
            }
        }

        return $result;
    }

    public function get_col($query = null, $x = 0)
    {
        if ($this->cache_disabled($query)) {
            return parent::get_col($query, $x);
        }

        $key = $this->generate_key($query, $x);

        $result = shzn('wpopt')->storage->get($key, self::get_cache_group());

        if (!$result) {
            $this->timer_start();

            $result = parent::get_col($query, $x);

            if ($this->timer_stop() > WPOPT_CACHE_DB_THRESHOLD_STORE) {
                shzn('wpopt')->storage->set($result, $key, self::get_cache_group(), WPOPT_CACHE_DB_LIFETIME);
            }
        }

        return $result;
    }

    public function get_row($query = null, $output = OBJECT, $y = 0)
    {
        if ($this->cache_disabled($query)) {
            return parent::get_row($query, $output, $y);
        }

        $key = $this->generate_key($query, $y);

        $result = shzn('wpopt')->storage->get($key, self::get_cache_group());

        if (!$result) {
            $this->timer_start();

            $result = parent::get_row($query, $output, $y);

            if ($this->timer_stop() > WPOPT_CACHE_DB_THRESHOLD_STORE) {
                shzn('wpopt')->storage->set($result, $key, self::get_cache_group(), WPOPT_CACHE_DB_LIFETIME);
            }
        }

        return $result;
    }
}
