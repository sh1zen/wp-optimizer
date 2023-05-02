<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core;

class Disk
{
    private static $wp_filesystem;

    private static bool $suspended = false;

    public static function read($path)
    {
        // writing to system rules file, may be potentially write-protected
        if ($data = @file_get_contents($path))
            return $data;

        return self::wp_filesystem()->get_contents($path);
    }

    /**
     * sdes
     * @return \WP_Filesystem_Direct
     */
    private static function wp_filesystem()
    {
        if (!isset(self::$wp_filesystem)) {
            global $wp_filesystem;

            require_once(ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();

            self::$wp_filesystem = $wp_filesystem;
        }

        return self::$wp_filesystem;
    }

    public static function write($path, $data, $flag = FILE_APPEND)
    {
        if (self::$suspended)
            return false;

        if (!file_exists($path)) {
            self::make_path(dirname($path));
        }

        // writing to system rules file, may be potentially write-protected
        if (@file_put_contents($path, $data, $flag))
            return true;

        return self::wp_filesystem()->put_contents($path, $data);
    }

    public static function delete_files($target, $identifier = '')
    {
        self::suspend_cache();

        $target = realpath($target);

        if (is_dir($target)) {

            if (empty($identifier)) {
                /**
                 * get all folders/files (even the hidden ones)
                 * This will prevent listing "." or ".." in the result
                 */
                $identifier = '{,.}[!.,!..]*';
            }

            $target = trailingslashit($target);

            $files = glob($target . $identifier, GLOB_MARK | GLOB_NOSORT | GLOB_BRACE); //GLOB_MARK adds a slash to directories returned

            foreach ($files as $file) {
                self::delete_files($file);
            }

            rmdir($target);
        }
        elseif (is_file($target)) {
            unlink($target);
        }

        self::resume_cache();
    }

    public static function suspend_cache()
    {
        self::$suspended = true;
    }

    public static function resume_cache()
    {
        self::$suspended = false;
    }

    /**
     * @param $directory
     * @return false|int|mixed
     */
    public static function calc_size($directory)
    {
        $bytesTotal = 0;
        $path = realpath($directory);
        if ($path !== false && $path != '' && file_exists($path)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $object) {
                $bytesTotal += $object->getSize();
            }
        }
        return $bytesTotal;
    }

    public static function make_path($target, $private = false, $dir_perms = 0777)
    {
        global $is_IIS;

        if (!$target = UtilEnv::realpath($target)) {
            return false;
        }

        if (file_exists($target)) {
            $res = @is_dir($target) and @chmod($target, $dir_perms);
        }
        else {
            $res = @mkdir($target, $dir_perms, true);
        }

        if ($private) {
            $dir_perms = 0750;
        }

        if ($res) {

            /*
            * If an umask is set that modifies $dir_perms, we'll have to re-set
            * the $dir_perms correctly with chmod()
            */
            if (($dir_perms & ~umask()) != $dir_perms) {

                $target_parent = dirname($target);
                while ('.' !== $target_parent && !is_dir($target_parent) && dirname($target_parent) !== $target_parent) {
                    $target_parent = dirname($target_parent);
                }

                $folder_parts = explode('/', substr($target, strlen($target_parent) + 1));
                for ($i = 1, $c = count($folder_parts); $i <= $c; $i++) {
                    chmod($target_parent . '/' . implode('/', array_slice($folder_parts, 0, $i)), $dir_perms);
                }
            }

            if ($private and wp_is_writable($target)) {

                $plugin_path = __DIR__ . "/utils/";

                if ($is_IIS) {
                    if (!is_file($target . '/Web.config')) {
                        @copy($plugin_path . '/Web.config', $target . '/Web.config');
                    }
                }
                else {
                    if (!is_file($target . '/.htaccess')) {
                        @copy($plugin_path . '/.htaccess', $target . '/.htaccess');
                    }
                }
                if (!is_file($target . '/index.php')) {
                    file_put_contents($target . '/index.php', '<?php');
                }
            }
        }

        return $res;
    }

    public static function autocomplete($path = '')
    {
        $response = array();

        $abspath = UtilEnv::normalize_path(ABSPATH, true);

        $_search_path = UtilEnv::normalize_path($abspath . $path, true);
        $search_sub_path = false;

        while (!($search_path = realpath($_search_path))) {

            $_search_path = untrailingslashit($_search_path);

            $search_sub_path = substr($_search_path, strrpos($_search_path, '/') + 1);
            $_search_path = substr($_search_path, 0, strrpos($_search_path, '/'));
        }

        $search_path = UtilEnv::normalize_path($search_path, true);

        // to prevent go upper than ABSPATH
        if (!str_contains($search_path, $abspath)) {
            $search_path = $abspath;
        }

        $dir_list = scandir($search_path);

        foreach ($dir_list as $value) {

            if ($value === '.' or $value === '..')
                continue;

            if (is_dir($search_path . $value)) {

                if ($search_sub_path and !str_contains($value, $search_sub_path))
                    continue;

                $response[] = str_replace($abspath, '', $search_path . $value . '/');
            }
        }

        return $response;
    }
}
