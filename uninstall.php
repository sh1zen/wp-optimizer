<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

/**
 * Uninstall Procedure
 */

// Make sure that we are uninstalling
if (!defined('WP_UNINSTALL_PLUGIN'))
    exit();

// Leave no trail
$option_names = array('wpopt', 'wpopt.media.todo');

if (!is_multisite()) {
    foreach ($option_names as $option_name)
        delete_option($option_name);
}
else {
    global $wpdb;

    $blog_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
    $original_blog_id = get_current_blog_id();

    foreach ($blog_ids as $blog_id) {
        switch_to_blog($blog_id);
        foreach ($option_names as $option_name)
            delete_option($option_name);
    }
    switch_to_blog($original_blog_id);
}