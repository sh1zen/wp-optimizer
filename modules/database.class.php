<?php

namespace WPOptimizer\modules;

use WPOptimizer\core\Disk;
use WPOptimizer\core\Graphic;
use WPOptimizer\core\UtilEnv;
use WPOptimizer\core\Settings;
use WPOptimizer\modules\supporters\List_Table;
use WPOptimizer\modules\supporters\DBSupport;

class Mod_Database extends Module
{
    public $scopes = array('admin-page', 'cron', 'settings');

    private $performer_response = array();

    private $ajax_limit;

    public function __construct()
    {
        require_once WPOPT_SUPPORTERS . '/database/DBSupport.class.php';

        $default = array(
            'backup' => array(
                'path'            => UtilEnv::normalize_path(WP_CONTENT_DIR . '/backup-db'),
                'excluded_tables' => array(),
                'mysqldump_path'  => ''
            ),
            'sweep'  => array(
                'excluded_taxonomies' => array(),
            )
        );

        $default_cron = array(
            'active' => false
        );

        parent::__construct(
            array(
                'settings'      => $default,
                'cron_settings' => $default_cron
            )
        );

        $this->ajax_limit = 100;
    }

    public function cron_handler($args = array())
    {
        DBSupport::cron_job();
    }


    public function cron_setting_fields($cron_settings)
    {
        $cron_settings[] = array('type' => 'checkbox', 'name' => __('Auto optimize Database', 'wpopt'), 'id' => 'database_active', 'value' => Settings::check($this->cron_settings, 'active'));

        return $cron_settings;
    }


    public function cron_validate_settings($valid, $input)
    {
        $valid[$this->slug] = array(
            'active' => isset($input['database_active']),
        );

        return $valid;
    }

    /**
     * Handle the gui for tables list
     * @param array $settings
     */
    public function render_tablesList_panel($settings = array())
    {
        require_once WPOPT_SUPPORTERS . '/database/List_Table.class.php';

        $table_list_obj = new List_Table();

        list($message, $status) = $table_list_obj->prepare_items();

        if (!empty($message)) {
            echo '<div id="message" class="' . $status . '"><p>' . $message . '</p></div>';
        }

        ?>
        <form method="post" action="<?php echo wpopt_module_panel_url("database", "db-tables"); ?>">
            <?php $table_list_obj->search_box('Search', 'search'); ?>
            <?php $table_list_obj->display(); ?>
        </form>
        <?php
    }

    /**
     * Handle the gui for exec-sql panel
     * @param array $settings
     * @return string
     */
    public function render_execSQL_panel($settings = array())
    {
        ob_start();
        ?>
        <form class="wpopt-ajax-db" method="post" data-module="<?php echo $this->slug ?>"
              data-nonce="<?php echo wp_create_nonce('wpopt-ajax-nonce') ?>"
              action="<?php echo wpopt_module_panel_url("database", "db-runsql"); ?>">
            <div>
                <strong><?php _e('Separate multiple queries with "<u>####</u>"', 'wpopt'); ?></strong><br/>
                <p style="color: green;"><?php _e('Use only INSERT, UPDATE, REPLACE, DELETE, CREATE and ALTER statements.', 'wpopt'); ?></p>
            </div>
            <table class="form-table">
                <tr>
                    <td>
                        <label>
                            <textarea id="sql_query" class="width100" cols="120" rows="10" name="sql_query"></textarea>
                        </label>
                    </td>
                </tr>
                <tr class="wpopt-centered">
                    <td>
                        <input type="submit" name="button" value="<?php _e('Run', 'wpopt'); ?>" class="button"
                               data-action="exec-sql"/>
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
     * Handle the gui for backup panel
     * @param array $settings
     * @return string
     */
    public function render_backup_panel($settings = array())
    {
        ob_start();
        ?>
        <section>
            <?php _e('Checking Backup Folder', 'wpopt'); ?>
            <span>(<strong><?php echo $settings['path']; ?></strong>)</span> ...
            <?php

            if (Disk::make_path($settings['path'])) {
                echo '<span style="color: green;">' . __("OK", 'wpopt') . '</span>';
            }
            else {
                echo '<span style="color: red;">' . __("FAIL", 'wpopt') . '</span>';
                echo '<div class="wpopt-notice wpopt-notice--error">' . sprintf(__('Backup folder does NOT exist or is NOT WRITABLE. Please create it and set permissions to \'777\' or change the location of the backup folder in settings.', 'wpopt'), WP_CONTENT_DIR) . '</div>';
            }
            ?>
        </section>
        <section>
            <form class="wpopt-ajax-db" method="post" data-module="<?php echo $this->slug ?>"
                  data-nonce="<?php echo wp_create_nonce('wpopt-ajax-nonce') ?>"
                  action="<?php echo wpopt_module_panel_url('database', 'db-backup'); ?>">
                <?php wp_nonce_field('wpopt-db-backup-manage'); ?>
                <input type="hidden" name="wpopt-db-do" value="db-manage-backup">

                <h3><?php _e('Manage Backups', 'wpopt'); ?></h3>
                <table class="widefat wpopt">
                    <thead>
                    <tr>
                        <th><?php _e('No.', 'wpopt'); ?></th>
                        <th><?php _e('MD5 Checksum', 'wpopt'); ?></th>
                        <th><?php _e('Database File', 'wpopt'); ?></th>
                        <th><?php _e('Creation date', 'wpopt'); ?></th>
                        <th><?php _e('Size', 'wpopt'); ?></th>
                        <th><?php _e('Select', 'wpopt'); ?></th>
                    </tr>
                    </thead>
                    <?php
                    $no = 0;
                    $totalsize = 0;

                    $backup_path = trailingslashit($this->option('backup.path', ''));

                    if (is_readable($backup_path) and count(scandir($backup_path)) > 2) {

                        $database_files = glob($backup_path . "*.{sql,gz}", GLOB_BRACE);

                        usort($database_files, function ($a, $b) {
                            return filemtime($a) - filemtime($b);
                        });

                        foreach ($database_files as $database_file) {

                            if ($no++ % 2 === 0) {
                                $style = '';
                            }
                            else {
                                $style = 'class="alternate"';
                            }

                            $file_size = filesize($database_file);

                            $display_name = strlen($database_file) > 50 ? substr($database_file, 0, 25) . '.....' . substr($database_file, -24) : $database_file;

                            echo '<tr ' . $style . '>';
                            echo '<td>' . number_format_i18n($no) . '</td>';
                            echo '<td>' . md5($database_file) . '</td>';
                            echo '<td>' . $display_name . '</td>';
                            echo '<td>' . get_date_from_gmt(date('Y-m-d H:i:s', filemtime($database_file))) . '</td>';
                            echo '<td>' . size_format($file_size) . '</td>';
                            echo '<td><input type="radio" name="file" value="' . esc_attr(basename($database_file)) . '"/></td></tr>';

                            $totalsize += $file_size;
                        }
                    }
                    else {
                        echo '<tr><td class="wpopt-centered" colspan="6">' . __('There Are No Database Backup Files Available.', 'wpopt') . '</td></tr>';
                    }
                    ?>
                    <tr class="wpopt-footer">
                        <th colspan="4"><?php printf(_n('%s Backup found', '%s Backups found', $no, 'wpopt'), number_format_i18n($no)); ?></th>
                        <th colspan="2"><?php echo size_format($totalsize); ?></th>
                    </tr>
                    <tr>
                        <td colspan="6" class="wpopt-centered wpopt-actions">
                            <input type="submit" name="action" value="<?php _e('Download', 'wpopt'); ?>"
                                   class="button" data-action="download"/>
                            <input type="submit" name="action" value="<?php _e('Restore', 'wpopt'); ?>"
                                   onclick="return confirm('<?php _e('Are you sure to restore selected backup?\nAny data inserted after the backup date will be lost.\n\nThis action is not reversible.', 'wpopt'); ?>')"
                                   class="button" data-action="restore"/>
                            <input type="submit" data-action="delete" class="button" name="action"
                                   value="<?php _e('Delete', 'wpopt'); ?>"
                                   onclick="return confirm('<?php _e('Are you sure to delete selected backup?', 'wpopt'); ?>')"/>&nbsp;&nbsp;
                        </td>
                    </tr>
                </table>
            </form>
            <form class="wpopt-ajax-db" method="post" data-module="<?php echo $this->slug ?>"
                  data-nonce="<?php echo wp_create_nonce('wpopt-ajax-nonce') ?>"
                  action="<?php echo wpopt_module_panel_url('database', 'db-backup'); ?>">
                <h3><?php _e('Backup Database', 'wpopt'); ?></h3>
                <table class="widefat wpopt">
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
                        <td><span dir="ltr"><?php echo $settings['path']; ?></span></td>
                    </tr>
                    <tr>
                        <th><?php _e('Database Backup Date:', 'wpopt'); ?></th>
                        <td><?php echo mysql2date(sprintf(__('%s @ %s', 'wpopt'), get_option('date_format'), get_option('time_format')), gmdate('Y-m-d H:i:s', current_time('timestamp'))); ?></td>
                    </tr>
                    <tr class="alternate">
                        <th><?php _e('Database Backup File Name:', 'wpopt'); ?></th>
                        <td>
                            <span dir="ltr"><?php echo '#######.sql'; ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Database Backup Type:', 'wpopt'); ?></th>
                        <td><?php _e('Full (Structure and Data)', 'wpopt'); ?></td>
                    </tr>
                    <tr class="alternate">
                        <th><?php _e('MYSQL Dump Location:', 'wpopt'); ?></th>
                        <td><span>
                            <?php
                            if ($path = wpopt_get_mysqlDump_command_path())
                                echo $path;
                            else
                                echo 'No mysqldump found or not valid path set. Using sql export method will be slower.';
                            ?>
                        </span></td>
                    </tr>
                    <tr>
                        <td colspan="2" class="wpopt-centered wpopt-actions">
                            <input data-action="backup" type="submit" name="action"
                                   value="<?php _e('Backup now', 'wpopt'); ?>"
                                   class="button"/>
                            <a class="button"
                               href="<?php echo wpopt_module_setting_url('database') ?>">Settings</a>
                        </td>
                    </tr>
                </table>
            </form>
        </section>
        <?php
        return ob_get_clean();
    }

    public function ajax_handler($args = array())
    {
        $response = '';

        $action_args = empty($args['options']) ? '' : unserialize(base64_decode($args['options']));

        $form_data = array();
        parse_str($args['form_data'], $form_data);

        switch ($args['action']) {

            case 'exec-sql':
                $this->ajax_performer($args['action'], array('query' => $form_data['sql_query']));
                break;

            case 'delete':
            case 'download':
            case 'restore':
                $this->ajax_performer($args['action'], array('file' => $form_data['file']));
                break;

            case 'backup':
                $this->ajax_performer($args['action']);
                break;

            case 'sweep_details':
                wp_send_json_success(DBSupport::details($action_args['sweep-name']));
                break;

            case 'sweep':
                $sweep = DBSupport::sweep($action_args['sweep-name'], $this->option('sweep', array()));

                $count = DBSupport::count($action_args['sweep-name'], $this->option('sweep', array()));

                $total_count = DBSupport::total_count($action_args['sweep-type']);

                wp_send_json_success(array(
                    'sweep'      => $sweep,
                    'count'      => $count,
                    'total'      => $total_count,
                    'percentage' => UtilEnv::format_percentage($sweep, $total_count)
                ));
                break;

            default:
                wp_send_json_error(array(
                    'response' => __('Action not supported', 'wpopt'),
                ));
        }

        foreach ($this->performer_response as $res) {
            list($text, $status) = $res;
            $response .= "<p class='{$status}'> {$text} </p>";
        }

        wp_send_json_success(array(
            'response' => $response
        ));
    }

    private function ajax_performer($action, $args = array())
    {
        switch ($action) {

            case 'exec-sql':

                $sql = trim($args['query']);

                if (empty($sql)) {
                    $this->performer_response[] = array(__('Empty Query', 'wpopt'), 'error');
                    break;
                }

                $sql = trim($sql);

                $sql_queries = array();

                foreach (explode('####', $sql) as $sql_query) {

                    $sql_query = trim(stripslashes($sql_query));

                    $sql_query = preg_replace("/[\r\n]+/", ' ', $sql_query);

                    if (!empty($sql_query)) {
                        $sql_queries[] = $sql_query;
                    }
                }

                if (empty($sql_queries)) {
                    $this->performer_response[] = array(__('Empty query', 'wpopt'), 'error');
                    break;
                }

                $total_query = $success_query = 0;

                foreach ($sql_queries as $sql_query) {

                    if (DBSupport::execute_sql($sql_query)) {
                        $success_query++;
                        $this->performer_response[] = array($sql_query, 'success');
                    }
                    else {
                        $this->performer_response[] = array($sql_query, 'error');
                    }

                    $total_query++;
                }

                $this->performer_response[] = array(number_format_i18n($success_query) . '/' . number_format_i18n($total_query) . ' ' . __('Query(s) executed successfully', 'wpopt'), 'info');
                break;

            case 'download':

                $database_file = sanitize_file_name($args['file']);

                if (empty($database_file)) {
                    $this->performer_response[] = array(__('No database backup selected', 'wpopt'), 'error');
                    break;
                }

                $file_path = trailingslashit($this->option('backup.path', '')) . $database_file;

                if (UtilEnv::download_file($file_path))
                    $this->performer_response[] = array(__('Download started.', 'wpopt'), 'success');
                else
                    $this->performer_response[] = array(__('Something went wrong during the download.', 'wpopt'), 'error');

                break;

            case 'delete':

                $database_file = sanitize_file_name($args['file']);

                if (empty($database_file)) {
                    $this->performer_response[] = array(__('No database backup selected', 'wpopt'), 'error');
                    break;
                }

                $file_path = trailingslashit($this->option('backup.path', '')) . $database_file;

                if (is_file($file_path)) {

                    if (unlink($file_path))
                        $this->performer_response[] = array(sprintf(__('Database backup \'%s\' deleted successfully.', 'wpopt'), $database_file), 'success');
                    else
                        $this->performer_response[] = array(sprintf(__('Unable to delete \'%s\', check file permissions.', 'wpopt'), $database_file), 'error');
                }
                else
                    $this->performer_response[] = array(sprintf(__('Invalid database backup \'%s\'.', 'wpopt'), $database_file), 'error');
                break;

            case 'backup':

                $options = $this->option('backup', array());

                $backup_path = trailingslashit($options['path']) . substr(md5(time()), 0, 7) . '.sql';

                $res = false;

                if (wpopt_get_mysqlDump_command_path($options['mysqldump_path'])) {

                    $res = DBSupport::mysqlDump_db($backup_path, $options['excluded_tables'], $options['mysqldump_path']);
                }

                if (!$res) {
                    $res = DBSupport::queryDump_db($backup_path, $options['excluded_tables']);
                }

                if ($res)
                    $this->performer_response[] = array(__('Backup correctly done.', 'wpopt'), 'success');
                else
                    $this->performer_response[] = array(__('Something went wrong. Try again.', 'wpopt'), 'success');

                break;

            case 'restore':

                $database_file = sanitize_file_name($args['file']);

                if (empty($database_file)) {
                    $this->performer_response[] = array(__('No database backup selected', 'wpopt'), 'error');
                    break;
                }

                $file_path = trailingslashit($this->option('backup.path', '')) . $database_file;

                if (DBSupport::restore_db($file_path)) {
                    $this->performer_response[] = array(__('Database restored.', 'wpopt'), 'success');
                }
                else {
                    $this->performer_response[] = array(__('Something went wrong during the backup restore.', 'wpopt'), 'error');
                }
                break;

            default:
                $this->performer_response[] = array(__('Invalid request.', 'wpopt'), 'error');
        }

        return true;
    }

    public function enqueue_scripts()
    {
        wp_enqueue_script('wpopt-db-sweep', plugin_dir_url(WPOPT_FILE) . 'modules/supporters/database/database.js', array('jquery'), false, true);
    }

    public function render_admin_page()
    {
        if (WPOPT_DEBUG)
            set_time_limit(0);
        else
            set_time_limit(60);
        ?>
        <section class="wpopt-wrap">
            <section class='wpopt-header'><h1>Database Manager</h1></section>
            <div id="wpopt-ajax-message" class="wpopt-notice"></div>
            <?php
            if (!empty($this->performer_response)) {

                echo '<div id="message" class="wpopt-notice">';

                foreach ($this->performer_response as $response) {
                    list($text, $status) = $response;

                    echo "<p class='{$status}'> {$text} </p>";
                }

                echo '</div>';
            }
            ?>
            <block class="wpopt">
                <?php
                echo Graphic::generateHTML_tabs_panels(array(

                    array(
                        'id'          => 'db-tables',
                        'tab-title'   => __('Tables', 'wpopt'),
                        'panel-title' => __('Database tables', 'wpopt'),
                        'callback'    => array($this, 'render_tablesList_panel')
                    ),
                    array(
                        'id'          => 'db-sweeper',
                        'tab-title'   => __('Database sweeper', 'wpopt'),
                        'panel-title' => __('Database Sweeper', 'wpopt'),
                        'callback'    => array($this, 'render_sweeper_panel'),
                    ),
                    array(
                        'id'          => 'db-backup',
                        'tab-title'   => __('Backup Manager', 'wpopt'),
                        'panel-title' => __('Backup your database', 'wpopt'),
                        'callback'    => array($this, 'render_backup_panel'),
                        'args'        => array($this->option('backup', array()))
                    ),
                    array(
                        'id'          => 'db-runsql',
                        'tab-title'   => __('Run SQL Query', 'wpopt'),
                        'panel-title' => __('Run SQL Query', 'wpopt'),
                        'callback'    => array($this, 'render_execSQL_panel'),
                        'args'        => array($this->option('backup', array()))
                    )
                ));
                ?>
            </block>
        </section>
        <?php
    }

    public function render_sweeper_panel()
    {
        $sweepers = array(
            array('title'  => __('Posts', 'wpopt'),
                  'type'   => 'posts',
                  'sweeps' => array(
                      __('Revision', 'wpopt')      => 'revisions',
                      __('Auto Draft', 'wpopt')    => 'auto_drafts',
                      __('Deleted Posts', 'wpopt') => 'deleted_posts',
                  )
            ),
            array('title'  => __('Posts Metas', 'wpopt'),
                  'type'   => 'postmeta',
                  'sweeps' => array(
                      __('Orphan Postmeta', 'wpopt')     => 'orphan_postmeta',
                      __('Duplicated Postmeta', 'wpopt') => 'duplicated_postmeta',
                      __('Oembed Postmeta', 'wpopt')     => 'oembed_postmeta'
                  )
            ),

            array('title'  => __('Comments', 'wpopt'),
                  'type'   => 'comments',
                  'sweeps' => array(
                      __('Unapproved Comments', 'wpopt') => 'unapproved_comments',
                      __('Spam Comments', 'wpopt')       => 'spam_comments',
                      __('Deleted Comments', 'wpopt')    => 'deleted_comments'
                  )
            ),

            array('title'  => __('Comments Metas', 'wpopt'),
                  'type'   => 'commentmeta',
                  'sweeps' => array(
                      __('Orphan Comments', 'wpopt')     => 'orphan_commentmeta',
                      __('Duplicated Comments', 'wpopt') => 'duplicated_commentmeta'
                  )
            ),

            array('title'  => __('Users Metas', 'wpopt'),
                  'type'   => 'usermeta',
                  'sweeps' => array(
                      __('Orphaned User Meta', 'wpopt')   => 'orphan_usermeta',
                      __('Duplicated User Meta', 'wpopt') => 'duplicated_usermeta'
                  )
            ),

            array('title'  => __('Terms', 'wpopt'),
                  'type'   => 'terms',
                  'sweeps' => array(
                      __('Orphaned Term Relationship', 'wpopt') => 'orphan_term_relationships',
                      __('Unused Terms', 'wpopt')               => 'unused_terms',
                  )
            ),

            array('title'  => __('Terms metas', 'wpopt'),
                  'type'   => 'termmeta',
                  'sweeps' => array(
                      __('Orphaned Term Meta', 'wpopt')   => 'orphan_termmeta',
                      __('Duplicated Term Meta', 'wpopt') => 'duplicated_termmeta',

                  )
            ),

            array('title'  => __('Option', 'wpopt'),
                  'type'   => 'options',
                  'sweeps' => array(
                      __('Transient Options', 'wpopt') => 'transient_options'
                  )
            ),
        );

        ?>
        <p><?php echo sprintf(__('If you click Details will be shown only %s items.', 'wpopt'), number_format_i18n($this->ajax_limit)); ?></p>
        <p>
            <strong><?php _e('Before doing any sweep it\'s recommended to make a backup of whole database.', 'wpopt'); ?></strong>
        </p>
        <br>
        <?php

        $nonce = wp_create_nonce('wpopt-ajax-nonce');

        foreach ($sweepers as $sweeper) {

            $total = DBSupport::total_count($sweeper['type']);
            if (!$total)
                continue;
            ?>
            <section class="list-tables">
                <pre-table>
                    <?php
                    echo "<h3>{$sweeper['title']}</h3>";
                    echo "<p>" . sprintf(__('There are a total of <strong class="attention"><span>%1$s</span></strong> ' . $sweeper['type'] . '.', 'wpopt'), number_format_i18n($total)) . "</p>";
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
                    foreach ($sweeper['sweeps'] as $name => $sweep_id) {

                        $count = DBSupport::count($sweep_id);
                        ?>
                        <tr <?php echo $alternate ? "class='alternate'" : ''; ?>>
                            <td>
                                <strong><?php echo $name ?></strong>
                                <div class="hidden sweep-details"></div>
                            </td>
                            <td>
                                <span class="sweep-count"><?php echo number_format_i18n($count); ?></span>
                            </td>
                            <td>
                                <span class="sweep-percentage"><?php echo UtilEnv::format_percentage($count, $total); ?></span>
                            </td>
                            <td>
                                <?php if ($count > 0) :
                                    $data = base64_encode(serialize(array('sweep-name' => $sweep_id, 'sweep-type' => $sweeper['type'])));
                                    ?>
                                    <button data-action="sweep"
                                            data-args="<?php echo $data; ?>"
                                            data-nonce="<?php echo $nonce; ?>"
                                            class="button button-primary wpopt-sweep"><?php _e('Sweep', 'wpopt'); ?></button>
                                    <button data-action="sweep_details"
                                            data-args="<?php echo $data; ?>"
                                            data-nonce="<?php echo $nonce; ?>"
                                            class="button wpopt-sweep-details"><?php _e('Details', 'wpopt'); ?></button>
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

    public function validate_settings($input, $valid)
    {
        $valid['sweep'] = array(
            'excluded_taxonomies' => array_map('trim', explode(',', $input['sweep.excluded_taxonomies']))
        );

        $valid['backup'] = array(
            'path'            => trim($input['backup.path']),
            'excluded_tables' => array_map('trim', explode(',', $input['backup.excluded_tables']))
        );

        return $valid;
    }

    public function restricted_access($context = 'settings')
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

    protected function setting_fields()
    {
        return $this->group_setting_fields(
            $this->setting_field(__('Backup path', 'wpopt'), "backup.path", 'text', ['default_value' => UtilEnv::normalize_path(WP_CONTENT_DIR . '/backup-db'), 'allow_empty' => false]),

            $this->setting_field(__('Sweeps:', 'wpopt'), false, "separator"),
            $this->setting_field(__('Excluded Taxonomies (comma separated)', 'wpopt'), "sweep.excluded_taxonomies", "textarea", ['value' => implode(', ', $this->option('sweep.excluded_taxonomies', array()))]),

            $this->setting_field(__('Backups:', 'wpopt'), false, "separator"),
            $this->setting_field(__('Excluded Tables (comma separated)', 'wpopt'), "backup.excluded_tables", "textarea", ['value' => implode(', ', $this->option('backup.excluded_tables', array()))])
        );
    }
}