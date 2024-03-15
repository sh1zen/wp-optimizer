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

/**
 * Class to handle Security and Optimization requests
 * @since 1.5.0
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
        $this->rules = RuleUtil::get_rules();

        $this->order = array(
            '# BEGIN O_API',
            '# BEGIN FLEX_API',
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

    public function get_rules()
    {
        return $this->rules;
    }

    public function has_rule($rule_name)
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

        $rules .= "\n$end_marker\n";

        return $rules;
    }

    private static function generate_rules_mime_types(): string
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

    private static function generate_rules_browser_cache($settings): string
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
                $headers_rules .= "        Header set Pragma \"no-cache\"\n";
                $headers_rules .= "        Header set Cache-Control \"max-age=0, private, no-store, no-cache, must-revalidate\"\n";
                $headers_rules .= "        Header unset Last-Modified\n";
                $headers_rules .= "        Header unset ETag\n";

                $file_match_rules .= "    FileETag None\n";
            }
            else {

                $cache_policy['immutable'] = ($cache_policy['immutable'] and Settings::get_option($settings, 'srv_browser_cache.immutable', false));

                $headers_rules .= "        Header set Pragma \"public\"\n";

                $cache_control = array();

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

                $headers_rules .= "        Header set Cache-Control \"" . implode(', ', $cache_control) . "\"\n";

                $file_match_rules .= "    FileETag MTime Size\n";
            }

            $file_match_rules .= "    <IfModule mod_headers.c>\n";
            $file_match_rules .= $headers_rules;
            $file_match_rules .= "    </IfModule>\n";

            $cache_control_rules .= "<FilesMatch \"\\.(" . implode('|', array_merge($extensions_lowercase, $extensions_uppercase)) . ")$\">\n" . $file_match_rules . "</FilesMatch>\n";
        }

        $rules .= "</IfModule>\n";

        return $rules . $cache_control_rules;
    }

    private static function generate_rules_compression($settings): string
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

    private static function generate_rules_security($settings): string
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
            $rules .= "ServerSignature Off\n";
        }

        return $rules;
    }

    private static function generate_rules_enhancements($settings): string
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