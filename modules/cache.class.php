<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use SHZN\modules\Module;

use WPOptimizer\modules\supporters\DBCache;
use WPOptimizer\modules\supporters\ObjectCache;
use WPOptimizer\modules\supporters\QueryCache;
use WPOptimizer\modules\supporters\StaticCache;

class Mod_Cache extends Module
{
    public static string $storage_internal = 'cache';

    public $scopes = array('settings', 'autoload');

    public function __construct()
    {
        require_once WPOPT_SUPPORTERS . 'cache/cache_dispatcher.class.php';

        require_once WPOPT_SUPPORTERS . 'cache/dbcache.class.php';
        require_once WPOPT_SUPPORTERS . 'cache/querycache.class.php';
        require_once WPOPT_SUPPORTERS . 'cache/staticcache.class.php';
        require_once WPOPT_SUPPORTERS . 'cache/objectcache.class.php';

        $default = array(
            'wp_query'     => array(
                'active'   => false,
                'lifespan' => "04:00:00"
            ),
            'object_cache' => array(
                'active'   => false,
                'lifespan' => "04:00:00"
            ),
            'static_pages' => array(
                'active'   => false,
                'lifespan' => "01:00:00"
            ),
            'wp_db.active' => array(
                'active'   => false,
                'lifespan' => "01:00:00"
            )
        );

        parent::__construct('wpopt', array(
            'settings' => $default,
        ));

        $this->cache_flush_hooks();

        $this->loader();
    }

    private function cache_flush_hooks()
    {
        if ($this->option('wp_query.active') or $this->option('wp_db.active') or $this->option('static_pages.active')) {

            add_action('clean_site_cache', array($this, 'flush_cache'), 10, 1); //blog_id
            add_action('clean_network_cache', array($this, 'flush_cache'), 10, 1); //network_id

            add_action('clean_post_cache', array($this, 'flush_cache'), 10, 1);
            add_action('clean_page_cache', array($this, 'flush_cache'), 10, 1);
            add_action('clean_attachment_cache', array($this, 'flush_cache'), 10, 1);
            add_action('clean_comment_cache', array($this, 'flush_cache'), 10, 1);

            add_action('clean_term_cache', array($this, 'flush_cache'), 10, 1);
            add_action('clean_object_term_cache', array($this, 'flush_cache'), 10, 1);
            add_action('clean_taxonomy_cache', array($this, 'flush_cache'), 10, 1);

            add_action('clean_user_cache', array($this, 'flush_cache'), 10, 1);
        }
    }

    private function loader()
    {
        if ($this->option('wp_query.active')) {
            QueryCache::Initialize(array(
                'lifespan' => shzn_timestr2seconds($this->option('wp_query.lifespan', '03:00:00'))
            ));
        }

        if ($this->option('static_pages.active')) {
            StaticCache::Initialize(array(
                'lifespan' => shzn_timestr2seconds($this->option('static_pages.lifespan', '03:00:00'))
            ));
        }
    }

    public function validate_settings($input, $valid)
    {
        if ($this->deactivating('wp_query.active', $input)) {
            QueryCache::deactivate();
        }

        if ($this->deactivating('static_pages.active', $input)) {
            StaticCache::deactivate();
        }

        if ($this->activating('object_cache.active', $input)) {
            ObjectCache::activate();
        }

        if ($this->deactivating('object_cache.active', $input)) {
            ObjectCache::deactivate();
        }

        if ($this->activating('wp_db.active', $input)) {
            DBCache::activate();
        }

        if ($this->deactivating('wp_db.active', $input) and class_exists('\WPOPT_DB')) {
            DBCache::deactivate();
        }

        return parent::validate_settings($input, $valid);
    }

    public function flush_cache()
    {
        if ($this->option('wp_query.active')) {
            QueryCache::clear_cache();
        }

        if ($this->option('static_pages.active')) {
            StaticCache::clear_cache();
        }

        if ($this->option('wp_db.active')) {
            DBCache::clear_cache();
        }

        return true;
    }

    protected function setting_fields($filter = '')
    {
        return $this->group_setting_fields(
            $this->setting_field(__('WP_Query Cache'), 'wp_query_cache', 'separator'),
            $this->setting_field(__('Active', 'wpopt'), "wp_query.active", "checkbox"),
            $this->setting_field(__('Lifespan', 'wpopt'), "wp_query.lifespan", "time", ['parent' => 'wp_query.active']),

            $this->setting_field(__('Database Query Cache'), 'db_query_cache', 'separator'),
            $this->setting_field(__('Active', 'wpopt'), "wp_db.active", "checkbox"),
            $this->setting_field(__('Lifespan', 'wpopt'), "wp_db.lifespan", "time", ['parent' => 'wp_db.active']),

            $this->setting_field(__('Object Cache'), 'object_cache', 'separator'),
            $this->setting_field(__('Active', 'wpopt'), "object_cache.active", "checkbox"),
            $this->setting_field(__('Lifespan', 'wpopt'), "object_cache.lifespan", "time", ['parent' => 'object_cache.active']),

            $this->setting_field(__('Static Pages Cache'), 'static_page_cache', 'separator'),
            $this->setting_field(__('Active', 'wpopt'), "static_pages.active", "checkbox"),
            $this->setting_field(__('Lifespan', 'wpopt'), "static_pages.lifespan", "time", ['parent' => 'static_pages.active'])
        );
    }

    protected function infos()
    {
        return [
            'wp_query_cache'    => __('Stores the results of WP_Query for faster retrieval on subsequent requests.', 'wpopt'),
            'db_query_cache'    => __('Stores frequently used database queries in memory to reduce the number of queries made to the database, improving website performance.', 'wpopt'),
            'object_cache'      => __('Stores PHP objects in memory for fast retrieval, reducing the need to recreate objects, improving website performance. Needs Redis or Memcached to be installed.', 'wpopt'),
            'static_page_cache' => __('Stores static HTML pages generated from dynamic content to avoid repeated processing.', 'wpopt'),
        ];
    }

    protected function custom_actions()
    {
        return array(
            array(
                'before'       => "<b style='margin-right: 1em'>" . __('Cache size', 'wpopt') . " : " . shzn('wpopt')->storage->get_size(self::$storage_internal) . "</b>",
                'id'           => 'reset_cache',
                'value'        => 'Reset Cache',
                'button_types' => 'button-danger',
                'context'      => 'action'
            )
        );
    }

    protected function process_custom_actions($action, $options)
    {
        if ($action === 'reset_cache') {

            QueryCache::clear_cache();
            StaticCache::clear_cache();
            DBCache::clear_cache();

            ObjectCache::clear_cache();
        }

        return false;
    }
}

return __NAMESPACE__;