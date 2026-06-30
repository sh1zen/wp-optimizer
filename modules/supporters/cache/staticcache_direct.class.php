<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

use WPS\core\Disk;
use WPS\core\Settings;
use WPS\core\UtilEnv;

class StaticCacheDirectAccess
{
    private const STORAGE_DIR = 'wpopt/direct-static';
    private const BOOTSTRAP_FILE = 'wpopt-static-direct.php';
    private const CONFIG_FILE = 'config.php';
    private const INDEX_DIR = 'index';
    private const DEFAULT_USER_SCOPE = 'not_logged_in';
    private const DEFAULT_STATUS_CACHE_POLICY = array('2xx', '4xx', '5xx');

    public static function activate(array $options): bool
    {
        return self::write_runtime_files($options);
    }

    public static function deactivate(): bool
    {
        self::clear_index();
        self::delete_bootstrap();
        self::delete_runtime_storage();

        return !is_file(self::bootstrap_path()) && !is_file(self::config_path());
    }

    public static function write_runtime_files(array $options): bool
    {
        if (!self::is_supported()) {
            return false;
        }

        if (!Disk::make_path(self::base_dir(), true) || !Disk::make_path(self::index_dir(), true)) {
            return false;
        }

        return self::write_config(self::build_config($options)) && self::write_bootstrap();
    }

    public static function status(): array
    {
        $config = array();
        if (is_file(self::config_path())) {
            $loaded_config = include self::config_path();
            $config = is_array($loaded_config) ? $loaded_config : array();
        }

        return array(
            'supported'         => self::is_supported(),
            'root_writable'     => is_writable(ABSPATH),
            'storage_writable'  => is_dir(self::base_dir()) ? is_writable(self::base_dir()) : is_writable(dirname(self::base_dir())),
            'config_enabled'    => !empty($config['enabled']),
            'config_path'       => self::config_path(),
            'bootstrap_exists'  => is_file(self::bootstrap_path()),
            'bootstrap_path'    => self::bootstrap_path(),
            'index_dir_writable' => is_dir(self::index_dir()) ? is_writable(self::index_dir()) : is_writable(dirname(self::index_dir())),
        );
    }

    public static function record(string $request_path, string $query_string, string $cache_key, string $cache_group, array $options): bool
    {
        if (empty($options['direct_access_enabled']) || !self::is_supported()) {
            return false;
        }

        if (self::is_admin_request_path($request_path)) {
            return false;
        }

        $user_scope = Settings::get_option($options, 'user_scope', self::DEFAULT_USER_SCOPE);
        $is_logged_in = function_exists('is_user_logged_in') && is_user_logged_in();

        if ($is_logged_in && !self::logged_in_direct_access_allowed($options)) {
            return false;
        }

        if ($is_logged_in && $user_scope === 'not_logged_in') {
            return false;
        }

        if (!$is_logged_in && $user_scope === 'logged_in') {
            return false;
        }

        $signature = self::signature($request_path, $query_string, self::cookie_vary_fragment($options));
        $index_file = self::index_file($signature);

        if (!Disk::make_path(dirname($index_file), true)) {
            return false;
        }

        $cache_file = wps('wpopt')->storage->get_path($cache_group, $cache_key);
        $entry = array(
            'cache_file' => self::relative_path(ABSPATH, $cache_file),
            'cache_key'  => $cache_key,
            'created'    => time(),
        );

        return Disk::write($index_file, "<?php\nreturn " . var_export($entry, true) . ";\n", 0);
    }

    public static function clear_index(): void
    {
        if (is_dir(self::index_dir())) {
            Disk::delete(self::index_dir(), 0, '*');
        }
    }

    public static function get_index_size(): int
    {
        return Disk::calc_size(self::index_dir());
    }

    public static function get_index_file_count(): int
    {
        return Disk::count_files(self::index_dir());
    }

    public static function get_rewrite_target(): string
    {
        return self::BOOTSTRAP_FILE;
    }

    public static function get_script_uri_path(): string
    {
        $target = self::get_rewrite_target();
        if ($target === '') {
            return '';
        }

        $site_path = parse_url(home_url('/'), PHP_URL_PATH) ?: '/';

        return '/' . trim(trailingslashit($site_path) . $target, '/');
    }

    public static function apache_cookie_rewrite_condition(array $options): string
    {
        if ((bool)Settings::get_option($options, 'no_cache_cookies_enabled', true)) {
            $patterns = self::no_cache_cookie_patterns($options);

            if (empty($patterns)) {
                return '.+';
            }

            if (!self::logged_in_direct_access_allowed($options)) {
                $patterns = array_merge($patterns, self::logged_in_cookie_patterns());
            }

            return self::apache_cookie_pattern_condition($patterns);
        }

        if (self::logged_in_direct_access_allowed($options)) {
            return '';
        }

        return self::apache_cookie_pattern_condition(self::logged_in_cookie_patterns());
    }

    public static function is_supported(): bool
    {
        return is_writable(ABSPATH) && (is_writable(self::base_dir()) || is_writable(dirname(self::base_dir())));
    }

    private static function write_config(array $config): bool
    {
        if (!Disk::make_path(self::base_dir(), true)) {
            return false;
        }

        return Disk::write(self::config_path(), "<?php\nreturn " . var_export($config, true) . ";\n", 0);
    }

    private static function build_config(array $options): array
    {
        $site_path = parse_url(home_url('/'), PHP_URL_PATH) ?: '/';

        return array(
            'enabled'                       => true,
            'abspath'                       => '',
            'site_path'                     => '/' . trim($site_path, '/'),
            'index_dir'                     => self::relative_path(ABSPATH, self::index_dir()),
            'cache_query_args'              => !empty($options['cache_query_args']),
            'disable_admin_cache'           => (bool)Settings::get_option($options, 'disable_admin_cache', true),
            'cache_include_rules_only'      => !empty($options['cache_include_rules_only']),
            'rules'                         => (array)Settings::get_option($options, 'rules', array()),
            'user_scope'                    => Settings::get_option($options, 'user_scope', self::DEFAULT_USER_SCOPE),
            'user_agent_exclusions_enabled' => !empty($options['user_agent_exclusions_enabled']),
            'user_agent_exclusions'         => array_values((array)Settings::get_option($options, 'user_agent_exclusions', array())),
            'no_cache_cookies_enabled'      => (bool)Settings::get_option($options, 'no_cache_cookies_enabled', true),
            'no_cache_cookies'              => self::no_cache_cookie_patterns($options),
            'status_cache_policy'           => self::normalize_status_cache_policy(Settings::get_option($options, 'status_cache_policy', self::DEFAULT_STATUS_CACHE_POLICY)),
        );
    }

    private static function write_bootstrap(): bool
    {
        return Disk::write(
            self::bootstrap_path(),
            self::script_source(self::relative_path(ABSPATH, self::config_path())),
            0
        );
    }

    private static function base_dir(): string
    {
        return trailingslashit(WP_CONTENT_DIR) . self::STORAGE_DIR;
    }

    private static function index_dir(): string
    {
        return trailingslashit(self::base_dir()) . self::INDEX_DIR;
    }

    private static function bootstrap_path(): string
    {
        return trailingslashit(ABSPATH) . self::BOOTSTRAP_FILE;
    }

    private static function delete_bootstrap(): void
    {
        $path = self::bootstrap_path();

        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function delete_runtime_storage(): void
    {
        if (is_dir(self::base_dir())) {
            self::delete_directory(self::base_dir());
        }
    }

    private static function delete_directory(string $path): void
    {
        $path = realpath($path);
        $base = realpath(WP_CONTENT_DIR);

        if (!$path || !$base || strpos(wp_normalize_path($path), wp_normalize_path(trailingslashit($base))) !== 0) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            }
            else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }

    private static function config_path(): string
    {
        return trailingslashit(self::base_dir()) . self::CONFIG_FILE;
    }

    private static function index_file(string $signature): string
    {
        return trailingslashit(self::index_dir()) . substr($signature, 0, 2) . '/' . $signature . '.php';
    }

    private static function signature(string $request_path, string $query_string, string $cookie_vary = ''): string
    {
        return hash('sha256', trim($request_path, '/') . "\n" . $query_string . ($cookie_vary !== '' ? "\n" . $cookie_vary : ''));
    }

    private static function logged_in_direct_access_allowed(array $options): bool
    {
        $scope = Settings::get_option($options, 'user_scope', self::DEFAULT_USER_SCOPE);

        return empty($options['disable_admin_cache']) && in_array($scope, array('both', 'logged_in'), true);
    }

    private static function is_admin_request_path(string $request_path): bool
    {
        $request_path = trim($request_path, '/');

        return $request_path === 'wp-admin' || strpos($request_path, 'wp-admin/') === 0;
    }

    private static function cookie_vary_fragment(array $options): string
    {
        if (!self::logged_in_direct_access_allowed($options) || empty($_COOKIE) || !is_array($_COOKIE)) {
            return '';
        }

        $cookies = array();
        foreach ($_COOKIE as $name => $value) {
            $name = (string)$name;
            if (strpos($name, 'wordpress_logged_in_') === 0 || strpos($name, 'wordpress_sec_') === 0) {
                $cookies[$name] = (string)$value;
            }
        }

        if (empty($cookies)) {
            return '';
        }

        ksort($cookies);

        return hash('sha256', http_build_query($cookies, '', '&', PHP_QUERY_RFC3986));
    }

    private static function relative_path(string $base_path, string $target_path): string
    {
        $base_path = wp_normalize_path(trailingslashit($base_path));
        $target_path = wp_normalize_path($target_path);

        if (strpos($target_path, $base_path) === 0) {
            return ltrim(substr($target_path, strlen($base_path)), '/');
        }

        return $target_path;
    }

    private static function logged_in_cookie_patterns(): array
    {
        return array(
            'wordpress_logged_in_',
            'wordpress_sec_',
        );
    }

    private static function no_cache_cookie_patterns(array $options): array
    {
        $patterns = Settings::get_option($options, 'no_cache_cookies', array());

        if (is_string($patterns)) {
            $patterns = preg_split("#[\r\n]+#", $patterns);
        }

        if (!is_array($patterns)) {
            return array();
        }

        return array_values(array_unique(array_filter(array_map(static function ($pattern): string {
            return trim((string)$pattern);
        }, $patterns))));
    }

    private static function normalize_status_cache_policy($policy): array
    {
        if (is_string($policy)) {
            $policy = $policy === '' ? array() : preg_split("#[\s,]+#", $policy);
        }

        if (!is_array($policy)) {
            return self::DEFAULT_STATUS_CACHE_POLICY;
        }

        $allowed = array('2xx', '3xx', '4xx', '5xx');
        $normalized = array();

        foreach ($policy as $status_group) {
            $status_group = strtolower(trim((string)$status_group));
            if (in_array($status_group, $allowed, true)) {
                $normalized[] = $status_group;
            }
        }

        return array_values(array_unique($normalized));
    }

    private static function apache_cookie_pattern_condition(array $patterns): string
    {
        $patterns = array_values(array_unique(array_filter(array_map(static function ($pattern): string {
            return preg_quote(trim((string)$pattern), '#');
        }, $patterns))));

        return implode('|', $patterns);
    }

    private static function script_source(string $config_path): string
    {
        return "<?php\n\nconst WPOPT_STATIC_DIRECT_CONFIG_PATH = " . var_export($config_path, true) . ";\n" . <<<'PHP'

const WPOPT_STATIC_DIRECT_DEFAULT_USER_SCOPE = 'not_logged_in';
const WPOPT_STATIC_DIRECT_DEFAULT_STATUS_POLICY = array('2xx', '4xx', '5xx');

function wpopt_static_direct_is_absolute_path(string $path): bool
{
    return $path !== '' && ($path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1);
}

function wpopt_static_direct_resolve_path(string $path, ?string $base_path = null): string
{
    $path = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path);
    if (wpopt_static_direct_is_absolute_path($path)) {
        return $path;
    }

    return rtrim($base_path ?: __DIR__, '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
}

function wpopt_static_direct_load_config(): array
{
    $config_file = wpopt_static_direct_resolve_path(WPOPT_STATIC_DIRECT_CONFIG_PATH);
    if (!is_file($config_file)) {
        http_response_code(404);
        exit;
    }

    $config = require $config_file;
    if (!is_array($config)) {
        http_response_code(404);
        exit;
    }

    return $config;
}

function wpopt_static_direct_fallback(array $config): void
{
    $abspath = (string)($config['abspath'] ?? '');
    $abspath = $abspath === '' ? __DIR__ : wpopt_static_direct_resolve_path($abspath);
    $abspath = rtrim($abspath, '/\\') . DIRECTORY_SEPARATOR;
    if ($abspath === DIRECTORY_SEPARATOR || !is_file($abspath . 'index.php')) {
        http_response_code(404);
        exit;
    }

    chdir($abspath);
    require $abspath . 'index.php';
    exit;
}

function wpopt_static_direct_pattern_matches(string $pattern, string $value): bool
{
    $pattern = trim($pattern);
    if ($pattern === '') {
        return false;
    }

    set_error_handler(static function () {
    }, E_WARNING);
    $is_regex = @preg_match($pattern, '') !== false;
    $matched = $is_regex ? preg_match($pattern, $value) === 1 : stripos($value, $pattern) !== false;
    restore_error_handler();

    return $matched;
}

function wpopt_static_direct_request_path(array $config): string
{
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $path = rawurldecode((string)($path ?: '/'));
    $path = preg_replace('#/+#', '/', $path);

    $site_path = '/' . trim((string)($config['site_path'] ?? '/'), '/');
    if ($site_path !== '/' && (stripos($path, $site_path . '/') === 0 || strcasecmp($path, $site_path) === 0)) {
        $path = substr($path, strlen($site_path));
    }

    return trim($path, '/');
}

function wpopt_static_direct_query_string(array $config): ?string
{
    $query_string = (string)($_SERVER['QUERY_STRING'] ?? '');
    if ($query_string === '') {
        return '';
    }

    parse_str($query_string, $args);
    if (isset($args['s']) || isset($args['preview'])) {
        return null;
    }

    if (empty($config['cache_query_args'])) {
        return '';
    }

    unset($args['s'], $args['preview'], $args['wpopt_page_test'], $args['wpopt_page_test_expires'], $args['wpopt_page_test_run'], $args['wpopt_page_test_signature']);
    if (empty($args)) {
        return '';
    }

    ksort($args);

    return http_build_query($args, '', '&', PHP_QUERY_RFC3986);
}

function wpopt_static_direct_is_page_test_disabled(): bool
{
    $query_string = (string)($_SERVER['QUERY_STRING'] ?? '');
    if ($query_string === '') {
        return false;
    }

    parse_str($query_string, $args);
    $mode = isset($args['wpopt_page_test']) && is_scalar($args['wpopt_page_test'])
        ? (string)$args['wpopt_page_test']
        : '';

    return $mode === 'disabled';
}

function wpopt_static_direct_logged_in_allowed(array $config): bool
{
    $scope = (string)($config['user_scope'] ?? WPOPT_STATIC_DIRECT_DEFAULT_USER_SCOPE);

    return empty($config['disable_admin_cache']) && in_array($scope, array('both', 'logged_in'), true);
}

function wpopt_static_direct_has_logged_in_cookie(): bool
{
    if (empty($_COOKIE) || !is_array($_COOKIE)) {
        return false;
    }

    foreach (array_keys($_COOKIE) as $name) {
        $name = (string)$name;
        if (strpos($name, 'wordpress_logged_in_') === 0 || strpos($name, 'wordpress_sec_') === 0) {
            return true;
        }
    }

    return false;
}

function wpopt_static_direct_cookie_vary_fragment(array $config): string
{
    if (!wpopt_static_direct_logged_in_allowed($config) || empty($_COOKIE) || !is_array($_COOKIE)) {
        return '';
    }

    $cookies = array();
    foreach ($_COOKIE as $name => $value) {
        $name = (string)$name;
        if (strpos($name, 'wordpress_logged_in_') === 0 || strpos($name, 'wordpress_sec_') === 0) {
            $cookies[$name] = (string)$value;
        }
    }

    if (empty($cookies)) {
        return '';
    }

    ksort($cookies);

    return hash('sha256', http_build_query($cookies, '', '&', PHP_QUERY_RFC3986));
}

function wpopt_static_direct_cookie_is_blocked(array $config): bool
{
    if (empty($_COOKIE) || !is_array($_COOKIE)) {
        return false;
    }

    if (!empty($config['no_cache_cookies_enabled'])) {
        $patterns = (array)($config['no_cache_cookies'] ?? array());
        if (empty($patterns)) {
            return true;
        }

        if (!wpopt_static_direct_logged_in_allowed($config)) {
            $patterns = array_values(array_unique(array_merge($patterns, array('wordpress_logged_in_', 'wordpress_sec_'))));
        }
    }
    else {
        if (wpopt_static_direct_logged_in_allowed($config)) {
            return false;
        }

        $patterns = array('wordpress_logged_in_', 'wordpress_sec_');
    }

    foreach (array_keys($_COOKIE) as $cookie_name) {
        foreach ($patterns as $pattern) {
            if (wpopt_static_direct_pattern_matches((string)$pattern, (string)$cookie_name)) {
                return true;
            }
        }
    }

    return false;
}

function wpopt_static_direct_user_agent_is_blocked(array $config): bool
{
    if (empty($config['user_agent_exclusions_enabled'])) {
        return false;
    }

    $user_agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($user_agent === '') {
        return false;
    }

    foreach ((array)($config['user_agent_exclusions'] ?? array()) as $pattern) {
        if (wpopt_static_direct_pattern_matches((string)$pattern, $user_agent)) {
            return true;
        }
    }

    return false;
}

function wpopt_static_direct_rules_allow(array $config, string $path): bool
{
    $rules = array_values(array_filter((array)($config['rules'] ?? array()), static function ($rule): bool {
        return is_array($rule) && !empty($rule['pattern']) && !empty($rule['active']);
    }));

    $include_rules = array();
    $exclude_rules = array();
    foreach ($rules as $rule) {
        if (($rule['mode'] ?? 'include') === 'exclude') {
            $exclude_rules[] = $rule['pattern'];
        }
        else {
            $include_rules[] = $rule['pattern'];
        }
    }

    foreach ($exclude_rules as $pattern) {
        if (wpopt_static_direct_pattern_matches((string)$pattern, $path)) {
            return false;
        }
    }

    if (empty($config['cache_include_rules_only'])) {
        return true;
    }

    foreach ($include_rules as $pattern) {
        if (wpopt_static_direct_pattern_matches((string)$pattern, $path)) {
            return true;
        }
    }

    return false;
}

function wpopt_static_direct_index_file(array $config, string $path, string $query_string): string
{
    $cookie_vary = wpopt_static_direct_cookie_vary_fragment($config);
    $signature = hash('sha256', trim($path, '/') . "\n" . $query_string . ($cookie_vary !== '' ? "\n" . $cookie_vary : ''));

    return rtrim(wpopt_static_direct_resolve_path((string)($config['index_dir'] ?? '')), '/\\') . DIRECTORY_SEPARATOR . substr($signature, 0, 2) . DIRECTORY_SEPARATOR . $signature . '.php';
}

function wpopt_static_direct_status_is_allowed(array $config, int $status): bool
{
    $group = (int)floor($status / 100) . 'xx';

    return in_array($group, (array)($config['status_cache_policy'] ?? WPOPT_STATIC_DIRECT_DEFAULT_STATUS_POLICY), true);
}

function wpopt_static_direct_header_is_allowed(string $header): bool
{
    $name = strtolower(trim(strtok($header, ':') ?: ''));

    return in_array($name, array('content-type', 'location', 'x-robots-tag'), true);
}

$config = wpopt_static_direct_load_config();

if (empty($config['enabled']) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    wpopt_static_direct_fallback($config);
}

if (wpopt_static_direct_is_page_test_disabled()) {
    wpopt_static_direct_fallback($config);
}

$accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
if ($accept !== '' && stripos($accept, 'text/html') === false) {
    wpopt_static_direct_fallback($config);
}

$has_logged_in_cookie = wpopt_static_direct_has_logged_in_cookie();
$user_scope = (string)($config['user_scope'] ?? WPOPT_STATIC_DIRECT_DEFAULT_USER_SCOPE);
if (
    ($has_logged_in_cookie && !wpopt_static_direct_logged_in_allowed($config))
    || (!$has_logged_in_cookie && $user_scope === 'logged_in')
    || ($has_logged_in_cookie && $user_scope === 'not_logged_in')
    || wpopt_static_direct_cookie_is_blocked($config)
    || wpopt_static_direct_user_agent_is_blocked($config)
) {
    wpopt_static_direct_fallback($config);
}

$request_path = wpopt_static_direct_request_path($config);
$query_string = wpopt_static_direct_query_string($config);
if ($query_string === null || !wpopt_static_direct_rules_allow($config, $request_path)) {
    wpopt_static_direct_fallback($config);
}

$index_file = wpopt_static_direct_index_file($config, $request_path, $query_string);
if (!is_file($index_file)) {
    wpopt_static_direct_fallback($config);
}

$entry = require $index_file;
$cache_file = is_array($entry) && !empty($entry['cache_file'])
    ? wpopt_static_direct_resolve_path((string)$entry['cache_file'])
    : '';
if (!is_array($entry) || $cache_file === '' || !is_file($cache_file)) {
    @unlink($index_file);
    wpopt_static_direct_fallback($config);
}

$stored = @unserialize((string)@file_get_contents($cache_file), array('allowed_classes' => false));
if (!is_array($stored) || empty($stored['data']) || (!empty($stored['expire']) && (int)$stored['expire'] < time())) {
    @unlink($index_file);
    @unlink($cache_file);
    wpopt_static_direct_fallback($config);
}

$payload = $stored['data'];
$status = 200;
$body = '';
$headers = array();

if (is_array($payload)) {
    $status = isset($payload['status']) ? (int)$payload['status'] : 200;
    $body = (string)($payload['body'] ?? '');
    $headers = (array)($payload['headers'] ?? array());
}
else {
    $body = (string)$payload;
}

if ($status < 100 || $status > 599 || !wpopt_static_direct_status_is_allowed($config, $status)) {
    wpopt_static_direct_fallback($config);
}

http_response_code($status);
foreach ($headers as $header) {
    if (is_string($header) && wpopt_static_direct_header_is_allowed($header)) {
        header($header, true);
    }
}

header('X-WPOpt-Static-Direct: HIT');
echo $body;
exit;
PHP;
    }
}
