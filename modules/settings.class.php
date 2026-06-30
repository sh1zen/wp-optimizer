<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use WPS\core\RequestActions;
use WPS\core\addon\Exporter;
use WPS\core\Graphic;
use WPS\core\List_Table;
use WPS\modules\Module;

class Mod_Settings extends Module
{
    public static ?string $name = 'Settings';

    private const BACKUP_OPTION = 'wpopt_configuration_backups';
    private const BACKUP_ITEM = 'configuration_backups';
    private const BACKUP_CONTEXT = 'settings';
    private const BACKUP_MIN_INTERVAL_SECONDS = 900;
    private const BACKUP_MAX_ENTRIES = 50;

    public array $scopes = array('core-settings', 'admin', 'ajax');

    protected string $context = 'wpopt';

    protected function init(): void
    {
        add_filter('pre_update_option_wpopt', array($this, 'backup_before_wpopt_option_update'), 10, 3);
    }

    public function restricted_access($context = ''): bool
    {
        switch ($context) {

            case 'ajax':
            case 'settings':
                return !current_user_can('manage_options');

            default:
                return false;
        }
    }

    public function actions(): void
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        RequestActions::request($this->action_hook, function ($action) {

            $response = false;
            $action_parts = explode(':', (string)$action, 2);
            $action = $action_parts[0];
            $backup_id = sanitize_key((string)($action_parts[1] ?? ''));

            switch ($action) {

                case 'reset_options':
                    $response = wps('wpopt')->moduleHandler->reset_modules(null, false);
                    $response = wps('wpopt')->settings->reset() && $response;
                    $response &= wps('wpopt')->moduleHandler->upgrade();
                    $response = $this->apply_configuration_lifecycle(wps('wpopt')->settings->get('', array())) && $response;
                    $this->redirect_after_action($response);
                    break;

                case 'restore_options':
                    $response = wps('wpopt')->moduleHandler->upgrade();
                    if ($response) {
                        $response = $this->apply_configuration_lifecycle(wps('wpopt')->settings->get('', array())) && $response;
                    }
                    break;

                case 'export_options':

                    require_once WPS_ADDON_PATH . 'Exporter.class.php';

                    $exporter = new Exporter();

                    $exporter->set_raw(wps('wpopt')->settings->export());
                    $exporter->format('text');
                    $exporter->download('wpopt-export.conf');

                    unset($exporter);

                    break;

                case 'import_options':
                    $response = false;
                    $uploaded_file = $_FILES['conf_file'] ?? null;

                    if (is_array($uploaded_file) && (int)($uploaded_file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                        $tmp_name = (string)($uploaded_file['tmp_name'] ?? '');

                        if ($tmp_name !== '' && is_uploaded_file($tmp_name)) {
                            $response = wps('wpopt')->moduleHandler->reset_modules(null, false);
                            $response = wps('wpopt')->settings->import((string)file_get_contents($tmp_name)) && $response;
                        }
                    }

                    $response &= wps('wpopt')->moduleHandler->upgrade();
                    if ($response) {
                        $response = $this->apply_configuration_lifecycle(wps('wpopt')->settings->get('', array())) && $response;
                    }
                    break;

                case 'restore_configuration_backup':
                    $backup_settings = $this->get_backup_settings($backup_id);

                    if (is_array($backup_settings)) {
                        $response = wps('wpopt')->moduleHandler->reset_modules(null, false);
                        $response = wps('wpopt')->settings->reset($backup_settings) && $response;
                        $response = $response && $this->apply_configuration_lifecycle($backup_settings);
                        $this->redirect_after_action($response);
                    }

                    break;

                case 'delete_configuration_backup':
                    $response = $this->delete_configuration_backup($backup_id);
                    break;
            }

            if ($response) {
                $this->add_notices('success', __('Action was correctly executed', $this->context));
            }
            else {
                $this->add_notices('warning', __('Action execution failed', $this->context));
            }
        });
    }

    private function redirect_after_action(bool $response): void
    {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'wps-status' => $response ? 'success' : 'warning',
                    'wps-notice' => $response ? __('Action was correctly executed', $this->context) : __('Action execution failed', $this->context),
                ),
                wps_admin_route_url('wpopt', 'setting-settings')
            )
        );
        exit;
    }

    public function ajax_handler($args = array()): void
    {
        if (($args['action'] ?? '') === 'reset_module') {
            $this->handle_module_reset_ajax($args, array('tracking'), array(
                'invalid' => __('Invalid module reset request.', 'wpopt'),
                'failed'  => __('Factory reset failed for %s.', 'wpopt'),
                'success' => __('%s has been reset to factory settings.', 'wpopt'),
            ));
            return;
        }

        if (($args['action'] ?? '') !== 'autosave_settings') {
            parent::ajax_handler($args);
            return;
        }

        $this->handle_settings_autosave_ajax((string)($args['form_data'] ?? ''), array(
            'invalid_payload' => __('Cannot detect which module must be saved.', 'wpopt'),
            'invalid_module'  => __('Invalid module settings payload.', 'wpopt'),
            'save_failed'     => __('Autosave failed while updating settings.', 'wpopt'),
            'saved'           => __('Settings autosaved.', 'wpopt'),
        ), array(
            'settings' => __('Settings', 'wpopt'),
            'tools'    => __('Tools', 'wpopt'),
        ), $this->get_nav_icons());
    }

    private function get_nav_icons(): array
    {
        return array(
            'activitylog'         => 'list',
            'cache'               => 'server',
            'database'            => 'database',
            'media'               => 'image',
            'minify'              => 'tools',
            'pagespeed'           => 'pagespeed',
            'performance_monitor' => 'chart',
            'widget'              => 'box',
            'wp_customizer'       => 'sliders',
            'wp_info'             => 'info',
            'wp_mail'             => 'mail',
            'wp_optimizer'        => 'gauge',
            'wp_security'         => 'shield',
            'wp_updates'          => 'repeat',
        );
    }

    public function backup_before_wpopt_option_update($value, $old_value, $option)
    {
        if (defined('WPOPT_RECOVERY_RUNNING') && WPOPT_RECOVERY_RUNNING) {
            return $value;
        }

        if ($option !== 'wpopt' || maybe_serialize($value) === maybe_serialize($old_value)) {
            return $value;
        }

        if (!$this->create_configuration_backup(is_array($old_value) ? $old_value : array())) {
            return $old_value;
        }

        return $value;
    }

    private function apply_configuration_lifecycle(array $settings): bool
    {
        $response = wps('wpopt')->moduleHandler->activate_modules_for_settings($settings);

        do_action('wpopt_configuration_restored', $settings, $response);

        return $response;
    }

    private function create_configuration_backup(?array $settings = null): bool
    {
        $settings = is_array($settings) ? $settings : wps('wpopt')->settings->get('', []);
        $backups = $this->get_configuration_backups();
        $now = time();

        if ($this->has_recent_configuration_backup($backups, $now)) {
            return true;
        }

        array_unshift($backups, array(
            'id'         => gmdate('YmdHis', $now) . '-' . strtolower(wp_generate_password(8, false, false)),
            'created_at' => $now,
            'settings'   => base64_encode(serialize($settings)),
        ));

        $backups = $this->limit_configuration_backups($backups);

        return $this->update_configuration_backups($backups);
    }

    private function has_recent_configuration_backup(array $backups, int $now): bool
    {
        return !empty($backups) && (int)$backups[0]['created_at'] >= ($now - self::BACKUP_MIN_INTERVAL_SECONDS);
    }

    private function get_configuration_backups(): array
    {
        $backups = wps('wpopt')->options->get(self::BACKUP_OPTION, self::BACKUP_ITEM, self::BACKUP_CONTEXT, array(), false);

        if (!is_array($backups)) {
            return array();
        }

        $backups = array_values(array_filter($backups, function ($backup) {
            return is_array($backup)
                && !empty($backup['id'])
                && !empty($backup['created_at'])
                && !empty($backup['settings']);
        }));

        usort($backups, function ($a, $b) {
            return (int)$b['created_at'] <=> (int)$a['created_at'];
        });

        $limited_backups = $this->limit_configuration_backups($backups);

        if (count($limited_backups) !== count($backups)) {
            $this->update_configuration_backups($limited_backups);
        }

        return $limited_backups;
    }

    private function limit_configuration_backups(array $backups): array
    {
        return array_slice(array_values($backups), 0, self::BACKUP_MAX_ENTRIES);
    }

    private function get_backup_settings(string $backup_id): ?array
    {
        if ($backup_id === '') {
            return null;
        }

        foreach ($this->get_configuration_backups() as $backup) {
            if ((string)$backup['id'] !== $backup_id) {
                continue;
            }

            $decoded_settings = base64_decode((string)$backup['settings'], true);

            if (!is_string($decoded_settings) || $decoded_settings === '') {
                return null;
            }

            $settings = @unserialize($decoded_settings, array('allowed_classes' => false));

            return is_array($settings) ? $settings : null;
        }

        return null;
    }

    private function delete_configuration_backup(string $backup_id): bool
    {
        if ($backup_id === '') {
            return false;
        }

        $deleted = false;
        $backups = array_values(array_filter($this->get_configuration_backups(), function ($backup) use ($backup_id, &$deleted) {
            if ((string)$backup['id'] === $backup_id) {
                $deleted = true;
                return false;
            }

            return true;
        }));

        return $deleted && $this->update_configuration_backups($backups);
    }

    private function update_configuration_backups(array $backups): bool
    {
        $backups = array_values($backups);

        if (empty($backups)) {
            wps('wpopt')->options->remove(self::BACKUP_OPTION, self::BACKUP_ITEM, self::BACKUP_CONTEXT);

            return true;
        }

        $existing = wps('wpopt')->options->get(self::BACKUP_OPTION, self::BACKUP_ITEM, self::BACKUP_CONTEXT, null, false);

        if (maybe_serialize($existing) === maybe_serialize($backups)) {
            return true;
        }

        return wps('wpopt')->options->update(self::BACKUP_OPTION, self::BACKUP_ITEM, $backups, self::BACKUP_CONTEXT);
    }

    protected function print_footer(): string
    {
        ob_start();
        ?>
        <form method="POST" autocapitalize="off" autocomplete="off" enctype="multipart/form-data">

            <?php RequestActions::nonce_field($this->action_hook); ?>

            <block class="wps-gridRow wps-settings-setup wps-settings-transfer wpopt-settings-transfer">
                <row class="wps-custom-action wps-settings-actions">
                    <button type="submit"
                            name="<?php echo esc_attr($this->action_hook); ?>"
                            value="reset_options"
                            class="wps wps-button wpopt-btn is-danger"
                            data-wps-confirm="<?php echo esc_attr__('Are you sure you want to reset plugin options? Current plugin options will be overwritten.', 'wpopt'); ?>">
                        <span class="dashicons dashicons-trash"></span>
                        <span><?php esc_html_e('Reset Plugin options', 'wpopt'); ?></span>
                    </button>
                    <button type="submit"
                            name="<?php echo esc_attr($this->action_hook); ?>"
                            value="restore_options"
                            class="wps wps-button wpopt-btn is-neutral">
                        <span class="dashicons dashicons-update-alt"></span>
                        <span><?php esc_html_e('Restore Plugin options', 'wpopt'); ?></span>
                    </button>
                    <button type="submit"
                            name="<?php echo esc_attr($this->action_hook); ?>"
                            value="export_options"
                            class="wps wps-button wpopt-btn is-info">
                        <span class="dashicons dashicons-upload"></span>
                        <span><?php esc_html_e('Export Plugin options', 'wpopt'); ?></span>
                    </button>
                </row>
                <row class="wps-custom-action wps-settings-import">
                    <label class="wps-import-dropzone" for="wpopt-conf-file">
                        <span class="wps-import-dropzone-icon">
                            <?php echo Graphic::icon('external'); ?>
                        </span>
                        <span class="wps-import-dropzone-copy">
                            <strong><?php esc_html_e('Drop configuration file here', 'wpopt'); ?></strong>
                            <small><?php esc_html_e('or choose the exported .conf file from your computer.', 'wpopt'); ?></small>
                        </span>
                        <input id="wpopt-conf-file" class="wps-import-file" type="file" name="conf_file" accept=".conf,text/plain">
                    </label>
                    <span class="wps-import-divider wpopt-import-divider"><span><?php esc_html_e('or', 'wpopt'); ?></span></span>
                    <div class="wps-import-action wpopt-import-action">
                        <button type="submit"
                                name="<?php echo esc_attr($this->action_hook); ?>"
                                value="import_options"
                                class="wps wps-button wpopt-btn is-info"
                                data-wps-confirm="<?php echo esc_attr__('Are you sure you want to import plugin options? Current plugin options may be overwritten.', 'wpopt'); ?>">
                            <span class="dashicons dashicons-upload"></span>
                            <span><?php esc_html_e('Import Plugin options', 'wpopt'); ?></span>
                        </button>
                        <p><?php esc_html_e('Import settings from a .conf file to apply saved configuration.', 'wpopt'); ?></p>
                    </div>
                </row>
            </block>

            <?php echo $this->render_configuration_backups(); ?>
        </form>
        <?php
        return ob_get_clean();
    }

    private function render_configuration_backups(): string
    {
        $backups = $this->get_configuration_backups();

        ob_start();
        ?>
        <block class="wps-gridRow wpopt-configuration-backups">
            <header class="wpopt-configuration-backups-header">
                <div>
                    <h2><?php esc_html_e('Configuration backups', 'wpopt'); ?></h2>
                    <p><?php esc_html_e('A backup is created before configuration changes. If the newest backup is less than 15 minutes old, no new backup is created. WP Optimizer keeps the newest 50 backups and removes older entries automatically.', 'wpopt'); ?></p>
                </div>
            </header>

            <?php if (empty($backups)) : ?>
                <div class="wpopt-configuration-backups-empty">
                    <?php esc_html_e('No configuration backups available yet.', 'wpopt'); ?>
                </div>
            <?php else : ?>
                <div class="wpopt-configuration-backups-table-wrap">
                    <?php echo $this->render_configuration_backups_table($backups); ?>
                </div>
            <?php endif; ?>
        </block>
        <?php

        return ob_get_clean();
    }

    private function render_configuration_backups_table(array $backups): string
    {
        $rows = array();

        foreach ($backups as $backup) {
            $created_at = (int)$backup['created_at'];

            ob_start();
            ?>
            <div class="wpopt-configuration-backups-actions">
                <button type="submit"
                        name="<?php echo esc_attr($this->action_hook); ?>"
                        value="<?php echo esc_attr('restore_configuration_backup:' . (string)$backup['id']); ?>"
                        class="wps wps-button wpopt-btn is-neutral"
                        data-wps-confirm="<?php echo esc_attr__('Restore this configuration backup? Current plugin options will be overwritten.', 'wpopt'); ?>">
                    <span class="dashicons dashicons-update-alt"></span>
                    <span><?php esc_html_e('Restore', 'wpopt'); ?></span>
                </button>
                <button type="submit"
                        name="<?php echo esc_attr($this->action_hook); ?>"
                        value="<?php echo esc_attr('delete_configuration_backup:' . (string)$backup['id']); ?>"
                        class="wps wps-button wpopt-btn is-danger"
                        data-wps-confirm="<?php echo esc_attr__('Delete this configuration backup?', 'wpopt'); ?>">
                    <span class="dashicons dashicons-trash"></span>
                    <span><?php esc_html_e('Delete', 'wpopt'); ?></span>
                </button>
            </div>
            <?php

            $rows[] = array(
                'date' => '<strong>' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $created_at)) . '</strong><code>' . esc_html((string)$backup['id']) . '</code>',
                'age' => esc_html(sprintf(__('%s ago', 'wpopt'), human_time_diff($created_at, time()))),
                'actions' => ob_get_clean(),
            );
        }

        return List_Table::generateHTML_table(array(
            'class' => 'wps wpopt-configuration-backups-table',
            'columns' => array(
                'date' => __('Date', 'wpopt'),
                'age' => __('Age', 'wpopt'),
                'actions' => __('Actions', 'wpopt'),
            ),
            'rows' => $rows,
            'empty' => __('No configuration backups available yet.', 'wpopt'),
        ));
    }
}

return __NAMESPACE__;

