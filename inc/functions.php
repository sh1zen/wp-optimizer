<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

use WPS\core\CronActions;
use WPOptimizer\core\PluginInit;
use WPOptimizer\modules\supporters\ImagesProcessor;

function wpopt(): PluginInit
{
    return PluginInit::getInstance();
}

function wpopt_optimize_image(string $path, bool $replace = true, array $settings = [])
{
    if (!wps('wpopt')) {
        return false;
    }

    require_once WPOPT_SUPPORTERS . '/media/ImagesProcessor.class.php';

    $settings = array_merge(wps('wpopt')->settings->get('media'), $settings);

    $imageProcessor = ImagesProcessor::getInstance($settings);

    if ($imageProcessor->optimize_image($path, $replace, false) === \WPOptimizer\modules\supporters\IPC_SUCCESS) {
        return $imageProcessor->get_metadata('file');
    }

    return false;
}

/**
 * wpopt utility useful to scan a directory and optimize images present
 */
function wpopt_optimize_media_path(string $path, array $settings = []): bool
{
    if (!wps('wpopt')) {
        return false;
    }

    require_once WPOPT_SUPPORTERS . '/media/ImagesProcessor.class.php';

    $settings = array_merge(wps('wpopt')->settings->get('media'), $settings);

    wps('wpopt')->options->update("status", 'optimization', 'running', "media");

    $scan_res = ImagesProcessor::getInstance($settings)->scan_dir($path);

    if ($scan_res === \WPOptimizer\modules\supporters\IPC_TIME_LIMIT) {
        CronActions::schedule_function('wpopt_optimize_media_path', 'wpopt_optimize_media_path', time() + 30, [$path, $settings]);
    }
    else {
        CronActions::unschedule_function('wpopt_optimize_media_path');
        wps('wpopt')->options->update("status", 'optimization', 'paused', "media");
    }

    return true;
}

function wpopt_minify_html($html, $options = [])
{
    require_once WPOPT_SUPPORTERS . '/minifier/Minify.class.php';
    require_once WPOPT_SUPPORTERS . '/minifier/Minify_HTML.class.php';
    require_once WPOPT_SUPPORTERS . '/minifier/Minify_CSS.class.php';
    require_once WPOPT_SUPPORTERS . '/minifier/Minify_JS.class.php';

    return \WPOptimizer\modules\supporters\Minify_HTML::minify($html, $options);
}

function wpopt_minify_css($css, $options = [])
{
    require_once WPOPT_SUPPORTERS . '/minifier/Minify.class.php';
    require_once WPOPT_SUPPORTERS . '/minifier/Minify_CSS.class.php';

    return \WPOptimizer\modules\supporters\Minify_CSS::minify($css, $options);
}

function wpopt_minify_javascript($css, $options = [])
{
    require_once WPOPT_SUPPORTERS . '/minifier/Minify.class.php';
    require_once WPOPT_SUPPORTERS . '/minifier/Minify_JS.class.php';

    return \WPOptimizer\modules\supporters\Minify_JS::minify($css, $options);
}

