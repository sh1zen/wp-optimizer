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
    public array $scopes = array('core-settings');

    private array $modules_slug2name = [];

    protected string $context = 'wpopt';

    public function restricted_access($context = ''): bool
    {
        switch ($context) {

            case 'settings':
                return !current_user_can('manage_options');

            default:
                return false;
        }
    }

    protected function init()
    {
        $modules = shzn('wpopt')->moduleHandler->get_modules(array('excepts' => array('modules_handler', 'settings', 'cron')), false);

        $this->modules_slug2name = array_combine(array_column($modules, 'slug'), array_column($modules, 'name'));
    }

    protected function setting_fields($filter = ''): array
    {
        $settings = array();

        foreach ($this->modules_slug2name as $slug => $name) {

            $settings[] = $this->setting_field($name, $slug, 'checkbox', ['value' => $this->option($slug, true)]);
        }

        return $settings;
    }
}

return __NAMESPACE__;