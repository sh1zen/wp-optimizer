<?php

return array(
    'directory' => '../modules',
    'namespace' => 'WPOptimizer\\modules',
    'modules'   => array(
        'activitylog'         => array('name' => 'Activity Log', 'scopes' => array('autoload', 'admin-page', 'settings')),
        'cache'               => array('name' => 'Cache', 'scopes' => array('settings', 'autoload', 'ajax')),
        'cloudflare'          => array('name' => 'Cloudflare', 'scopes' => array('core-settings', 'admin', 'ajax')),
        'cron'                => array('name' => 'Cron Manager', 'scopes' => array('core-settings', 'admin-page', 'cron')),
        'database'            => array('name' => 'Database Manager', 'scopes' => array('admin-page', 'cron', 'settings')),
        'media'               => array('name' => 'Media Optimizer', 'scopes' => array('autoload', 'cron', 'admin-page', 'settings')),
        'minify'              => array('name' => 'Minify', 'scopes' => array('settings', 'autoload')),
        'modules_handler'     => array('name' => 'Modules Handler', 'scopes' => array('core-settings')),
        'pagespeed'           => array('name' => 'PageSpeed', 'scopes' => array('autoload', 'settings')),
        'performance_monitor' => array('name' => 'Performance Monitor', 'scopes' => array('autoload', 'admin-page', 'settings')),
        'settings'            => array('name' => 'Settings', 'scopes' => array('core-settings', 'admin', 'ajax')),
        'tracking'            => array('name' => 'Tracking', 'scopes' => array('core-settings')),
        'widget'              => array('name' => 'Widget', 'scopes' => array('settings', 'admin')),
        'wp_customizer'       => array('name' => 'Wp Customizer', 'scopes' => array('settings', 'autoload')),
        'wp_info'             => array('name' => 'System Info', 'scopes' => array('admin-page')),
        'wp_mail'             => array('name' => 'WP Mail', 'scopes' => array('settings', 'autoload', 'admin-page')),
        'wp_optimizer'        => array('name' => 'Wp Optimizer', 'scopes' => array('settings', 'autoload')),
        'wp_security'         => array('name' => 'Wp Security', 'scopes' => array('settings', 'autoload')),
        'wp_updates'          => array('name' => 'Wp Updates', 'scopes' => array('settings', 'autoload')),
    ),
);
