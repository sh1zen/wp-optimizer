<?php

function wpopt_clear_database()
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
     * Delete orphaned comments and akismet's stuff
     */
    //$results += $wpdb->query("DELETE FROM {$wpdb->prefix}commentmeta WHERE meta_key LIKE '%akismet%';");
    $results += $wpdb->query("DELETE FROM {$wpdb->prefix}commentmeta WHERE comment_id NOT IN (SELECT comment_id FROM {$wpdb->prefix}comments);");

    /*
     * Delete unusefull post meta
     */
    if (rand(0, 20) == 13) //to this only sometime
        $results += $wpdb->query("DELETE t1 FROM {$wpdb->prefix}postmeta t1 INNER JOIN {$wpdb->prefix}postmeta t2 WHERE t1.meta_id < t2.meta_id AND t1.meta_key = t2.meta_key AND t1.meta_value = t2.meta_value AND t1.post_id = t2.post_id");

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

    /*
     * Optimize table
     */
    $mytables = $wpdb->get_results("SHOW TABLES", ARRAY_N);

    foreach ($mytables as $id => $table_name) {
        //$wpdb->query("ALTER TABLE `$table_name[0]` ENGINE=MyISAM");
        $results += $wpdb->query("OPTIMIZE TABLE `$table_name[0]`");
    }

    return 'Row affected: ' . $results;
}

function wpopt_optimize_images($elements = array())
{
    if (!is_array($elements)) {
        //$elements = date("Y", strtotime('last month')) . '/' . date('m', strtotime('last month'));
        return wpopt_opti_dir_img((string)$elements);
    }

    return wpopt_opti_imgages($elements);
}

function wpopt_opti_dir_img($path)
{
    $uploads_dir = wp_upload_dir();
    $data = '';

    $root = ($uploads_dir['basedir'] . '/' . $path . '/');

    //Going through directiry recursevely
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
        RecursiveIteratorIterator::CATCH_GET_CHILD // Ignore "Permission denied"
    );

    if (PHP_OS === 'WINNT') {
        $gifsicle_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'gifsicle.exe');
        $optipng_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'optipng.exe');
        $jpegtran_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'jpegtran.exe');
        $pngquant_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'pngquant.exe');
        $webp_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'cwebp.exe');
    }
    elseif (PHP_OS === 'Darwin') {
        $gifsicle_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'gifsicle-mac');
        $optipng_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'optipng-mac');
        $jpegtran_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'jpegtran-mac');
        $pngquant_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'pngquant-mac');
        $webp_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'cwebp-mac14');
    }
    elseif (PHP_OS === 'SunOS') {
        $gifsicle_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'gifsicle-sol');
        $optipng_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'optipng-sol');
        $jpegtran_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'jpegtran-sol');
        $pngquant_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'pngquant-sol');
        $webp_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'cwebp-sol');
    }
    elseif (PHP_OS === 'FreeBSD') {
        $gifsicle_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'gifsicle-fbsd');
        $optipng_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'optipng-fbsd');
        $jpegtran_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'jpegtran-fbsd');
        $pngquant_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'pngquant-fbsd');
        $webp_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'cwebp-fbsd');
    }
    else { //if ( PHP_OS === 'Linux' ) {
        $gifsicle_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'gifsicle-linux');
        $optipng_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'optipng-linux');
        $jpegtran_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'jpegtran-linux');
        $pngquant_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'pngquant-linux');
        $webp_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'cwebp-linux');
    }

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

        $data .= $file_esc . "<br>";

        usleep(30000);
    }

    return $data;
}

function wpopt_opti_imgages($images)
{
    $data = array();

    if (PHP_OS === 'WINNT') {
        $gifsicle_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'gifsicle.exe');
        $optipng_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'optipng.exe');
        $jpegtran_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'jpegtran.exe');
        $pngquant_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'pngquant.exe');
        $webp_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'cwebp.exe');
    }
    elseif (PHP_OS === 'Darwin') {
        $gifsicle_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'gifsicle-mac');
        $optipng_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'optipng-mac');
        $jpegtran_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'jpegtran-mac');
        $pngquant_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'pngquant-mac');
        $webp_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'cwebp-mac14');
    }
    elseif (PHP_OS === 'SunOS') {
        $gifsicle_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'gifsicle-sol');
        $optipng_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'optipng-sol');
        $jpegtran_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'jpegtran-sol');
        $pngquant_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'pngquant-sol');
        $webp_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'cwebp-sol');
    }
    elseif (PHP_OS === 'FreeBSD') {
        $gifsicle_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'gifsicle-fbsd');
        $optipng_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'optipng-fbsd');
        $jpegtran_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'jpegtran-fbsd');
        $pngquant_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'pngquant-fbsd');
        $webp_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'cwebp-fbsd');
    }
    else { //if ( PHP_OS === 'Linux' ) {
        $gifsicle_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'gifsicle-linux');
        $optipng_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'optipng-linux');
        $jpegtran_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'jpegtran-linux');
        $pngquant_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'pngquant-linux');
        $webp_src = escapeshellarg(WP_OPT_PATH . '/include/executables/' . 'cwebp-linux');
    }

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

function wpopt_clear_orphaned_images($directory = '')
{
    // already cleared: ~ 2019 2020
    if (empty($directory))
        $directory = date("Y", strtotime('last month')) . '/' . date('m', strtotime('last month'));

    //pay attention that causes cpu heavy load
    wpopt_clean_uploads_from_nonattachments((string)$directory);

    return '';
}

function wpopt_clean_uploads_from_nonattachments($year)
{
    global $wpdb;

    $uploads_dir = wp_upload_dir();

    //You may want to take it by bites if your uploads is rather large (over 5 gb for example)
    $root = ($uploads_dir['basedir'] . '/' . $year . '/');

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

function wpopt_speed_up_core()
{
    /*
        remove_action('init', 'wp_version_check');

        add_filter('pre_option_update_core', '__return_null');

        // Remove updates page.
        //remove_submenu_page( 'index.php', 'update-core.php' );

        // Disable plugin API checks.
        remove_all_filters('plugins_api');

        // Disable theme checks.
        remove_action('load-update-core.php', 'wp_update_themes');
        remove_action('load-themes.php', 'wp_update_themes');
        remove_action('load-update.php', 'wp_update_themes');
        remove_action('wp_update_themes', 'wp_update_themes');
        remove_action('admin_init', '_maybe_update_themes');
        wp_clear_scheduled_hook('wp_update_themes');

        // Disable plugin checks.
        remove_action('load-update-core.php', 'wp_update_plugins');
        remove_action('load-plugins.php', 'wp_update_plugins');
        remove_action('load-update.php', 'wp_update_plugins');
        remove_action('admin_init', '_maybe_update_plugins');
        remove_action('wp_update_plugins', 'wp_update_plugins');
        wp_clear_scheduled_hook('wp_update_plugins');

        // Disable any other update/cron checks.
        remove_action('wp_version_check', 'wp_version_check');
        remove_action('admin_init', '_maybe_update_core');
        remove_action('wp_maybe_auto_update', 'wp_maybe_auto_update');
        remove_action('admin_init', 'wp_maybe_auto_update');
        remove_action('admin_init', 'wp_auto_update_core');
        wp_clear_scheduled_hook('wp_version_check');
        wp_clear_scheduled_hook('wp_maybe_auto_update');

        // Hide nag messages.
        remove_action('admin_notices', 'update_nag', 3);
        remove_action('network_admin_notices', 'update_nag', 3);
        remove_action('admin_notices', 'maintenance_nag');
        remove_action('network_admin_notices', 'maintenance_nag');

        // Disable even other external updates related to core.
        add_filter( 'auto_update_translation', '__return_false' );
        add_filter( 'automatic_updater_disabled', '__return_true' );
        add_filter( 'allow_minor_auto_core_updates', '__return_false' );
        add_filter( 'allow_major_auto_core_updates', '__return_false' );
        add_filter( 'allow_dev_auto_core_updates', '__return_false' );
        add_filter( 'auto_update_core', '__return_false' );
        add_filter( 'wp_auto_update_core', '__return_false' );
        add_filter( 'auto_update_plugin', '__return_false' );
        add_filter( 'auto_update_theme', '__return_false' );
        add_filter( 'auto_core_update_send_email', '__return_false' );
        add_filter( 'automatic_updates_send_debug_email ', '__return_false' );
        add_filter( 'send_core_update_notification_email', '__return_false' );
        add_filter( 'automatic_updates_is_vcs_checkout', '__return_true' );
    */
}

