<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

use WPS\core\Utility;
use WPS\core\wps_wrapper;

const WPS_FRAMEWORK = __DIR__ . '/';
const WPS_DRIVERS_PATH = WPS_FRAMEWORK . 'drivers/';
const WPS_ADDON_PATH = WPS_FRAMEWORK . 'addon/';

require_once WPS_FRAMEWORK . 'conf.php';

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
require_once WPS_FRAMEWORK . 'Images.php';

require_once WPS_FRAMEWORK . 'Cache.class.php';
require_once WPS_FRAMEWORK . 'Stack.php';
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

// make sure to be fired after all modules are loaded to prevent serialize of incomplete php object class
add_action('init', ['\WPS\core\CronActions', 'Initialize'], 10000);

function wps_loaded(string $context = 'wps', $module = null): bool
{
    $debug = wps_core()->debug;
    wps_core()->debug = false;

    $loaded = wps($context);
    if ($module and is_null($loaded->$module)) {
        $loaded = false;
    }

    wps_core()->debug = $debug;

    return $loaded != false;
}

function wps(string $context = 'wps', $args = false, $components = [])
{
    static $cached = [];

    if ($context == 'dump') {
        return array_keys($cached);
    }

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
function wps_core(): Utility
{
    return Utility::getInstance();
}

function wps_init(): void
{
    wps(
        'wps',
        [
            'table_name' => "wps_core",
        ],
        [
            'cache'    => true,
            'options'  => true,
            'settings' => true,
            'storage'  => true
        ]
    );

    wps_maybe_upgrade('wps', WPS_VERSION, __DIR__ . '/upgrades/');
}

function wps_uninstall(): bool
{
    global $wpdb;

    $uby = array_flip(wps('dump'));

    unset($uby['wps']);

    if (count(array_filter($uby)) <= 1) {
        $wpdb->query("DROP TABLE IF EXISTS " . wps('wps')->options->table_name());
        delete_option('wps');

        return true;
    }

    return false;
}

wps_init();