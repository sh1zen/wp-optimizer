<?php

require_once ABSPATH . WPINC . '/wp-db.php';
require_once WP_CONTENT_DIR . "/plugins/wp-optimizer/inc/WOStorage.class.php";

// no caching during activation
$is_installing = (defined('WP_INSTALLING') and WP_INSTALLING);

if ($is_installing or is_admin()) {
    $GLOBALS['wpdb'] = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
}
else {
    $GLOBALS['wpdb'] = new WPOPT_DB(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);

    WOStorage::getInstance();
}

if (!defined("WPOPT_DB_TIME_to_STORE"))
    define('WPOPT_DB_TIME_to_STORE', 0.001);

if (!defined("WPOPT_DB_STORAGE_LIFESPAN"))
    define('WPOPT_DB_STORAGE_LIFESPAN', MINUTE_IN_SECONDS * 10);


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

        add_action('clean_post_cache', 'WPOPT_DB::clear_cache', 10, 0);
        add_action('clean_page_cache', 'WPOPT_DB::clear_cache', 10, 0);
        add_action('clean_attachment_cache', 'WPOPT_DB::clear_cache', 10, 0);
        add_action('clean_comment_cache', 'WPOPT_DB::clear_cache', 10, 0);

        add_action('clean_term_cache', 'WPOPT_DB::clear_cache', 10, 0);
        add_action('clean_object_term_cache', 'WPOPT_DB::clear_cache', 10, 0);
        add_action('clean_taxonomy_cache', 'WPOPT_DB::clear_cache', 10, 0);

        add_action('clean_user_cache', 'WPOPT_DB::clear_cache', 10, 0);
    }

    public static function clear_cache()
    {
        WOStorage::getInstance()->delete(self::get_cache_group());
    }

    private static function get_cache_group()
    {
        return 'cache/db';
    }

    public function get_var($query = null, $x = 0, $y = 0)
    {
        if ($this->cache_disabled($query))
            return parent::get_var($query, $x, $y);

        $key = $this->generate_key($query, $x, $y);

        $result = WOStorage::getInstance()->get($key, self::get_cache_group());

        if (!$result) {
            $this->timer_start();

            $result = parent::get_var($query, $x, $y);

            if ($this->timer_stop() > WPOPT_DB_TIME_to_STORE) {
                WOStorage::getInstance()->set($result, self::get_cache_group(), $key, WPOPT_DB_STORAGE_LIFESPAN);
            }
        }

        return $result;
    }

    private function cache_disabled($query)
    {
        if (preg_match('/' . $this->options . '/', $query)) {
            return true;
        }

        return false;
    }

    private function generate_key($query, ...$args)
    {
        return WOStorage::generate_key(preg_replace("~{[^}]+}~", "", $query), $args);
    }

    public function get_results($query = null, $output = OBJECT)
    {
        if ($this->cache_disabled($query))
            return parent::get_results($query, $output);

        $key = $this->generate_key($query);

        $result = WOStorage::getInstance()->get($key, self::get_cache_group());

        if (!$result) {
            $this->timer_start();

            $result = parent::get_results($query, $output);

            if ($this->timer_stop() > WPOPT_DB_TIME_to_STORE) {
                WOStorage::getInstance()->set($result, self::get_cache_group(), $key, WPOPT_DB_STORAGE_LIFESPAN);
            }
        }

        return $result;
    }

    public function get_col($query = null, $x = 0)
    {
        if ($this->cache_disabled($query))
            return parent::get_col($query, $x);

        $key = $this->generate_key($query, $x);

        $result = WOStorage::getInstance()->get($key, self::get_cache_group());

        if (!$result) {
            $this->timer_start();

            $result = parent::get_col($query, $x);

            if ($this->timer_stop() > WPOPT_DB_TIME_to_STORE) {
                WOStorage::getInstance()->set($result, self::get_cache_group(), $key, WPOPT_DB_STORAGE_LIFESPAN);
            }
        }

        return $result;
    }

    public function get_row($query = null, $output = OBJECT, $y = 0)
    {
        if ($this->cache_disabled($query))
            return parent::get_row($query, $output, $y);

        $key = $this->generate_key($query, $y);

        $result = WOStorage::getInstance()->get($key, self::get_cache_group());

        if (!$result) {
            $this->timer_start();

            $result = parent::get_row($query, $output, $y);

            if ($this->timer_stop() > WPOPT_DB_TIME_to_STORE) {
                WOStorage::getInstance()->set($result, self::get_cache_group(), $key, WPOPT_DB_STORAGE_LIFESPAN);
            }
        }

        return $result;
    }

}
