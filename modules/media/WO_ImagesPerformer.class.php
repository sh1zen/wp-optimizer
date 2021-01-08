<?php

class WO_ImagesPerformer
{
    private static $_instance;

    private static $optimizers;

    private $report;

    private $settings;

    private function __construct($settings = array())
    {
        $this->settings = array_merge(array(
            'use_imagick' => false,

        ), $settings);

        $this->report = WOReport::getInstance();

        self::$optimizers = self::get_img_optimizers();
    }


    private static function get_img_optimizers()
    {
        $optimizers = array();

        if (PHP_OS === 'WINNT') {
            $optimizers['gifsicle_src'] = escapeshellarg(WPOPT_ABSPATH . 'inc/executables/' . 'gifsicle.exe');
            $optimizers['optipng_src'] = escapeshellarg(WPOPT_ABSPATH . 'inc/executables/' . 'optipng.exe');
            $optimizers['jpegtran_src'] = escapeshellarg(WPOPT_ABSPATH . 'inc/executables/' . 'jpegtran.exe');
            $optimizers['webp_src'] = escapeshellarg(WPOPT_ABSPATH . 'inc/executables/' . 'cwebp.exe');
        }
        elseif (PHP_OS === 'Darwin') {
            $optimizers['gifsicle_src'] = escapeshellarg(WPOPT_ABSPATH . 'inc/executables/' . 'gifsicle-mac');
            $optimizers['optipng_src'] = escapeshellarg(WPOPT_ABSPATH . 'inc/executables/' . 'optipng-mac');
            $optimizers['jpegtran_src'] = escapeshellarg(WPOPT_ABSPATH . 'inc/executables/' . 'jpegtran-mac');
            $optimizers['webp_src'] = escapeshellarg(WPOPT_ABSPATH . 'inc/executables/' . 'cwebp-mac14');
        }
        elseif (PHP_OS === 'SunOS') {
            $optimizers['gifsicle_src'] = escapeshellarg(WPOPT_ABSPATH . 'inc/executables/' . 'gifsicle-sol');
            $optimizers['optipng_src'] = escapeshellarg(WPOPT_ABSPATH . 'inc/executables/' . 'optipng-sol');
            $optimizers['jpegtran_src'] = escapeshellarg(WPOPT_ABSPATH . 'inc/executables/' . 'jpegtran-sol');
            $optimizers['webp_src'] = escapeshellarg(WPOPT_ABSPATH . 'inc/executables/' . 'cwebp-sol');
        }
        elseif (PHP_OS === 'FreeBSD') {
            $optimizers['gifsicle_src'] = escapeshellarg(WPOPT_ABSPATH . 'inc/executables/' . 'gifsicle-fbsd');
            $optimizers['optipng_src'] = escapeshellarg(WPOPT_ABSPATH . 'inc/executables/' . 'optipng-fbsd');
            $optimizers['jpegtran_src'] = escapeshellarg(WPOPT_ABSPATH . 'inc/executables/' . 'jpegtran-fbsd');
            $optimizers['webp_src'] = escapeshellarg(WPOPT_ABSPATH . 'inc/executables/' . 'cwebp-fbsd');
        }
        elseif (PHP_OS === 'Linux') {
            $optimizers['gifsicle_src'] = escapeshellarg(WPOPT_ABSPATH . 'inc/executables/' . 'gifsicle-linux');
            $optimizers['optipng_src'] = escapeshellarg(WPOPT_ABSPATH . 'inc/executables/' . 'optipng-linux');
            $optimizers['jpegtran_src'] = escapeshellarg(WPOPT_ABSPATH . 'inc/executables/' . 'jpegtran-linux');
            $optimizers['webp_src'] = escapeshellarg(WPOPT_ABSPATH . 'inc/executables/' . 'cwebp-linux');
        }
        return $optimizers;
    }

    /**
     * @param array $settings
     * @return WO_ImagesPerformer
     */
    public static function getInstance($settings = array())
    {
        if (!isset(self::$_instance)) {
            self::$_instance = new self($settings);
        }

        return self::$_instance;
    }

    public function clear_orphaned_images($directory = '')
    {
        if (empty($directory))
            $directory = date("Y", strtotime('last month')) . '/' . date('m', strtotime('last month'));

        //pay attention that causes heavy cpu load
        $this->clean_uploads_from_nonattachments((string)$directory);
    }

    private function clean_uploads_from_nonattachments($year)
    {
        global $wpdb;

        $uploads_dir = wp_upload_dir();

        //You may want to take it by bites if your uploads is rather large (over 5 gb for example)
        $root = realpath($uploads_dir['basedir'] . '/' . $year . '/');

        if (!$root) {
            return;
        }

        echo '<br>Selected path: ' . $root . '<br>';

        //Going through directiry recursevely
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
            RecursiveIteratorIterator::CATCH_GET_CHILD // Ignore "Permission denied"
        );

        foreach ($iter as $fileinfo) {

            set_time_limit(15);
            //get files only
            if ($fileinfo->isFile()) {
                $image = $fileinfo->getBasename();

                $res = $wpdb->query("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value LIKE '%" . ($image) . "%';");

                if (!$res) {
                    usleep(10000);
                    $res = $wpdb->query("SELECT ID FROM {$wpdb->posts} WHERE guid LIKE '%" . ($image) . "%';");
                }

                //Not found - then delete file
                if (!$res) {
                    unlink($fileinfo->getPathname());
                }
            }
            usleep(10000);
        }
    }

    private function opti_images_dir($path)
    {
        $uploads_dir = wp_upload_dir();

        $root = realpath($uploads_dir['basedir'] . '/' . $path . '/');

        if (!$root) {
            return;
        }

        //Going through directiry recursevely
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
            RecursiveIteratorIterator::CATCH_GET_CHILD // Ignore "Permission denied"
        );

        $files_data = array();

        foreach ($iter as $fileinfo) {

            if (!$fileinfo->isFile()) {
                continue;
            }

            if (!$fileinfo->isWritable()) {
                $this->report->add('images', escapeshellarg($fileinfo->getPathname()), 'error', array('message' => __('Is not writable.', 'wpopt')));
                continue;
            }

            set_time_limit(30);

            $file = $fileinfo->getPathname();

            $wp_filetype = wp_check_filetype($file, wp_get_mime_types());
            $ext = $wp_filetype['ext'];
            $mime_type = $wp_filetype['type'];

            if ($ext and $mime_type) {
                $files_data[] = array(
                    'file' => $file,
                    'type' => $mime_type
                );
            }
            else {
                $this->report->add('images', escapeshellarg($fileinfo->getPathname()), 'error', array('message' => __('Invalid extension.', 'wpopt')));
                continue;
            }

            if (count($files_data) > WPOPT_MIN_IMAGES_TO_OPTIMIZE) {
                $this->optimize_images($files_data);
                $files_data = array();
            }
        }

        $this->optimize_images($files_data);
    }

    public function optimize_images($images)
    {
        if (extension_loaded('imagick') and WOSettings::check($this->settings, 'use_imagick')) {
            $this->opti_images_imagick($images);
        }

        sleep(8);

        while ($image_data = array_pop($images)) {

            $file = $image_data['file'];

            if (!file_exists($file))
                continue;

            if (!WO_UtilEnv::safe_time_limit(0.5, 60)) {

                // reinsert popped image
                array_push($images, $image_data);
                break;
            }

            $file_esc = escapeshellarg($file);

            $orig_size = filesize($file);

            switch ($image_data['type']) {

                case 'image/jpg':
                case 'image/jpeg':
                case 'image/pjpeg':
                    exec(self::$optimizers['jpegtran_src'] . ' -copy none -optimize -progressive -outfile ' . $file_esc . ' ' . $file_esc);
                    break;

                case 'image/gif':
                    $tempfile = pathinfo($file, PATHINFO_DIRNAME) . '.tmp_' . time(); //temporary GIF output
                    // run gifsicle on the GIF
                    exec(self::$optimizers['gifsicle_src'] . " -b -O3 --careful -o " . $file_esc . " " . $file_esc);
                    // retrieve the filesize of the temporary GIF
                    $new_size = filesize($tempfile);
                    // if the new GIF is smaller
                    if ($orig_size > $new_size and $new_size != 0) {
                        // replace the original with the optimized file
                        rename($tempfile, $file);
                        // if the optimization didn't produce a smaller GIF
                    }
                    else {
                        unlink($tempfile);
                    }
                    break;

                case 'image/png':
                    if (false) {
                        exec(self::$optimizers['optipng_src'] . " -o7 -quiet -strip all " . $file_esc);
                    }
                    break;

                case 'image/webp':
                    if (false) {
                        //todo see how works webp_src
                        exec(self::$optimizers['webp_src'] . "  " . $file_esc);
                    }
                    break;

                default:
                    continue 2;
            }

            usleep(3000);
            clearstatcache();

            $this->report->add('images', $file_esc, 'success', array('mem_before' => $orig_size, 'mem_after' => filesize($file)));
        }

        return $images;
    }


}
