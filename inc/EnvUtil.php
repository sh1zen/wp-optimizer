<?php

namespace WPOptimizer\core;

class EnvUtil
{
    public static function filename_to_url($filename, $use_site_url = false)
    {
        // using wp-content instead of document_root as known dir since dirbased
        // multisite wp adds blogname to the path inside site_url
        if (substr($filename, 0, strlen(WP_CONTENT_DIR)) != WP_CONTENT_DIR)
            return '';

        $uri_from_wp_content = substr($filename, strlen(WP_CONTENT_DIR));

        if (DIRECTORY_SEPARATOR != '/')
            $uri_from_wp_content = str_replace(DIRECTORY_SEPARATOR, '/',
                $uri_from_wp_content);

        return content_url($uri_from_wp_content);
    }

    /**
     * Returns true if server is Apache
     *
     * @return boolean
     */
    public static function is_apache()
    {
        // assume apache when unknown, since most common
        if (empty($_SERVER['SERVER_SOFTWARE']))
            return true;

        return isset($_SERVER['SERVER_SOFTWARE']) and stristr($_SERVER['SERVER_SOFTWARE'], 'Apache') !== false;
    }

    /**
     * Check whether server is LiteSpeed
     *
     * @return bool
     */
    public static function is_litespeed()
    {
        return isset($_SERVER['SERVER_SOFTWARE']) and stristr($_SERVER['SERVER_SOFTWARE'], 'LiteSpeed') !== false;
    }

    /**
     * Returns true if server is nginx
     *
     * @return boolean
     */
    public static function is_nginx()
    {
        return isset($_SERVER['SERVER_SOFTWARE']) and stristr($_SERVER['SERVER_SOFTWARE'], 'nginx') !== false;
    }

    /**
     * Returns true if server is nginx
     *
     * @return boolean
     */
    public static function is_iis()
    {
        return isset($_SERVER['SERVER_SOFTWARE']) and stristr($_SERVER['SERVER_SOFTWARE'], 'IIS') !== false;
    }

    /**
     * Returns domain from host
     *
     * @param $url
     * @return string
     */
    public static function url_to_host($url)
    {
        $a = parse_url($url);
        if (isset($a['host']))
            return $a['host'];

        return '';
    }

    /**
     * Memoized version of wp_upload_dir. That function is quite slow
     * for a number of times CDN calls it
     */
    public static function wp_upload_dir()
    {
        static $values_by_blog = array();

        $blog_id = get_current_blog_id();

        if (!isset($values_by_blog[$blog_id]))
            $values_by_blog[$blog_id] = wp_upload_dir();

        return $values_by_blog[$blog_id];
    }

    /**
     * Returns if there is multisite mode
     *
     * @return boolean
     */
    public static function is_wpmu()
    {
        static $wpmu = null;

        if ($wpmu === null) {
            $wpmu = (file_exists(ABSPATH . 'wpmu-settings.php') ||
                (defined('MULTISITE') and MULTISITE) ||
                defined('SUNRISE') ||
                EnvUtil::is_wpmu_subdomain());
        }

        return $wpmu;
    }

    /**
     * Returns true if WPMU uses vhosts
     *
     * @return boolean
     */
    public static function is_wpmu_subdomain()
    {
        return ((defined('SUBDOMAIN_INSTALL') and SUBDOMAIN_INSTALL) ||
            (defined('VHOST') and VHOST == 'yes'));
    }

    /**
     * Returns SSL home url
     *
     * @return string
     */
    public static function home_url_maybe_https()
    {
        $home_url = get_home_url();
        return EnvUtil::url_to_maybe_https($home_url);
    }

    /**
     * Returns SSL URL if current connection is https
     *
     * @param string $url
     * @return string
     */
    public static function url_to_maybe_https($url)
    {
        if (EnvUtil::is_https()) {
            $url = str_replace('http://', 'https://', $url);
        }

        return $url;
    }

    /**
     * Returns true if current connection is secure
     *
     * @return boolean
     */
    public static function is_https()
    {
        switch (true) {
            case (isset($_SERVER['HTTPS']) and
                EnvUtil::to_boolean($_SERVER['HTTPS'])):
            case (isset($_SERVER['SERVER_PORT']) and
                (int)$_SERVER['SERVER_PORT'] == 443):
            case (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) and
                $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https'):
                return true;
        }

        return false;
    }

    /**
     * Converts value to boolean
     *
     * @param mixed $value
     * @return boolean
     */
    public static function to_boolean($value)
    {
        if (is_string($value)) {
            switch (strtolower($value)) {
                case '+':
                case '1':
                case 'y':
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

        return (boolean)$value;
    }

    /**
     * Returns blog path
     *
     * Example:
     *
     * siteurl=http://domain.com/site/blog
     * return /site/blog/
     *
     * With trailing slash!
     *
     * @return string
     */
    public static function site_url_uri()
    {
        return EnvUtil::url_to_uri(site_url()) . '/';
    }

    /**
     * Returns path from URL. Without trailing slash
     */
    public static function url_to_uri($url)
    {
        $uri = @parse_url($url, PHP_URL_PATH);

        // convert FALSE and other return values to string
        if (empty($uri))
            return '';

        return rtrim($uri, '/');
    }

    /**
     * Returns home domain
     *
     * @return string
     */
    public static function home_url_host()
    {
        $home_url = get_home_url();
        $parse_url = @parse_url($home_url);

        if ($parse_url and isset($parse_url['host'])) {
            return $parse_url['host'];
        }

        return EnvUtil::host();
    }

    public static function host()
    {
        $host_port = EnvUtil::host_port();

        $pos = strpos($host_port, ':');
        if ($pos === false)
            return $host_port;

        return substr($host_port, 0, $pos);
    }

    /**
     * Returns server hostname with port
     *
     * @return string
     */
    public static function host_port()
    {
        static $host = null;

        if ($host === null) {
            if (!empty($_SERVER['HTTP_HOST'])) {
                // HTTP_HOST sometimes is not set causing warning
                $host = $_SERVER['HTTP_HOST'];
            }
            else {
                $host = '';
            }
        }

        return $host;
    }

    /**
     * Returns home path
     *
     * Example:
     *
     * home=http://domain.com/site/
     * siteurl=http://domain.com/site/blog
     * return /site/
     *
     * With trailing slash!
     *
     * @return string
     */
    public static function home_url_uri()
    {
        return EnvUtil::url_to_uri(get_home_url()) . '/';
    }

    public static function network_home_url_uri()
    {
        $uri = network_home_url('', 'relative');

        /* There is a bug in WP where network_home_url can return
         * a non-relative URI even though scheme is set to relative.
         */
        if (EnvUtil::is_url($uri))
            $uri = parse_url($uri, PHP_URL_PATH);

        if (empty($uri))
            return '/';

        return $uri;
    }

    /**
     * Check if URL is valid
     *
     * @param string $url
     * @return boolean
     */
    public static function is_url($url)
    {
        return preg_match('~^(https?:)?//~', $url);
    }

    /**
     * Parses path
     *
     * @param string $path
     * @return array|string|string[]
     */
    public static function parse_path($path)
    {
        return str_replace(array(
            '%BLOG_ID%',
            '%POST_ID%',
            '%BLOG_ID%',
            '%HOST%'
        ), array(
            (isset($GLOBALS['blog_id']) and is_numeric($GLOBALS['blog_id']) ? (int)$GLOBALS['blog_id'] : 0),
            (isset($GLOBALS['post_id']) and is_numeric($GLOBALS['post_id']) ?
                    (int)$GLOBALS['post_id'] : 0),
            get_current_blog_id(),
            EnvUtil::host()
        ), $path);
    }

    /**
     * Normalizes file name
     *
     * Relative to site root!
     *
     * @param string $file
     * @return string
     */
    public static function normalize_file($file)
    {
        if (EnvUtil::is_url($file)) {
            if (strstr($file, '?') === false) {
                $home_url_regexp = '~' . EnvUtil::home_url_regexp() . '~i';
                $file = preg_replace($home_url_regexp, '', $file);
            }
        }

        if (!EnvUtil::is_url($file)) {
            $file = EnvUtil::normalize_path($file);
            $file = str_replace(EnvUtil::site_root(), '', $file);
            $file = ltrim($file, '/');
        }

        return $file;
    }

    /**
     * Returns home url regexp
     *
     * @return string
     */
    public static function home_url_regexp()
    {
        $home_url = get_home_url();
        return EnvUtil::get_url_regexp($home_url);
    }

    /**
     * Returns URL regexp from URL
     *
     * @param string $url
     * @return string
     */
    public static function get_url_regexp($url)
    {
        $url = preg_replace('~(https?:)?//~i', '', $url);
        $url = preg_replace('~^www\.~i', '', $url);

        return '(https?:)?//(www\.)?' . EnvUtil::preg_quote($url);
    }

    /**
     * Quotes regular expression string
     *
     * @param string $string
     * @param string $delimiter
     * @return string
     */
    public static function preg_quote($string, $delimiter = '~')
    {
        $string = preg_quote($string, $delimiter);
        return strtr($string, array(
            ' ' => '\ '
        ));
    }

    /**
     * Converts win path to unix
     *
     * @param string $path
     * @param bool $trailing_slash
     * @return string
     */
    public static function normalize_path($path, $trailing_slash = false)
    {
        // Assume empty dir is root
        if (!$path)
            return '/';

        $path = preg_replace('~[/\\\]+~', '/', $path);

        // Remove the trailing slash
        if (!$trailing_slash)
            $path = rtrim($path, '/');
        else
            $path = trailingslashit($path);

        return $path;
    }

    /**
     * Returns absolute path to blog install dir
     *
     * Example:
     *
     * DOCUMENT_ROOT=/var/www/vhosts/domain.com
     * install dir=/var/www/vhosts/domain.com/site/blog
     * return /var/www/vhosts/domain.com/site/blog
     *
     * No trailing slash!
     *
     * @return string
     */
    public static function site_root()
    {
        $site_root = ABSPATH;
        $site_root = realpath($site_root);
        return EnvUtil::normalize_path($site_root);
    }

    /**
     * Returns domain url regexp
     *
     * @return string
     */
    public static function home_domain_root_url_regexp()
    {
        $domain_url = EnvUtil::home_domain_root_url();
        return EnvUtil::get_url_regexp($domain_url);
    }

    /**
     * Get domain URL
     *
     * @return string
     */

    public static function home_domain_root_url()
    {
        $home_url = get_home_url();
        $parse_url = @parse_url($home_url);

        if ($parse_url and isset($parse_url['scheme']) and isset($parse_url['host'])) {
            $scheme = $parse_url['scheme'];
            $host = $parse_url['host'];
            $port = (isset($parse_url['port']) and $parse_url['port'] != 80 ? ':' . (int)$parse_url['port'] : '');
            return sprintf('%s://%s%s', $scheme, $host, $port);
        }

        return false;
    }

    /**
     * Normalizes file name for minify
     * Relative to document root!
     *
     * @param $url
     * @return string
     */
    public static function url_to_docroot_filename($url)
    {
        $data = array(
            'home_url' => get_home_url(),
            'url'      => $url
        );

        $home_url = $data['home_url'];
        $normalized_url = $data['url'];
        $normalized_url = EnvUtil::remove_query_all($normalized_url);

        // cut protocol
        $normalized_url = preg_replace('~^http(s)?://~', '//', $normalized_url);
        $home_url = preg_replace('~^http(s)?://~', '//', $home_url);

        if (substr($normalized_url, 0, strlen($home_url)) != $home_url) {
            // not a home url, return unchanged since cant be
            // converted to filename
            return null;
        }

        $path_relative_to_home = str_replace($home_url, '', $normalized_url);

        $home = set_url_scheme(get_option('home'), 'http');
        $siteurl = set_url_scheme(get_option('siteurl'), 'http');

        $home_path = rtrim(EnvUtil::site_path(), '/');
        // adjust home_path if site is not is home
        if (!empty($home) and 0 !== strcasecmp($home, $siteurl)) {
            // $siteurl - $home
            $wp_path_rel_to_home = rtrim(str_ireplace($home, '', $siteurl), '/');
            if (substr($home_path, -strlen($wp_path_rel_to_home)) ==
                $wp_path_rel_to_home) {
                $home_path = substr($home_path, 0, -strlen($wp_path_rel_to_home));
            }
        }

        // common encoded characters
        $path_relative_to_home = str_replace('%20', ' ', $path_relative_to_home);

        $full_filename = $home_path . DIRECTORY_SEPARATOR .
            trim($path_relative_to_home, DIRECTORY_SEPARATOR);

        $docroot = EnvUtil::document_root();
        if (substr($full_filename, 0, strlen($docroot)) == $docroot) {
            $docroot_filename = substr($full_filename, strlen($docroot));
        }
        else {
            $docroot_filename = $path_relative_to_home;
        }

        // sometimes urls (coming from other plugins/themes)
        // contain multiple "/" like "my-folder//myfile.js" which
        // fails to recognize by filesystem, while url is accessible
        $docroot_filename = str_replace('//', DIRECTORY_SEPARATOR, $docroot_filename);

        return ltrim($docroot_filename, DIRECTORY_SEPARATOR);
    }

    /**
     * Removes all query strings from url
     * @param $url
     * @return false|string
     */
    public static function remove_query_all($url)
    {
        $pos = strpos($url, '?');
        if ($pos === false)
            return $url;

        return substr($url, 0, $pos);
    }

    /**
     * Copy of wordpress get_home_path, but accessible not only for wp-admin
     * Get the absolute filesystem path to the root of the WordPress installation
     * (i.e. filesystem path of siteurl)
     *
     * @return string Full filesystem path to the root of the WordPress installation
     */
    public static function site_path()
    {
        $home = set_url_scheme(get_option('home'), 'http');
        $siteurl = set_url_scheme(get_option('siteurl'), 'http');

        $home_path = ABSPATH;
        if (!empty($home) and 0 !== strcasecmp($home, $siteurl)) {
            $wp_path_rel_to_home = str_ireplace($home, '', $siteurl); /* $siteurl - $home */
            // fix of get_home_path, used when index.php is moved outside of
            // wp folder.
            $pos = strripos(
                str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME']),
                trailingslashit($wp_path_rel_to_home));
            if ($pos !== false) {
                $home_path = substr($_SERVER['SCRIPT_FILENAME'], 0, $pos);
                $home_path = trailingslashit($home_path);
            }
            else if (defined('WP_CLI')) {
                $pos = strripos(
                    str_replace('\\', '/', ABSPATH),
                    trailingslashit($wp_path_rel_to_home));
                if ($pos !== false) {
                    $home_path = substr(ABSPATH, 0, $pos);
                    $home_path = trailingslashit($home_path);
                }
            }
        }

        return str_replace('\\', DIRECTORY_SEPARATOR, $home_path);
    }

    /**
     * Returns absolute path to document root
     *
     * No trailing slash!
     *
     * @return string
     */
    public static function document_root()
    {
        static $document_root = null;

        if (!is_null($document_root))
            return $document_root;

        if (!empty($_SERVER['SCRIPT_FILENAME']) and
            !empty($_SERVER['PHP_SELF'])) {
            $script_filename = EnvUtil::normalize_path(
                $_SERVER['SCRIPT_FILENAME']);
            $php_self = EnvUtil::normalize_path(
                $_SERVER['PHP_SELF']);
            if (substr($script_filename, -strlen($php_self)) == $php_self) {
                $document_root = substr($script_filename, 0, -strlen($php_self));
                $document_root = realpath($document_root);
                return $document_root;
            }
        }

        if (!empty($_SERVER['PATH_TRANSLATED'])) {
            $document_root = substr(
                EnvUtil::normalize_path($_SERVER['PATH_TRANSLATED']),
                0,
                -strlen(EnvUtil::normalize_path($_SERVER['PHP_SELF'])));
        }
        elseif (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $document_root = EnvUtil::normalize_path($_SERVER['DOCUMENT_ROOT']);
        }
        else {
            $document_root = ABSPATH;
        }

        return realpath($document_root);
    }

    public static function docroot_to_full_filename($docroot_filename)
    {
        return rtrim(EnvUtil::document_root(), DIRECTORY_SEPARATOR) .
            DIRECTORY_SEPARATOR . $docroot_filename;
    }

    /**
     * Translates remote file to local file
     *
     * @param string $file
     * @return string
     */
    public static function translate_file($file)
    {
        return $file;
    }

    /**
     * Removes WP query string from URL
     * @param $url
     * @return string|string[]|null
     */
    public static function remove_query($url)
    {
        return preg_replace('~(\?|&amp;|&#038;|&)+ver=[a-z0-9-_\.]+~i', '', $url);
    }

    /**
     * Returns real path of given path
     *
     * @param string $path
     * @return string
     */
    public static function realpath($path)
    {
        $path = EnvUtil::normalize_path($path);

        $parts = explode('/', $path);
        $absolutes = array();

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

        return implode('/', $absolutes);
    }

    /**
     * Returns full URL from relative one
     * @param $relative_url
     * @return string
     */
    public static function url_relative_to_full($relative_url)
    {
        $relative_url = EnvUtil::path_remove_dots($relative_url);

        if (version_compare(PHP_VERSION, '5.4.7') < 0) {
            if (substr($relative_url, 0, 2) == '//') {
                $relative_url =
                    (EnvUtil::is_https() ? 'https' : 'http') .
                    ':' . $relative_url;
            }
        }

        $rel = parse_url($relative_url);
        // it's full url already
        if (isset($rel['scheme']) || isset($rel['host']))
            return $relative_url;

        if (!isset($rel['host'])) {
            $home_parsed = parse_url(get_home_url());
            $rel['host'] = $home_parsed['host'];
            if (isset($home_parsed['port'])) {
                $rel['port'] = $home_parsed['port'];
            }
        }

        $scheme = isset($rel['scheme']) ? $rel['scheme'] . '://' : '//';
        $host = isset($rel['host']) ? $rel['host'] : '';
        $port = isset($rel['port']) ? ':' . $rel['port'] : '';
        $path = isset($rel['path']) ? $rel['path'] : '';
        $query = isset($rel['query']) ? '?' . $rel['query'] : '';
        return "$scheme$host$port$path$query";
    }

    /**
     * Returns real path of given path
     *
     * @param string $path
     * @return string
     */
    public static function path_remove_dots($path)
    {
        $parts = explode('/', $path);
        $absolutes = array();

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

        return implode('/', $absolutes);
    }

    /**
     * Redirects to URL
     *
     * @param string $url
     * @param array $params
     */
    public static function redirect($url = '', $params = array())
    {
        $url = EnvUtil::url_format($url, $params);

        @header('Location: ' . $url);
        exit();
    }

    /**
     * Formats URL
     *
     * @param string $url
     * @param array $params
     * @param boolean $skip_empty
     * @param string $separator
     * @return string
     */
    public static function url_format($url = '', $params = array(),
                                      $skip_empty = false, $separator = '&')
    {
        if ($url != '') {
            $parse_url = @parse_url($url);
            $url = '';

            if (!empty($parse_url['scheme'])) {
                $url .= $parse_url['scheme'] . '://';

                if (!empty($parse_url['user'])) {
                    $url .= $parse_url['user'];

                    if (!empty($parse_url['pass'])) {
                        $url .= ':' . $parse_url['pass'];
                    }
                }

                if (!empty($parse_url['host'])) {
                    $url .= $parse_url['host'];
                }

                if (!empty($parse_url['port']) and $parse_url['port'] != 80) {
                    $url .= ':' . (int)$parse_url['port'];
                }
            }

            if (!empty($parse_url['path'])) {
                $url .= $parse_url['path'];
            }

            if (!empty($parse_url['query'])) {
                $old_params = array();
                parse_str($parse_url['query'], $old_params);

                $params = array_merge($old_params, $params);
            }

            $query = EnvUtil::url_query($params);

            if ($query != '') {
                $url .= '?' . $query;
            }

            if (!empty($parse_url['fragment'])) {
                $url .= '#' . $parse_url['fragment'];
            }
        }
        else {
            $query = EnvUtil::url_query($params, $skip_empty, $separator);

            if ($query != '') {
                $url = '?' . $query;
            }
        }

        return $url;
    }

    /**
     * Formats query string
     *
     * @param array $params
     * @param boolean $skip_empty
     * @param string $separator
     * @return string
     */
    public static function url_query($params = array(), $skip_empty = false,
                                     $separator = '&')
    {
        $str = '';
        static $stack = array();

        foreach ((array)$params as $key => $value) {
            if ($skip_empty === true and empty($value)) {
                continue;
            }

            array_push($stack, $key);

            if (is_array($value)) {
                if (count($value)) {
                    $str .= ($str != '' ? '&' : '') .
                        EnvUtil::url_query($value, $skip_empty, $key);
                }
            }
            else {
                $name = '';
                foreach ($stack as $key) {
                    $name .= ($name != '' ? '[' . $key . ']' : $key);
                }
                $str .= ($str != '' ? $separator : '') . $name . '=' . rawurlencode($value);
            }

            array_pop($stack);
        }

        return $str;
    }

    /**
     * Redirects to URL
     *
     * @param string $url
     * @param array $params
     *
     * @return string
     */
    public static function safe_redirect_temp($url = '', $params = array())
    {
        $url = EnvUtil::url_format($url, $params);

        $status_code = 302;

        $protocol = $_SERVER["SERVER_PROTOCOL"];
        if ('HTTP/1.1' === $protocol) {
            $status_code = 307;
        }

        $text = get_status_header_desc($status_code);
        if (!empty($text)) {
            $status_header = "$protocol $status_code $text";
            @header($status_header, true, $status_code);
        }

        @header('Cache-Control: no-cache');
        wp_safe_redirect($url, $status_code);
        exit();
    }

    /**
     * Detects post ID
     *
     * @return integer
     */
    public static function detect_post_id()
    {
        global $posts, $comment_post_ID, $post_ID;

        if ($post_ID) {
            return $post_ID;
        }
        elseif ($comment_post_ID) {
            return $comment_post_ID;
        }
        elseif ((is_single() || is_page()) and is_array($posts) and isset($posts[0]->ID)) {
            return $posts[0]->ID;
        }
        elseif (isset($posts->ID)) {
            return $posts->ID;
        }
        elseif (isset($_REQUEST['p'])) {
            return (integer)$_REQUEST['p'];
        }

        return 0;
    }

    /**
     * Returns true if zlib output compression is enabled otherwise false
     *
     * @return boolean
     */
    public static function is_zlib_enabled()
    {
        return EnvUtil::to_boolean(ini_get('zlib.output_compression'));
    }

    /**
     * Returns the apache, nginx version
     *
     * @return string
     */
    public static function get_server_version()
    {
        $sig = explode('/', $_SERVER['SERVER_SOFTWARE']);
        $temp = isset($sig[1]) ? explode(' ', $sig[1]) : array('0');
        return $temp[0];
    }

    /**
     * Checks if current request is REST REQUEST
     * @param $url
     * @return bool|int
     */
    public static function is_rest_request($url)
    {
        if (defined('REST_REQUEST') and REST_REQUEST)
            return true;

        // in case when called before constant is set
        // wp filters are not available in that case
        return preg_match('~/wp-json/~', $url);
    }

    public static function is_function_disabled($function_name)
    {
        return in_array($function_name, array_map('trim', explode(',', ini_get('disable_functions'))), true);
    }

    public static function is_shell_exec_available()
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

    public static function is_safe_mode_active($ini_get_callback = 'ini_get')
    {
        if (($safe_mode = @call_user_func($ini_get_callback, 'safe_mode')) and strtolower($safe_mode) != 'off')
            return true;

        return false;
    }

    /**
     * check if time left is under a specific percentage
     * @param int $percent
     * @param bool $autorise
     * @return bool
     */
    public static function safe_time_limit($percent = 0.1, $autorise = 30)
    {
        global $wo_meter;

        if (($max_et = absint(ini_get('max_execution_time'))) === 0)
            return true;

        if (1 - $wo_meter->get_time(true) / $max_et <= $percent) {

            if ($autorise)
                return self::rise_time_limit($autorise);
            else
                return false;
        }

        return true;
    }

    public static function rise_time_limit($time = 30)
    {
        if (absint(ini_get('max_execution_time')) === 0)
            return true;

        return function_exists('set_time_limit') and set_time_limit($time);
    }
}
