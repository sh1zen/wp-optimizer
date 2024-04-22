<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

use WPS\core\UtilEnv;

function wps_admin_enqueue_scripts(): void
{
    $wps_assets_url = UtilEnv::path_to_url(dirname(__DIR__));

    $min = (wps_core()->online and !wps_core()->debug) ? '.min' : '';

    wp_register_style('vendor-wps-css', "{$wps_assets_url}assets/css/style{$min}.css", [], wps_core()->debug ? time() : WPS_VERSION);
    wp_register_script('vendor-wps-js', "{$wps_assets_url}assets/js/core{$min}.js", ['jquery'], wps_core()->debug ? time() : WPS_VERSION);

    wps_localize([
        'text_close_warning' => __('Are you sure you want to leave?', 'wps')
    ]);
}


function wps_module_panel_url($module = '', $panel = ''): ?string
{
    return admin_url("admin.php?page=$module#$panel");
}

function wps_module_setting_url($context, $panel = ''): ?string
{
    return admin_url("admin.php?page=$context-modules-settings#settings-$panel");
}

function wps_run_upgrade($context, $version, $path): void
{
    $settings = wps($context)->settings->get('', ['ver' => '0.0.0']);

    UtilEnv::handle_upgrade($settings['ver'], $version, $path);

    $settings['ver'] = $version;

    wps($context)->settings->reset($settings);
}

function wps_maybe_upgrade($context, $new_version, $path): void
{
    $current_version = wps($context)->settings->get('ver', false);

    // need upgrade
    if (!$current_version or version_compare($current_version, $new_version, '<')) {

        wps_run_upgrade($context, $new_version, $path);

        wps_core()->is_upgrading(true);

        if (wps_loaded($context, 'moduleHandler')) {
            wps($context)->moduleHandler->upgrade();
        }
    }
}

function wps_localize($data = []): bool
{
    global $wp_scripts;

    if (empty($data) or !($wp_scripts instanceof WP_Scripts)) {
        return false;
    }

    if (wp_scripts()->query("vendor-wps-js", 'done')) {
        echo "<script type='text/javascript'>wps.locale.add(" . json_encode($data) . ")</script>";
    }
    else {
        return $wp_scripts->add_inline_script("vendor-wps-js", "wps.locale.add(" . json_encode($data) . ")", 'after');
    }

    return true;
}