<?php

/**
 * Module for updates handling
 */
class WOMod_WP_Optimizer extends WOModule
{
    public $scopes = array('settings', 'autoload');

    private $server_conf_hooks;

    public function __construct()
    {
        $this->server_conf_hooks = array(
            'srv_security'      => array(
                'active' => false,
            ),
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

        parent::__construct(
            array(
                'settings' => array_merge(
                    $defaults,
                    $this->server_conf_hooks
                ),
            )
        );

        if (is_admin()) {
            if (!is_writable(ABSPATH . '.htaccess')) {
                $this->add_notices('error', sprintf(__("<b><i>'%s'</i> is not writable.</b><br>Modify (<b>run chmod 777</b>) it's group permission to allow WP-Optimizer to make changes automatically.", 'wpopt'), ABSPATH . '.htaccess'));
            }
        }
    }

    public function validate_settings($input, $valid)
    {
        require_once __DIR__ . '/optimizer/wo_optimizer.class.php';

        $new_valid = parent::validate_settings($input, $valid);

        foreach ($this->server_conf_hooks as $server_hooks => $value) {

            if ($this->deactivating("{$server_hooks}.active", $input)) {
                WO_Optimizer::server_conf($server_hooks, 'remove');
            }
            elseif (WOSettings::get_option($new_valid, "{$server_hooks}.active")) {
                // do also if not activating to ensure children settings changes are performed
                WO_Optimizer::server_conf($server_hooks, 'add', $new_valid);
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

                require_once __DIR__ . '/optimizer/wo_optimizer.class.php';

                $virtual_page = WO_Optimizer::server_conf('', 'get');

                $htaccess_init_len = strlen($virtual_page);

                foreach ($this->server_conf_hooks as $server_hooks => $value) {

                    if ($this->option($server_hooks)) {

                        WO_Optimizer::server_conf($server_hooks, 'add', $this->option(), $virtual_page);
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
            array('type' => 'separator', 'name' => __('Server configuration (Up to now, apache only)', 'wpopt')),
            array('type' => 'checkbox', 'name' => __('Security Fixes', 'wpopt'), 'id' => 'srv_security.active', 'value' => $this->option('srv_security.active')),
            array('type' => 'checkbox', 'parent' => 'srv_security.active', 'name' => __('Disable directory listing', 'wpopt'), 'id' => 'srv_security.listings', 'value' => $this->option('srv_security.listings', true)),
            array('type' => 'checkbox', 'parent' => 'srv_security.active', 'name' => __('Disable access to configuration file', 'wpopt'), 'id' => 'srv_security.protect_htaccess', 'value' => $this->option('srv_security.protect_htaccess', true)),
            array('type' => 'checkbox', 'parent' => 'srv_security.active', 'name' => __('Enable HTTP Strict Transport Security', 'wpopt'), 'id' => 'srv_security.hsts', 'value' => $this->option('srv_security.hsts', true)),
            array('type' => 'checkbox', 'parent' => 'srv_security.active', 'name' => __('Disable Cross-Origin Resource Sharing', 'wpopt'), 'id' => 'srv_security.cors', 'value' => $this->option('srv_security.cors', true)),
            array('type' => 'checkbox', 'parent' => 'srv_security.active', 'name' => __('Disable HTTP Track & Trace', 'wpopt'), 'id' => 'srv_security.http_track&trace', 'value' => $this->option('srv_security.http_track&trace', true)),
            array('type' => 'checkbox', 'parent' => 'srv_security.active', 'name' => __('Block Cross Site Scripting', 'wpopt'), 'id' => 'srv_security.xss', 'value' => $this->option('srv_security.xss', true)),
            array('type' => 'checkbox', 'parent' => 'srv_security.active', 'name' => __('Send No Sniff Header', 'wpopt'), 'id' => 'srv_security.nosniff', 'value' => $this->option('srv_security.nosniff', true)),
            array('type' => 'checkbox', 'parent' => 'srv_security.active', 'name' => __('Send No Referrer Header', 'wpopt'), 'id' => 'srv_security.noreferrer', 'value' => $this->option('srv_security.noreferrer')),
            array('type' => 'checkbox', 'parent' => 'srv_security.active', 'name' => __('Send No Frame Header', 'wpopt'), 'id' => 'srv_security.noframe', 'value' => $this->option('srv_security.noframe')),

            array('type' => 'divide'),
            array('type' => 'checkbox', 'name' => __('Server Enhancements', 'wpopt'), 'id' => 'srv_enhancements.active', 'value' => $this->option('srv_enhancements.active')),
            array('type' => 'checkbox', 'parent' => 'srv_enhancements.active', 'name' => __('Remove www', 'wpopt'), 'id' => 'srv_enhancements.remove_www', 'value' => $this->option('srv_enhancements.remove_www')),
            array('type' => 'checkbox', 'parent' => 'srv_enhancements.active', 'name' => __('Redirect HTTP to HTTPS', 'wpopt'), 'id' => 'srv_enhancements.redirect_https', 'value' => $this->option('srv_enhancements.redirect_https')),
            array('type' => 'checkbox', 'parent' => 'srv_enhancements.active', 'name' => __('Connection keep alive', 'wpopt'), 'id' => 'srv_enhancements.keep_alive', 'value' => $this->option('srv_enhancements.keep_alive')),
            array('type' => 'checkbox', 'parent' => 'srv_enhancements.active', 'name' => __('Follow symlinks', 'wpopt'), 'id' => 'srv_enhancements.follow_symlinks', 'value' => $this->option('srv_enhancements.follow_symlinks')),
            array('type' => 'checkbox', 'parent' => 'srv_enhancements.active', 'name' => __('Timezone', 'wpopt'), 'id' => 'srv_enhancements.timezone', 'value' => $this->option('srv_enhancements.timezone')),
            array('type' => 'checkbox', 'parent' => 'srv_enhancements.active', 'name' => __('Default Charset UTF-8', 'wpopt'), 'id' => 'srv_enhancements.default_utf8', 'value' => $this->option('srv_enhancements.default_utf8')),
            array('type' => 'checkbox', 'parent' => 'srv_enhancements.active', 'name' => __('Enable PageSpeed if installed', 'wpopt'), 'id' => 'srv_enhancements.pagespeed', 'value' => $this->option('srv_enhancements.pagespeed')),

            array('type' => 'divide'),
            array('type' => 'checkbox', 'name' => __('Enable server Compression', 'wpopt'), 'id' => 'srv_compression.active', 'value' => $this->option('srv_compression.active')),
            array('type' => 'checkbox', 'parent' => 'srv_compression.active', 'name' => __('GZIP', 'wpopt'), 'id' => 'srv_compression.gzip', 'value' => $this->option('srv_compression.gzip')),
            array('type' => 'checkbox', 'parent' => 'srv_compression.active', 'name' => __('BROTLI', 'wpopt'), 'id' => 'srv_compression.brotli', 'value' => $this->option('srv_compression.brotli', true)),

            array('type' => 'divide'),
            array('type' => 'checkbox', 'name' => __('Enable browser cache', 'wpopt'), 'id' => 'srv_browser_cache.active', 'value' => $this->option('srv_browser_cache.active')),
            array('type' => 'checkbox', 'parent' => 'srv_browser_cache.active', 'name' => __('Use Cache Control Headers', 'wpopt'), 'id' => 'srv_browser_cache.cache_control', 'value' => $this->option('srv_browser_cache.cache_control', true)),
            array('type' => 'numeric', 'parent' => 'srv_browser_cache.active', 'name' => __('Default lifetime', 'wpopt'), 'id' => 'srv_browser_cache.lifetime_default', 'value' => $this->option('srv_browser_cache.lifetime_default', MONTH_IN_SECONDS)),
            array('type' => 'numeric', 'parent' => 'srv_browser_cache.active', 'name' => __('CSS & JavaScripts lifetime', 'wpopt'), 'id' => 'srv_browser_cache.lifetime_text', 'value' => $this->option('srv_browser_cache.lifetime_text', MONTH_IN_SECONDS)),
            array('type' => 'numeric', 'parent' => 'srv_browser_cache.active', 'name' => __('Images lifetime', 'wpopt'), 'id' => 'srv_browser_cache.lifetime_image', 'value' => $this->option('srv_browser_cache.lifetime_image', MONTH_IN_SECONDS)),
            array('type' => 'numeric', 'parent' => 'srv_browser_cache.active', 'name' => __('Fonts lifetime', 'wpopt'), 'id' => 'srv_browser_cache.lifetime_font', 'value' => $this->option('srv_browser_cache.lifetime_font', YEAR_IN_SECONDS)),
            array('type' => 'numeric', 'parent' => 'srv_browser_cache.active', 'name' => __('Archives lifetime', 'wpopt'), 'id' => 'srv_browser_cache.lifetime_archive', 'value' => $this->option('srv_browser_cache.lifetime_archive', DAY_IN_SECONDS)),
        );
    }
}