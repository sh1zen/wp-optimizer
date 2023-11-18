<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
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

    public function hash_version_script($target_url)
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

    protected function init(): void
    {
        if (is_admin()) {
            if (!is_writable(ABSPATH . '.htaccess')) {
                $this->add_notices('error', sprintf(__("<b><i>'%s'</i> is not writable.</b><br>Modify (<b>run chmod 774</b>) it's group permission to allow WP-Security to make changes automatically.", 'wpopt'), ABSPATH . '.htaccess'));
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
            $this->setting_field(__('Some configuration are available only in apache.', 'wpopt'), false, "separator"),

            $this->group_setting_fields(
                $this->setting_field(__('Requests and Server', 'wpopt'), "srv_security.active", "checkbox"),
                $this->setting_field(__('Disable directory listing', 'wpopt'), "srv_security.listings", "checkbox", ['parent' => 'srv_security.active', 'default_value' => true]),
                $this->setting_field(__('Disable access to configuration file (.htaccess)', 'wpopt'), "srv_security.protect_htaccess", "checkbox", ['parent' => 'srv_security.active', 'default_value' => true]),
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
                $this->setting_field(__('Disable WP File Editor', 'wpopt'), "a_api.disable_file_editor", "checkbox", ['parent' => 'a_api.active']),
            )
        );
    }

    protected function infos(): array
    {
        return [
            'srv_security.listings'         => __("Directory listing is a web server feature that displays the contents of a directory when an index file is not present, potentially exposing sensitive information.", 'wpopt'),
            'srv_security.protect_htaccess' => __("Disabling access to .htaccess files prevents unauthorized modification of server configuration settings, improving website security..", 'wpopt'),
            'srv_security.hsts'             => __("Enabling HTTPS Strict Transport Security (HSTS) enforces secure HTTPS connections, reducing the risk of man-in-the-middle attacks and improving website security.", 'wpopt'),
            'srv_security.cors'             => __("Disabling Cross-Origin Resource Sharing (CORS) restricts cross-domain requests, improving website security by preventing unauthorized access to resources.", 'wpopt'),
            'srv_security.http_track&trace' => __("Disabling HTTP track and trace prevents servers from disclosing information about requests, improving website security by reducing the risk of sensitive data leaks.", 'wpopt'),
            'srv_security.xss'              => __("Blocking cross-site scripting (XSS) prevents malicious scripts from executing on a website, improving website security and protecting user data.", 'wpopt'),
            'srv_security.nosniff'          => __("Sending NoSniff header instructs web browsers to prevent MIME type sniffing, improving website security by reducing the risk of content spoofing attacks.", 'wpopt'),
            'srv_security.noreferrer'       => __("Sending no referrer header prevents the browser from sending information about the previous page visited, improving user privacy and security.", 'wpopt'),
            'srv_security.noframe'          => __("Sending no-frame headers prevent website content from being displayed within an iframe, improving website security and preventing clickjacking attacks.", 'wpopt'),
        ];
    }
}

return __NAMESPACE__;