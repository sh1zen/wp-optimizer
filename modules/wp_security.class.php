<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C)  2022
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use SHZN\modules\Module;

use SHZN\core\Settings;
use WPOptimizer\modules\supporters\WP_Enhancer;

/**
 * Module for updates handling
 */
class Mod_WP_Security extends Module
{
    public $scopes = array('settings', 'autoload');

    private $server_conf_hooks;

    public function __construct()
    {
        $this->server_conf_hooks = array(
            'srv_security' => array(
                'active' => false,
            )
        );

        parent::__construct('wpopt',
            array(
                'settings' => $this->server_conf_hooks
            )
        );

        if (is_admin()) {
            if (!is_writable(ABSPATH . '.htaccess')) {
                $this->add_notices('error', sprintf(__("<b><i>'%s'</i> is not writable.</b><br>Modify (<b>run chmod 774</b>) it's group permission to allow WP-Security to make changes automatically.", 'wpopt'), ABSPATH . '.htaccess'));
            }
        }

        $this->security();
    }

    private function security()
    {
        if ($this->option('a_api.active')) {
            // user enumeration patch
            if ($this->option('security.nousernum') and !is_admin()) {
                // default URL format
                if (preg_match('/author=([0-9]*)/i', $_SERVER['QUERY_STRING']) or preg_match('/\?author=([0-9]*)(\/*)/i', $_SERVER['QUERY_STRING'])) {
                    wp_redirect(get_option('home'), 302);
                    exit;
                }
            }

            // disable xml rpc
            if ($this->option('security.disable_xml_rpc')) {

                if (stripos(strtolower($_SERVER['REQUEST_URI']), 'xmlrpc') !== false) {
                    die();
                }

                // Disable use XML-RPC
                add_filter('xmlrpc_enabled', '__return_false');

                // Disable X-Pingback to header
                add_filter('wp_headers', function ($headers) {
                    unset($headers['X-Pingback']);

                    return $headers;
                });
            }

            // disable json api
            if ($this->option('security.disable_jsonapi')) {
                add_filter('rest_authentication_errors', function ($result) {
                    if (!empty($result)) {
                        return $result;
                    }
                    if (!is_user_logged_in()) {
                        return new \WP_Error('rest_not_logged_in', 'You are not currently logged in.', array('status' => 401));
                    }
                    return $result;
                });
            }

            // disable file edit
            if ($this->option('security.disable_file_editor') and !defined('DISALLOW_FILE_EDIT')) {
                define('DISALLOW_FILE_EDIT', true);
            }
        }

        if ($this->option('dcl_security.active')) {

            if ($this->option('dcl_security.nowpgenerator') or $this->option('dcl_security.nowpversion')) {
                // remove version from head
                remove_action('wp_head', 'wp_generator');
                // remove version from rss
                add_filter('the_generator', '__return_empty_string');

                if (function_exists('remove_yoast_seo_comments_fn'))
                    add_action('template_redirect', 'remove_yoast_seo_comments_fn', 9999);
            }

            if ($this->option('dcl_security.nocssversion')) {
                add_filter('style_loader_src', array($this, 'remove_version_script_style'), 20000);
            }

            if ($this->option('dcl_security.nojsversion')) {
                add_filter('script_loader_src', array($this, 'remove_version_script_style'), 20000);
            }
        }
    }

    public function remove_version_script_style($target_url)
    {
        $target_url = remove_query_arg('ver', $target_url);
        return remove_query_arg('version', $target_url);
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
            $this->setting_field(__('Some configuration are available only in apache.', 'wpopt'), false, "separator"),
            $this->setting_field(__('Requests and Server', 'wpopt'), "srv_security.active", "checkbox"),
            $this->setting_field(__('Disable directory listing', 'wpopt'), "srv_security.listings", "checkbox", ['parent' => 'srv_security.active', 'default_value' => true]),
            $this->setting_field(__('Disable access to configuration file', 'wpopt'), "srv_security.protect_htaccess", "checkbox", ['parent' => 'srv_security.active', 'default_value' => true]),
            $this->setting_field(__('Enable HTTPS Strict Transport Security', 'wpopt'), "srv_security.hsts", "checkbox", ['parent' => 'srv_security.active', 'default_value' => true]),
            $this->setting_field(__('Disable Cross-Origin Resource Sharing', 'wpopt'), "srv_security.cors", "checkbox", ['parent' => 'srv_security.active', 'default_value' => true]),
            $this->setting_field(__('Disable HTTP Track & Trace', 'wpopt'), "srv_security.http_track&trace", "checkbox", ['parent' => 'srv_security.active', 'default_value' => true]),
            $this->setting_field(__('Block Cross Site Scripting', 'wpopt'), "srv_security.xss", "checkbox", ['parent' => 'srv_security.active', 'default_value' => true]),
            $this->setting_field(__('Send No Sniff Header', 'wpopt'), "srv_security.nosniff", "checkbox", ['parent' => 'srv_security.active', 'default_value' => true]),
            $this->setting_field(__('Send No Referrer Header', 'wpopt'), "srv_security.noreferrer", "checkbox", ['parent' => 'srv_security.active']),
            $this->setting_field(__('Send No Frame Header', 'wpopt'), "srv_security.noframe", "checkbox", ['parent' => 'srv_security.active']),
            $this->setting_field(__('Disable server signature ', 'wpopt'), "srv_security.signature", "checkbox", ['parent' => 'srv_security.active']),

            $this->setting_field('', false, "divide"),
            $this->setting_field(__('Disable Information Disclosure & Remove Meta information.', 'wpopt'), "dcl_security.active", "checkbox"),
            $this->setting_field(__('Hide WordPress Version Number', 'wpopt'), "dcl_security.nowpversion", "checkbox", ['parent' => 'dcl_security.active']),
            $this->setting_field(__('Remove WordPress Meta Generator Tag', 'wpopt'), "dcl_security.nowpgenerator", "checkbox", ['parent' => 'dcl_security.active']),
            $this->setting_field(__('Remove Version from Stylesheet', 'wpopt'), "dcl_security.nocssversion", "checkbox", ['parent' => 'dcl_security.active']),
            $this->setting_field(__('Remove Version from Script', 'wpopt'), "dcl_security.nojsversion", "checkbox", ['parent' => 'dcl_security.active']),

            $this->setting_field('', false, "divide"),
            $this->setting_field(__('Admin & API Security', 'wpopt'), "a_api.active", "checkbox"),
            $this->setting_field(__('Disable WP User Enumeration', 'wpopt'), "a_api.nousernum", "checkbox", ['parent' => 'a_api.active']),
            $this->setting_field(__('Disable WP File Editor', 'wpopt'), "a_api.disable_file_editor", "checkbox", ['parent' => 'a_api.active']),
            $this->setting_field(__('Disable XMLRPC', 'wpopt'), "a_api.disable_xml_rpc", "checkbox", ['parent' => 'a_api.active']),
            $this->setting_field(__('Disable WP API JSON', 'wpopt'), "a_api.disable_jsonapi", "checkbox", ['parent' => 'a_api.active'])
        );

    }
}

return __NAMESPACE__;