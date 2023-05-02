<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use SHZN\modules\Module;
use SHZN\core\Disk;
use SHZN\core\UtilEnv;

class Mod_Settings extends Module
{
    public $scopes = array('core-settings', 'admin');

    public function __construct()
    {
        parent::__construct('wpopt');
    }

    public function restricted_access($context = '')
    {
        switch ($context) {

            case 'ajax':
            case 'settings':
                return !current_user_can('manage_options');

            default:
                return false;
        }
    }

    protected function custom_actions()
    {
        return array(
            array(
                'id'           => 'reset_options',
                'value'        => __('Reset Plugin options', 'wpopt'),
                'button_types' => 'button-danger',
                'after'        => '<hr>'
            ),
            array(
                'id'           => 'export_options',
                'value'        => __('Export Plugin options', 'wpopt'),
                'button_types' => 'button-primary',
                'after'        => '<hr>'
            ),
            array(
                'before'       => array(
                    'id'      => 'conf_data',
                    'type'    => 'textarea',
                    'context' => 'block'
                ),
                'id'           => 'import_options',
                'button_types' => 'button-primary',
                'value'        => __('Import Plugin options', 'wpopt'),
            )
        );
    }

    protected function process_custom_actions($action, $options)
    {
        switch ($action) {
            case 'reset_options':
                return shzn('wpopt')->settings->reset();

            case 'export_options':

                if (Disk::write(WPOPT_STORAGE . 'wpopt-export.conf', shzn('wpopt')->settings->export())) {
                    UtilEnv::download_file(WPOPT_STORAGE . 'wpopt-export.conf');
                    return true;
                }
                break;

            case 'import_options':
                return shzn('wpopt')->settings->import($options['conf_data']);
        }

        return false;
    }
}

return __NAMESPACE__;