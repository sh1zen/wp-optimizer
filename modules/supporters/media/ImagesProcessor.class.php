<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C)  2022
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

const IPC_FAIL = 0;
const IPC_SUCCESS = 1;
const IPC_ALREADY_PROCESSED = 2;
const IPC_SKIP = 3;
const IPC_MEDIA_NOT_FOUND = 4;
const IPC_TIME_LIMIT = 5;
const IPC_NOT_WRITABLE = 6;

require_once WPOPT_SUPPORTERS . '/media/GD.class.php';

use FilesystemIterator;
use SHZN\core\Settings;
use SHZN\core\UtilEnv;

class ImagesProcessor
{
    private static ImagesProcessor $_instance;

    private array $settings;

    private array $metadata;

    private function __construct($settings = array())
    {
        $this->settings = $settings;

        $this->metadata = [];
    }

    /**
     * @param array $settings
     * @return ImagesProcessor
     */
    public static function getInstance($settings = array())
    {
        if (!isset(self::$_instance)) {
            self::$_instance = new self($settings);
        }

        return self::$_instance;
    }

    public static function remove($media_id, bool $unlink)
    {
        $media = shzn('wpopt')->options->get_by_id($media_id);

        if ($unlink and $media) {
            @unlink($media['obj_id']);
        }

        shzn('wpopt')->options->remove_by_id($media_id);
    }

    public function find_orphaned_media()
    {
        global $wpdb;

        if (!($root = realpath(UtilEnv::wp_upload_dir('basedir') . '/'))) {
            return IPC_FAIL;
        }

        $item_count = 0;
        $last_scanned = shzn('wpopt')->options->get('last_scanned', 'find_orphaned_media', 'media', 0);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
            \RecursiveIteratorIterator::CATCH_GET_CHILD // Ignore "Permission denied"
        );

        $return = IPC_SUCCESS;

        foreach ($iterator as $fileInfo) {

            if ($this->do_pause("orphan-media-scanner")) {
                break;
            }

            if (!UtilEnv::safe_time_limit(3, 60)) {
                $return = IPC_TIME_LIMIT;
                break;
            }

            if ($item_count++ < $last_scanned) {
                continue;
            }

            if ($fileInfo->isFile()) {

                $image = $fileInfo->getBasename();

                $res = $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE guid LIKE '%{$image}%';");

                if (!$res) {
                    $res = $wpdb->get_var("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value LIKE '%{$image}%';");
                }

                if (!$res) {
                    shzn('wpopt')->options->add(
                        $fileInfo->getPathname(),
                        'orphaned_media',
                        [
                            'size' => $fileInfo->getSize(),
                            'time' => $fileInfo->getCTime(),
                        ],
                        'media',
                        MONTH_IN_SECONDS
                    );
                }
                usleep(1000);
            }
        }

        shzn('wpopt')->options->update('last_scanned', 'find_orphaned_media', $item_count, 'media', DAY_IN_SECONDS * 3);

        return $return;
    }

    public function do_pause($context)
    {
        return shzn('wpopt')->options->get("status", $context, "media", '', false) === 'paused';
    }

    public function scan_media()
    {
        global $wpdb;

        $scannedID = shzn('wpopt')->options->get('last_scanned_postID', 'scan_media', 'media', 0);

        $return = IPC_SUCCESS;

        while (UtilEnv::safe_time_limit(5, 60)) {

            $post_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > {$scannedID} ORDER BY ID ASC LIMIT 20");

            if (empty($post_ids)) {
                break;
            }

            foreach ($post_ids as $post_id) {

                if ($this->do_pause("optimization")) {
                    break 2;
                }

                $scannedID = $post_id;

                if ($this->scan_post($post_id) === IPC_TIME_LIMIT) {
                    $return = IPC_TIME_LIMIT;
                    break;
                }

                do_action('wpopt_delete_options_cache', $post_id);
            }

            shzn('wpopt')->options->update('last_scanned_postID', 'scan_media', $scannedID, 'media');
        }

        shzn('wpopt')->options->update('last_scanned_postID', 'scan_media', $scannedID, 'media');

        return $return;
    }

    public function scan_post($post_ID)
    {
        global $wpdb;

        $metadata = wp_get_attachment_metadata($post_ID, true);

        if (!$metadata) {
            return IPC_MEDIA_NOT_FOUND;
        }

        if (!UtilEnv::safe_time_limit(6, 60)) {
            return IPC_TIME_LIMIT;
        }

        $sub_path = UtilEnv::normalize_path(pathinfo($metadata['file'], PATHINFO_DIRNAME), true);
        $image_container_path = UtilEnv::normalize_path(UtilEnv::wp_upload_dir('basedir') . '/' . $sub_path, true);

        if (($this->optimize_image($image_container_path . basename($metadata['file']))) === IPC_SUCCESS) {

            if (basename($metadata['file']) !== $this->get_metadata('file')) {

                unlink($image_container_path . basename($metadata['file']));

                $wpdb->update($wpdb->posts,
                    [
                        'post_mime_type' => $this->get_metadata('mime-type'),
                        'guid'           => UtilEnv::path_to_url($image_container_path . $this->get_metadata('file'), true),
                    ],
                    ['ID' => $post_ID]
                );

                $metadata['file'] = $sub_path . $this->get_metadata('file');
                update_post_meta($post_ID, "_wp_attached_file", $metadata['file']);
            }

            $metadata['width'] = $this->get_metadata('width');
            $metadata['height'] = $this->get_metadata('height');
        }

        if (isset($metadata['original_image'])) {

            if (($this->optimize_image($image_container_path . $metadata['original_image'])) === IPC_SUCCESS) {

                if ($metadata['original_image'] !== $this->get_metadata('file')) {
                    unlink($image_container_path . $metadata['original_image']);
                    $metadata['original_image'] = $this->get_metadata('file');
                }
            }
        }

        foreach ($metadata['sizes'] as $key => $image) {

            /**
             * Run image optimization
             */
            if (($this->optimize_image($image_container_path . $image['file'])) === IPC_SUCCESS) {

                if ($metadata['file'] !== $this->get_metadata('file')) {
                    unlink($image_container_path . $image['file']);
                }

                $new_metadata = $this->get_metadata();

                $metadata['sizes'][$key] = array_merge($image, $new_metadata);
            }
        }

        wp_update_attachment_metadata($post_ID, $metadata);

        return IPC_SUCCESS;
    }

    public function optimize_image($image_path, $remove_converted = false, $save_processed = true)
    {
        $image_path = UtilEnv::normalize_path($image_path);

        if (!UtilEnv::safe_time_limit(3, 60)) {
            return IPC_TIME_LIMIT;
        }

        if (!is_writable($image_path)) {
            return IPC_NOT_WRITABLE;
        }

        if (!$this->allow_optimization($image_path)) {
            return IPC_SKIP;
        }

        $metadata = false;

        if (extension_loaded('imagick') and Settings::check($this->settings, 'use_imagick')) {
            $metadata = $this->optimize_imagick($image_path);
        }
        elseif (extension_loaded('gd') or extension_loaded('gd2')) {
            $metadata = $this->optimize_gd($image_path);
        }

        if (!$metadata) {
            return IPC_FAIL;
        }

        if ($remove_converted and (isset($metadata['wpopt']['prev_ext']))) {
            unlink($image_path);
        }

        if ($save_processed) {
            shzn('wpopt')->options->add($metadata['file'], 'optimized_images', $metadata['wpopt'], 'media');
        }

        unset($metadata['wpopt']);

        $metadata['file'] = basename($metadata['file']);

        /**
         * make available optimization metadata
         */
        $this->metadata = $metadata;

        return IPC_SUCCESS;
    }

    private function allow_optimization($image_path)
    {
        if (shzn('wpopt')->options->get($image_path, 'optimized_images', 'media', null) !== null) {
            return false;
        }

        switch (pathinfo($image_path, PATHINFO_EXTENSION)) {

            case 'jpg':
            case 'jpeg':
            case 'pjpeg':
            case 'jpe':
                return Settings::check($this->settings, 'format.jpg');

            case 'gif':
                return Settings::check($this->settings, 'format.gif');

            case 'png':
                return Settings::check($this->settings, 'format.png');

            case 'webp':
                return Settings::check($this->settings, 'format.webp');

            case 'bmp':
            case 'xbm':
            case 'wbmp':
            case 'tif':
            case 'tiff':
            case 'heic':
                return Settings::check($this->settings, 'format.others');
        }

        return false;
    }

    private function optimize_imagick($image_path)
    {
        $wpopt = array();

        try {

            $original_size = filesize($image_path);

            $imagick = new \Imagick($image_path);

            if (!Settings::check($this->settings, 'keep_exif')) {

                $profiles = $imagick->getImageProfiles("icc", true);

                $imagick->stripImage();

                if (!empty($profiles)) {
                    $imagick->profileImage("icc", $profiles['icc']);
                }
            }

            if (Settings::check($this->settings, 'resize_larger_images')) {

                $width = Settings::get_option($this->settings, 'resize_width_px', 2560);
                $height = Settings::get_option($this->settings, 'resize_height_px', 1440);

                if ($imagick->getImageWidth() > $width or $imagick->getImageHeight() > $height) {
                    $imagick->scaleImage($width, $height, true);
                }
            }

            //$imagick->resampleImage(50, 50, \Imagick::FILTER_LANCZOS, 1);

            if (Settings::check($this->settings, 'convert_to_webp')) {
                $imagick->setImageFormat('webp');

                if (pathinfo($image_path, PATHINFO_EXTENSION) !== 'webp') {
                    $wpopt['prev_ext'] = pathinfo($image_path, PATHINFO_EXTENSION);
                }

                $image_path = UtilEnv::change_file_extension($image_path, 'webp');
            }

            $quality = Settings::check($this->settings, 'quality', 80);

            if (!$quality) {
                $quality = 80;
            }

            $imagick->setImageCompressionQuality($quality);

            $imagick->writeImage($image_path);

            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();
            $mimetype = $imagick->getImageMimeType();

            $imagick->clear();
            $imagick->destroy();

        } catch (\Exception $e) {
            return false;
        }

        clearstatcache();

        $wpopt['prev_size'] = $original_size;
        $wpopt['size'] = filesize($image_path);

        return ['width' => $width, 'height' => $height, 'mime-type' => $mimetype, 'file' => $image_path, 'wpopt' => $wpopt];
    }

    private function optimize_gd($image_path)
    {
        $wpopt = array();

        $original_size = filesize($image_path);

        $imageGD = new GD($image_path);

        if (!Settings::check($this->settings, 'keep_exif')) {
            $imageGD->stripImage();
        }

        if (Settings::check($this->settings, 'resize_larger_images')) {
            $imageGD->scaleImage(
                Settings::get_option($this->settings, 'resize_width_px', 2560),
                Settings::get_option($this->settings, 'resize_height_px', 1440),
                true
            );
        }

        if (Settings::check($this->settings, 'convert_to_webp')) {
            $imageGD->setImageFormat('webp');

            if (pathinfo($image_path, PATHINFO_EXTENSION) !== 'webp') {
                $wpopt['prev_ext'] = pathinfo($image_path, PATHINFO_EXTENSION);
            }

            $image_path = UtilEnv::change_file_extension($image_path, 'webp');
        }

        $quality = Settings::check($this->settings, 'quality', 80);

        if (!$quality) {
            $quality = 80;
        }

        $imageGD->setImageCompressionQuality($quality);

        $width = $imageGD->width;
        $height = $imageGD->height;
        $mimetype = $imageGD->getImageMimeType();

        if ($width > 0 and $height > 0 and !$imageGD->writeImage($image_path)) {
            return false;
        }

        clearstatcache();

        $wpopt['prev_size'] = $original_size;
        $wpopt['size'] = filesize($image_path);

        return ['width' => $width, 'height' => $height, 'mime-type' => $mimetype, 'file' => $image_path, 'wpopt' => $wpopt];
    }

    public function get_metadata($filter = '', $default = '')
    {
        if (empty($filter)) {
            return $this->metadata;
        }

        return $this->metadata[$filter] ?? $default;
    }

    public function scan_dir($path = '')
    {
        $root = realpath(ABSPATH . '/' . $path . '/');

        if (!$root) {
            return IPC_FAIL;
        }

        // Going through directory recursively
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
            \RecursiveIteratorIterator::CATCH_GET_CHILD // Ignore "Permission denied"
        );

        foreach ($iter as $file_info) {

            if ($this->do_pause("optimization")) {
                break;
            }

            if (!$file_info->isFile() or !$file_info->isWritable()) {
                continue;
            }

            if ($this->optimize_image($file_info->getPathname(), true) === IPC_TIME_LIMIT) {
                return IPC_TIME_LIMIT;
            }
        }

        return IPC_SUCCESS;
    }
}
