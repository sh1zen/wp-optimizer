<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use WPS\core\RequestActions;
use WPS\core\CronActions;
use WPS\modules\Module;

use WPOptimizer\modules\supporters\DBCache;
use WPOptimizer\modules\supporters\ObjectCache;
use WPOptimizer\modules\supporters\QueryCache;
use WPOptimizer\modules\supporters\StaticCache;

class Mod_Cache extends Module
{
    public static ?string $name = 'Cache';

    public static string $storage_internal = 'cache';

    public array $scopes = array('settings', 'autoload');

    protected string $context = 'wpopt';

    public function validate_settings($input, $filtering = false): array
    {
        $new_valid = parent::validate_settings($input, $filtering);

        $this->load_dependencies();

        if ($this->deactivating('wp_query.active', $new_valid)) {
            QueryCache::deactivate();
        }

        if ($this->deactivating('static_pages.active', $new_valid)) {
            StaticCache::deactivate();
        }

        if ($this->activating('object_cache.active', $new_valid)) {
            ObjectCache::activate();
        }

        if ($this->deactivating('object_cache.active', $new_valid)) {
            ObjectCache::deactivate();
        }

        if ($this->activating('wp_db.active', $new_valid)) {
            DBCache::activate();
        }

        if ($this->deactivating('wp_db.active', $new_valid)) {
            DBCache::deactivate();
        }

        return $new_valid;
    }

    private function load_dependencies(): void
    {
        require_once WPOPT_SUPPORTERS . 'cache/cache_dispatcher.class.php';

        require_once WPOPT_SUPPORTERS . 'cache/dbcache.class.php';
        require_once WPOPT_SUPPORTERS . 'cache/querycache.class.php';
        require_once WPOPT_SUPPORTERS . 'cache/staticcache.class.php';
        require_once WPOPT_SUPPORTERS . 'cache/objectcache.class.php';
    }

    public function flush_cache_blog($blog_id): void
    {
        DBCache::flush(false, $blog_id);
        StaticCache::flush(false, $blog_id);
        QueryCache::flush(false, $blog_id);
    }

    public function flush_handler($arg = null): void
    {
        $this->flush_cache();
    }

    private function flush_cache($just_expired = false): void
    {
        if ($this->option('wp_query.active')) {
            QueryCache::flush($just_expired ? $this->option('wp_query.lifespan') : false);
        }

        if ($this->option('static_pages.active')) {
            StaticCache::flush($just_expired ? $this->option('static_pages.lifespan') : false);
        }

        if ($this->option('wp_db.active')) {
            DBCache::flush($just_expired ? WPOPT_CACHE_DB_LIFETIME : false);
        }
    }

    public function actions(): void
    {
        CronActions::schedule("WPOPT-ClearCache", HOUR_IN_SECONDS * 4, function () {

            /**
             * check old files every 4 Hours to prevent cache space explosion,
             * especially with static-cache
             */
            $this->flush_cache(true);
        }, '06:00');

        RequestActions::request($this->action_hook, function ($action) {

            $response = false;

            if ($action === 'reset_cache') {

                QueryCache::flush();
                StaticCache::flush();
                DBCache::flush();

                ObjectCache::flush();

                $response = true;
            }

            if ($response) {
                $this->add_notices('success', __('Action was correctly executed', $this->context));
            }
            else {
                $this->add_notices('warning', __('Action execution failed', $this->context));
            }
        });
    }

    protected function init(): void
    {
        $this->load_dependencies();

        $this->cache_flush_hooks();

        $this->loader();
    }

    private function cache_flush_hooks(): void
    {
        if ($this->option('wp_query.active') or $this->option('wp_db.active') or $this->option('static_pages.active')) {

            add_action('clean_site_cache', array($this, 'flush_cache_blog'), 10, 1); //blog_id
            add_action('clean_network_cache', array($this, 'flush_cache_blog'), 10, 1); //network_id

            add_action('clean_post_cache', array($this, 'flush_handler'), 10, 1);
            add_action('clean_page_cache', array($this, 'flush_handler'), 10, 1);
            add_action('clean_attachment_cache', array($this, 'flush_handler'), 10, 1);
            add_action('clean_comment_cache', array($this, 'flush_handler'), 10, 1);

            add_action('clean_term_cache', array($this, 'flush_handler'), 10, 1);
            add_action('clean_object_term_cache', array($this, 'flush_handler'), 10, 1);
            add_action('clean_taxonomy_cache', array($this, 'flush_handler'), 10, 1);

            add_action('clean_user_cache', array($this, 'flush_handler'), 10, 1);
        }
    }

    private function loader(): void
    {
        if ($this->option('wp_query.active')) {
            QueryCache::getInstance($this->option('wp_query.lifespan', '04:00:00'), $this->option('wp_query'));
        }

        if ($this->option('static_pages.active')) {
            StaticCache::getInstance($this->option('static_pages.lifespan', '04:00:00'), $this->option('static_pages'));
        }
    }

    protected function setting_fields($filter = ''): array
    {
        return $this->group_setting_fields(
            $this->group_setting_fields(
                $this->setting_field(__('WP_Query Cache'), 'wp_query_cache', 'separator'),
                $this->setting_field(__('Active', 'wpopt'), "wp_query.active", "checkbox"),
                $this->setting_field(__('Lifespan', 'wpopt'), "wp_query.lifespan", "time", ['parent' => 'wp_query.active']),
            ),
            $this->group_setting_fields(
                $this->setting_field(__('Database Query Cache'), 'db_query_cache', 'separator'),
                $this->setting_field(__('Active', 'wpopt'), "wp_db.active", "checkbox"),
            ),
            $this->group_setting_fields(
                $this->setting_field(__('Object Cache'), 'object_cache', 'separator'),
                $this->setting_field(__('Active', 'wpopt'), "object_cache.active", "checkbox"),
            ),
            $this->group_setting_fields(
                $this->setting_field(__('Static Pages Cache'), 'static_page_cache', 'separator'),
                $this->setting_field(__('Active', 'wpopt'), "static_pages.active", "checkbox"),
                $this->setting_field(__('Lifespan', 'wpopt'), "static_pages.lifespan", "time", ['parent' => 'static_pages.active']),
                $this->setting_field(__('Excluded pages (one per line)', 'wpopt'), "static_pages.excluded", "textarea_array", ['parent' => 'static_pages.active', 'value' => implode("\n", $this->option('static_pages.excluded', []))]),
            )
        );
    }

    protected function infos(): array
    {
        return [
            'wp_query_cache'        => __('Stores the results of WP_Query for faster retrieval on subsequent requests.', 'wpopt'),
            'db_query_cache'        => __('Stores frequently used database queries in memory to reduce the number of queries made to the database, improving website performance.', 'wpopt') . '<br/>' .
                __('To set custom cached content lifetime paste "define(\'WPOPT_CACHE_DB_LIFETIME\', put-here-your-desired-lifetime)" in wp.config, default 3600.', 'wpopt') . '<br/>' .
                __('To enable caching also for option table paste "define(\'WPOPT_CACHE_DB_OPTIONS\', true)" in wp.config.', 'wpopt'),
            'object_cache'          => __('Stores PHP objects in memory for fast retrieval, reducing the need to recreate objects, improving website performance. Needs Redis or Memcached to be installed.', 'wpopt'),
            'static_page_cache'     => __('Stores static HTML pages generated from dynamic content to avoid repeated processing.', 'wpopt'),
            'static_pages.excluded' => __('A list of regular expressions or page names to exclude (one per line).', 'wpopt')
        ];
    }

    protected function print_footer(): string
    {
        ob_start();
        ?>
        <form method="POST" autocapitalize="off" autocomplete="off">

            <?php RequestActions::nonce_field($this->action_hook); ?>

            <block class="wps-gridRow">
                <row class="wps-custom-action wps-row">
                    <b style='margin-right: 1em'>
                        <?php _e('Cache size', 'wpopt') ?> : <?php echo wps('wpopt')->storage->get_size(self::$storage_internal) ?>
                    </b>
                    <?php echo RequestActions::get_action_button($this->action_hook, 'reset_cache', __('Reset Cache', 'wpopt')); ?>
                </row>
            </block>
        </form>
        <?php
        return ob_get_clean();
    }
}

return __NAMESPACE__;