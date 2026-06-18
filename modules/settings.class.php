<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use WPS\core\Ajax;
use WPS\core\RequestActions;
use WPS\core\addon\Exporter;
use WPS\core\Graphic;
use WPS\modules\Module;

class Mod_Settings extends Module
{
    public static ?string $name = 'Settings';

    public array $scopes = array('core-settings', 'admin', 'ajax');

    protected string $context = 'wpopt';

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

            switch ($action) {

                case 'reset_options':
                    $response = wps('wpopt')->settings->reset();
                    $response &= wps('wpopt')->moduleHandler->upgrade();
                    $this->redirect_after_action($response);
                    break;

                case 'restore_options':
                    $response = wps('wpopt')->moduleHandler->upgrade();
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
                            $response = wps('wpopt')->settings->import((string)file_get_contents($tmp_name));
                        }
                    }

                    $response &= wps('wpopt')->moduleHandler->upgrade();
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
                admin_url('admin.php?page=wpopt-settings')
            ) . '#settings-settings'
        );
        exit;
    }

    public function ajax_handler($args = array()): void
    {
        if (($args['action'] ?? '') !== 'autosave_settings') {
            parent::ajax_handler($args);
            return;
        }

        parse_str((string)($args['form_data'] ?? ''), $form_data);

        $context = wps('wpopt')->settings->get_context();
        $payload = $form_data[$context] ?? [];
        $module_slug = is_array($payload) ? sanitize_key($payload['change'] ?? '') : '';

        if (!$module_slug && !empty($form_data['option_panel'])) {
            $module_slug = sanitize_key(preg_replace('#^settings-#', '', (string)$form_data['option_panel']));
        }

        if (!$module_slug && is_array($payload) && $this->looks_like_modules_handler_payload($payload)) {
            $module_slug = 'modules_handler';
        }

        if (!$module_slug || !is_array($payload)) {
            Ajax::response([
                'text' => __('Cannot detect which module must be saved.', 'wpopt'),
            ], 'error');
        }

        $module = wps('wpopt')->moduleHandler->get_module_instance($module_slug);

        if (is_null($module)) {
            Ajax::response([
                'text' => __('Invalid module settings payload.', 'wpopt'),
            ], 'error');
        }

        $valid = $module->validate_settings($payload);

        $settings = wps('wpopt')->settings->get('', []);
        $settings[$module->slug] = $valid;

        $saved = wps('wpopt')->settings->reset($settings);

        if (!$saved) {
            Ajax::response([
                'text' => __('Autosave failed while updating settings.', 'wpopt'),
            ], 'error');
        }

        $response = [
            'text'   => __('Settings autosaved.', 'wpopt'),
            'module' => $module->slug,
        ];

        if ($module->slug === 'modules_handler') {
            $response['nav_update'] = $this->build_nav_update($valid);
        }

        Ajax::response($response, 'success');
    }

    private function looks_like_modules_handler_payload(array $payload): bool
    {
        foreach (wps('wpopt')->moduleHandler->get_modules('all', false) as $module) {
            if (array_key_exists($module['slug'], $payload)) {
                return true;
            }
        }

        return false;
    }

    private function build_nav_update(array $module_settings): array
    {
        return array(
            'sections' => array(
                array(
                    'kind'  => 'settings',
                    'label' => __('Settings', 'wpopt'),
                    'items' => $this->build_nav_items('settings', 'module-setting-', $module_settings),
                ),
                array(
                    'kind'  => 'tools',
                    'label' => __('Tools', 'wpopt'),
                    'items' => $this->build_nav_items('admin-page', 'module-', $module_settings),
                ),
            ),
        );
    }

    private function build_nav_items(string $scope, string $route_prefix, array $module_settings): array
    {
        $items = array();

        foreach (wps('wpopt')->moduleHandler->get_modules(array('scopes' => $scope), false) as $module) {
            $slug = sanitize_key((string)($module['slug'] ?? ''));

            if (!$slug || !$this->module_is_enabled_for_nav($slug, $module_settings)) {
                continue;
            }

            $items[] = array(
                'id'    => $route_prefix . $slug,
                'label' => (string)($module['name'] ?? $slug),
                'icon'  => $this->get_nav_icon($slug),
                'url'   => wps_admin_route_url('wpopt', $route_prefix . $slug),
            );
        }

        return $items;
    }

    private function module_is_enabled_for_nav(string $slug, array $module_settings): bool
    {
        if (!array_key_exists($slug, $module_settings)) {
            return true;
        }

        return (bool)$module_settings[$slug];
    }

    private function get_nav_icon(string $slug): string
    {
        $icons = array(
            'activitylog'         => 'list',
            'cache'               => 'server',
            'database'            => 'database',
            'media'               => 'image',
            'minify'              => 'tools',
            'performance_monitor' => 'chart',
            'widget'              => 'box',
            'wp_customizer'       => 'sliders',
            'wp_info'             => 'info',
            'wp_mail'             => 'mail',
            'wp_optimizer'        => 'gauge',
            'wp_security'         => 'shield',
            'wp_updates'          => 'repeat',
        );

        return $icons[$slug] ?? 'tools';
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
                            onclick="return confirm('<?php echo esc_js(__('Are you sure you want to reset plugin options? Current plugin options will be overwritten.', 'wpopt')); ?>')">
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
                                onclick="return confirm('<?php echo esc_js(__('Are you sure you want to import plugin options? Current plugin options may be overwritten.', 'wpopt')); ?>')">
                            <span class="dashicons dashicons-upload"></span>
                            <span><?php esc_html_e('Import Plugin options', 'wpopt'); ?></span>
                        </button>
                        <p><?php esc_html_e('Import settings from a .conf file to apply saved configuration.', 'wpopt'); ?></p>
                    </div>
                </row>
            </block>
        </form>
        <?php
        return ob_get_clean();
    }
}

return __NAMESPACE__;

