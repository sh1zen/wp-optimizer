<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C)  2022
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use SHZN\core\Disk;
use SHZN\modules\Module;

use WPOptimizer\modules\supporters\QueryCache;
use WPOptimizer\modules\supporters\StaticCache;

class Mod_Cache extends Module
{
    public static string $storage_internal = 'cache';

    public $scopes = array('settings', 'autoload');

    public function __construct()
    {
        require_once WPOPT_SUPPORTERS . 'cache/cache_dispatcher.class.php';
        require_once WPOPT_SUPPORTERS . 'cache/querycache.class.php';
        require_once WPOPT_SUPPORTERS . 'cache/staticcache.class.php';

        $default = array(
            'wp_query'     => array(
                'active'   => false,
                'lifespan' => "01:00:00"
            ),
            'static_pages' => array(
                'active'   => false,
                'lifespan' => "01:00:00"
            ),
            'wp_db'        => false,
        );

        parent::__construct('wpopt', array(
            'settings' => $default,
        ));

        $this->cache_flush_hooks();

        $this->loader();
    }

    private function cache_flush_hooks()
    {
        if ($this->option('wp_query.active') or $this->option('wp_db') or $this->option('static_pages.active')) {

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
            QueryCache::clear_cache();
        }

        if ($this->deactivating('static_pages.active', $input)) {
            StaticCache::clear_cache();
        }

        // database-cache
        if ($this->activating('wp_db', $input)) {
            Disk::write(
                WP_CONTENT_DIR . DIRECTORY_SEPARATOR . "db.php",
                "<?php" . PHP_EOL . PHP_EOL . "include_once('" . WPOPT_SUPPORTERS . "cache/db.php');"
            );
        }

        if ($this->deactivating('wp_db', $input) and class_exists('\WPOPT_DB')) {
            \WPOPT_DB::clear_cache();
            Disk::delete_files(WP_CONTENT_DIR . DIRECTORY_SEPARATOR . "db.php");
        }

        return parent::validate_settings($input, $valid);
    }

    protected function setting_fields($filter = '')
    {
        return $this->group_setting_fields(
            $this->setting_field(__('WP_Query Cache'), false, 'separator'),
            $this->setting_field(__('Active', 'wpopt'), "wp_query.active", "checkbox"),
            $this->setting_field(__('Lifespan', 'wpopt'), "wp_query.lifespan", "time", ['parent' => 'wp_query.active']),

            $this->setting_field(__('Database Query Cache'), false, 'separator'),
            $this->setting_field(__('Active', 'wpopt'), "wp_db", "checkbox"),

            $this->setting_field(__('Static Pages Cache'), false, 'separator'),
            $this->setting_field(__('Active', 'wpopt'), "static_pages.active", "checkbox"),
            $this->setting_field(__('Lifespan', 'wpopt'), "static_pages.lifespan", "time", ['parent' => 'static_pages.active'])
        );
    }

    protected function custom_actions()
    {
        return array(
            array(
                'before'       => "<b>" . __('Cache size', 'wpopt') . " : " . shzn('wpopt')->storage->get_size(self::$storage_internal) . "</b>",
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
            return $this->flush_cache();
        }

        return false;
    }

    public function flush_cache($blog_id = 0)
    {
        return shzn('wpopt')->storage->delete(self::$storage_internal, '', $blog_id);
    }
}

return __NAMESPACE__;