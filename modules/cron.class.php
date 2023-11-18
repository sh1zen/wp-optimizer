<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use WPS\modules\Module;

class Mod_Cron extends Module
{
    public array $scopes = array('core-settings');

    protected string $context = 'wpopt';

    public function validate_settings($input, $filtering = false): array
    {
        return wps('wpopt')->cron->cron_setting_validator($input, $filtering);
    }

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
        /**
         * we need to load all modules with cron scope
         */
        wps('wpopt')->moduleHandler->setup_modules('cron');
    }

    protected function setting_fields($filter = ''): array
    {
        /**
         * Load here all module cron settings
         */
        return wps('wpopt')->cron->cron_setting_fields();
    }
}

return __NAMESPACE__;