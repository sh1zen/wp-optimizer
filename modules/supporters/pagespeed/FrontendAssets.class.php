<?php
namespace WPOptimizer\modules\supporters;

use WPS\core\UtilEnv;
use WPS\core\StringHelper;

/** Shared asset policy for WP Optimizer's frontend engines. */
final class FrontendAssets
{
    private const MAX_HASH_BYTES = 1048576;
    private static array $asset_versions = array();

    public static function boot(): void
    {
        foreach (array('delete_attachment', 'attachment_updated', 'switch_theme', 'upgrader_process_complete', 'edited_term', 'deleted_term', 'trashed_post', 'deleted_post') as $hook) {
            add_action($hook, array(self::class, 'invalidate'), 30, 0);
        }
        // Shared blocks, templates, navigation and styles affect pages beyond the saved post.
        foreach (array('wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'nav_menu_item') as $type) {
            add_action('save_post_' . $type, static function ($id) {
                if (!wp_is_post_revision($id) && !wp_is_post_autosave($id)) { self::invalidate(); }
            }, 30, 1);
        }
        add_action('wp_ajax_wps_lcp_measure', array(LcpImages::class, 'receive'));
        add_action('wp_ajax_nopriv_wps_lcp_measure', array(LcpImages::class, 'receive'));
    }

    public static function invalidate(): void
    {
        update_option('wps_frontend_revision', wp_generate_uuid4(), false);
        do_action('wps_frontend_assets_invalidated');
    }

    public static function revision(): string
    {
        return (string)get_option('wps_frontend_revision', '1');
    }

    public static function watch_settings(string $option, array $sections): void
    {
        add_action('update_option_' . $option, static function ($old, $new) use ($sections) {
            foreach ($sections as $section) {
                if (($old[$section] ?? array()) !== ($new[$section] ?? array())) { self::invalidate(); break; }
            }
        }, 30, 2);
    }

    public static function page(): string
    {
        // Personalized/query-string pages never share a generated result.
        $uri = wp_unslash($_SERVER['REQUEST_URI'] ?? '/');
        return self::absolute($uri, home_url('/'));
    }

    public static function eligible(): bool
    {
        return !is_admin() && !is_user_logged_in() && !is_preview() && !is_feed()
            && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
            && empty($_GET) && !is_404();
    }

    public static function rules($value): array
    {
        return array_values(array_filter(array_map('trim', is_array($value) ? $value : preg_split('/[\r\n]+/', (string)$value))));
    }

    public static function matches(string $value, $rules): bool
    {
        return StringHelper::contains_any($value, self::rules($rules));
    }

    public static function absolute(string $url, string $base): string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES, 'UTF-8');
        if ($url === '' || preg_match('~^(data|javascript|blob):~i', $url)) { return ''; }
        $absolute = esc_url_raw(\WP_Http::make_absolute_url($url, $base), array('http', 'https'));
        return preg_replace_callback('/[^\x21-\x7E]/u', static function ($m) { return rawurlencode($m[0]); }, $absolute) ?? '';
    }

    /** No network fetching, traversal or mapping remote URLs to local files. */
    public static function local(string $url): string
    {
        $site = wp_parse_url(site_url('/'));
        $parts = wp_parse_url($url);
        if (!$parts || strtolower($parts['host'] ?? '') !== strtolower($site['host'] ?? '')
            || ($parts['port'] ?? null) !== ($site['port'] ?? null)
            || !in_array($parts['scheme'] ?? '', array('http', 'https'), true)) { return ''; }
        $base = rtrim($site['path'] ?? '/', '/') . '/';
        $path = rawurldecode($parts['path'] ?? '');
        if (strpos($path, $base) !== 0 || strpos($path, "\0") !== false) { return ''; }
        $root = realpath(ABSPATH);
        $file = realpath(ABSPATH . substr($path, strlen($base)));
        return $file && is_file($file) && strpos(wp_normalize_path($file), rtrim(wp_normalize_path($root), '/') . '/') === 0 ? $file : '';
    }

    /** Reuse bounded content hashes; large assets use filesystem metadata without reading bytes. */
    public static function version(string $url): string
    {
        $file = self::local($url);
        if (!$file) { return $url . ':remote'; }

        clearstatcache(true, $file);
        $stat = stat($file);
        if ($stat === false) { return $url . ':unavailable'; }
        $signature = hash('sha256', $file . ':' . $stat['size'] . ':' . $stat['mtime'] . ':' . $stat['ctime'] . ':' . $stat['ino'] . ':' . self::revision());
        if ((self::$asset_versions[$file]['signature'] ?? '') !== $signature) {
            if ($stat['size'] > self::MAX_HASH_BYTES) {
                $version = 'stat:' . $signature;
            }
            else {
                $version = wp_cache_get($signature, 'wpopt_asset_versions');
                if (!is_string($version) || !preg_match('/^[a-f0-9]{64}$/', $version)) {
                    $version = hash_file('sha256', $file);
                    if ($version === false) { return $url . ':'; }
                    wp_cache_set($signature, $version, 'wpopt_asset_versions', HOUR_IN_SECONDS);
                }
            }
            self::$asset_versions[$file] = array('signature' => $signature, 'version' => $version);
        }
        return $url . ':' . self::$asset_versions[$file]['version'];
    }

    public static function runtime(string $name): string
    {
        $file = WPOPT_ABSPATH . 'assets/' . $name;
        return add_query_arg('ver', substr(hash_file('sha256', $file), 0, 12), UtilEnv::path_to_url($file, true));
    }

    public static function inline_runtime(string $name, string $id): string
    {
        // WordPress applies the same nonce/attribute filters used by native inline scripts.
        $file = WPOPT_ABSPATH . 'assets/' . $name;
        return is_readable($file) ? wp_get_inline_script_tag(file_get_contents($file), array('id' => $id)) : '';
    }

    public static function head(string $html, string $markup): string
    {
        // Early in the head, ahead of stylesheet/script discovery.
        return preg_replace_callback('~<head\b[^>]*>~i', static function ($m) use ($markup) { return $m[0] . "\n" . $markup; }, $html, 1);
    }

    public static function diagnostic(string $feature, array $data): void
    {
        // Persist a sample only when details change, at most once a minute per feature.
        $key = 'wps_frontend_diagnostic_' . $feature;
        $previous = get_transient($key);
        $now = time();
        if (!is_array($previous) || (($previous['details'] ?? null) !== $data && (int)($previous['time'] ?? 0) <= $now - MINUTE_IN_SECONDS)) {
            set_transient($key, array('page' => self::page(), 'time' => $now, 'details' => $data), DAY_IN_SECONDS);
        }
        do_action('wps_frontend_diagnostic', $feature, $data);
    }
}
