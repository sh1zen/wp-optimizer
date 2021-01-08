<?php

class WOMod_Cache extends WOModule
{
    public static $storage_internal = 'cache';

    public $scopes = array('settings', 'autoload');

    public function __construct()
    {
        require_once __DIR__ . '/cache/cache_dispatcher.class.php';
        require_once __DIR__ . '/cache/query_cache.class.php';

        $default = array(
            'wp_query'  => array(
                'active'   => false,
                'lifespan' => "01:00:00"
            ),
            'wp_object' => array(
                'active'   => false,
                'lifespan' => "01:00:00"
            ),
            'wp_db'     => false,
        );

        parent::__construct(array(
            'settings' => $default
        ));

        $this->cache_flush_hooks();

        $this->loader();
    }

    private function cache_flush_hooks()
    {
        if ($this->option('wp_query.active') or $this->option('wp_db')) {

            add_action('clean_site_cache', array($this, 'flush_cache'), 10, 1); //blog_id
            add_action('clean_network_cache', array($this, 'flush_cache'), 10, 1); //network_id

            add_action('clean_post_cache', array($this, 'flush_cache'), 10, 0);
            add_action('clean_page_cache', array($this, 'flush_cache'), 10, 0);
            add_action('clean_attachment_cache', array($this, 'flush_cache'), 10, 0);
            add_action('clean_comment_cache', array($this, 'flush_cache'), 10, 0);

            add_action('clean_term_cache', array($this, 'flush_cache'), 10, 0);
            add_action('clean_object_term_cache', array($this, 'flush_cache'), 10, 0);
            add_action('clean_taxonomy_cache', array($this, 'flush_cache'), 10, 0);

            add_action('clean_user_cache', array($this, 'flush_cache'), 10, 0);
        }
    }

    private function loader()
    {
        $args = array(
            'lifespan' => wpopt_timestr2seconds($this->option('wp_query.lifespan', '03:00:00'))
        );

        if ($this->option('wp_query.active')) {
            WPOPT_Query_Cache::Initialize($args);
        }
    }

    public function validate_settings($input, $valid)
    {
        if ($this->deactivating('wp_query.active', $input)) {
            WPOPT_Query_Cache::clear_cache();
        }

        // object-cache
        if ($this->activating('wp_object.active', $input)) {
            file_put_contents(
                WP_CONTENT_DIR . DIRECTORY_SEPARATOR . "object-cache.php",
                "<?php" . PHP_EOL . PHP_EOL . "include_once('" . WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . "wp-optimizer/modules/cache/object-cache.php');"
            );
        }

        if ($this->deactivating('wp_object.active', $input)) {
            WPOPT_Object_Cache::clear_cache();
            WODisk::delete_files(WP_CONTENT_DIR . DIRECTORY_SEPARATOR . "object-cache.php");
        }

        // database-cache
        if ($this->activating('wp_db', $input)) {
            file_put_contents(
                WP_CONTENT_DIR . DIRECTORY_SEPARATOR . "db.php",
                "<?php" . PHP_EOL . PHP_EOL . "include_once('" . WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . "wp-optimizer/modules/cache/db.php');"
            );
        }

        if ($this->deactivating('wp_db', $input) and class_exists('WPOPT_DB')) {
            WPOPT_DB::clear_cache();
            WODisk::delete_files(WP_CONTENT_DIR . DIRECTORY_SEPARATOR . "db.php");
        }

        return parent::validate_settings($input, $valid);
    }

    protected function setting_fields()
    {
        return array(
            array('type' => 'checkbox', 'name' => __('Cache WP_Query', 'wpopt'), 'id' => 'wp_query.active', 'value' => $this->option('wp_query.active', false)),
            array('type' => 'time', 'parent' => 'wp_query.active', 'name' => __('Lifespan', 'wpopt'), 'id' => 'wp_query.lifespan', 'value' => $this->option('wp_query.lifespan', '')),
            array('type' => 'divide'),
            /*
            array('type' => 'checkbox', 'name' => __('Object Cache', 'wpopt'), 'id' => 'wp_object', 'value' => $this->option('wp_object.active', false)),
            array('type' => 'time', 'parent' => 'wp_object', 'name' => __('Lifespan', 'wpopt'), 'id' => 'wp_object_lifespan', 'value' => $this->option('wp_object.lifespan', '')),
            array('type' => 'divide'),
            */
            array('type' => 'checkbox', 'name' => __('Database Query Cache', 'wpopt'), 'id' => 'wp_db', 'value' => $this->option('wp_db', false)),
        );
    }

    protected function custom_actions()
    {
        return array(
            array(
                'before'       => "<b>" . __('Cache size', 'wpopt') . " : " . WOStorage::getInstance()->get_size(self::$storage_internal) . "</b>",
                'id'           => 'reset_cache',
                'value'        => 'Reset Cache',
                'button_types' => 'button-danger',
                'context'      => 'action'
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