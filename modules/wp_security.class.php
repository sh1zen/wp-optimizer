<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use WPS\core\Rewriter;
use WPS\modules\Module;
use WPS\core\Settings;
use WPOptimizer\modules\supporters\WP_Htaccess;

/**
 * Module for updates handling
 */
class Mod_WP_Security extends Module
{
    public array $scopes = array('settings', 'autoload');
    protected string $context = 'wpopt';
    private array $server_conf_hooks = array(
        'srv_security' => array(
            'active' => false,
        )
    );

    public function hash_version_script($target_url): string
    {
        $rewriter = Rewriter::getInstance($target_url);

        if ($this->option('dcl_security.hideversion')) {
            $rewriter->remove_query_arg('version');
            $rewriter->remove_query_arg('ver');
        }
        else {
            $rewriter->set_query_arg('ver', md5(WPS_SALT . $rewriter->get_query_var('ver', $rewriter->remove_query_arg('version'))));
        }

        return $rewriter->get_uri(false);
    }

    public function validate_settings($input, $filtering = false): array
    {
        $this->load_dependencies();

        $new_valid = parent::validate_settings($input, $filtering);
        $new_valid = $this->apply_constant_locks($new_valid);

        if ($this->constant_enabled('DISALLOW_FILE_MODS')) {
            return $new_valid;
        }

        $this->sync_server_rules($new_valid);

        return $new_valid;
    }

    public function cleanup(array $settings = array(), array $all_settings = array()): bool
    {
        $this->load_dependencies();

        if ($this->constant_enabled('DISALLOW_FILE_MODS')) {
            return true;
        }

        return $this->sync_server_rules($this->inactive_server_rules_settings());
    }

    public function activate(array $settings = array(), array $all_settings = array()): bool
    {
        $this->load_dependencies();
        $settings = $this->apply_constant_locks(!empty($settings) ? $settings : $this->option());

        if ($this->constant_enabled('DISALLOW_FILE_MODS')) {
            return true;
        }

        return $this->sync_server_rules($settings);
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

    private function apply_constant_locks(array $settings): array
    {
        if ($this->constant_enabled('DISALLOW_FILE_EDIT')) {
            $settings['a_api']['disable_file_editor'] = true;
        }

        if ($this->constant_enabled('DISALLOW_FILE_MODS')) {
            $settings['srv_security']['active'] = false;
        }

        return $settings;
    }

    private function constant_enabled(string $constant): bool
    {
        return defined($constant) && constant($constant);
    }

    private function locked_checkbox_args(array $args, string $constant, bool $locked_value, string $hint): array
    {
        if (!$this->constant_enabled($constant)) {
            return $args;
        }

        $args['value'] = $locked_value;
        $args['default_value'] = $locked_value;
        $args['props']['disabled'] = 'disabled';
        $args['props']['aria-disabled'] = 'true';
        $args['props']['title'] = $hint;
        $args['classes'][] = 'wpopt-setting-locked-by-constant';
        $args['label'] = trim(($args['label'] ?? '') . ' ' . $hint);

        return $args;
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

            if (!$this->constant_enabled('DISALLOW_FILE_MODS') && !WP_Htaccess::is_rules_file_writable()) {
                add_action('admin_notices', function () {
                    $this->add_notices('error', sprintf(__("'%1\$s' is not writable. Update its permissions to allow WP Optimizer to apply %2\$s server-rule changes automatically. Until this file is writable, WP Optimizer cannot apply some performance, security, and compatibility improvements automatically.", 'wpopt'), WP_Htaccess::get_rules_path(), WP_Htaccess::get_server_label()));
                }, 0);
            }
        }

        $this->security();
    }

    private function security(): void
    {
        if ($this->option('a_api.active')) {
            // user enumeration patch
            if ($this->option('a_api.nousernum') and !is_admin()) {
                // default URL format
                if (preg_match('/author=([0-9]*)/i', $_SERVER['QUERY_STRING']) or preg_match('/\?author=([0-9]*)(\/*)/i', $_SERVER['QUERY_STRING'])) {
                    Rewriter::getInstance()->redirect(get_option('home'), 302);
                    exit;
                }
            }

            // disable file edit
            if ($this->option('a_api.disable_file_editor') and !defined('DISALLOW_FILE_EDIT')) {
                define('DISALLOW_FILE_EDIT', true);
            }
        }

        if ($this->option('dcl_security.active')) {

            if ($this->option('dcl_security.nowpgenerator') or $this->option('dcl_security.nowpversion')) {
                // remove version from head
                remove_action('wp_head', 'wp_generator');
                // remove version from rss
                add_filter('the_generator', '__return_empty_string');

                if (function_exists('remove_yoast_seo_comments_fn')) {
                    add_action('template_redirect', 'remove_yoast_seo_comments_fn', 10000);
                }
            }

            if (!is_admin() and ($this->option('dcl_security.hashversion') or $this->option('dcl_security.hideversion'))) {
                add_filter('style_loader_src', array($this, 'hash_version_script'), 10000);
                add_filter('script_loader_src', array($this, 'hash_version_script'), 10000);
            }
        }
    }

    protected function print_header(): string
    {
        if ($this->constant_enabled('DISALLOW_FILE_MODS')) {
            return "<p><b>" . esc_html__('Server-rule changes are disabled because DISALLOW_FILE_MODS is enabled in wp-config.php.', 'wpopt') . "</b></p>";
        }

        $this->load_dependencies();
        $header = '';

        if (WP_Htaccess::is_nginx()) {
            $header .= "<p><b>" . esc_html__('Nginx server rules', 'wpopt') . "</b><br>" . sprintf(esc_html__("WP-Optimizer writes Nginx rules to %s. Include this file inside your Nginx server block, then reload Nginx after changes. Optional module directives such as Brotli and PageSpeed are written as comments to avoid reload errors when the module is not installed.", 'wpopt'), '<code>' . esc_html(WP_Htaccess::get_rules_path()) . '</code>') . "</p>";
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
        $file_mods_hint = __('Locked by DISALLOW_FILE_MODS in wp-config.php. WordPress has disabled file modifications, so WP Optimizer cannot manage server rules or filesystem-writing features from this screen.', 'wpopt');
        $file_editor_hint = __('Locked by DISALLOW_FILE_EDIT in wp-config.php. The WordPress file editor is already disabled by configuration.', 'wpopt');
        $srv_security_active_args = $this->locked_checkbox_args(['default_value' => true], 'DISALLOW_FILE_MODS', false, $file_mods_hint);
        $disable_file_editor_args = $this->locked_checkbox_args(['parent' => 'a_api.active'], 'DISALLOW_FILE_EDIT', true, $file_editor_hint);

        return $this->group_setting_fields(
            $this->group_setting_fields(
                $this->setting_field(__('Requests and Server', 'wpopt'), "srv_security.active", "checkbox", $srv_security_active_args),
                $this->setting_field(__('Disable directory listing', 'wpopt'), "srv_security.listings", "checkbox", ['parent' => 'srv_security.active', 'default_value' => true]),
                $this->setting_field(__('Disable access to configuration files (.htaccess/nginx.conf)', 'wpopt'), "srv_security.protect_htaccess", "checkbox", ['parent' => 'srv_security.active', 'default_value' => true]),
                $this->setting_field(__('Enable HTTPS Strict Transport Security', 'wpopt'), "srv_security.hsts", "checkbox", ['parent' => 'srv_security.active', 'default_value' => true]),
                $this->setting_field(__('Disable Cross-Origin Resource Sharing', 'wpopt'), "srv_security.cors", "checkbox", ['parent' => 'srv_security.active', 'default_value' => true]),
                $this->setting_field(__('Disable HTTP Track & Trace', 'wpopt'), "srv_security.http_track&trace", "checkbox", ['parent' => 'srv_security.active', 'default_value' => true]),
                $this->setting_field(__('Block Cross Site Scripting', 'wpopt'), "srv_security.xss", "checkbox", ['parent' => 'srv_security.active', 'default_value' => true]),
                $this->setting_field(__('Send No Sniff Header', 'wpopt'), "srv_security.nosniff", "checkbox", ['parent' => 'srv_security.active', 'default_value' => true]),
                $this->setting_field(__('Send No Referrer Header', 'wpopt'), "srv_security.noreferrer", "checkbox", ['parent' => 'srv_security.active']),
                $this->setting_field(__('Send No Frame Header', 'wpopt'), "srv_security.noframe", "checkbox", ['parent' => 'srv_security.active']),
                $this->setting_field(__('Disable server signature ', 'wpopt'), "srv_security.signature", "checkbox", ['parent' => 'srv_security.active', 'default_value' => true]),
            ),
            $this->group_setting_fields(
                $this->setting_field(__('Disable Information Disclosure & Remove Meta information.', 'wpopt'), "dcl_security.active", "checkbox"),
                $this->setting_field(__('Hide WordPress Version Number', 'wpopt'), "dcl_security.nowpversion", "checkbox", ['parent' => 'dcl_security.active', 'default_value' => true]),
                $this->setting_field(__('Remove WordPress Meta Generator Tag', 'wpopt'), "dcl_security.nowpgenerator", "checkbox", ['parent' => 'dcl_security.active', 'default_value' => true]),
                $this->setting_field(__('Hash versioned Styles and Scripts', 'wpopt'), "dcl_security.hashversion", "checkbox", ['parent' => 'dcl_security.active']),
                $this->setting_field(__('Hide version from Styles and Scripts', 'wpopt'), "dcl_security.hideversion", "checkbox", ['parent' => 'dcl_security.active']),
            ),
            $this->group_setting_fields(
                $this->setting_field(__('Admin & API Security', 'wpopt'), "a_api.active", "checkbox"),
                $this->setting_field(__('Disable WP User Enumeration', 'wpopt'), "a_api.nousernum", "checkbox", ['parent' => 'a_api.active', 'default_value' => true]),
                $this->setting_field(__('Disable WP File Editor', 'wpopt'), "a_api.disable_file_editor", "checkbox", $disable_file_editor_args),
            )
        );
    }

    protected function infos(): array
    {
        return [
            'srv_security.active'           => __("Enable server-level security hardening rules for Apache or Nginx environments.", 'wpopt'),
            'srv_security.listings'         => __("Directory listing is a web server feature that displays the contents of a directory when an index file is not present, potentially exposing sensitive information.", 'wpopt'),
            'srv_security.protect_htaccess' => __("Disabling public access to server configuration files prevents unauthorized reads of rewrite and hardening rules, improving website security.", 'wpopt'),
            'srv_security.hsts'             => __("Enabling HTTPS Strict Transport Security (HSTS) enforces secure HTTPS connections, reducing the risk of man-in-the-middle attacks and improving website security.", 'wpopt'),
            'srv_security.cors'             => __("Disabling Cross-Origin Resource Sharing (CORS) restricts cross-domain requests, improving website security by preventing unauthorized access to resources.", 'wpopt'),
            'srv_security.http_track&trace' => __("Disabling HTTP track and trace prevents servers from disclosing information about requests, improving website security by reducing the risk of sensitive data leaks.", 'wpopt'),
            'srv_security.xss'              => __("Blocking cross-site scripting (XSS) prevents malicious scripts from executing on a website, improving website security and protecting user data.", 'wpopt'),
            'srv_security.nosniff'          => __("Sending NoSniff header instructs web browsers to prevent MIME type sniffing, improving website security by reducing the risk of content spoofing attacks.", 'wpopt'),
            'srv_security.noreferrer'       => __("Sending no referrer header prevents the browser from sending information about the previous page visited, improving user privacy and security.", 'wpopt'),
            'srv_security.noframe'          => __("Sending no-frame headers prevent website content from being displayed within an iframe, improving website security and preventing clickjacking attacks.", 'wpopt'),
            'srv_security.signature'        => __("Disable server signature exposure to reduce information disclosure in HTTP responses.", 'wpopt'),
            'dcl_security.active'           => __("Enable WordPress metadata and version disclosure protections.", 'wpopt'),
            'dcl_security.nowpversion'      => __("Remove visible WordPress version references to reduce fingerprinting risk.", 'wpopt'),
            'dcl_security.nowpgenerator'    => __("Remove WordPress generator meta tags from page output and feeds.", 'wpopt'),
            'dcl_security.hashversion'      => __("Replace static asset version values with hashed values to reduce version disclosure.", 'wpopt'),
            'dcl_security.hideversion'      => __("Remove version query strings from scripts and styles URLs.", 'wpopt'),
            'a_api.active'                  => __("Enable admin and API related hardening options.", 'wpopt'),
            'a_api.nousernum'               => __("Block basic user enumeration techniques via author archive query parameters.", 'wpopt'),
            'a_api.disable_file_editor'     => __("Disable the built-in plugin and theme file editor in the WordPress admin.", 'wpopt'),
        ];
    }
}

return __NAMESPACE__;
