<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

class UtilEnv
{
    public static function handle_upgrade($ver_start, $ver_to, $upgrade_path)
    {
        $upgrades = array_filter(
            array_map(function ($fname) {
                return basename($fname, ".php");
            }, array_diff(
                    scandir($upgrade_path), array('.', '..'))
            ),
            function ($ver) use ($ver_start, $ver_to) {
                return version_compare($ver, $ver_start, '>') and version_compare($ver, $ver_to, '<=');
            }
        );

        usort($upgrades, 'version_compare');

        $current_ver = $ver_start;

        while (!empty($upgrades)) {

            self::rise_time_limit();

            $next_ver = array_shift($upgrades);

            require_once $upgrade_path . "$next_ver.php";

            $current_ver = $next_ver;
        }

        return $current_ver;
    }

    public static function rise_time_limit($rise_time = false)
    {
        if ($rise_time === false) {
            $rise_time = ini_get('max_execution_time');
        }

        $rise_time = absint($rise_time);

        if (function_exists('set_time_limit') and set_time_limit($rise_time)) {
            return $rise_time;
        }

        return false;
    }

    public static function db_create($table_name, $args, $drop_if_exist = false): array
    {
        global $wpdb;

        if (!str_starts_with($table_name, $wpdb->prefix)) {
            $table_name = $wpdb->prefix . $table_name;
        }

        if ($drop_if_exist) {
            $wpdb->query("DROP TABLE IF EXISTS $table_name");
        }

        $sql = "CREATE TABLE IF NOT EXISTS $table_name ( ";

        foreach ($args['fields'] as $key => $value) {
            $sql .= " $key $value, ";
        }

        if (isset($args['primary_key'])) {
            $sql .= " PRIMARY KEY  ({$args['primary_key']})";
        }

        $sql .= " ) ENGINE=InnoDB " . $wpdb->get_charset_collate() . ";";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        return dbDelta($sql);
    }

    public static function db_search_replace($search, $replace, $table, $column, $where = [])
    {
        global $wpdb;

        $_where = '';

        if (!empty($where)) {

            $conditions = [];

            foreach ($where as $field => $value) {
                $conditions[] = "$field = $value";
            }

            $_where = "WHERE " . implode(' AND ', $conditions);
        }

        return $wpdb->query($wpdb->prepare("UPDATE $table SET $column = REPLACE($column, '%s', '%s') $_where;", $search, $replace));
    }

    public static function array_flatter_one_level($items): array
    {
        if (!is_array($items)) {
            return array($items);
        }

        $res = array();

        foreach ($items as $value) {
            if (is_array($value) and isset($value[0])) {
                $res = array_merge($res, self::array_flatter_one_level($value));
            }
            else {
                $res[] = $value;
            }
        }

        return $res;
    }

    public static function array_flatter($items, $filter_key_format = false, $delimiter = false): array
    {
        if (!is_array($items)) {
            return array($items);
        }

        if ($filter_key_format and !in_array($filter_key_format, array('string', 'numeric', 'bool', 'object'), true)) {
            $filter_key_format = false;
        }

        if ($filter_key_format) {
            $res = array();
            foreach ($items as $key => $value) {

                if ($filter_key_format !== true and !call_user_func("is_{$filter_key_format}", $key)) {
                    $key = array();
                }

                if ($delimiter and is_string($key) and str_starts_with($key, $delimiter)) {
                    $res = array_merge($res, (array)$key);
                }
                else {
                    $res = array_merge($res, (array)$key, self::array_flatter($value, $filter_key_format, $delimiter));
                }
            }
            return $res;
        }

        return array_reduce($items, function ($carry, $item) {
            return array_merge($carry, self::array_flatter($item));
        }, array());
    }

    /**
     * Memoized version of wp_upload_dir.
     */
    public static function wp_upload_dir($part = '')
    {
        static $values_by_blog = array();

        $blog_id = get_current_blog_id();

        if (!isset($values_by_blog[$blog_id])) {
            $values_by_blog[$blog_id] = wp_upload_dir();
        }

        if ($part) {
            return $values_by_blog[$blog_id][$part];
        }

        return $values_by_blog[$blog_id];
    }

    public static function boolean_to_string($bool, $strict = true): string
    {
        if ($strict and !is_bool($bool)) {
            $bool = self::to_boolean($bool);
        }

        if ($bool) {
            return 'true';
        }

        return 'false';
    }

    /**
     * Converts value to boolean
     */
    public static function to_boolean($value, $strict = false): bool
    {
        if (is_string($value)) {
            switch (strtolower($value)) {
                case '+':
                case '1':
                case 'y':
                case 'si':
                case 'on':
                case 'yes':
                case 'true':
                case 'enabled':
                    return true;

                case '-':
                case '0':
                case 'n':
                case 'no':
                case 'off':
                case 'false':
                case 'disabled':
                    return false;
            }
        }

        if ($strict) {
            return $value === true;
        }

        return (!empty($value) or boolval($value));
    }

    public static function convertSecondsToDuration($seconds): array
    {
        $duration = array();

        $units = array(
            YEAR_IN_SECONDS  => [__('Year', 'wps'), __('Years', 'wps')],
            MONTH_IN_SECONDS => [__('Month', 'wps'), __('Months', 'wps')],
            DAY_IN_SECONDS   => [__('Day', 'wps'), __('Days', 'wps')],
            HOUR_IN_SECONDS  => [__('Hour', 'wps'), __('Hours', 'wps')],
        );

        foreach ($units as $value => $unit) {
            $result = floor($seconds / $value);
            if ($result > 0) {
                $duration[_n($unit[0], $unit[1], $result)] = $result;
                $seconds -= $result * $value;
            }
        }

        return $duration;
    }

    /**
     * Quotes regular expression string
     */
    public static function preg_quote($string, $delimiter = '~'): string
    {
        $string = preg_quote($string, $delimiter);
        return strtr($string, array(
            ' ' => '\ '
        ));
    }

    /**
     * Returns real path of given path
     */
    public static function realpath($path, $exist = false, $trailing_slash = false)
    {
        $path = self::normalize_path($path, $trailing_slash);

        if ($exist) {
            $absolutes = realpath($path);
        }
        else {
            $absolutes = array();

            $parts = explode('/', $path);

            foreach ($parts as $part) {
                if ('.' == $part) {
                    continue;
                }
                if ('..' == $part) {
                    array_pop($absolutes);
                }
                else {
                    $absolutes[] = $part;
                }
            }

            $absolutes = implode('/', $absolutes);
        }

        return $absolutes;
    }

    /**
     * Converts path to unix like ./././
     */
    public static function normalize_path(string $path, bool $trailing_slash = false): string
    {
        $wrapper = '';

        // Remove the trailing slash
        if (!$trailing_slash) {
            $path = rtrim($path, '/');
        }
        else {
            $path .= '/';
        }

        if (wp_is_stream($path)) {
            list($wrapper, $path) = explode('://', $path, 2);

            $wrapper .= '://';
        }
        else {
            // Windows paths should uppercase the drive letter.
            if (':' === substr($path, 1, 1)) {
                $path = ucfirst($path);
            }
        }

        // Standardise all paths to use '/' and replace multiple slashes down to a singular.
        $path = preg_replace('#[/\\\]+#', '/', $path);

        return $wrapper . $path;
    }

    public static function path_to_url($path, $file = false): string
    {
        $base_dir = self::normalize_path(ABSPATH, false);

        return site_url(str_replace($base_dir, '', self::normalize_path($path, !$file)));
    }

    /**
     * Get the attachment absolute path from its url
     * @param string $url the attachment url to get its absolute path
     * @return bool|string It returns the absolute path of an attachment
     */
    public static function url_to_path($url)
    {
        if (!is_string($url)) {
            return '';
        }

        $parsed_url_path = parse_url($url, PHP_URL_PATH);

        if (empty($parsed_url_path)) {
            return '';
        }

        return realpath($_SERVER['DOCUMENT_ROOT'] . $parsed_url_path);
    }

    public static function plugin_basename($file): string
    {
        $plugin_dir = self::normalize_path(WP_PLUGIN_DIR);
        $mu_plugin_dir = self::normalize_path(WPMU_PLUGIN_DIR);

        // Get relative path from plugins directory.
        $file = preg_replace('#^' . preg_quote($plugin_dir, '#') . '/|^' . preg_quote($mu_plugin_dir, '#') . '/#', '', self::normalize_path($file));
        return trim($file, '/');
    }

    public static function change_file_extension($file, $extension, $unique = false)
    {
        $old_extension = pathinfo($file, PATHINFO_EXTENSION);

        if ($old_extension) {
            $changed = str_replace($old_extension, $extension, $file);
        }
        else {
            $changed = "$file.$extension";
        }

        if ($unique) {
            $changed = self::unique_filename($changed);
        }

        return $changed;
    }

    public static function unique_filename(string $filename, bool $obfuscation = false): string
    {
        $iter = 0;

        $path_parts = pathinfo($filename);

        $ext = isset($path_parts['extension']) ? '.' . $path_parts['extension'] : '';

        $filename = $obfuscation ? md5(WPS_SALT . $path_parts['filename']) : $path_parts['filename'];

        $path = $path_parts['dirname'] === '.' ? '' : "{$path_parts['dirname']}/";

        do {

            $out_name = $iter > 0 ? "$filename-$iter" : $filename;

            $iter++;

        } while (file_exists($path . $out_name . $ext));

        return $path . $out_name . $ext;
    }

    /**
     * Returns the apache, nginx version
     */
    public static function get_server_version(): string
    {
        $sig = explode('/', $_SERVER['SERVER_SOFTWARE']);
        $temp = isset($sig[1]) ? explode(' ', $sig[1]) : array('0');
        return $temp[0];
    }

    public static function get_server_load($now = true): float
    {
        if ($now and function_exists('sys_getloadavg')) {
            return round(sys_getloadavg()[0], 2);
        }

        $server_load = 0;

        if (stristr(PHP_OS, "win")) {
            $cmd = "wmic cpu get loadpercentage /all";
            @exec($cmd, $output);

            if ($output) {
                foreach ($output as $line) {
                    if ($line && preg_match("/^[0-9]+\$/", $line)) {
                        $server_load = $line;
                        break;
                    }
                }
            }
        }
        else {
            if (@is_readable("/proc/stat")) {

                // Collect 2 samples - each with 1 second period
                // See: https://de.wikipedia.org/wiki/Load#Der_Load_Average_auf_Unix-Systemen
                $stats = @file_get_contents("/proc/stat");

                if ($stats !== false) {
                    // Remove double spaces to make it easier to extract values with explode()
                    $stats = preg_replace("/[[:blank:]]+/", " ", $stats);

                    // Separate lines
                    $stats = preg_replace("#\n+#", "\n", str_replace("\r", "\n", $stats));
                    $stats = explode("\n", $stats);

                    // Separate values and find line for main CPU load
                    foreach ($stats as $statLine) {

                        $statLine = trim($statLine);

                        if (!str_starts_with($statLine, 'cpu')) {
                            continue;
                        }

                        $statLineData = explode(" ", trim($statLine));

                        if (isset($statLineData[4])) {

                            // Sum up the 4 values for User, Nice, System and Idle and calculate
                            // the percentage of idle time (which is part of the 4 values!)
                            $cpuTime = (int)$statLineData[1] + (int)$statLineData[2] + (int)$statLineData[3] + (int)$statLineData[4];

                            // Invert percentage to get CPU time, not idle time
                            $server_load = 100 - ((int)$statLineData[4] * 100 / $cpuTime);
                            break;
                        }
                    }
                }
            }

            if (!$server_load) {
                if (@file_exists('/proc/loadavg')) {

                    if ($fh = @fopen('/proc/loadavg', 'r')) {
                        $data = @fread($fh, 6);
                        @fclose($fh);
                        $load_avg = explode(" ", $data);
                        $server_load = trim($load_avg[0]);
                    }
                }
                else {

                    $data = @system('uptime');
                    preg_match('/(.*):(.*)/', $data, $matches);
                    $load_arr = explode(',', $matches[2]);
                    $server_load = trim($load_arr[0]);
                }
            }
        }

        if (empty($server_load)) {
            $server_load = 0;
        }

        return round((int)$server_load, 2);
    }

    public static function size2bytes($val): int
    {
        $val = trim($val);

        if (empty($val)) {
            return 0;
        }

        $val = preg_replace('/[^\dkmgtb]/', '', strtolower($val));

        if (!preg_match("/\b(\d+(?:\.\d+)?)\s*([kmgt]?b)\b/", trim($val), $matches)) {
            return absint($val);
        }

        $val = absint($matches[1]);

        switch ($matches[2]) {
            case 'gb':
                $val *= 1024;
            case 'mb':
                $val *= 1024;
            case 'kb':
                $val *= 1024;
        }

        return $val;
    }

    public static function download_file($file_path, $delete = false)
    {
        $file_path = trim($file_path);

        if (!file_exists($file_path) or headers_sent())
            return false;

        ob_start();

        header('Expires: 0');
        header("Cache-Control: private");
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Content-Description: File Transfer');
        header('Content-Type: application/force-download');
        header('Content-Type: application/octet-stream');
        header('Content-Type: application/download');
        header('Content-Disposition: attachment; filename=' . basename($file_path) . ';');
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . filesize($file_path));

        ob_clean();
        flush();

        $chunkSize = 1024 * 1024;
        $handle = fopen($file_path, 'rb');

        while (!feof($handle)) {
            $buffer = fread($handle, $chunkSize);
            echo $buffer;
            ob_flush();
            flush();
        }
        fclose($handle);

        if ($delete) {
            unlink($file_path);
        }

        exit();
    }

    /**
     *
     * @param int $current Current number.
     * @param int $total Total number.
     * @return string Number in percentage
     */
    public static function format_percentage($current, $total)
    {
        if ($total == 0)
            return 0;

        return ($total > 0 ? round(($current / $total) * 100, 2) : 0) . '%';
    }

    /**
     * check if time left is more than a margin otherwise try to rise it
     *
     * @param int $margin
     * @param int $extend
     * @return int|bool
     */
    public static function safe_time_limit(int $margin = 0, int $extend = 0)
    {
        static $time_reset = WP_START_TIMESTAMP;
        static $max_execution_time = null;

        if (is_null($max_execution_time)) {
            $max_execution_time = absint(ini_get('max_execution_time'));
        }

        if ($max_execution_time === 0) {
            return true;
        }

        $left_time = $max_execution_time - (microtime(true) - $time_reset);

        if ($margin > $left_time) {

            if ($extend and ($extended = self::rise_time_limit($extend)) !== false) {
                $time_reset = microtime(true);
                $max_execution_time = $extended;
                return $extended;
            }

            return false;
        }

        return $left_time;
    }

    public static function verify_nonce($name, $nonce = false)
    {
        if (!$nonce) {
            if (isset($_REQUEST['_ajax_nonce'])) {
                $nonce = $_REQUEST['_ajax_nonce'];
            }
            elseif (isset($_REQUEST['_wpnonce'])) {
                $nonce = $_REQUEST['_wpnonce'];
            }
        }

        return \wp_verify_nonce($nonce, $name);
    }

    public static function relativePath(string $from, string $to): string
    {
        $fromA = \explode('/', \rtrim($from, '/'));
        $toA = \explode('/', $to);

        $descend = [];
        $ascend = [];

        for ($i = 0; $i < max(count($fromA), count($toA)); $i++) {

            if (!isset($fromA[$i], $toA[$i]) or $fromA[$i] !== $toA[$i]) {

                if (isset($fromA[$i])) {
                    $ascend[] = '..';
                }

                if (isset($toA[$i])) {
                    $descend[] = $toA[$i];
                }
            }
        }

        if (empty($ascend)) {
            $ascend = ['.'];
        }

        return implode('/', array_merge($ascend, $descend));
    }

    public static function epochs_timestamp($epoch, $current_time = false): array
    {
        if (!$current_time) {
            $current_time = time();
        }

        switch ($epoch) {
            case 'today':
                $start_time = mktime(0, 0, 0, date('m', $current_time), date('d', $current_time), date('Y', $current_time));
                $end_time = $start_time + DAY_IN_SECONDS;
                break;
            case 'yesterday':
                $end_time = mktime(0, 0, 0, date('m', $current_time), date('d', $current_time), date('Y', $current_time));
                $start_time = $end_time - DAY_IN_SECONDS;
                break;
            case 'week':
                $end_time = mktime(23, 59, 59, date('m', $current_time), date('d', $current_time), date('Y', $current_time));
                $start_time = $end_time + 1 - WEEK_IN_SECONDS;
                break;
            case 'month':
                $end_time = mktime(23, 59, 59, date('m', $current_time), date('d', $current_time), date('Y', $current_time));
                $start_time = $end_time + 1 - MONTH_IN_SECONDS;
                break;
            default:
                $date_array = explode('/', $epoch);

                if (count($date_array) === 3) {
                    $start_time = mktime(0, 0, 0, (int)$date_array[1], (int)$date_array[0], (int)$date_array[2]);
                    $end_time = mktime(23, 59, 59, (int)$date_array[1], (int)$date_array[0], (int)$date_array[2]);
                }
                break;
        }

        return [$start_time ?? 0, $end_time ?? 0];
    }

    /**
     * Check if URL is valid
     */
    public static function is_url($url): bool
    {
        return is_string($url) and preg_match('#^(https?:)?//#', $url);
    }

    public static function is_html($string): bool
    {
        return is_string($string) and preg_match("#<[^>]*>#", $string);
    }

    public static function is_this_site(string $url): bool
    {
        return str_starts_with($url, get_option('home'));
    }

    public static function is_function_disabled($function_name): bool
    {
        return in_array($function_name, array_map('trim', explode(',', ini_get('disable_functions'))), true);
    }

    public static function is_shell_exec_available(): bool
    {
        if (self::is_safe_mode_active())
            return false;

        // Is shell_exec or escapeshellcmd or escapeshellarg disabled?
        if (array_intersect(array('shell_exec', 'escapeshellarg', 'escapeshellcmd'), array_map('trim', explode(',', @ini_get('disable_functions')))))
            return false;

        // Can we issue a simple echo command?
        if (!@shell_exec('echo WP Backup'))
            return false;

        return true;
    }

    public static function is_safe_mode_active($ini_get_callback = 'ini_get'): bool
    {
        if (($safe_mode = @call_user_func($ini_get_callback, 'safe_mode')) and strtolower($safe_mode) != 'off')
            return true;

        return false;
    }

    public static function is_safe_buffering(): bool
    {
        $noOptimize = false;

        // Checking for DONOTMINIFY constant as used by e.g. WooCommerce POS.
        if (defined('DONOTMINIFY') and UtilEnv::to_boolean(constant('DONOTMINIFY'), true)) {
            $noOptimize = true;
        }

        // And make sure pagebuilder previews don't get optimized HTML/ JS/ CSS/ ...
        if (false === $noOptimize) {
            $_qs_pageBuilders = array('tve', 'elementor-preview', 'fl_builder', 'vc_action', 'et_fb', 'bt-beaverbuildertheme', 'ct_builder', 'fb-edit', 'siteorigin_panels_live_editor');
            foreach ($_qs_pageBuilders as $pageBuilder) {
                if (isset($_GET[$pageBuilder])) {
                    $noOptimize = true;
                    break;
                }
            }
        }

        // Also honor PageSpeed=off parameter as used by mod_pagespeed, in use by some pagebuilders,
        // see https://www.modpagespeed.com/doc/experiment#ModPagespeed for info on that.
        if (false === $noOptimize and isset($_GET['PageSpeed']) and 'off' === $_GET['PageSpeed']) {
            $noOptimize = true;
        }

        // Check for site being previewed in the Customizer (available since WP 4.0).
        $is_customize_preview = false;
        if (function_exists('is_customize_preview') and \is_customize_preview()) {
            $is_customize_preview = \is_customize_preview();
        }

        /**
         * We only buffer the frontend requests (and then only if not a feed
         * and not turned off explicitly and not when being previewed in Customizer)!
         */
        return (!\is_admin() and !\is_feed() and !\is_embed() and !$noOptimize and !$is_customize_preview);
    }

    /**
     * Returns true if server is Apache
     */
    public static function is_apache(): bool
    {
        // assume apache when unknown, since most common
        if (empty($_SERVER['SERVER_SOFTWARE']))
            return true;

        return stristr($_SERVER['SERVER_SOFTWARE'], 'Apache') !== false;
    }

    /**
     * Check whether server is LiteSpeed
     */
    public static function is_litespeed(): bool
    {
        return isset($_SERVER['SERVER_SOFTWARE']) and stristr($_SERVER['SERVER_SOFTWARE'], 'LiteSpeed') !== false;
    }

    /**
     * Returns true if server is nginx
     */
    public static function is_nginx(): bool
    {
        return isset($_SERVER['SERVER_SOFTWARE']) and stristr($_SERVER['SERVER_SOFTWARE'], 'nginx') !== false;
    }

    /**
     * Returns if there is multisite mode
     */
    public static function is_wpmu(): bool
    {
        static $wpmu = null;

        if ($wpmu === null) {
            $wpmu = (file_exists(ABSPATH . 'wpmu-settings.php') || (defined('MULTISITE') and MULTISITE) || defined('SUNRISE') || self::is_wpmu_subdomain());
        }

        return $wpmu;
    }

    /**
     * Returns true if WPMU uses vhosts
     */
    public static function is_wpmu_subdomain(): bool
    {
        return ((defined('SUBDOMAIN_INSTALL') and SUBDOMAIN_INSTALL) ||
            (defined('VHOST') and VHOST == 'yes'));
    }

    /**
     * Returns true if current connection is secure
     */
    public static function is_https(): bool
    {
        return isset($_SERVER['HTTPS']) and self::to_boolean($_SERVER['HTTPS']) or (isset($_SERVER['SERVER_PORT']) and (int)$_SERVER['SERVER_PORT'] == 443) or (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) and $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https');
    }

    public static function normalize_url(string $url): string
    {
        // Parse the URL into its components
        $urlComponents = parse_url($url);

        // Make sure the scheme and host are in lowercase
        $urlComponents['scheme'] = strtolower($urlComponents['scheme']);
        $urlComponents['host'] = strtolower($urlComponents['host']);

        // Remove default ports (e.g., :80 for HTTP, :443 for HTTPS)
        if (isset($urlComponents['port'])) {
            $defaultPorts = [
                'http'  => 80,
                'https' => 443,
                // Add other default ports if necessary
            ];

            if (isset($defaultPorts[$urlComponents['scheme']]) && $urlComponents['port'] == $defaultPorts[$urlComponents['scheme']]) {
                unset($urlComponents['port']);
            }
        }

        // Remove consecutive double slashes in the path
        if (isset($urlComponents['path'])) {
            $urlComponents['path'] = preg_replace('#/+#', '/', $urlComponents['path']);
        }

        // Reassemble the normalized URL
        $normalizedUrl = $urlComponents['scheme'] . '://';
        if (isset($urlComponents['user'])) {
            $normalizedUrl .= $urlComponents['user'];
            if (isset($urlComponents['pass'])) {
                $normalizedUrl .= ':' . $urlComponents['pass'];
            }
            $normalizedUrl .= '@';
        }
        $normalizedUrl .= $urlComponents['host'];
        if (isset($urlComponents['port'])) {
            $normalizedUrl .= ':' . $urlComponents['port'];
        }
        if (isset($urlComponents['path'])) {
            $normalizedUrl .= $urlComponents['path'];
        }
        if (isset($urlComponents['query'])) {
            $normalizedUrl .= '?' . $urlComponents['query'];
        }
        if (isset($urlComponents['fragment'])) {
            $normalizedUrl .= '#' . $urlComponents['fragment'];
        }

        return $normalizedUrl;
    }

    public static function filesize($path, bool $pre_clear_cache = false): int
    {
        $size = 0;
        if (file_exists($path)) {
            if ($pre_clear_cache) {
                clearstatcache(true, $path);
            }
            $size = (int)(@filesize($path) ?: 0);
        }

        return $size;
    }

    public static function table_exist(string $table_name): bool
    {
        global $wpdb;
        static $tables = [];

        if (empty($table_name)) {
            return false;
        }

        if (empty($tables)) {
            $tables = array_flip($wpdb->get_col("SHOW TABLES"));
        }

        return isset($tables[$table_name]);
    }
}