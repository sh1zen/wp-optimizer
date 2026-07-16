<?php

namespace WPS\core {
    final class Disk
    {
        public static function make_path(string $path, bool $recursive = false): bool
        {
            return is_dir($path) || mkdir($path, 0777, $recursive);
        }

        public static function write(string $path, string $contents, int $flags = 0): bool
        {
            self::make_path(dirname($path), true);

            return file_put_contents($path, $contents, $flags) !== false;
        }
    }

    final class Settings
    {
        public static function get_option(array $options, string $key, $default = null)
        {
            return array_key_exists($key, $options) ? $options[$key] : $default;
        }
    }

    final class UtilEnv
    {
    }
}

namespace {
    $test_root = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'wpopt-direct-cache-' . getmypid();
    define('ABSPATH', $test_root . DIRECTORY_SEPARATOR);
    define('WP_CONTENT_DIR', $test_root . DIRECTORY_SEPARATOR . 'wp-content');

    function trailingslashit(string $path): string
    {
        return rtrim($path, '/\\') . '/';
    }

    function home_url(string $path = ''): string
    {
        return 'https://example.test/' . ltrim($path, '/');
    }

    function wp_normalize_path(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    function wpopt_direct_test_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    function wpopt_direct_test_remove_directory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    wpopt_direct_test_remove_directory($test_root);
    mkdir(ABSPATH, 0777, true);
    mkdir(WP_CONTENT_DIR, 0777, true);

    require_once dirname(__DIR__) . '/inc/Compatibility.class.php';
    require_once dirname(__DIR__) . '/modules/supporters/cache/staticcache_direct.class.php';

    $bootstrap_path = ABSPATH . 'wpopt-static-direct.php';
    $config_path = WP_CONTENT_DIR . '/wpopt/direct-static/config.php';
    file_put_contents($bootstrap_path, '<?php // stale bootstrap');

    wpopt_direct_test_assert(
        \WPOptimizer\modules\supporters\StaticCacheDirectAccess::refresh_installed_runtime(),
        'A stale bootstrap without configuration must be recovered.'
    );
    $disabled_config = require $config_path;
    wpopt_direct_test_assert(
        is_array($disabled_config) && $disabled_config['enabled'] === false,
        'Missing direct-cache configuration must produce a disabled fail-safe runtime.'
    );

    file_put_contents($config_path, "<?php\nreturn " . var_export(array('enabled' => true), true) . ";\n");
    wpopt_direct_test_assert(
        \WPOptimizer\modules\supporters\StaticCacheDirectAccess::refresh_installed_runtime(),
        'A valid installed runtime must refresh successfully.'
    );
    $enabled_config = require $config_path;
    wpopt_direct_test_assert(!empty($enabled_config['enabled']), 'An enabled runtime must remain enabled.');
    wpopt_direct_test_assert(
        in_array('woocommerce_items_in_cart', $enabled_config['woocommerce_cookie_patterns'], true),
        'Refreshed direct-cache configuration must contain mandatory WooCommerce cookie exclusions.'
    );

    wpopt_direct_test_remove_directory($test_root);

    echo "Static direct-cache tests passed.\n";
}
