<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C)  2022
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use SHZN\core\Settings;
use SHZN\modules\Module;

use WPOptimizer\modules\supporters\WP_Enhancer;

/**
 * Module for updates handling
 */
class Mod_WP_Optimizer extends Module
{
    public $scopes = array('settings', 'autoload');

    private array $server_conf_hooks;

    public function __construct()
    {
        $this->server_conf_hooks = array(
            'srv_enhancements'  => array(
                'active' => false,
            ),
            'srv_compression'   => array(
                'active' => false,
            ),
            'srv_browser_cache' => array(
                'active' => false,
            ),
        );

        $defaults = array(
            'minify_css'  => false,
            'minify_js'   => false,
            'minify_html' => false,
        );

        parent::__construct('wpopt',
            array(
                'settings' => array_merge(
                    $defaults,
                    $this->server_conf_hooks
                ),
            )
        );

        if (is_admin()) {
            if (!is_writable(ABSPATH . '.htaccess')) {
                $this->add_notices('error', sprintf(__("<b><i>'%s'</i> is not writable.</b><br>Modify (<b>run chmod 774</b>) it's group permission to allow WP-Optimizer to make changes automatically.", 'wpopt'), ABSPATH . '.htaccess'));
            }
        }

        $this->optimize();
    }

    private function optimize()
    {
        if ($this->option('cron.enhance')) {

            if (!defined('WP_CRON_LOCK_TIMEOUT')) {
                define('WP_CRON_LOCK_TIMEOUT', MINUTE_IN_SECONDS);
            }

            remove_action('init', 'wp_cron');

            if (time() > $this->option('cron.timenext', 300) + shzn('wpopt')->options->get('last_cron_event', 'cron', 'wp_optimizer', 0)) {

                spawn_cron();

                shzn('wpopt')->options->update('last_cron_event', 'cron', time(), 'wp_optimizer');
            }
        }

        if ($this->option('db.enhance')) {

            add_action('init', function () {
                global $wpdb;

                // Enable caching of database queries
                $wpdb->query('SET SESSION query_cache_type = ON');

                // Set cache limit to a higher value 10MB
                $wpdb->query('SET SESSION query_cache_size = 10485760');
            });
        }
    }

    public function validate_settings($input, $valid)
    {
        require_once WPOPT_SUPPORTERS . 'optisec/WP_Enhancer.class.php';

        $new_valid = parent::validate_settings($input, $valid);

        foreach ($this->server_conf_hooks as $server_hooks => $value) {

            if ($this->deactivating("{$server_hooks}.active", $input)) {
                WP_Enhancer::server_conf($server_hooks, 'remove');
            }
            elseif (Settings::get_option($new_valid, "{$server_hooks}.active")) {
                // do also if not activating to ensure children settings changes are performed
                WP_Enhancer::server_conf($server_hooks, 'add', $new_valid);
            }
        }

        return $new_valid;
    }

    public function restricted_access($context = '')
    {
        if ($context === 'settings')
            return !current_user_can('manage_options');

        return false;
    }

    protected function setting_form_templates($context)
    {
        if ($context === 'header') {

            if (true or !is_writable(ABSPATH . '.htaccess')) {

                require_once WPOPT_SUPPORTERS . 'optisec/WP_Enhancer.class.php';

                $virtual_page = WP_Enhancer::server_conf('', 'get');

                $htaccess_init_len = strlen($virtual_page);

                foreach ($this->server_conf_hooks as $server_hooks => $value) {

                    if ($this->option($server_hooks)) {

                        WP_Enhancer::server_conf($server_hooks, 'add', $this->option(), $virtual_page);
                    }
                }

                $htaccess_end_len = strlen($virtual_page);

                $virtual_page = htmlentities($virtual_page);

                if ($htaccess_init_len !== $htaccess_end_len)
                    return "<p><b>" . __("To manually modifies your server for enhanced mode copy and paste this to your htaccess.", 'wpopt') . "</b><br><br><textarea style='width: 100%; height: 200px'>$virtual_page</textarea></p>";
            }
        }

        return '';
    }

    protected function setting_fields($filter = '')
    {
        return $this->group_setting_fields(

            $this->setting_field(__('General speedup', 'wpopt'), false, "separator"),
            $this->setting_field('', false, "divide"),
            $this->setting_field(__('Improve Database performances', 'wpopt'), "db.enhance", "checkbox"),
            $this->setting_field(__('Improve WordPress Cron performances', 'wpopt'), "cron.enhance", "checkbox"),
            $this->setting_field(__('Check Cron schedules every (seconds)', 'wpopt'), "cron.timenext", "numeric", ['parent' => 'cron.enhance', 'default_value' => 300]),

            $this->setting_field(__('Server configuration (Up to now, apache only)', 'wpopt'), false, "separator"),
            $this->setting_field('', false, "divide"),
            $this->setting_field(__('Server Enhancements', 'wpopt'), "srv_enhancements.active", "checkbox"),
            $this->setting_field(__('Remove www', 'wpopt'), "srv_enhancements.remove_www", "checkbox", ['parent' => 'srv_enhancements.active']),
            $this->setting_field(__('Redirect HTTP to HTTPS', 'wpopt'), "srv_enhancements.redirect_https", "checkbox", ['parent' => 'srv_enhancements.active']),
            $this->setting_field(__('Connection keep alive', 'wpopt'), "srv_enhancements.keep_alive", "checkbox", ['parent' => 'srv_enhancements.active', 'default_value' => true]),
            $this->setting_field(__('Follow symlinks', 'wpopt'), "srv_enhancements.follow_symlinks", "checkbox", ['parent' => 'srv_enhancements.active']),
            $this->setting_field(__('Timezone', 'wpopt'), "srv_enhancements.timezone", "checkbox", ['parent' => 'srv_enhancements.active']),
            $this->setting_field(__('Default Charset UTF-8', 'wpopt'), "srv_enhancements.default_utf8", "checkbox", ['parent' => 'srv_enhancements.active']),
            $this->setting_field(__('Enable PageSpeed if installed', 'wpopt'), "srv_enhancements.pagespeed", "checkbox", ['parent' => 'srv_enhancements.active']),

            $this->setting_field('', false, "divide"),
            $this->setting_field(__('Enable server Compression', 'wpopt'), "srv_compression.active", "checkbox"),
            $this->setting_field(__('GZIP', 'wpopt'), "srv_compression.gzip", "checkbox", ['parent' => 'srv_compression.active']),
            $this->setting_field(__('BROTLI', 'wpopt'), "srv_compression.brotli", "checkbox", ['parent' => 'srv_compression.active', 'default_value' => true]),

            $this->setting_field('', false, "divide"),
            $this->setting_field(__('Enable browser cache', 'wpopt'), "srv_browser_cache.active", "checkbox"),
            $this->setting_field(__('Use Cache Control Headers', 'wpopt'), "srv_browser_cache.cache_control", "checkbox", ['parent' => 'srv_browser_cache.active', 'default_value' => true]),
            $this->setting_field(__('Default lifetime', 'wpopt'), "srv_browser_cache.lifetime_default", "numeric", ['parent' => 'srv_browser_cache.active', 'default_value' => MONTH_IN_SECONDS]),
            $this->setting_field(__('CSS & JavaScripts lifetime', 'wpopt'), "srv_browser_cache.lifetime_text", "numeric", ['parent' => 'srv_browser_cache.active', 'default_value' => MONTH_IN_SECONDS]),
            $this->setting_field(__('Images lifetime', 'wpopt'), "srv_browser_cache.lifetime_image", "numeric", ['parent' => 'srv_browser_cache.active', 'default_value' => MONTH_IN_SECONDS]),
            $this->setting_field(__('Fonts lifetime', 'wpopt'), "srv_browser_cache.lifetime_font", "numeric", ['parent' => 'srv_browser_cache.active', 'default_value' => YEAR_IN_SECONDS]),
            $this->setting_field(__('Archives lifetime', 'wpopt'), "srv_browser_cache.lifetime_archive", "numeric", ['parent' => 'srv_browser_cache.active', 'default_value' => DAY_IN_SECONDS])
        );
    }
}

return __NAMESPACE__;