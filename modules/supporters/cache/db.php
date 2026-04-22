<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

use WPS\core\Cache;

if (!defined('ABSPATH')) {
    return;
}

// WP 6.1+: wp-db.php è deprecato, usa class-wpdb.php
if (file_exists(ABSPATH . WPINC . '/class-wpdb.php')) {
    require_once ABSPATH . WPINC . '/class-wpdb.php';
} else {
    // fallback legacy
    require_once ABSPATH . WPINC . '/wp-db.php';
}

/**
 * IMPORTANT:
 * In db.php drop-in we are loaded during require_wp_db() very early.
 * DO NOT call WP functions like is_admin(), wp_doing_ajax(), wp_doing_cron(), get_option(), etc. here.
 *
 * Also wps('wpopt') framework may not be ready yet.
 */
$WPOPT_BOOTSTRAP_EARLY = true;

// We can consider "not early" only if pluggable set of functions is available.
// (is_admin is in wp-includes/load.php, but it relies on globals and constants that may not be stable here)
if (function_exists('did_action') && did_action('plugins_loaded')) {
    $WPOPT_BOOTSTRAP_EARLY = false;
}

$GLOBALS['wpdb'] = new WPOPT_DB(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);

/**
 * If you REALLY must avoid caching in admin/activation, do it lazily inside cache_disabled()
 * when WP functions are available, never here at top-level.
 */

class WPOPT_DB extends wpdb
{
    private static function get_cache_group(): string
    {
        return 'cache/db';
    }

    /**
     * Cache is only enabled when the WPS framework storage is available
     * AND WP is sufficiently initialized to evaluate context (admin/ajax/cron).
     */
    private function cache_ready(): bool
    {
        // WPOPT constants must exist
        if (!defined('WPOPT_ABSPATH')) {
            return false;
        }

        // Framework function must exist
        if (!function_exists('wps')) {
            return false;
        }

        // wps('wpopt') may throw if container not ready; guard hard.
        try {
            $wpopt = wps('wpopt');
        } catch (\Throwable $e) {
            return false;
        }

        // storage must exist and be usable
        return isset($wpopt->storage) && $wpopt->storage;
    }

    private function cache_disabled($query): bool
    {
        // No query => no cache
        if (!$query) {
            return true;
        }

        // If cache backend/framework not ready => disable caching (but still work)
        if (!$this->cache_ready()) {
            return true;
        }

        // Admin/AJAX/CRON checks are only safe when WP functions exist.
        // If they don't exist yet, assume early bootstrap => disable caching.
        if (!function_exists('is_admin') || !function_exists('wp_doing_cron') || !function_exists('wp_doing_ajax')) {
            return true;
        }

        // Disable cache in these contexts
        if (is_admin() || wp_doing_cron() || wp_doing_ajax()) {
            return true;
        }

        // Optional: don't cache options queries
        if (defined('WPOPT_CACHE_DB_OPTIONS') && !WPOPT_CACHE_DB_OPTIONS) {
            // $this->options is wpdb property holding options table name
            if (is_string($this->options) && str_contains($query, $this->options)) {
                return true;
            }
        }

        return false;
    }

    private function generate_key($query, ...$args): string
    {
        // preg_replace prevents different keys when query contains LIKE %% passed to $wpdb->prepare(...)
        return Cache::generate_key(preg_replace("#{[^}]+}#", "", $query ?: ''), $args);
    }

    public function get_var($query = null, $x = 0, $y = 0)
    {
        if ($this->cache_disabled($query)) {
            return parent::get_var($query, $x, $y);
        }

        $key = $this->generate_key($query, $x, $y);

        $wpopt = wps('wpopt');
        $result = $wpopt->storage->get($key, self::get_cache_group());

        if (!$result) {
            if (function_exists('wpopt_record_cache_metric')) {
                wpopt_record_cache_metric('db', 'miss');
            }

            $this->timer_start();
            $result = parent::get_var($query, $x, $y);

            if (defined('WPOPT_CACHE_DB_THRESHOLD_STORE') && defined('WPOPT_CACHE_DB_LIFETIME')) {
                if ($this->timer_stop() > WPOPT_CACHE_DB_THRESHOLD_STORE) {
                    $wpopt->storage->set($result, $key, self::get_cache_group(), WPOPT_CACHE_DB_LIFETIME);
                }
            }
        }
        elseif (function_exists('wpopt_record_cache_metric')) {
            wpopt_record_cache_metric('db', 'hit');
        }

        return $result;
    }

    public function get_results($query = null, $output = OBJECT)
    {
        if ($this->cache_disabled($query)) {
            return parent::get_results($query, $output);
        }

        $key = $this->generate_key($query);

        $wpopt = wps('wpopt');
        $result = $wpopt->storage->get($key, self::get_cache_group());

        if (!$result) {
            if (function_exists('wpopt_record_cache_metric')) {
                wpopt_record_cache_metric('db', 'miss');
            }

            $this->timer_start();
            $result = parent::get_results($query, $output);

            if (defined('WPOPT_CACHE_DB_THRESHOLD_STORE') && defined('WPOPT_CACHE_DB_LIFETIME')) {
                if ($this->timer_stop() > WPOPT_CACHE_DB_THRESHOLD_STORE) {
                    $wpopt->storage->set($result, $key, self::get_cache_group(), WPOPT_CACHE_DB_LIFETIME);
                }
            }
        }
        elseif (function_exists('wpopt_record_cache_metric')) {
            wpopt_record_cache_metric('db', 'hit');
        }

        return $result;
    }

    public function get_col($query = null, $x = 0)
    {
        if ($this->cache_disabled($query)) {
            return parent::get_col($query, $x);
        }

        $key = $this->generate_key($query, $x);

        $wpopt = wps('wpopt');
        $result = $wpopt->storage->get($key, self::get_cache_group());

        if (!$result) {
            if (function_exists('wpopt_record_cache_metric')) {
                wpopt_record_cache_metric('db', 'miss');
            }

            $this->timer_start();
            $result = parent::get_col($query, $x);

            if (defined('WPOPT_CACHE_DB_THRESHOLD_STORE') && defined('WPOPT_CACHE_DB_LIFETIME')) {
                if ($this->timer_stop() > WPOPT_CACHE_DB_THRESHOLD_STORE) {
                    $wpopt->storage->set($result, $key, self::get_cache_group(), WPOPT_CACHE_DB_LIFETIME);
                }
            }
        }
        elseif (function_exists('wpopt_record_cache_metric')) {
            wpopt_record_cache_metric('db', 'hit');
        }

        return $result;
    }

    public function get_row($query = null, $output = OBJECT, $y = 0)
    {
        if ($this->cache_disabled($query)) {
            return parent::get_row($query, $output, $y);
        }

        $key = $this->generate_key($query, $y);

        $wpopt = wps('wpopt');
        $result = $wpopt->storage->get($key, self::get_cache_group());

        if (!$result) {
            if (function_exists('wpopt_record_cache_metric')) {
                wpopt_record_cache_metric('db', 'miss');
            }

            $this->timer_start();
            $result = parent::get_row($query, $output, $y);

            if (defined('WPOPT_CACHE_DB_THRESHOLD_STORE') && defined('WPOPT_CACHE_DB_LIFETIME')) {
                if ($this->timer_stop() > WPOPT_CACHE_DB_THRESHOLD_STORE) {
                    $wpopt->storage->set($result, $key, self::get_cache_group(), WPOPT_CACHE_DB_LIFETIME);
                }
            }
        }
        elseif (function_exists('wpopt_record_cache_metric')) {
            wpopt_record_cache_metric('db', 'hit');
        }

        return $result;
    }
}
