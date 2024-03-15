<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use WPS\modules\Module;

/**
 * Module for updates handling
 */
class Mod_WP_Updates extends Module
{
    public array $scopes = array('settings', 'autoload');

    protected string $context = 'wpopt';

    public function restricted_access($context = ''): bool
    {
        if ($context === 'settings')
            return !current_user_can('manage_options');

        return false;
    }

    protected function init(): void
    {
        $this->disable_updates();
    }

    private function disable_updates()
    {
        if ($this->option('core-updates')) {
            remove_action('init', 'wp_version_check');

            add_filter('pre_option_update_core', '__return_null');

            add_filter('wp_auto_update_core', '__return_false');
            add_filter('auto_update_core', '__return_false');
            add_filter('allow_minor_auto_core_updates', '__return_false');
            add_filter('allow_major_auto_core_updates', '__return_false');
            add_filter('allow_dev_auto_core_updates', '__return_false');

            remove_action('wp_version_check', 'wp_version_check');

            wp_clear_scheduled_hook('wp_version_check');
        }

        if ($this->option('page-updates')) {
            // Remove updates page.

            add_action('admin_menu', function () {
                remove_submenu_page('index.php', 'update-core.php');
            });
        }

        if ($this->option('plugin-updates')) {
            // Disable plugin API checks.
            remove_all_filters('plugins_api');

            // Disable plugin checks.
            remove_action('load-update-core.php', 'wp_update_plugins');
            remove_action('load-plugins.php', 'wp_update_plugins');
            remove_action('load-update.php', 'wp_update_plugins');
            remove_action('admin_init', '_maybe_update_plugins');
            remove_action('wp_update_plugins', 'wp_update_plugins');
            wp_clear_scheduled_hook('wp_update_plugins');

            add_filter('auto_update_plugin', '__return_false');
        }

        if ($this->option('theme-updates')) {
            // Disable theme checks.
            remove_action('load-update-core.php', 'wp_update_themes');
            remove_action('load-themes.php', 'wp_update_themes');
            remove_action('load-update.php', 'wp_update_themes');
            remove_action('wp_update_themes', 'wp_update_themes');
            remove_action('admin_init', '_maybe_update_themes');
            wp_clear_scheduled_hook('wp_update_themes');

            add_filter('auto_update_theme', '__return_false');
        }

        if ($this->option('message-updates')) {
            // Hide nag messages.
            remove_action('admin_notices', 'update_nag', 3);
            remove_action('network_admin_notices', 'update_nag', 3);
            remove_action('admin_notices', 'maintenance_nag');
            remove_action('network_admin_notices', 'maintenance_nag');
        }

        if ($this->option('automatic-updates')) {

            add_filter('automatic_updater_disabled', '__return_true');

            remove_action('admin_init', 'wp_maybe_auto_update');
            remove_action('admin_init', 'wp_auto_update_core');
            remove_action('wp_version_check', 'wp_version_check');
            remove_action('admin_init', '_maybe_update_core');
            remove_action('wp_maybe_auto_update', 'wp_maybe_auto_update');

            add_filter('auto_update_translation', '__return_false');

            add_filter('allow_minor_auto_core_updates', '__return_false');
            add_filter('allow_major_auto_core_updates', '__return_false');
            add_filter('allow_dev_auto_core_updates', '__return_false');

            add_filter('auto_update_core', '__return_false');
            add_filter('wp_auto_update_core', '__return_false');
            add_filter('auto_update_plugin', '__return_false');
            add_filter('auto_update_theme', '__return_false');
        }

        if ($this->option('mail-updates')) {
            add_filter('auto_core_update_send_email', '__return_false');
            add_filter('auto_core_update_send_email', '__return_false');
            add_filter('automatic_updates_send_debug_email ', '__return_false');
            add_filter('send_core_update_notification_email', '__return_false');
        }
    }

    protected function setting_fields($filter = ''): array
    {
        return $this->group_setting_fields(
            $this->setting_field(__('Disable Wordpress Core Updates', 'wpopt'), "core-updates", "checkbox"),
            $this->setting_field(__('Disable Plugins Updates', 'wpopt'), "plugin-updates", "checkbox"),
            $this->setting_field(__('Disable Themes Updates', 'wpopt'), "theme-update", "checkbox"),
            $this->setting_field(__('Disable Updates Messages', 'wpopt'), "message-updates", "checkbox"),
            $this->setting_field(__('Remove Updates Page', 'wpopt'), "page-updates", "checkbox"),
            $this->setting_field(__('Disable Automatic Updates', 'wpopt'), "automatic-updates", "checkbox"),
            $this->setting_field(__('Disable WordPress update notices mail', 'wpopt'), "mail-updates", "checkbox")
        );
    }
}

return __NAMESPACE__;