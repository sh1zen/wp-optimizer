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
use WPS\core\Rewriter;
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
                    Rewriter::reload();
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
                    $response = wps('wpopt')->settings->import($_REQUEST['conf_data']);
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

    public function ajax_handler($args = array()): void
    {
        if (($args['action'] ?? '') !== 'autosave_settings') {
            parent::ajax_handler($args);
            return;
        }

        parse_str((string)($args['form_data'] ?? ''), $form_data);

        $context = wps('wpopt')->settings->get_context();
        $payload = $form_data[$context] ?? [];

        if (empty($payload['change'])) {
            Ajax::response([
                'text' => __('Cannot detect which module must be saved.', 'wpopt'),
            ], 'error');
        }

        $module = wps('wpopt')->moduleHandler->get_module_instance($payload['change']);

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

        Ajax::response([
            'text'   => __('Settings autosaved.', 'wpopt'),
            'module' => $module->slug,
        ], 'success');
    }

    protected function print_footer(): string
    {
        ob_start();
        ?>
        <form method="POST" autocapitalize="off" autocomplete="off">

            <?php RequestActions::nonce_field($this->action_hook); ?>

            <block class="wps-gridRow wpopt-settings-setup">
                <row class="wps-custom-action wpopt-settings-actions">
                    <?php

                    echo RequestActions::get_action_button($this->action_hook, 'reset_options', __('Reset Plugin options', 'wpopt'), 'wps wps-button wpopt-btn is-danger');

                    echo RequestActions::get_action_button($this->action_hook, 'restore_options', __('Restore Plugin options', 'wpopt'), 'wps wps-button wpopt-btn is-neutral');

                    echo RequestActions::get_action_button($this->action_hook, 'export_options', __('Export Plugin options', 'wpopt'), 'wps wps-button wpopt-btn is-info');

                    ?>
                </row>
                <row class="wps-custom-action wpopt-settings-import">
                    <?php

                    Graphic::generate_field(array(
                        'id'      => 'conf_data',
                        'type'    => 'textarea',
                        'classes' => 'wpopt-import-field',
                        'context' => 'block'
                    ));

                    echo RequestActions::get_action_button($this->action_hook, 'import_options', __('Import Plugin options', 'wpopt'), 'wps wps-button wpopt-btn is-info');

                    ?>
                </row>
            </block>
        </form>
        <?php
        return ob_get_clean();
    }
}

return __NAMESPACE__;

