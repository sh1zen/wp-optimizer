<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

use WPS\core\Disk;

class DBCache extends Cache_Dispatcher
{
    public static function activate()
    {
        Disk::write(
            WP_CONTENT_DIR . DIRECTORY_SEPARATOR . "db.php",
            "<?php" . PHP_EOL . PHP_EOL . "include_once('" . WPOPT_SUPPORTERS . "cache/db.php');",
            0
        );
    }

    protected static function get_cache_group(): string
    {
        return "cache/db";
    }

    public static function deactivate()
    {
        Disk::delete(WP_CONTENT_DIR . DIRECTORY_SEPARATOR . "db.php");
        self::flush();
    }
}
