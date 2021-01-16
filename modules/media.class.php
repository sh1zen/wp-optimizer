<?php

/**
 *  Module for images optimization handling
 */
class WOMod_Media extends WOModule
{
    public $scopes = array('autoload', 'cron');

    private $images_mime_types;

    private $to_optimize_data;

    private $optimization_info = array();

    public function __construct()
    {
        $this->images_mime_types = array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'gif'          => 'image/gif',
            'png'          => 'image/png',
            'ico'          => 'image/x-icon',
            'pjpeg'        => 'image/pjpeg',
            'webp'         => 'image/webp'
        );

        $this->to_optimize_data = array();

        parent::__construct(array(
            'cron_settings' => array(
                'active' => false
            )
        ));

        if (WOSettings::check($this->cron_settings, 'active')) {

            add_filter('wp_handle_upload', array($this, 'add_images_2_process'), 10, 2);
            add_filter('wp_generate_attachment_metadata', array($this, 'add_thumbs_2_process'), 10, 3);

            add_action('shutdown', array($this, 'shutdown'));
        }

        if (is_admin() or wp_doing_cron()) {

            require_once __DIR__ . '/media/WO_ImagesPerformer.class.php';

            $this->optimization_info = get_option('wpopt.media.status', array());
        }
    }

    public function cron_validate_settings($valid, $input)
    {
        $valid[$this->slug] = array(
            'active' => isset($input['images_active']),
        );

        return $valid;
    }

    public function cron_setting_fields($cron_settings)
    {
        $cron_settings[] = array('type' => 'checkbox', 'name' => __('Auto optimize images (daily uploads)', 'wpopt'), 'id' => 'images_active', 'value' => WOSettings::check($this->cron_settings, 'active'));

        return $cron_settings;
    }

    public function shutdown()
    {
        if (!empty($this->to_optimize_data)) {
            WOOptions::update('media.todo', $this->to_optimize_data);
        }
    }

    public function ajax_handler($args = array())
    {
        $response = false;

        switch ($args['action']) {

            case 'autoCompleteDirs':
                $response = array();
                $response['predictions'] = WODisk::autocomplete($args['options']);
                break;
        }

        if ($response) {
            wp_send_json_success(array(
                'response' => $response
            ));
        }
        else {
            wp_send_json_error(array(
                'response' => __('Action not supported', 'wpopt'),
            ));
        }
    }

    public function enqueue_scripts()
    {
        wp_enqueue_script('wpopt-media-page', plugin_dir_url(WPOPT_FILE) . 'modules/media/media.js', array('jquery'), false, true);
    }

    public function render_mediaCleaner_Panel()
    {
        ob_start();
        $media2Clean = get_option('wpopt.media.to.clean', array(array(
                                                                    'name' => 'nome',
                                                                    'path' => WP_CONTENT_DIR . '/oop',
                                                                    'type' => 'imsge'
                                                                )));

        if (empty($media2Clean)):
            ?>
            <div class="wpopt-notice">
                <h3><?php _e('No orphaned media found.', 'wpopt'); ?></h3>
                <button class="button button-primary button-large" data-nonce="<?= wp_create_nonce('wpopt-images'); ?>"
                        data-action="scan-orphaned-media"><?= __('Scan now', 'wpopt') ?></button>
            </div>
        <?php
        else:

            require_once __DIR__ . '/media/WO_OM_List_Table.class.php';

            $table_list_obj = new WO_OM_List_Table();

            $table_list_obj->prepare_items();

            ?>
            <form method="post" action="<?php echo wpopt_module_panel_url($this->slug, "media-cleaner"); ?>">
                <?php $table_list_obj->display(); ?>
            </form>
        <?php

        endif;
        return ob_get_clean();
    }

    public function render_imagesOptimizer_Panel()
    {
        ob_start();
        ?>
        <section class="wpopt-wrap-flex">
            <section class='wpopt'>
                <block class="wpopt">
                    <h3><?= __('Before start optimizing makes sure you did:', 'wpopt') ?></h3>
                    <ul class="wpopt-list">
                        <li>
                            <strong><?= sprintf(__('Read related <a href="%s">FAQ</a>.', 'wpopt'), admin_url('admin.php?page=wpopt-faqs')) ?></strong>
                        </li>
                        <li>
                            <strong><?= sprintf(__('Setup your optimization parameters <a href="%s">here</a>.', 'wpopt'), admin_url('admin.php?page=wpopt-settings#media')) ?></strong>
                        </li>
                    </ul>
                    <strong><?= __('Note that optimization will run in background and time required depends on number of media you have.', 'wpopt') ?></strong>
                    <?php
                    if (!extension_loaded('imagick')) {
                        ?>
                        <br><br>
                        <strong style="color:#bc0000"><?= sprintf(__('To guarantee a complete image optimization support, install "<a href="%s">imagick</a>" on your server.', 'wpopt'), "https://www.php.net/manual/en/book.imagick.php") ?></strong><?php
                    }
                    ?>
                </block>

                <block class="wpopt">
                    <h2><?php _e('Specify a path where the media optimization will run.', 'wpopt'); ?></h2>
                    <pre><?php _e('Optimization will run silently in background.', 'wpopt'); ?></pre>

                    <?php

                    $nonce = wp_create_nonce('wpopt-media');

                    $disabled = $this->status("optimization.status", 'paused') === 'running' ? 'disabled' : '';

                    ?>
                    <div class="wpopt-dir-explorer">
                        <label>
                            <text><?= WO_UtilEnv::normalize_path(ABSPATH, true) ?></text>
                            <input name="wpopt-dir" type="text" value="wp-content/" <?= $disabled ?>>
                        </label>
                    </div>
                    <br>
                    <?php if (get_option('images.to.optimize')): ?>
                        <button class="button button-primary button-large"><?= __('Restart', 'wpopt') ?></button>
                        <button class="button button-primary button-large"><?= __('Resume', 'wpopt') ?></button>
                    <?php else: ?>
                        <button class="button button-primary button-large"><?= __('Start', 'wpopt') ?></button>
                    <?php endif; ?>
                    <button class="button button-primary button-large"><?= __('Pause', 'wpopt') ?></button>
                </block>

            </section>
            <aside class="wpopt">
                <section class="wpopt-box">
                    <h3><?= __('Optimization stats:', 'wpopt') ?></h3>
                    <ul class="wpopt">
                        <li>
                            <a href="https://translate.wordpress.org/projects/wp-plugins/wp-optimizer/">Help me translating</a>
                        </li>
                        <li>
                            <a href="https://wordpress.org/support/plugin/wp-optimizer/reviews/?filter=5">Leave a review</a>
                        </li>
                    </ul>
                </section>
            </aside>
        </section>
        <?php
        return ob_get_clean();
    }

    private function status($option, $default = false)
    {
        return WOSettings::get($option, $default);
    }

    public function render_admin_page()
    {
        ?>
        <section class="wpopt-wrap">
            <section class="wpopt-header"><h1>Media Optimizer</h1></section>
            <block class="wpopt">
                <?php
                echo wpopt_generateHTML_tabs_panels(array(

                        array(
                            'id'          => 'images-optimizer',
                            'tab-title'   => __('Images optimizer', 'wpopt'),
                            'panel-title' => __('Images Optimizer', 'wpopt'),
                            'callback'    => array($this, 'render_imagesOptimizer_Panel')

                        ),
                        array(
                            'id'          => 'media-cleaner',
                            'tab-title'   => __('Media cleaner', 'wpopt'),
                            'panel-title' => __('Media Cleaner', 'wpopt'),
                            'callback'    => array($this, 'render_mediaCleaner_Panel')
                        ),
                    )
                );
                ?>
            </block>
        </section>
        <?php
    }

    public function cron_handler($args = array())
    {
        $ImagesPerformer = WO_ImagesPerformer::getInstance($this->option());

        $images = WOOptions::get('media.todo', array());

        if (!empty($images)) {

            $images = $ImagesPerformer->optimize_images($images);

            WOOptions::update('media.todo', $images);

            if(WPOPT_DEBUG) {
                wpopt_write_log(wp_date("H:i:s"));
            }

            // schedule again cron
            if (!empty($images)) {
                WOCron::schedule_function(array($this, 'cron_handler'), $args, time() + MINUTE_IN_SECONDS);
            }
            else {
                WOCron::unschedule_function(array($this, 'cron_handler'), $args);
            }
        }
    }

    public function add_images_2_process($upload, $context)
    {
        if (!in_array($upload['type'], array_values($this->images_mime_types)))
            return $upload;

        if (empty($this->to_optimize_data)) {
            $this->to_optimize_data = WOOptions::get('media.todo', array());

            if (!$this->to_optimize_data)
                $this->to_optimize_data = array();
        }

        $this->to_optimize_data[] = $upload;

        return $upload;
    }

    public function add_thumbs_2_process($metadata, $attachment_id, $context)
    {
        $file_data = wp_check_filetype($metadata['file'], $this->images_mime_types);

        if (!$file_data['type'])
            return $metadata;

        if (empty($this->to_optimize_data)) {

            $this->to_optimize_data = WOOptions::get('media.todo', array());

            if (!$this->to_optimize_data)
                $this->to_optimize_data = array();
        }

        $wp_upload_dir = $this->cache_get('wp_upload_dir');

        if (!$wp_upload_dir) {
            $wp_upload_dir = wp_upload_dir();
            $this->cache_set('wp_upload_dir', $wp_upload_dir);
        }

        $tmp = explode(DIRECTORY_SEPARATOR, $metadata['file']);

        $this->to_optimize_data[] = array('file' => $wp_upload_dir['path'] . '/' . end($tmp), 'type' => $file_data['type']);

        foreach ($metadata['sizes'] as $thumb) {
            if (!in_array($thumb['mime-type'], $this->images_mime_types))
                continue;

            $this->to_optimize_data[] = array('file' => $wp_upload_dir['path'] . '/' . $thumb['file'], 'type' => $thumb['mime-type']);
        }

        return $metadata;
    }

    public function restricted_access($context = '')
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

}
