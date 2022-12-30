<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C)  2022
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

use SHZN\core\Settings;
use SHZN\core\UtilEnv;
use SHZN\core\RuleUtil;

/**
 * Class to handle Security and Optimization requests
 * @since 1.5.0
 */
class WP_Enhancer
{
    private static WP_Enhancer $_instance;

    private function __construct()
    {
    }

    /**
     * @return WP_Enhancer
     */
    public static function getInstance()
    {
        if (!isset(self::$_instance)) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public static function server_conf($item, $mode, $settings = array(), $no_write = false)
    {
        if ($mode === 'get')
            return RuleUtil::get_rules();

        $order = array(
            WPOPT_MARKER_BEGIN_MIME_TYPES,
            '# WPOPT_MARKER_BEGIN_SRV_COMPRESSION',
            '# WPOPT_MARKER_BEGIN_SRV_ENHANCEMENTS',
            '# WPOPT_MARKER_BEGIN_SRV_SECURITY',
            WPOPT_MARKER_BEGIN_WORDPRESS,
            '# WPOPT_MARKER_BEGIN_SRV_BROWSER_CACHE',
        );

        if (Settings::get_option($settings, 'srv_compression') or Settings::get_option($settings, 'srv_browser_cache')) {

            $response = RuleUtil::add_rules(
                self::generate_rules('srv_mime_types', WPOPT_MARKER_BEGIN_MIME_TYPES, WPOPT_MARKER_END_MIME_TYPES, $settings),
                WPOPT_MARKER_BEGIN_MIME_TYPES,
                WPOPT_MARKER_END_MIME_TYPES,
                $order,
                $no_write
            );
        }
        else {
            $response = RuleUtil::remove_rules(
                WPOPT_MARKER_BEGIN_MIME_TYPES,
                WPOPT_MARKER_END_MIME_TYPES,
                $no_write
            );
        }

        $start_marker = '# WPOPT_MARKER_BEGIN_' . strtoupper($item);
        $end_marker = '# WPOPT_MARKER_END_' . strtoupper($item);

        switch ($mode) {
            case 'add':
                $response = RuleUtil::add_rules(
                    self::generate_rules($item, $start_marker, $end_marker, $settings),
                    $start_marker,
                    $end_marker,
                    $order,
                    $no_write
                );
                break;
            case'remove':
                $response = RuleUtil::remove_rules(
                    $start_marker,
                    $end_marker,
                    $no_write
                );
                break;
        }

        return $response;
    }

    private static function generate_rules($item, $start_marker, $end_marker, $settings = array())
    {
        $rules = "\n" . $start_marker . "\n";

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
        }

        $rules .= $end_marker . "\n";

        return $rules;
    }

    private static function generate_rules_mime_types()
    {
        $ext_types = self::get_ext_types(array('image', 'fonts', 'audio', 'document', 'spreadsheet', 'text', 'code'));
        $mime_types = self::get_mime_types($ext_types);

        $rules = '';

        if (!$mime_types) {
            return '';
        }

        $rules .= "<IfModule mod_mime.c>\n";

        foreach ($mime_types as $mime_type => $ext) {
            $rules .= "    AddType " . $mime_type . " ." . implode(' .', $ext) . "\n";
        }

        $rules .= "</IfModule>\n";

        return $rules;
    }

    private static function get_ext_types($filter = array(), $key_value = false)
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

    private static function generate_rules_browser_cache($settings)
    {
        $default_lifetime = Settings::get_option($settings, "srv_browser_cache.lifetime_default", WEEK_IN_SECONDS);

        $cache_control_rules = '';

        $rules = "<IfModule mod_expires.c>\n";
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

            if (Settings::get_option($settings, 'srv_browser_cache.cache_control')) {

                $extensions_lowercase = array_map('strtolower', $extensions);
                $extensions_uppercase = array_map('strtoupper', $extensions);

                $headers_rules = '';
                $file_match_rules = '';

                $cache_policy = array();

                switch ($type) {

                    case 'text':
                        $cache_policy = array(
                            'cache'      => true,
                            'revalidate' => true
                        );
                        break;

                    case 'image':
                    case 'font':
                        $cache_policy = array(
                            'cache'   => true,
                            'max-age' => true,
                            'stale'   => true
                        );
                        break;

                    case 'audio':
                    case 'video':
                        $cache_policy = array(
                            'cache'      => true,
                            'private'    => true,
                            'revalidate' => true
                        );
                        break;

                    case 'document':
                    case 'spreadsheet':
                    case 'interactive':
                    case 'archive':
                        $cache_policy = array(
                            'cache'      => true,
                            'private'    => true,
                            'max-age'    => true,
                            'revalidate' => true
                        );
                        break;

                    case 'code':
                        break;
                }

                $cache_policy = array_merge(array(
                    'cache'      => false,
                    'max-age'    => false,
                    'private'    => false,
                    'revalidate' => false,
                    'stale'      => false,
                    'extra'      => false
                ), $cache_policy);

                if (!$cache_policy['cache']) {
                    $headers_rules .= "        Header set Pragma \"no-cache\"\n";
                    $headers_rules .= "        Header set Cache-Control \"max-age=0, private, no-store, no-cache, must-revalidate\"\n";
                    $headers_rules .= "        Header unset Last-Modified\n";
                    $headers_rules .= "        Header unset ETag\n";

                    $file_match_rules .= "    FileETag None\n";
                }
                else {
                    $headers_rules .= "        Header set Pragma \"public\"\n";

                    $cache_control = array();
                    $header_join = "append";

                    $cache_control[] = $cache_policy['private'] ? 'private' : 'public';

                    if ($cache_policy['max-age']) {
                        $header_join = "set";
                        $cache_control[] = "max-age=" . $lifetime;
                    }

                    if ($cache_policy['stale']) {
                        $cache_control[] = "stale-while-revalidate=" . $lifetime;
                    }
                    elseif ($cache_policy['revalidate']) {
                        $cache_control[] = 'must-revalidate';

                        if (!$cache_policy['private'])
                            $cache_control[] = 'proxy-revalidate';
                    }

                    if ($cache_policy['extra']) {
                        $cache_control[] = $cache_policy['extra'];
                    }

                    $headers_rules .= "        Header {$header_join} Cache-Control \"" . implode(', ', $cache_control) . "\"\n";

                    $file_match_rules .= "    FileETag MTime Size\n";
                }

                $file_match_rules .= "    <IfModule mod_headers.c>\n";
                $file_match_rules .= $headers_rules;
                $file_match_rules .= "    </IfModule>\n";

                $cache_control_rules .= "<FilesMatch \"\\.(" . implode('|', array_merge($extensions_lowercase, $extensions_uppercase)) . ")$\">\n" . $file_match_rules . "</FilesMatch>\n";
            }
        }

        $rules .= "</IfModule>\n";

        return $rules . $cache_control_rules;
    }

    private static function generate_rules_compression($settings)
    {
        $ext_types = self::get_ext_types(array('image', 'document', 'spreadsheet', 'text', 'code'));

        $mime_types = self::get_mime_types($ext_types, 'keys');
        $extensions = self::get_mime_types($ext_types, 'flat_values');

        $extensions = str_replace('.', '', $extensions);

        $rules = '';

        if (Settings::get_option($settings, 'srv_compression.gzip')) {

            $rules .= "<IfModule mod_deflate.c>\n";

            $rules .= "    <IfModule mod_setenvif.c>\n";
            $rules .= "        BrowserMatch ^Mozilla/4 gzip-only-text/html\n";
            $rules .= "        BrowserMatch ^Mozilla/4\\.0[678] no-gzip\n";
            $rules .= "        BrowserMatch \\bMSIE !no-gzip !gzip-only-text/html\n";
            $rules .= "        BrowserMatch \\bMSI[E] !no-gzip !gzip-only-text/html\n";
            $rules .= "    </IfModule>\n";

            $rules .= "    <IfModule mod_headers.c>\n";
            // Force proxies to cache gzipped & non-gzipped css/js files separately.
            $rules .= "        Header append Vary Accept-Encoding\n";
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
            $rules .= "        Header append Vary Accept-Encoding\n";
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

    private static function generate_rules_security($settings)
    {
        $rules = '';

        if (Settings::get_option($settings, 'srv_security.listings')) {
            $rules .= "Options All -Indexes\n";
        }

        if (Settings::get_option($settings, 'srv_security.http_track&trace')) {
            $rules .= "<IfModule mod_rewrite.c>\n";
            $rules .= "    RewriteEngine on\n";
            $rules .= "    RewriteCond %{REQUEST_METHOD} ^(TRACE|TRACK)\n";
            $rules .= "    RewriteRule .* - [F]\n";
            $rules .= "</IfModule>\n";
        }

        $rules .= "<IfModule mod_headers.c>\n";

        if (Settings::get_option($settings, 'srv_security.cors'))
            $rules .= "    Header set Access-Control-Allow-Origin " . get_site_url() . "\n";

        if (Settings::get_option($settings, 'srv_security.xss'))
            $rules .= "    Header set X-XSS-Protection \"1; mode=block\"\n";

        if (Settings::get_option($settings, 'srv_security.nosniff'))
            $rules .= "    Header set X-Content-Type-Options \"nosniff\"\n";

        if (Settings::get_option($settings, 'srv_security.noreferrer'))
            $rules .= "    Header set Referrer-Policy \"no-referrer\"\n";

        if (Settings::get_option($settings, 'srv_security.noframe'))
            $rules .= "    Header set X-Frame-Options \"DENY\"\n";

        if (Settings::get_option($settings, 'srv_security.hsts'))
            $rules .= "    Header set Strict-Transport-Security \"max-age=31536000; includeSubDomains; preload\"\n";

        if (Settings::get_option($settings, 'srv_security.signature')) {
            $rules .= "    Header unset Server\n";
            $rules .= "    Header always unset X-Powered-By\n";
            $rules .= "    Header unset X-Powered-By\n";
            $rules .= "    Header unset X-CF-Powered-By\n";
            $rules .= "    Header unset X-Mod-Pagespeed\n";
            $rules .= "    Header unset X-Pingback\n";
        }

        $rules .= "</IfModule>\n";

        if (Settings::get_option($settings, 'srv_security.protect_htaccess')) {
            $rules .= "<Files .htaccess>\n";
            $rules .= "    order allow,deny\n";
            $rules .= "    deny from all\n";
            $rules .= " </Files>\n";
        }

        if (Settings::get_option($settings, 'srv_security.signature')) {
            //$rules .= "ServerTokens Prod\n";
            $rules .= "ServerSignature Off\n";
        }

        return $rules;
    }

    private static function generate_rules_enhancements($settings)
    {
        $rules = '';

        if (Settings::get_option($settings, 'srv_enhancements.default_utf8')) {
            $rules .= "AddDefaultCharset utf-8\n";
        }

        if (Settings::get_option($settings, 'srv_enhancements.timezone')) {
            $rules .= "SetEnv TZ " . get_option('timezone_string') . "\n";
        }

        if (Settings::get_option($settings, 'srv_enhancements.keep_alive')) {
            $rules .= "<IfModule mod_headers.c>\n";
            $rules .= "     Header set Connection keep-alive\n";
            $rules .= " </IfModule>\n";
        }

        if (Settings::get_option($settings, 'srv_enhancements.pagespeed')) {
            $rules .= "<IfModule pagespeed_module>\n";
            $rules .= "     ModPagespeed on\n";
            $rules .= "     ModPagespeedEnableFilters rewrite_css,combine_css\n";
            $rules .= "     ModPagespeedEnableFilters recompress_images\n";
            $rules .= "     ModPagespeedEnableFilters convert_png_to_jpeg,convert_jpeg_to_webp\n";
            $rules .= "     ModPagespeedEnableFilters collapse_whitespace,remove_comments\n";
            $rules .= "</IfModule>\n";
        }

        $rules .= "<IfModule mod_rewrite.c>\n";
        $rules .= "      RewriteEngine On\n";

        if (Settings::get_option($settings, 'srv_enhancements.redirect_https')) {
            $rules .= "      RewriteCond %{HTTPS} off\n";
            $rules .= "      RewriteRule (.*) https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n";
        }

        if (Settings::get_option($settings, 'srv_enhancements.redirect_https')) {
            $rules .= "      RewriteCond %{HTTPS} off\n";
            $rules .= "      RewriteRule (.*) https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n";
        }

        if (Settings::get_option($settings, 'srv_enhancements.follow_symlinks')) {
            $rules .= "      Options +FollowSymlinks\n";
        }

        if (Settings::get_option($settings, 'srv_enhancements.remove_www')) {

            $rules .= "      RewriteCond %{HTTP_HOST} ^www\.(.*)$ [NC]\n";
            if (Settings::get_option($settings, 'srv_enhancements.redirect_https'))
                $rules .= "      RewriteRule ^(.*)$ https://%1%{REQUEST_URI} [R=301,QSA,NC,L]\n";
            else
                $rules .= "      RewriteRule ^(.*)$ http://%1%{REQUEST_URI} [R=301,QSA,NC,L]\n";
        }

        $rules .= "</IfModule>\n";

        return $rules;
    }
}