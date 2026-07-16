<?php

$GLOBALS['wpopt_test_filters'] = array();

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, $value, ...$args)
    {
        $callback = $GLOBALS['wpopt_test_filters'][$hook] ?? null;

        return is_callable($callback) ? $callback($value, ...$args) : $value;
    }
}

require_once dirname(__DIR__) . '/inc/Compatibility.class.php';

use WPOptimizer\core\Compatibility;

function wpopt_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

wpopt_test_assert(
    Compatibility::path_is_woocommerce_sensitive('checkout/order-pay/42', array('checkout')),
    'WooCommerce child endpoints must be excluded.'
);
wpopt_test_assert(
    Compatibility::path_is_woocommerce_sensitive('negozio/cassa/order-received/42', array('negozio/cassa')),
    'Custom WooCommerce page paths must be excluded.'
);
wpopt_test_assert(
    !Compatibility::path_is_woocommerce_sensitive('checkout-example', array('checkout')),
    'Partial path names must not be excluded.'
);
wpopt_test_assert(
    Compatibility::is_woocommerce_cache_mutation_request(array('add-to-cart' => '42')),
    'Add-to-cart requests must bypass cache.'
);
wpopt_test_assert(
    Compatibility::has_woocommerce_cache_cookie(array('wp_woocommerce_session_hash' => 'session')),
    'WooCommerce session cookies must bypass cache.'
);

$_COOKIE = array('woocommerce_items_in_cart' => '1');
$_GET = array();
$_POST = array();
$_SERVER['REQUEST_URI'] = '/ordinary-page/';
wpopt_test_assert(
    Compatibility::cache_bypass_reason() === 'woocommerce_session',
    'WooCommerce session cookies must bypass cache on otherwise cacheable pages.'
);
wpopt_test_assert(
    !Compatibility::should_bypass_optimization(),
    'A WooCommerce cookie alone must not disable safe HTML optimization.'
);
$_COOKIE = array();
wpopt_test_assert(
    Compatibility::woocommerce_sensitive_paths() === array(),
    'WooCommerce route defaults must not affect sites where WooCommerce is unavailable.'
);

foreach (array(
    'elementor-preview', 'fl_builder', 'et_fb', 'preview', 'bricks', 'ct_builder', 'breakdance_iframe',
) as $builder_key) {
    wpopt_test_assert(
        Compatibility::query_has_page_builder_context(array($builder_key => '1')),
        "The {$builder_key} builder context must be detected."
    );
}

wpopt_test_assert(
    Compatibility::is_page_builder_asset('https://example.test/wp-content/plugins/elementor/assets/app.js'),
    'Elementor assets must be preserved.'
);
wpopt_test_assert(
    Compatibility::is_page_builder_asset('https://example.test/wp-includes/blocks/navigation/style.css'),
    'Gutenberg block assets must be preserved.'
);
wpopt_test_assert(
    Compatibility::is_page_builder_asset('https://example.test/wp-content/uploads/breakdance/css/post-42.css'),
    'Generated builder assets must be preserved.'
);
wpopt_test_assert(
    Compatibility::is_page_builder_asset('https://example.test/wp-includes/js/jquery/jquery.js'),
    'Oxygen-compatible jQuery assets must be preserved.'
);
wpopt_test_assert(
    Compatibility::is_optimization_sensitive_asset('https://example.test/wp-content/plugins/woocommerce/assets/js/frontend/cart.js'),
    'WooCommerce assets must be preserved from minifier rewriting.'
);
wpopt_test_assert(
    !Compatibility::is_page_builder_asset('https://example.test/wp-content/themes/custom/style.css'),
    'Unrelated assets must remain optimizable.'
);
wpopt_test_assert(
    Compatibility::buffer_contains_page_builder_markup('<main class="brxe-container"></main>'),
    'Builder markup must be detected.'
);

$GLOBALS['wpopt_test_filters']['wpopt_page_builder_query_keys'] = static function (): array {
    return array();
};
wpopt_test_assert(
    Compatibility::query_has_page_builder_context(array('elementor-preview' => '42')),
    'Filters must not be able to remove built-in builder protections.'
);

defined('WC_VERSION') || define('WC_VERSION', 'test');
$GLOBALS['wpopt_test_filters']['wpopt_woocommerce_sensitive_paths'] = static function (): array {
    return array();
};
wpopt_test_assert(
    in_array('checkout', Compatibility::woocommerce_sensitive_paths(), true),
    'Filters must not be able to remove built-in WooCommerce routes.'
);

$_SERVER['REQUEST_URI'] = '/checkout/order-pay/42';
$GLOBALS['wpopt_test_filters']['wpopt_compatibility_bypass_optimization'] = static function (): bool {
    return false;
};
wpopt_test_assert(
    Compatibility::should_bypass_optimization(),
    'Filters must not be able to disable mandatory checkout protection.'
);

echo "Compatibility tests passed.\n";
