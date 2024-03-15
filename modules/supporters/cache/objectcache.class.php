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
            "<?php" . PHP_EOL . PHP_EOL . "include_once('" . WPOPT_SUPPORTERS . "cache/object-cache.php');",
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

