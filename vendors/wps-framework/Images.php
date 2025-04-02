<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

/**
 * Image_Utils.
 */
class Images
{
    /**
     * @param string $url The attachment URL for which we want to know the Post ID.
     * @return int The Post ID belonging to the attachment, 0 if not found.
     */
    public static function attachmentUrlToPostId(string $url): int
    {
        if (is_numeric($url)) {
            return intval($url);
        }

        $id = wps()->options->get($url, 'attachmentUrlToPostId', 'cache');

        if ($id === 'not_found') {
            return 0;
        }

        // ID is found in cache, return.
        if ($id !== false) {
            return $id;
        }

        $id = wps_attachment_url_to_postid($url);

        if (empty($id)) {
            wps()->options->update($url, 'attachmentUrlToPostId', 'not_found', 'cache', WEEK_IN_SECONDS);
            return 0;
        }

        // We have the Post ID, but it's not in the cache yet.
        wps()->options->update($url, 'attachmentUrlToPostId', $id, 'cache', WEEK_IN_SECONDS);

        return $id;
    }

    public static function getSiteLogoId()
    {
        if (!get_theme_support('custom-logo')) {
            return [];
        }

        return get_theme_mod('custom_logo');
    }

    /**
     * Returns the different image variations for consideration.
     *
     * @param int|string $attachment_id The attachment to return the variations for.
     *
     * @return array The different variations possible for this attachment ID.
     */
    public static function get_variations($attachment_id): array
    {
        $variations = [];

        foreach (static::get_sizes() as $size) {
            $variation = self::get_image($attachment_id, $size);

            // The get_image function returns false if the size doesn't exist for this attachment.
            if ($variation) {
                $variations[] = $variation;
            }
        }

        return $variations;
    }

    /**
     * Retrieve the internal WP image file sizes.
     */
    private static function get_sizes(): array
    {
        return apply_filters('wps_image_sizes', ['thumbnail', 'medium', 'medium_large', 'large', 'full']);
    }

    /**
     * Find the right version of an image based on size.
     *
     * @param int|string $attachment Attachment ID.
     * @param string|array $size Size name.
     *
     * @return false|array $image
     *
     * @type string $id Image's id.
     * @type string $alt Image's alt text.
     * @type string $caption Image's caption text.
     * @type string $description Image's description text.
     * @type string $path Path of image.
     * @type string $file SubPath of image.
     * @type int $width Width of image.
     * @type int $height Height of image.
     * @type string $type Image's MIME type.
     * @type string $size Image's size.
     * @type array $meta Image's meta.
     * @type string $url Image's URL.
     * @type int $filesize The file size in bytes, if already set.
     */
    public static function get_image($attachment, $size = 'full', $allowExternal = true)
    {
        if (empty($attachment)) {
            return false;
        }

        if (is_array($size)) {

            foreach ($size as $_size) {
                if ($image = self::get_image($attachment, $_size, $allowExternal)) {
                    return $image;
                }
            }

            return false;
        }

        $is_url = UtilEnv::is_url($attachment);

        if (!$is_url) {

            $attachment = wps_get_post($attachment);

            if (!$attachment) {
                return false;
            }
        }

        $attachment_id = $is_url ? 0 : $attachment->ID;

        $cacheKey = Cache::generate_key($is_url ? $attachment : $attachment_id, $size);

        $image = wps()->options->get($cacheKey, 'schema.images.get_image', 'cache');

        if ($image !== false) {
            return $image;
        }

        $external = false;

        if ($is_url) {
            $file_url = $attachment;
            $external = true;

            list($width, $height) = getimagesize($file_url);

            $metadata = [
                'file'       => '',
                'width'      => $width,
                'height'     => $height,
                'image_meta' => []
            ];
        }
        else {

            $metadata = get_post_meta($attachment_id, '_wp_attachment_metadata', true);

            if ($metadata) {

                $file = $metadata['file'] ?: get_post_meta($attachment_id, '_wp_attached_file', true);

                if (str_starts_with($file, UtilEnv::wp_upload_dir('basedir'))) {
                    // Replace file location with url location.
                    $file_url = str_replace(UtilEnv::wp_upload_dir('basedir'), UtilEnv::wp_upload_dir('baseurl'), $file);
                }
                elseif (str_contains($file, 'wp-content/uploads')) {
                    // Get the directory name relative to the basedir (back compat for pre-2.7 uploads).
                    $file_url = trailingslashit(UtilEnv::wp_upload_dir('baseurl') . '/' . _wp_get_attachment_relative_path($file)) . wp_basename($file);
                }
                else {
                    // It's a newly-uploaded file, therefore $file is relative to the basedir.
                    $file_url = UtilEnv::wp_upload_dir('baseurl') . "/$file";
                }

                if (!$file_url) {
                    $file_url = $attachment->guid;
                }
            }
            else {
                $file_url = $attachment->guid;
                $external = true;

                if (UtilEnv::is_this_site($file_url)) {

                    if (UtilEnv::url_to_path($file_url)) {
                        list($width, $height) = getimagesize($file_url) ?: [0, 0];
                    }
                    else {
                        $width = 0;
                        $height = 0;
                    }
                }
                else {
                    list($width, $height) = wps_core()->online ? getimagesize($file_url) : [0, 0];
                }

                $metadata = [
                    'file'       => '',
                    'width'      => $width,
                    'height'     => $height,
                    'image_meta' => []
                ];
            }
        }

        if ($external and !$allowExternal) {
            return false;
        }

        $image = [
            'id'          => $attachment_id,
            'alt'         => $is_url ? '' : self::get_alt_tag($attachment_id),
            'caption'     => $is_url ? '' : $attachment->post_excerpt,
            'description' => $is_url ? '' : $attachment->post_content,
            'size'        => $size,
            'url'         => $file_url,
            'file'        => $metadata['file'],
            'width'       => $metadata['width'],
            'height'      => $metadata['height'],
            'meta'        => $metadata['image_meta']
        ];

        if ($size === 'full') {
            $image['path'] = get_attached_file($attachment_id, true);
            $image['type'] = get_post_mime_type($attachment_id);
        }
        elseif (isset($metadata['sizes'])) {

            if (empty($metadata['sizes'][$size])) {
                return false;
            }

            $image['path'] = path_join(dirname($metadata['file']), $metadata['sizes'][$size]['file']);
            $image['url'] = path_join(dirname($file_url), $metadata['sizes'][$size]['file']);

            $image['file'] = $metadata['sizes'][$size]['file'];
            $image['width'] = $metadata['sizes'][$size]['width'];
            $image['height'] = $metadata['sizes'][$size]['height'];
            $image['type'] = $metadata['sizes'][$size]['mime-type'];
        }

        // Deals with non-set keys and values being null or false.
        if (wps_core()->online and (empty($image['width']) or empty($image['height']))) {
            return false;
        }

        $image['pixels'] = ((int)$image['width'] * (int)$image['height']);
        $image['filesize'] = $external ? 0 : UtilEnv::filesize(UtilEnv::url_to_path($image['url']));

        wps()->options->update($cacheKey, 'schema.images.get_image', $image, 'cache', MONTH_IN_SECONDS, $attachment_id);

        return $image;
    }

    /**
     * Grabs an image alt text.
     *
     * @param int $attachment_id The attachment ID.
     *
     * @return string The image alt text.
     */
    private static function get_alt_tag(int $attachment_id): string
    {
        return (string)get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
    }

    public static function get_images($attachments, $size = 'full', $allowExternal = true): array
    {
        if (empty($attachments)) {
            return [];
        }

        if (is_array($size)) {

            foreach ($size as $_size) {

                $image = self::get_images($attachments, $_size, $allowExternal);

                if (!empty($image)) {
                    return $image;
                }
            }

            return [];
        }

        $cacheKeys = [];
        $images = [];

        foreach ($attachments as $attachment) {

            $is_url = UtilEnv::is_url($attachment);

            if ($is_url) {
                $attachment_id = $attachment;
            }
            else {
                $attachment = wps_get_post($attachment);

                if (!$attachment) {
                    continue;
                }

                $attachment_id = $attachment->ID;
            }

            $cacheKeys[Cache::generate_key($attachment_id, $size)] = $attachment;
        }

        $cached_images = wps()->options->get_list(array_keys($cacheKeys), 'schema.images.get_image', 'cache');

        foreach ($cacheKeys as $cache_key => $attachment) {
            $images[$cache_key] = $cached_images[$cache_key] ?? self::get_image($attachment, $size, $allowExternal);
        }

        // fixing bug of images with array of false
        return array_filter($images);
    }

    /**
     * Grabs the images from the content.
     *
     * @param string $content The post content string.
     *
     * @return array An array of image URLs.
     */
    public static function get_images_from_content(string $content): array
    {
        $content_images = self::get_img_tags_from_content($content);
        $images = array_map([self::class, 'get_img_tag_source'], $content_images);
        $images = array_filter(array_unique($images));

        // Reset the array keys.
        return array_values($images);
    }

    /**
     * Gets the image tags from a given content string.
     *
     * @param string $content The content to search for image tags.
     * @return array An array of `<img>` tags.
     */
    private static function get_img_tags_from_content(string $content): array
    {
        if (!str_contains($content, '<img')) {
            return [];
        }

        preg_match_all('#<img[^>]+src="([^">]+)"#', $content, $matches);

        if (isset($matches[1])) {
            return $matches[1];
        }

        return [];
    }

    /**
     * Removes image dimensions from the slug of a URL.
     *
     * @param string $url The image URL.
     * @return string      The formatted image URL.
     *
     */
    public static function removeImageDimensions(string $url): string
    {
        return preg_replace('#(.*)(-\d+x\d+)\.([a-z]{1,5})$#', '$1.$3', $url);
    }

    public static function get_user_snippet_image($userID, $size)
    {
        $url = get_avatar_url($userID);

        if (empty($url)) {
            $url = "https://secure.gravatar.com/avatar/cb5febbf69fa9e85698bac992b2a4433?s=500&d=mm&r=g";
        }

        $url = apply_filters("wps_author_avatar", $url, $userID);

        $snippet_data = wps()->options->get($url, "snippet_data", "cache", false);

        if (!$snippet_data) {

            $snippet_data = self::get_snippet_data($url, $size);

            wps()->options->add($url, "snippet_data", $snippet_data, "cache", WEEK_IN_SECONDS);
        }

        return $snippet_data;
    }

    /**
     * @param int|string $object wp_attachment_id, path, url
     * @param string $size
     * @return array|false
     */
    public static function get_snippet_data($object, $size = 'thumbnail')
    {
        $width = 0;
        $height = 0;

        $snippet_data = false;

        if (is_numeric($object)) {

            if ($image_data = wp_get_attachment_image_src($object, $size)) {

                $snippet_data = ['url' => $image_data[0], 'width' => $image_data[1], 'height' => $image_data[2]];
            }
        }
        else {

            if (UtilEnv::is_url($object)) {
                $image_path = UtilEnv::url_to_path($object);
            }
            else {
                $image_path = UtilEnv::normalize_path($object);
            }

            if ($image_path) {
                list($width, $height) = wp_getimagesize($image_path);
            }

            $snippet_data = ['url' => $object, 'width' => $width, 'height' => $height];
        }

        return $snippet_data;
    }

    /**
     * Retrieves the image URL from an image tag.
     *
     * @param string $image Image HTML element.
     *
     * @return string|bool The image URL on success, false on failure.
     */
    private static function get_img_tag_source($image)
    {
        preg_match('#src=(["\'])(.*?)\1#', $image, $matches);
        if (isset($matches[2])) {
            return $matches[2];
        }
        return false;
    }
}
