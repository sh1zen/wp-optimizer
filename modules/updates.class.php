<?php

/**
 * Host info
 *
 * @since 1.1.0
 * @access public
 */
class WOMod_Updates extends WO_Module
{
    public $scopes = array('settings', 'autoload');

    public function __construct()
    {
        $default = array(
            'core-updates'      => true,
            'plugin-updates'    => true,
            'theme-updates'     => true,
            'message-updates'   => true,
            'page-updates'      => true,
            'automatic-updates' => true,
            'mail-updates'      => true
        );

        parent::__construct(
            array(
                'disabled' => false, //!current_user_can('administrator'),
                'settings' => $default,
            )
        );

        $this->disable_updates();
    }

    public function disable_updates()
    {
        if (!WOSettings::check($this->settings, 'core-updates')) {
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

        if (!WOSettings::check($this->settings, 'page-updates')) {
            // Remove updates page.

            add_action('admin_menu', function () {
                remove_submenu_page('index.php', 'update-core.php');
            });
        }

        if (!WOSettings::check($this->settings, 'plugin-updates')) {
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

        if (!WOSettings::check($this->settings, 'theme-updates')) {
            // Disable theme checks.
            remove_action('load-update-core.php', 'wp_update_themes');
            remove_action('load-themes.php', 'wp_update_themes');
            remove_action('load-update.php', 'wp_update_themes');
            remove_action('wp_update_themes', 'wp_update_themes');
            remove_action('admin_init', '_maybe_update_themes');
            wp_clear_scheduled_hook('wp_update_themes');

            add_filter('auto_update_theme', '__return_false');
        }

        if (!WOSettings::check($this->settings, 'message-updates')) {
            // Hide nag messages.
            remove_action('admin_notices', 'update_nag', 3);
            remove_action('network_admin_notices', 'update_nag', 3);
            remove_action('admin_notices', 'maintenance_nag');
            remove_action('network_admin_notices', 'maintenance_nag');
        }

        if (!WOSettings::check($this->settings, 'automatic-updates')) {

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

            wp_clear_scheduled_hook('wp_maybe_auto_update');
            wp_clear_scheduled_hook('wp_version_check');


            add_filter('automatic_updates_is_vcs_checkout', '__return_true');
        }

        if (!WOSettings::check($this->settings, 'mail-updates')) {

            add_filter('auto_core_update_send_email', '__return_false');
            add_filter('auto_core_update_send_email', '__return_false');
            add_filter('automatic_updates_send_debug_email ', '__return_false');
            add_filter('send_core_update_notification_email', '__return_false');

        }
    }

    public function get_setting_content($context)
    {
        $response = false;

        switch ($context) {
            case 'header':
                $response = 'Active updates:';
        }

        return $response;
    }

    public function setting_fields()
    {
        return array(
            array('type' => 'checkbox', 'name' => 'Wordpress Core Updates', 'id' => 'core-updates', 'value' => WOSettings::check($this->settings, 'core-updates')),
            array('type' => 'checkbox', 'name' => 'Plugins Updates', 'id' => 'plugin-updates', 'value' => WOSettings::check($this->settings, 'plugin-updates')),
            array('type' => 'checkbox', 'name' => 'Themes Updates', 'id' => 'theme-updates', 'value' => WOSettings::check($this->settings, 'theme-updates')),
            array('type' => 'checkbox', 'name' => 'Updates Messages', 'id' => 'message-updates', 'value' => WOSettings::check($this->settings, 'message-updates')),
            array('type' => 'checkbox', 'name' => 'Updates Page', 'id' => 'page-updates', 'value' => WOSettings::check($this->settings, 'page-updates')),
            array('type' => 'checkbox', 'name' => 'Automatic Updates', 'id' => 'automatic-updates', 'value' => WOSettings::check($this->settings, 'automatic-updates')),
            array('type' => 'checkbox', 'name' => 'Allow WordPress send updates notices mail', 'id' => 'mail-updates', 'value' => WOSettings::check($this->settings, 'mail-updates')),
        );
    }

}