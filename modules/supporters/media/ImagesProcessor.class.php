<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
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
use WPS\core\Settings;
use WPS\core\UtilEnv;

class ImagesProcessor
{
    private static string $allowedMimeTypeRegex = "(jpe?g|jpe|p(jpe|n)g|gif|webp|bmp|xbm|wbmp|tiff?|heic)";

    private array $settings;

    private array $metadata = [];

    private function __construct($settings = array())
    {
        $this->settings = array_merge(
            [
                'use_imagick'          => true,
                'format'               => array(
                    'jpg'    => true,
                    'png'    => false,
                    'gif'    => true,
                    'webp'   => false,
                    'others' => false
                ),
                'quality'              => 80,
                'keep_exif'            => false,
                'convert_to_webp'      => true,
                'resize_larger_images' => false,
                'resize_width_px'      => 2560,
                'resize_height_px'     => 1440
            ],
            $settings
        );
    }

    public static function getInstance(array $settings = array()): ImagesProcessor
    {
        return new static($settings);
    }

    public static function remove($media_id, bool $unlink): void
    {
        $media = wps('wpopt')->options->get_by_id($media_id);

        if ($unlink and $media) {
            @unlink($media['obj_id']);
        }

        wps('wpopt')->options->remove_by_id($media_id);
    }

    public function find_orphaned_media()
    {
        $uploadDir = realpath(UtilEnv::wp_upload_dir('basedir') . '/');

        if (!$uploadDir) {
            return IPC_FAIL;
        }

        $restore_path = wps('wpopt')->options->get('path', 'orphaned_media_progress', 'media');
        // todo check if file still exist
        $restore_file = wps('wpopt')->options->get('file', 'orphaned_media_progress', 'media');

        $root = trailingslashit($restore_path ?: $uploadDir);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        $return = IPC_SUCCESS;

        $iter = 0;

        foreach ($iterator as $fileInfo) {

            if (UtilEnv::safe_time_limit(3, 60) === false or $this->do_pause("orphan-media-scanner")) {

                //saving progress
                wps('wpopt')->options->update(
                    'path',
                    'orphaned_media_progress',
                    $fileInfo->getPath(),
                    'media'
                );

                wps('wpopt')->options->update(
                    'file',
                    'orphaned_media_progress',
                    $fileInfo->getBasename(),
                    'media'
                );

                $return = IPC_TIME_LIMIT;
                break;
            }

            if ($restore_file and ($fileInfo->getBasename() !== $restore_file)) {
                continue;
            }

            $restore_file = false;

            if ($fileInfo->isFile() and $fileInfo->isWritable() and $this->allow_media_clean($fileInfo->getExtension())) {

                if ($iter++ > 10) {
                    die();
                }

                $res = wps_attachment_path_to_postid($fileInfo->getRealPath());

                if (!$res) {
                    wps('wpopt')->options->add(
                        $fileInfo->getPathname(),
                        'orphaned_media',
                        [
                            'size' => $fileInfo->getSize(),
                            'time' => $fileInfo->getCTime(),
                        ],
                        'media'
                    );
                }

                usleep(1000);
            }
        }

        return $return;
    }

    public function do_pause($context): bool
    {
        return wps('wpopt')->options->get("status", $context, "media", '', false) === 'paused';
    }

    public function scan_media()
    {
        global $wpdb;

        $scannedID = wps('wpopt')->options->get('last_scanned_postID', 'scan_media', 'media', 0);

        $return = IPC_SUCCESS;

        while (UtilEnv::safe_time_limit(5, 60) !== false) {

            $post_ids = $wpdb->get_col("SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND ID > $scannedID AND post_mime_type REGEXP '" . self::$allowedMimeTypeRegex . "' ORDER BY ID ASC LIMIT 20");

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

                // directly wipe related core cached items
                wps()->options->remove_by_container($post_id);
            }

            wps('wpopt')->options->update('last_scanned_postID', 'scan_media', $scannedID, 'media');
        }

        wps('wpopt')->options->update('last_scanned_postID', 'scan_media', $scannedID, 'media');

        return $return;
    }

    public function scan_post($attachment_id)
    {
        global $wpdb;

        $metadata = wp_get_attachment_metadata($attachment_id, true);

        if (!$metadata) {
            return IPC_MEDIA_NOT_FOUND;
        }

        if (UtilEnv::safe_time_limit(6, 60) === false) {
            return IPC_TIME_LIMIT;
        }

        // fix to handle corrupted metadata
        $file = $metadata['file'] ?: wps_get_post_meta('_wp_attached_file', '', $attachment_id);

        if (empty($file)) {
            return IPC_MEDIA_NOT_FOUND;
        }

        $sub_path = UtilEnv::normalize_path(pathinfo($file, PATHINFO_DIRNAME), true);

        $image_path_container = UtilEnv::normalize_path(UtilEnv::wp_upload_dir('basedir') . '/' . $sub_path, true);

        if ($this->optimize_image($image_path_container . basename($file), true) === IPC_SUCCESS) {

            $wpdb->update($wpdb->posts,
                [
                    'post_mime_type' => $this->get_metadata('mime-type'),
                    'guid'           => UtilEnv::path_to_url($this->get_metadata('file'), true),
                ],
                ['ID' => $attachment_id]
            );

            $metadata['file'] = $sub_path . basename($this->get_metadata('file'));
            $metadata['width'] = $this->get_metadata('width');
            $metadata['height'] = $this->get_metadata('height');
            $metadata['filesize'] = $this->get_metadata('filesize');

            update_post_meta($attachment_id, "_wp_attached_file", $metadata['file']);
        }

        if (isset($metadata['original_image'])) {

            if ($this->optimize_image($image_path_container . $metadata['original_image'], true) === IPC_SUCCESS) {

                $metadata['original_image'] = basename($this->get_metadata('file'));
            }
        }

        $allow_unlink_oversize_images = Settings::check($this->settings, 'resize_larger_images');

        if ($allow_unlink_oversize_images) {

            $allowed_width = Settings::get_option($this->settings, 'resize_width_px', 2560);
            $allowed_height = Settings::get_option($this->settings, 'resize_height_px', 1440);

            if (!is_numeric($allowed_width) or !is_numeric($allowed_height)) {
                $allow_unlink_oversize_images = false;
            }
        }

        foreach ($metadata['sizes'] as $size => $image) {

            if ($allow_unlink_oversize_images and str_contains($size, 'x')) {

                list($width, $height) = explode('x', $size, 2);

                if (is_numeric($width) and is_numeric($height)) {

                    if ($width > $allowed_width or $height > $allowed_height) {
                        unlink($image_path_container . $image['file']);
                        unset($metadata['sizes'][$size]);
                        continue;
                    }
                }
            }

            if ($this->optimize_image($image_path_container . $image['file'], true) === IPC_SUCCESS) {

                $new_metadata = $this->get_metadata('', []);
                $new_metadata['file'] = basename($new_metadata['file']);

                $metadata['sizes'][$size] = array_merge($image, $new_metadata);
            }
        }

        wp_update_attachment_metadata($attachment_id, $metadata);

        return IPC_SUCCESS;
    }

    public function optimize_image($image_path, $remove_converted = false, $save_processed = true)
    {
        $image_path = UtilEnv::normalize_path($image_path);

        if (UtilEnv::safe_time_limit(3, 60) === false) {
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

        if ($remove_converted) {
            unlink($image_path);
        }

        if ($save_processed) {
            wps('wpopt')->options->add($metadata['file'], 'optimized_images', $metadata['wpopt'], 'media');
        }

        unset($metadata['wpopt']);

        /**
         * make available optimization metadata
         */
        $this->metadata = $metadata;

        return IPC_SUCCESS;
    }

    private function allow_optimization($image_path)
    {
        if (wps('wpopt')->options->get($image_path, 'optimized_images', 'media', null) !== null) {
            return false;
        }

        switch (strtolower(pathinfo($image_path, PATHINFO_EXTENSION))) {

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

            $original_size = UtilEnv::filesize($image_path);

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

                if (is_numeric($width) and is_numeric($height)) {

                    if ($imagick->getImageWidth() > $width or $imagick->getImageHeight() > $height) {
                        $imagick->scaleImage($width, $height, true);
                    }
                }
            }

            //$imagick->resampleImage(50, 50, \Imagick::FILTER_LANCZOS, 1);

            if (Settings::check($this->settings, 'convert_to_webp')) {
                $imagick->setImageFormat('webp');

                if (pathinfo($image_path, PATHINFO_EXTENSION) !== 'webp') {
                    $wpopt['prev_ext'] = pathinfo($image_path, PATHINFO_EXTENSION);
                }

                $image_path = UtilEnv::change_file_extension($image_path, 'webp', true);
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

        clearstatcache(true, $image_path);

        $wpopt['prev_size'] = $original_size;
        $wpopt['size'] = UtilEnv::filesize($image_path);

        return ['width' => $width, 'height' => $height, 'mime-type' => $mimetype, 'file' => $image_path, 'filesize' => $wpopt['size'], 'wpopt' => $wpopt];
    }

    private function optimize_gd($image_path)
    {
        $wpopt = array();

        $original_size = UtilEnv::filesize($image_path);

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

            $image_path = UtilEnv::change_file_extension($image_path, 'webp', true);
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

        clearstatcache(true, $image_path);

        $wpopt['prev_size'] = $original_size;
        $wpopt['size'] = UtilEnv::filesize($image_path);

        return ['width' => $width, 'height' => $height, 'mime-type' => $mimetype, 'file' => $image_path, 'filesize' => $wpopt['size'], 'wpopt' => $wpopt];
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

    private function allow_media_clean($getExtension)
    {
        return match (strtolower($getExtension)) {
            'php', 'htaccess' => false,
            default => true,
        };
    }
}
