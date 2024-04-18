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
    public static $name = "Media Optimizer";

    public array $scopes = array('autoload', 'cron', 'admin-page', 'settings');

    protected string $context = 'wpopt';

    public function media_lazyload_maker($buffer)
    {
        if (!UtilEnv::is_safe_buffering()) {
            return $buffer;
        }

        return preg_replace(
            "#<img(?!.*?\bloading\b)([^']*?) src=['\"]([^'\"]*?)['\"]([^>]*?)>#",
            "<img$1 src='$2' loading='lazy'$3>",
            $buffer
        );
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
                if (CronActions::schedule_function('orphaned_media_scanner_cron_handler', array($this, 'orphaned_media_scanner_cron_handler'), time(), [])) {
                    $response = __('Orphaned media scanner is successfully started.', 'wpopt');
                }
                else {
                    $response = __('An error occurred while starting orphaned media scanner.', 'wpopt');
                }
                break;

            case 'pause-orphaned-media':
                $this->status('orphan-media-scanner', 'paused');
                $response = __('Orphaned media scanner is shutting down.', 'wpopt');
                break;

            case 'reset-orphaned-media':
                wps('wpopt')->options->remove_all('media', 'orphaned_media');
                $this->status('orphan-media-scanner', 'paused');
                $response = __('Orphaned media scanner has been successfully reset.', 'wpopt');
                break;

            case 'pause-ipc-posts':
                $this->status('optimization', 'paused');
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
        $ImagesPerformer = ImagesProcessor::getInstance($this->option());

        $this->status('orphan-media-scanner', 'running');

        if ($ImagesPerformer->find_orphaned_media() === \WPOptimizer\modules\supporters\IPC_TIME_LIMIT) {
            CronActions::schedule_function('orphaned_media_scanner_cron_handler', array($this, 'orphaned_media_scanner_cron_handler'), time() + 30);
        }
        else {
            CronActions::unschedule_function('orphaned_media_scanner_cron_handler');
            $this->status('orphan-media-scanner', 'paused');
        }
    }

    public function enqueue_scripts(): void
    {
        parent::enqueue_scripts();
        wp_enqueue_script('wpopt-media-page', UtilEnv::path_to_url(WPOPT_ABSPATH) . 'modules/supporters/media/media.js', array('vendor-wps-js'), WPOPT_VERSION);
    }

    public function render_mediaCleaner_Panel(): string
    {
        require_once WPOPT_SUPPORTERS . '/media/Media_Table.class.php';

        Media_Table::process_bulk_action();

        $nonce = wp_create_nonce('wpopt-ajax-nonce');

        $media2Clean = wps('wpopt')->options->get_all('orphaned_media', 'media', [], 1000);

        ob_start();
        ?>
        <block class="wps"><b><?php echo __('Find and remove orphaned media', 'wpopt') ?></b></block>
        <block>
            <?php if ($this->status('orphan-media-scanner') === 'running'): ?>
                <h3><?php _e('Orphaned media scan is running.', 'wpopt'); ?></h3>
            <?php endif; ?>
            <block style="padding-right: 20px">
                <button class="button button-primary button-large"
                    <?php echo $this->status('orphan-media-scanner') === 'running' ? 'disabled' : '' ?>
                        data-wps="ajax-action" data-mod="media" data-action="scan-orphaned-media"
                        data-nonce="<?php echo $nonce ?>">
                    <?php echo __('Scan now', 'wpopt') ?>
                </button>
                <button class="button button-primary button-large"
                    <?php echo $this->status('orphan-media-scanner') === 'running' ? '' : 'disabled' ?>
                        data-wps="ajax-action" data-mod="media" data-action="pause-orphaned-media"
                        data-nonce="<?php echo $nonce ?>">
                    <?php echo __('Pause', 'wpopt') ?>
                </button>
            </block>
            <button class="button button-primary button-large"
                <?php echo $this->status('orphan-media-scanner') === 'running' ? 'disabled' : '' ?>
                    data-wps="ajax-action" data-mod="media" data-action="reset-orphaned-media"
                    data-nonce="<?php echo $nonce ?>">
                <?php echo __('Reset', 'wpopt') ?>
            </button>
        </block>
        <br><br>
        <?php
        if (empty($media2Clean)) {
            echo "<h3>" . __('No orphaned media found.', 'wpopt') . "</h3>";
        }
        else {
            $table = new Media_Table();

            $table->set_items($media2Clean);

            $table->prepare_items();

            ?>
            <form method="post" action="<?php echo wps_module_panel_url($this->slug, "media-cleaner"); ?>">
                <?php $table->display(); ?>
            </form>
            <?php
        }

        return (string)ob_get_clean();
    }

    public function render_imagesOptimizer_Panel(): string
    {
        $nonce = wp_create_nonce('wpopt-ajax-nonce');
        ob_start();
        ?>
        <section class='wps'>
            <notice class="wps">
                <h3><?php echo __('Before start optimizing makes sure you did:', 'wpopt') ?></h3>
                <ul class="wps-list">
                    <li>
                        <strong><?php echo sprintf(__('Read related <a href="%s">FAQ</a>.', 'wpopt'), admin_url('admin.php?page=wpopt-faqs')) ?></strong>
                    </li>
                    <li>
                        <strong><?php echo sprintf(__('Set up your optimization parameters <a href="%s">here</a>.', 'wpopt'), admin_url('admin.php?page=wpopt-modules-settings#settings-media')); ?></strong>
                    </li>
                </ul>
                <strong><?php echo sprintf(__('Note that optimization will run in background and time required depends on number of media you have. <a href="%s">Cron</a> must be active.', 'wpopt'), admin_url('admin.php?page=wpopt-settings#settings-cron')); ?></strong>
                <?php
                if (!extension_loaded('imagick')) {

                    echo '<br><br>';

                    if (!(extension_loaded('gd') or extension_loaded('gd2'))) {
                        echo "<strong style='color:#bc0000'>" . sprintf(__('To proceed with image optimization you need to install <a href="%s">imagick</a> or <a href="%s">GD</a> on your server.', 'wpopt'), "https://www.php.net/manual/en/book.imagick.php", "https://www.php.net/manual/en/book.image.php") . "</strong>";
                    }
                    else {
                        echo "<strong style='color:#bc0000'>" . sprintf(__('To guarantee a complete image optimization support, install <a href="%s">imagick</a> on your server.', 'wpopt'), "https://www.php.net/manual/en/book.imagick.php") . "</strong>";
                    }
                }
                ?>
            </notice>
            <notice class="wps">
                <h2><?php _e('Optimize all media library.', 'wpopt'); ?></h2>
                <pre><?php echo sprintf(__('Optimization will run silently in background (<a href="%s">Cron</a> must be active).', 'wpopt'), admin_url('admin.php?page=wpopt-settings#settings-cron')); ?></pre>
                <block style="padding-right: 30px">
                    <button class="button button-primary button-large"
                        <?php echo $this->status('optimization') === 'running' ? 'disabled' : '' ?>
                            data-wps="ajax-action" data-mod="media" data-action="start-ipc-posts"
                            data-nonce="<?php echo $nonce; ?>">
                        <?php echo __('Start', 'wpopt') ?>
                    </button>
                    <button class="button button-primary button-large"
                        <?php echo $this->status('optimization') === 'running' ? '' : 'disabled' ?>
                            data-wps="ajax-action" data-mod="media" data-action="pause-ipc-posts"
                            data-nonce="<?php echo $nonce; ?>">
                        <?php echo __('Pause', 'wpopt') ?>
                    </button>
                </block>
                <button class="button button-primary button-large"
                    <?php echo $this->status('optimization') === 'running' ? 'disabled' : '' ?>
                        data-wps="ajax-action" data-mod="media" data-action="reset-ipc-posts"
                        data-nonce="<?php echo $nonce; ?>">
                    <?php echo __('Reset', 'wpopt') ?>
                </button>
            </notice>
        </section>
        <?php
        return (string)ob_get_clean();
    }

    public function render_sub_modules(): void
    {
        ?>
        <section class="wps-wrap">
            <block class="wps">
                <section class="wps-header"><h1>Media Optimizer</h1></section>
                <?php
                echo Graphic::generateHTML_tabs_panels(array(
                        array(
                            'id'          => 'images-optimizer',
                            'panel-title' => __('Images optimizer', 'wpopt'),
                            'callback'    => array($this, 'render_imagesOptimizer_Panel')
                        ),
                        array(
                            'id'          => 'media-cleaner',
                            'panel-title' => __('Media cleaner', 'wpopt'),
                            'callback'    => array($this, 'render_mediaCleaner_Panel')
                        ),
                        array(
                            'id'          => 'stats',
                            'panel-title' => __('Stats', 'wpopt'),
                            'callback'    => array($this, 'render_stats')
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
        $ImagesPerformer = ImagesProcessor::getInstance($this->option());

        $this->status('optimization', 'running');

        $res = $ImagesPerformer->scan_media();

        if ($res === \WPOptimizer\modules\supporters\IPC_TIME_LIMIT) {
            CronActions::schedule_function('ipc_scanner_cron_handler', array($this, 'ipc_scanner_cron_handler'), time() + 30);
        }
        else {
            CronActions::unschedule_function('ipc_scanner_cron_handler');
            $this->status('optimization', 'paused');
        }
    }

    public function render_stats($args = array()): string
    {
        global $wpdb;

        $scannedID = wps('wpopt')->options->get('last_scanned_postID', 'scan_media', 'media', 0);

        $optimized_images_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID <= {$scannedID} AND post_type = 'attachment' AND post_mime_type LIKE '%image%'");
        $all_media_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE '%image%'");

        list($size, $prev_size, $media_optimized_id) = wps('wpopt')->options->get('size_prevsize', 'stats', 'media', [0, 0, 0]);

        while (!empty($optimized_images = $wpdb->get_results("SELECT id, value FROM " . wps('wpopt')->options->table_name() . " WHERE item = 'optimized_images' AND context = 'media' AND id > '{$media_optimized_id}' ORDER BY id LIMIT 10000", ARRAY_A))) {

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
        $color_media_library = $processed_percentile > 75 ? '#00e045' : ($processed_percentile > 45 ? '#e7f100' : '#f10000');

        ob_start();
        ?>
        <section class="wps">
            <notice class="wps">
                <h3><?php echo sprintf(__('Media optimized: %d', 'wpopt'), $optimized_images_count); ?> | <?php echo sprintf(__('Left: %d.', 'wpopt'), $all_media_count - $optimized_images_count); ?></h3>
                <br>
                <block class="wps-card__wrapper">
                    <block class="wps-card">
                        <h3><?php echo sprintf(__('Processed media library:', 'wpopt'), $processed_percentile) ?></h3>
                        <div class='wps-progressbarCircle' data-percent='<?php echo $processed_percentile; ?>'
                             data-stroke='2'
                             data-size='155' data-color='<?php echo $color_media_library ?>'></div>
                    </block>
                    <block class="wps-card">
                        <h3><?php echo sprintf(__('Space freed up: %s:', 'wpopt'), size_format($prev_size - $size, 2)) ?></h3>
                        <div class='wps-progressbarCircle' data-percent='<?php echo $saved_space; ?>'
                             data-stroke='2'
                             data-size='155' data-color='#343434'></div>
                    </block>
                </block>
            </notice>
            <block class="wps-row">
                <button class="button button-primary button-large"
                    <?php echo $this->status('optimization') === 'running' ? 'disabled' : '' ?>
                        data-wps="ajax-action" data-mod="media" data-action="reset-stats"
                        data-nonce="<?php echo wp_create_nonce('wpopt-ajax-nonce'); ?>">
                    <?php echo __('Reset', 'wpopt') ?>
                </button>
            </block>
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
        if (is_admin() or wp_doing_cron()) {

            require_once WPOPT_SUPPORTERS . '/media/ImagesProcessor.class.php';
        }

        if ($this->option('loading_lazy', false)) {

            ob_start([$this, "media_lazyload_maker"]);
        }
    }

    protected function setting_fields($filter = ''): array
    {
        return $this->group_setting_fields(

            $this->group_setting_fields(
                $this->setting_field(__('Force images to use browser built-in loading lazy advantages', 'wpopt'), "loading_lazy", "checkbox"),
            ),

            $this->group_setting_fields(
                $this->setting_field(__('Images optimization preferences', 'wpopt'), false, 'separator'),
                $this->setting_field(__('Auto optimize uploads', 'wpopt'), 'auto_optimize_uploads', 'link', ['value' => ['text' => __('set it here', 'wpopt'), 'href' => admin_url('admin.php?page=wpopt-settings#settings-cron')]]),
                $this->setting_field(__('Use Imagick (if installed)', 'wpopt'), "use_imagick", "checkbox", ['default_value' => true]),
                $this->setting_field(__('Optimization quality', 'wpopt'), "quality", "number", ['default_value' => 80]),
                $this->setting_field(__('Keep all the EXIF data of your images', 'wpopt'), "keep_exif", "checkbox", ['default_value' => false]),
                $this->setting_field(__('Resize larger images', 'wpopt'), "resize_larger_images", "checkbox", ['default_value' => false]),
                $this->setting_field(__('max with (px)', 'wpopt'), "resize_width_px", "number", ['default_value' => 2560, 'parent' => 'resize_larger_images']),
                $this->setting_field(__('max height (px)', 'wpopt'), "resize_height_px", "number", ['default_value' => 1440, 'parent' => 'resize_larger_images']),
            ),

            $this->group_setting_fields(
                $this->setting_field(__('Formats', 'wpopt'), false, 'separator'),
                $this->setting_field(__('Convert all images to new webp format', 'wpopt'), "convert_to_webp", "checkbox", ['default_value' => false]),
                $this->setting_field(__('Optimize JPG/JPEG', 'wpopt'), "format.jpg", "checkbox", ['default_value' => true]),
                $this->setting_field(__('Optimize PNG', 'wpopt'), "format.png", "checkbox", ['default_value' => false]),
                $this->setting_field(__('Optimize GIF', 'wpopt'), "format.gif", "checkbox", ['default_value' => true]),
                $this->setting_field(__('Optimize WEBP', 'wpopt'), "format.webp", "checkbox", ['default_value' => true]),
                $this->setting_field(__('Optimize other formats (tiff, heic, bmp)', 'wpopt'), "format.others", "checkbox", ['default_value' => false]),
            )
        );
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
