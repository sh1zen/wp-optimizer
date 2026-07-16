<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use WPS\modules\Module;

class Mod_Modules_Handler extends Module
{
    public array $scopes = array('core-settings');

    private array $modules_slug2name = [];

    private array $inactive_by_default = [
        'activitylog',
        'performance_monitor',
        'wp_mail',
        'wp_updates',
        'wp_info',
    ];

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

    protected function init(): void
    {
        $modules = wps('wpopt')->moduleHandler->get_modules(array('excepts' => array('cloudflare', 'modules_handler', 'settings', 'tracking')), false);

        $this->modules_slug2name = array_combine(array_column($modules, 'slug'), array_column($modules, 'name'));
    }

    protected function setting_fields($filter = ''): array
    {
        $settings = array();

        foreach ($this->modules_slug2name as $slug => $name) {

            $settings[] = $this->setting_field($name, $slug, 'checkbox', [
                'value'  => $this->option($slug, $this->is_active_by_default($slug)),
                'before' => $this->module_reset_button($slug, $name, sprintf(__('Reset %s to factory settings', 'wpopt'), $name)),
            ]);
        }

        return $settings;
    }

    private function is_active_by_default(string $slug): bool
    {
        return !in_array($slug, $this->inactive_by_default, true);
    }
}

return __NAMESPACE__;
