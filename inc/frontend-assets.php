<?php
/** Load WP Optimizer's frontend engines and register their endpoints and workers. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'WPOptimizerVendor\\Sabberworm\\CSS\\';
    if (strpos($class, $prefix) === 0) {
        $file = WPOPT_ABSPATH . 'vendors/css-parser/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) { require_once $file; }
    }
});

require_once WPOPT_SUPPORTERS . 'pagespeed/FrontendAssets.class.php';
require_once WPOPT_SUPPORTERS . 'pagespeed/LcpImages.class.php';
require_once WPOPT_SUPPORTERS . 'pagespeed/ScriptTiming.class.php';
require_once WPOPT_SUPPORTERS . 'pagespeed/UsedCss.class.php';
\WPOptimizer\modules\supporters\FrontendAssets::boot();
add_action('wps_used_css_build', array(\WPOptimizer\modules\supporters\UsedCss::class, 'build'), 10, 1);
