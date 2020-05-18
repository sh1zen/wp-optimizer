<?php

if (!defined('ABSPATH'))
    exit();

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

    public static function execute_sql($sql_query)
    {
        global $wpdb;

        if (preg_match("/LOAD_FILE/i", $sql_query))
            return false;

        if (preg_match("/^\\s*(select|drop|show|grant) /i", $sql_query))
            return false;

        if (preg_match("/^\\s*(insert|update|replace|delete|create|alter) /i", $sql_query)) {
            return $wpdb->query($sql_query);
        }

        return false;
    }

    /**
     * Does the sweeping/cleaning up
     *
     * @param string $name Sweep name.
     * @param array $args
     * @return string Processed message
     * @since 1.0.0
     *
     * @access public
     */
    public static function sweep($name, $args = array())
    {
        global $wpdb;

        $args = wp_parse_args($args, array(
            'excluded_taxonomies' => array(),
        ));

        $query = array();

        switch ($name) {

            case 'revisions':
                $query = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_type = %s", 'revision'));
                if ($query) {
                    foreach ($query as $id) {
                        wp_delete_post_revision((int)$id);
                    }
                }
                break;

            case 'auto_drafts':
            case 'deleted_posts':

                if ($name == "auto_drafts")
                    $query = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_status = %s", 'auto-draft'));
                else
                    $query = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_status = %s", 'trash'));

                if ($query) {
                    foreach ($query as $id) {
                        wp_delete_post((int)$id, true);
                    }
                }
                break;

            case 'unapproved_comments':
            case 'spam_comments':
            case 'deleted_comments':

                if ($name == 'unapproved_comments')
                    $query = $wpdb->get_col($wpdb->prepare("SELECT comment_ID FROM $wpdb->comments WHERE comment_approved = %s", '0'));
                elseif ($name == 'deleted_comments')
                    $query = $wpdb->get_col($wpdb->prepare("SELECT comment_ID FROM $wpdb->comments WHERE (comment_approved = %s OR comment_approved = %s)", 'trash', 'post-trashed'));
                else
                    $query = $wpdb->get_col($wpdb->prepare("SELECT comment_ID FROM $wpdb->comments WHERE comment_approved = %s", 'spam'));


                if ($query) {
                    foreach ($query as $id) {
                        wp_delete_comment((int)$id, true);
                    }
                }
                break;

            case 'transient_options':
                $query = $wpdb->get_col($wpdb->prepare("SELECT option_id FROM $wpdb->options WHERE option_name LIKE(%s)", '%_transient_%'));
                if (!empty($query)) {

                    $wpdb->query("DELETE FROM $wpdb->options WHERE option_id IN (" . implode(', ', $query) . ") ");
                }
                break;

            case 'orphan_postmeta':
            case 'oembed_postmeta':

                if ($name === 'orphan_postmeta')
                    $query = $wpdb->get_col("SELECT meta_id FROM $wpdb->postmeta WHERE post_id NOT IN (SELECT ID FROM $wpdb->posts)");
                else
                    $query = $wpdb->get_col($wpdb->prepare("SELECT meta_id FROM $wpdb->postmeta WHERE meta_key LIKE (%s)", '%_oembed_%'));

                if (!empty($query)) {

                    $wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_id IN (" . implode(', ', $query) . ")");

                }
                break;

            case 'orphan_commentmeta':
                $query = $wpdb->get_col("SELECT meta_id FROM $wpdb->commentmeta WHERE comment_id NOT IN (SELECT comment_ID FROM $wpdb->comments)");

                if (!empty($query)) {

                    $wpdb->query("DELETE FROM $wpdb->commentmeta WHERE meta_id IN (" . implode(', ', $query) . ") ");
                }
                break;

            case 'orphan_usermeta':
                $query = $wpdb->get_col("SELECT umeta_id FROM $wpdb->usermeta WHERE user_id NOT IN (SELECT ID FROM $wpdb->users)");
                if (!empty($query)) {

                    $wpdb->query("DELETE FROM $wpdb->usermeta WHERE umeta_id IN (" . implode(', ', $query) . ")");
                }
                break;

            case 'orphan_termmeta':
                $query = $wpdb->get_col("SELECT meta_id FROM $wpdb->termmeta WHERE term_id NOT IN (SELECT term_id FROM $wpdb->terms)");
                if (!empty($query)) {

                    $wpdb->query("DELETE FROM $wpdb->termmeta WHERE meta_id IN (" . implode(', ', $query) . ") ");
                }
                break;

            case 'orphan_term_relationships':
                $query = $wpdb->get_results("SELECT tr.object_id, tr.term_taxonomy_id, tt.term_id, tt.taxonomy FROM $wpdb->term_relationships AS tr INNER JOIN $wpdb->term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy NOT IN ('" . implode('\',\'', $args['excluded_taxonomies']) . "') AND tr.object_id NOT IN (SELECT ID FROM $wpdb->posts)"); // WPCS: unprepared SQL ok.
                if ($query) {
                    foreach ($query as $tax) {
                        $wp_remove_object_terms = wp_remove_object_terms((int)$tax->object_id, (int)$tax->term_id, $tax->taxonomy);
                        if (true !== $wp_remove_object_terms) {
                            $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->term_relationships WHERE object_id = %d AND term_taxonomy_id = %d", $tax->object_id, $tax->term_taxonomy_id));
                        }
                    }
                }
                break;

            case 'unused_terms':
                $query = $wpdb->get_results($wpdb->prepare("SELECT tt.term_taxonomy_id, t.term_id, tt.taxonomy FROM $wpdb->terms AS t INNER JOIN $wpdb->term_taxonomy AS tt ON t.term_id = tt.term_id WHERE tt.count = %d AND t.term_id", 0)); // WPCS: unprepared SQL ok.
                if ($query) {
                    $check_wp_terms = false;
                    foreach ($query as $tax) {
                        if (taxonomy_exists($tax->taxonomy)) {
                            wp_delete_term((int)$tax->term_id, $tax->taxonomy);
                        }
                        else {
                            $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->term_taxonomy WHERE term_taxonomy_id = %d", (int)$tax->term_taxonomy_id));
                            $check_wp_terms = true;
                        }
                    }
                    // We need this for invalid taxonomies.
                    if ($check_wp_terms) {
                        $wpdb->get_results("DELETE FROM $wpdb->terms WHERE term_id NOT IN (SELECT term_id FROM $wpdb->term_taxonomy)");
                    }
                }
                break;

            case 'duplicated_postmeta':
                $query = $wpdb->get_results($wpdb->prepare("SELECT GROUP_CONCAT(meta_id) AS ids, post_id, COUNT(*) AS count FROM $wpdb->postmeta GROUP BY post_id, meta_key, meta_value HAVING count > %d", 1));
                if ($query) {
                    foreach ($query as $meta) {
                        $ids = array_map('intval', explode(',', $meta->ids));
                        array_pop($ids);
                        $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->postmeta WHERE meta_id IN (" . implode(',', $ids) . ') AND post_id = %d', (int)$meta->post_id)); // WPCS: unprepared SQL ok.
                    }
                }
                break;

            case 'duplicated_commentmeta':
                $query = $wpdb->get_results($wpdb->prepare("SELECT GROUP_CONCAT(meta_id) AS ids, comment_id, COUNT(*) AS count FROM $wpdb->commentmeta GROUP BY comment_id, meta_key, meta_value HAVING count > %d", 1));
                if ($query) {
                    foreach ($query as $meta) {
                        $ids = array_map('intval', explode(',', $meta->ids));
                        array_pop($ids);
                        $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->commentmeta WHERE meta_id IN (" . implode(',', $ids) . ') AND comment_id = %d', (int)$meta->comment_id)); // WPCS: unprepared SQL ok.
                    }
                }
                break;

            case 'duplicated_usermeta':
                $query = $wpdb->get_results($wpdb->prepare("SELECT GROUP_CONCAT(umeta_id) AS ids, user_id, COUNT(*) AS count FROM $wpdb->usermeta GROUP BY user_id, meta_key, meta_value HAVING count > %d", 1));
                if ($query) {
                    foreach ($query as $meta) {
                        $ids = array_map('intval', explode(',', $meta->ids));
                        array_pop($ids);
                        $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->usermeta WHERE umeta_id IN (" . implode(',', $ids) . ') AND user_id = %d', (int)$meta->user_id)); // WPCS: unprepared SQL ok.
                    }
                }
                break;

            case 'duplicated_termmeta':
                $query = $wpdb->get_results($wpdb->prepare("SELECT GROUP_CONCAT(meta_id) AS ids, term_id, COUNT(*) AS count FROM $wpdb->termmeta GROUP BY term_id, meta_key, meta_value HAVING count > %d", 1));
                if ($query) {
                    foreach ($query as $meta) {
                        $ids = array_map('intval', explode(',', $meta->ids));
                        array_pop($ids);
                        $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->termmeta WHERE meta_id IN (" . implode(',', $ids) . ') AND term_id = %d', (int)$meta->term_id)); // WPCS: unprepared SQL ok.
                    }
                }
                break;
        }

        return empty($query) ? 0 : number_format_i18n(count($query));
    }

    /**
     * Manage database tables
     *
     * @param $action
     * @param array $tables
     * @return bool|int
     */
    public static function manage_tables($action, $tables = array())
    {
        global $wpdb;

        if (empty($tables)) {
            return false;
            //$tables = $wpdb->get_col('SHOW TABLES');
        }

        $succeeded = 0;

        switch ($action) {

            case 'myisam':

                foreach ($tables as $table) {
                    if ($wpdb->query("ALTER TABLE {$table} ENGINE = MyISAM"))
                        $succeeded++;
                }
                break;

            case 'innodb':

                foreach ($tables as $table) {
                    if ($wpdb->query("ALTER TABLE {$table} ENGINE = InnoDB"))
                        $succeeded++;
                }
                break;

            case 'delete':

                foreach ($tables as $table) {
                    if ($wpdb->query("DROP TABLE {$table}"))
                        $succeeded++;
                }
                break;

            case 'optimize':

                foreach ($tables as $table) {
                    if ($wpdb->query("OPTIMIZE TABLE {$table}"))
                        $succeeded++;
                }
                break;

            case 'empty':

                foreach ($tables as $table) {
                    if ($wpdb->query("TRUNCATE TABLE {$table}"))
                        $succeeded++;
                }
                break;

            case 'repair':

                foreach ($tables as $table) {
                    $query_result = $wpdb->get_results("REPAIR TABLE " . $table);
                    foreach ($query_result as $row) {
                        if ($row->Msg_type == 'error') {
                            if (!preg_match('/corrupt/i', $row->Msg_text)) {
                                $succeeded++;
                            }
                        }
                    }
                }
                break;
        }

        return $succeeded;
    }

    public static function restore_db($backup)
    {
        global $wpdb;

        //$_wpdb = new wpdb('root', '', 'wp_test', '127.0.0.1');

        $tables = $wpdb->get_col('SHOW TABLES');

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE {$table}");
        }

        $query = '';

        $lines = file($backup);

        foreach ($lines as $line) {

            if (substr($line, 0, 2) == '--' || $line == '')
                continue;

            $query .= $line;

            if (substr(trim($line), -1, 1) == ';') {

                $wpdb->query($query);

                $query = '';
            }

            set_time_limit(30);
        }
        return true;
    }

    public static function queryDump_db($SQLfilename, $_excluded_tables = array(), $row_number = 500)
    {
        global $wpdb;

        $tables = $wpdb->get_col('SHOW TABLES');
        $output = '';

        if (file_exists($SQLfilename))
            unlink($SQLfilename);

        $file_descriptor = fopen($SQLfilename, 'w');

        $available_memory = wpopt_size2bytes(@ini_get('memory_limit'));

        foreach ($tables as $table) {

            set_time_limit(60);

            if (empty($_excluded_tables) or !(in_array($table, $_excluded_tables))) {

                /**
                 * Rules for CREATE TABLE
                 */
                $result = $wpdb->get_row('SHOW CREATE TABLE ' . $table, ARRAY_N);
                $output .= $result[1] . ";" . PHP_EOL . PHP_EOL;

                $iter = 0;

                while ($result = $wpdb->get_results("SELECT * FROM {$table} LIMIT {$row_number} OFFSET " . ($iter++ * $row_number), ARRAY_N)) {

                    $nres = count($result) - 1;

                    $output .= 'INSERT INTO ' . $table . ' VALUES';
                    foreach ($result as $n => $row) {

                        $row = array_map(array($wpdb, '_real_escape'), $row);

                        $output .= '( "' . implode('", "', $row) . '" )';

                        if ($n < $nres) $output .= ', ';
                    }

                    $output .= ';' . PHP_EOL;

                    if ($available_memory - memory_get_usage() < 10485760) {
                        fwrite($file_descriptor, $output);
                        $output = '';
                        $wpdb->flush();
                    }

                }
                fwrite($file_descriptor, $output);
                $output = '';
                $wpdb->flush();
            }
        }

        $wpdb->flush();

        fclose($file_descriptor);

        return true;
    }

    public static function mysqlDump_db($SQLfilename, $_excluded_tables = array(), $mysqldump_locations = '')
    {
        set_time_limit(120);

        $host = explode(':', DB_HOST);

        $host = reset($host);
        $port = strpos(DB_HOST, ':') ? end($host) : '';

        // Path to the mysqldump executable
        $cmd = escapeshellarg(wpopt_get_mysqlDump_command_path($mysqldump_locations));

        // We don't want to create a new DB
        $cmd .= ' --no-create-db --hex-blob';

        // Username
        $cmd .= ' -u ' . escapeshellarg(DB_USER);

        // Don't pass the password if it's blank
        if (DB_PASSWORD)
            $cmd .= ' -p' . escapeshellarg(DB_PASSWORD);

        // Set the host
        $cmd .= ' -h ' . escapeshellarg($host);

        // Set the port if it was set
        if (!empty($port) && is_numeric($port))
            $cmd .= ' -P ' . $port;

        // The file we're saving too
        $cmd .= ' -r ' . escapeshellarg($SQLfilename);

        if (!empty($_excluded_tables)) {
            $cmd .= implode(' --ignore-table=' . DB_NAME . '.', $_excluded_tables);
        }

        // The database we're dumping
        $cmd .= ' ' . escapeshellarg(DB_NAME);

        // Pipe STDERR to STDOUT
        $cmd .= ' 2>&1';

        $stderr = shell_exec($cmd);

        // Skip the new password warning that is output in mysql > 5.6
        if (trim($stderr) === 'Warning: Using a password on the command line interface can be insecure.') {
            $stderr = '';
        }

        if ($stderr) {
            wpopt_write_log($stderr);

            if (file_exists($SQLfilename))
                unlink($SQLfilename);

            return false;
        }

        // If we have an empty file delete it
        if (@filesize($SQLfilename) === 0) {
            unlink($SQLfilename);
            return false;
        }

        return true;
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
            $optimizers['gifsicle_src'] = escapeshellarg(WPOPT_ABSPATH . '/inc/executables/' . 'gifsicle.exe');
            $optimizers['optipng_src'] = escapeshellarg(WPOPT_ABSPATH . '/inc/executables/' . 'optipng.exe');
            $optimizers['jpegtran_src'] = escapeshellarg(WPOPT_ABSPATH . '/inc/executables/' . 'jpegtran.exe');
            $optimizers['webp_src'] = escapeshellarg(WPOPT_ABSPATH . '/inc/executables/' . 'cwebp.exe');
        }
        elseif (PHP_OS === 'Darwin') {
            $optimizers['gifsicle_src'] = escapeshellarg(WPOPT_ABSPATH . '/inc/executables/' . 'gifsicle-mac');
            $optimizers['optipng_src'] = escapeshellarg(WPOPT_ABSPATH . '/inc/executables/' . 'optipng-mac');
            $optimizers['jpegtran_src'] = escapeshellarg(WPOPT_ABSPATH . '/inc/executables/' . 'jpegtran-mac');
            $optimizers['webp_src'] = escapeshellarg(WPOPT_ABSPATH . '/inc/executables/' . 'cwebp-mac14');
        }
        elseif (PHP_OS === 'SunOS') {
            $optimizers['gifsicle_src'] = escapeshellarg(WPOPT_ABSPATH . '/inc/executables/' . 'gifsicle-sol');
            $optimizers['optipng_src'] = escapeshellarg(WPOPT_ABSPATH . '/inc/executables/' . 'optipng-sol');
            $optimizers['jpegtran_src'] = escapeshellarg(WPOPT_ABSPATH . '/inc/executables/' . 'jpegtran-sol');
            $optimizers['webp_src'] = escapeshellarg(WPOPT_ABSPATH . '/inc/executables/' . 'cwebp-sol');
        }
        elseif (PHP_OS === 'FreeBSD') {
            $optimizers['gifsicle_src'] = escapeshellarg(WPOPT_ABSPATH . '/inc/executables/' . 'gifsicle-fbsd');
            $optimizers['optipng_src'] = escapeshellarg(WPOPT_ABSPATH . '/inc/executables/' . 'optipng-fbsd');
            $optimizers['jpegtran_src'] = escapeshellarg(WPOPT_ABSPATH . '/inc/executables/' . 'jpegtran-fbsd');
            $optimizers['webp_src'] = escapeshellarg(WPOPT_ABSPATH . '/inc/executables/' . 'cwebp-fbsd');
        }
        elseif (PHP_OS === 'Linux') {
            $optimizers['gifsicle_src'] = escapeshellarg(WPOPT_ABSPATH . '/inc/executables/' . 'gifsicle-linux');
            $optimizers['optipng_src'] = escapeshellarg(WPOPT_ABSPATH . '/inc/executables/' . 'optipng-linux');
            $optimizers['jpegtran_src'] = escapeshellarg(WPOPT_ABSPATH . '/inc/executables/' . 'jpegtran-linux');
            $optimizers['webp_src'] = escapeshellarg(WPOPT_ABSPATH . '/inc/executables/' . 'cwebp-linux');
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

    public function clear_database_full()
    {
        global $wpdb;

        $results = 0;

        /*
         * Clear wp transients
         */
        $results += $wpdb->query("DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE '_transient_%';");
        $results += $wpdb->query("DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE '_site_transient_%';");

        /*
         * Delete posts and pages revisions and auto-draft
         */
        $results += $wpdb->query("DELETE FROM {$wpdb->prefix}posts WHERE post_type = 'revision';");
        $results += $wpdb->query("DELETE FROM {$wpdb->prefix}posts WHERE post_status = 'auto-draft';");

        /*
         * Delete posts in trash
         */
        $results += $wpdb->query("DELETE p FROM {$wpdb->prefix}posts p LEFT OUTER JOIN {$wpdb->prefix}postmeta pm ON (p.ID = pm.post_id) WHERE post_status = 'trash';");

        /*
        * Delete orphaned post attachments
        */
        $results += $wpdb->query("DELETE p1 FROM wp_posts p1 LEFT JOIN wp_posts p2 ON p1.post_parent = p2.ID WHERE p1.post_parent > 0 AND p1.post_type = 'attachment' AND p2.ID IS NULL");

        /*
        * Delete comments spam or not approved
        */
        $results += $wpdb->query("DELETE FROM {$wpdb->prefix}comments WHERE {$wpdb->prefix}comments.comment_approved = 'spam';");
        $results += $wpdb->query("DELETE from {$wpdb->prefix}comments WHERE comment_approved = '0';");

        /*
         * Delete orphaned comments
         */
        $results += $wpdb->query("DELETE FROM {$wpdb->prefix}commentmeta WHERE comment_id NOT IN (SELECT comment_id FROM {$wpdb->prefix}comments);");

        /*
         * Delete duplicated post meta
         */
        if (rand(0, 40) == 13) //do this only sometime
        {
            $results += $wpdb->query("DELETE t1 FROM {$wpdb->prefix}postmeta t1 INNER JOIN {$wpdb->prefix}postmeta t2 WHERE t1.meta_id < t2.meta_id AND t1.meta_key = t2.meta_key AND t1.meta_value = t2.meta_value AND t1.post_id = t2.post_id");
        }

        /*
         * Delete useless post meta
         */
        $results += $wpdb->query("DELETE FROM {$wpdb->prefix}postmeta WHERE meta_key = '_edit_lock';");
        $results += $wpdb->query("DELETE FROM {$wpdb->prefix}postmeta WHERE meta_key = '_edit_last';");
        $results += $wpdb->query("DELETE FROM {$wpdb->prefix}postmeta WHERE meta_key = '_gform-entry-id';");
        $results += $wpdb->query("DELETE FROM {$wpdb->prefix}postmeta WHERE meta_key = '_gform-form-id';");
        $results += $wpdb->query("DELETE FROM {$wpdb->prefix}postmeta WHERE meta_key = '_encloseme';");


        /*
         * Delete orphaned post meta
         */
        $results += $wpdb->query("DELETE pm FROM {$wpdb->prefix}postmeta pm LEFT JOIN {$wpdb->prefix}posts wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL;");

        /*
        * Delete empty postmeta
        */
        $results += $wpdb->query("DELETE FROM {$wpdb->prefix}postmeta WHERE meta_value = '' ;");

        /*
        * Clear terms relationships
        */
        $results += $wpdb->query("DELETE tr FROM {$wpdb->prefix}term_taxonomy tr LEFT JOIN {$wpdb->prefix}terms ON tr.term_id = {$wpdb->prefix}terms.term_id WHERE {$wpdb->prefix}terms.term_id is NULL;");
        $results += $wpdb->query("DELETE tr FROM {$wpdb->prefix}term_relationships tr LEFT JOIN {$wpdb->prefix}posts ON tr.object_id = {$wpdb->prefix}posts.ID LEFT JOIN {$wpdb->prefix}term_taxonomy ON tr.term_taxonomy_id = {$wpdb->prefix}term_taxonomy.term_taxonomy_id WHERE {$wpdb->prefix}posts.ID is NULL AND ({$wpdb->prefix}term_taxonomy.taxonomy = 'category' OR {$wpdb->prefix}term_taxonomy.taxonomy is NULL);");

        return 'Row affected: ' . $results;
    }

}

