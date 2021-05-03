<?php

namespace WPOptimizer\core;

class Disk
{
    private static $wp_filesystem;

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

    public static function write($path, $data)
    {
        // writing to system rules file, may be potentially write-protected
        if (@file_put_contents($path, $data))
            return true;

        return self::wp_filesystem()->put_contents($path, $data);
    }

    public static function count_files($path, $filters = array())
    {

    }

    public static function delete_files($target, $identifier = '')
    {
        $identifier .= '*';

        if (is_dir($target)) {
            $files = glob($target . $identifier, GLOB_MARK); //GLOB_MARK adds a slash to directories returned

            foreach ($files as $file) {
                self::delete_files($file);
            }

            rmdir($target);
        }
        elseif (is_file($target)) {
            unlink($target);
        }
    }

    /**
     * @param $directory
     * @return false|int|mixed
     */
    public static function calc_size($directory)
    {
        $totalSize = 0;
        $directoryArray = scandir($directory);

        foreach ($directoryArray as $key => $fileName) {
            if ($fileName != ".." and $fileName != ".") {
                if (is_dir($directory . "/" . $fileName)) {
                    $totalSize = $totalSize + self::calc_size($directory . "/" . $fileName);
                }
                else if (is_file($directory . "/" . $fileName)) {
                    $totalSize = $totalSize + filesize($directory . "/" . $fileName);
                }
            }
        }
        return $totalSize;
    }


    public static function make_path($path = WP_CONTENT_DIR . '/wpopt-data', $private = true)
    {
        global $is_IIS;

        $plugin_path = __DIR__;

        $path = realpath($path);

        if(!$path)
            return false;

        // Create Backup Folder
        $res = wp_mkdir_p($path);

        if ($private and is_dir($path) and wp_is_writable($path)) {

            if ($is_IIS) {
                // todo aggiungere i rispettivi file
                if (!is_file($path . '/Web.config')) {
                    copy($plugin_path . '/Web.config.txt', $path . '/Web.config');
                }
            }
            else {
                if (!is_file($path . '/.htaccess')) {
                    copy($plugin_path . '/htaccess.txt', $path . '/.htaccess');
                }
            }
            if (!is_file($path . '/index.php')) {
                file_put_contents($path . '/index.php', '<?php');
            }

            chmod($path, 0750);
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

        if (!$search_path)
            return $response;

        $search_path = UtilEnv::normalize_path($search_path, true);

        // to prevent go upper than ABSPATH
        if (strpos($search_path, $abspath) === false)
            $search_path = $abspath;

        $dir_list = scandir($search_path);

        foreach ($dir_list as $value) {

            if ($value === '.' or $value === '..')
                continue;

            if (is_dir($search_path . $value)) {

                if ($search_sub_path and strpos($value, $search_sub_path) === false)
                    continue;

                $response[] = str_replace($abspath, '', $search_path . $value . '/');
            }
        }

        return $response;
    }
}
