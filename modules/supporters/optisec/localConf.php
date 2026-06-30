<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

use WPS\core\Settings;
use WPS\core\UtilEnv;
use WPS\core\RuleUtil;

require_once dirname(__DIR__) . '/cache/staticcache_direct.class.php';

/**
 * Class to handle Security and Optimization requests
 */
class WP_Htaccess
{
    private string $rules = '';

    private bool $edited = false;

    private array $settings;
    private array $order;

    public function __construct($settings)
    {
        $this->settings = $settings;
        $this->rules = str_replace(["\r\n", "\r"], "\n", RuleUtil::get_rules());

        $this->order = array(
            '# BEGIN O_API',
            '# BEGIN FLEX_API',
            '# WPOPT_MARKER_BEGIN_STATIC_DIRECT_ACCESS',
            '# BEGIN WordPress',
            '# WPOPT_MARKER_BEGIN_SRV_MIME_TYPES',
            '# WPOPT_MARKER_BEGIN_SRV_COMPRESSION',
            '# WPOPT_MARKER_BEGIN_SRV_ENHANCEMENTS',
            '# WPOPT_MARKER_BEGIN_SRV_SECURITY',
            '# WPOPT_MARKER_BEGIN_SRV_BROWSER_CACHE',
        );

        if (!$this->has_rule('srv_mime_types')) {
            $this->add_rule('srv_mime_types');
        }
    }

    public static function get_rules_path()
    {
        return RuleUtil::get_rules_path() ?: '';
    }

    public static function get_rules_file_name(): string
    {
        $path = self::get_rules_path();

        return $path ? basename($path) : '.htaccess';
    }

    public static function get_server_label(): string
    {
        return self::is_nginx() ? 'Nginx' : 'Apache';
    }

    public static function is_rules_file_writable(): bool
    {
        $path = self::get_rules_path();

        if (!$path) {
            return false;
        }

        if (file_exists($path)) {
            return is_writable($path);
        }

        return is_writable(dirname($path));
    }

    public static function is_nginx(): bool
    {
        return UtilEnv::is_nginx();
    }

    public function get_rules()
    {
        return $this->rules;
    }

    public function has_rule($rule_name): bool
    {
        $start_marker = '# WPOPT_MARKER_BEGIN_' . strtoupper($rule_name);
        $end_marker = '# WPOPT_MARKER_END_' . strtoupper($rule_name);

        return RuleUtil::has_rule($this->rules, $start_marker, $end_marker);
    }

    public function add_rule($name): void
    {
        $start_marker = '# WPOPT_MARKER_BEGIN_' . strtoupper($name);
        $end_marker = '# WPOPT_MARKER_END_' . strtoupper($name);

        $res = RuleUtil::add_rules(
            self::generate_rules($name, $start_marker, $end_marker, $this->settings),
            $start_marker,
            $end_marker,
            $this->order,
            $this->rules
        );

        $this->edited = ($this->edited or $res);
    }

    private static function generate_rules($item, $start_marker, $end_marker, $settings = array()): string
    {
        $rules = "\n$start_marker\n";

        if (self::is_nginx()) {
            $rules .= self::generate_nginx_rules($item, $settings);
            $rules .= "\n$end_marker\n";

            return $rules;
        }

        switch ($item) {
            case 'srv_mime_types':
                $rules .= self::generate_rules_mime_types();
                break;

            case 'srv_browser_cache':
                $rules .= self::generate_rules_browser_cache($settings);
                break;

            case 'srv_compression':
                $rules .= self::generate_rules_compression($settings);
                break;

            case 'srv_security':
                $rules .= self::generate_rules_security($settings);
                break;

            case 'srv_enhancements':
                $rules .= self::generate_rules_enhancements($settings);
                break;

            case 'static_direct_access':
                $rules .= self::generate_rules_static_direct_access($settings);
        }

        $rules .= "\n$end_marker\n";

        return $rules;
    }

    private static function generate_nginx_rules($item, $settings = array()): string
    {
        switch ($item) {
            case 'srv_mime_types':
                return self::generate_nginx_rules_mime_types();

            case 'srv_browser_cache':
                return self::generate_nginx_rules_browser_cache($settings);

            case 'srv_compression':
                return self::generate_nginx_rules_compression($settings);

            case 'srv_security':
                return self::generate_nginx_rules_security($settings);

            case 'srv_enhancements':
                return self::generate_nginx_rules_enhancements($settings);

            case 'static_direct_access':
                return self::generate_nginx_rules_static_direct_access($settings);
        }

        return '';
    }

    private static function generate_rules_static_direct_access($settings): string
    {
        $rewrite_target = StaticCacheDirectAccess::get_rewrite_target();
        if ($rewrite_target === '') {
            return '# WP Optimizer static direct access is unavailable because the direct cache script is outside ABSPATH.';
        }

        $static_settings = (array)Settings::get_option($settings, 'static_pages', array());
        $cookie_condition = StaticCacheDirectAccess::apache_cookie_rewrite_condition($static_settings);
        $script_uri_path = StaticCacheDirectAccess::get_script_uri_path();

        $rules = new RuleUtil(true);
        $rules->add('<IfModule mod_rewrite.c>');
        $rules->add('RewriteEngine On', 4);
        $rules->add('RewriteCond %{REQUEST_METHOD} =GET', 4);
        $rules->add('RewriteCond %{REQUEST_URI} \\.(' . self::static_direct_asset_extensions_pattern() . ')($|[?#]) [NC]', 4);
        $rules->add('RewriteCond %{REQUEST_FILENAME} !-f', 4);
        $rules->add('RewriteRule ^ - [R=404,L]', 4);
        $rules->add('RewriteCond %{REQUEST_METHOD} =GET', 4);
        $rules->add('RewriteCond %{HTTP_ACCEPT} (^$|text/html) [NC]', 4);
        $rules->add('RewriteCond %{REQUEST_URI} !^' . preg_quote($script_uri_path, '#') . '$ [NC]', 4);
        $rules->add('RewriteCond %{REQUEST_URI} !/(' . self::static_direct_excluded_paths_pattern() . ')(/|$) [NC]', 4);
        $rules->add('RewriteCond %{QUERY_STRING} !(^|&)(s|preview)= [NC]', 4);

        if ($cookie_condition !== '') {
            $rules->add('RewriteCond %{HTTP_COOKIE} !(' . $cookie_condition . ') [NC]', 4);
        }

        $rules->add('RewriteRule ^.*$ ' . $rewrite_target . ' [L,QSA]', 4);
        $rules->autoLine(false)->add('</IfModule>');

        return $rules->export();
    }

    private static function generate_nginx_rules_static_direct_access($settings): string
    {
        $script_uri_path = StaticCacheDirectAccess::get_script_uri_path();
        if ($script_uri_path === '') {
            return '# WP Optimizer static direct access is unavailable because the direct cache script is outside ABSPATH.';
        }

        $static_settings = (array)Settings::get_option($settings, 'static_pages', array());

        $rules = "# Include this block in the server context before the WordPress fallback.\n";
        $rules .= "location ~* \\.(" . self::static_direct_asset_extensions_pattern() . ")$ {\n";
        $rules .= "    try_files \$uri =404;\n";
        $rules .= "}\n";
        $rules .= "set \$wpopt_static_direct 0;\n";
        $rules .= "if (\$request_method = GET) { set \$wpopt_static_direct 1; }\n";
        $rules .= "if (\$http_accept !~* \"(^$|text/html)\") { set \$wpopt_static_direct 0; }\n";
        $rules .= "if (\$request_uri ~* \"^" . self::quote_nginx_regex_part($script_uri_path) . "$\") { set \$wpopt_static_direct 0; }\n";
        $rules .= "if (\$request_uri ~* \"/(" . self::static_direct_excluded_paths_pattern() . ")(/|$)\") { set \$wpopt_static_direct 0; }\n";
        $rules .= "if (\$args ~* \"(^|&)(s|preview)=\") { set \$wpopt_static_direct 0; }\n";

        $cookie_condition = StaticCacheDirectAccess::apache_cookie_rewrite_condition($static_settings);
        if ($cookie_condition !== '') {
            $rules .= "if (\$http_cookie ~* \"(" . str_replace('"', '\"', $cookie_condition) . ")\") { set \$wpopt_static_direct 0; }\n";
        }

        $rules .= "if (\$wpopt_static_direct = 1) { rewrite ^ " . $script_uri_path . " last; }\n";

        return $rules;
    }

    private static function static_direct_excluded_paths_pattern(): string
    {
        return 'wp-admin|wp-login\\.php|wp-cron\\.php|xmlrpc\\.php|wp-json';
    }

    private static function static_direct_asset_extensions_pattern(): string
    {
        return 'avif|bmp|gif|heic|ico|jpe?g|png|svg|tiff?|webp|css|js|mjs|map|json|xml|txt|woff2?|ttf|otf|eot|mp4|webm|mp3|ogg|wav|pdf';
    }

    private static function generate_rules_mime_types(): string
    {
        $ext_types = self::get_ext_types(array('image', 'font', 'audio', 'document', 'spreadsheet', 'text', 'code'));
        $mime_types = self::get_mime_types($ext_types);

        if (!$mime_types) {
            return '';
        }

        $rules = new RuleUtil(true);

        $rules->add("<IfModule mod_mime.c>");

        foreach ($mime_types as $mime_type => $ext) {
            $rules->add("AddType $mime_type ." . implode(' .', $ext), 4);
        }

        $rules->autoLine(false)->add("</IfModule>");

        return $rules->export();
    }

    private static function generate_nginx_rules_mime_types(): string
    {
        // Nginx "types" replaces the inherited MIME map in the current context,
        // so include every type known by this class instead of only Apache additions.
        $ext_types = UtilEnv::array_flatter(array_values(self::get_ext_types()));
        $mime_types = self::get_mime_types($ext_types);

        if (!$mime_types) {
            return '';
        }

        $rules = "types {\n";

        foreach ($mime_types as $mime_type => $ext) {
            $rules .= "    $mime_type " . implode(' ', $ext) . ";\n";
        }

        $rules .= "}\n";

        return $rules;
    }

    private static function get_ext_types($filter = array(), $key_value = false): array
    {
        $types = array(
            'image'       => array('jpg', 'jpeg', 'jpe', 'webp', 'gif', 'png', 'bmp', 'tif', 'tiff', 'ico', 'heic'),
            'font'        => array('ttf', 'woff', 'woff2', 'otf', 'svg', 'eot', 'sfnt'),
            'audio'       => array('aac', 'ac3', 'aif', 'aiff', 'flac', 'm3a', 'm4a', 'm4b', 'mka', 'mp1', 'mp2', 'mp3', 'ogg', 'oga', 'ram', 'wav', 'wma'),
            'video'       => array('3g2', '3gp', '3gpp', 'asf', 'avi', 'divx', 'dv', 'flv', 'm4v', 'mkv', 'mov', 'mp4', 'mpeg', 'mpg', 'mpv', 'ogm', 'ogv', 'qt', 'rm', 'vob', 'wmv'),
            'document'    => array('doc', 'docx', 'docm', 'dotm', 'odt', 'pages', 'pdf', 'xps', 'oxps', 'rtf', 'wp', 'wpd', 'psd', 'xcf'),
            'spreadsheet' => array('numbers', 'ods', 'xls', 'xlsx', 'xlsm', 'xlsb'),
            'interactive' => array('swf', 'key', 'ppt', 'pptx', 'pptm', 'pps', 'ppsx', 'ppsm', 'sldx', 'sldm', 'odp'),
            'text'        => array('asc', 'csv', 'tsv', 'txt', 'js', 'less', 'htc', 'css', 'xml'),
            'archive'     => array('bz2', 'cab', 'dmg', 'gz', 'rar', 'sea', 'sit', 'sqx', 'tar', 'tgz', 'zip', '7z'),
            'code'        => array('php', 'pl', 'cgi', 'spl'),
            'web_pages'   => array('htm', 'html'),
        );

        if (empty($filter)) {
            return $types;
        }

        if ($key_value) {
            return array_intersect_key($types, (array)$filter);
        }

        return UtilEnv::array_flatter(
            array_values(
                array_intersect_key(
                    $types,
                    array_flip((array)$filter)
                )
            )
        );
    }

    private static function get_mime_types($categories = array(), $return = 'both')
    {
        $mime_types = array(
            // Image formats.
            'jpg|jpeg|jpe'                 => 'image/jpeg',
            'gif'                          => 'image/gif',
            'png'                          => 'image/png',
            'webp'                         => 'image/webp',
            'bmp'                          => 'image/bmp',
            'tiff|tif'                     => 'image/tiff',
            'ico'                          => 'image/x-icon',
            'heic'                         => 'image/heic',
            // Video formats.
            'asf|asx'                      => 'video/x-ms-asf',
            'wmv'                          => 'video/x-ms-wmv',
            'wmx'                          => 'video/x-ms-wmx',
            'wm'                           => 'video/x-ms-wm',
            'avi'                          => 'video/avi',
            'divx'                         => 'video/divx',
            'flv'                          => 'video/x-flv',
            'mov|qt'                       => 'video/quicktime',
            'mpeg|mpg|mpe'                 => 'video/mpeg',
            'mp4|m4v'                      => 'video/mp4',
            'ogv'                          => 'video/ogg',
            'webm'                         => 'video/webm',
            'mkv'                          => 'video/x-matroska',
            '3gp|3gpp'                     => 'video/3gpp',  // Can also be audio.
            '3g2|3gp2'                     => 'video/3gpp2', // Can also be audio.
            // Text formats.
            'txt|asc|c|cc|h|srt'           => 'text/plain',
            'csv'                          => 'text/csv',
            'tsv'                          => 'text/tab-separated-values',
            'ics'                          => 'text/calendar',
            'rtx'                          => 'text/richtext',
            'css|less'                     => 'text/css',
            'htm|html'                     => 'text/html',
            'vtt'                          => 'text/vtt',
            'htc'                          => 'text/x-component',
            'dfxp'                         => 'application/ttaf+xml',
            'xml'                          => 'application/xml',
            // Audio formats.
            'mp3|m4a|m4b'                  => 'audio/mpeg',
            'aac'                          => 'audio/aac',
            'ra|ram'                       => 'audio/x-realaudio',
            'wav'                          => 'audio/wav',
            'ogg|oga'                      => 'audio/ogg',
            'flac'                         => 'audio/flac',
            'mid|midi'                     => 'audio/midi',
            'wma'                          => 'audio/x-ms-wma',
            'wax'                          => 'audio/x-ms-wax',
            'mka'                          => 'audio/x-matroska',
            // Misc application formats.
            'rtf'                          => 'application/rtf',
            'js'                           => 'application/javascript',
            'pdf'                          => 'application/pdf',
            'swf'                          => 'application/x-shockwave-flash',
            'class'                        => 'application/java',
            'exe'                          => 'application/x-msdownload',
            'psd'                          => 'application/octet-stream',
            'xcf'                          => 'application/octet-stream',
            // MS Office formats.
            'doc'                          => 'application/msword',
            'pot|pps|ppt'                  => 'application/vnd.ms-powerpoint',
            'wri'                          => 'application/vnd.ms-write',
            'xla|xls|xlt|xlw'              => 'application/vnd.ms-excel',
            'mdb'                          => 'application/vnd.ms-access',
            'mpp'                          => 'application/vnd.ms-project',
            'docx'                         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'docm'                         => 'application/vnd.ms-word.document.macroEnabled.12',
            'dotx'                         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
            'dotm'                         => 'application/vnd.ms-word.template.macroEnabled.12',
            'xlsx'                         => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xlsm'                         => 'application/vnd.ms-excel.sheet.macroEnabled.12',
            'xlsb'                         => 'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
            'xltx'                         => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
            'xltm'                         => 'application/vnd.ms-excel.template.macroEnabled.12',
            'xlam'                         => 'application/vnd.ms-excel.addin.macroEnabled.12',
            'pptx'                         => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'pptm'                         => 'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
            'ppsx'                         => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
            'ppsm'                         => 'application/vnd.ms-powerpoint.slideshow.macroEnabled.12',
            'potx'                         => 'application/vnd.openxmlformats-officedocument.presentationml.template',
            'potm'                         => 'application/vnd.ms-powerpoint.template.macroEnabled.12',
            'ppam'                         => 'application/vnd.ms-powerpoint.addin.macroEnabled.12',
            'sldx'                         => 'application/vnd.openxmlformats-officedocument.presentationml.slide',
            'sldm'                         => 'application/vnd.ms-powerpoint.slide.macroEnabled.12',
            'onetoc|onetoc2|onetmp|onepkg' => 'application/onenote',
            'oxps'                         => 'application/oxps',
            'xps'                          => 'application/vnd.ms-xpsdocument',
            // OpenOffice formats.
            'odt'                          => 'application/vnd.oasis.opendocument.text',
            'odp'                          => 'application/vnd.oasis.opendocument.presentation',
            'ods'                          => 'application/vnd.oasis.opendocument.spreadsheet',
            'odg'                          => 'application/vnd.oasis.opendocument.graphics',
            'odc'                          => 'application/vnd.oasis.opendocument.chart',
            'odb'                          => 'application/vnd.oasis.opendocument.database',
            'odf'                          => 'application/vnd.oasis.opendocument.formula',
            // WordPerfect formats.
            'wp|wpd'                       => 'application/wordperfect',
            // iWork formats.
            'key'                          => 'application/vnd.apple.keynote',
            'numbers'                      => 'application/vnd.apple.numbers',
            'pages'                        => 'application/vnd.apple.pages',
            //fonts
            'ttf'                          => 'font/x-font-ttf',
            'woff'                         => 'font/woff',
            'woff2'                        => 'font/woff2',
            'otf'                          => 'application/x-font-opentype',
            'svg'                          => 'image/svg+xml',
            'eot'                          => 'application/vnd.ms-fontobject',
            'sfnt'                         => 'application/font-sfnt',
            //code
            'php'                          => 'application/x-httpd-php',
            'pl'                           => 'text/x-script.perl',
            'cgi'                          => 'application/x-httpd-cgi',
            'spl'                          => 'application/futuresplash',
        );

        $matched_types = array();

        if (isset($categories[0]))
            $categories = array_flip($categories);

        foreach ($mime_types as $exts => $mime_type) {
            foreach (explode('|', $exts) as $ext) {

                if (isset($categories[$ext]))
                    $matched_types[$mime_type][] = $ext;
            }
        }

        if ($return === 'keys')
            return array_keys($matched_types);

        if ($return === 'values')
            return array_values($matched_types);

        if ($return === 'flat_values')
            return call_user_func_array('array_merge', array_values($matched_types));

        return $matched_types;
    }

    private static function get_cache_policy($type): array
    {
        switch ($type) {

            case 'text':
                $cache_policy = array(
                    'cache'      => true,
                    'immutable'  => true,
                    'revalidate' => true,
                );
                break;

            case 'font':
            case 'image':
                $cache_policy = array(
                    'cache'       => true,
                    'immutable'   => true,
                    'stale-error' => false
                );
                break;

            case 'audio':
            case 'video':
            case 'document':
            case 'spreadsheet':
            case 'interactive':
            case 'archive':
                $cache_policy = array(
                    'cache'       => true,
                    'private'     => true,
                    'revalidate'  => true,
                    'stale-error' => false
                );
                break;

            default:
                $cache_policy = [];
        }

        return $cache_policy;
    }

    private static function apache_page_test_env_rule(): string
    {
        return "<IfModule mod_setenvif.c>\n"
            . "    SetEnvIfNoCase Query_String \"(^|&)wpopt_page_test=disabled(&|$)\" wpopt_page_test_disabled=1 no-gzip=1 no-brotli=1\n"
            . "</IfModule>\n";
    }

    private static function apache_header_env(): string
    {
        return ' env=!wpopt_page_test_disabled';
    }

    private static function apache_page_test_rewrite_cond(): string
    {
        return "      RewriteCond %{QUERY_STRING} !(^|&)wpopt_page_test=disabled(&|$) [NC]\n";
    }

    private static function apache_page_test_cache_bypass_headers(): string
    {
        return "        Header set Pragma \"no-cache\" env=wpopt_page_test_disabled\n"
            . "        Header set Cache-Control \"max-age=0, private, no-store, no-cache, must-revalidate\" env=wpopt_page_test_disabled\n"
            . "        Header unset Expires env=wpopt_page_test_disabled\n"
            . "        Header unset ETag env=wpopt_page_test_disabled\n";
    }

    private static function nginx_page_test_break(): string
    {
        return "    if (\$arg_wpopt_page_test = disabled) {\n"
            . "        expires off;\n"
            . "        add_header Pragma \"no-cache\" always;\n"
            . "        add_header Cache-Control \"max-age=0, private, no-store, no-cache, must-revalidate\" always;\n"
            . "        break;\n"
            . "    }\n";
    }

    private static function nginx_page_test_rewrite_break(): string
    {
        return "if (\$arg_wpopt_page_test = disabled) {\n"
            . "    break;\n"
            . "}\n";
    }

    private static function generate_rules_browser_cache($settings): string
    {
        $default_lifetime = Settings::get_option($settings, "srv_browser_cache.lifetime_default", WEEK_IN_SECONDS);

        $cache_control_rules = '';

        $rules = self::apache_page_test_env_rule();
        $rules .= "<IfModule mod_expires.c>\n";
        $rules .= "    ExpiresActive On\n";

        $default_lifetime = array(
            'image'       => MONTH_IN_SECONDS,
            'font'        => YEAR_IN_SECONDS,
            'audio'       => $default_lifetime,
            'video'       => $default_lifetime,
            'document'    => $default_lifetime,
            'spreadsheet' => $default_lifetime,
            'interactive' => $default_lifetime,
            'text'        => MONTH_IN_SECONDS,
            'archive'     => DAY_IN_SECONDS,
            'code'        => 0,
            'web_pages'   => 0,
        );

        foreach (self::get_ext_types() as $type => $extensions) {

            if (!($mime_types = self::get_mime_types($extensions)) or !isset($default_lifetime[$type]))
                continue;

            $lifetime = Settings::get_option($settings, "srv_browser_cache.lifetime_{$type}", $default_lifetime[$type]);

            if (intval($lifetime) > 0) {

                foreach ($mime_types as $mime_type => $ext) {
                    $rules .= "    ExpiresByType " . $mime_type . " A" . $lifetime . "\n";
                }
            }

            $extensions_lowercase = array_map('strtolower', $extensions);
            $extensions_uppercase = array_map('strtoupper', $extensions);

            $headers_rules = '';
            $file_match_rules = '';

            $cache_policy = array_merge(
                [
                    'cache'            => false,
                    'private'          => false,
                    'revalidate'       => false,
                    'immutable'        => false,
                    'stale-error'      => Settings::get_option($settings, 'srv_browser_cache.stale_error', false),
                    'stale-revalidate' => Settings::get_option($settings, 'srv_browser_cache.stale_revalidate', false)
                ],
                self::get_cache_policy($type)
            );

            if (!$cache_policy['cache']) {
                $headers_rules .= "        Header set Pragma \"no-cache\"" . self::apache_header_env() . "\n";
                $headers_rules .= "        Header set Cache-Control \"max-age=0, private, no-store, no-cache, must-revalidate\"" . self::apache_header_env() . "\n";
                $headers_rules .= "        Header unset Last-Modified" . self::apache_header_env() . "\n";
                $headers_rules .= "        Header unset ETag" . self::apache_header_env() . "\n";

                $file_match_rules .= "    FileETag None\n";
            }
            else {

                $cache_control = [];

                $cache_policy['immutable'] = ($cache_policy['immutable'] and Settings::get_option($settings, 'srv_browser_cache.immutable', false));

                $headers_rules .= "        Header set Pragma \"public\"" . self::apache_header_env() . "\n";

                $cache_control[] = $cache_policy['private'] ? 'private' : 'public';

                $cache_control[] = "max-age=" . $lifetime;

                if ($cache_policy['immutable']) {
                    $cache_control[] = "immutable";
                }

                if ($cache_policy['stale-revalidate'] and !$cache_policy['immutable'] and $cache_policy['revalidate']) {
                    $cache_control[] = "stale-while-revalidate=" . DAY_IN_SECONDS;
                }

                if ($cache_policy['stale-error'] and !$cache_policy['immutable'] and $cache_policy['revalidate']) {
                    $cache_control[] = "stale-if-error=" . DAY_IN_SECONDS;
                }

                if ($cache_policy['revalidate'] and !$cache_policy['immutable']) {
                    $cache_control[] = 'must-revalidate';

                    if (!$cache_policy['private']) {
                        $cache_control[] = 'proxy-revalidate';
                    }
                }

                $headers_rules .= "        Header set Cache-Control \"" . implode(', ', $cache_control) . "\"" . self::apache_header_env() . "\n";

                $file_match_rules .= "    FileETag MTime Size\n";
            }

            $file_match_rules .= "    <IfModule mod_headers.c>\n";
            $file_match_rules .= $headers_rules;
            $file_match_rules .= self::apache_page_test_cache_bypass_headers();
            $file_match_rules .= "    </IfModule>\n";

            $cache_control_rules .= "<FilesMatch \"\\.(" . implode('|', array_merge($extensions_lowercase, $extensions_uppercase)) . ")$\">\n" . $file_match_rules . "</FilesMatch>\n";
        }

        $rules .= "</IfModule>\n";

        return $rules . $cache_control_rules;
    }

    private static function generate_nginx_rules_browser_cache($settings): string
    {
        $default_lifetime = Settings::get_option($settings, "srv_browser_cache.lifetime_default", WEEK_IN_SECONDS);

        $default_lifetime = array(
            'image'       => MONTH_IN_SECONDS,
            'font'        => YEAR_IN_SECONDS,
            'audio'       => $default_lifetime,
            'video'       => $default_lifetime,
            'document'    => $default_lifetime,
            'spreadsheet' => $default_lifetime,
            'interactive' => $default_lifetime,
            'text'        => MONTH_IN_SECONDS,
            'archive'     => DAY_IN_SECONDS,
            'code'        => 0,
            'web_pages'   => 0,
        );

        $rules = '';

        foreach (self::get_ext_types() as $type => $extensions) {

            if (!isset($default_lifetime[$type])) {
                continue;
            }

            $lifetime = Settings::get_option($settings, "srv_browser_cache.lifetime_{$type}", $default_lifetime[$type]);
            $extensions = array_map('strtolower', $extensions);
            $extensions = array_map([self::class, 'quote_nginx_regex_part'], $extensions);

            $cache_policy = array_merge(
                [
                    'cache'            => false,
                    'private'          => false,
                    'revalidate'       => false,
                    'immutable'        => false,
                    'stale-error'      => Settings::get_option($settings, 'srv_browser_cache.stale_error', false),
                    'stale-revalidate' => Settings::get_option($settings, 'srv_browser_cache.stale_revalidate', false)
                ],
                self::get_cache_policy($type)
            );

            $rules .= "location ~* \\.(" . implode('|', $extensions) . ")$ {\n";
            $rules .= self::nginx_page_test_break();

            if (!$cache_policy['cache']) {
                $rules .= "    expires off;\n";
                $rules .= "    etag off;\n";
                $rules .= "    add_header Pragma \"no-cache\" always;\n";
                $rules .= "    add_header Cache-Control \"max-age=0, private, no-store, no-cache, must-revalidate\" always;\n";
                $rules .= "    # Last-Modified cannot be unset in standard Nginx without the headers_more module.\n";
            }
            else {
                $cache_control = [];

                $cache_policy['immutable'] = ($cache_policy['immutable'] and Settings::get_option($settings, 'srv_browser_cache.immutable', false));

                if (intval($lifetime) > 0) {
                    $rules .= "    expires " . intval($lifetime) . "s;\n";
                }

                $rules .= "    etag on;\n";
                $rules .= "    add_header Pragma \"public\" always;\n";

                $cache_control[] = $cache_policy['private'] ? 'private' : 'public';
                $cache_control[] = "max-age=" . intval($lifetime);

                if ($cache_policy['immutable']) {
                    $cache_control[] = "immutable";
                }

                if ($cache_policy['stale-revalidate'] and !$cache_policy['immutable'] and $cache_policy['revalidate']) {
                    $cache_control[] = "stale-while-revalidate=" . DAY_IN_SECONDS;
                }

                if ($cache_policy['stale-error'] and !$cache_policy['immutable'] and $cache_policy['revalidate']) {
                    $cache_control[] = "stale-if-error=" . DAY_IN_SECONDS;
                }

                if ($cache_policy['revalidate'] and !$cache_policy['immutable']) {
                    $cache_control[] = 'must-revalidate';

                    if (!$cache_policy['private']) {
                        $cache_control[] = 'proxy-revalidate';
                    }
                }

                $rules .= "    add_header Cache-Control " . self::quote_nginx_value(implode(', ', $cache_control)) . " always;\n";
            }

            $rules .= "}\n";
        }

        return $rules;
    }

    private static function generate_rules_compression($settings): string
    {
        $ext_types = self::get_ext_types(array('image', 'document', 'spreadsheet', 'text', 'code'));

        $mime_types = self::get_mime_types($ext_types, 'keys');
        $extensions = self::get_mime_types($ext_types, 'flat_values');

        $extensions = str_replace('.', '', $extensions);

        $rules = '';

        if (Settings::get_option($settings, 'srv_compression.gzip')) {

            $rules .= self::apache_page_test_env_rule();
            $rules .= "<IfModule mod_deflate.c>\n";

            $rules .= "    <IfModule mod_setenvif.c>\n";
            $rules .= "        BrowserMatch ^Mozilla/4 gzip-only-text/html\n";
            $rules .= "        BrowserMatch ^Mozilla/4\\.0[678] no-gzip\n";
            $rules .= "        BrowserMatch \\bMSIE !no-gzip !gzip-only-text/html\n";
            $rules .= "        BrowserMatch \\bMSI[E] !no-gzip !gzip-only-text/html\n";
            $rules .= "    </IfModule>\n";

            $rules .= "    <IfModule mod_headers.c>\n";
            // Force proxies to cache gzipped & non-gzipped css/js files separately.
            $rules .= "        Header append Vary Accept-Encoding" . self::apache_header_env() . "\n";
            $rules .= "    </IfModule>\n";

            if (version_compare(UtilEnv::get_server_version(), '2.3.7', '>=')) {
                $rules .= "    <IfModule mod_filter.c>\n";
            }

            $rules .= "        AddOutputFilterByType DEFLATE " . implode(' ', $mime_types) . "\n";

            if (version_compare(UtilEnv::get_server_version(), '2.3.7', '>=')) {
                $rules .= "    </IfModule>\n";
            }

            $rules .= "    <IfModule mod_mime.c>\n";
            $rules .= "        AddOutputFilter DEFLATE " . implode(' ', $extensions) . "\n";
            $rules .= "    </IfModule>\n";

            $rules .= "</IfModule>\n";
        }

        if (Settings::get_option($settings, 'srv_compression.brotli')) {
            $rules .= "<IfModule mod_brotli.c>\n";

            $rules .= "    <IfModule mod_headers.c>\n";
            // Force proxies to cache gzipped & non-gzipped css/js files separately.
            $rules .= "        Header append Vary Accept-Encoding" . self::apache_header_env() . "\n";
            $rules .= "    </IfModule>\n";

            if (version_compare(UtilEnv::get_server_version(), '2.3.7', '>=')) {
                $rules .= "    <IfModule mod_filter.c>\n";
            }
            $rules .= "        AddOutputFilterByType BROTLI_COMPRESS " . implode(' ', $mime_types) . "\n";

            if (version_compare(UtilEnv::get_server_version(), '2.3.7', '>=')) {
                $rules .= "    </IfModule>\n";
            }

            $rules .= "    <IfModule mod_mime.c>\n";
            $rules .= "        AddOutputFilter BROTLI_COMPRESS " . implode(' ', $extensions) . "\n";
            $rules .= "    </IfModule>\n";

            $rules .= "</IfModule>\n";
        }

        return $rules;
    }

    private static function generate_nginx_rules_compression($settings): string
    {
        $ext_types = self::get_ext_types(array('image', 'document', 'spreadsheet', 'text', 'code'));
        $mime_types = self::get_mime_types($ext_types, 'keys');

        $rules = '';

        if (Settings::get_option($settings, 'srv_compression.gzip')) {
            $rules .= "# Requests with ?wpopt_page_test=disabled bypass WP Optimizer PHP modules; Nginx gzip is a server-level directive and cannot be toggled per query string here.\n";
            $rules .= "gzip on;\n";
            $rules .= "gzip_vary on;\n";
            $rules .= "gzip_disable \"msie6\";\n";
            $rules .= "gzip_types " . implode(' ', $mime_types) . ";\n";
        }

        if (Settings::get_option($settings, 'srv_compression.brotli')) {
            $rules .= "# Brotli requested by WP-Optimizer. Uncomment only if the ngx_brotli module is installed.\n";
            $rules .= "# brotli on;\n";
            $rules .= "# brotli_types " . implode(' ', $mime_types) . ";\n";
        }

        return $rules;
    }

    private static function generate_rules_security($settings): string
    {
        $rules = self::apache_page_test_env_rule();

        if (Settings::get_option($settings, 'srv_security.listings')) {
            $rules .= "Options All -Indexes\n";
        }

        if (Settings::get_option($settings, 'srv_security.http_track&trace')) {
            $rules .= "<IfModule mod_rewrite.c>\n";
            $rules .= "    RewriteEngine on\n";
            $rules .= "    RewriteCond %{QUERY_STRING} !(^|&)wpopt_page_test=disabled(&|$) [NC]\n";
            $rules .= "    RewriteCond %{REQUEST_METHOD} ^(TRACE|TRACK)\n";
            $rules .= "    RewriteRule .* - [F]\n";
            $rules .= "</IfModule>\n";
        }

        $rules .= "<IfModule mod_headers.c>\n";

        if (Settings::get_option($settings, 'srv_security.cors'))
            $rules .= "    Header set Access-Control-Allow-Origin " . get_site_url() . self::apache_header_env() . "\n";

        if (Settings::get_option($settings, 'srv_security.xss'))
            $rules .= "    Header set X-XSS-Protection \"1; mode=block\"" . self::apache_header_env() . "\n";

        if (Settings::get_option($settings, 'srv_security.nosniff'))
            $rules .= "    Header set X-Content-Type-Options \"nosniff\"" . self::apache_header_env() . "\n";

        if (Settings::get_option($settings, 'srv_security.noreferrer'))
            $rules .= "    Header set Referrer-Policy \"no-referrer\"" . self::apache_header_env() . "\n";

        if (Settings::get_option($settings, 'srv_security.noframe'))
            $rules .= "    Header set X-Frame-Options \"DENY\"" . self::apache_header_env() . "\n";

        if (Settings::get_option($settings, 'srv_security.hsts'))
            $rules .= "    Header set Strict-Transport-Security \"max-age=31536000; includeSubDomains; preload\"" . self::apache_header_env() . "\n";

        if (Settings::get_option($settings, 'srv_security.signature')) {
            $rules .= "    Header unset Server" . self::apache_header_env() . "\n";
            $rules .= "    Header always unset X-Powered-By" . self::apache_header_env() . "\n";
            $rules .= "    Header unset X-Powered-By" . self::apache_header_env() . "\n";
            $rules .= "    Header unset X-CF-Powered-By" . self::apache_header_env() . "\n";
            $rules .= "    Header unset X-Mod-Pagespeed" . self::apache_header_env() . "\n";
            $rules .= "    Header unset X-Pingback" . self::apache_header_env() . "\n";
        }

        $rules .= "</IfModule>\n";

        if (Settings::get_option($settings, 'srv_security.protect_htaccess')) {
            $rules .= "<Files .htaccess>\n";
            $rules .= "    order allow,deny\n";
            $rules .= "    deny from all\n";
            $rules .= " </Files>\n";
        }

        if (Settings::get_option($settings, 'srv_security.signature')) {
            $rules .= "ServerSignature Off\n";
        }

        return $rules;
    }

    private static function generate_nginx_rules_security($settings): string
    {
        $rules = '';

        if (Settings::get_option($settings, 'srv_security.listings')) {
            $rules .= "autoindex off;\n";
        }

        if (Settings::get_option($settings, 'srv_security.http_track&trace')) {
            $rules .= self::nginx_page_test_rewrite_break();
            $rules .= "if (\$request_method ~ ^(TRACE|TRACK)$) {\n";
            $rules .= "    return 403;\n";
            $rules .= "}\n";
        }

        if (Settings::get_option($settings, 'srv_security.cors'))
            $rules .= "add_header Access-Control-Allow-Origin " . self::quote_nginx_value(get_site_url()) . " always;\n";

        if (Settings::get_option($settings, 'srv_security.xss'))
            $rules .= "add_header X-XSS-Protection \"1; mode=block\" always;\n";

        if (Settings::get_option($settings, 'srv_security.nosniff'))
            $rules .= "add_header X-Content-Type-Options \"nosniff\" always;\n";

        if (Settings::get_option($settings, 'srv_security.noreferrer'))
            $rules .= "add_header Referrer-Policy \"no-referrer\" always;\n";

        if (Settings::get_option($settings, 'srv_security.noframe'))
            $rules .= "add_header X-Frame-Options \"DENY\" always;\n";

        if (Settings::get_option($settings, 'srv_security.hsts'))
            $rules .= "add_header Strict-Transport-Security \"max-age=31536000; includeSubDomains; preload\" always;\n";

        if (Settings::get_option($settings, 'srv_security.signature')) {
            $rules .= "server_tokens off;\n";
            $rules .= "fastcgi_hide_header X-Powered-By;\n";
            $rules .= "proxy_hide_header X-Powered-By;\n";
            $rules .= "proxy_hide_header X-CF-Powered-By;\n";
            $rules .= "proxy_hide_header X-Mod-Pagespeed;\n";
            $rules .= "proxy_hide_header X-Pingback;\n";
        }

        if (Settings::get_option($settings, 'srv_security.protect_htaccess')) {
            $rules .= "location ~ /(?:\\.ht|nginx\\.conf$) {\n";
            $rules .= "    deny all;\n";
            $rules .= "}\n";
        }

        return $rules;
    }

    private static function generate_rules_enhancements($settings): string
    {
        $rules = self::apache_page_test_env_rule();

        if (Settings::get_option($settings, 'srv_enhancements.default_utf8')) {
            $rules .= "AddDefaultCharset utf-8\n";
        }

        if (Settings::get_option($settings, 'srv_enhancements.timezone')) {
            $rules .= "SetEnv TZ " . get_option('timezone_string') . "\n";
        }

        if (Settings::get_option($settings, 'srv_enhancements.keep_alive')) {
            $rules .= "<IfModule mod_headers.c>\n";
            $rules .= "     Header set Connection keep-alive" . self::apache_header_env() . "\n";
            $rules .= " </IfModule>\n";
        }

        if (Settings::get_option($settings, 'srv_enhancements.pagespeed')) {
            $rules .= "<IfModule pagespeed_module>\n";
            $rules .= "     ModPagespeed on\n";
            $rules .= "     ModPagespeedDisallow \"*wpopt_page_test=disabled*\"\n";
            $rules .= "     ModPagespeedEnableFilters rewrite_css,combine_css\n";
            $rules .= "     ModPagespeedEnableFilters collapse_whitespace,remove_comments\n";
            $rules .= "</IfModule>\n";
        }

        $rules .= "<IfModule mod_rewrite.c>\n";
        $rules .= "      RewriteEngine On\n";

        if (Settings::get_option($settings, 'srv_enhancements.redirect_https')) {
            $rules .= self::apache_page_test_rewrite_cond();
            $rules .= "      RewriteCond %{HTTPS} off\n";
            $rules .= "      RewriteRule (.*) https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n";
        }

        if (Settings::get_option($settings, 'srv_enhancements.follow_symlinks')) {
            $rules .= "      Options +FollowSymlinks\n";
        }

        if (Settings::get_option($settings, 'srv_enhancements.remove_www')) {

            $rules .= self::apache_page_test_rewrite_cond();
            $rules .= "      RewriteCond %{HTTP_HOST} ^www\.(.*)$ [NC]\n";
            if (Settings::get_option($settings, 'srv_enhancements.redirect_https'))
                $rules .= "      RewriteRule ^(.*)$ https://%1%{REQUEST_URI} [R=301,QSA,NC,L]\n";
            else
                $rules .= "      RewriteRule ^(.*)$ http://%1%{REQUEST_URI} [R=301,QSA,NC,L]\n";
        }

        $rules .= "</IfModule>\n";

        return $rules;
    }

    private static function generate_nginx_rules_enhancements($settings): string
    {
        $rules = '';

        if (Settings::get_option($settings, 'srv_enhancements.default_utf8')) {
            $rules .= "charset utf-8;\n";
        }

        if (Settings::get_option($settings, 'srv_enhancements.timezone')) {
            $timezone = get_option('timezone_string');

            if ($timezone) {
                $rules .= "fastcgi_param TZ " . self::quote_nginx_value($timezone) . ";\n";
            }
        }

        if (Settings::get_option($settings, 'srv_enhancements.keep_alive')) {
            $rules .= "keepalive_timeout 65;\n";
            $rules .= "keepalive_requests 100;\n";
        }

        if (Settings::get_option($settings, 'srv_enhancements.pagespeed')) {
            $rules .= "# PageSpeed requested by WP-Optimizer. Uncomment only if the ngx_pagespeed module is installed.\n";
            $rules .= "# pagespeed on;\n";
            $rules .= "# pagespeed EnableFilters rewrite_css,combine_css;\n";
            $rules .= "# pagespeed EnableFilters collapse_whitespace,remove_comments;\n";
        }

        if (Settings::get_option($settings, 'srv_enhancements.redirect_https')) {
            $rules .= self::nginx_page_test_rewrite_break();
            $rules .= "if (\$scheme = http) {\n";
            $rules .= "    return 301 https://\$host\$request_uri;\n";
            $rules .= "}\n";
        }

        if (Settings::get_option($settings, 'srv_enhancements.follow_symlinks')) {
            $rules .= "disable_symlinks off;\n";
        }

        if (Settings::get_option($settings, 'srv_enhancements.remove_www')) {
            $scheme = Settings::get_option($settings, 'srv_enhancements.redirect_https') ? 'https' : '$scheme';
            $rules .= self::nginx_page_test_rewrite_break();
            $rules .= "if (\$host ~* ^www\\.(?<wpopt_domain>.+)$) {\n";
            $rules .= "    return 301 {$scheme}://\$wpopt_domain\$request_uri;\n";
            $rules .= "}\n";
        }

        return $rules;
    }

    private static function quote_nginx_regex_part(string $value): string
    {
        return preg_quote($value, '~');
    }

    private static function quote_nginx_value(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\"'], $value) . '"';
    }

    public function write(): bool
    {
        return $this->edited and RuleUtil::write_rules($this->rules);
    }

    public function edited(): bool
    {
        return $this->edited;
    }

    public function toggle_rule(string $server_hooks, $activate)
    {
        $activate ? $this->add_rule($server_hooks) : $this->remove_rule($server_hooks);
    }

    public function remove_rule($name): void
    {
        $start_marker = '# WPOPT_MARKER_BEGIN_' . strtoupper($name);
        $end_marker = '# WPOPT_MARKER_END_' . strtoupper($name);

        $res = RuleUtil::remove_rules(
            $start_marker,
            $end_marker,
            $this->rules
        );

        $this->edited = ($this->edited or $res);
    }
}
