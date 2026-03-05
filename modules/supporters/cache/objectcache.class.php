<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

use WPS\core\Disk;

class ObjectCache extends Cache_Dispatcher
{
    public static function activate()
    {
        Disk::write(
            WP_CONTENT_DIR . DIRECTORY_SEPARATOR . "object-cache.php",
            "<?php\n\n// WP-Optimizer object cache drop-in\n" .
            "if (defined('WPOPT_SUPPORTERS')) {\n" .
            "    include_once(WPOPT_SUPPORTERS . 'cache/object-cache.php');\n" .
            "} else {\n" .
            "    // Fallback: compute path relative to this file if constant missing.\n" .
            "    \$p = dirname(__FILE__) . '/cms/extensions/wp-optimizer/modules/supporters/cache/object-cache.php';\n" .
            "    if (is_file(\$p)) { include_once(\$p); }\n" .
            "}\n",
            0
        );
    }

    public static function deactivate(): void
    {
        wp_cache_flush();
        wp_cache_close();

        Disk::delete(WP_CONTENT_DIR . DIRECTORY_SEPARATOR . "object-cache.php");
    }

    public static function flush($lifetime = false, $blog_id = ''): void
    {
        if ($lifetime) {
            return;
        }

        if ($blog_id) {
            wp_cache_flush_group($blog_id);
        }

        wp_cache_flush();
    }
}

