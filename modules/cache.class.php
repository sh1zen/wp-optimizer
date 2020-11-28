<?php

class WOMod_Cache extends WO_Module
{
    public $scopes = array('settings', 'autoload');

    public function __construct()
    {
        $default = array(
            'wp_query_posts'   => true,
            'storage_lifespan' => "00:15:00"
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

            WPOPT_PostCache::Initialize(array(
                'lifespan' => wpopt_timestr2seconds($this->settings['storage_lifespan'])
            ));
        }
    }

    public function setting_fields()
    {
        return array(
            array('type' => 'checkbox', 'name' => 'Cache WP_Query posts', 'id' => 'wp_query_posts', 'value' => WOSettings::check($this->settings, 'wp_query_posts')),
            array('type' => 'time', 'name' => 'Cache Lifespan', 'id' => 'storage_lifespan', 'value' => $this->settings['storage_lifespan']),
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
            array(
                'before'       => "<b>" . WOStorage::getInstance()->get_size('WpQuery_postcache') . "</b>",
                'name'         => 'reset cache',
                'value'        => 'Reset Cache',
                'button_types' => 'button-danger'
            )
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