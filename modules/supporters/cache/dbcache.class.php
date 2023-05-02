<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

use SHZN\core\Disk;

class DBCache extends Cache_Dispatcher
{
    public static function activate()
    {
        Disk::write(
            WP_CONTENT_DIR . DIRECTORY_SEPARATOR . "db.php",
            "<?php" . PHP_EOL . PHP_EOL . "include_once('" . WPOPT_SUPPORTERS . "cache/db.php');"
        );
    }

    protected static function get_cache_group()
    {
        return "cache/db";
    }

    public static function deactivate()
    {
        Disk::delete_files(WP_CONTENT_DIR . DIRECTORY_SEPARATOR . "db.php");
        self::clear_cache();
    }
}
