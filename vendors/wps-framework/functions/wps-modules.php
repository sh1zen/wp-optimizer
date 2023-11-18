<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

use WPS\core\UtilEnv;

function wps_module_panel_url($module = '', $panel = ''): ?string
{
    return admin_url("admin.php?page={$module}#{$panel}");
}

function wps_module_setting_url($context, $panel = ''): ?string
{
    return admin_url("admin.php?page={$context}-modules-settings#settings-{$panel}");
}

function wps_run_upgrade($context, $version, $path): void
{
    $settings = wps($context)->settings->get('', ['ver' => '0.0.0']);

    UtilEnv::handle_upgrade($settings['ver'], $version, $path);

    $settings['ver'] = $version;

    wps($context)->settings->reset($settings);
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