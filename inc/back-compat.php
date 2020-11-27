<?php

if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax()
    {
        return apply_filters('wp_doing_ajax', defined('DOING_AJAX') && DOING_AJAX);
    }
}


if (!function_exists('wp_doing_cron')) {
    function wp_doing_cron()
    {
        return apply_filters('wp_doing_cron', defined('DOING_CRON') && DOING_CRON);
    }
}