<?php
/**
 * Runtime compatibility policy for commerce and visual-builder requests.
 *
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\core;

final class Compatibility
{
    private const DEFAULT_WOOCOMMERCE_PATHS = array('cart', 'checkout', 'my-account');

    private const WOOCOMMERCE_CACHE_QUERY_KEYS = array('add-to-cart', 'wc-api', 'wc-ajax');

    private const WOOCOMMERCE_CACHE_COOKIE_PATTERNS = array(
        'woocommerce_cart_hash',
        'woocommerce_items_in_cart',
        'wp_woocommerce_session_',
        'woocommerce_recently_viewed',
        'store_notice',
    );

    private const WOOCOMMERCE_ASSET_PATHS = array(
        '/plugins/woocommerce/',
        '/plugins/woocommerce-',
    );

    private const PAGE_BUILDER_QUERY_KEYS = array(
        'preview',
        'preview_id',
        'preview_nonce',
        'elementor-preview',
        'elementor_library',
        'fl_builder',
        'fl_builder_ui',
        'et_fb',
        'et_bfb',
        'et_pb_preview',
        'bricks',
        'bricks_preview',
        'ct_builder',
        'oxygen_iframe',
        'oxy_preview_revision',
        'breakdance',
        'breakdance_iframe',
        'breakdance_builder',
    );

    private const PAGE_BUILDER_ASSET_PATHS = array(
        '/plugins/elementor/',
        '/plugins/elementor-pro/',
        '/plugins/bb-plugin/',
        '/plugins/bb-theme-builder/',
        '/themes/bb-theme/',
        '/plugins/divi-builder/',
        '/themes/divi/',
        '/themes/bricks/',
        '/plugins/bricks/',
        '/plugins/gutenberg/',
        '/wp-includes/blocks/',
        '/wp-includes/js/dist/',
        '/wp-includes/js/jquery/jquery.js',
        '/plugins/oxygen/',
        '/plugins/oxygen-elements/',
        '/plugins/oxy-',
        '/plugins/breakdance/',
        '/plugins/breakdance-pro/',
        '/uploads/elementor/',
        '/uploads/bb-plugin/',
        '/uploads/et-cache/',
        '/uploads/bricks/',
        '/uploads/oxygen/',
        '/uploads/breakdance/',
    );

    private const PAGE_BUILDER_MARKERS = array(
        'elementor-element',
        'fl-builder-content',
        'et_pb_',
        'wp-block-',
        'brx-body',
        'brxe-',
        'ct-section',
        'ct-div-block',
        'oxy-',
        'breakdance',
        'bde-',
    );

    public static function cache_bypass_reason(): string
    {
        if (self::is_woocommerce_sensitive_request()) {
            return 'woocommerce';
        }

        if (self::is_woocommerce_cache_mutation_request()) {
            return 'woocommerce_action';
        }

        if (self::has_woocommerce_cache_cookie()) {
            return 'woocommerce_session';
        }

        if (self::is_page_builder_request()) {
            return 'page_builder';
        }

        return '';
    }

    public static function should_bypass_optimization(): bool
    {
        $bypass = self::is_woocommerce_sensitive_request() || self::is_page_builder_request();

        if (!function_exists('apply_filters')) {
            return $bypass;
        }

        return $bypass || (bool)apply_filters('wpopt_compatibility_bypass_optimization', false, $bypass);
    }

    public static function is_woocommerce_sensitive_request(): bool
    {
        foreach (array('is_cart', 'is_checkout', 'is_account_page') as $conditional) {
            if (function_exists($conditional) && $conditional()) {
                return true;
            }
        }

        return self::path_is_woocommerce_sensitive(self::request_path());
    }

    public static function is_woocommerce_cache_mutation_request(?array $request = null): bool
    {
        $request = $request ?? self::request_values();
        foreach (self::WOOCOMMERCE_CACHE_QUERY_KEYS as $key) {
            if (array_key_exists($key, $request)) {
                return true;
            }
        }

        return false;
    }

    public static function woocommerce_cache_query_keys(): array
    {
        return self::WOOCOMMERCE_CACHE_QUERY_KEYS;
    }

    public static function has_woocommerce_cache_cookie(?array $cookies = null): bool
    {
        $cookies = $cookies ?? (is_array($_COOKIE ?? null) ? $_COOKIE : array());
        foreach (array_keys($cookies) as $cookie_name) {
            foreach (self::woocommerce_cache_cookie_patterns() as $pattern) {
                if (str_starts_with((string)$cookie_name, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function woocommerce_cache_cookie_patterns(): array
    {
        return self::WOOCOMMERCE_CACHE_COOKIE_PATTERNS;
    }

    public static function is_page_builder_request(): bool
    {
        if (self::query_has_page_builder_context(self::request_values())) {
            return true;
        }

        if (class_exists('FLBuilderModel') && is_callable(array('FLBuilderModel', 'is_builder_active')) && \FLBuilderModel::is_builder_active()) {
            return true;
        }

        if (function_exists('et_fb_is_enabled') && et_fb_is_enabled()) {
            return true;
        }

        if (function_exists('bricks_is_builder') && bricks_is_builder()) {
            return true;
        }

        if ((defined('SHOW_CT_BUILDER') && SHOW_CT_BUILDER) || (defined('OXYGEN_IFRAME') && OXYGEN_IFRAME)) {
            return true;
        }

        if (class_exists('Elementor\\Plugin') && isset(\Elementor\Plugin::$instance)) {
            $plugin = \Elementor\Plugin::$instance;
            if (
                (isset($plugin->editor) && is_callable(array($plugin->editor, 'is_edit_mode')) && $plugin->editor->is_edit_mode())
                || (isset($plugin->preview) && is_callable(array($plugin->preview, 'is_preview_mode')) && $plugin->preview->is_preview_mode())
            ) {
                return true;
            }
        }

        return function_exists('apply_filters') && (bool)apply_filters('wpopt_is_page_builder_request', false);
    }

    public static function query_has_page_builder_context(array $query): bool
    {
        foreach (self::page_builder_query_keys() as $key) {
            if (array_key_exists($key, $query)) {
                return true;
            }
        }

        return false;
    }

    public static function page_builder_query_keys(): array
    {
        $keys = self::PAGE_BUILDER_QUERY_KEYS;

        if (!function_exists('apply_filters')) {
            return $keys;
        }

        $filtered = array_filter((array)apply_filters('wpopt_page_builder_query_keys', $keys), 'is_string');

        return array_values(array_unique(array_merge($keys, $filtered)));
    }

    public static function path_is_woocommerce_sensitive(string $path, ?array $sensitive_paths = null): bool
    {
        $path = self::normalize_path($path);
        if ($path === '') {
            return false;
        }

        foreach ($sensitive_paths ?? self::woocommerce_sensitive_paths() as $sensitive_path) {
            $sensitive_path = self::normalize_path((string)$sensitive_path);
            if ($sensitive_path !== '' && ($path === $sensitive_path || str_starts_with($path, $sensitive_path . '/'))) {
                return true;
            }
        }

        return false;
    }

    public static function woocommerce_sensitive_paths(): array
    {
        $paths = self::woocommerce_is_available() ? self::DEFAULT_WOOCOMMERCE_PATHS : array();

        if (function_exists('wc_get_page_id') && function_exists('get_page_uri')) {
            foreach (array('cart', 'checkout', 'myaccount') as $page) {
                $page_id = (int)wc_get_page_id($page);
                if ($page_id > 0) {
                    $page_uri = get_page_uri($page_id);
                    if (is_string($page_uri) && $page_uri !== '') {
                        $paths[] = $page_uri;
                    }
                }
            }
        }

        if (function_exists('apply_filters')) {
            $paths = array_merge($paths, (array)apply_filters('wpopt_woocommerce_sensitive_paths', $paths));
        }

        return array_values(array_unique(array_filter(array_map(static function ($path): string {
            return self::normalize_path((string)$path);
        }, $paths))));
    }

    public static function is_page_builder_asset(string $url): bool
    {
        $path = parse_url(html_entity_decode($url), PHP_URL_PATH);
        $path = strtolower(str_replace('\\', '/', is_string($path) ? $path : $url));

        foreach (self::PAGE_BUILDER_ASSET_PATHS as $builder_path) {
            if (strpos($path, $builder_path) !== false) {
                return true;
            }
        }

        return function_exists('apply_filters') && (bool)apply_filters('wpopt_is_page_builder_asset', false, $url);
    }

    public static function is_woocommerce_asset(string $url): bool
    {
        $path = parse_url(html_entity_decode($url), PHP_URL_PATH);
        $path = strtolower(str_replace('\\', '/', is_string($path) ? $path : $url));

        foreach (self::WOOCOMMERCE_ASSET_PATHS as $woocommerce_path) {
            if (str_contains($path, $woocommerce_path)) {
                return true;
            }
        }

        return false;
    }

    public static function is_optimization_sensitive_asset(string $url): bool
    {
        return self::is_page_builder_asset($url) || self::is_woocommerce_asset($url);
    }

    public static function buffer_contains_page_builder_markup(string $buffer): bool
    {
        $normalized_buffer = strtolower($buffer);
        foreach (self::PAGE_BUILDER_MARKERS as $marker) {
            if (str_contains($normalized_buffer, strtolower($marker))) {
                return true;
            }
        }

        return function_exists('apply_filters') && (bool)apply_filters('wpopt_buffer_contains_page_builder_markup', false, $buffer);
    }

    public static function request_path(): string
    {
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = self::normalize_path(rawurldecode((string)$path));

        if (function_exists('home_url')) {
            $site_path = self::normalize_path((string)parse_url(home_url('/'), PHP_URL_PATH));
            if ($site_path !== '' && ($path === $site_path || str_starts_with($path, $site_path . '/'))) {
                $path = ltrim(substr($path, strlen($site_path)), '/');
            }
        }

        return $path;
    }

    public static function normalize_path(string $path): string
    {
        $path = preg_replace('#/+#', '/', str_replace('\\', '/', trim($path)));

        return trim((string)$path, '/');
    }

    private static function woocommerce_is_available(): bool
    {
        return defined('WC_VERSION') || function_exists('WC') || class_exists('WooCommerce', false);
    }

    private static function request_values(): array
    {
        $request = array_merge(
            is_array($_GET ?? null) ? $_GET : array(),
            is_array($_POST ?? null) ? $_POST : array()
        );

        return function_exists('wp_unslash') ? (array)wp_unslash($request) : $request;
    }
}
