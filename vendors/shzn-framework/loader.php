<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

use SHZN\core\UtilEnv;
use SHZN\core\Utility;

const SHZN_VERSION = "2.0.0";
const SHZN_DEBUG = false;
const SHZN_SALT = "#0b1b#2a4d2ce76ee3ac41021dda6cd#";
const SHZN_FRAMEWORK = __DIR__ . '/';
const SHZN_DRIVERS_PATH = SHZN_FRAMEWORK . 'drivers/';
const SHZN_ADDON_PATH = SHZN_FRAMEWORK . 'addon/';

require_once SHZN_FRAMEWORK . 'environment/php_polyfill/loader.php';
require_once SHZN_FRAMEWORK . 'environment/wp_polyfill.php';

require_once SHZN_FRAMEWORK . 'functions.php';

require_once SHZN_FRAMEWORK . 'shzn_wrapper.php';

require_once SHZN_FRAMEWORK . 'Query.class.php';
require_once SHZN_FRAMEWORK . 'Actions.class.php';
require_once SHZN_FRAMEWORK . 'StringHelper.class.php';
require_once SHZN_FRAMEWORK . 'Utility.class.php';
require_once SHZN_FRAMEWORK . 'Rewriter.class.php';
require_once SHZN_FRAMEWORK . 'UtilEnv.php';

require_once SHZN_FRAMEWORK . 'Cache.class.php';
require_once SHZN_FRAMEWORK . 'Storage.class.php';
require_once SHZN_FRAMEWORK . 'Disk.class.php';
require_once SHZN_FRAMEWORK . 'Settings.class.php';
require_once SHZN_FRAMEWORK . 'Options.class.php';

require_once SHZN_FRAMEWORK . 'Ajax.class.php';
require_once SHZN_FRAMEWORK . 'Cron.class.php';

require_once SHZN_FRAMEWORK . 'Graphic.class.php';

require_once SHZN_FRAMEWORK . 'RuleUtil.php';
require_once SHZN_FRAMEWORK . 'PerformanceMeter.class.php';
require_once SHZN_FRAMEWORK . 'Module.class.php';
require_once SHZN_FRAMEWORK . 'ModuleHandler.class.php';

require_once SHZN_FRAMEWORK . 'functions/autoload.php';

add_action('admin_enqueue_scripts', 'shzn_admin_enqueue_scripts', 10, 0);

function shzn_admin_enqueue_scripts(): void
{
    $shzn_assets_url = UtilEnv::path_to_url(__DIR__);

    $min = (shzn_utils()->online and !SHZN_DEBUG) ? '.min' : '';

    wp_register_style('vendor-shzn-css', "{$shzn_assets_url}assets/css/style{$min}.css", [], SHZN_DEBUG ? time() : SHZN_VERSION);
    wp_register_script('vendor-shzn-js', "{$shzn_assets_url}assets/js/core{$min}.js", ['jquery'], SHZN_DEBUG ? time() : SHZN_VERSION);

    shzn_localize([
        'text_close_warning' => __('Are you sure you want to leave?', 'shzn')
    ]);
}

function shzn($context = 'common', $args = false, $components = [])
{
    static $cached = [];

    if (!is_string($context)) {
        $fn = shzn_debug_backtrace(2);
        trigger_error("SHZN Framework >> not valid context type in {$fn}.", E_USER_ERROR);
    }

    if ($args or !empty($components)) {

        if (!isset($cached[$context]) or !is_object($cached[$context])) {
            $cached[$context] = new \SHZN\core\shzn_wrapper($context, $args, $components);
            $cached[$context]->setup();
        }
        else {
            $cached[$context]->update_components($components, $args);
        }
    }
    elseif (!isset($cached[$context])) {
        $fn = shzn_debug_backtrace(2);
        trigger_error("SHZN Framework >> object $context not defined in {$fn}.", E_USER_WARNING);
        return false;
    }

    return $cached[$context];
}

/**
 * must be used only after init hook is fired
 */
function shzn_utils(): ?Utility
{
    return Utility::getInstance();
}