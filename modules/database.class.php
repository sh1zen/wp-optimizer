<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use WPOptimizer\modules\supporters\DB_List_Table;
use WPOptimizer\modules\supporters\DBSupport;
use WPS\core\Ajax;
use WPS\core\Disk;
use WPS\core\Graphic;
use WPS\core\List_Table;
use WPS\core\UtilEnv;
use WPS\modules\Module;

class Mod_Database extends Module
{
    public static ?string $name = 'Database Manager';

    const BACKUP_PATH = WPOPT_STORAGE . 'backup-db/';

    public array $scopes = array('admin-page', 'cron', 'settings');
    private int $ajax_limit = 100;

    protected string $context = 'wpopt';

    private bool $dependencies_loaded = false;

    public function cleanup(array $settings = array(), array $all_settings = array()): bool
    {
        wps('wpopt')->options->remove_all('cache', 'get_tables_data');

        return true;
    }

    private function load_dependencies(): void
    {
        if ($this->dependencies_loaded) {
            return;
        }

        require_once WPOPT_SUPPORTERS . '/database/DBSupport.class.php';

        $this->dependencies_loaded = true;
    }

    public function cron_handler($args = array())
    {
        $this->load_dependencies();

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

        $this->set_tables_list_request($settings['list_request'] ?? array());

        $table_list_obj = new DB_List_Table(wps_module_panel_url("database", "db-tables"));

        list($message, $status) = $table_list_obj->prepare_items();

        ob_start();

        if (!empty($message)) {
            echo '<div id="message" class="' . $status . '"><p>' . $message . '</p></div>';
        }

        ?>
        <form class="wps-list-table-form wpopt-db-tables-form" method="post" action="<?php echo wps_module_panel_url("database", "db-tables"); ?>">
            <?php $this->display_list_table_on_panel_url($table_list_obj, 'database', 'db-tables'); ?>
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
                        <div class="wpopt-db-button-row">
                            <input type="submit" name="button" value="<?php _e('Run', 'wpopt'); ?>" class="wps wps-button wpopt-btn is-warning"
                                   data-action="exec-sql"/>
                            <input type="button" name="cancel" value="<?php _e('Clear', 'wpopt'); ?>" class="wps wps-button wpopt-btn is-neutral"
                                   onclick='document.getElementById("sql_query").value = ""'/>
                        </div>
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

        $this->load_dependencies();
        $file_mods_disabled = $this->file_mods_disabled();

        ob_start();
        ?>
        <section class="wpopt-db-backup-panel">
            <?php
            $backup_path_ready = !$file_mods_disabled && Disk::make_path(self::BACKUP_PATH, true);
            ?>
            <div class="wpopt-db-backup-status <?php echo $backup_path_ready ? 'is-success' : 'is-error'; ?>">
                <span class="wpopt-db-backup-status-icon dashicons <?php echo $backup_path_ready ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                <span class="wpopt-db-backup-status-copy">
                    <strong><?php _e('Checking Backup Folder', 'wpopt'); ?></strong>
                    <code><?php echo esc_html(self::BACKUP_PATH); ?></code>
                </span>
                <span class="wpopt-db-backup-status-result">
                    <?php echo $backup_path_ready ? esc_html__('OK', 'wpopt') : esc_html__('FAIL', 'wpopt'); ?>
                </span>
            </div>
            <?php

            if ($file_mods_disabled) {
                echo '<div class="wps-notice wps-notice--warning">' . esc_html__('Database backup file management is disabled because DISALLOW_FILE_MODS is enabled in wp-config.php.', 'wpopt') . '</div>';
            }
            elseif (!$backup_path_ready) {
                echo '<div class="wps-notice wps-notice--error">' . sprintf(__('Backup folder does NOT exist or is NOT WRITABLE. Please create it and set permissions to \'774\' or change the location of the backup folder in settings.', 'wpopt'), WP_CONTENT_DIR) . '</div>';
            }
            ?>
        </section>
        <section>
            <form class="wpopt-ajax-db wpopt-db-backup-table-form" method="post" data-module="<?php echo $this->slug ?>"
                  data-nonce="<?php echo wp_create_nonce('wpopt-ajax-nonce') ?>"
                  action="<?php echo wps_module_panel_url('database', 'db-backup'); ?>">
                <?php wp_nonce_field('wpopt-db-backup-manage'); ?>
                <input type="hidden" name="wpopt-db-do" value="db-manage-backup">

                <?php echo $this->render_database_backup_files_table($file_mods_disabled); ?>
            </form>
            <form class="wpopt-ajax-db wpopt-db-backup-form" method="post" data-module="<?php echo $this->slug ?>"
                  data-nonce="<?php echo wp_create_nonce('wpopt-ajax-nonce') ?>"
                  action="<?php echo wps_module_panel_url('database', 'db-backup'); ?>">
                <h3><?php _e('Backup Database', 'wpopt'); ?></h3>
                <?php echo $this->render_database_backup_summary_panel($file_mods_disabled); ?>
            </form>
        </section>
        <?php
        return ob_get_clean();
    }

    private function render_database_backup_files_table(bool $file_mods_disabled): string
    {
        return List_Table::generateHTML_table($this->get_database_backup_files_table_args($file_mods_disabled));
    }

    private function get_database_backup_files_table_args(bool $file_mods_disabled): array
    {
        $data = $this->get_database_backup_files_table_data($file_mods_disabled);
        $count = (int)$data['count'];

        return array(
            'class' => 'wps wpopt-db-backups-table',
            'columns' => $this->get_database_backup_files_columns(),
            'rows' => $data['rows'],
            'footer_rows' => array(
                array(
                    'class' => 'wps-footer',
                    'cells' => array(
                        'number' => array(
                            'class' => 'wpopt-db-backup-summary-cell',
                            'attributes' => array('colspan' => count($this->get_database_backup_files_columns())),
                            'content' => '<div class="wpopt-db-backup-summary-row"><strong>' . sprintf(
                                esc_html(_n('%s Backup found', '%s Backups found', $count, 'wpopt')),
                                esc_html(number_format_i18n($count))
                            ) . '</strong><span>' . esc_html(size_format((int)$data['total_size'], 2)) . '</span></div>',
                        ),
                    ),
                ),
            ),
            'empty' => __('There Are No Database Backup Files Available.', 'wpopt'),
        );
    }

    private function get_database_backup_files_table_data(bool $file_mods_disabled): array
    {
        $rows = array();
        $total_size = 0;
        $count = 0;

        if (is_readable(self::BACKUP_PATH)) {
            $database_files = array_filter(array_merge(
                (array)glob(self::BACKUP_PATH . '*.sql'),
                (array)glob(self::BACKUP_PATH . '*.gz')
            ));

            usort($database_files, static function ($a, $b) {
                return filemtime($a) - filemtime($b);
            });

            foreach ($database_files as $database_file) {
                $count++;
                $file_size = UtilEnv::filesize($database_file);
                $display_name = strlen($database_file) > 50 ? substr($database_file, 0, 25) . '.....' . substr($database_file, -24) : $database_file;

                $rows[] = array(
                    'number' => esc_html(number_format_i18n($count)),
                    'checksum' => '<code>' . esc_html(md5($database_file)) . '</code>',
                    'file' => esc_html($display_name),
                    'created' => esc_html(get_date_from_gmt(date('Y-m-d H:i:s', filemtime($database_file)))),
                    'size' => esc_html(size_format($file_size)),
                    'actions' => $this->render_database_backup_file_actions(basename($database_file), $file_mods_disabled),
                );

                $total_size += $file_size;
            }
        }
        else {
            if (!$file_mods_disabled && !file_exists(self::BACKUP_PATH)) {
                Disk::make_path(self::BACKUP_PATH, true);
            }
        }

        return array(
            'rows' => $rows,
            'count' => $count,
            'total_size' => $total_size,
        );
    }

    private function get_database_backup_files_columns(): array
    {
        return array(
            'number' => __('No.', 'wpopt'),
            'checksum' => __('MD5 Checksum', 'wpopt'),
            'file' => __('Database File', 'wpopt'),
            'created' => __('Creation date', 'wpopt'),
            'size' => __('Size', 'wpopt'),
            'actions' => __('Actions', 'wpopt'),
        );
    }

    private function render_database_backup_file_actions(string $database_file, bool $file_mods_disabled): string
    {
        $args = esc_attr(base64_encode(serialize(array('file' => $database_file))));
        $file = esc_attr($database_file);
        $disabled_title = esc_attr($file_mods_disabled ? __('Disabled by DISALLOW_FILE_MODS in wp-config.php.', 'wpopt') : '');

        ob_start();
        ?>
        <div class="wpopt-db-backup-row-actions">
            <button type="submit" class="wps wps-button wpopt-btn is-info" data-action="download" data-file="<?php echo $file; ?>" data-args="<?php echo $args; ?>">
                <?php esc_html_e('Download', 'wpopt'); ?>
            </button>
            <button type="submit" class="wps wps-button wpopt-btn is-warning" data-action="restore" data-file="<?php echo $file; ?>" data-args="<?php echo $args; ?>"
                    data-wps-confirm="<?php echo esc_attr__("Are you sure to restore selected backup?\nAny data inserted after the backup date will be lost.\n\nThis action is not reversible.", 'wpopt'); ?>">
                <?php esc_html_e('Restore', 'wpopt'); ?>
            </button>
            <button type="submit" class="wps wps-button wpopt-btn is-danger" data-action="delete" data-file="<?php echo $file; ?>" data-args="<?php echo $args; ?>"
                    <?php disabled($file_mods_disabled); ?>
                    title="<?php echo $disabled_title; ?>"
                    data-wps-confirm="<?php echo esc_attr__('Are you sure to delete selected backup?', 'wpopt'); ?>">
                <?php esc_html_e('Delete', 'wpopt'); ?>
            </button>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_database_backup_summary_panel(bool $file_mods_disabled): string
    {
        ob_start();
        echo $this->render_database_backup_summary_cards();
        ?>
        <div class="wps-centered wps-actions wpopt-db-table-actions">
            <div class="wpopt-db-button-row">
                <input data-action="backup" type="submit" name="action"
                       value="<?php esc_attr_e('Backup now', 'wpopt'); ?>"
                       <?php disabled($file_mods_disabled); ?>
                       title="<?php echo esc_attr($file_mods_disabled ? __('Disabled by DISALLOW_FILE_MODS in wp-config.php.', 'wpopt') : ''); ?>"
                       class="wps wps-button wpopt-btn is-success">
                <a class="wps wps-button wpopt-btn is-neutral"
                   href="<?php echo esc_url(wps_module_setting_url('wpopt', 'database')); ?>"><?php esc_html_e('Settings', 'wpopt'); ?></a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_database_backup_summary_cards(): string
    {
        ob_start();
        ?>
        <div class="wpopt-db-backup-summary-grid" role="list">
            <?php foreach ($this->get_database_backup_summary_rows() as $item) : ?>
                <section class="wpopt-db-backup-summary-card <?php echo esc_attr((string)($item['class'] ?? '')); ?>" role="listitem">
                    <span class="wpopt-db-backup-summary-icon dashicons <?php echo esc_attr((string)($item['icon'] ?? 'dashicons-database')); ?>" aria-hidden="true"></span>
                    <span class="wpopt-db-backup-summary-label"><?php echo esc_html(rtrim((string)$item['option'], ':')); ?></span>
                    <strong class="wpopt-db-backup-summary-value"><?php echo (string)$item['value']; ?></strong>
                </section>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function get_database_backup_summary_rows(): array
    {
        global $wpdb;

        $mysql_dump = DBSupport::get_mysqlDump_cmd_path();
        $database_size = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(data_length + index_length) AS size FROM information_schema.tables WHERE table_schema = %s GROUP BY table_schema;",
                DB_NAME
            )
        );
        $database_index_size = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(index_length) AS size FROM information_schema.tables WHERE table_schema = %s GROUP BY table_schema;",
                DB_NAME
            )
        );

        return array(
            array(
                'option' => __('Database Name:', 'wpopt'),
                'value' => esc_html(DB_NAME),
                'icon' => 'dashicons-database',
            ),
            array(
                'option' => __('Database size:', 'wpopt'),
                'value' => esc_html(size_format((int)$database_size, 2)),
                'icon' => 'dashicons-chart-pie',
            ),
            array(
                'option' => __('Database index size:', 'wpopt'),
                'value' => esc_html(size_format((int)$database_index_size, 2)),
                'icon' => 'dashicons-chart-area',
            ),
            array(
                'option' => __('Database Backup Type:', 'wpopt'),
                'value' => esc_html__('Full (Structure and Data)', 'wpopt'),
                'icon' => 'dashicons-backup',
            ),
            array(
                'option' => __('MYSQL Dump Location:', 'wpopt'),
                'value' => $mysql_dump ? esc_html($mysql_dump) : esc_html__('No mysqldump found or not valid path set. Using sql export method will be slower.', 'wpopt'),
                'icon' => 'dashicons-admin-tools',
                'class' => 'is-wide',
            ),
        );
    }

    public function ajax_handler($args = array()): void
    {
        $this->load_dependencies();

        $response = false;

        if (($args['action'] ?? '') === 'render_panel') {
            $panel_id = $this->get_requested_panel_id($args['options'] ?? '');
            $panel_options = is_array($args['options'] ?? null) ? $args['options'] : array();
            $html = $this->render_database_panel_content($panel_id, $panel_options);

            if ($html !== '') {
                Ajax::response(array('html' => $html), 'success');
            }

            Ajax::response(array('text' => __('Invalid database panel.', 'wpopt')), 'error');
        }

        $action_args = [];

        if (!empty($args['options']) && is_string($args['options'])) {
            $decoded_options = base64_decode($args['options'], true);

            if (is_string($decoded_options) && '' !== $decoded_options) {
                $decoded_args = @unserialize($decoded_options, ['allowed_classes' => false]);

                if (is_array($decoded_args)) {
                    $action_args = $decoded_args;
                }
            }
        }

        $form_data = array();
        parse_str((string)($args['form_data'] ?? ''), $form_data);

        switch ($args['action']) {

            case 'exec-sql':
                $response = $this->exec_sql((string)($form_data['sql_query'] ?? ''));
                break;

            case 'delete':
            case 'download':
            case 'restore':
            case 'backup':
                $response = $this->handle_database_actions($args['action'], array('file' => $form_data['file'] ?? $action_args['file'] ?? ''));
                break;

            case 'sweep_details':
                $sweep_name = (string)($action_args['sweep-name'] ?? '');
                $response = $sweep_name ? DBSupport::details($sweep_name) : false;
                break;

            case 'sweep':
                $sweep_name = (string)($action_args['sweep-name'] ?? '');
                $sweep_type = (string)($action_args['sweep-type'] ?? '');

                if ('' === $sweep_name || '' === $sweep_type) {
                    $response = false;
                    break;
                }

                $sweep = DBSupport::sweep($sweep_name, $this->option('sweep', array()));

                DBSupport::clear_count_cache(array($sweep_name), array($sweep_type));

                $count = DBSupport::count($sweep_name, $this->option('sweep', array()));

                $total_count = DBSupport::total_count($sweep_type);

                $response = array(
                        'sweep'      => $sweep,
                        'count'      => $count,
                        'total'      => $total_count,
                        'percentage' => UtilEnv::format_percentage($sweep, $total_count)
                );
                break;

            case 'option_toggle_autoload':
            case 'option_delete':
                $option_name = (string)($action_args['option-name'] ?? '');
                $response = $this->handle_option_action($args['action'], $option_name);
                break;

            case 'option_preview':
                $option_name = (string)($action_args['option-name'] ?? '');
                $preview = DBSupport::get_option_preview($option_name, 0);
                $response = $preview ? $this->new_response(__('Option preview loaded.', 'wpopt'), 'success', $preview) : false;
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

    private function handle_option_action(string $action, string $option_name): array
    {
        $option_name = DBSupport::sanitize_option_name($option_name);

        if ('' === $option_name) {
            return $this->new_response(__('Invalid option selected.', 'wpopt'), 'error');
        }

        if ('option_toggle_autoload' === $action) {
            $result = DBSupport::toggle_option_autoload($option_name);

            if (!$result['success']) {
                return $this->new_response($result['message'], 'error', array(
                        'summary' => DBSupport::get_options_autoload_summary(),
                ));
            }

            return $this->new_response($result['message'], 'success', array(
                    'option'  => DBSupport::get_option_row_summary($option_name),
                    'summary' => DBSupport::get_options_autoload_summary(),
            ));
        }

        if ('option_delete' === $action) {
            $result = DBSupport::delete_option_row($option_name);

            if (!$result['success']) {
                return $this->new_response($result['message'], 'error', array(
                        'summary' => DBSupport::get_options_autoload_summary(),
                ));
            }

            return $this->new_response($result['message'], 'success', array(
                    'deleted' => true,
                    'option_name' => $option_name,
                    'summary' => DBSupport::get_options_autoload_summary(),
            ));
        }

        return $this->new_response(__('Invalid request.', 'wpopt'), 'error');
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

                if ($this->file_mods_disabled()) {
                    $response = $this->new_response(__('Deleting backup files is disabled because DISALLOW_FILE_MODS is enabled in wp-config.php.', 'wpopt'), 'error');
                    break;
                }

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

                if ($this->file_mods_disabled()) {
                    $response = $this->new_response(__('Creating database backup files is disabled because DISALLOW_FILE_MODS is enabled in wp-config.php.', 'wpopt'), 'error');
                    break;
                }

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

    private function file_mods_disabled(): bool
    {
        return defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS;
    }

    public function enqueue_scripts(): void
    {
        parent::enqueue_scripts();
        $script_asset = UtilEnv::resolve_asset(WPOPT_ABSPATH, 'modules/supporters/database/database.js', wps_core()->online);
        wp_enqueue_script('wpopt-db-sweep', $script_asset['url'], array('vendor-wps-js'), $script_asset['version'] ?: WPOPT_VERSION);
    }

    public function render_sub_modules(bool $standalone = true): void
    {
        ?>
        <section class="wps-wrap wpopt-db-manager-page">
            <block class="wps wpopt-db-shell">
                <?php
                echo Graphic::generateHTML_tabs_panels(array(
                        array(
                                'id'          => 'db-sweeper',
                                'tab-title'   => __('Database sweeper', 'wpopt'),
                                'panel-title' => __('Database Sweeper', 'wpopt'),
                                'panel-icon'  => 'broom',
                                'panel-description' => __('Remove unnecessary data and optimize your site database.', 'wpopt'),
                                'callback'    => array($this, 'render_lazy_panel_placeholder'),
                                'args'        => array('db-sweeper')
                        ),
                        array(
                                'id'          => 'db-tables',
                                'tab-title'   => __('Tables', 'wpopt'),
                                'panel-title' => __('Database tables', 'wpopt'),
                                'panel-icon'  => 'grid',
                                'callback'    => array($this, 'render_lazy_panel_placeholder'),
                                'args'        => array('db-tables')
                        ),
                        array(
                                'id'          => 'db-options',
                                'tab-title'   => __('Options', 'wpopt'),
                                'panel-title' => __('WordPress options', 'wpopt'),
                                'panel-icon'  => 'settings',
                                'panel-description' => __('Review wp_options rows, autoload cost and risky cleanup actions.', 'wpopt'),
                                'callback'    => array($this, 'render_lazy_panel_placeholder'),
                                'args'        => array('db-options')
                        ),

                        array(
                                'id'          => 'db-backup',
                                'tab-title'   => __('Backup Manager', 'wpopt'),
                                'panel-title' => __('Backup your database', 'wpopt'),
                                'panel-icon'  => 'database',
                                'callback'    => array($this, 'render_lazy_panel_placeholder'),
                                'args'        => array('db-backup')
                        ),
                        array(
                                'id'          => 'db-runsql',
                                'tab-title'   => __('Run SQL Query', 'wpopt'),
                                'panel-title' => __('Run SQL Query', 'wpopt'),
                                'panel-icon'  => 'settings',
                                'callback'    => array($this, 'render_lazy_panel_placeholder'),
                                'args'        => array('db-runsql')
                        )
                ));
                ?>
            </block>
        </section>
        <?php
    }

    public function render_lazy_panel_placeholder(string $panel_id): string
    {
        return sprintf(
                '<div class="wpopt-db-lazy-panel" data-panel="%1$s" data-nonce="%2$s"><strong>%3$s</strong></div>',
                esc_attr($panel_id),
                esc_attr(wp_create_nonce('wpopt-ajax-nonce')),
                esc_html__('Loading section...', 'wpopt')
        );
    }

    private function get_requested_panel_id($options): string
    {
        if (is_array($options)) {
            return sanitize_key((string)($options['panel'] ?? ''));
        }

        return sanitize_key((string)$options);
    }

    private function render_database_panel_content(string $panel_id, array $options = array()): string
    {
        switch ($panel_id) {
            case 'db-sweeper':
                UtilEnv::rise_time_limit(WPOPT_DEBUG ? 120 : 60);
                return $this->render_sweeper_panel();

            case 'db-tables':
                return $this->render_tablesList_panel(array(
                        'list_request' => $this->sanitize_tables_list_request($options)
                ));

            case 'db-options':
                return $this->render_options_panel(array(
                        'list_request' => $this->sanitize_options_list_request($options)
                ));

            case 'db-backup':
                return $this->render_backup_panel($this->option('backup', array()));

            case 'db-runsql':
                return $this->render_execSQL_panel();
        }

        return '';
    }

    private function sanitize_tables_list_request(array $options): array
    {
        $request = array();

        if (isset($options['paged'])) {
            $request['paged'] = max(1, absint($options['paged']));
        }

        if (isset($options['orderby'])) {
            $orderby = sanitize_key((string)$options['orderby']);

            if (in_array($orderby, array('table_name', 'table_rows', 'data_length', 'data_free', 'engine'), true)) {
                $request['orderby'] = $orderby;
            }
        }

        if (isset($options['order'])) {
            $order = strtoupper(sanitize_key((string)$options['order']));

            if (in_array($order, array('ASC', 'DESC'), true)) {
                $request['order'] = $order;
            }
        }

        if (isset($options['s'])) {
            $request['s'] = sanitize_text_field(wp_unslash((string)$options['s']));
        }

        return $request;
    }

    private function sanitize_options_list_request(array $options): array
    {
        $request = array();

        if (isset($options['paged'])) {
            $request['paged'] = max(1, absint($options['paged']));
        }

        if (isset($options['orderby'])) {
            $orderby = sanitize_key((string)$options['orderby']);

            if (in_array($orderby, array('option_name', 'option_size', 'autoload'), true)) {
                $request['orderby'] = $orderby;
            }
        }

        if (isset($options['order'])) {
            $order = strtoupper(sanitize_key((string)$options['order']));

            if (in_array($order, array('ASC', 'DESC'), true)) {
                $request['order'] = $order;
            }
        }

        if (isset($options['s'])) {
            $request['s'] = sanitize_text_field(wp_unslash((string)$options['s']));
        }

        if (isset($options['wpopt_autoload'])) {
            $autoload_filter = sanitize_key((string)$options['wpopt_autoload']);

            if (in_array($autoload_filter, array('all', 'autoload'), true)) {
                $request['wpopt_autoload'] = $autoload_filter;
            }
        }

        return $request;
    }

    private function set_tables_list_request(array $request): void
    {
        foreach (array('paged', 'orderby', 'order', 's') as $key) {
            if (!isset($request[$key])) {
                continue;
            }

            $_REQUEST[$key] = $request[$key];

            if ('s' !== $key) {
                $_GET[$key] = $request[$key];
            }
        }
    }

    public function render_sweeper_panel()
    {
        $this->load_dependencies();

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
        <section class="wpopt-db-sweeper">
            <div class="wpopt-db-notice">
                <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                <div>
                    <strong><?php _e('Always back up the database before continuing.', 'wpopt'); ?></strong>
                    <p><?php _e('This tool removes unnecessary data and optimizes tables to improve site performance.', 'wpopt'); ?></p>
                    <small><?php echo sprintf(__('Details show up to %s items for each check.', 'wpopt'), number_format_i18n($this->ajax_limit)); ?></small>
                </div>
                <a class="wps wps-button wpopt-btn is-neutral" href="<?php echo esc_url(wps_module_panel_url('database', 'db-backup')); ?>">
                    <span class="dashicons dashicons-shield-alt"></span><?php _e('Create backup now', 'wpopt'); ?>
                </a>
            </div>

            <div class="wpopt-db-sweeper-toolbar">
                <div>
                    <strong><?php _e('Database Sweeper', 'wpopt'); ?></strong>
                    <p><?php _e('Remove unnecessary data and optimize your site database.', 'wpopt'); ?></p>
                </div>
                <button type="button" class="wps wps-button wpopt-btn is-neutral">
                    <?php _e('Expand all', 'wpopt'); ?><span class="dashicons dashicons-arrow-down-alt2"></span>
                </button>
            </div>
        <?php

        $nonce = wp_create_nonce('wpopt-ajax-nonce');

        foreach ($sweepers as $sweeper) {

            $total = DBSupport::total_count($sweeper['type']);
            if (!$total)
                continue;
            ?>
            <section class="list-tables wpopt-db-sweep-card">
                <pre-table>
                    <span class="wpopt-db-sweep-icon dashicons dashicons-media-document"></span>
                    <?php
                    echo "<h3>{$sweeper['title']}</h3>";
                    echo "<p>" . sprintf(__('Total: <strong class="attention"><span>%1$s</span></strong> %2$s.', 'wpopt'), number_format_i18n($total), esc_html($sweeper['type'])) . "</p>";
                    echo "<span class='wpopt-db-sweep-count'>" . sprintf(_n('%s item', '%s items', $total, 'wpopt'), number_format_i18n($total)) . "</span>";
                    ?>
                </pre-table>
                <?php echo $this->render_sweeper_table($sweeper, $total, $nonce); ?>
            </section>
            <?php
        }
        ?>
        </section>
        <?php

        return ob_get_clean();
    }

    private function render_sweeper_table(array $sweeper, int $total, string $nonce): string
    {
        $rows = array();

        foreach ($sweeper['sweeps'] as $name => $sweep_id) {
            if (empty($sweep_id)) {
                continue;
            }

            $count = DBSupport::count($sweep_id);
            $actions = esc_html__('None', 'wpopt');

            if ($count > 0) {
                $data = base64_encode(serialize(array('sweep-name' => $sweep_id, 'sweep-type' => $sweeper['type'])));
                $actions = '<button data-action="sweep" data-args="' . esc_attr($data) . '" data-nonce="' . esc_attr($nonce) . '" class="wps wps-button wpopt-btn is-warning wpopt-sweep">' . esc_html__('Sweep', 'wpopt') . '</button>';
                $actions .= '<button data-action="sweep_details" data-args="' . esc_attr($data) . '" data-nonce="' . esc_attr($nonce) . '" class="wps wps-button wpopt-btn is-info wpopt-sweep-details">' . esc_html__('Details', 'wpopt') . '</button>';
            }

            $rows[] = array(
                'details' => '<strong>' . esc_html($name) . '</strong><div class="hidden sweep-details"></div>',
                'count' => '<span class="sweep-count">' . esc_html(number_format_i18n($count)) . '</span>',
                'percent' => '<span class="sweep-percentage">' . esc_html(UtilEnv::format_percentage($count, $total)) . '</span>',
                'action' => $actions,
            );
        }

        return List_Table::generateHTML_table(array(
            'class' => 'table-sweep',
            'columns' => array(
                'details' => __('Details', 'wpopt'),
                'count' => __('Count', 'wpopt'),
                'percent' => __('% Of', 'wpopt'),
                'action' => __('Action', 'wpopt'),
            ),
            'rows' => $rows,
            'empty' => __('No sweep actions available.', 'wpopt'),
        ));
    }

    /**
     * Handle the gui for wp_options list.
     */
    public function render_options_panel($settings = array()): string
    {
        $this->load_dependencies();

        $request = $this->sanitize_options_list_request($settings['list_request'] ?? array());
        $request = $this->normalize_options_list_request($request);
        $summary = DBSupport::get_options_autoload_summary();
        $per_page = 25;
        $current_page = max(1, (int)($request['paged'] ?? 1));
        $total_items = DBSupport::count_options($request);
        $total_pages = max(1, (int)ceil($total_items / $per_page));

        if ($current_page > $total_pages) {
            $current_page = $total_pages;
            $request['paged'] = $current_page;
        }

        $rows = DBSupport::get_options_data($request, $per_page, $current_page);

        ob_start();
        ?>
        <section class="wpopt-db-options-panel">
            <div class="wpopt-db-options-summary" data-role="wpopt-options-summary">
                <?php echo $this->render_options_summary_cards($summary); ?>
            </div>
            <div class="wpopt-db-options-warning">
                <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                <div>
                    <strong><?php _e('Be careful: wrong changes here can break the site.', 'wpopt'); ?></strong>
                    <p><?php _e('Disabling autoload on required options can cause extra database queries or broken plugin/theme behavior. Deleting an option can permanently remove configuration, scheduled data, licenses, cache state or serialized settings. Create a database backup before changing anything you are not sure about.', 'wpopt'); ?></p>
                </div>
            </div>
            <form class="wps-list-table-form wpopt-db-options-form" method="get" action="<?php echo esc_url(wps_module_panel_url("database", "db-options")); ?>">
                <input type="hidden" name="page" value="<?php echo esc_attr(wps_admin_menu_slug($this->context)); ?>"/>
                <input type="hidden" name="wps-page" value="module-database"/>
                <?php echo $this->render_options_table_controls($request, $total_items, $total_pages, $current_page); ?>
                <?php echo $this->render_options_table($rows, $request); ?>
                <?php echo $this->render_options_table_bottom_controls($request, $total_items, $total_pages, $current_page); ?>
            </form>
        </section>
        <?php

        return ob_get_clean();
    }

    private function render_options_table_bottom_controls(array $request, int $total_items, int $total_pages, int $current_page): string
    {
        $pagination = $this->render_options_pagination($request, $total_items, $total_pages, $current_page, 'bottom');

        if ('' === $pagination) {
            return '';
        }

        return '<div class="tablenav bottom">' . $pagination . '<br class="clear"/></div>';
    }

    private function normalize_options_list_request(array $request): array
    {
        $request['wpopt_autoload'] = $request['wpopt_autoload'] ?? 'all';
        $request['orderby'] = $request['orderby'] ?? 'option_size';
        $request['order'] = $request['order'] ?? 'DESC';
        $request['paged'] = max(1, (int)($request['paged'] ?? 1));

        return $request;
    }

    private function render_options_table_controls(array $request, int $total_items, int $total_pages, int $current_page): string
    {
        ob_start();
        ?>
        <div class="tablenav top">
            <div class="alignleft actions wpopt-options-filters">
                <label class="screen-reader-text" for="wpopt-autoload-filter"><?php esc_html_e('Filter by autoload', 'wpopt'); ?></label>
                <select id="wpopt-autoload-filter" name="wpopt_autoload">
                    <option value="all" <?php selected($request['wpopt_autoload'], 'all'); ?>><?php esc_html_e('All options', 'wpopt'); ?></option>
                    <option value="autoload" <?php selected($request['wpopt_autoload'], 'autoload'); ?>><?php esc_html_e('Autoload only', 'wpopt'); ?></option>
                </select>
                <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'wpopt'); ?>"/>
            </div>
            <p class="search-box">
                <label class="screen-reader-text" for="search-search-input"><?php esc_html_e('Search', 'wpopt'); ?></label>
                <input type="search" id="search-search-input" name="s" value="<?php echo esc_attr((string)($request['s'] ?? '')); ?>"/>
                <input type="submit" id="search-submit" class="button" value="<?php esc_attr_e('Search', 'wpopt'); ?>"/>
            </p>
            <?php echo $this->render_options_pagination($request, $total_items, $total_pages, $current_page, 'top'); ?>
            <br class="clear"/>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_options_table(array $rows, array $request): string
    {
        $columns = array(
                'option_name' => array('label' => __('Option name', 'wpopt'), 'sortable' => true),
                'option_size' => array('label' => __('Size', 'wpopt'), 'sortable' => true),
                'autoload'    => array('label' => __('Autoload', 'wpopt'), 'sortable' => true),
                'actions'     => array('label' => __('Actions', 'wpopt')),
        );

        $table_rows = array();

        foreach ($rows as $row) {
            $option_name = (string)($row['option_name'] ?? '');
            $protected = DBSupport::is_protected_option($option_name);
            $table_rows[] = array(
                    'option_name' => $this->render_option_name($option_name, $protected),
                    'option_size' => '<span class="wpopt-option-size">' . esc_html(size_format(absint($row['option_size'] ?? 0), 2)) . '</span>',
                    'autoload'    => $this->render_option_autoload($option_name, (string)($row['autoload'] ?? ''), $protected),
                    'actions'     => $this->render_option_actions($option_name, $protected),
            );
        }

        return List_Table::generateHTML_table(array(
                'class'   => 'wpopt-options-table',
                'columns' => $columns,
                'rows'    => $table_rows,
                'empty'   => __('No options found.', 'wpopt'),
                'sort_url' => $this->get_options_table_url($request, array('paged' => null)),
                'orderby' => (string)$request['orderby'],
                'order'   => (string)$request['order'],
        ));
    }

    private function render_option_name(string $option_name, bool $protected): string
    {
        $args = esc_attr(base64_encode(serialize(array('option-name' => $option_name))));
        $nonce = esc_attr(wp_create_nonce('wpopt-ajax-nonce'));
        $icon = '<svg class="wpopt-option-name-eye" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M12 5c5 0 8.6 4.4 9.7 5.9a1.8 1.8 0 0 1 0 2.2C20.6 14.6 17 19 12 19s-8.6-4.4-9.7-5.9a1.8 1.8 0 0 1 0-2.2C3.4 9.4 7 5 12 5Zm0 2C8 7 5 10.4 4 12c1 1.6 4 5 8 5s7-3.4 8-5c-1-1.6-4-5-8-5Zm0 2.25A2.75 2.75 0 1 1 12 14.75 2.75 2.75 0 0 1 12 9.25Zm0 2A.75.75 0 1 0 12 12.75.75.75 0 0 0 12 11.25Z" fill="currentColor"/></svg>';
        $protected_label = $protected ? '<span class="wpopt-option-protected"><span class="dashicons dashicons-lock"></span>' . esc_html__('Protected', 'wpopt') . '</span>' : '';

        return sprintf(
                '<button type="button" class="wpopt-option-name-preview wpopt-option-preview" data-action="option_preview" data-args="%1$s" data-nonce="%2$s" aria-label="%3$s">%4$s<code class="wpopt-option-name">%5$s</code></button>%6$s',
                $args,
                $nonce,
                esc_attr(sprintf(__('Show option "%s" here', 'wpopt'), $option_name)),
                $icon,
                esc_html($option_name),
                $protected_label
        );
    }

    private function render_option_autoload(string $option_name, string $autoload, bool $protected): string
    {
        $autoloads = DBSupport::option_autoloads($autoload);

        return sprintf(
                '<input type="checkbox" class="wps-apple-switch wpopt-option-autoload-toggle" data-action="option_toggle_autoload" data-args="%1$s" data-nonce="%2$s" aria-label="%3$s" %4$s %5$s>',
                esc_attr(base64_encode(serialize(array('option-name' => $option_name)))),
                esc_attr(wp_create_nonce('wpopt-ajax-nonce')),
                esc_attr(sprintf(__('Toggle autoload for "%s"', 'wpopt'), $option_name)),
                checked($autoloads, true, false),
                disabled($protected, true, false)
        );
    }

    private function render_option_actions(string $option_name, bool $protected): string
    {
        $args = esc_attr(base64_encode(serialize(array('option-name' => $option_name))));
        $nonce = esc_attr(wp_create_nonce('wpopt-ajax-nonce'));

        if ($protected) {
            return '<div class="wpopt-option-actions is-protected"><span class="wpopt-option-action-note">' . esc_html__('Core/plugin option protected.', 'wpopt') . '</span></div>';
        }

        $delete_button = sprintf(
                '<button type="button" class="wps wps-button wpopt-btn is-danger wpopt-option-action" data-action="option_delete" data-args="%1$s" data-nonce="%2$s" data-confirm="%3$s">%4$s</button>',
                $args,
                $nonce,
                esc_attr__('Deleting the wrong option can break the site or remove plugin/theme settings. Continue?', 'wpopt'),
                esc_html__('Delete', 'wpopt')
        );

        return '<div class="wpopt-option-actions">' . $delete_button . '</div>';
    }

    private function render_options_pagination(array $request, int $total_items, int $total_pages, int $current_page, string $which): string
    {
        if ($total_items <= 0 || $total_pages <= 1) {
            return '';
        }

        $page_url = function (int $page) use ($request): string {
            return esc_url($this->get_options_table_url($request, array('paged' => max(1, $page))));
        };

        $nav_button = static function (string $class, string $label, string $symbol, ?string $url): string {
            if ($url === null) {
                return '';
            }

            return '<a class="' . esc_attr($class) . ' button" href="' . $url . '"><span class="screen-reader-text">' . esc_html($label) . '</span><span aria-hidden="true">' . esc_html($symbol) . '</span></a>';
        };

        ob_start();
        ?>
        <div class="tablenav-pages <?php echo esc_attr($which); ?>">
            <span class="displaying-num"><?php echo esc_html(sprintf(_n('%s item', '%s items', $total_items, 'wpopt'), number_format_i18n($total_items))); ?></span>
            <span class="pagination-links">
                <?php echo $nav_button('first-page', __('First page', 'wpopt'), '<<', $current_page > 1 ? $page_url(1) : null); ?>
                <?php echo $nav_button('prev-page', __('Previous page', 'wpopt'), '<', $current_page > 1 ? $page_url($current_page - 1) : null); ?>
                <span class="paging-input wpopt-pagination-status" aria-label="<?php echo esc_attr(sprintf(__('Page %1$s of %2$s', 'wpopt'), number_format_i18n($current_page), number_format_i18n($total_pages))); ?>">
                    <span class="current-page" aria-current="page"><?php echo esc_html(number_format_i18n($current_page)); ?></span>
                    <span class="wps-page-separator" aria-hidden="true">...</span>
                    <span class="wps-total-pages"><?php echo esc_html(number_format_i18n($total_pages)); ?></span>
                </span>
                <?php echo $nav_button('next-page', __('Next page', 'wpopt'), '>', $current_page < $total_pages ? $page_url($current_page + 1) : null); ?>
                <?php echo $nav_button('last-page', __('Last page', 'wpopt'), '>>', $current_page < $total_pages ? $page_url($total_pages) : null); ?>
            </span>
        </div>
        <?php
        return ob_get_clean();
    }

    private function get_options_table_url(array $request, array $overrides = array()): string
    {
        $args = array(
                'wpopt_autoload' => $request['wpopt_autoload'] ?? 'all',
                's'              => $request['s'] ?? '',
                'orderby'        => $request['orderby'] ?? 'option_size',
                'order'          => $request['order'] ?? 'DESC',
                'paged'          => $request['paged'] ?? 1,
        );

        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
                unset($args[$key]);
            }
            else {
                $args[$key] = $value;
            }
        }

        foreach ($args as $key => $value) {
            if ($value === '' || ($key === 'wpopt_autoload' && $value === 'all') || ($key === 'paged' && (int)$value <= 1)) {
                unset($args[$key]);
            }
        }

        return add_query_arg($args, wps_module_panel_url('database', 'db-options'));
    }

    private function display_list_table_on_panel_url(\WP_List_Table $table, string $module, string $panel): void
    {
        $original_request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $_SERVER['REQUEST_URI'] = $this->get_admin_request_uri(wps_module_panel_url($module, $panel));

        try {
            $table->display();
        }
        finally {
            $_SERVER['REQUEST_URI'] = $original_request_uri;
        }
    }

    private function get_admin_request_uri(string $url): string
    {
        $path = wp_parse_url($url, PHP_URL_PATH) ?: '';
        $query = wp_parse_url($url, PHP_URL_QUERY);
        $fragment = wp_parse_url($url, PHP_URL_FRAGMENT);

        return $path . ($query ? '?' . $query : '') . ($fragment ? '#' . $fragment : '');
    }

    private function render_options_summary_cards(array $summary): string
    {
        $autoload_size = (int)($summary['autoload_size'] ?? 0);
        $autoload_count = (int)($summary['autoload_count'] ?? 0);
        $total_size = (int)($summary['total_size'] ?? 0);
        $total_count = (int)($summary['total_count'] ?? 0);

        ob_start();
        ?>
        <div class="wpopt-db-options-card" data-stat="autoload-size">
            <span><?php _e('Total Autoload Size', 'wpopt'); ?></span>
            <strong><?php echo esc_html(size_format($autoload_size, 2)); ?></strong>
        </div>
        <div class="wpopt-db-options-card" data-stat="autoload-count">
            <span><?php _e('Autoloaded Options', 'wpopt'); ?></span>
            <strong><?php echo esc_html(number_format_i18n($autoload_count)); ?></strong>
        </div>
        <div class="wpopt-db-options-card" data-stat="total-count">
            <span><?php _e('Total Options', 'wpopt'); ?></span>
            <strong><?php echo esc_html(number_format_i18n($total_count)); ?></strong>
        </div>
        <div class="wpopt-db-options-card" data-stat="total-size">
            <span><?php _e('Total Options Size', 'wpopt'); ?></span>
            <strong><?php echo esc_html(size_format($total_size, 2)); ?></strong>
        </div>
        <?php
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
    }

    protected function setting_fields($filter = ''): array
    {
        return $this->group_setting_fields(
            $this->group_setting_fields(
                $this->setting_field(__('Sweeper', 'wpopt'), false, "separator"),
                $this->setting_field(__('Check for duplicate postmeta?', 'wpopt'), "sweeper.duplicated_postmeta", "checkbox", ['default_value' => false])
            ),
            $this->group_setting_fields(
                $this->setting_field(__('Backups', 'wpopt'), false, "separator"),
                $this->setting_field(__('Excluded Tables (one per line)', 'wpopt'), "backup.excluded_tables", "textarea_array", ['value' => implode("\n", $this->option('backup.excluded_tables', []))])
            )
        );
    }
}

return __NAMESPACE__;

