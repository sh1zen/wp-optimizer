<?php

namespace WPOptimizer\modules;

use WPOptimizer\core\Settings;
use WPOptimizer\modules\supporters\WP_OptiSec;

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

        parent::__construct(
            array(
                'settings' => $this->server_conf_hooks
            )
        );

        if (is_admin()) {
            if (!is_writable(ABSPATH . '.htaccess')) {
                $this->add_notices('error', sprintf(__("<b><i>'%s'</i> is not writable.</b><br>Modify (<b>run chmod 777</b>) it's group permission to allow WP-Security to make changes automatically.", 'wpopt'), ABSPATH . '.htaccess'));
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

                if(function_exists('remove_yoast_seo_comments_fn'))
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
        require_once WPOPT_SUPPORTERS . 'optisec/WP_OptiSec.class.php';

        $new_valid = parent::validate_settings($input, $valid);

        foreach ($this->server_conf_hooks as $server_hooks => $value) {

            if ($this->deactivating("{$server_hooks}.active", $input)) {
               WP_OptiSec::server_conf($server_hooks, 'remove');
            }
            elseif (Settings::get_option($new_valid, "{$server_hooks}.active")) {
                // do also if not activating to ensure children settings changes are performed
                WP_OptiSec::server_conf($server_hooks, 'add', $new_valid);
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

                require_once WPOPT_SUPPORTERS . 'optisec/WP_OptiSec.class.php';

                $virtual_page = WP_OptiSec::server_conf('', 'get');

                $htaccess_init_len = strlen($virtual_page);

                foreach ($this->server_conf_hooks as $server_hooks => $value) {

                    if ($this->option($server_hooks)) {

                        WP_OptiSec::server_conf($server_hooks, 'add', $this->option(), $virtual_page);
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

    protected function setting_fields()
    {
        return array(
            array('type' => 'separator', 'name' => __('Some configuration are available only in apache.', 'wpopt')),
            array('type' => 'checkbox', 'name' => __('Requests and Server', 'wpopt'), 'id' => 'srv_security.active', 'value' => $this->option('srv_security.active')),
            array('type' => 'checkbox', 'parent' => 'srv_security.active', 'name' => __('Disable directory listing', 'wpopt'), 'id' => 'srv_security.listings', 'value' => $this->option('srv_security.listings', true)),
            array('type' => 'checkbox', 'parent' => 'srv_security.active', 'name' => __('Disable access to configuration file', 'wpopt'), 'id' => 'srv_security.protect_htaccess', 'value' => $this->option('srv_security.protect_htaccess', true)),
            array('type' => 'checkbox', 'parent' => 'srv_security.active', 'name' => __('Enable HTTPS Strict Transport Security', 'wpopt'), 'id' => 'srv_security.hsts', 'value' => $this->option('srv_security.hsts', true)),
            array('type' => 'checkbox', 'parent' => 'srv_security.active', 'name' => __('Disable Cross-Origin Resource Sharing', 'wpopt'), 'id' => 'srv_security.cors', 'value' => $this->option('srv_security.cors', true)),
            array('type' => 'checkbox', 'parent' => 'srv_security.active', 'name' => __('Disable HTTP Track & Trace', 'wpopt'), 'id' => 'srv_security.http_track&trace', 'value' => $this->option('srv_security.http_track&trace', true)),
            array('type' => 'checkbox', 'parent' => 'srv_security.active', 'name' => __('Block Cross Site Scripting', 'wpopt'), 'id' => 'srv_security.xss', 'value' => $this->option('srv_security.xss', true)),
            array('type' => 'checkbox', 'parent' => 'srv_security.active', 'name' => __('Send No Sniff Header', 'wpopt'), 'id' => 'srv_security.nosniff', 'value' => $this->option('srv_security.nosniff', true)),
            array('type' => 'checkbox', 'parent' => 'srv_security.active', 'name' => __('Send No Referrer Header', 'wpopt'), 'id' => 'srv_security.noreferrer', 'value' => $this->option('srv_security.noreferrer')),
            array('type' => 'checkbox', 'parent' => 'srv_security.active', 'name' => __('Send No Frame Header', 'wpopt'), 'id' => 'srv_security.noframe', 'value' => $this->option('srv_security.noframe')),

            array('type' => 'divide'),
            array('type' => 'checkbox', 'name' => __('Disable Information Disclosure & Remove Meta information', 'wpopt'), 'id' => 'dcl_security.active', 'value' => $this->option('dcl_security.active')),
            array('type' => 'checkbox', 'parent' => 'dcl_security.active', 'name' => __('Hide WordPress Version Number', 'wpopt'), 'id' => 'dcl_security.nowpversion', 'value' => $this->option('dcl_security.nowpversion')),
            array('type' => 'checkbox', 'parent' => 'dcl_security.active', 'name' => __('Remove WordPress Meta Generator Tag', 'wpopt'), 'id' => 'dcl_security.nowpgenerator', 'value' => $this->option('dcl_security.nowpgenerator')),
            array('type' => 'checkbox', 'parent' => 'dcl_security.active', 'name' => __('Remove Version from Stylesheet', 'wpopt'), 'id' => 'dcl_security.nocssversion', 'value' => $this->option('dcl_security.nocssversion')),
            array('type' => 'checkbox', 'parent' => 'dcl_security.active', 'name' => __('Remove Version from Script', 'wpopt'), 'id' => 'dcl_security.nojsversion', 'value' => $this->option('dcl_security.nojsversion')),

            array('type' => 'divide'),
            array('type' => 'checkbox', 'name' => __('Admin & API Security', 'wpopt'), 'id' => 'a_api.active', 'value' => $this->option('a_api.active')),
            array('type' => 'checkbox', 'parent' => 'a_api.active', 'name' => __('Disable WP User Enumeration', 'wpopt'), 'id' => 'security.nousernum', 'value' => $this->option('security.nousernum')),
            array('type' => 'checkbox', 'parent' => 'a_api.active', 'name' => __('Disable WP File Editor', 'wpopt'), 'id' => 'security.disable_file_editor', 'value' => $this->option('security.disable_file_editor')),
            array('type' => 'checkbox', 'parent' => 'a_api.active', 'name' => __('Disable XMLRPC', 'wpopt'), 'id' => 'security.disable_xml_rpc', 'value' => $this->option('security.disable_xml_rpc')),
            array('type' => 'checkbox', 'parent' => 'a_api.active', 'name' => __('Disable WP API JSON', 'wpopt'), 'id' => 'security.disable_jsonapi', 'value' => $this->option('security.disable_jsonapi')),
        );
    }
}