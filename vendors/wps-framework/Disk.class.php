<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

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

    public static function write($path, $data, $flag = FILE_APPEND): bool
    {
        $container = dirname($path);

        if (self::is_suspended(UtilEnv::realpath($container, true, true)))
            return false;

        if (!file_exists($container)) {
            self::make_path($container);
        }

        // writing to system rules file, may be potentially write-protected
        if (@file_put_contents($path, $data, $flag))
            return true;

        return self::wp_filesystem()->put_contents($path, $data);
    }

    private static function is_suspended($dir): bool
    {
        return file_exists($dir . md5($dir) . '.lock');
    }

    public static function update_path($target, $private = false, $dir_perms = false): bool
    {
        global $is_IIS;

        if (!$target = UtilEnv::realpath($target)) {
            return false;
        }

        if ($private and !$dir_perms) {
            $dir_perms = 0750;
        }

        if (!$dir_perms) {
            $dir_perms = 0777;
        }

        if (file_exists($target)) {
            $res = @is_dir($target) and @chmod($target, $dir_perms);
        }
        else {
            $res = @mkdir($target, $dir_perms, true);
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

            if ($private and is_writable($target)) {

                $plugin_path = __DIR__ . "/utils/";

                if ($is_IIS) {
                    if (!file_exists($target . '/Web.config')) {
                        @copy($plugin_path . '/Web.config', $target . '/Web.config');
                    }
                }
                else {
                    if (!file_exists($target . '/.htaccess')) {
                        @copy($plugin_path . '/.htaccess', $target . '/.htaccess');
                    }
                }

                if (!file_exists($target . '/index.php')) {
                    @file_put_contents($target . '/index.php', '<?php');
                }
            }
        }

        return $res;
    }


    public static function make_path($target, $private = false, $dir_perms = false): bool
    {
        if (!$target = UtilEnv::realpath($target)) {
            return false;
        }

        if (!file_exists($target) or $private or $dir_perms) {
            return self::update_path($target, $private, $dir_perms);
        }

        return true;
    }

    public static function delete($target, $lifetime = 0, $identifier = ''): int
    {
        $target = UtilEnv::realpath($target, true);

        if (!$target) {
            return 0;
        }

        if (is_file($target)) {
            @unlink($target);
            return 1;
        }

        $target = trailingslashit($target);

        self::suspend_write($target);

        $deleted = self::deleter($target, $lifetime, true, $identifier);

        self::resume_write($target);

        if (!$identifier and !$lifetime) {
            @rmdir($target);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Expects that $dir is real and normalized
     */
    private static function suspend_write($dir): void
    {
        (bool)@file_put_contents($dir . md5($dir) . '.lock', time(), 0);
    }

    private static function deleter(string $path, $lifetime = 0, $recursive = false, $identifier = false): int
    {
        if (!$handle = @opendir($path)) {
            return 0;
        }

        UtilEnv::rise_time_limit(0);

        $deleted = 0;

        $time = time();

        while (false !== ($filename = readdir($handle))) {

            if ($filename == '.' or $filename == '..') {
                continue;
            }

            $file_path = $path . DIRECTORY_SEPARATOR . $filename;

            if (is_dir($file_path)) {
                if ($recursive) {
                    $deleted += self::deleter($file_path, $lifetime, $recursive, $identifier);

                    if (!$identifier and !$lifetime) {
                        @rmdir($path);
                        $deleted++;
                    }
                }
            }
            else {

                if (!empty($identifier) and $identifier !== '*' and !preg_match("#$identifier#", $filename)) {
                    continue;
                }

                if ($lifetime) {
                    if (@filemtime($file_path) < $time - $lifetime) {
                        @unlink($file_path);
                        $deleted++;
                    }
                }
                else {
                    @unlink($file_path);
                    $deleted++;
                }
            }
        }

        closedir($handle);

        return $deleted;
    }

    private static function resume_write($dir)
    {
        return @unlink($dir . md5($dir) . '.lock');
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

    public static function remove_old_file($dir, $max_age = 60, $recursive = false): void
    {
        static $now_time;

        if (!$now_time) {
            $now_time = time();
        }

        $limit = $now_time - $max_age;

        $dir = realpath($dir);

        if (!is_dir($dir)) {
            return;
        }

        if (($dh = opendir($dir)) === false) {
            return;
        }

        while (($file = readdir($dh)) !== false) {

            $file = $dir . '/' . $file;

            UtilEnv::safe_time_limit(10, 240);

            if (!is_file($file)) {
                if ($recursive and is_dir($file)) {
                    self::remove_old_file($file, $max_age, $recursive);
                }
                continue;
            }

            if (filemtime($file) < $limit) {
                unlink($file);
            }
        }
        closedir($dh);
    }
}
