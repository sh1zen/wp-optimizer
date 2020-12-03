<?php

class WOMod_Cache extends WO_Module
{
    public static $storage_internal = 'cache';

    public $scopes = array('settings', 'autoload');

    public function __construct()
    {
        require_once __DIR__ . '/cache/cache_dispatcher.class.php';
        require_once __DIR__ . '/cache/WPQuery_Cache.class.php';

        $default = array(
            'wp_query'         => false,
            'wp_db_cache'      => false,
            'wp_object_cache'  => false,
            'storage_lifespan' => "01:00:00"
        );

        parent::__construct(array(
            'settings' => $default
        ));

        $this->check_status();

        $this->loader();
    }

    private function check_status()
    {
        WPQuery_Cache::check_status();

        add_action('clean_site_cache', array($this, 'flush_cache'), 10, 1); //blog_id
        add_action('clean_network_cache', array($this, 'flush_cache'), 10, 1); //network_id
    }

    private function loader()
    {
        $args = array(
            'lifespan' => wpopt_timestr2seconds($this->settings['storage_lifespan'])
        );

        if (WOSettings::check($this->settings, 'wp_query')) {

            WPQuery_Cache::Initialize($args);
        }
    }

    public function validate_settings($input, $valid)
    {
        if ($this->deactivating('wp_query', $input)) {
            WPQuery_Cache::clear_cache();
        }

        if ($this->deactivating('wp_object_cache', $input)) {
            WPOPT_Object_Cache::clear_cache();
            wpopt_delete_files(WP_CONTENT_DIR . DIRECTORY_SEPARATOR . "object-cache.php");
        }

        if ($this->deactivating('wp_db_cache', $input)) {
            WPOPT_DB::clear_cache();
            wpopt_delete_files(WP_CONTENT_DIR . DIRECTORY_SEPARATOR . "db.php");
        }

        if ($this->activating('wp_object_cache', $input)) {
            file_put_contents(
                WP_CONTENT_DIR . DIRECTORY_SEPARATOR . "object-cache.php",
                "<?php" . PHP_EOL . PHP_EOL . "include_once('" . WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . "wp-optimizer/modules/cache/object-cache.php');"
            );
        }

        if ($this->activating('wp_db_cache', $input)) {
            file_put_contents(
                WP_CONTENT_DIR . DIRECTORY_SEPARATOR . "db.php",
                "<?php" . PHP_EOL . PHP_EOL . "include_once('" . WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . "wp-optimizer/modules/cache/db.php');"
            );
        }

        $valid['wp_query'] = isset($input['wp_query']);
        $valid['wp_db_cache'] = isset($input['wp_db_cache']);
        $valid['wp_object_cache'] = isset($input['wp_object_cache']);
        $valid['storage_lifespan'] = sanitize_text_field($input['storage_lifespan']);

        return $valid;
    }

    protected function setting_fields()
    {
        return array(
            array('type' => 'checkbox', 'name' => __('Cache WP_Query', 'wpopt'), 'id' => 'wp_query', 'value' => WOSettings::check($this->settings, 'wp_query')),
            //array('type' => 'checkbox', 'name' => __('Object Cache', 'wpopt'), 'id' => 'wp_object_cache', 'value' => WOSettings::check($this->settings, 'wp_object_cache')),
            array('type' => 'checkbox', 'name' => __('Database Query Cache', 'wpopt'), 'id' => 'wp_db_cache', 'value' => WOSettings::check($this->settings, 'wp_db_cache')),
            array('type' => 'time', 'name' => __('Cache Lifespan', 'wpopt'), 'id' => 'storage_lifespan', 'value' => $this->settings['storage_lifespan']),
        );
    }

    protected function custom_actions()
    {
        return array(
            array(
                'before'       => "<b>" . WOStorage::getInstance()->get_size(self::$storage_internal) . "</b>",
                'name'         => 'reset_cache',
                'value'        => 'Reset Cache',
                'button_types' => 'button-danger'
            )
        );
    }

    protected function process_custom_actions($action, $options)
    {
        switch ($action) {

            case 'reset_cache':
                return $this->flush_cache();
        }

        return false;
    }

    public function flush_cache($blog_id = 0)
    {
        return WOStorage::getInstance()->delete(self::$storage_internal, '', $blog_id);
    }
}