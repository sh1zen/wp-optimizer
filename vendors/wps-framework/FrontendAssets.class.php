<?php
namespace WPS\core;

/** Shared, deliberately conservative frontend asset policy. */
final class FrontendAssets
{
    public static function boot(): void
    {
        spl_autoload_register(static function ($class) {
            $prefix = 'WPSVendor\\Sabberworm\\CSS\\';
            if (strpos($class, $prefix) === 0) {
                $file = __DIR__ . '/vendor/css-parser/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) { require_once $file; }
            }
        });
        foreach (array('delete_attachment', 'attachment_updated', 'switch_theme', 'upgrader_process_complete', 'edited_term', 'deleted_term', 'trashed_post', 'deleted_post') as $hook) {
            add_action($hook, array(self::class, 'invalidate'), 30, 0);
        }
        add_action('save_post', static function ($id) {
            if (!wp_is_post_revision($id) && !wp_is_post_autosave($id)) { self::invalidate(); }
        }, 30, 1);
        add_action('wp_ajax_wps_lcp_measure', array(LcpImages::class, 'receive'));
        add_action('wp_ajax_nopriv_wps_lcp_measure', array(LcpImages::class, 'receive'));
        add_action('wps_used_css_build', array(UsedCss::class, 'build'), 10, 1);
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
        foreach (self::rules($rules) as $rule) {
            if (stripos($value, $rule) !== false) { return true; }
        }
        return false;
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

    public static function version(string $url): string
    {
        $file = self::local($url);
        return $url . ($file ? ':' . hash_file('sha256', $file) : ':remote');
    }

    public static function runtime(string $name): string
    {
        $file = __DIR__ . '/assets/js/' . $name;
        return add_query_arg('ver', substr(hash_file('sha256', $file), 0, 12), UtilEnv::path_to_url($file, true));
    }

    public static function inline_runtime(string $name, string $id): string
    {
        // WordPress applies the same nonce/attribute filters used by native inline scripts.
        $file = __DIR__ . '/assets/js/' . $name;
        return is_readable($file) ? wp_get_inline_script_tag(file_get_contents($file), array('id' => $id)) : '';
    }

    public static function head(string $html, string $markup): string
    {
        // Early in the head, ahead of stylesheet/script discovery.
        return preg_replace_callback('~<head\b[^>]*>~i', static function ($m) use ($markup) { return $m[0] . "\n" . $markup; }, $html, 1);
    }

    public static function diagnostic(string $feature, array $data): void
    {
        // An administrator can inspect the last public-page result from module settings.
        set_transient('wps_frontend_diagnostic_' . $feature, array('page' => self::page(), 'time' => time(), 'details' => $data), DAY_IN_SECONDS);
        do_action('wps_frontend_diagnostic', $feature, $data);
    }
}
