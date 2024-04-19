<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use WPS\modules\Module;

class Mod_Tracking extends Module
{
    public array $scopes = array('core-settings');

    protected string $context = 'wpopt';

    protected function setting_fields($filter = ''): array
    {
        return $this->group_setting_fields(

            $this->group_setting_fields(
                $this->setting_field(__('General', 'wpopt'), false, "separator"),
                $this->setting_field(__('Allow tracking errors', 'wpopt'), "errors", "checkbox"),
                $this->setting_field(__('Allow tracking usage', 'wpopt'), "usage", "checkbox"),
            ),
        );
    }

    protected function infos(): array
    {
        return [
            'errors' => __('If enabled, this feature allow sending report about THIS plugin errors, we will never collect any personal data.', 'wpopt'),
            'usage'  => __('If enabled, this feature allow sending report about THIS plugin features used, just to know witch features are useful.', 'wpopt'),
        ];
    }
}

return __NAMESPACE__;