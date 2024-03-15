<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

use WPS\core\UtilEnv;

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