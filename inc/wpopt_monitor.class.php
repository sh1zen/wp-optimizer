<?php

/**
 * Used to monitor specific hooks to enable on-demand optimizations
 */
class wpopt_monitor
{
    private $data;
    private $cache;

    public function __construct()
    {
        $this->data = wpopt::getInstance()->data;
        $this->cache = wpopt_plcache::getInstance();

        add_filter('wp_handle_upload', array($this, 'add_images_2_process'), 10, 2);
        add_filter('wp_generate_attachment_metadata', array($this, 'add_images_2_process_thumbs'), 10, 3);
    }

    public function add_images_2_process($upload, $context)
    {
        if (!in_array($upload['type'], array_values($this->data['mime-types'])))
            return $upload;

        $data = get_option('wpopt-imgs--todo');

        if (!$data)
            $data = array();

        $data[] = $upload;

        update_option('wpopt-imgs--todo', $data, false);

        return $upload;
    }

    public function add_images_2_process_thumbs($metadata, $attachment_id, $context)
    {
        $file_data = wp_check_filetype($metadata['file'], $this->data['mime-types']);

        if (!$file_data['type'])
            return $metadata;

        $data = get_option('wpopt-imgs--todo');

        if (!$this->cache->upload_dir)
            $this->cache->upload_dir = wp_upload_dir();

        if (!$data)
            $data = array();

        $tmp = explode(DIRECTORY_SEPARATOR, $metadata['file']);

        $data[] = array('file' => $this->cache->upload_dir['path'] . '/' . end($tmp), 'type' => $file_data['type']);

        foreach ($metadata['sizes'] as $thumb) {
            if (!in_array($thumb['mime-type'], $this->data['mime-types']))
                continue;

            $data[] = array('file' => $this->cache->upload_dir['path'] . '/' . $thumb['file'], 'type' => $thumb['mime-type']);
        }

        update_option('wpopt-imgs--todo', $data, false);

        return $metadata;
    }
}