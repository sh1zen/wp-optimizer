<?php

class wpopt_setup
{
    public $option_name = 'wp-opt';

    private $data = array(
        'clear-time'  => '05:00:00',
        'active'      => true,
        'images'      => true,
        'database'    => false,
        'save_report' => false,
        'lock'        => false,
        'mime-types'  => array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'gif'          => 'image/gif',
            'png'          => 'image/png',
            'ico'          => 'image/x-icon',
            'pjpeg'        => 'image/pjpeg'
        )
    );

    private $menu_page;

    public function __construct($environment = '')
    {
        if ($environment == 'cron')
            return;

        $this->menu_page = new wpopt_menu_page();

        // Admin sub-menu
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_menu', array($this, 'add_plugin_page'));

        // Listen for the activate event
        register_activation_hook(WP_OPT_FILE, array($this, 'activate'));

        // Deactivation plugin
        register_deactivation_hook(WP_OPT_FILE, array($this, 'deactivate'));

        add_filter('wp_handle_upload', array($this, 'add_images_2_process'), 10, 2);
        add_filter('wp_generate_attachment_metadata', array($this, 'add_images_2_process_thumbnails'), 10, 3);

        // cron--job
        add_action('wp-opt-cron', array($this, 'cron'));
    }

    public function cron()
    {
        wpopt_do_cron(get_option($this->option_name));
    }

    public function add_images_2_process($upload, $context)
    {
        if (!in_array($upload['type'], array_values($this->data['mime-types'])))
            return $upload;

        $data = get_option('wp-opt--todo');

        if (!$data or empty($data))
            $data = array();
        else
            $data = json_decode($data, true);

        $data[] = $upload;

        update_option('wp-opt--todo', json_encode($data), false);

        return $upload;
    }

    public function add_images_2_process_thumbnails($metadata, $attachment_id, $context)
    {
        $file_data = wp_check_filetype($metadata['file'], $this->data['mime-types']);

        if (!$file_data['type'])
            return $metadata;

        $data = get_option('wp-opt--todo');
        $upload_dir = wp_upload_dir();

        if (!$data or empty($data))
            $data = array();
        else
            $data = json_decode($data, true);

        $tmp = explode(DIRECTORY_SEPARATOR, $metadata['file']);

        $data[] = array('file' => $upload_dir['path'] . '/' . end($tmp), 'type' => $file_data['type']);

        foreach ($metadata['sizes'] as $thumb) {
            if (!in_array($thumb['mime-type'], $this->data['mime-types']))
                continue;

            $data[] = array('file' => $upload_dir['path'] . '/' . $thumb['file'], 'type' => $thumb['mime-type']);
        }

        update_option('wp-opt--todo', json_encode($data), false);

        return $metadata;
    }

    public function add_plugin_page()
    {
        add_menu_page(
            'WP Optimizer',
            'WP Optimizer',
            'manage_options',
            'WP Optimizer',
            array($this->menu_page, 'render_main'),
            'dashicons-admin-site'
        );
    }

    public function admin_init()
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        register_setting('wp-opt', $this->option_name, array($this, 'validate'));
    }

    public function activate()
    {
        if (!get_option($this->option_name, false))
            update_option($this->option_name, $this->data);

        wp_clear_scheduled_hook('wp-opt-cron');

        if (!wp_next_scheduled('wp-opt-cron')) {
            wp_schedule_event(strtotime($this->data['clear-time']), 'daily', 'wp-opt-cron');
        }
    }

    public function deactivate()
    {
        wp_clear_scheduled_hook('wp-opt-cron');
    }

    public function validate($input)
    {
        $valid = (array)get_option($this->option_name);


        if ($input['change'] == 'settings') {

            $valid['clear-time'] = sanitize_text_field($input['clear-time']);

            wp_clear_scheduled_hook('wp-opt-cron');
            wp_schedule_event(strtotime($valid['clear-time']), 'daily', 'wp-opt-cron');

            $valid['active'] = (bool)sanitize_text_field($input['active']);
            $valid['images'] = (bool)sanitize_text_field($input['images']);
            $valid['database'] = (bool)sanitize_text_field($input['database']);
            $valid['save_report'] = (bool)sanitize_text_field($input['save_report']);
        }

        return $valid;
    }
}
