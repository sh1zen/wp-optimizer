<?php

define('WPOPT_DB_PATH', __DIR__);

class WOMod_Database extends WO_Module
{
    public $scopes = array('admin-page');

    public $performer_response;

    public function __construct()
    {
        $default = array(
            'backup' => array(
                'backup_path'     => wpopt_conform_dir(WP_CONTENT_DIR . '/backup-db/'),
                'excluded_tables' => array(),
                'mysqldump_path'  => ''
            ),
            'sweep'  => array(
                'excluded_taxonomies' => array()
            )
        );

        parent::__construct(
            array(
                'disabled' => !current_user_can('manage_database'),
                'settings' => $default
            )
        );
    }

    /**
     * Handle the gui for backup panel
     * @param array $settings
     * @return false|string
     */
    public static function render_backup_panel($settings = array())
    {
        ob_start();
        ?>
        <section>
            <?php _e('Checking Backup Folder', 'wpopt'); ?>
            <span>(<strong><?php echo $settings['backup_path']; ?></strong>)</span> ...
            <?php

            if (wpopt_create_folder($settings['backup_path'])) {
                echo '<span style="color: green;">' . __("OK", 'wpopt') . '</span>';
            }
            else {

                echo '<span style="color: red;">' . __("FAIL", 'wpopt') . '</span>';
                echo '<div class="wpopt-notice wpopt-notice--error">' . sprintf(__('Backup folder does NOT exist or is NOT WRITABLE. Please create it and set permissions to \'777\' or change the location of the backup folder in settings.', 'wpopt'), WP_CONTENT_DIR) . '</div>';

            }
            ?>
        </section>

        <form method="post" action="<?php echo WO::getInstance()->module_panel_url('database', 'db-backup'); ?>">
            <?php wp_nonce_field('wp-dbmanager_backup'); ?>
            <input type="hidden" name="do" value="db-backup">
            <div class="wrap">
                <h3><?php _e('Backup Database', 'wpopt'); ?></h3>
                <table class="widefat">
                    <thead>
                    <tr>
                        <th><?php _e('Option', 'wpopt'); ?></th>
                        <th><?php _e('Value', 'wpopt'); ?></th>
                    </tr>
                    </thead>
                    <tr>
                        <th><?php _e('Database Name:', 'wpopt'); ?></th>
                        <td><?php echo DB_NAME; ?></td>
                    </tr>
                    <tr class="alternate">
                        <th><?php _e('Database Backup To:', 'wpopt'); ?></th>
                        <td><span dir="ltr"><?php echo $settings['backup_path']; ?></span></td>
                    </tr>
                    <tr>
                        <th><?php _e('Database Backup Date:', 'wpopt'); ?></th>
                        <td><?php echo mysql2date(sprintf(__('%s @ %s', 'wpopt'), get_option('date_format'), get_option('time_format')), gmdate('Y-m-d H:i:s', current_time('timestamp'))); ?></td>
                    </tr>
                    <tr class="alternate">
                        <th><?php _e('Database Backup File Name:', 'wpopt'); ?></th>
                        <td>
                            <span dir="ltr"><?php echo gmdate('Y-m-d@H-i-s', current_time('timestamp')) . '.sql'; ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Database Backup Type:', 'wpopt'); ?></th>
                        <td><?php _e('Full (Structure and Data)', 'wpopt'); ?></td>
                    </tr>
                    <tr class="alternate">
                        <th><?php _e('MYSQL Dump Location:', 'wpopt'); ?></th>
                        <td><span dir="ltr">
                            <?php
                            if (!empty($settings['mysqldump_path']))
                                echo stripslashes($settings['mysqldump_path']);
                            else
                                echo 'No dumper found or not valid path set, using sql export method (slower)';
                            ?>
                        </span></td>
                    </tr>
                    <tr>
                        <td colspan="2" style="text-align: center; padding-top: 20px">
                            <a class="button" style="margin-right: 20px"
                               href="<?php echo WO::getInstance()->setting_panel_url('database') ?>">Settings</a>
                            <input type="submit" name="button" value="<?php _e('Backup', 'wpopt'); ?>" class="button"/>
                            <input type="button" name="cancel" value="<?php _e('Cancel', 'wpopt'); ?>" class="button"
                                   onclick="history.go(-1)"/>
                        </td>
                    </tr>
                </table>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * Handle the gui for exec-sql panel
     * @param array $settings
     * @return false|string
     */
    public static function render_execSQL_panel($settings = array())
    {
        ob_start();
        ?>
        <form method="post" action="<?php echo admin_url("admin.php?page=womod-database#db-runsql"); ?>">
            <?php wp_nonce_field('wp-dbmanager_run'); ?>
            <input type="hidden" name="do" value="exec-sql"/>&nbsp;
            <div>
                <strong><?php _e('Separate Multiple Queries With A New Line', 'wpopt'); ?></strong><br/>
                <p style="color: green;"><?php _e('Use Only INSERT, UPDATE, REPLACE, DELETE, CREATE and ALTER statements.', 'wpopt'); ?></p>
            </div>
            <table class="form-table">
                <tr>
                    <td>
                        <label>
                            <textarea id="sql_query" class="width100" cols="120" rows="10" name="sql_query"></textarea>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td>
                        <input type="submit" name="button" value="<?php _e('Run', 'wpopt'); ?>" class="button"/>
                        <input type="button" name="cancel" value="<?php _e('Clear', 'wpopt'); ?>" class="button"
                               onclick='document.getElementById("sql_query").value = ""'/>
                    </td>
                </tr>
            </table>
            <p>
                <?php _e('1. CREATE statement will return an error, which is perfectly normal due to the database class. To confirm that your table has been created check the Tables panel.', 'wpopt'); ?>
                <br/>
                <?php _e('2. UPDATE statement may return an error sometimes due to the newly updated value being the same as the previous value.', 'wpopt'); ?>
                <br/>
                <?php _e('3. ALTER statement will return an error because there is no value returned.', 'wpopt'); ?>
            </p>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * Handle the gui for tables list
     * @param array $settings
     */
    public static function render_tablesList_panel($settings = array())
    {
        require_once __DIR__ . '/database/list-tables.php';

        $table_list_obj = new WO_DB_Tables_List();

        list($message, $status) = $table_list_obj->prepare_items();

        if (!empty($message)) {
            echo '<div id="message" class="' . $status . '"><p>' . $message . '</p></div>';
        }

        ?>
        <form id="aDBc_form" action="" method="post">
            <?php $table_list_obj->display(); ?>
        </form>
        <?php
    }

    public function ajax_handler()
    {
        $sweep_name = sanitize_text_field($_GET['sweep_name']);

        if (!wpopt_verify_nonce('wp_sweep_' . $sweep_name)) {
            wp_send_json_error(
                array(
                    'error' => __('Failed to verify referrer.', 'wpopt'),
                )
            );
        }

        $action = sanitize_text_field($_GET['wpopt_action']);

        switch ($action) {

            case 'sweep_details':
                wp_send_json_success($this->details($sweep_name));
                break;
            case 'sweep':
                $sweep = WOPerformer::sweep($sweep_name, $this->get_excluded_taxonomies());

                $count = $this->count($sweep_name);
                $total_count = $this->total_count($_GET['sweep_type']);

                wp_send_json_success(
                    array(
                        'sweep'      => $sweep,
                        'count'      => $count,
                        'total'      => $total_count,
                        'percentage' => wpopt_format_percentage($sweep, $total_count)
                    )
                );
                break;
        }
    }

    /**
     * Return details about a sweep
     *
     * @param string $name
     * @return array
     * @since 1.2.0
     */
    private function details($name)
    {
        global $wpdb;

        $details = array();

        switch ($name) {
            case 'revisions':
                $details = $wpdb->get_col($wpdb->prepare("SELECT post_title FROM $wpdb->posts WHERE post_type = %s LIMIT %d", 'revision', $this->ajax_limit));
                break;
            case 'auto_drafts':
                $details = $wpdb->get_col($wpdb->prepare("SELECT post_title FROM $wpdb->posts WHERE post_status = %s LIMIT %d", 'auto-draft', $this->ajax_limit));
                break;
            case 'deleted_posts':
                $details = $wpdb->get_col($wpdb->prepare("SELECT post_title FROM $wpdb->posts WHERE post_status = %s LIMIT %d", 'trash', $this->ajax_limit));
                break;
            case 'unapproved_comments':
                $details = $wpdb->get_col($wpdb->prepare("SELECT comment_author FROM $wpdb->comments WHERE comment_approved = %s LIMIT %d", '0', $this->ajax_limit));
                break;
            case 'spam_comments':
                $details = $wpdb->get_col($wpdb->prepare("SELECT comment_author FROM $wpdb->comments WHERE comment_approved = %s LIMIT %d", 'spam', $this->ajax_limit));
                break;
            case 'deleted_comments':
                $details = $wpdb->get_col($wpdb->prepare("SELECT comment_author FROM $wpdb->comments WHERE (comment_approved = %s OR comment_approved = %s) LIMIT %d", 'trash', 'post-trashed', $this->ajax_limit));
                break;
            case 'transient_options':
                $details = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM $wpdb->options WHERE option_name LIKE(%s) LIMIT %d", '%_transient_%', $this->ajax_limit));
                break;
            case 'orphan_postmeta':
                $details = $wpdb->get_col($wpdb->prepare("SELECT meta_key FROM $wpdb->postmeta WHERE post_id NOT IN (SELECT ID FROM $wpdb->posts) LIMIT %d", $this->ajax_limit));
                break;
            case 'orphan_commentmeta':
                $details = $wpdb->get_col($wpdb->prepare("SELECT meta_key FROM $wpdb->commentmeta WHERE comment_id NOT IN (SELECT comment_ID FROM $wpdb->comments) LIMIT %d", $this->ajax_limit));
                break;
            case 'orphan_usermeta':
                $details = $wpdb->get_col($wpdb->prepare("SELECT meta_key FROM $wpdb->usermeta WHERE user_id NOT IN (SELECT ID FROM $wpdb->users) LIMIT %d", $this->ajax_limit));
                break;
            case 'orphan_termmeta':
                $details = $wpdb->get_col($wpdb->prepare("SELECT meta_key FROM $wpdb->termmeta WHERE term_id NOT IN (SELECT term_id FROM $wpdb->terms) LIMIT %d", $this->ajax_limit));
                break;
            case 'orphan_term_relationships':
                $orphan_term_relationships_sql = implode("','", array_map('esc_sql', $this->get_excluded_taxonomies()));
                $details = $wpdb->get_col($wpdb->prepare("SELECT tt.taxonomy FROM $wpdb->term_relationships AS tr INNER JOIN $wpdb->term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy NOT IN ('$orphan_term_relationships_sql') AND tr.object_id NOT IN (SELECT ID FROM $wpdb->posts) LIMIT %d", $this->ajax_limit)); // WPCS: unprepared SQL ok.
                break;
            case 'unused_terms':
                $details = $wpdb->get_col($wpdb->prepare("SELECT t.name FROM $wpdb->terms AS t INNER JOIN $wpdb->term_taxonomy AS tt ON t.term_id = tt.term_id WHERE tt.count = %d AND t.term_id LIMIT %d", 0, $this->ajax_limit)); // WPCS: unprepared SQL ok.
                break;
            case 'duplicated_postmeta':
                $query = $wpdb->get_results($wpdb->prepare("SELECT COUNT(*) AS count, meta_key FROM $wpdb->postmeta GROUP BY post_id, meta_key, meta_value HAVING count > %d LIMIT %d", 1, $this->ajax_limit));
                if ($query) {
                    foreach ($query as $meta) {
                        $details[] = $meta->meta_key;
                    }
                }
                break;
            case 'duplicated_commentmeta':
                $query = $wpdb->get_results($wpdb->prepare("SELECT COUNT(*) AS count, meta_key FROM $wpdb->commentmeta GROUP BY comment_id, meta_key, meta_value HAVING count > %d LIMIT %d", 1, $this->ajax_limit));
                if ($query) {
                    foreach ($query as $meta) {
                        $details[] = $meta->meta_key;
                    }
                }
                break;
            case 'duplicated_usermeta':
                $query = $wpdb->get_results($wpdb->prepare("SELECT COUNT(*) AS count, meta_key FROM $wpdb->usermeta GROUP BY user_id, meta_key, meta_value HAVING count > %d LIMIT %d", 1, $this->ajax_limit));
                if ($query) {
                    foreach ($query as $meta) {
                        $details[] = $meta->meta_key;
                    }
                }
                break;
            case 'duplicated_termmeta':
                $query = $wpdb->get_results($wpdb->prepare("SELECT COUNT(*) AS count, meta_key FROM $wpdb->termmeta GROUP BY term_id, meta_key, meta_value HAVING count > %d LIMIT %d", 1, $this->ajax_limit));
                if ($query) {
                    foreach ($query as $meta) {
                        $details[] = $meta->meta_key;
                    }
                }
                break;
            case 'oembed_postmeta':
                $details = $wpdb->get_col($wpdb->prepare("SELECT meta_key FROM $wpdb->postmeta WHERE meta_key LIKE(%s) LIMIT %d", '%_oembed_%', $this->ajax_limit));
                break;
        }

        return $details;
    }

    private function get_excluded_taxonomies()
    {
        return $this->settings['sweep']['excluded_taxonomies'];
    }

    /**
     * Count the number of items for each sweep
     *
     * @param string $name
     * @return int
     * @since 1.2.0
     *
     * @access public
     */
    public function count($name)
    {
        global $wpdb;

        $count = 0;

        switch ($name) {
            case 'revisions':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = %s", 'revision'));
                break;
            case 'auto_drafts':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = %s", 'auto-draft'));
                break;
            case 'deleted_posts':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = %s", 'trash'));
                break;
            case 'unapproved_comments':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = %s", '0'));
                break;
            case 'spam_comments':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = %s", 'spam'));
                break;
            case 'deleted_comments':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->comments WHERE (comment_approved = %s OR comment_approved = %s)", 'trash', 'post-trashed'));
                break;
            case 'transient_options':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE(%s)", '%_transient_%'));
                break;
            case 'orphan_postmeta':
                $count = $wpdb->get_var("SELECT COUNT(meta_id) FROM $wpdb->postmeta WHERE post_id NOT IN (SELECT ID FROM $wpdb->posts)");
                break;
            case 'orphan_commentmeta':
                $count = $wpdb->get_var("SELECT COUNT(meta_id) FROM $wpdb->commentmeta WHERE comment_id NOT IN (SELECT comment_ID FROM $wpdb->comments)");
                break;
            case 'orphan_usermeta':
                $count = $wpdb->get_var("SELECT COUNT(umeta_id) FROM $wpdb->usermeta WHERE user_id NOT IN (SELECT ID FROM $wpdb->users)");
                break;
            case 'orphan_termmeta':
                $count = $wpdb->get_var("SELECT COUNT(meta_id) FROM $wpdb->termmeta WHERE term_id NOT IN (SELECT term_id FROM $wpdb->terms)");
                break;
            case 'orphan_term_relationships':
                $orphan_term_relationships_sql = implode("','", array_map('esc_sql', $this->get_excluded_taxonomies()));
                $count = $wpdb->get_var("SELECT COUNT(object_id) FROM $wpdb->term_relationships AS tr INNER JOIN $wpdb->term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy NOT IN ('$orphan_term_relationships_sql') AND tr.object_id NOT IN (SELECT ID FROM $wpdb->posts)"); // WPCS: unprepared SQL ok.
                break;
            case 'unused_terms':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(t.term_id) FROM $wpdb->terms AS t INNER JOIN $wpdb->term_taxonomy AS tt ON t.term_id = tt.term_id WHERE tt.count = %d AND t.term_id ", 0)); // WPCS: unprepared SQL ok.
                break;
            case 'duplicated_postmeta':
                $query = $wpdb->get_col($wpdb->prepare("SELECT COUNT(meta_id) AS count FROM $wpdb->postmeta GROUP BY post_id, meta_key, meta_value HAVING count > %d", 1));
                if (is_array($query)) {
                    $count = array_sum(array_map('intval', $query));
                }
                break;
            case 'duplicated_commentmeta':
                $query = $wpdb->get_col($wpdb->prepare("SELECT COUNT(meta_id) AS count FROM $wpdb->commentmeta GROUP BY comment_id, meta_key, meta_value HAVING count > %d", 1));
                if (is_array($query)) {
                    $count = array_sum(array_map('intval', $query));
                }
                break;
            case 'duplicated_usermeta':
                $query = $wpdb->get_col($wpdb->prepare("SELECT COUNT(umeta_id) AS count FROM $wpdb->usermeta GROUP BY user_id, meta_key, meta_value HAVING count > %d", 1));
                if (is_array($query)) {
                    $count = array_sum(array_map('intval', $query));
                }
                break;
            case 'duplicated_termmeta':
                $query = $wpdb->get_col($wpdb->prepare("SELECT COUNT(meta_id) AS count FROM $wpdb->termmeta GROUP BY term_id, meta_key, meta_value HAVING count > %d", 1));
                if (is_array($query)) {
                    $count = array_sum(array_map('intval', $query));
                }
                break;
            case 'oembed_postmeta':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(meta_id) FROM $wpdb->postmeta WHERE meta_key LIKE(%s)", '%_oembed_%'));
                break;
        }

        return $count;
    }

    /**
     * Count the number of total items
     *
     * @param string $name
     * @return int
     * @since 1.2.0
     */
    public function total_count($name)
    {
        global $wpdb;

        $count = 0;

        switch ($name) {
            case 'posts':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts");
                break;
            case 'postmeta':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta");
                break;
            case 'comments':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->comments");
                break;
            case 'commentmeta':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->commentmeta");
                break;
            case 'users':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users");
                break;
            case 'usermeta':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->usermeta");
                break;
            case 'term_relationships':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->term_relationships");
                break;
            case 'term_taxonomy':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->term_taxonomy");
                break;
            case 'terms':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->terms");
                break;
            case 'termmeta':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->termmeta");
                break;
            case 'options':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->options");
                break;
        }

        return $count;
    }

    public function enqueue_scripts()
    {
        wp_enqueue_script('wpopt-db-sweep', plugin_dir_url(WPOPT_FILE) . 'modules/database/database.js', array('jquery'), false, true);

        wp_localize_script(
            'wpopt-db-sweep', 'wpopt_sweep', array(
                'text_close_warning' => __('Sweeping process is in progress. If you leave now, it will not be completed.', 'wpopt'),
                'text_sweep'         => __('Sweep', 'wpopt'),
                'text_sweeping'      => __('Sweeping...', 'wpopt'),
                'text_na'            => __('N/A', 'wpopt'),
            )
        );
    }

    public function render()
    {
        $performer_text = '';

        if (isset($_POST['do'])) {
            // todo sanitize
            $performer_text = $this->performer($_POST);
        }

        ?>
        <section class="wpopt-wrap">
            <section class='wpopt'><h1>Database Manager</h1></section>
            <block class="wpopt">
                <?php

                if (!empty($performer_text)) {
                    echo '<div id="message" class="updated"><p>' . $performer_text . '</p></div>';
                }

                echo wpopt_generateHTML_tabs_panels(array(

                    array('id'          => 'db-tables',
                          'tab-title'   => __('Tables', 'wpopt'),
                          'panel-title' => __('Database tables', 'wpopt'),
                          'callback'    => array($this, 'render_tablesList_panel')
                    ),
                    array('id'          => 'db-sweeper',
                          'tab-title'   => __('Database sweeper', 'wpopt'),
                          'panel-title' => __('Database Sweeper', 'wpopt'),
                          'callback'    => array($this, 'render_sweeper_panel'),
                    ),
                    array('id'          => 'db-backup',
                          'tab-title'   => __('Backup Manager', 'wpopt'),
                          'panel-title' => __('Backup your database', 'wpopt'),
                          'callback'    => array($this, 'render_backup_panel'),
                          'args'        => array($this->settings['backup'])
                    ),
                    array('id'          => 'db-runsql',
                          'tab-title'   => __('Run SQL Query', 'wpopt'),
                          'panel-title' => __('Run SQL Query', 'wpopt'),
                          'callback'    => array($this, 'render_execSQL_panel'),
                          'args'        => array($this->settings['backup'])
                    )
                ));
                ?>
            </block>
        </section>
        <?php
    }

    public function performer($args = array())
    {
        if (empty($args['do']))
            return false;

        require_once __DIR__ . '/database/performers.php';

        switch ($args['do']) {

            case 'exec-sql':

                if (!wpopt_verify_nonce('wp-dbmanager_run'))
                    die("Wp Optimizer :: Invalid Access");

                $sql = trim($args['sql_query']);

                $this->performer_response = wpopt_db_process_sql($sql);

                break;

            case 'db-backup':

                if (!wpopt_verify_nonce('wp-dbmanager_backup'))
                    die("Wp Optimizer :: Invalid Access");

                wpopt_db_make_backup($this->settings['backup']);

                break;
        }

        return $this->performer_response;
    }

    public function render_sweeper_panel()
    {
        $sweepers = array(
            array('title'  => __('Posts', 'wpopt'),
                  'type'   => 'posts',
                  'sweeps' => array(__('Revision', 'wpopt')      => 'revisions',
                                    __('Auto Draft', 'wpopt')    => 'auto_drafts',
                                    __('Deleted Posts', 'wpopt') => 'deleted_posts'
                  )
            ),

            array('title'  => __('Post Metas', 'wpopt'),
                  'type'   => 'postmeta',
                  'sweeps' => array(__('Orphan Postmeta', 'wpopt')     => 'orphan_postmeta',
                                    __('Duplicated Postmeta', 'wpopt') => 'duplicated_postmeta',
                                    __('Oembed Postmeta', 'wpopt')     => 'oembed_postmeta'
                  )
            ),

            array('title'  => __('Comments', 'wpopt'),
                  'type'   => 'comments',
                  'sweeps' => array(__('Unapproved Comments', 'wpopt') => 'unapproved_comments',
                                    __('Spam Comments', 'wpopt')       => 'spam_comments',
                                    __('Deleted Comments', 'wpopt')    => 'deleted_comments'
                  )
            ),

            array('title'  => __('Comment Metas', 'wpopt'),
                  'type'   => 'commentmeta',
                  'sweeps' => array(__('Orphan Comments', 'wpopt')     => 'orphan_commentmeta',
                                    __('Duplicated Comments', 'wpopt') => 'duplicated_commentmeta'
                  )
            ),

            array('title'  => __('User Metas', 'wpopt'),
                  'type'   => 'usermeta',
                  'sweeps' => array(__('Orphaned User Meta', 'wpopt')   => 'orphan_usermeta',
                                    __('Duplicated User Meta', 'wpopt') => 'duplicated_usermeta'
                  )
            ),

            array('title'  => __('Terms', 'wpopt'),
                  'type'   => 'terms',
                  'sweeps' => array(__('Orphaned Term Meta', 'wpopt')         => 'orphan_termmeta',
                                    __('Duplicated Term Meta', 'wpopt')       => 'duplicated_termmeta',
                                    __('Orphaned Term Relationship', 'wpopt') => 'orphan_term_relationships',
                                    __('Unused Terms', 'wpopt')               => 'unused_terms'
                  )
            ),

            array('title'  => __('Option', 'wpopt'),
                  'type'   => 'options',
                  'sweeps' => array(__('Transient Options', 'wpopt') => 'transient_options'
                  )
            ),
        );

        ?>
        <p><?php _e(sprintf(__('If you click Details will be shown only %s items.', 'wpopt'), number_format_i18n($this->ajax_limit))); ?></p>
        <p>
            <strong><?php _e('Before doing any sweep it\'s recommended to make a backup of whole database.', 'wpopt'); ?></strong>
        </p>
        <br>
        <?php

        foreach ($sweepers as $sweeper) {

            $total = $this->total_count($sweeper['type']);
            ?>
            <section class="list-tables">
                <pre-table>
                    <?php
                    echo "<h3>{$sweeper['title']}</h3>";
                    echo "<p>" . wp_kses_post(sprintf(__('There are a total of <strong class="attention"><span>%1$s</span></strong> ' . $sweeper['type'] . '.', 'wpopt'), number_format_i18n($total))) . "</p>";
                    ?>
                </pre-table>
                <table class="widefat table-sweep">
                    <thead>
                    <tr>
                        <th class="col-sweep-details"><?php _e('Details', 'wpopt'); ?></th>
                        <th class="col-sweep-count"><?php _e('Count', 'wpopt'); ?></th>
                        <th class="col-sweep-percent"><?php _e('% Of', 'wpopt'); ?></th>
                        <th class="col-sweep-action"><?php _e('Action', 'wpopt'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    $alternate = false;
                    foreach ($sweeper['sweeps'] as $name => $id) {
                        $count = $this->count($id);
                        ?>
                        <tr <?php echo $alternate ? "class='alternate'" : ''; ?>>
                            <td>
                                <strong><?php echo $name ?></strong>
                                <div style="display: none" class="sweep-details"></div>
                            </td>
                            <td>
                                <span class="sweep-count"><?php echo number_format_i18n($count); ?></span>
                            </td>
                            <td>
                                <span class="sweep-percentage"><?php echo wpopt_format_percentage($count, $total); ?></span>
                            </td>
                            <td>
                                <?php if ($count > 0) :
                                    $nonce = esc_attr(wp_create_nonce('wp_sweep_' . $id));
                                    ?>
                                    <button data-action="sweep" data-sweep_name="<?php echo $id; ?>"
                                            data-sweep_type="<?php echo $sweeper['type']; ?>"
                                            data-nonce="<?php echo $nonce; ?>"
                                            class="button button-primary btn-sweep"><?php _e('Sweep', 'wpopt'); ?></button>
                                    <button data-action="sweep_details" data-sweep_name="<?php echo $id; ?>"
                                            data-sweep_type="<?php echo $sweeper['type']; ?>"
                                            data-nonce="<?php echo $nonce; ?>"
                                            class="button btn-sweep-details"><?php _e('Details', 'wpopt'); ?></button>
                                <?php else :
                                    _e('None', 'wpopt');
                                endif;
                                ?>
                            </td>
                        </tr>
                        <?php
                        $alternate = !$alternate;
                    }
                    ?>
                    </tbody>
                </table>
            </section>
            <?php
        }
    }

    public function setting_fields()
    {
        return array(
            array('type' => 'checkbox', 'name' => 'Auto optimization', 'id' => 'cron', 'value' => $this->settings['cron'] === 'true'),
            array('type' => 'separator', 'name' => 'Sweeps:'),
            array('type' => 'checkbox', 'name' => 'post & post meta', 'id' => 'core-updates', 'value' => $this->settings['core-updates'] === 'true'),
        );
    }

}