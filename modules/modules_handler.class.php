<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use SHZN\modules\Module;

class Mod_Modules_Handler extends Module
{
    public $scopes = array('core-settings');

    private $modules_slug2name;

    public function __construct()
    {
        $modules = shzn('wpopt')->moduleHandler->get_modules(array('excepts' => array('modules_handler', 'settings', 'cron')), false);

        $slugs = array_column($modules, 'slug');

        $this->modules_slug2name = array_combine($slugs, array_column($modules, 'name'));

        parent::__construct('wpopt', array(
            'settings' => array_fill_keys($slugs, true)
        ));
    }

    protected function setting_fields($filter = '')
    {
        $settings = array();

        foreach ($this->option() as $key => $value) {
            if (isset($this->modules_slug2name[$key])) {
                $settings[] = $this->setting_field($this->modules_slug2name[$key], $key, 'checkbox', ['value' => $value]);
            }
        }

        return $settings;
    }

    public function restricted_access($context = '')
    {
        switch ($context) {

            case 'settings':
                return !current_user_can('manage_options');

            default:
                return false;
        }
    }
}

return __NAMESPACE__;