<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use SHZN\core\Settings;
use SHZN\modules\Module;
use WPOptimizer\modules\supporters\WP_Htaccess;

/**
 * Module for updates handling
 */
class Mod_WP_Optimizer extends Module
{
    public array $scopes = array('settings', 'autoload');
    protected string $context = 'wpopt';
    private array $server_conf_hooks = array(
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

    public function validate_settings($input, $filtering = false): array
    {
        $this->load_dependencies();

        $new_valid = parent::validate_settings($input, $filtering);

        $htaccess = new WP_Htaccess($new_valid);

        foreach ($this->server_conf_hooks as $server_hooks => $value) {

            $htaccess->toggle_rule($server_hooks, Settings::get_option($new_valid, "$server_hooks.active"));
        }

        if ($htaccess->edited()) {
            $htaccess->write();
        }

        return $new_valid;
    }

    private function load_dependencies()
    {
        require_once WPOPT_SUPPORTERS . 'optisec/WP_Htaccess.class.php';
    }

    public function restricted_access($context = ''): bool
    {
        if ($context === 'settings')
            return !current_user_can('manage_options');

        return false;
    }

    protected function init()
    {
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
    }

    protected function print_header(): string
    {
        if (!is_writable(ABSPATH . '.htaccess')) {

            $this->load_dependencies();

            $htaccess = new WP_Htaccess($this->option());

            foreach ($this->server_conf_hooks as $server_hooks => $value) {

                $htaccess->toggle_rule($server_hooks, $this->option("{$server_hooks}.active"));
            }

            if ($htaccess->edited() and !$htaccess->write()) {

                $virtual_page = htmlentities($htaccess->get_rules());

                return "<p><b>" . __("Your htaccess file is not modifiable, to make desired enhancements copy and paste those lines to .htaccess file.", 'wpopt') . "</b><br><br><textarea style='width: 100%; height: 200px'>$virtual_page</textarea></p>";
            }
        }

        return '';
    }

    protected function setting_fields($filter = ''): array
    {
        return $this->group_setting_fields(

            $this->group_setting_fields(
                $this->setting_field(__('General speedup', 'wpopt'), false, "separator"),
                $this->setting_field(__('Improve WordPress Cron performances', 'wpopt'), "cron.enhance", "checkbox"),
                $this->setting_field(__('Check Cron schedules every (seconds)', 'wpopt'), "cron.timenext", "numeric", ['parent' => 'cron.enhance', 'default_value' => 300]),
            ),

            $this->setting_field(__('Server configuration (Up to now, apache only)', 'wpopt'), false, "separator"),

            $this->group_setting_fields(
                $this->setting_field(__('Server Enhancements', 'wpopt'), "srv_enhancements.active", "checkbox"),
                $this->setting_field(__('Remove www', 'wpopt'), "srv_enhancements.remove_www", "checkbox", ['parent' => 'srv_enhancements.active']),
                $this->setting_field(__('Redirect HTTP to HTTPS', 'wpopt'), "srv_enhancements.redirect_https", "checkbox", ['parent' => 'srv_enhancements.active']),
                $this->setting_field(__('Connection keep alive', 'wpopt'), "srv_enhancements.keep_alive", "checkbox", ['parent' => 'srv_enhancements.active', 'default_value' => true]),
                $this->setting_field(__('Follow symlinks', 'wpopt'), "srv_enhancements.follow_symlinks", "checkbox", ['parent' => 'srv_enhancements.active']),
                $this->setting_field(__('Timezone', 'wpopt'), "srv_enhancements.timezone", "checkbox", ['parent' => 'srv_enhancements.active']),
                $this->setting_field(__('Default Charset UTF-8', 'wpopt'), "srv_enhancements.default_utf8", "checkbox", ['parent' => 'srv_enhancements.active']),
                $this->setting_field(__('Enable PageSpeed if installed', 'wpopt'), "srv_enhancements.pagespeed", "checkbox", ['parent' => 'srv_enhancements.active']),
            ),

            $this->group_setting_fields(
                $this->setting_field(__('Enable server ongoing scripts compression', 'wpopt'), "srv_compression.active", "checkbox"),
                $this->setting_field(__('GZIP', 'wpopt'), "srv_compression.gzip", "checkbox", ['parent' => 'srv_compression.active']),
                $this->setting_field(__('BROTLI', 'wpopt'), "srv_compression.brotli", "checkbox", ['parent' => 'srv_compression.active', 'default_value' => true]),
            ),

            $this->group_setting_fields(
                $this->setting_field(__('Enable browser cache', 'wpopt'), "srv_browser_cache.active", "checkbox"),
                $this->setting_field(__('Immutable', 'wpopt'), "srv_browser_cache.immutable", "checkbox", ['parent' => 'srv_browser_cache.active', 'default_value' => false]),
                $this->setting_field(__('Stale if Error', 'wpopt'), "srv_browser_cache.stale_error", "checkbox", ['parent' => 'srv_browser_cache.active', 'default_value' => false]),
                $this->setting_field(__('Stale while revalidate ', 'wpopt'), "srv_browser_cache.stale_revalidate", "checkbox", ['parent' => 'srv_browser_cache.active', 'default_value' => false]),
                $this->setting_field(__('Default lifetime', 'wpopt'), "srv_browser_cache.lifetime_default", "numeric", ['parent' => 'srv_browser_cache.active', 'default_value' => YEAR_IN_SECONDS]),
                $this->setting_field(__('CSS & JavaScripts lifetime', 'wpopt'), "srv_browser_cache.lifetime_text", "numeric", ['parent' => 'srv_browser_cache.active', 'default_value' => YEAR_IN_SECONDS]),
                $this->setting_field(__('Images lifetime', 'wpopt'), "srv_browser_cache.lifetime_image", "numeric", ['parent' => 'srv_browser_cache.active', 'default_value' => YEAR_IN_SECONDS]),
                $this->setting_field(__('Fonts lifetime', 'wpopt'), "srv_browser_cache.lifetime_font", "numeric", ['parent' => 'srv_browser_cache.active', 'default_value' => YEAR_IN_SECONDS]),
                $this->setting_field(__('Archives lifetime', 'wpopt'), "srv_browser_cache.lifetime_archive", "numeric", ['parent' => 'srv_browser_cache.active', 'default_value' => DAY_IN_SECONDS])
            )
        );
    }

    protected function infos(): array
    {
        return [
            'cron.enhance'                       => __("Prevent WordPress from checking for cron-jobs at each request, instead does it in background every custom time.", 'wpopt'),
            'srv_enhancements.keep_alive'        => __("Connection keep-alive is a feature that maintains an open connection between a client and a server, reducing the need to repeatedly establish connections, improving website performance.", 'wpopt'),
            'srv_enhancements.follow_symlinks'   => __("Follow symbolic link is an option that allows a web server to follow symbolic links when serving content, expanding the available content and improving website functionality, but slowing it down.", 'wpopt'),
            'srv_enhancements.pagespeed'         => __("Google PageSpeed PHP module is a server-side module that optimizes web pages and improves website performance, by applying best practices and filters to HTML, CSS, and JavaScript.", 'wpopt'),
            'srv_enhancements.timezone'          => __("Add current timezone as default.", 'wpopt'),
            'srv_compression.brotli'             => __("Brotli is a newer compression algorithm that provides better compression rates than Gzip, resulting in smaller file sizes and faster website performance.", 'wpopt'),
            'srv_browser_cache.immutable'        => __("Prevent cached content from being overwritten, reducing the need to revalidate unchanged content.", 'wpopt'),
            'srv_browser_cache.stale_error'      => __("Allow cached content to be served when there is a server error, improving website availability and reducing server load.", 'wpopt'),
            'srv_browser_cache.stale_revalidate' => __("Serve stale content while updating it in the background, improving website performance and reducing the impact of validation delays.", 'wpopt'),
        ];
    }
}

return __NAMESPACE__;