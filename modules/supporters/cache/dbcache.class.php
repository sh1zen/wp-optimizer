<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

use WPS\core\Disk;

class DBCache extends Cache_Dispatcher
{
    public static function activate($options = [])
    {
        if (is_bool($options)) {
            $options = array('disable_admin_cache' => $options);
        }

        $options = is_array($options) ? $options : array();
        $disable_admin_cache = !empty($options['disable_admin_cache']);
        $config = var_export($options, true);

        Disk::write(
            WP_CONTENT_DIR . DIRECTORY_SEPARATOR . "db.php",
            "<?php" . PHP_EOL . PHP_EOL .
            "defined('WPOPT_ADMIN_CACHE_DISABLED') || define('WPOPT_ADMIN_CACHE_DISABLED', " . ($disable_admin_cache ? 'true' : 'false') . ");" . PHP_EOL .
            "defined('WPOPT_DB_CACHE_CONFIG') || define('WPOPT_DB_CACHE_CONFIG', " . $config . ");" . PHP_EOL .
            "include_once('" . WPOPT_SUPPORTERS . "cache/db.php');",
            0
        );
    }

    protected static function get_cache_group(): string
    {
        return "cache/db";
    }

    public static function deactivate(): void
    {
        Disk::delete(WP_CONTENT_DIR . DIRECTORY_SEPARATOR . "db.php");
        self::flush();
    }
}
