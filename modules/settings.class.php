<?php

class WOMod_Settings extends WO_Module
{
    public $scopes = array('settings', 'admin');

    public function __construct()
    {
        parent::__construct();
    }

    protected function restricted_access($context = '')
    {
        switch ($context) {

            case 'settings':
                return !current_user_can('administrator');

            default:
                return false;
        }
    }

    protected function custom_actions()
    {
        return array(
            array(
                'name'         => 'reset_options',
                'value'        => __('Reset Plugin options', 'wpopt'),
                'button_types' => 'button-danger'
            ),
            array(
                'name'         => 'export_options',
                'value'        => __('Export Plugin options', 'wpopt'),
                'button_types' => 'button-primary'
            )
        );
    }


    protected function process_custom_actions($action, $options)
    {
        switch ($action) {
            case 'reset_options':
                return WOSettings::getInstance()->reset_options();

            case 'export_options':
                if (file_put_contents(WPOPT_STORAGE . 'export.conf', WOSettings::getInstance()->export())) {
                    wpopt_download_file(WPOPT_STORAGE . 'export.conf');
                    return true;
                }
                break;
        }

        return false;
    }

}