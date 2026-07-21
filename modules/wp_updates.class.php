<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use WPS\core\UtilEnv;
use WPS\modules\Module;

/**
 * Module for updates handling
 */
class Mod_WP_Updates extends Module
{
    private const OPTIMIZER_UPDATE_CHECK_HOOK = 'wpopt_optimizer_update_check';

    public array $scopes = array('settings', 'autoload');

    protected string $context = 'wpopt';

    public function cleanup(array $settings = array(), array $all_settings = array()): bool
    {
        $this->configure_optimizer_update_check(false);
        $this->restore_scheduled_hook('wp_version_check');
        $this->restore_scheduled_hook('wp_update_plugins');
        $this->restore_scheduled_hook('wp_update_themes');

        return true;
    }

    public function activate(array $settings = array(), array $all_settings = array()): bool
    {
        $this->disable_updates();

        return true;
    }

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

            $this->clear_scheduled_hook('wp_version_check');
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
            $this->clear_scheduled_hook('wp_update_plugins');

            add_filter('auto_update_plugin', '__return_false');

            $this->configure_optimizer_update_check(true);
        }
        else {
            $this->configure_optimizer_update_check(false);
        }

        if ($this->option('theme-updates')) {
            // Disable theme checks.
            remove_action('load-update-core.php', 'wp_update_themes');
            remove_action('load-themes.php', 'wp_update_themes');
            remove_action('load-update.php', 'wp_update_themes');
            remove_action('wp_update_themes', 'wp_update_themes');
            remove_action('admin_init', '_maybe_update_themes');
            $this->clear_scheduled_hook('wp_update_themes');

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

    private function clear_scheduled_hook(string $hook): void
    {
        if (wp_next_scheduled($hook)) {
            wp_clear_scheduled_hook($hook);
        }
    }

    private function restore_scheduled_hook(string $hook): void
    {
        if (!wp_next_scheduled($hook)) {
            wp_schedule_event(time(), 'twicedaily', $hook);
        }
    }

    private function configure_optimizer_update_check(bool $enabled): void
    {
        if (!$enabled) {
            remove_action(self::OPTIMIZER_UPDATE_CHECK_HOOK, array(self::class, 'check_optimizer_update'));
            $this->clear_scheduled_hook(self::OPTIMIZER_UPDATE_CHECK_HOOK);
            return;
        }

        add_action(self::OPTIMIZER_UPDATE_CHECK_HOOK, array(self::class, 'check_optimizer_update'));

        if (!wp_next_scheduled(self::OPTIMIZER_UPDATE_CHECK_HOOK)) {
            wp_schedule_event(time(), 'twicedaily', self::OPTIMIZER_UPDATE_CHECK_HOOK);
        }
    }

    /**
     * Check WP Optimizer only and store its result for manual updates.
     */
    public static function check_optimizer_update(): bool
    {
        $plugin_file = UtilEnv::plugin_basename(WPOPT_FILE);
        $plugin_data = get_file_data(
            WPOPT_FILE,
            array(
                'Name'        => 'Plugin Name',
                'PluginURI'   => 'Plugin URI',
                'Version'     => 'Version',
                'TextDomain'  => 'Text Domain',
                'RequiresWP'  => 'Requires at least',
                'RequiresPHP' => 'Requires PHP',
                'UpdateURI'   => 'Update URI',
            ),
            'plugin'
        );

        if (empty($plugin_data['Version'])) {
            return false;
        }

        $request = wp_remote_post(
            'https://api.wordpress.org/plugins/update-check/1.1/',
            array(
                'timeout'    => 15,
                'body'       => array(
                    'plugins'      => wp_json_encode(array(
                        'plugins' => array($plugin_file => $plugin_data),
                        'active'  => array($plugin_file),
                    )),
                    'translations' => wp_json_encode(array()),
                    'locale'       => wp_json_encode(array(get_locale())),
                    'all'          => wp_json_encode(true),
                ),
                'user-agent' => 'WordPress/' . wp_get_wp_version() . '; ' . home_url('/'),
            )
        );

        if (is_wp_error($request) || wp_remote_retrieve_response_code($request) !== 200) {
            return false;
        }

        $response = json_decode(wp_remote_retrieve_body($request), true);

        if (!is_array($response)) {
            return false;
        }

        $current = get_site_transient('update_plugins');
        $checked = is_object($current) && isset($current->checked) && is_array($current->checked)
            ? $current->checked
            : array();
        $checked[$plugin_file] = (string)$plugin_data['Version'];

        $updates = new \stdClass();
        $updates->last_checked = time();
        $updates->checked = $checked;
        $updates->response = self::normalize_optimizer_update($response['plugins'] ?? array(), $plugin_file);
        $updates->no_update = self::normalize_optimizer_update($response['no_update'] ?? array(), $plugin_file);
        $updates->translations = isset($response['translations']) && is_array($response['translations'])
            ? array_values($response['translations'])
            : array();

        set_site_transient('update_plugins', $updates);

        return true;
    }

    /**
     * @param mixed $entries
     */
    private static function normalize_optimizer_update($entries, string $plugin_file): array
    {
        if (!is_array($entries) || !isset($entries[$plugin_file])) {
            return array();
        }

        $update = (object)$entries[$plugin_file];
        $update->plugin = $plugin_file;
        unset($update->translations, $update->compatibility);

        return array($plugin_file => $update);
    }

    protected function setting_fields($filter = ''): array
    {
        return $this->group_setting_fields(
            $this->setting_field(__('Disable Wordpress Core Updates', 'wpopt'), "core-updates", "checkbox", ['risk' => 'danger']),
            $this->setting_field(__('Disable Plugins Updates', 'wpopt'), "plugin-updates", "checkbox", ['risk' => 'danger']),
            $this->setting_field(__('Disable Themes Updates', 'wpopt'), "theme-update", "checkbox", ['risk' => 'danger']),
            $this->setting_field(__('Disable Updates Messages', 'wpopt'), "message-updates", "checkbox"),
            $this->setting_field(__('Remove Updates Page', 'wpopt'), "page-updates", "checkbox"),
            $this->setting_field(__('Disable Automatic Updates', 'wpopt'), "automatic-updates", "checkbox"),
            $this->setting_field(__('Disable WordPress update notices mail', 'wpopt'), "mail-updates", "checkbox")
        );
    }

    protected function infos(): array
    {
        return [
            'core-updates'      => __("Disable WordPress core update checks and background update routines.", 'wpopt'),
            'plugin-updates'    => __("Disable plugin updates while checking WP Optimizer twice daily for manual updates.", 'wpopt'),
            'theme-update'      => __("Disable theme update checks and automatic theme updates.", 'wpopt'),
            'message-updates'   => __("Hide update notifications in the WordPress admin area.", 'wpopt'),
            'page-updates'      => __("Remove the Updates page from the WordPress admin menu.", 'wpopt'),
            'automatic-updates' => __("Disable the automatic updater for core, plugins, themes and translations.", 'wpopt'),
            'mail-updates'      => __("Disable automatic update notification emails sent by WordPress.", 'wpopt'),
        ];
    }
}

return __NAMESPACE__;
