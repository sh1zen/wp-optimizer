<?php

define('ABSPATH', __DIR__ . '/runtime/');

function wp_is_stream(string $path): bool
{
    return strpos($path, '://') !== false;
}

eval(<<<'PHP'
namespace WPS\core;

class Settings
{
    public static function get_option($settings, $path, $default = false)
    {
        $value = $settings;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
PHP);

require_once dirname(__DIR__, 3) . '/themes/flex-and-go/core/vendors/wps-framework/UtilEnv.php';
require_once dirname(__DIR__, 3) . '/themes/flex-and-go/core/vendors/wps-framework/RuleUtil.php';
require_once dirname(__DIR__) . '/modules/supporters/optisec/localConf.php';

use WPS\core\RuleUtil;
use WPS\core\UtilEnv;
use WPOptimizer\modules\supporters\WP_Htaccess;

function wpopt_server_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$original_server_software = $_SERVER['SERVER_SOFTWARE'] ?? null;

$servers = array(
    array('Apache/2.4.62', null, false, false, 'Apache', '.htaccess'),
    array('LiteSpeed/6.3.1', null, true, false, 'LiteSpeed', '.htaccess'),
    array('LiteSpeed', 'Openlitespeed', true, true, 'OpenLiteSpeed', '.htaccess'),
    array('OpenLiteSpeed/1.8.3', null, true, true, 'OpenLiteSpeed', '.htaccess'),
    array('nginx/1.27.4', null, false, false, 'Nginx', 'nginx.conf'),
);

foreach ($servers as [$signature, $edition, $is_litespeed, $is_openlitespeed, $label, $rules_file]) {
    $_SERVER['SERVER_SOFTWARE'] = $signature;
    if ($edition === null) {
        unset($_SERVER['LSWS_EDITION']);
    }
    else {
        $_SERVER['LSWS_EDITION'] = $edition;
    }

    wpopt_server_assert(UtilEnv::is_litespeed() === $is_litespeed, "Unexpected LiteSpeed detection for {$signature}.");
    wpopt_server_assert(UtilEnv::is_openlitespeed() === $is_openlitespeed, "Unexpected OpenLiteSpeed detection for {$signature}.");
    wpopt_server_assert(WP_Htaccess::get_server_label() === $label, "Unexpected server label for {$signature}.");
    wpopt_server_assert(basename((string)RuleUtil::get_rules_path()) === $rules_file, "Unexpected rules file for {$signature}.");
}

unset($_SERVER['LSWS_EDITION']);

$generate_rules = new ReflectionMethod(WP_Htaccess::class, 'generate_rules');
$generate_rules->setAccessible(true);
$enhancement_settings = array(
    'srv_enhancements' => array(
        'redirect_https' => true,
        'remove_www'     => true,
    ),
);

$_SERVER['SERVER_SOFTWARE'] = 'OpenLiteSpeed/1.8.3';
$openlitespeed_rules = $generate_rules->invoke(null, 'srv_enhancements', '# BEGIN TEST', '# END TEST', $enhancement_settings);
wpopt_server_assert(strpos($openlitespeed_rules, 'RewriteRule') !== false, 'OpenLiteSpeed must receive compatible rewrite enhancements.');
wpopt_server_assert(strpos($openlitespeed_rules, '<IfModule') === false, 'OpenLiteSpeed rules must not contain unsupported Apache containers.');
wpopt_server_assert(strpos($openlitespeed_rules, 'Header ') === false, 'OpenLiteSpeed .htaccess rules must not contain unsupported header directives.');

$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed/6.3.1';
$litespeed_rules = $generate_rules->invoke(null, 'srv_enhancements', '# BEGIN TEST', '# END TEST', $enhancement_settings);
wpopt_server_assert(strpos($litespeed_rules, '<IfModule mod_rewrite.c>') !== false, 'LiteSpeed Enterprise must receive Apache-compatible directives.');

if ($original_server_software === null) {
    unset($_SERVER['SERVER_SOFTWARE']);
}
else {
    $_SERVER['SERVER_SOFTWARE'] = $original_server_software;
}

echo "Server compatibility tests passed.\n";
