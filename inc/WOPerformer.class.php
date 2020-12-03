<?php


/**
 * Scope of this class is to provide some useful methods to
 * clean/optimize/repair database and optimize images.
 *
 * It's just an executive class.
 */
class WOPerformer
{
    /**
     * @var
     */
    private static $_instance;

    /**
     * @return WOPerformer
     */
    public static function getInstance()
    {
        if (!isset(self::$_instance)) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public function optimize_images($elements = array())
    {
        if (!is_array($elements)) {
            //$elements = date("Y", strtotime('last month')) . '/' . date('m', strtotime('last month'));
            return $this->opti_images_dir((string)$elements);
        }

        return $this->opti_imgages($elements);
    }

    private function opti_images_dir($path)
    {
        $uploads_dir = wp_upload_dir();
        $data = array();

        $root = realpath($uploads_dir['basedir'] . '/' . $path . '/');

        if (!$root) {
            return array();
        }

        //Going through directiry recursevely
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
            RecursiveIteratorIterator::CATCH_GET_CHILD // Ignore "Permission denied"
        );

        list($gifsicle_src, $optipng_src, $jpegtran_src, $webp_src) = $this->get_img_optimizers();

        foreach ($iter as $fileinfo) {

            if (!$fileinfo->isFile() or !$fileinfo->isWritable()) {
                $data .= (string)$fileinfo->getPathname() . ' NWFILE_OR_F <br>';
                continue;
            }

            set_time_limit(30);

            $file = (string)$fileinfo->getPathname();
            $file_esc = escapeshellarg($file);

            $orig_size = filesize($file);

            switch (strtolower($fileinfo->getExtension())) {

                case 'jpg':
                case 'jpeg':
                    exec($jpegtran_src . ' -copy none -optimize -progressive -outfile ' . $file_esc . ' ' . $file_esc);
                    break;

                case 'gif':
                    if (false) {
                        $tempfile = (string)$fileinfo->getPathname() . '.tmp'; //temporary GIF output
                        // run gifsicle on the GIF
                        exec($gifsicle_src . " -b -O3 --careful -o " . $file_esc . " " . $file_esc);
                        // retrieve the filesize of the temporary GIF
                        $new_size = filesize($tempfile);
                        // if the new GIF is smaller
                        if ($orig_size > $new_size && $new_size != 0) {
                            // replace the original with the optimized file
                            rename($tempfile, $file);
                            // if the optimization didn't produce a smaller GIF
                        }
                        else {
                            unlink($tempfile);
                        }
                    }
                    break;

                case 'png':
                    if (false) {
                        exec($optipng_src . " -o7 -quiet -strip all " . $file_esc);
                    }
                    break;
            }

            $data[] = array('name' => $file_esc);

            usleep(30000);
        }

        return $data;
    }

    private function get_img_optimizers()
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

    private function opti_imgages($images)
    {
        $data = array();

        list($gifsicle_src, $optipng_src, $jpegtran_src, $webp_src) = $this->get_img_optimizers();

        foreach ($images as $image_data) {

            $file = $image_data['file'];

            if (!file_exists($file))
                continue;

            set_time_limit(30);

            $file_esc = escapeshellarg($file);

            $orig_size = filesize($file);

            switch ($image_data['type']) {

                case 'image/jpg':
                case 'image/jpeg':
                case 'image/pjpeg':
                    exec($jpegtran_src . ' -copy none -optimize -progressive -outfile ' . $file_esc . ' ' . $file_esc);
                    break;

                case 'image/gif':
                    if (false) {
                        $tempfile = pathinfo($file, PATHINFO_DIRNAME) . '.tmp_' . time(); //temporary GIF output
                        // run gifsicle on the GIF
                        exec($gifsicle_src . " -b -O3 --careful -o " . $file_esc . " " . $file_esc);
                        // retrieve the filesize of the temporary GIF
                        $new_size = filesize($tempfile);
                        // if the new GIF is smaller
                        if ($orig_size > $new_size && $new_size != 0) {
                            // replace the original with the optimized file
                            rename($tempfile, $file);
                            // if the optimization didn't produce a smaller GIF
                        }
                        else {
                            unlink($tempfile);
                        }
                    }
                    break;

                case 'image/png':
                    if (false) {
                        exec($optipng_src . " -o7 -quiet -strip all " . $file_esc);
                    }
                    break;
            }

            usleep(30000);

            $data[] = array('file_name' => $file_esc, 'mem_before' => $orig_size, 'mem_after' => filesize($file));
        }

        return $data;
    }

    public function clear_orphaned_images($directory = '')
    {
        if (empty($directory))
            $directory = date("Y", strtotime('last month')) . '/' . date('m', strtotime('last month'));

        //pay attention that causes heavy cpu load
        $this->clean_uploads_from_nonattachments((string)$directory);

        return '';
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


}
