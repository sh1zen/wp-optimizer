<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

use WPS\core\UtilEnv;
use WPS\core\Utility;
use WPS\core\wps_wrapper;

/**
 * WordPress Speeder
 * 2.0.0
 */
const WPS_VERSION = "2.0.2";
const WPS_DEBUG = false;
const WPS_SALT = "#0b1b#2a4d2ce76ee3ac41021dda6cd#";
const WPS_FRAMEWORK = __DIR__ . '/';
const WPS_DRIVERS_PATH = WPS_FRAMEWORK . 'drivers/';
const WPS_ADDON_PATH = WPS_FRAMEWORK . 'addon/';

require_once WPS_FRAMEWORK . 'environment/php_polyfill/loader.php';
require_once WPS_FRAMEWORK . 'environment/wp_polyfill.php';

require_once WPS_FRAMEWORK . 'functions/autoload.php';

require_once WPS_FRAMEWORK . 'wps_wrapper.php';

require_once WPS_FRAMEWORK . 'Query.class.php';
require_once WPS_FRAMEWORK . 'RequestActions.class.php';
require_once WPS_FRAMEWORK . 'CronActions.class.php';
require_once WPS_FRAMEWORK . 'Ajax.class.php';
require_once WPS_FRAMEWORK . 'StringHelper.class.php';
require_once WPS_FRAMEWORK . 'TextReplacer.class.php';
require_once WPS_FRAMEWORK . 'Utility.class.php';
require_once WPS_FRAMEWORK . 'Rewriter.class.php';
require_once WPS_FRAMEWORK . 'UtilEnv.php';

require_once WPS_FRAMEWORK . 'Cache.class.php';
require_once WPS_FRAMEWORK . 'Storage.class.php';
require_once WPS_FRAMEWORK . 'Disk.class.php';
require_once WPS_FRAMEWORK . 'Settings.class.php';
require_once WPS_FRAMEWORK . 'Options.class.php';

require_once WPS_FRAMEWORK . 'CronForModules.php';

require_once WPS_FRAMEWORK . 'Graphic.class.php';

require_once WPS_FRAMEWORK . 'RuleUtil.php';
require_once WPS_FRAMEWORK . 'PerformanceMeter.class.php';
require_once WPS_FRAMEWORK . 'Module.class.php';
require_once WPS_FRAMEWORK . 'ModuleHandler.class.php';

add_action('admin_enqueue_scripts', 'wps_admin_enqueue_scripts', 10, 0);

add_action('init', ['\WPS\core\CronActions', 'Initialize']);

function wps_admin_enqueue_scripts(): void
{
    $wps_assets_url = UtilEnv::path_to_url(__DIR__);

    $min = (wps_utils()->online and !wps_utils()->debug) ? '.min' : '';

    wp_register_style('vendor-wps-css', "{$wps_assets_url}assets/css/style{$min}.css", [], wps_utils()->debug ? time() : WPS_VERSION);
    wp_register_script('vendor-wps-js', "{$wps_assets_url}assets/js/core{$min}.js", ['jquery'], wps_utils()->debug ? time() : WPS_VERSION);

    wps_localize([
        'text_close_warning' => __('Are you sure you want to leave?', 'wps')
    ]);
}

function wps_loaded(string $context = 'common'): bool
{
    $debug = wps_utils()->debug;
    wps_utils()->debug = false;
    $loaded = wps($context);
    wps_utils()->debug = $debug;

    return $loaded != false;
}

function wps(string $context = 'common', $args = false, $components = [])
{
    static $cached = [];

    if ($args or !empty($components)) {

        if (!isset($cached[$context]) or !is_object($cached[$context])) {
            $cached[$context] = new wps_wrapper($context, $args, $components);
            $cached[$context]->setup();
        }
        else {
            $cached[$context]->update_components($components, $args);
        }
    }
    elseif (!isset($cached[$context])) {
        wps_debug_log("WPS Framework >> object '$context' not defined");
        return false;
    }

    return $cached[$context];
}

/**
 * must be used only after init hook is fired
 */
function wps_utils(): Utility
{
    return Utility::getInstance();
}