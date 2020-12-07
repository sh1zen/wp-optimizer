<?php

/**
 *  Module for images optimization handling
 */
class WOMod_Images extends WO_Module
{
    public $scopes = array('autoload', 'cron');

    private $mime_types;

    private $to_optimize_data;

    public function __construct()
    {
        require_once __DIR__ . '/images/wo_imagesperformer.class.php';

        $this->mime_types = array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'gif'          => 'image/gif',
            'png'          => 'image/png',
            'ico'          => 'image/x-icon',
            'pjpeg'        => 'image/pjpeg'
        );

        $default_cron = array(
            'active' => false
        );

        $this->to_optimize_data = array();

        parent::__construct(
            array(
                'cron_settings' => $default_cron
            )
        );

        if (WOSettings::check($this->cron_settings, 'active')) {

            add_filter('wp_handle_upload', array($this, 'add_images_2_process'), 10, 2);
            add_filter('wp_generate_attachment_metadata', array($this, 'add_thumbs_2_process'), 10, 3);

            add_action('shutdown', array($this, 'shutdown'));
        }
    }

    public function cron_validate_settings($valid, $input)
    {
        $valid['images'] = array(
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
            update_option('wpopt-imgs--todo', $this->to_optimize_data, false);
        }
    }

    public function cron_handler()
    {

    }

    public function add_images_2_process($upload, $context)
    {
        if (!in_array($upload['type'], array_values($this->mime_types)))
            return $upload;

        if (empty($this->to_optimize_data)) {
            $this->to_optimize_data = get_option('wpopt-imgs--todo');

            if (!$this->to_optimize_data)
                $this->to_optimize_data = array();
        }

        $this->to_optimize_data[] = $upload;

        return $upload;
    }

    public function add_thumbs_2_process($metadata, $attachment_id, $context)
    {
        $file_data = wp_check_filetype($metadata['file'], $this->mime_types);

        if (!$file_data['type'])
            return $metadata;

        if (empty($this->to_optimize_data)) {

            $this->to_optimize_data = get_option('wpopt-imgs--todo');

            if (!$this->to_optimize_data)
                $this->to_optimize_data = array();
        }

        $wp_upload_dir = WOCache::getInstance()->get_cache('wp_upload_dir');

        if (!$wp_upload_dir) {
            $wp_upload_dir = wp_upload_dir();
            WOCache::getInstance()->set_cache('wp_upload_dir', $wp_upload_dir);
        }

        $tmp = explode(DIRECTORY_SEPARATOR, $metadata['file']);

        $this->to_optimize_data[] = array('file' => $wp_upload_dir['path'] . '/' . end($tmp), 'type' => $file_data['type']);

        foreach ($metadata['sizes'] as $thumb) {
            if (!in_array($thumb['mime-type'], $this->mime_types))
                continue;

            $this->to_optimize_data[] = array('file' => $wp_upload_dir['path'] . '/' . $thumb['file'], 'type' => $thumb['mime-type']);
        }

        return $metadata;
    }

    protected function restricted_access($context = '')
    {
        switch ($context) {

            case 'settings':
            case 'render-admin':
                return !current_user_can('administrator');

            default:
                return false;
        }
    }
}
