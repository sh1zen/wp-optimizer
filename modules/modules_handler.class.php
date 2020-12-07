<?php

class WOMod_Modules_Handler extends WO_Module
{
    public $scopes = array('core-settings');

    private $modules_slug2name;

    public function __construct()
    {
        $modules = WOModuleHandler::getInstance()->get_modules(array('excepts' => array('modules_handler', 'settings', 'cron')), false);

        $slugs = array_column($modules, 'slug');

        $this->modules_slug2name = array_combine($slugs, array_column($modules, 'name'));

        parent::__construct(array(
            'settings' => array_fill_keys($slugs, true)
        ));
    }

    protected function setting_fields()
    {
        $settings = array();

        foreach ($this->settings as $key => $value) {
            $settings[] = array('type' => 'checkbox', 'name' => $this->modules_slug2name[$key], 'id' => $key, 'value' => $value);
        }

        return $settings;
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