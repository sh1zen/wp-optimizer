<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use WPS\core\Ajax;
use WPS\core\CronActions;
use WPS\core\Graphic;
use WPS\core\UtilEnv;
use WPS\modules\Module;

use WPOptimizer\modules\supporters\Media_Table;
use WPOptimizer\modules\supporters\ImagesProcessor;

/**
 *  Module for images optimization handling
 */
class Mod_Media extends Module
{
    public static ?string $name = "Media Optimizer";

    public array $scopes = array('autoload', 'cron', 'admin-page', 'settings');

    protected string $context = 'wpopt';

    private bool $image_processor_loaded = false;

    private function load_image_processor(): void
    {
        if ($this->image_processor_loaded) {
            return;
        }

        require_once WPOPT_SUPPORTERS . '/media/ImagesProcessor.class.php';

        $this->image_processor_loaded = true;
    }

    public function cleanup(array $settings = array(), array $all_settings = array()): bool
    {
        $this->status('orphan-media-scanner', 'paused');
        $this->status('optimization', 'paused');

        return wpopt_cleanup_media_cron_hooks();
    }

    public function cron_setting_fields(): array
    {
        return [
            ['type' => 'checkbox', 'name' => __('Auto optimize images', 'wpopt'), 'id' => 'media.active', 'value' => wps($this->context)->cron->is_active($this->slug), 'depend' => 'active']
        ];
    }

    public function ajax_handler($args = array()): void
    {
        $response = array();

        switch ($args['action']) {

            case 'reset-stats':
                wps('wpopt')->options->remove_all('media', 'stats');
                $response = __('Media optimization statistics has been successfully reset.', 'wpopt');
                break;

            case 'scan-orphaned-media':
                wpopt_remove_cron_hooks(array('orphaned_media_scanner_cron_handler'));

                if (CronActions::schedule_function('orphaned_media_scanner_cron_handler', array($this, 'orphaned_media_scanner_cron_handler'), time())) {
                    $response = __('Orphaned media scanner is successfully started.', 'wpopt');
                }
                else {
                    $response = __('An error occurred while starting orphaned media scanner.', 'wpopt');
                }
                break;

            case 'pause-orphaned-media':
                $this->status('orphan-media-scanner', 'paused');
                wpopt_remove_cron_hooks(array('orphaned_media_scanner_cron_handler'));
                $response = __('Orphaned media scanner is shutting down.', 'wpopt');
                break;

            case 'reset-orphaned-media':
                wps('wpopt')->options->remove_all('media', 'orphaned_media');
                $this->status('orphan-media-scanner', 'paused');
                wpopt_remove_cron_hooks(array('orphaned_media_scanner_cron_handler'));
                $response = __('Orphaned media scanner has been successfully reset.', 'wpopt');
                break;

            case 'pause-ipc-posts':
                $this->status('optimization', 'paused');
                wpopt_remove_cron_hooks(array('ipc_scanner_cron_handler'));
                $response = __('Media optimization scan is shutting down.', 'wpopt');
                break;

            case 'start-ipc-posts':
                if (CronActions::schedule_function('ipc_scanner_cron_handler', array($this, 'ipc_scanner_cron_handler'), time())) {
                    $response = __('Media optimization scan is successfully started.', 'wpopt');
                }
                else {
                    $response = __('An error occurred while starting optimization scan.', 'wpopt');
                }
                break;

            case 'reset-ipc-posts':
                wps('wpopt')->options->remove_all('media', 'scan_media');
                wpopt_remove_cron_hooks(array('ipc_scanner_cron_handler'));
                $this->status('optimization', 'paused');
                $response = __('Media posts optimization has been successfully reset.', 'wpopt');
                break;
        }

        if (empty($response)) {
            Ajax::response(__('Action not supported', 'wpopt'), 'error');
        }
        else {
            Ajax::response($response, 'success');
        }
    }

    public function status($context, $value = false)
    {
        if ($value) {
            return wps('wpopt')->options->update("status", $context, $value, "media");
        }

        return wps('wpopt')->options->get("status", $context, "media", '');
    }

    public function orphaned_media_scanner_cron_handler(): void
    {
        $this->load_image_processor();

        $ImagesPerformer = ImagesProcessor::getInstance($this->option());

        $this->status('orphan-media-scanner', 'running');

        if ($ImagesPerformer->find_orphaned_media() === \WPOptimizer\modules\supporters\IPC_TIME_LIMIT) {
            CronActions::schedule_function('orphaned_media_scanner_cron_handler', array($this, 'orphaned_media_scanner_cron_handler'), time() + 30);
        }
        else {
            wpopt_remove_cron_hooks(array('orphaned_media_scanner_cron_handler'));
            $this->status('orphan-media-scanner', 'paused');
        }
    }

    public function enqueue_scripts(): void
    {
        parent::enqueue_scripts();
        $script_asset = UtilEnv::resolve_asset(WPOPT_ABSPATH, 'modules/supporters/media/media.js', wps_core()->online);
        wp_enqueue_script('wpopt-media-page', $script_asset['url'], array('vendor-wps-js'), $script_asset['version'] ?: WPOPT_VERSION);
    }

    public function render_mediaCleaner_Panel(): string
    {
        require_once WPOPT_SUPPORTERS . '/media/Media_Table.class.php';

        Media_Table::process_bulk_action();

        $nonce = wp_create_nonce('wpopt-ajax-nonce');

        $media2Clean = wps('wpopt')->options->get_all('orphaned_media', 'media', [], 1000);
        $scannerRunning = $this->status('orphan-media-scanner') === 'running';

        ob_start();
        ?>
        <block class="wps-options">
            <row class="wps-row">
                <?php echo Graphic::icon('broom', 'wps-row-icon'); ?>
                <div class="wps-option">
                    <strong><?php _e('Scan orphaned media', 'wpopt'); ?></strong>
                    <span class="wps-option-description"><?php _e('Find uploaded files that are no longer referenced by WordPress content, then review the results before deleting anything.', 'wpopt'); ?></span>
                </div>
                <div class="wps-value wps-inline-actions">
                    <?php if ($scannerRunning): ?>
                        <span class="wps-status-pill is-info"><?php _e('Running', 'wpopt'); ?></span>
                    <?php endif; ?>
                    <button class="wps wps-button wpopt-btn is-info"
                        <?php echo $scannerRunning ? 'disabled' : '' ?>
                            data-wps="ajax-action" data-mod="media" data-action="scan-orphaned-media"
                            data-nonce="<?php echo $nonce ?>">
                        <?php echo __('Scan now', 'wpopt') ?>
                    </button>
                    <button class="wps wps-button wpopt-btn is-info"
                        <?php echo $scannerRunning ? '' : 'disabled' ?>
                            data-wps="ajax-action" data-mod="media" data-action="pause-orphaned-media"
                            data-nonce="<?php echo $nonce ?>">
                        <?php echo __('Pause', 'wpopt') ?>
                    </button>
                    <button class="wps wps-button wpopt-btn is-danger"
                        <?php echo $scannerRunning ? 'disabled' : '' ?>
                            data-wps="ajax-action" data-mod="media" data-action="reset-orphaned-media"
                            data-nonce="<?php echo $nonce ?>">
                        <?php echo __('Reset', 'wpopt') ?>
                    </button>
                </div>
            </row>
        </block>
        <?php
        if (empty($media2Clean)) {
            ?>
            <block class="wps-options">
                <row class="wps-row">
                    <?php echo Graphic::icon('check', 'wps-row-icon'); ?>
                    <div class="wps-option">
                        <strong><?php _e('No orphaned media found', 'wpopt'); ?></strong>
                        <span class="wps-option-description"><?php _e('Run a scan when you want to refresh the orphaned media list.', 'wpopt'); ?></span>
                    </div>
                    <div class="wps-value">
                        <span class="wps-status-pill"><?php _e('Clean', 'wpopt'); ?></span>
                    </div>
                </row>
            </block>
            <?php
        }
        else {
            $table = new Media_Table();

            $table->set_items($media2Clean);

            $table->prepare_items();

            ?>
            <form class="wps-list-table-form wpopt-media-cleaner-form" method="post" action="<?php echo wps_module_panel_url($this->slug, "media-cleaner"); ?>">
                <?php $table->display(); ?>
            </form>
            <?php
        }

        return (string)ob_get_clean();
    }

    public function render_imagesOptimizer_Panel(): string
    {
        $nonce = wp_create_nonce('wpopt-ajax-nonce');
        $scheduled = CronActions::get_scheduled_function('ipc_scanner_cron_handler');
        $isRunning = $this->status('optimization') === 'running';
        $hasImageEngine = extension_loaded('imagick') || extension_loaded('gd') || extension_loaded('gd2');
        ob_start();
        ?>
        <notice class="wps wpopt-media-preflight-notice">
            <h3><?php echo __('Before start optimizing makes sure you did:', 'wpopt') ?></h3>
            <ul class="wps-list">
                <li>
                    <strong><?php echo sprintf(__('Read related <a href="%s">FAQ</a>.', 'wpopt'), admin_url('admin.php?page=wpopt-faqs')) ?></strong>
                </li>
                <li>
                    <strong><?php echo sprintf(__('Set up your optimization parameters <a href="%s">here</a>.', 'wpopt'), wps_module_setting_url('wpopt', 'media')); ?></strong>
                </li>
            </ul>
            <strong><?php echo sprintf(__('Note that optimization will run in background and time required depends on number of media you have. <a href="%s">Cron</a> must be active.', 'wpopt'), wps_admin_route_url('wpopt', 'setting-cron')); ?></strong>
        </notice>
        <block class="wps-options">
            <?php if (!extension_loaded('imagick')): ?>
                <row class="wps-row">
                    <?php echo Graphic::icon($hasImageEngine ? 'info' : 'tools', 'wps-row-icon'); ?>
                    <div class="wps-option">
                        <strong><?php _e('Image engine support', 'wpopt'); ?></strong>
                        <span class="wps-option-description">
                            <?php
                            if (!$hasImageEngine) {
                                echo sprintf(__('Install <a href="%s">Imagick</a> or <a href="%s">GD</a> to run image optimization on this server.', 'wpopt'), 'https://www.php.net/manual/en/book.imagick.php', 'https://www.php.net/manual/en/book.image.php');
                            }
                            else {
                                echo sprintf(__('GD is available, but <a href="%s">Imagick</a> is recommended for broader image format support and better optimization results.', 'wpopt'), 'https://www.php.net/manual/en/book.imagick.php');
                            }
                            ?>
                        </span>
                    </div>
                    <div class="wps-value">
                        <span class="wps-status-pill is-warning"><?php echo $hasImageEngine ? __('Recommended', 'wpopt') : __('Required', 'wpopt'); ?></span>
                    </div>
                </row>
            <?php endif; ?>

            <row class="wps-row">
                <?php echo Graphic::icon('image', 'wps-row-icon'); ?>
                <div class="wps-option">
                    <strong><?php _e('Optimize media library', 'wpopt'); ?></strong>
                    <span class="wps-option-description">
                        <?php
                        if ($scheduled) {
                            echo sprintf(__('Optimization is scheduled for %s.', 'wpopt'), wps_time('mysql', 0, true, $scheduled['timestamp']));
                        }
                        else {
                            _e('Runs silently in the background and processes the library according to the current Media Optimizer settings.', 'wpopt');
                        }
                        ?>
                    </span>
                </div>
                <div class="wps-value wps-inline-actions">
                    <?php if ($isRunning): ?>
                        <span class="wps-status-pill is-info"><?php _e('Running', 'wpopt'); ?></span>
                    <?php elseif ($scheduled): ?>
                        <span class="wps-status-pill is-info"><?php _e('Scheduled', 'wpopt'); ?></span>
                    <?php endif; ?>
                    <button class="wps wps-button wpopt-btn is-info"
                        <?php echo (!empty($scheduled) || $isRunning) ? 'disabled' : '' ?>
                            data-wps="ajax-action" data-mod="media" data-action="start-ipc-posts"
                            data-nonce="<?php echo $nonce; ?>">
                        <?php echo __('Start', 'wpopt') ?>
                    </button>
                    <button class="wps wps-button wpopt-btn is-info"
                        <?php echo $isRunning ? '' : 'disabled' ?>
                            data-wps="ajax-action" data-mod="media" data-action="pause-ipc-posts"
                            data-nonce="<?php echo $nonce; ?>">
                        <?php echo __('Pause', 'wpopt') ?>
                    </button>
                    <button class="wps wps-button wpopt-btn is-danger"
                        <?php echo $isRunning ? 'disabled' : '' ?>
                            data-wps="ajax-action" data-mod="media" data-action="reset-ipc-posts"
                            data-nonce="<?php echo $nonce; ?>">
                        <?php echo __('Reset', 'wpopt') ?>
                    </button>
                </div>
            </row>
        </block>
        <?php
        return (string)ob_get_clean();
    }

    public function render_sub_modules(bool $standalone = true): void
    {
        ?>
        <section class="wps-wrap wpopt-media-optimizer-page">
            <block class="wps">
                <?php
                echo Graphic::generateHTML_tabs_panels(array(
                        array(
                            'id'                => 'images-optimizer',
                            'panel-title'       => __('Images optimizer', 'wpopt'),
                            'panel-description' => __('Compress, resize and process image attachments in the background.', 'wpopt'),
                            'panel-icon'        => 'image',
                            'tab-icon'          => 'image',
                            'callback'          => array($this, 'render_imagesOptimizer_Panel')
                        ),
                        array(
                            'id'                => 'media-cleaner',
                            'panel-title'       => __('Media cleaner', 'wpopt'),
                            'panel-description' => __('Find attachments that are no longer referenced and review them before removal.', 'wpopt'),
                            'panel-icon'        => 'broom',
                            'tab-icon'          => 'broom',
                            'callback'          => array($this, 'render_mediaCleaner_Panel')
                        ),
                        array(
                            'id'                => 'stats',
                            'panel-title'       => __('Stats', 'wpopt'),
                            'panel-description' => __('Track processed images and estimated space saved by optimization.', 'wpopt'),
                            'panel-icon'        => 'chart',
                            'tab-icon'          => 'chart',
                            'callback'          => array($this, 'render_stats')
                        ),
                    )
                );
                ?>
            </block>
        </section>
        <?php
    }

    public function cron_handler($args = array()): void
    {
        $this->ipc_scanner_cron_handler();
    }

    /**
     * Image Cleaner Processor Cron Job handler
     * @return void
     */
    public function ipc_scanner_cron_handler(): void
    {
        $this->load_image_processor();

        $ImagesPerformer = ImagesProcessor::getInstance($this->option());

        $this->status('optimization', 'running');

        $res = $ImagesPerformer->scan_media();

        if ($res === \WPOptimizer\modules\supporters\IPC_TIME_LIMIT) {
            CronActions::schedule_function('ipc_scanner_cron_handler', array($this, 'ipc_scanner_cron_handler'), time() + 30);
        }
        else {
            wpopt_remove_cron_hooks(array('ipc_scanner_cron_handler'));
            $this->status('optimization', 'paused');
        }
    }

    public function render_stats($args = array()): string
    {
        global $wpdb;

        $scannedID = wps('wpopt')->options->get('last_scanned_postID', 'scan_media', 'media', 0);

        $optimized_images_count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE ID <= $scannedID AND post_type = 'attachment' AND post_mime_type LIKE '%image%'");
        $all_media_count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'attachment' AND post_mime_type LIKE '%image%'");

        list($size, $prev_size, $media_optimized_id) = wps('wpopt')->options->get('size_prevsize', 'stats', 'media', [0, 0, 0]);

        while (!empty($optimized_images = $wpdb->get_results("SELECT id, value FROM " . wps('wpopt')->options->table_name() . " WHERE item = 'optimized_images' AND context = 'media' AND id > '$media_optimized_id' ORDER BY id LIMIT 10000", ARRAY_A))) {

            $wpdb->flush();

            $prev_size += array_reduce($optimized_images, function ($carry, $meta) {

                $value = maybe_unserialize($meta['value']);
                return $carry + $value['prev_size'];
            }, 0);

            $size += array_reduce($optimized_images, function ($carry, $meta) {

                $value = maybe_unserialize($meta['value']);
                return $carry + $value['size'];
            }, 0);

            $media_optimized_id = end($optimized_images)['id'];

            unset($optimized_images);
        }

        // clear last path related media optimization data
        wps('wpopt')->options->remove_all('media', 'optimized_images');

        wps('wpopt')->options->add('size_prevsize', 'stats', [$size, $prev_size, $media_optimized_id], 'media');

        $saved_space = $prev_size ? min(round(($prev_size - $size) / $prev_size * 100, 2), 100) : 0;

        $processed_percentile = ($all_media_count and $optimized_images_count) ? min(round($optimized_images_count / $all_media_count * 100, 2), 100) : 0;
        $optimized_images_count = (int)$optimized_images_count;
        $all_media_count = (int)$all_media_count;
        $media_left_count = max(0, $all_media_count - $optimized_images_count);
        $saved_bytes = max(0, $prev_size - $size);
        $processed_progress = max(0, min((float)$processed_percentile, 100));
        $saved_progress = max(0, min((float)$saved_space, 100));
        $format_percent = static function (float $value): string {
            $precision = abs($value - round($value)) < 0.005 ? 0 : (abs(($value * 10) - round($value * 10)) < 0.005 ? 1 : 2);

            return number_format_i18n($value, $precision) . '%';
        };

        ob_start();
        ?>
        <section class="wpopt-media-stats">
            <div class="wpopt-media-stats-summary">
                <div class="wpopt-media-stats-summary-item">
                    <?php echo Graphic::icon('image', 'wpopt-media-stat-icon'); ?>
                    <div class="wpopt-media-stat-copy">
                        <strong><?php _e('Media optimized', 'wpopt'); ?></strong>
                        <span class="wpopt-media-stat-value"><?php echo esc_html(number_format_i18n($optimized_images_count)); ?></span>
                    </div>
                </div>
                <div class="wpopt-media-stats-summary-item">
                    <?php echo Graphic::icon('box', 'wpopt-media-stat-icon'); ?>
                    <div class="wpopt-media-stat-copy">
                        <strong><?php _e('Left', 'wpopt'); ?></strong>
                        <span class="wpopt-media-stat-value"><?php echo esc_html(number_format_i18n($media_left_count)); ?></span>
                    </div>
                </div>
            </div>

            <div class="wpopt-media-stats-grid">
                <article class="wpopt-media-stat-card">
                    <?php echo Graphic::icon('chart', 'wpopt-media-stat-icon is-green'); ?>
                    <div class="wpopt-media-stat-card-body">
                        <strong><?php _e('Processed media library', 'wpopt'); ?></strong>
                        <div class="wpopt-media-progress-ring is-complete" style="--wpopt-media-progress: <?php echo esc_attr($processed_progress); ?>%;">
                            <span><?php echo esc_html($format_percent($processed_progress)); ?></span>
                        </div>
                        <p><?php echo $processed_progress >= 100 ? esc_html__('Your entire media library has been processed.', 'wpopt') : esc_html(sprintf(__('%s of your media library has been processed.', 'wpopt'), $format_percent($processed_progress))); ?></p>
                    </div>
                </article>

                <article class="wpopt-media-stat-card">
                    <?php echo Graphic::icon('database', 'wpopt-media-stat-icon'); ?>
                    <div class="wpopt-media-stat-card-body">
                        <strong><?php _e('Space freed up', 'wpopt'); ?></strong>
                        <span class="wpopt-media-stat-value is-large"><?php echo esc_html(size_format($saved_bytes, 2)); ?></span>
                        <div class="wpopt-media-progress-ring" style="--wpopt-media-progress: <?php echo esc_attr($saved_progress); ?>%;">
                            <span><?php echo esc_html($format_percent($saved_progress)); ?></span>
                        </div>
                        <p><?php _e('Percentage of total savings capacity achieved.', 'wpopt'); ?></p>
                    </div>
                </article>
            </div>

            <div class="wpopt-media-stats-reset">
                <?php echo Graphic::icon('repeat', 'wpopt-media-stat-icon'); ?>
                <div class="wpopt-media-stat-copy">
                    <strong><?php _e('Reset statistics', 'wpopt'); ?></strong>
                    <span><?php _e('This will clear all statistics and start tracking again.', 'wpopt'); ?></span>
                </div>
                <button class="wps wps-button wpopt-btn is-neutral"
                    <?php echo $this->status('optimization') === 'running' ? 'disabled' : '' ?>
                        data-wps="ajax-action" data-mod="media" data-action="reset-stats"
                        data-nonce="<?php echo wp_create_nonce('wpopt-ajax-nonce'); ?>">
                    <?php echo __('Reset', 'wpopt') ?>
                </button>
            </div>
        </section>
        <?php

        return (string)ob_get_clean();
    }

    public function restricted_access($context = ''): bool
    {
        switch ($context) {

            case 'settings':
            case 'render-admin':
            case 'ajax':
                return !current_user_can('manage_options');

            default:
                return false;
        }
    }

    public function init(): void
    {
        if (wp_doing_cron()) {
            $this->load_image_processor();
        }

    }

    protected function setting_fields($filter = ''): array
    {
        return $this->group_setting_fields(
            $this->group_setting_fields(
                $this->setting_field(__('Images optimization preferences', 'wpopt'), false, 'separator'),
                $this->setting_field(__('Auto optimize uploads', 'wpopt'), 'auto_optimize_uploads', 'link', ['value' => ['text' => __('set it here', 'wpopt'), 'href' => wps_admin_route_url('wpopt', 'setting-cron')]]),
                $this->setting_field(__('Use Imagick (if installed)', 'wpopt'), "use_imagick", "checkbox", ['default_value' => true]),
                $this->setting_field(__('Optimization intensity', 'wpopt'), "quality", "range", [
                        'default_value' => 8,
                        'value'         => $this->quality_intensity_value(),
                        'classes'       => 'wpopt-optimization-intensity-range',
                        'props'         => [
                                'min'  => '0',
                                'max'  => '10',
                                'step' => '1',
                        ],
                ]),
                $this->setting_field(__('Keep all the EXIF data of your images', 'wpopt'), "keep_exif", "checkbox", ['default_value' => false]),
                $this->setting_field(__('Resize larger images', 'wpopt'), "resize_larger_images", "checkbox", ['default_value' => false]),
                $this->setting_field(__('max with (px)', 'wpopt'), "resize_width_px", "number", ['default_value' => 2560, 'parent' => 'resize_larger_images']),
                $this->setting_field(__('max height (px)', 'wpopt'), "resize_height_px", "number", ['default_value' => 1440, 'parent' => 'resize_larger_images']),
            ),

            $this->group_setting_fields(
                $this->setting_field(__('Formats', 'wpopt'), false, 'separator'),
                $this->setting_field(__('Convert all images to new webp format', 'wpopt'), "convert_to_webp", "checkbox", ['default_value' => false]),
                $this->setting_field(__('Optimize JPG/JPEG', 'wpopt'), "format.jpg", "checkbox", ['default_value' => true]),
                $this->setting_field(__('Optimize PNG', 'wpopt'), "format.png", "checkbox", ['default_value' => true]),
                $this->setting_field(__('Optimize GIF', 'wpopt'), "format.gif", "checkbox", ['default_value' => true]),
                $this->setting_field(__('Optimize WEBP', 'wpopt'), "format.webp", "checkbox", ['default_value' => true]),
                $this->setting_field(__('Optimize other formats (tiff, heic, bmp)', 'wpopt'), "format.others", "checkbox", ['default_value' => false]),
            )
        );
    }

    private function quality_intensity_value(): int
    {
        $quality = absint($this->option('quality', 8));

        if ($quality > 10) {
            return min(10, max(0, (int)round($quality / 10)));
        }

        return min(10, max(0, $quality));
    }

    protected function infos(): array
    {
        return [
            'auto_optimize_uploads' => __("Automatically compresses and resizes images for faster loading, improving website performance.", 'wpopt'),
            'use_imagick'           => __("A PHP extension for creating, modifying and manipulating images, it makes optimization faster.", 'wpopt'),
            'convert_to_webp'       => __("WebP is a modern image format with superior compression efficiency, reducing file size and improving website performance.", 'wpopt'),
        ];
    }
}

return __NAMESPACE__;
