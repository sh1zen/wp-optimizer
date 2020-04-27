<?php

/**
 * Used to monitor specific hooks to enable on-demand optimizations.
 *
 */
class WOMonitor
{
    private $mime_types;
    private $cache;

    public function __construct()
    {
        $this->mime_types = WOSettings::getInstance()->get_settings('mime-types');

        $this->cache = WOPlCache::getInstance();

        add_filter('wp_handle_upload', array($this, 'add_images_2_process'), 10, 2);
        add_filter('wp_generate_attachment_metadata', array($this, 'add_images_2_process_thumbs'), 10, 3);
    }

    public function add_images_2_process($upload, $context)
    {
        if (!in_array($upload['type'], array_values($this->mime_types)))
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
        $file_data = wp_check_filetype($metadata['file'], $this->mime_types);

        if (!$file_data['type'])
            return $metadata;

        $data = get_option('wpopt-imgs--todo');

        $upload_dir = $this->cache->get_cache('upload_dir');

        if (!$upload_dir) {
            $upload_dir = wp_upload_dir();
            $this->cache->set_cache('upload_dir', $upload_dir);
        }

        if (!$data)
            $data = array();

        $tmp = explode(DIRECTORY_SEPARATOR, $metadata['file']);

        $data[] = array('file' => $upload_dir['path'] . '/' . end($tmp), 'type' => $file_data['type']);

        foreach ($metadata['sizes'] as $thumb) {
            if (!in_array($thumb['mime-type'], $this->mime_types))
                continue;

            $data[] = array('file' => $upload_dir['path'] . '/' . $thumb['file'], 'type' => $thumb['mime-type']);
        }

        update_option('wpopt-imgs--todo', $data, false);

        return $metadata;
    }
}