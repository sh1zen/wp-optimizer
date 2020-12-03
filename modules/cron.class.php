<?php

class WOMod_Cron extends WO_Module
{
    public $scopes = array('settings');

    public function __construct()
    {
        /**
         * we need to load all modules with cron scope
         */
        WOModuleHandler::getInstance()->setup_modules('cron');

        parent::__construct();
    }

    public function validate_settings($input, $valid)
    {
        /**
         * Filters all modules cron settings validation
         * @since 0.0.9
         */
        return apply_filters('wpopt_validate_cron_settings', $valid, $input);
    }

    protected function setting_fields()
    {
        $cron_settings = array();

        /**
         * Filters all modules cron settings
         * @since 0.0.9
         */
        return apply_filters('wpopt_cron_settings_fields', $cron_settings);
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

}