<?php

define('WPOPT_CACHE_PATH', __DIR__);

class WOMod_Cache extends WO_Module
{
    public $scopes = array('settings', 'autoload');

    public function __construct()
    {
        $default = array(
            'wp_query_posts' => true
        );

        parent::__construct(
            array(
                'settings' => $default
            )
        );

        if (!(is_admin() or wp_doing_ajax() or wp_doing_cron())) {
            $this->loader();
        }
    }

    private function loader()
    {
        if (WOSettings::check($this->settings, 'wp_query_posts')) {
            require_once __DIR__ . '/cache/postcache.class.php';
        }
    }

    public function setting_fields()
    {
        return array(
            array('type' => 'checkbox', 'name' => 'Cache WP_Query posts', 'id' => 'wp_query_posts', 'value' => WOSettings::check($this->settings, 'wp_query_posts')),
        );
    }

    public function get_setting_content($context)
    {
        if ($context === 'footer')
            return $this->footer_options();

        return '';
    }

    private function footer_options()
    {
        $options = array(
            array('name' => 'reset cache', 'value' => 'reset', 'button_types' => 'button-danger')
        );

        return $this->custom_options_form($options);
    }

    protected function process_custom_options($options)
    {
        if (isset($options['reset_cache'])) {
            WOStorage::getInstance()->remove("WpQuery_postcache");
        }
    }


}