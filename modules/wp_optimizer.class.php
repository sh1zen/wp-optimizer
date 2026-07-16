<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use WPS\core\Settings;
use WPS\modules\Module;
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

        $this->sync_server_rules($new_valid);

        return $new_valid;
    }

    public function cleanup(array $settings = array(), array $all_settings = array()): bool
    {
        $this->load_dependencies();
        wps('wpopt')->options->remove('last_cron_event', 'cron', 'wp_optimizer');

        return $this->sync_server_rules($this->inactive_server_rules_settings());
    }

    public function activate(array $settings = array(), array $all_settings = array()): bool
    {
        $this->load_dependencies();

        return $this->sync_server_rules(!empty($settings) ? $settings : $this->option());
    }

    private function inactive_server_rules_settings(): array
    {
        $settings = array();

        foreach ($this->server_conf_hooks as $server_hooks => $value) {
            $settings[$server_hooks]['active'] = false;
        }

        return $settings;
    }

    private function sync_server_rules(array $settings): bool
    {
        $htaccess = new WP_Htaccess($settings);

        foreach ($this->server_conf_hooks as $server_hooks => $value) {

            $htaccess->toggle_rule($server_hooks, Settings::get_option($settings, "$server_hooks.active"));
        }

        if ($htaccess->edited()) {
            return $htaccess->write();
        }

        return true;
    }

    private function load_dependencies(): void
    {
        require_once WPOPT_SUPPORTERS . 'optisec/localConf.php';
    }

    public function restricted_access($context = ''): bool
    {
        if ($context === 'settings')
            return !current_user_can('manage_options');

        return false;
    }

    protected function init(): void
    {
        if (is_admin()) {
            $this->load_dependencies();

            if (!WP_Htaccess::is_rules_file_writable()) {
                add_action('admin_notices', function () {
                    $this->add_notices('error', sprintf(__("'%1\$s' is not writable. Update its permissions to allow WP Optimizer to apply %2\$s server-rule changes automatically. Until this file is writable, WP Optimizer cannot apply some performance, security, and compatibility improvements automatically.", 'wpopt'), WP_Htaccess::get_rules_path(), WP_Htaccess::get_server_label()));
                }, 0);
            }
        }

        $this->optimize();
    }

    private function optimize(): void
    {
        if (is_admin() && $this->option('heartbeat.admin_control', true)) {
            add_filter('heartbeat_settings', array($this, 'control_admin_heartbeat'));
        }

        if ($this->option('cron.enhance')) {

            if (!defined('WP_CRON_LOCK_TIMEOUT')) {
                define('WP_CRON_LOCK_TIMEOUT', MINUTE_IN_SECONDS);
            }

            remove_action('init', 'wp_cron');

            if (time() > $this->option('cron.timenext', 300) + wps('wpopt')->options->get('last_cron_event', 'cron', 'wp_optimizer', 0)) {

                spawn_cron();

                wps('wpopt')->options->update('last_cron_event', 'cron', time(), 'wp_optimizer');
            }
        }
    }

    public function control_admin_heartbeat(array $settings): array
    {
        $settings['interval'] = max(15, min(120, absint($this->option('heartbeat.admin_interval', 60))));

        return $settings;
    }

    protected function print_header(): string
    {
        $this->load_dependencies();
        $header = '';

        if (WP_Htaccess::is_nginx()) {
            $header .= "<p><b>" . esc_html__('Nginx server rules', 'wpopt') . "</b><br>" . sprintf(esc_html__("WP-Optimizer writes Nginx rules to %s. Include this file inside your Nginx server block, then reload Nginx after changes. Optional module directives such as Brotli and PageSpeed are written as comments to avoid reload errors when the module is not installed.", 'wpopt'), '<code>' . esc_html(WP_Htaccess::get_rules_path()) . '</code>') . "</p>";
        }
        elseif (WP_Htaccess::is_openlitespeed()) {
            $header .= "<p><b>" . esc_html__('OpenLiteSpeed server rules', 'wpopt') . "</b><br>" . esc_html__('WP-Optimizer writes supported rewrite rules to .htaccess. Enable Auto Load from .htaccess in OpenLiteSpeed WebAdmin; configure compression, cache headers and other non-rewrite directives in WebAdmin.', 'wpopt') . "</p>";
        }

        if (!WP_Htaccess::is_rules_file_writable()) {
            $htaccess = new WP_Htaccess($this->option());

            foreach ($this->server_conf_hooks as $server_hooks => $value) {

                $htaccess->toggle_rule($server_hooks, $this->option("{$server_hooks}.active"));
            }

            if ($htaccess->edited() and !$htaccess->write()) {

                $virtual_page = esc_textarea($htaccess->get_rules());

                return $header . "<p><b>" . sprintf(esc_html__("Your %s file is not modifiable, to make desired enhancements copy and paste those lines to the server configuration file.", 'wpopt'), esc_html(WP_Htaccess::get_rules_file_name())) . "</b><br><br><textarea style='width: 100%; height: 200px'>$virtual_page</textarea></p>";
            }
        }

        return $header;
    }

    protected function setting_fields($filter = ''): array
    {
        return $this->group_setting_fields(

            $this->group_setting_fields(
                $this->setting_field(__('General speedup', 'wpopt'), false, "separator"),
                $this->setting_field(__('Improve WordPress Cron performances', 'wpopt'), "cron.enhance", "checkbox", ['default_value' => true]),
                $this->setting_field(__('Check Cron schedules every (seconds)', 'wpopt'), "cron.timenext", "numeric", ['parent' => 'cron.enhance', 'default_value' => 300]),
                $this->setting_field(__('Control wp-admin Heartbeat', 'wpopt'), "heartbeat.admin_control", "checkbox", ['default_value' => true]),
                $this->setting_field(__('wp-admin Heartbeat interval (seconds)', 'wpopt'), "heartbeat.admin_interval", "numeric", ['parent' => 'heartbeat.admin_control', 'default_value' => 60, 'props' => ['min' => 15, 'max' => 120]]),
            ),

            $this->group_setting_fields(
                $this->setting_field(__('Server Enhancements', 'wpopt'), "srv_enhancements.active", "checkbox"),
                $this->setting_field(__('Remove www', 'wpopt'), "srv_enhancements.remove_www", "checkbox", ['parent' => 'srv_enhancements.active', 'risk' => 'danger']),
                $this->setting_field(__('Redirect HTTP to HTTPS', 'wpopt'), "srv_enhancements.redirect_https", "checkbox", ['parent' => 'srv_enhancements.active', 'risk' => 'danger']),
                $this->setting_field(__('Connection keep alive', 'wpopt'), "srv_enhancements.keep_alive", "checkbox", ['parent' => 'srv_enhancements.active', 'default_value' => true]),
                $this->setting_field(__('Follow symlinks', 'wpopt'), "srv_enhancements.follow_symlinks", "checkbox", ['parent' => 'srv_enhancements.active', 'risk' => 'danger']),
                $this->setting_field(__('Timezone', 'wpopt'), "srv_enhancements.timezone", "checkbox", ['parent' => 'srv_enhancements.active']),
                $this->setting_field(__('Default Charset UTF-8', 'wpopt'), "srv_enhancements.default_utf8", "checkbox", ['parent' => 'srv_enhancements.active']),
                $this->setting_field(__('Enable PageSpeed if installed', 'wpopt'), "srv_enhancements.pagespeed", "checkbox", ['parent' => 'srv_enhancements.active']),
            ),

            $this->group_setting_fields(
                $this->setting_field(__('Enable server ongoing scripts compression', 'wpopt'), "srv_compression.active", "checkbox", ['default_value' => true]),
                $this->setting_field(__('GZIP', 'wpopt'), "srv_compression.gzip", "checkbox", ['parent' => 'srv_compression.active']),
                $this->setting_field(__('BROTLI', 'wpopt'), "srv_compression.brotli", "checkbox", ['parent' => 'srv_compression.active', 'default_value' => true]),
            ),

            $this->group_setting_fields(
                $this->setting_field(__('Enable browser cache', 'wpopt'), "srv_browser_cache.active", "checkbox", ['default_value' => true]),
                $this->setting_field(__('Immutable', 'wpopt'), "srv_browser_cache.immutable", "checkbox", ['parent' => 'srv_browser_cache.active', 'default_value' => true]),
                $this->setting_field(__('Stale if Error', 'wpopt'), "srv_browser_cache.stale_error", "checkbox", ['parent' => 'srv_browser_cache.active', 'default_value' => true]),
                $this->setting_field(__('Stale while revalidate ', 'wpopt'), "srv_browser_cache.stale_revalidate", "checkbox", ['parent' => 'srv_browser_cache.active', 'default_value' => true]),
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
            'cron.timenext'                      => __("Interval in seconds used to trigger background cron checks when cron optimization is enabled.", 'wpopt'),
            'heartbeat.admin_control'            => __("Reduce wp-admin Heartbeat traffic by forcing a custom interval for the WordPress Heartbeat API in admin pages.", 'wpopt'),
            'heartbeat.admin_interval'           => __("Interval in seconds for wp-admin Heartbeat requests. WordPress supports values between 15 and 120 seconds.", 'wpopt'),
            'srv_enhancements.active'            => __("Enable server enhancement directives for Apache, Nginx, LiteSpeed or OpenLiteSpeed.", 'wpopt'),
            'srv_enhancements.remove_www'        => __("Redirect www URLs to non-www URLs to enforce a single canonical hostname.", 'wpopt'),
            'srv_enhancements.redirect_https'    => __("Force HTTP requests to redirect to HTTPS.", 'wpopt'),
            'srv_enhancements.keep_alive'        => __("Connection keep-alive is a feature that maintains an open connection between a client and a server, reducing the need to repeatedly establish connections, improving website performance.", 'wpopt'),
            'srv_enhancements.follow_symlinks'   => __("Follow symbolic link is an option that allows a web server to follow symbolic links when serving content, expanding the available content and improving website functionality, but slowing it down.", 'wpopt'),
            'srv_enhancements.pagespeed'         => __("Google PageSpeed PHP module is a server-side module that optimizes web pages and improves website performance, by applying best practices and filters to HTML, CSS, and JavaScript.", 'wpopt'),
            'srv_enhancements.timezone'          => __("Add current timezone as default.", 'wpopt'),
            'srv_enhancements.default_utf8'      => __("Set UTF-8 as default response charset for supported content types.", 'wpopt'),
            'srv_compression.active'             => __("Enable server-side compression rules for supported assets.", 'wpopt'),
            'srv_compression.gzip'               => __("Enable GZIP compression for text-based responses.", 'wpopt'),
            'srv_compression.brotli'             => __("Brotli is a newer compression algorithm that provides better compression rates than Gzip, resulting in smaller file sizes and faster website performance.", 'wpopt'),
            'srv_browser_cache.active'           => __("Enable browser caching headers for static resources.", 'wpopt'),
            'srv_browser_cache.immutable'        => __("Prevent cached content from being overwritten, reducing the need to revalidate unchanged content.", 'wpopt'),
            'srv_browser_cache.stale_error'      => __("Allow cached content to be served when there is a server error, improving website availability and reducing server load.", 'wpopt'),
            'srv_browser_cache.stale_revalidate' => __("Serve stale content while updating it in the background, improving website performance and reducing the impact of validation delays.", 'wpopt'),
            'srv_browser_cache.lifetime_default' => __("Default cache lifetime (in seconds) applied when no specific asset rule matches.", 'wpopt'),
            'srv_browser_cache.lifetime_text'    => __("Cache lifetime (in seconds) for CSS and JavaScript resources.", 'wpopt'),
            'srv_browser_cache.lifetime_image'   => __("Cache lifetime (in seconds) for image resources.", 'wpopt'),
            'srv_browser_cache.lifetime_font'    => __("Cache lifetime (in seconds) for font resources.", 'wpopt'),
            'srv_browser_cache.lifetime_archive' => __("Cache lifetime (in seconds) for archive resources such as compressed files.", 'wpopt'),
        ];
    }
}

return __NAMESPACE__;
