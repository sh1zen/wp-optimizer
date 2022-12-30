<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C)  2022
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use SHZN\modules\Module;

class Mod_Cron extends Module
{
    public $scopes = array('core-settings');

    public function __construct()
    {
        /**
         * we need to load all modules with cron scope
         */
        shzn('wpopt')->moduleHandler->setup_modules('cron');

        parent::__construct('wpopt');
    }

    public function validate_settings($input, $valid)
    {
        return shzn('wpopt')->cron->cron_setting_validator($input, $valid);
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

    protected function setting_fields($filter = '')
    {
        /**
         * Load here all module cron settings
         */
        return shzn('wpopt')->cron->cron_setting_fields();
    }
}

return __NAMESPACE__;