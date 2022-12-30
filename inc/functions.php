<?php

/**
 * @author    sh1zen
 * @copyright Copyright (C)  2022
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

function wpopt()
{
    return \WPOptimizer\core\PluginInit::getInstance();
}

/**
 * wpopt utility useful to scan a directory and optimize images present
 *
 * @param string $path
 * @param array $settings
 * @return bool
 */
function wpopt_optimize_media_path(string $path, array $settings = [])
{
    require_once WPOPT_SUPPORTERS . '/media/ImagesProcessor.class.php';

    $settings = array_merge(
        [
            'use_imagick'          => true,
            'format'               => array(
                'jpg'    => true,
                'png'    => false,
                'gif'    => true,
                'webp'   => true,
                'others' => false
            ),
            'quality'              => 80,
            'keep_exif'            => false,
            'convert_to_webp'      => false,
            'resize_larger_images' => false,
            'resize_width_px'      => 2560,
            'resize_height_px'     => 1440
        ],
        shzn('wpopt') ? shzn('wpopt')->settings->get('media') : [],
        $settings
    );

    $imageProcessor = \WPOptimizer\modules\supporters\ImagesProcessor::getInstance($settings);

    if (shzn('wpopt')) {

        shzn('wpopt')->options->update("status", 'optimization', 'running', "media");

        $res = $imageProcessor->scan_dir($path);
        if ($res === \WPOptimizer\modules\supporters\IPC_TIME_LIMIT) {
            \SHZN\core\Cron::schedule_function('wpopt_optimize_media_path', [$path, $settings], time() + 30);
        }
        else {
            \SHZN\core\Cron::unschedule_function('wpopt_optimize_media_path', [$path, $settings]);
            shzn('wpopt')->options->update("status", 'optimization', 'paused', "media");
        }
        return true;
    }

    return false;
}

