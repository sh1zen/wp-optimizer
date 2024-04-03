<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

use WPS\core\Images;
use WPS\core\Query;
use WPS\core\UtilEnv;

function wps_get_images($attachments, $size = 'thumbnail'): array
{
    return Images::get_images($attachments, $size);
}

function wps_get_image($attachment, $size = 'thumbnail')
{
    return Images::get_image($attachment, $size);
}

function wps_get_mainImageURL($post = null, $size = 'large', $useContent = false): string
{
    return wps_get_mainImage($post, $size, $useContent)[1];
}

function wps_get_mainImage($post = null, $size = 'large', $useContent = false): array
{
    if (is_array($size)) {

        foreach ($size as $_size) {
            $mainImage = wps_get_mainImage($post, $_size, $useContent);

            if ($mainImage and !empty($mainImage[1])) {
                return $mainImage;
            }
        }

        return [0, ''];
    }

    $post = wps_get_post($post);

    if ($mainImage = wps()->options->get($post->ID, "mainImage-$size", "cache", false)) {
        return $mainImage;
    }

    $mediaURL = '';
    $mediaID = get_post_thumbnail_id($post);

    if (!$mediaID) {

        $media_query = Query::getInstance(ARRAY_A);

        $mediaID = $media_query->tables($media_query->wpdb()->posts)->where([
            'post_type'      => 'attachment',
            'post_parent'    => $post->ID,
            ['post_mime_type' => 'image/', 'compare' => 'LIKE']
        ])->select('ID')->orderby('menu_order')->limit(1)->query_one() ?: 0;
    }

    if ($mediaID) {

        $image = Images::get_image($mediaID, $size);

        $mediaURL = $image ? $image['url'] : '';
    }
    elseif ($useContent) {

        $images = Images::get_images_from_content($post->post_content);

        if ($images) {
            $mediaURL = Images::removeImageDimensions($images[0]);
        }
    }

    wps()->options->add($post->ID, "mainImage-$size", [$mediaID, $mediaURL], "cache", MONTH_IN_SECONDS);

    return [$mediaID, $mediaURL];
}

function wps_get_snippet_data($url, $size = 'large')
{
    $snippet_data = wps()->options->get($url, "snippet_data", "cache", false);

    if (!$snippet_data) {

        $snippet_data = Images::get_snippet_data($url, $size);

        if (!$snippet_data) {
            return false;
        }

        wps()->options->add($url, "snippet_data", $snippet_data, "cache", WEEK_IN_SECONDS);
    }

    return $snippet_data;
}

function wps_attachment_url_to_postid($url)
{
    global $wpdb;

    $id = apply_filters('attachment_url_to_postid', null, $url);

    if (!is_null($id)) {
        return $id;
    }

    if (!UtilEnv::is_this_site($url)) {
        return 0;
    }

    $site_url = parse_url(UtilEnv::wp_upload_dir('baseurl'));
    $parsed_url = parse_url($url);

    $basename = preg_replace("#(.*)(-\d+x\d+)\.([a-z]{1,5})$#", '$1.$3', str_replace($site_url['path'] . '/', '', $parsed_url['path']));

    $sql = $wpdb->prepare(
        "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
        $basename
    );

    return $wpdb->get_var($sql) ?: 0;
}

function wps_attachment_path_to_postid($filepath)
{
    global $wpdb;

    $id = apply_filters('attachment_path_to_postid', null, $filepath);

    if (!is_null($id)) {
        return $id;
    }

    $filepath = str_replace(
        UtilEnv::normalize_path(UtilEnv::wp_upload_dir('basedir')),
        '',
        UtilEnv::normalize_path($filepath)
    );

    $basename = preg_replace('#(.*)(-\d+x\d+)\.([a-z]{1,5})$#', '$1.$3', $filepath);

    if (str_starts_with($basename, '/')) {
        $basename = substr($basename, 1);
    }

    $sql = $wpdb->prepare(
        "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
        $basename
    );

    return $wpdb->get_var($sql) ?: 0;
}