<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use WPS\core\Ajax;
use WPS\core\Disk;
use WPS\core\Graphic;
use WPS\core\UtilEnv;
use WPS\modules\Module;

use WPOptimizer\modules\supporters\DB_List_Table;
use WPOptimizer\modules\supporters\DBSupport;

class Mod_Database extends Module
{
    public static ?string $name = 'Database Manager';

    const BACKUP_PATH = WPOPT_STORAGE . 'backup-db/';

    public array $scopes = array('admin-page', 'cron', 'settings');
    private int $ajax_limit = 100;

    protected string $context = 'wpopt';

    public function cron_handler($args = array())
    {
        DBSupport::cron_job();
    }

    public function cron_setting_fields(): array
    {
        return [
            ['type' => 'checkbox', 'name' => __('Auto optimize Database', 'wpopt'), 'id' => 'database.active', 'value' => wps($this->context)->cron->is_active($this->slug), 'depend' => 'active']
        ];
    }

    /**
     * Handle the gui for tables list
     */
    public function render_tablesList_panel($settings = array()): string
    {
        require_once WPOPT_SUPPORTERS . '/database/DB_List_Table.class.php';

        $table_list_obj = new DB_List_Table();

        list($message, $status) = $table_list_obj->prepare_items();

        ob_start();

        if (!empty($message)) {
            echo '<div id="message" class="' . $status . '"><p>' . $message . '</p></div>';
        }

        ?>
        <form method="post" action="<?php echo wps_module_panel_url("database", "db-tables"); ?>">
            <?php $table_list_obj->search_box('Search', 'search'); ?>
            <?php $table_list_obj->display(); ?>
        </form>
        <?php

        return ob_get_clean();
    }

    /**
     * Handle the gui for exec-sql panel
     */
    public function render_execSQL_panel(): string
    {
        ob_start();
        ?>
        <form class="wpopt-ajax-db" method="post" data-module="<?php echo $this->slug ?>"
              data-nonce="<?php echo wp_create_nonce('wpopt-ajax-nonce') ?>"
              action="<?php echo wps_module_panel_url("database", "db-runsql"); ?>">
            <div>
                <strong><?php _e('Separate multiple queries with "<u>; (semicolon)</u>"', 'wpopt'); ?></strong><br/>
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
                <tr class="wps-centered">
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
     */
    public function render_backup_panel($settings = array()): string
    {
        global $wpdb;

        ob_start();
        ?>
        <section>
            <?php _e('Checking Backup Folder', 'wpopt'); ?>
            <span>(<strong><?php echo self::BACKUP_PATH; ?></strong>)</span> ...
            <?php

            if (Disk::make_path(self::BACKUP_PATH, true)) {
                echo '<span style="color: green;">' . __("OK", 'wpopt') . '</span>';
            }
            else {
                echo '<span style="color: red;">' . __("FAIL", 'wpopt') . '</span>';
                echo '<div class="wps-notice wps-notice--error">' . sprintf(__('Backup folder does NOT exist or is NOT WRITABLE. Please create it and set permissions to \'774\' or change the location of the backup folder in settings.', 'wpopt'), WP_CONTENT_DIR) . '</div>';
            }
            ?>
        </section>
        <section>
            <form class="wpopt-ajax-db" method="post" data-module="<?php echo $this->slug ?>"
                  data-nonce="<?php echo wp_create_nonce('wpopt-ajax-nonce') ?>"
                  action="<?php echo wps_module_panel_url('database', 'db-backup'); ?>">
                <?php wp_nonce_field('wpopt-db-backup-manage'); ?>
                <input type="hidden" name="wpopt-db-do" value="db-manage-backup">

                <h3><?php _e('Manage Backups', 'wpopt'); ?></h3>
                <table class="widefat wps">
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

                    if (is_readable(self::BACKUP_PATH)) {

                        $database_files = array_filter(
                            array_merge(
                                glob(self::BACKUP_PATH . "*.sql"),
                                glob(self::BACKUP_PATH . "*.gz")
                            )
                        );

                        if (empty($database_files)) {
                            echo '<tr><td class="wps-centered" colspan="6">' . __('There Are No Database Backup Files Available.', 'wpopt') . '</td></tr>';
                        }
                        else {
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

                                $file_size = UtilEnv::filesize($database_file);

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
                    }
                    else {

                        if (!file_exists(self::BACKUP_PATH)) {
                            Disk::make_path(self::BACKUP_PATH, true);
                        }

                        echo '<tr><td class="wps-centered" colspan="6">' . __('There Are No Database Backup Files Available.', 'wpopt') . '</td></tr>';
                    }

                    ?>
                    <tr class="wps-footer">
                        <th colspan="4"><?php printf(_n('%s Backup found', '%s Backups found', $no, 'wpopt'), number_format_i18n($no)); ?></th>
                        <th colspan="2"><?php echo size_format($totalsize, 2); ?></th>
                    </tr>
                    <tr>
                        <td colspan="6" class="wps-centered wps-actions">
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
                  action="<?php echo wps_module_panel_url('database', 'db-backup'); ?>">
                <h3><?php _e('Backup Database', 'wpopt'); ?></h3>
                <table class="widefat wps">
                    <thead>
                    <tr>
                        <th><?php _e('Option', 'wpopt'); ?></th>
                        <th><?php _e('Value', 'wpopt'); ?></th>
                    </tr>
                    </thead>
                    <tr class="alternate">
                        <th><?php _e('Database Name:', 'wpopt'); ?></th>
                        <td><?php echo DB_NAME; ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Database size:', 'wpopt'); ?></th>
                        <td><?php echo size_format($wpdb->get_var("SELECT SUM(data_length + index_length) AS 'size' FROM information_schema.tables WHERE table_schema = '" . DB_NAME . "' GROUP BY table_schema;"), 2); ?></td>
                    </tr>
                    <tr class="alternate">
                        <th><?php _e('Database index size:', 'wpopt'); ?></th>
                        <td><?php echo size_format($wpdb->get_var("SELECT SUM(index_length) AS 'size' FROM information_schema.tables WHERE table_schema = '" . DB_NAME . "' GROUP BY table_schema;"), 2); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Database Backup Type:', 'wpopt'); ?></th>
                        <td><?php _e('Full (Structure and Data)', 'wpopt'); ?></td>
                    </tr>
                    <tr class="alternate">
                        <th><?php _e('MYSQL Dump Location:', 'wpopt'); ?></th>
                        <td>
                            <span>
                            <?php
                            if ($path = DBSupport::get_mysqlDump_cmd_path()) {
                                echo $path;
                            }
                            else {
                                echo __('No mysqldump found or not valid path set. Using sql export method will be slower.', 'wpopt');
                            }
                            ?>
                        </span>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2" class="wps-centered wps-actions">
                            <input data-action="backup" type="submit" name="action"
                                   value="<?php _e('Backup now', 'wpopt'); ?>"
                                   class="button"/>
                            <a class="button"
                               href="<?php echo wps_module_setting_url('wpopt', 'database') ?>">Settings</a>
                        </td>
                    </tr>
                </table>
            </form>
        </section>
        <?php
        return ob_get_clean();
    }

    public function ajax_handler($args = array()): void
    {
        $response = false;

        $action_args = empty($args['options']) ? '' : unserialize(base64_decode($args['options']));

        $form_data = array();
        parse_str($args['form_data'], $form_data);

        switch ($args['action']) {

            case 'exec-sql':
                $response = $this->exec_sql($form_data['sql_query']);
                break;

            case 'delete':
            case 'download':
            case 'restore':
            case 'backup':
                $response = $this->handle_database_actions($args['action'], array('file' => $form_data['file'] ?? ''));
                break;

            case 'sweep_details':
                $response = DBSupport::details($action_args['sweep-name']);
                break;

            case 'sweep':
                $sweep = DBSupport::sweep($action_args['sweep-name'], $this->option('sweep', array()));

                $count = DBSupport::count($action_args['sweep-name'], $this->option('sweep', array()));

                $total_count = DBSupport::total_count($action_args['sweep-type']);

                $response = array(
                    'sweep'      => $sweep,
                    'count'      => $count,
                    'total'      => $total_count,
                    'percentage' => UtilEnv::format_percentage($sweep, $total_count)
                );
                break;
        }

        if ($response) {
            Ajax::response($response, 'success');
        }
        else {
            Ajax::response(['text' => __('Invalid request.', 'wpopt')], 'error');
        }
    }

    private function exec_sql($sql): array
    {
        $sql = trim($sql);

        if (empty($sql)) {
            return $this->new_response(__('Empty Query', 'wpopt'), 'error');
        }

        $sql_queries = array();

        foreach (explode(';', $sql) as $sql_query) {

            $sql_query = trim(stripslashes($sql_query));

            $sql_query = preg_replace("/[\r\n]+/", ' ', $sql_query);

            if (!empty($sql_query)) {
                $sql_queries[] = $sql_query;
            }
        }

        if (empty($sql_queries)) {
            return $this->new_response(__('Empty query', 'wpopt'), 'error');
        }

        $total_query = $success_query = 0;
        $query_status = [];

        foreach ($sql_queries as $sql_query) {

            if (DBSupport::execute_sql($sql_query)) {
                $success_query++;
                $query_status[] = $this->new_response($sql_query, 'success');
            }
            else {
                $query_status[] = $this->new_response($sql_query, 'error');
            }

            $total_query++;
        }

        return $this->new_response(number_format_i18n($success_query) . '/' . number_format_i18n($total_query) . ' ' . __('Query(s) executed successfully', 'wpopt'), 'info', $query_status);
    }

    private function new_response($text, $status = 'success', $extra_data = []): array
    {
        return array('text' => $text, 'status' => $status, 'list' => $extra_data);
    }

    private function handle_database_actions($action, $args = array())
    {
        switch ($action) {

            case 'download':

                $database_file = sanitize_file_name($args['file']);

                if (empty($database_file)) {
                    $response = $this->new_response(__('No database backup selected', 'wpopt'), 'error');
                    break;
                }

                if (UtilEnv::download_file(self::BACKUP_PATH . $database_file)) {
                    $response = $this->new_response(__('Download started.', 'wpopt'), 'success');
                }
                else {
                    $response = $this->new_response(__('Something went wrong during the download.', 'wpopt'), 'error');
                }

                break;

            case 'delete':

                $database_file = sanitize_file_name($args['file']);

                if (empty($database_file)) {
                    $response = $this->new_response(__('No database backup selected', 'wpopt'), 'error');
                    break;
                }

                $file_path = self::BACKUP_PATH . $database_file;

                if (is_file($file_path)) {

                    if (unlink($file_path)) {
                        $response = $this->new_response(sprintf(__('Database backup \'%s\' deleted successfully.', 'wpopt'), $database_file), 'success');
                    }
                    else {
                        $response = $this->new_response(sprintf(__('Unable to delete \'%s\', check file permissions.', 'wpopt'), $database_file), 'error');
                    }
                }
                else {
                    $response = $this->new_response(sprintf(__('Invalid database backup \'%s\'.', 'wpopt'), $database_file), 'error');
                }

                break;

            case 'backup':

                $backup_path = self::BACKUP_PATH . substr(md5(time()), 0, 7) . '.sql';

                $res = false;

                if (DBSupport::get_mysqlDump_cmd_path($this->option('backup.mysqldump_path', ''))) {

                    $res = DBSupport::mysqlDump_db(
                        $backup_path,
                        $this->option('backup.excluded_tables', []),
                        $this->option('backup.mysqldump_path', '')
                    );
                }

                if (!$res) {
                    $res = DBSupport::queryDump_db(
                        $backup_path,
                        $this->option('backup.excluded_tables', [])
                    );
                }

                if ($res) {
                    $response = $this->new_response(__('Backup correctly done.', 'wpopt'), 'success');
                }
                else {
                    $response = $this->new_response(__('Something went wrong. Try again.', 'wpopt'), 'success');
                }

                break;

            case 'restore':

                $database_file = sanitize_file_name($args['file']);

                if (empty($database_file)) {
                    $response = $this->new_response(__('No database backup selected', 'wpopt'), 'error');
                    break;
                }

                if (DBSupport::restore_db(self::BACKUP_PATH . $database_file)) {
                    $response = $this->new_response(__('Database restored.', 'wpopt'), 'success');
                }
                else {
                    $response = $this->new_response(__('Something went wrong during the backup restore.', 'wpopt'), 'error');
                }
                break;

            default :
                $response = false;
        }

        return $response;
    }

    public function enqueue_scripts(): void
    {
        parent::enqueue_scripts();
        wp_enqueue_script('wpopt-db-sweep', UtilEnv::path_to_url(WPOPT_ABSPATH) . 'modules/supporters/database/database.js', array('vendor-wps-js'), WPOPT_VERSION);
    }

    public function render_sub_modules(): void
    {
        UtilEnv::rise_time_limit(WPOPT_DEBUG ? 120 : 60);
        ?>
        <section class="wps-wrap">
            <div id="wpopt-ajax-message" class="wps-notice"></div>
            <block class="wps">
                <section class='wps-header'><h1>Database Manager</h1></section>
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
                      __('Duplicated Postmeta', 'wpopt') => $this->option('sweeper.duplicated_postmeta') ? 'duplicated_postmeta' : '',
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

        ob_start();
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

                        if (empty($sweep_id)) {
                            continue;
                        }

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
                                <span
                                        class="sweep-percentage"><?php echo UtilEnv::format_percentage($count, $total); ?></span>
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

        return ob_get_clean();
    }

    public function validate_settings($input, $filtering = false): array
    {
        $new_valid = parent::validate_settings($input, $filtering);

        $new_valid['backup']['excluded_tables'] = array_map('esc_sql', $new_valid['backup']['excluded_tables']);

        return $new_valid;
    }

    public function restricted_access($context = 'settings'): bool
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

    protected function init(): void
    {
        require_once WPOPT_SUPPORTERS . '/database/DBSupport.class.php';
    }

    protected function setting_fields($filter = ''): array
    {
        return $this->group_setting_fields(

            $this->setting_field(__('Sweeper:', 'wpopt'), false, "separator"),
            $this->setting_field(__('Check for duplicate postmeta?', 'wpopt'), "sweeper.duplicated_postmeta", "checkbox", ['default_value' => false]),

            $this->setting_field(__('Backups:', 'wpopt'), false, "separator"),
            $this->setting_field(__('Excluded Tables (one per line)', 'wpopt'), "backup.excluded_tables", "textarea_array", ['value' => implode("\n", $this->option('backup.excluded_tables', []))])
        );
    }
}

return __NAMESPACE__;