<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use WPS\core\Ajax;
use WPS\core\Disk;
use WPS\core\List_Table;
use WPS\core\RequestActions;
use WPS\core\CronActions;
use WPS\core\Settings;
use WPS\core\UtilEnv;
use WPS\modules\Module;

use WPOptimizer\modules\supporters\DBCache;
use WPOptimizer\modules\supporters\ObjectCache;
use WPOptimizer\modules\supporters\QueryCache;
use WPOptimizer\modules\supporters\StaticCache;
use WPOptimizer\modules\supporters\StaticCacheDirectAccess;
use WPOptimizer\modules\supporters\StaticCacheRules;
use WPOptimizer\modules\supporters\WP_Htaccess;

class Mod_Cache extends Module
{
    public static ?string $name = 'Cache';

    public static string $storage_internal = 'cache';

    public array $scopes = array('settings', 'autoload', 'ajax');

    protected string $context = 'wpopt';

    private bool $dependencies_loaded = false;
    private array $cache_auto_purge_suspensions = array();
    private array $cache_auto_purge_dirty = array();
    private array $cache_runtime_suspensions = array();
    private bool $cache_trash_cleanup_registered = false;

    private const DEFAULT_STATIC_LIFESPAN = '04:00';
    private const DEFAULT_DYNAMIC_LIFESPAN = '01:00';
    private const DEFAULT_STATIC_USER_SCOPE = 'not_logged_in';
    private const STATIC_USER_SCOPES = array('both', 'logged_in', 'not_logged_in');
    private const DEFAULT_STATIC_STATUS_CACHE_POLICY = array('2xx', '4xx', '5xx');
    private const STATIC_STATUS_CACHE_GROUPS = array('2xx', '3xx', '4xx', '5xx');
    private const DEFAULT_LAYER_DISABLE_ADMIN_CACHE = true;
    private const DYNAMIC_CACHE_LAYERS = array('wp_query', 'wp_db');
    private const CACHE_LAYERS = array('static_pages', 'wp_query', 'wp_db', 'object_cache');
    private const CACHE_RUNTIME_SUSPEND_LAYERS = array('wp_query', 'wp_db', 'object_cache');
    private const CACHE_TRASH_CLEANUP_HOOK = 'WPOPT-CacheTrashCleanup';
    private const CACHE_TRASH_CLEANUP_BATCH_LIMIT = 300;
    private const CACHE_TRASH_CLEANUP_TIME_BUDGET = 2.0;
    private const WP_QUERY_CACHE_TYPES = array(
        'post',
        'page',
        'attachment',
        'custom_post_type',
        'taxonomy_query',
        'meta_query',
        'search_query',
        'author_query',
        'date_query',
        'id_query',
        'feed_query',
    );
    private const CACHE_LAYER_BOOLEAN_DEFAULTS = array(
        'cache_include_rules_only'      => false,
        'cache_query_args'              => false,
        'auto_purge_content'            => true,
        'user_agent_exclusions_enabled' => false,
        'no_cache_cookies_enabled'      => true,
        'disable_admin_cache'           => self::DEFAULT_LAYER_DISABLE_ADMIN_CACHE,
    );
    private const STATIC_BOOLEAN_DEFAULTS = array(
        'cache_include_rules_only'      => false,
        'cache_query_args'              => false,
        'auto_purge_content'            => true,
        'user_agent_exclusions_enabled' => false,
        'no_cache_cookies_enabled'      => true,
        'direct_access_enabled'         => false,
        'disable_admin_cache'           => self::DEFAULT_LAYER_DISABLE_ADMIN_CACHE,
    );

    public function validate_settings($input, $filtering = false): array
    {
        $new_valid = parent::validate_settings($input, $filtering);

        $this->load_dependencies();

        $static_options_changed = false;
        $new_valid['static_pages'] = $this->validate_static_page_settings(
            (array)($new_valid['static_pages'] ?? array()),
            $input,
            (bool)$filtering,
            $static_options_changed
        );

        $dynamic_options_changed = array();
        foreach (self::DYNAMIC_CACHE_LAYERS as $layer) {
            $layer_options_changed = false;
            $new_valid[$layer] = $this->validate_cache_layer_settings(
                $layer,
                (array)($new_valid[$layer] ?? array()),
                $input,
                (bool)$filtering,
                $layer_options_changed
            );

            if ($layer_options_changed) {
                $dynamic_options_changed[$layer] = true;
            }
        }

        if (!$filtering && isset($new_valid['static_pages'])) {
            $new_valid['static_pages']['direct_access_enabled'] = $this->sync_static_direct_access((array)$new_valid['static_pages']);
        }

        $wp_query_deactivating = $this->deactivating('wp_query.active', $new_valid);
        $wp_db_deactivating = $this->deactivating('wp_db.active', $new_valid);
        $static_pages_deactivating = $this->deactivating('static_pages.active', $new_valid);

        if ($static_options_changed && !$static_pages_deactivating) {
            $this->flush_single_cache_layer('static_pages');
        }

        if (!empty($dynamic_options_changed['wp_query']) && !$wp_query_deactivating) {
            $this->flush_single_cache_layer('wp_query');
        }

        if (!empty($dynamic_options_changed['wp_db']) && !$wp_db_deactivating) {
            $this->flush_single_cache_layer('wp_db');
        }

        if ($wp_query_deactivating) {
            $this->flush_single_cache_layer('wp_query');
        }

        if ($static_pages_deactivating) {
            $this->deactivate_static_cache_layer();
        }

        if ($this->activating('object_cache.active', $new_valid)) {
            ObjectCache::activate($this->layer_admin_cache_disabled_from_settings($new_valid, 'object_cache'));
        }

        if ($this->deactivating('object_cache.active', $new_valid)) {
            ObjectCache::deactivate();
        }

        if (($this->activating('wp_db.active', $new_valid) || !empty($dynamic_options_changed['wp_db'])) && !empty($new_valid['wp_db']['active'])) {
            DBCache::activate($this->layer_options_from_settings($new_valid, 'wp_db'));
        }

        if ($wp_db_deactivating) {
            Disk::delete(WP_CONTENT_DIR . DIRECTORY_SEPARATOR . "db.php");
            $this->flush_single_cache_layer('wp_db');
        }

        return $new_valid;
    }

    private function validate_static_page_settings(array $static_settings, $input, bool $filtering, bool &$options_changed): array
    {
        $input = (array)$input;

        if (!$this->static_input_exists($input, $filtering, 'active')) {
            if ($this->static_configuration_form_submitted($input, $filtering) || !array_key_exists('active', $static_settings)) {
                $static_settings['active'] = (bool)$this->option('static_pages.active', false);
            }
        }

        $static_settings['rules'] = StaticCacheRules::normalize_rules($this->get_static_page_rules());

        $current_lifespan = $this->normalize_static_lifespan($this->option('static_pages.lifespan', self::DEFAULT_STATIC_LIFESPAN), self::DEFAULT_STATIC_LIFESPAN);
        $new_lifespan = $this->normalize_static_lifespan($this->read_static_input($input, $filtering, 'lifespan', $current_lifespan), self::DEFAULT_STATIC_LIFESPAN);
        $static_settings['lifespan'] = $new_lifespan;
        $options_changed = $this->static_input_changed($input, $filtering, 'lifespan', $current_lifespan, $new_lifespan) || $options_changed;

        foreach (self::STATIC_BOOLEAN_DEFAULTS as $option_key => $default_value) {
            $current_value = (bool)$this->option("static_pages.{$option_key}", $default_value);
            $new_value = (bool)$this->read_static_input($input, $filtering, $option_key, $current_value);
            $static_settings[$option_key] = $new_value;

            if ($option_key !== 'auto_purge_content') {
                $options_changed = $this->static_input_changed($input, $filtering, $option_key, $current_value, $new_value) || $options_changed;
            }
        }

        $current_user_scope = $this->normalize_static_user_scope($this->option('static_pages.user_scope', self::DEFAULT_STATIC_USER_SCOPE));
        $new_user_scope = $this->normalize_static_user_scope($this->read_static_input($input, $filtering, 'user_scope', $current_user_scope));
        $static_settings['user_scope'] = $new_user_scope;
        $options_changed = $this->static_input_changed($input, $filtering, 'user_scope', $current_user_scope, $new_user_scope) || $options_changed;

        $current_user_agent_exclusions = $this->option('static_pages.user_agent_exclusions', $this->default_static_user_agent_exclusions());
        $static_settings['user_agent_exclusions'] = $this->normalize_static_user_agent_exclusions(
            $this->read_static_input($input, $filtering, 'user_agent_exclusions', $current_user_agent_exclusions)
        );

        $current_no_cache_cookies = $this->option('static_pages.no_cache_cookies', array());
        $new_no_cache_cookies = $this->normalize_static_no_cache_cookies(
            $this->read_static_input($input, $filtering, 'no_cache_cookies', $current_no_cache_cookies)
        );
        $static_settings['no_cache_cookies'] = $new_no_cache_cookies;
        $options_changed = $this->static_input_changed($input, $filtering, 'no_cache_cookies', $this->normalize_static_no_cache_cookies($current_no_cache_cookies), $new_no_cache_cookies) || $options_changed;

        $current_status_policy = $this->normalize_static_status_cache_policy($this->option('static_pages.status_cache_policy', $this->default_static_status_cache_policy()));
        $new_status_policy = $this->normalize_static_status_cache_policy($this->read_static_input($input, $filtering, 'status_cache_policy', $current_status_policy));
        $static_settings['status_cache_policy'] = $new_status_policy;
        $options_changed = $this->static_input_changed($input, $filtering, 'status_cache_policy', $current_status_policy, $new_status_policy) || $options_changed;

        return $static_settings;
    }

    private function validate_cache_layer_settings(string $layer, array $layer_settings, $input, bool $filtering, bool &$options_changed): array
    {
        $input = (array)$input;

        if (!$this->cache_layer_is_configurable($layer)) {
            return $layer_settings;
        }

        if (!$this->layer_input_exists($layer, $input, $filtering, 'active')) {
            if ($this->cache_layer_configuration_form_submitted($layer, $input, $filtering) || !array_key_exists('active', $layer_settings)) {
                $layer_settings['active'] = (bool)$this->option("{$layer}.active", false);
            }
        }

        if ($this->cache_layer_supports_rules($layer)) {
            $layer_settings['rules'] = StaticCacheRules::normalize_rules($this->get_cache_layer_rules($layer));
            $layer_settings['rules_namespace'] = $layer;
        }
        else {
            unset($layer_settings['rules'], $layer_settings['rules_namespace'], $layer_settings['cache_include_rules_only']);
        }

        $current_lifespan = $this->normalize_static_lifespan($this->option("{$layer}.lifespan", self::DEFAULT_DYNAMIC_LIFESPAN), self::DEFAULT_DYNAMIC_LIFESPAN);
        $new_lifespan = $this->normalize_static_lifespan($this->read_layer_input($layer, $input, $filtering, 'lifespan', $current_lifespan), self::DEFAULT_DYNAMIC_LIFESPAN);
        $layer_settings['lifespan'] = $new_lifespan;
        $options_changed = $this->layer_input_changed($layer, $input, $filtering, 'lifespan', $current_lifespan, $new_lifespan) || $options_changed;

        foreach ($this->cache_layer_boolean_defaults($layer) as $option_key => $default_value) {
            $current_value = (bool)$this->option("{$layer}.{$option_key}", $default_value);
            $new_value = (bool)$this->read_layer_input($layer, $input, $filtering, $option_key, $current_value);
            $layer_settings[$option_key] = $new_value;

            if ($option_key !== 'auto_purge_content') {
                $options_changed = $this->layer_input_changed($layer, $input, $filtering, $option_key, $current_value, $new_value) || $options_changed;
            }
        }

        $current_user_agent_exclusions = $this->option("{$layer}.user_agent_exclusions", $this->default_static_user_agent_exclusions());
        $new_user_agent_exclusions = $this->normalize_static_user_agent_exclusions(
            $this->read_layer_input($layer, $input, $filtering, 'user_agent_exclusions', $current_user_agent_exclusions)
        );
        $layer_settings['user_agent_exclusions'] = $new_user_agent_exclusions;
        $options_changed = $this->layer_input_changed($layer, $input, $filtering, 'user_agent_exclusions', $this->normalize_static_user_agent_exclusions($current_user_agent_exclusions), $new_user_agent_exclusions) || $options_changed;

        $current_no_cache_cookies = $this->option("{$layer}.no_cache_cookies", array());
        $new_no_cache_cookies = $this->normalize_static_no_cache_cookies(
            $this->read_layer_input($layer, $input, $filtering, 'no_cache_cookies', $current_no_cache_cookies)
        );
        $layer_settings['no_cache_cookies'] = $new_no_cache_cookies;
        $options_changed = $this->layer_input_changed($layer, $input, $filtering, 'no_cache_cookies', $this->normalize_static_no_cache_cookies($current_no_cache_cookies), $new_no_cache_cookies) || $options_changed;

        if ($layer === 'wp_query') {
            $current_query_types = $this->normalize_wp_query_cache_types($this->option('wp_query.query_types', $this->default_wp_query_cache_types()));
            $new_query_types = $this->normalize_wp_query_cache_types(
                $this->read_layer_input($layer, $input, $filtering, 'query_types', $current_query_types)
            );
            $layer_settings['query_types'] = $new_query_types;
            $options_changed = $this->layer_input_changed($layer, $input, $filtering, 'query_types', $current_query_types, $new_query_types) || $options_changed;
        }

        if ($layer === 'wp_db') {
            $current_tables = $this->normalize_db_cache_tables($this->option('wp_db.tables', $this->default_db_cache_tables()));
            $new_tables = $this->normalize_db_cache_tables(
                $this->read_layer_input($layer, $input, $filtering, 'tables', $current_tables)
            );
            $layer_settings['tables'] = $new_tables;
            $options_changed = $this->layer_input_changed($layer, $input, $filtering, 'tables', $current_tables, $new_tables) || $options_changed;
        }

        return $layer_settings;
    }

    private function read_static_input(array $input, bool $filtering, string $option_key, $current_value)
    {
        if (isset($input['static_pages']) && is_array($input['static_pages']) && array_key_exists($option_key, $input['static_pages'])) {
            return $input['static_pages'][$option_key];
        }

        $flat_key = "static_pages.{$option_key}";

        if ($filtering) {
            return Settings::get_option($input, $flat_key, $current_value);
        }

        return array_key_exists($flat_key, $input) ? $input[$flat_key] : $current_value;
    }

    private function read_layer_input(string $layer, array $input, bool $filtering, string $option_key, $current_value)
    {
        if (isset($input[$layer]) && is_array($input[$layer]) && array_key_exists($option_key, $input[$layer])) {
            return $input[$layer][$option_key];
        }

        $flat_key = "{$layer}.{$option_key}";

        if ($filtering) {
            return Settings::get_option($input, $flat_key, $current_value);
        }

        return array_key_exists($flat_key, $input) ? $input[$flat_key] : $current_value;
    }

    private function static_input_changed(array $input, bool $filtering, string $option_key, $current_value, $new_value): bool
    {
        if ($filtering || !$this->static_input_exists($input, $filtering, $option_key)) {
            return false;
        }

        return $current_value !== $new_value;
    }

    private function layer_input_changed(string $layer, array $input, bool $filtering, string $option_key, $current_value, $new_value): bool
    {
        if ($filtering || !$this->layer_input_exists($layer, $input, $filtering, $option_key)) {
            return false;
        }

        return $current_value !== $new_value;
    }

    private function static_input_exists(array $input, bool $filtering, string $option_key): bool
    {
        if (isset($input['static_pages']) && is_array($input['static_pages']) && array_key_exists($option_key, $input['static_pages'])) {
            return true;
        }

        $flat_key = "static_pages.{$option_key}";

        if (array_key_exists($flat_key, $input)) {
            return true;
        }

        return $filtering && Settings::get_option($input, $flat_key, null) !== null;
    }

    private function layer_input_exists(string $layer, array $input, bool $filtering, string $option_key): bool
    {
        if (isset($input[$layer]) && is_array($input[$layer]) && array_key_exists($option_key, $input[$layer])) {
            return true;
        }

        $flat_key = "{$layer}.{$option_key}";

        if (array_key_exists($flat_key, $input)) {
            return true;
        }

        return $filtering && Settings::get_option($input, $flat_key, null) !== null;
    }

    private function static_configuration_form_submitted(array $input, bool $filtering): bool
    {
        if ($filtering) {
            return false;
        }

        $configuration_keys = array(
            'lifespan',
            'cache_include_rules_only',
            'cache_query_args',
            'auto_purge_content',
            'disable_admin_cache',
            'direct_access_enabled',
            'user_agent_exclusions_enabled',
            'user_agent_exclusions',
            'no_cache_cookies_enabled',
            'no_cache_cookies',
            'status_cache_policy',
            'user_scope',
        );

        foreach ($configuration_keys as $option_key) {
            if ($this->static_input_exists($input, false, $option_key)) {
                return true;
            }
        }

        return false;
    }

    private function cache_layer_configuration_form_submitted(string $layer, array $input, bool $filtering): bool
    {
        if ($filtering) {
            return false;
        }

        foreach ($this->cache_layer_configuration_keys($layer) as $option_key) {
            if ($this->layer_input_exists($layer, $input, false, $option_key)) {
                return true;
            }
        }

        return false;
    }

    private function cache_layer_configuration_keys(string $layer): array
    {
        $keys = array(
            'lifespan',
            'cache_query_args',
            'auto_purge_content',
            'disable_admin_cache',
            'user_agent_exclusions_enabled',
            'user_agent_exclusions',
            'no_cache_cookies_enabled',
            'no_cache_cookies',
        );

        if ($this->cache_layer_supports_rules($layer)) {
            $keys[] = 'cache_include_rules_only';
        }

        if ($layer === 'wp_db') {
            $keys = array_values(array_diff($keys, array('disable_admin_cache')));
        }

        if ($layer === 'wp_query') {
            $keys[] = 'query_types';
        }

        if ($layer === 'wp_db') {
            $keys[] = 'tables';
        }

        return $keys;
    }

    private function cache_layer_boolean_defaults(string $layer): array
    {
        $defaults = self::CACHE_LAYER_BOOLEAN_DEFAULTS;

        if (!$this->cache_layer_supports_rules($layer)) {
            unset($defaults['cache_include_rules_only']);
        }

        if ($layer === 'wp_db') {
            unset($defaults['disable_admin_cache']);
        }

        return $defaults;
    }

    public function cleanup(array $settings = array(), array $all_settings = array()): bool
    {
        $this->load_dependencies();

        $static_settings = (array)Settings::get_option($settings, 'static_pages', $this->option('static_pages', array()));
        $response = $this->toggle_static_direct_access_rules(false, $static_settings);

        $this->deactivate_static_direct_access_fast();
        ObjectCache::deactivate();
        Disk::delete(WP_CONTENT_DIR . DIRECTORY_SEPARATOR . "db.php");
        $this->flush_single_cache_layer('wp_db');
        $this->deactivate_static_cache_layer();
        $this->flush_single_cache_layer('wp_query');
        wpopt_remove_cron_hooks(array('WPOPT-ClearCache'));

        return $response;
    }

    public function activate(array $settings = array(), array $all_settings = array()): bool
    {
        $this->load_dependencies();

        $settings = !empty($settings) ? $settings : $this->option();
        $static_settings = (array)Settings::get_option($settings, 'static_pages', array());
        $response = true;

        if (!empty($static_settings['direct_access_enabled'])) {
            $this->sync_static_direct_access($static_settings);
        }
        else {
            $this->toggle_static_direct_access_rules(false, $static_settings);
            $this->deactivate_static_direct_access_fast();
        }

        if (Settings::get_option($settings, 'object_cache.active')) {
            ObjectCache::activate($this->layer_admin_cache_disabled_from_settings($settings, 'object_cache'));
        }

        if (Settings::get_option($settings, 'wp_db.active')) {
            DBCache::activate($this->layer_options_from_settings($settings, 'wp_db'));
        }

        $this->schedule_expired_cache_cleanup($settings);

        return $response;
    }

    private function load_dependencies(): void
    {
        if ($this->dependencies_loaded) {
            return;
        }

        $this->require_cache_supporter('cache/cache_dispatcher.class.php');

        $this->require_cache_supporter('cache/staticcache_rules.class.php');
        $this->require_cache_supporter('cache/cache_request_policy.class.php');
        $this->require_cache_supporter('cache/staticcache_direct.class.php');
        $this->require_cache_supporter('cache/dbcache.class.php');
        $this->require_cache_supporter('cache/querycache.class.php');
        $this->require_cache_supporter('cache/staticcache_runtime.class.php');
        $this->require_cache_supporter('cache/objectcache.class.php');
        require_once WPOPT_SUPPORTERS . 'optisec/localConf.php';

        $this->dependencies_loaded = true;
    }

    private function require_cache_supporter(string $relative_path): void
    {
        $path = WPOPT_SUPPORTERS . $relative_path;

        if (function_exists('opcache_invalidate') && is_file($path)) {
            @opcache_invalidate($path, true);
        }

        require_once $path;
    }

    public function flush_cache_blog($blog_id): void
    {
        $this->load_dependencies();

        $this->flush_single_cache_layer('wp_db', absint($blog_id));
        $this->flush_single_cache_layer('static_pages', absint($blog_id));
        $this->flush_single_cache_layer('wp_query', absint($blog_id));
    }

    public function flush_handler($arg = null): void
    {
        $this->flush_cache();
    }

    public function purge_query_cache_for_post_id($post_id = 0): void
    {
        if (!$this->wp_query_auto_purge_is_enabled()) {
            return;
        }

        $criteria = $this->get_query_cache_post_criteria(absint($post_id));

        if (!empty($criteria)) {
            QueryCache::clear_by_dependencies($criteria);
        }
    }

    public function purge_query_cache_for_comment($comment_id = 0): void
    {
        if (!$this->wp_query_auto_purge_is_enabled()) {
            return;
        }

        $post_ids = array();

        foreach ((array)$comment_id as $single_comment_id) {
            $comment = get_comment($single_comment_id);

            if ($comment && !empty($comment->comment_post_ID)) {
                $post_ids[] = (string)absint($comment->comment_post_ID);
            }
        }

        if (!empty($post_ids)) {
            QueryCache::clear_by_dependencies(array(
                'post_ids' => $post_ids,
            ));
        }
    }

    public function purge_query_cache_for_terms($ids = array(), $taxonomy = ''): void
    {
        if (!$this->wp_query_auto_purge_is_enabled()) {
            return;
        }

        $term_ids = array_map('absint', (array)$ids);
        $criteria = array(
            'terms' => $term_ids,
        );

        if (is_string($taxonomy) && $taxonomy !== '') {
            $criteria['taxonomies'] = array($taxonomy);
        }

        QueryCache::clear_by_dependencies($criteria);
    }

    public function purge_query_cache_for_object_terms($object_ids = array()): void
    {
        if (!$this->wp_query_auto_purge_is_enabled()) {
            return;
        }

        $criteria = array();

        foreach ((array)$object_ids as $object_id) {
            $criteria = $this->merge_query_cache_criteria($criteria, $this->get_query_cache_post_criteria(absint($object_id)));
        }

        if (!empty($criteria)) {
            QueryCache::clear_by_dependencies($criteria);
        }
    }

    public function purge_query_cache_for_taxonomy(string $taxonomy = ''): void
    {
        if (!$this->wp_query_auto_purge_is_enabled() || $taxonomy === '') {
            return;
        }

        QueryCache::clear_by_dependencies(array(
            'taxonomies' => array($taxonomy),
        ));
    }

    public function purge_query_cache_for_user($user = 0): void
    {
        if (!$this->wp_query_auto_purge_is_enabled()) {
            return;
        }

        $user_id = is_object($user) && isset($user->ID) ? absint($user->ID) : absint($user);
        if (!$user_id) {
            return;
        }

        QueryCache::clear_by_dependencies(array(
            'authors' => array((string)$user_id),
        ));
    }

    private function flush_cache($just_expired = false): void
    {
        if (!$this->has_active_cache_layers()) {
            return;
        }

        $this->load_dependencies();

        $this->flush_cache_layers(
            (bool)$just_expired,
            (bool)$this->option('wp_query.active'),
            (bool)$this->option('static_pages.active'),
            (bool)$this->option('wp_db.active')
        );

        if (!$just_expired) {
            $this->purge_cloudflare_cache();
        }
    }

    private function flush_cache_layers(bool $just_expired, bool $flush_query, bool $flush_static, bool $flush_db): void
    {
        if ($flush_query) {
            if ($just_expired) {
                QueryCache::flush($this->option('wp_query.lifespan'));
            }
            else {
                $this->flush_single_cache_layer('wp_query');
                $this->cache_auto_purge_dirty['wp_query'] = false;
            }
        }

        if ($flush_static) {
            if ($just_expired) {
                StaticCache::flush($this->option('static_pages.lifespan'));
            }
            else {
                $this->flush_single_cache_layer('static_pages');
                $this->cache_auto_purge_dirty['static_pages'] = false;
            }
        }

        if ($flush_db) {
            if ($just_expired) {
                DBCache::flush($this->option('wp_db.lifespan', self::DEFAULT_DYNAMIC_LIFESPAN));
            }
            else {
                $this->flush_single_cache_layer('wp_db');
                $this->cache_auto_purge_dirty['wp_db'] = false;
            }
        }

        $this->sync_cache_auto_purge_globals();
    }

    public function actions(): void
    {
        $this->register_cache_trash_cleanup();
        $this->schedule_expired_cache_cleanup($this->option());

        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        RequestActions::request($this->action_hook, function ($action) {

            $this->load_dependencies();

            $response = $this->handle_cache_action((string)$action, $_POST);
            $notice_status = $response ? 'success' : 'warning';
            $notice_message = $response
                ? __('Action was correctly executed', $this->context)
                : __('Action execution failed', $this->context);

            $this->add_notices($notice_status, $notice_message);

            return array(
                'wps-status' => $notice_status,
                'wps-notice' => $notice_message,
            );
        }, false, true);
    }

    private function schedule_expired_cache_cleanup(array $settings): void
    {
        if (!$this->settings_have_active_cache_layers($settings)) {
            wpopt_remove_cron_hooks(array('WPOPT-ClearCache'));
            return;
        }

        CronActions::schedule("WPOPT-ClearCache", HOUR_IN_SECONDS * 4, function () {
            $this->flush_cache(true);
        }, '06:00');
    }

    public function ajax_handler($args = array()): void
    {
        if (!current_user_can('manage_options')) {
            Ajax::response(array('text' => __('You are not allowed to perform this action.', 'wpopt')), 'error');
        }

        if (empty($args['nonce']) || !UtilEnv::verify_nonce("{$this->context}-ajax-nonce", (string)$args['nonce'])) {
            Ajax::response(array('text' => __('Invalid request.', 'wpopt')), 'error');
        }

        $this->load_dependencies();

        $form_data = array();
        parse_str((string)($args['form_data'] ?? ''), $form_data);

        $response = $this->handle_cache_action((string)($args['action'] ?? ''), $form_data);
        $action_layer = $this->cache_action_layer((string)($args['action'] ?? ''));
        $notice_status = $response ? 'success' : 'warning';

        Ajax::response(array(
            'text' => $response
                ? __('Action was correctly executed', $this->context)
                : __('Action execution failed', $this->context),
            'html' => $this->render_cache_rules_section($action_layer),
        ), $notice_status);
    }

    private function handle_cache_action(string $action, array $input = array()): bool
    {
        if (strpos($action, 'reset_cache:') === 0) {
            return $this->reset_cache_layer(substr($action, strlen('reset_cache:')));
        }

        if ($action === 'reset_cache') {
            $this->flush_single_cache_layer('wp_query');
            $this->flush_single_cache_layer('static_pages');
            $this->flush_single_cache_layer('wp_db');

            ObjectCache::flush();

            $this->purge_cloudflare_cache();

            return true;
        }

        if ($action === 'add_static_rule') {
            return $this->add_cache_rule('static_pages', $input);
        }

        if (strpos($action, 'add_cache_rule:') === 0) {
            return $this->add_cache_rule(substr($action, strlen('add_cache_rule:')), $input);
        }

        if (strpos($action, 'clear_static_rule:') === 0) {
            return $this->clear_cache_rule('static_pages', substr($action, strlen('clear_static_rule:')));
        }

        if (strpos($action, 'clear_cache_rule:') === 0) {
            [$layer, $rule_id] = $this->parse_cache_rule_action($action, 'clear_cache_rule:');
            return $this->clear_cache_rule($layer, $rule_id);
        }

        if (strpos($action, 'reset_static_rule_stats:') === 0) {
            return $this->reset_cache_rule_stats('static_pages', substr($action, strlen('reset_static_rule_stats:')));
        }

        if (strpos($action, 'reset_cache_rule_stats:') === 0) {
            [$layer, $rule_id] = $this->parse_cache_rule_action($action, 'reset_cache_rule_stats:');
            return $this->reset_cache_rule_stats($layer, $rule_id);
        }

        if (strpos($action, 'toggle_static_rule_mode:') === 0) {
            return $this->toggle_cache_rule_mode('static_pages', substr($action, strlen('toggle_static_rule_mode:')));
        }

        if (strpos($action, 'toggle_cache_rule_mode:') === 0) {
            [$layer, $rule_id] = $this->parse_cache_rule_action($action, 'toggle_cache_rule_mode:');
            return $this->toggle_cache_rule_mode($layer, $rule_id);
        }

        if (strpos($action, 'remove_static_rule:') === 0) {
            return $this->remove_cache_rule('static_pages', substr($action, strlen('remove_static_rule:')));
        }

        if (strpos($action, 'remove_cache_rule:') === 0) {
            [$layer, $rule_id] = $this->parse_cache_rule_action($action, 'remove_cache_rule:');
            return $this->remove_cache_rule($layer, $rule_id);
        }

        return false;
    }

    private function parse_cache_rule_action(string $action, string $prefix): array
    {
        $parts = explode(':', substr($action, strlen($prefix)), 2);

        return [
            sanitize_key((string)($parts[0] ?? '')),
            sanitize_key((string)($parts[1] ?? '')),
        ];
    }

    private function cache_action_layer(string $action): string
    {
        if ($action === 'add_static_rule' || strpos($action, '_static_rule') !== false) {
            return 'static_pages';
        }

        foreach (array('add_cache_rule:', 'clear_cache_rule:', 'reset_cache_rule_stats:', 'toggle_cache_rule_mode:', 'remove_cache_rule:') as $prefix) {
            if (strpos($action, $prefix) === 0) {
                return sanitize_key((string)strtok(substr($action, strlen($prefix)), ':')) ?: 'static_pages';
            }
        }

        return 'static_pages';
    }

    private function reset_cache_layer(string $layer): bool
    {
        $layer = sanitize_key($layer);
        $layers = $this->get_cache_layers();

        if (!isset($layers[$layer]) || empty($layers[$layer]['active'])) {
            return false;
        }

        call_user_func($layers[$layer]['flush']);

        return true;
    }

    private function get_cache_layers(): array
    {
        $this->load_dependencies();

        return [
            'wp_query' => [
                'label' => __('WP_Query Cache', 'wpopt'),
                'active' => (bool)$this->option('wp_query.active'),
                'storage_group' => QueryCache::get_storage_cache_group(),
                'size' => static function (): int {
                    return self::storage_group_size_bytes('wp_query');
                },
                'files' => static function (): int {
                    return self::storage_group_file_count('wp_query');
                },
                'flush' => function (): void {
                    $this->flush_single_cache_layer('wp_query');
                },
            ],
            'wp_db' => [
                'label' => __('Database Query Cache', 'wpopt'),
                'active' => (bool)$this->option('wp_db.active'),
                'storage_group' => DBCache::get_storage_cache_group(),
                'size' => static function (): int {
                    return self::storage_group_size_bytes('wp_db');
                },
                'files' => static function (): int {
                    return self::storage_group_file_count('wp_db');
                },
                'flush' => function (): void {
                    $this->flush_single_cache_layer('wp_db');
                },
            ],
            'object_cache' => [
                'label' => __('Object Cache', 'wpopt'),
                'active' => (bool)$this->option('object_cache.active'),
                'storage_group' => '',
                'size' => null,
                'files' => null,
                'flush' => static function (): void {
                    ObjectCache::flush();
                },
            ],
            'static_pages' => [
                'label' => __('Static Pages Cache', 'wpopt'),
                'active' => (bool)$this->option('static_pages.active'),
                'storage_group' => StaticCache::get_storage_cache_group(),
                'size' => static function (): int {
                    return StaticCache::get_storage_size();
                },
                'files' => static function (): int {
                    return StaticCache::get_storage_file_count();
                },
                'flush' => function (): void {
                    $this->flush_single_cache_layer('static_pages');
                },
            ],
        ];
    }

    private function add_cache_rule(string $layer, array $input): bool
    {
        $layer = $this->normalize_cache_layer($layer);
        if ($layer === '' || !$this->cache_layer_supports_rules($layer)) {
            return false;
        }

        $raw_name = $input['static_rule_name'] ?? '';
        $raw_pattern = $input['static_rule_pattern'] ?? '';
        $raw_mode = $input['static_rule_mode'] ?? 'include';

        $name = is_scalar($raw_name) ? sanitize_text_field(wp_unslash((string)$raw_name)) : '';
        $pattern = is_scalar($raw_pattern) ? sanitize_text_field(wp_unslash((string)$raw_pattern)) : '';
        $mode = is_scalar($raw_mode) ? StaticCacheRules::normalize_rule_mode(sanitize_key(wp_unslash((string)$raw_mode))) : 'include';

        if (!StaticCacheRules::pattern_is_valid($pattern)) {
            return false;
        }

        $rules = StaticCacheRules::normalize_rules($this->get_cache_layer_rules($layer));
        $rules[] = StaticCacheRules::create_rule($name, $pattern, $mode);

        return $this->update_cache_layer_rules($layer, $rules);
    }

    private function clear_cache_rule(string $layer, string $rule_id): bool
    {
        $layer = $this->normalize_cache_layer($layer);
        $rule_id = sanitize_key($rule_id);

        if ($layer === '' || !$this->cache_layer_supports_rules($layer) || $rule_id === '') {
            return false;
        }

        StaticCacheRules::clear_rule($rule_id, $this->cache_layer_storage_group($layer), $this->cache_layer_rules_namespace($layer));

        return true;
    }

    private function reset_cache_rule_stats(string $layer, string $rule_id): bool
    {
        $layer = $this->normalize_cache_layer($layer);
        $rule_id = sanitize_key($rule_id);

        if ($layer === '' || !$this->cache_layer_supports_rules($layer) || $rule_id === '') {
            return false;
        }

        StaticCacheRules::delete_rule_stats($rule_id, $this->cache_layer_rules_namespace($layer));

        return true;
    }

    private function remove_cache_rule(string $layer, string $rule_id): bool
    {
        $layer = $this->normalize_cache_layer($layer);
        $rule_id = sanitize_key($rule_id);

        if ($layer === '' || !$this->cache_layer_supports_rules($layer) || $rule_id === '') {
            return false;
        }
        $removed = false;
        $rules = array_values(array_filter(
            StaticCacheRules::normalize_rules($this->get_cache_layer_rules($layer)),
            static function (array $rule) use ($rule_id, &$removed): bool {
                if ($rule['id'] === $rule_id) {
                    $removed = true;
                    return false;
                }

                return true;
            }
        ));

        if (!$removed) {
            return false;
        }

        StaticCacheRules::clear_rule($rule_id, $this->cache_layer_storage_group($layer), $this->cache_layer_rules_namespace($layer));
        StaticCacheRules::delete_rule_stats($rule_id, $this->cache_layer_rules_namespace($layer));

        return $this->update_cache_layer_rules($layer, $rules);
    }

    private function toggle_cache_rule_mode(string $layer, string $rule_id): bool
    {
        $layer = $this->normalize_cache_layer($layer);
        $rule_id = sanitize_key($rule_id);

        if ($layer === '' || !$this->cache_layer_supports_rules($layer) || $rule_id === '') {
            return false;
        }

        $updated = false;
        $rules = array_map(
            static function (array $rule) use ($rule_id, &$updated): array {
                if ($rule['id'] !== $rule_id) {
                    return $rule;
                }

                $rule['mode'] = ($rule['mode'] ?? 'include') === 'include' ? 'exclude' : 'include';
                $updated = true;

                return $rule;
            },
            StaticCacheRules::normalize_rules($this->get_cache_layer_rules($layer))
        );

        if (!$updated) {
            return false;
        }

        $updated = $this->update_cache_layer_rules($layer, $rules);

        if ($updated) {
            $this->flush_single_cache_layer($layer);
        }

        return $updated;
    }

    private function get_static_page_rules(): array
    {
        return (array)wps('wpopt')->settings->get($this->slug . '.static_pages.rules', []);
    }

    private function get_cache_layer_rules(string $layer): array
    {
        $layer = $this->normalize_cache_layer($layer);

        if ($layer === '' || !$this->cache_layer_supports_rules($layer)) {
            return array();
        }

        return (array)wps('wpopt')->settings->get($this->slug . ".{$layer}.rules", []);
    }

    private function normalize_cache_layer(string $layer): string
    {
        $layer = sanitize_key($layer);

        return $this->cache_layer_is_configurable($layer) ? $layer : '';
    }

    private function cache_layer_is_configurable(string $layer): bool
    {
        return in_array($layer, array('static_pages', 'wp_query', 'wp_db'), true);
    }

    private function cache_layer_supports_rules(string $layer): bool
    {
        return $layer === 'static_pages';
    }

    private function cache_layer_label(string $layer): string
    {
        switch ($layer) {
            case 'wp_query':
                return __('WP_Query Cache', 'wpopt');
            case 'wp_db':
                return __('Database Query Cache', 'wpopt');
            case 'static_pages':
                return __('Static Pages Cache', 'wpopt');
        }

        return __('Cache', 'wpopt');
    }

    private function cache_layer_rules_namespace(string $layer): string
    {
        return $layer === 'static_pages' ? 'static' : $layer;
    }

    private function cache_layer_storage_group(string $layer): string
    {
        $this->load_dependencies();

        if ($layer === 'wp_query') {
            return QueryCache::get_storage_cache_group();
        }

        if ($layer === 'wp_db') {
            return DBCache::get_storage_cache_group();
        }

        return StaticCache::get_static_cache_group();
    }

    private function flush_single_cache_layer(string $layer, int $blog_id = 0): void
    {
        if ($layer === 'wp_query') {
            $this->flush_cache_storage_group(QueryCache::get_storage_cache_group(), $blog_id);
            StaticCacheRules::clear_all_entries('wp_query');
            return;
        }

        if ($layer === 'wp_db') {
            $this->flush_cache_storage_group(DBCache::get_storage_cache_group(), $blog_id);
            StaticCacheRules::clear_all_entries('wp_db');
            return;
        }

        $this->flush_static_cache_layer($blog_id);
    }

    private function flush_static_cache_layer(int $blog_id = 0): void
    {
        $this->flush_cache_storage_group(StaticCache::get_storage_cache_group(), $blog_id);

        if ($blog_id === 0) {
            $this->flush_static_direct_index();
        }

        StaticCacheRules::clear_all_entries();
    }

    private function deactivate_static_cache_layer(): void
    {
        $this->flush_static_cache_layer();
        $this->deactivate_static_direct_access_fast();
    }

    private function flush_static_direct_index(): void
    {
        $this->detach_cache_storage_path(trailingslashit(WPOPT_STORAGE) . 'direct-static/index', 'direct-static-index');
    }

    private function deactivate_static_direct_access_fast(): bool
    {
        $bootstrap_path = trailingslashit(ABSPATH) . 'wpopt-static-direct.php';

        if (is_file($bootstrap_path)) {
            @unlink($bootstrap_path);
        }

        $this->detach_cache_storage_path(trailingslashit(WPOPT_STORAGE) . 'direct-static', 'direct-static');

        return !is_file($bootstrap_path);
    }

    private function flush_cache_storage_group(string $storage_group, int $blog_id = 0): void
    {
        if ($storage_group === '') {
            return;
        }

        $storage = wps('wpopt')->storage;
        if (!method_exists($storage, 'get_path')) {
            return;
        }

        $path = $storage->get_path($storage_group, '', $blog_id);
        $this->detach_cache_storage_path($path, $storage_group, $blog_id);
    }

    private function detach_cache_storage_path(string $path, string $storage_group, int $blog_id = 0): bool
    {
        $source = realpath($path);
        if (!$source || !is_dir($source)) {
            return true;
        }

        $storage_root = realpath(WPOPT_STORAGE);
        if (!$storage_root || !$this->path_is_inside($source, $storage_root)) {
            return false;
        }

        $trash_root = $this->cache_trash_root();
        if (!Disk::make_path($trash_root, true)) {
            return false;
        }

        $trash_name = sanitize_file_name(str_replace(array('/', '\\'), '-', trim($storage_group, '/\\')));
        if ($blog_id > 0) {
            $trash_name .= '-blog-' . $blog_id;
        }

        $destination = trailingslashit($trash_root) . $trash_name . '-' . gmdate('YmdHis') . '-' . wp_generate_password(8, false, false);

        if (@rename($source, $destination)) {
            $this->schedule_cache_trash_cleanup(10);
            return true;
        }

        return false;
    }

    private function cache_trash_root(): string
    {
        return trailingslashit(WPOPT_STORAGE) . 'cache-trash';
    }

    private function register_cache_trash_cleanup(): void
    {
        if ($this->cache_trash_cleanup_registered) {
            return;
        }

        add_action(self::CACHE_TRASH_CLEANUP_HOOK, array($this, 'cleanup_cache_trash'));
        $this->cache_trash_cleanup_registered = true;

        if ($this->cache_trash_has_entries()) {
            $this->schedule_cache_trash_cleanup(30);
        }
    }

    private function schedule_cache_trash_cleanup(int $delay = 60): void
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
            return;
        }

        if (!wp_next_scheduled(self::CACHE_TRASH_CLEANUP_HOOK)) {
            wp_schedule_single_event(time() + max(1, $delay), self::CACHE_TRASH_CLEANUP_HOOK);
        }
    }

    public function cleanup_cache_trash(): void
    {
        if ($this->delete_cache_trash_batch()) {
            $this->schedule_cache_trash_cleanup(60);
        }
    }

    private function delete_cache_trash_batch(): bool
    {
        $trash_root = realpath($this->cache_trash_root());
        if (!$trash_root || !is_dir($trash_root)) {
            return false;
        }

        $deadline = microtime(true) + self::CACHE_TRASH_CLEANUP_TIME_BUDGET;
        $deleted = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($trash_root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                if (microtime(true) >= $deadline || $deleted >= self::CACHE_TRASH_CLEANUP_BATCH_LIMIT) {
                    break;
                }

                $item_path = $item->getPathname();
                if (!$this->path_is_inside($item_path, $trash_root)) {
                    continue;
                }

                if ($item->isDir()) {
                    @rmdir($item_path);
                }
                else {
                    @unlink($item_path);
                }

                $deleted++;
            }
        } catch (\UnexpectedValueException $exception) {
            return false;
        }

        return $this->cache_trash_has_entries();
    }

    private function cache_trash_has_entries(): bool
    {
        $trash_root = realpath($this->cache_trash_root());
        if (!$trash_root || !is_dir($trash_root)) {
            return false;
        }

        try {
            $iterator = new \FilesystemIterator($trash_root, \FilesystemIterator::SKIP_DOTS);
            return $iterator->valid();
        } catch (\UnexpectedValueException $exception) {
            return false;
        }
    }

    private function path_is_inside(string $path, string $base): bool
    {
        $path = wp_normalize_path($path);
        $base = trailingslashit(wp_normalize_path($base));

        return strpos($path, $base) === 0;
    }

    private function normalize_static_user_scope($scope): string
    {
        $scope = is_scalar($scope) ? sanitize_key((string)$scope) : '';

        if (in_array($scope, self::STATIC_USER_SCOPES, true)) {
            return $scope;
        }

        return self::DEFAULT_STATIC_USER_SCOPE;
    }

    private function normalize_static_user_agent_exclusions($patterns): array
    {
        return $this->normalize_static_pattern_list($patterns);
    }

    private function normalize_static_no_cache_cookies($patterns): array
    {
        return $this->normalize_static_pattern_list($patterns);
    }

    private function normalize_static_pattern_list($patterns): array
    {
        if (is_string($patterns)) {
            $patterns = preg_split("#[\r\n]+#", $patterns);
        }

        if (!is_array($patterns)) {
            return array();
        }

        return array_values(array_unique(array_filter(array_map(static function ($pattern): string {
            return sanitize_text_field(trim((string)$pattern));
        }, $patterns))));
    }

    private function default_static_user_agent_exclusions(): array
    {
        return array(
            'curl',
            'wget',
            'python-requests',
            'Go-http-client',
            'Apache-HttpClient',
            'PostmanRuntime',
            'httpie',
            'libwww-perl',
            '/bot|crawler|spider/i',
        );
    }

    private function normalize_static_lifespan($lifespan, string $default = self::DEFAULT_STATIC_LIFESPAN): string
    {
        $lifespan = is_scalar($lifespan) ? trim((string)$lifespan) : '';

        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $lifespan, $matches)) {
            $hours = min(23, max(0, (int)$matches[1]));
            $minutes = min(59, max(0, (int)$matches[2]));

            return sprintf('%02d:%02d', $hours, $minutes);
        }

        return $default;
    }

    private function default_static_status_cache_policy(): array
    {
        return self::DEFAULT_STATIC_STATUS_CACHE_POLICY;
    }

    private function get_static_status_cache_policy_options(): array
    {
        return array(
            '2xx' => __('2xx Success', 'wpopt'),
            '3xx' => __('3xx Redirects', 'wpopt'),
            '4xx' => __('4xx Client errors', 'wpopt'),
            '5xx' => __('5xx Server errors', 'wpopt'),
        );
    }

    private function normalize_static_status_cache_policy($statuses): array
    {
        if (is_string($statuses)) {
            $statuses = $statuses === '' ? array() : preg_split("#[\s,]+#", $statuses);
        }

        if (!is_array($statuses)) {
            return $this->default_static_status_cache_policy();
        }

        $normalized = array();

        foreach ($statuses as $status) {
            $status = strtolower(sanitize_key((string)$status));

            if (in_array($status, self::STATIC_STATUS_CACHE_GROUPS, true)) {
                $normalized[] = $status;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function default_wp_query_cache_types(): array
    {
        return self::WP_QUERY_CACHE_TYPES;
    }

    private function get_wp_query_cache_type_options(): array
    {
        return array(
            'post'             => __('Post queries', 'wpopt'),
            'page'             => __('Page queries', 'wpopt'),
            'attachment'       => __('Attachment queries', 'wpopt'),
            'custom_post_type' => __('Custom post type queries', 'wpopt'),
            'taxonomy_query'   => __('Taxonomy queries', 'wpopt'),
            'meta_query'       => __('Meta queries', 'wpopt'),
            'search_query'     => __('Search queries', 'wpopt'),
            'author_query'     => __('Author queries', 'wpopt'),
            'date_query'       => __('Date queries', 'wpopt'),
            'id_query'         => __('Specific ID/name queries', 'wpopt'),
            'feed_query'       => __('Feed queries', 'wpopt'),
        );
    }

    private function normalize_wp_query_cache_types($types): array
    {
        if (is_string($types)) {
            $types = $types === '' ? array() : preg_split("#[\s,]+#", $types);
        }

        if (!is_array($types)) {
            return $this->default_wp_query_cache_types();
        }

        $normalized = array();

        foreach ($types as $type) {
            $type = sanitize_key((string)$type);

            if (in_array($type, self::WP_QUERY_CACHE_TYPES, true)) {
                $normalized[] = $type;
            }
        }

        if (empty($normalized) && !empty(array_filter($types))) {
            return $this->default_wp_query_cache_types();
        }

        return array_values(array_unique($normalized));
    }

    private function default_db_cache_tables(): array
    {
        return array_keys($this->get_db_cache_table_options());
    }

    private function get_db_cache_table_options(): array
    {
        global $wpdb;

        $tables = array();

        if ($wpdb instanceof \wpdb) {
            $results = $wpdb->get_col('SHOW TABLES');

            if (is_array($results)) {
                $tables = array_map('strval', $results);
            }
        }

        if (empty($tables) && isset($wpdb)) {
            foreach (array('posts', 'postmeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'comments', 'commentmeta', 'users', 'usermeta', 'options') as $property) {
                if (!empty($wpdb->{$property}) && is_string($wpdb->{$property})) {
                    $tables[] = $wpdb->{$property};
                }
            }
        }

        $tables = array_values(array_unique(array_filter($tables)));
        natcasesort($tables);

        $options = array();

        foreach ($tables as $table) {
            $table = (string)$table;
            $options[strtolower($table)] = $this->format_db_table_label($table);
        }

        return $options;
    }

    private function normalize_db_cache_tables($tables): array
    {
        if (is_string($tables)) {
            $tables = $tables === '' ? array() : preg_split("#[\s,]+#", $tables);
        }

        if (!is_array($tables)) {
            return $this->default_db_cache_tables();
        }

        $available = array_map('strtolower', array_keys($this->get_db_cache_table_options()));
        $requested = array_map(static function ($table): string {
            return strtolower((string)$table);
        }, $tables);
        $normalized = array_values(array_intersect($available, $requested));

        if (empty($normalized) && !empty(array_filter($tables))) {
            return $this->default_db_cache_tables();
        }

        return $normalized;
    }

    private function format_db_table_label(string $table): string
    {
        global $wpdb;

        $prefix = isset($wpdb) && is_string($wpdb->prefix ?? null) ? $wpdb->prefix : '';

        if ($prefix !== '' && strpos($table, $prefix) === 0) {
            return substr($table, strlen($prefix)) ?: $table;
        }

        return $table;
    }

    private function update_static_page_rules(array $rules): bool
    {
        return $this->update_cache_layer_rules('static_pages', $rules);
    }

    private function update_cache_layer_rules(string $layer, array $rules): bool
    {
        $layer = $this->normalize_cache_layer($layer);

        if ($layer === '' || !$this->cache_layer_supports_rules($layer)) {
            return false;
        }

        $updated = wps('wpopt')->settings->update(
            $this->slug . ".{$layer}.rules",
            StaticCacheRules::normalize_rules($rules),
            true
        );

        if ($layer === 'static_pages' && $updated && (bool)$this->option('static_pages.direct_access_enabled', false)) {
            $this->flush_static_direct_index();
            StaticCacheDirectAccess::write_runtime_files($this->static_direct_access_options());
        }

        return $updated;
    }

    private function sync_static_direct_access(array $static_options): bool
    {
        $requested = !empty($static_options['direct_access_enabled']);

        if (!$requested) {
            $this->toggle_static_direct_access_rules(false, $static_options);
            $this->deactivate_static_direct_access_fast();
            return false;
        }

        if (empty($static_options['active'])) {
            $this->toggle_static_direct_access_rules(false, $static_options);
            $this->deactivate_static_direct_access_fast();
            $this->add_notices('warning', __('Static cache direct access is saved but inactive until static page cache is enabled.', 'wpopt'));

            return true;
        }

        if (StaticCacheDirectAccess::activate($static_options) && $this->toggle_static_direct_access_rules(true, $static_options)) {
            return true;
        }

        $this->add_notices('warning', __('Static cache direct access is saved, but server rules or runtime files could not be updated. Check server rules file and cache directory permissions.', 'wpopt'));

        return true;
    }

    private function toggle_static_direct_access_rules(bool $enabled, array $static_options): bool
    {
        $settings = $this->option();
        $settings['static_pages'] = $static_options;

        $htaccess = new WP_Htaccess($settings);
        $htaccess->toggle_rule('static_direct_access', $enabled);

        return !$htaccess->edited() || $htaccess->write();
    }

    private function get_static_direct_access_status(array $static_options): array
    {
        $settings = $this->option();
        $settings['static_pages'] = $static_options;

        $runtime_status = StaticCacheDirectAccess::status();
        $rules_path = WP_Htaccess::get_rules_path();
        $server_rule_exists = false;

        if ($rules_path !== '') {
            $htaccess = new WP_Htaccess($settings);
            $server_rule_exists = $htaccess->has_rule('static_direct_access');
        }

        $ready = !empty($static_options['active'])
            && !empty($runtime_status['config_enabled'])
            && !empty($runtime_status['bootstrap_exists'])
            && $server_rule_exists;

        if (empty($static_options['active'])) {
            $message = __('Saved, but static page cache is disabled.', 'wpopt');
        }
        elseif (empty($runtime_status['supported'])) {
            $message = __('Saved, but the WordPress root or cache directory is not writable.', 'wpopt');
        }
        elseif (empty($runtime_status['config_enabled']) || empty($runtime_status['bootstrap_exists'])) {
            $message = __('Saved, but the direct access runtime files are not installed yet.', 'wpopt');
        }
        elseif (!$server_rule_exists) {
            $message = sprintf(
                __('Runtime ready, but the server rule is missing in %s.', 'wpopt'),
                $rules_path !== '' ? basename($rules_path) : WP_Htaccess::get_rules_file_name()
            );
        }
        else {
            $message = empty($static_options['disable_admin_cache']) && in_array(Settings::get_option($static_options, 'user_scope', self::DEFAULT_STATIC_USER_SCOPE), array('both', 'logged_in'), true)
                ? __('Installed: eligible anonymous and logged-in cache hits bypass WordPress.', 'wpopt')
                : __('Installed: eligible anonymous cache hits bypass WordPress.', 'wpopt');
        }

        return array(
            'ready'              => $ready,
            'message'            => $message,
            'runtime'            => $runtime_status,
            'rules_path'         => $rules_path,
            'rules_writable'     => WP_Htaccess::is_rules_file_writable(),
            'server_rule_exists' => $server_rule_exists,
        );
    }

    protected function init(): void
    {
        $this->register_cache_trash_cleanup();

        if (!$this->has_active_cache_layers()) {
            return;
        }

        $this->load_dependencies();

        $this->cache_flush_hooks();

        $this->loader();
    }

    private function has_active_cache_layers(): bool
    {
        return $this->settings_have_active_cache_layers($this->option());
    }

    private function settings_have_active_cache_layers(array $settings): bool
    {
        return (bool)Settings::get_option($settings, 'wp_query.active')
            || (bool)Settings::get_option($settings, 'wp_db.active')
            || (bool)Settings::get_option($settings, 'static_pages.active')
            || (bool)Settings::get_option($settings, 'object_cache.active');
    }

    private function layer_options(string $layer): array
    {
        return $this->normalize_runtime_layer_options($layer, (array)$this->option($layer));
    }

    private function layer_options_from_settings(array $settings, string $layer): array
    {
        return $this->normalize_runtime_layer_options($layer, (array)Settings::get_option($settings, $layer, array()));
    }

    private function normalize_runtime_layer_options(string $layer, array $options): array
    {
        if (!$this->cache_layer_is_configurable($layer)) {
            return $options;
        }

        if (in_array($layer, self::DYNAMIC_CACHE_LAYERS, true)) {
            $options = array_merge(
                array(
                    'lifespan' => self::DEFAULT_DYNAMIC_LIFESPAN,
                ),
                self::CACHE_LAYER_BOOLEAN_DEFAULTS,
                $options
            );
            $options['user_agent_exclusions'] = $this->normalize_static_user_agent_exclusions($options['user_agent_exclusions'] ?? $this->default_static_user_agent_exclusions());
            $options['no_cache_cookies'] = $this->normalize_static_no_cache_cookies($options['no_cache_cookies'] ?? array());

            if ($layer === 'wp_query') {
                $options['query_types'] = $this->normalize_wp_query_cache_types($options['query_types'] ?? $this->default_wp_query_cache_types());
            }

            if ($layer === 'wp_db') {
                $options['tables'] = $this->normalize_db_cache_tables($options['tables'] ?? $this->default_db_cache_tables());
            }
        }

        if ($this->cache_layer_supports_rules($layer)) {
            $options['rules'] = StaticCacheRules::normalize_rules((array)($options['rules'] ?? $this->get_cache_layer_rules($layer)));
            $options['rules_namespace'] = $this->cache_layer_rules_namespace($layer);
        }
        else {
            unset($options['rules'], $options['rules_namespace'], $options['cache_include_rules_only']);
        }

        return $options;
    }

    private function layer_admin_cache_disabled_from_settings(array $settings, string $layer): bool
    {
        return (bool)Settings::get_option($settings, "{$layer}.disable_admin_cache", self::DEFAULT_LAYER_DISABLE_ADMIN_CACHE);
    }

    private function static_direct_access_options(): array
    {
        return (array)$this->option('static_pages', array());
    }

    private function purge_cloudflare_cache(): bool
    {
        $cloudflare = wps('wpopt')->moduleHandler->get_module_instance('cloudflare');

        if (!$cloudflare || !method_exists($cloudflare, 'purge_cache')) {
            return false;
        }

        return (bool)$cloudflare->purge_cache();
    }

    private function static_auto_purge_is_enabled(): bool
    {
        return (bool)$this->option('static_pages.auto_purge_content', true);
    }

    private function dynamic_auto_purge_is_enabled(string $layer): bool
    {
        return (bool)$this->option("{$layer}.auto_purge_content", true);
    }

    private function wp_query_auto_purge_is_enabled(): bool
    {
        if (!(bool)$this->option('wp_query.active') || !$this->dynamic_auto_purge_is_enabled('wp_query')) {
            return false;
        }

        if ($this->cache_auto_purge_is_suspended('wp_query')) {
            return false;
        }

        return true;
    }

    public function cache_is_active(): bool
    {
        return !empty($this->get_active_cache_layers());
    }

    public function suspend_cache_auto_purge(string $source = ''): bool
    {
        $active_layers = $this->get_active_cache_layers();

        if (empty($active_layers)) {
            return false;
        }

        foreach ($active_layers as $layer) {
            $this->increment_cache_auto_purge_suspension($layer);
            $this->mark_cache_auto_purge_dirty($layer);

            if (in_array($layer, self::CACHE_RUNTIME_SUSPEND_LAYERS, true)) {
                $this->increment_cache_runtime_suspension($layer);
            }
        }

        $this->sync_cache_auto_purge_globals();

        return true;
    }

    public function resume_cache_auto_purge(bool $flush_if_dirty = true, string $source = ''): bool
    {
        if (!$this->has_cache_auto_purge_suspensions()) {
            return false;
        }

        $remaining_auto_purge_suspensions = $this->cache_auto_purge_suspensions;
        foreach (self::CACHE_LAYERS as $layer) {
            if (empty($remaining_auto_purge_suspensions[$layer])) {
                continue;
            }

            $remaining_auto_purge_suspensions[$layer]--;

            if ($remaining_auto_purge_suspensions[$layer] <= 0) {
                unset($remaining_auto_purge_suspensions[$layer]);
            }
        }

        $should_flush_dirty_layers = $flush_if_dirty && empty(array_filter($remaining_auto_purge_suspensions));
        $flushed = true;

        if ($should_flush_dirty_layers) {
            $flushed = $this->flush_dirty_cache_layers();
        }

        foreach (self::CACHE_LAYERS as $layer) {
            $this->decrement_cache_auto_purge_suspension($layer);
        }

        foreach (self::CACHE_RUNTIME_SUSPEND_LAYERS as $layer) {
            $this->decrement_cache_runtime_suspension($layer);
        }

        $this->sync_cache_auto_purge_globals();

        return $flushed;
    }

    public function flush_cache_layers_active(): bool
    {
        $active_layers = $this->get_active_cache_layers();

        if (empty($active_layers)) {
            $this->cache_auto_purge_dirty = array();
            $this->sync_cache_auto_purge_globals();
            return false;
        }

        $this->load_dependencies();
        $this->flush_cache_layers(
            false,
            in_array('wp_query', $active_layers, true),
            in_array('static_pages', $active_layers, true),
            in_array('wp_db', $active_layers, true)
        );

        if (in_array('object_cache', $active_layers, true)) {
            ObjectCache::flush();
            $this->cache_auto_purge_dirty['object_cache'] = false;
            $this->sync_cache_auto_purge_globals();
        }

        return true;
    }

    private function get_active_cache_layers(): array
    {
        $active_layers = array();

        foreach (self::CACHE_LAYERS as $layer) {
            if ((bool)$this->option("{$layer}.active")) {
                $active_layers[] = $layer;
            }
        }

        return $active_layers;
    }

    private function cache_auto_purge_is_suspended(string $layer): bool
    {
        if (empty($this->cache_auto_purge_suspensions[$layer])) {
            return false;
        }

        $this->mark_cache_auto_purge_dirty($layer);

        return true;
    }

    private function mark_cache_auto_purge_dirty(string $layer): void
    {
        if (!in_array($layer, self::CACHE_LAYERS, true)) {
            return;
        }

        $this->cache_auto_purge_dirty[$layer] = true;
        $this->sync_cache_auto_purge_globals();
    }

    private function increment_cache_auto_purge_suspension(string $layer): void
    {
        if (!in_array($layer, self::CACHE_LAYERS, true)) {
            return;
        }

        $this->cache_auto_purge_suspensions[$layer] = absint($this->cache_auto_purge_suspensions[$layer] ?? 0) + 1;
    }

    private function decrement_cache_auto_purge_suspension(string $layer): void
    {
        if (empty($this->cache_auto_purge_suspensions[$layer])) {
            return;
        }

        $this->cache_auto_purge_suspensions[$layer]--;

        if ($this->cache_auto_purge_suspensions[$layer] <= 0) {
            unset($this->cache_auto_purge_suspensions[$layer]);
        }
    }

    private function has_cache_auto_purge_suspensions(): bool
    {
        return !empty(array_filter($this->cache_auto_purge_suspensions));
    }

    private function increment_cache_runtime_suspension(string $layer): void
    {
        if (!in_array($layer, self::CACHE_RUNTIME_SUSPEND_LAYERS, true)) {
            return;
        }

        $this->cache_runtime_suspensions[$layer] = absint($this->cache_runtime_suspensions[$layer] ?? 0) + 1;
    }

    private function decrement_cache_runtime_suspension(string $layer): void
    {
        if (empty($this->cache_runtime_suspensions[$layer])) {
            return;
        }

        $this->cache_runtime_suspensions[$layer]--;

        if ($this->cache_runtime_suspensions[$layer] <= 0) {
            unset($this->cache_runtime_suspensions[$layer]);
        }
    }

    private function flush_dirty_cache_layers(): bool
    {
        $active_layers = $this->get_active_cache_layers();
        $dirty_layers = array_values(array_filter($active_layers, function (string $layer): bool {
            return !empty($this->cache_auto_purge_dirty[$layer]);
        }));

        if (empty($dirty_layers)) {
            return true;
        }

        $this->load_dependencies();
        $this->flush_cache_layers(
            false,
            in_array('wp_query', $dirty_layers, true),
            in_array('static_pages', $dirty_layers, true),
            in_array('wp_db', $dirty_layers, true)
        );

        if (in_array('object_cache', $dirty_layers, true)) {
            ObjectCache::flush();
            $this->cache_auto_purge_dirty['object_cache'] = false;
            $this->sync_cache_auto_purge_globals();
        }

        return true;
    }

    private function sync_cache_auto_purge_globals(): void
    {
        $GLOBALS['wpopt_cache_auto_purge_suspensions'] = array_sum(array_map('absint', $this->cache_auto_purge_suspensions));
        $GLOBALS['wpopt_cache_auto_purge_suspended_layers'] = array_keys(array_filter($this->cache_auto_purge_suspensions));
        $GLOBALS['wpopt_cache_auto_purge_dirty_layers'] = array_keys(array_filter($this->cache_auto_purge_dirty));
        $GLOBALS['wpopt_cache_runtime_suspensions'] = array_sum(array_map('absint', $this->cache_runtime_suspensions));
        $GLOBALS['wpopt_cache_runtime_suspended_layers'] = array_keys(array_filter($this->cache_runtime_suspensions));
    }

    private function get_query_cache_post_criteria(int $post_id): array
    {
        if (!$post_id) {
            return array();
        }

        $criteria = array(
            'post_ids' => array((string)$post_id),
        );

        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return $criteria;
        }

        $criteria['post_types'] = array((string)$post->post_type);
        $criteria['authors'] = array((string)$post->post_author);

        foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
            $term_ids = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));

            if (is_wp_error($term_ids) || empty($term_ids)) {
                continue;
            }

            $criteria['taxonomies'][] = (string)$taxonomy;

            foreach ($term_ids as $term_id) {
                $criteria['terms'][] = (string)absint($term_id);
            }
        }

        return $this->normalize_query_cache_criteria($criteria);
    }

    private function merge_query_cache_criteria(array $base, array $extra): array
    {
        foreach ($extra as $key => $values) {
            $base[$key] = array_merge((array)($base[$key] ?? array()), (array)$values);
        }

        return $this->normalize_query_cache_criteria($base);
    }

    private function normalize_query_cache_criteria(array $criteria): array
    {
        $normalized = array();

        foreach ($criteria as $key => $values) {
            $values = array_values(array_unique(array_filter(array_map(static function ($value): string {
                return sanitize_key((string)$value);
            }, (array)$values))));

            if (!empty($values)) {
                $normalized[sanitize_key((string)$key)] = $values;
            }
        }

        return $normalized;
    }

    public function purge_static_cache_for_post(int $post_id, \WP_Post $post, bool $update): void
    {
        if ($update || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        $this->purge_static_cache_paths($this->get_post_related_static_paths($post));
    }

    public function purge_static_cache_for_post_update(int $post_id, \WP_Post $post_after, \WP_Post $post_before): void
    {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        $paths = array_merge(
            $this->get_post_related_static_paths($post_before),
            $this->get_post_related_static_paths($post_after)
        );

        $this->purge_static_cache_paths($paths);
    }

    public function purge_static_cache_for_post_id(int $post_id): void
    {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            $this->purge_static_cache_paths(array(home_url('/')));
            return;
        }

        $this->purge_static_cache_paths($this->get_post_related_static_paths($post));
    }

    public function purge_static_cache_for_term(int $term_id, int $tt_id = 0, string $taxonomy = '', $deleted_term = null): void
    {
        $paths = array(home_url('/'));
        $term_link = $deleted_term instanceof \WP_Term
            ? get_term_link($deleted_term)
            : get_term_link($term_id, $taxonomy);

        if (!is_wp_error($term_link)) {
            $paths[] = $term_link;
        }

        $this->purge_static_cache_paths($paths);
    }

    public function purge_static_cache_for_object_terms(int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids): void
    {
        $post = get_post($object_id);
        if (!$post instanceof \WP_Post) {
            return;
        }

        $paths = $this->get_post_related_static_paths($post);

        if (!empty($old_tt_ids)) {
            $old_terms = get_terms(array(
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'term_taxonomy_id' => array_map('absint', $old_tt_ids),
            ));

            if (!is_wp_error($old_terms)) {
                foreach ($old_terms as $term) {
                    $term_link = get_term_link($term);
                    if (!is_wp_error($term_link)) {
                        $paths[] = $term_link;
                    }
                }
            }
        }

        $this->purge_static_cache_paths($paths);
    }

    public function purge_static_cache_for_comment(string $new_status, string $old_status, \WP_Comment $comment): void
    {
        if ($new_status === $old_status || ($new_status !== 'approved' && $old_status !== 'approved')) {
            return;
        }

        $post = get_post((int)$comment->comment_post_ID);
        if (!$post instanceof \WP_Post) {
            return;
        }

        $this->purge_static_cache_paths($this->get_post_related_static_paths($post));
    }

    private function get_post_related_static_paths(\WP_Post $post): array
    {
        $paths = array(home_url('/'));

        $permalink = get_permalink($post);
        if (is_string($permalink) && $permalink !== '') {
            $paths[] = $permalink;
        }

        $post_type_archive = get_post_type_archive_link($post->post_type);
        if (is_string($post_type_archive) && $post_type_archive !== '') {
            $paths[] = $post_type_archive;
        }

        foreach (get_object_taxonomies($post->post_type, 'objects') as $taxonomy) {
            if (empty($taxonomy->public) && empty($taxonomy->publicly_queryable)) {
                continue;
            }

            $terms = get_the_terms($post, $taxonomy->name);
            if (empty($terms) || is_wp_error($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                $term_link = get_term_link($term);
                if (!is_wp_error($term_link)) {
                    $paths[] = $term_link;
                }
            }
        }

        $author_link = get_author_posts_url((int)$post->post_author);
        if (is_string($author_link) && $author_link !== '') {
            $paths[] = $author_link;
        }

        return $paths;
    }

    private function purge_static_cache_paths(array $urls): int
    {
        if ($this->cache_auto_purge_is_suspended('static_pages')) {
            return 0;
        }

        $this->load_dependencies();

        $paths = array();
        foreach ($urls as $url) {
            $path = $this->url_to_static_request_path((string)$url);

            if ($path === null) {
                continue;
            }

            $paths[] = $path;
        }

        $paths = array_values(array_unique($paths));

        if (empty($paths)) {
            return 0;
        }

        return StaticCacheRules::clear_paths($paths, StaticCache::get_static_cache_group());
    }

    private function url_to_static_request_path(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $path = wp_parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        $home_path = wp_parse_url(home_url('/'), PHP_URL_PATH);
        $home_path = is_string($home_path) ? trim($home_path, '/') : '';
        $path = trim($path, '/');

        if ($home_path !== '' && ($path === $home_path || strpos($path, $home_path . '/') === 0)) {
            $path = trim(substr($path, strlen($home_path)), '/');
        }

        return $path;
    }

    private function cache_flush_hooks(): void
    {
        if ($this->option('wp_query.active') or $this->option('wp_db.active') or $this->option('static_pages.active')) {

            add_action('clean_site_cache', array($this, 'flush_cache_blog'), 10, 1); //blog_id
            add_action('clean_network_cache', array($this, 'flush_cache_blog'), 10, 1); //network_id
        }

        if ($this->option('wp_query.active') && $this->dynamic_auto_purge_is_enabled('wp_query')) {
            add_action('clean_post_cache', array($this, 'purge_query_cache_for_post_id'), 10, 1);
            add_action('clean_page_cache', array($this, 'purge_query_cache_for_post_id'), 10, 1);
            add_action('clean_attachment_cache', array($this, 'purge_query_cache_for_post_id'), 10, 1);
            add_action('clean_comment_cache', array($this, 'purge_query_cache_for_comment'), 10, 1);

            add_action('clean_term_cache', array($this, 'purge_query_cache_for_terms'), 10, 2);
            add_action('clean_object_term_cache', array($this, 'purge_query_cache_for_object_terms'), 10, 1);
            add_action('clean_taxonomy_cache', array($this, 'purge_query_cache_for_taxonomy'), 10, 1);

            add_action('clean_user_cache', array($this, 'purge_query_cache_for_user'), 10, 1);
        }

        if ($this->option('static_pages.active') && $this->static_auto_purge_is_enabled()) {
            add_action('save_post', array($this, 'purge_static_cache_for_post'), 20, 3);
            add_action('post_updated', array($this, 'purge_static_cache_for_post_update'), 20, 3);
            add_action('before_delete_post', array($this, 'purge_static_cache_for_post_id'), 20, 1);
            add_action('trashed_post', array($this, 'purge_static_cache_for_post_id'), 20, 1);
            add_action('untrashed_post', array($this, 'purge_static_cache_for_post_id'), 20, 1);
            add_action('set_object_terms', array($this, 'purge_static_cache_for_object_terms'), 20, 6);
            add_action('edited_term', array($this, 'purge_static_cache_for_term'), 20, 3);
            add_action('delete_term', array($this, 'purge_static_cache_for_term'), 20, 4);
            add_action('transition_comment_status', array($this, 'purge_static_cache_for_comment'), 20, 3);
        }
    }

    private function loader(): void
    {
        if ($this->option('wp_query.active')) {
            QueryCache::getInstance($this->option('wp_query.lifespan', '04:00:00'), $this->layer_options('wp_query'));
        }

        if ($this->option('static_pages.active')) {
            StaticCache::getInstance($this->option('static_pages.lifespan', self::DEFAULT_STATIC_LIFESPAN . ':00'), $this->layer_options('static_pages'));
        }
    }

    protected function setting_fields($filter = ''): array
    {
        return $this->group_setting_fields(
            $this->group_setting_fields(
                $this->setting_field(__('WP_Query Cache'), 'wp_query_cache', 'separator'),
                $this->setting_field(__('Active', 'wpopt'), "wp_query.active", "checkbox", ['default' => true]),
                $this->setting_field(__('Configure', 'wpopt'), "wp_query.configuration", "conf", [
                    'parent' => 'wp_query.active',
                    'value'  => [
                        'text' => __('Configure', 'wpopt'),
                        'href' => wps_admin_route_url('wpopt', 'conf-cache-wp_query'),
                    ],
                ]),
            ),
            $this->group_setting_fields(
                $this->setting_field(__('Database Query Cache'), 'db_query_cache', 'separator'),
                $this->setting_field(__('Active', 'wpopt'), "wp_db.active", "checkbox", ['default' => true]),
                $this->setting_field(__('Configure', 'wpopt'), "wp_db.configuration", "conf", [
                    'parent' => 'wp_db.active',
                    'value'  => [
                        'text' => __('Configure', 'wpopt'),
                        'href' => wps_admin_route_url('wpopt', 'conf-cache-wp_db'),
                    ],
                ]),
            ),
            $this->group_setting_fields(
                $this->setting_field(__('Object Cache'), 'object_cache', 'separator'),
                $this->setting_field(__('Active', 'wpopt'), "object_cache.active", "checkbox"),
            ),
            $this->group_setting_fields(
                $this->setting_field(__('Static Pages Cache'), 'static_page_cache', 'separator'),
                $this->setting_field(__('Active', 'wpopt'), "static_pages.active", "checkbox"),
                $this->setting_field(__('Configure', 'wpopt'), "static_pages.configuration", "conf", [
                    'parent' => 'static_pages.active',
                    'value'  => [
                        'text' => __('Configure', 'wpopt'),
                        'href' => wps_admin_route_url('wpopt', 'conf-cache-static_pages'),
                    ],
                ]),
            )
        );
    }

    protected function infos(): array
    {
        return [
            'wp_query_cache'        => __('Stores the results of WP_Query for faster retrieval on subsequent requests.', 'wpopt'),
            'db_query_cache'        => __('Stores frequently used database queries in memory to reduce the number of queries made to the database, improving website performance.', 'wpopt') . '<br/>' .
                __('Use Configure to set this engine lifetime, regex rules and request exclusions. WPOPT_CACHE_DB_LIFETIME can still override the generated lifetime when explicitly defined.', 'wpopt') . '<br/>' .
                __('To enable caching also for option table paste "define(\'WPOPT_CACHE_DB_OPTIONS\', true)" in wp.config.', 'wpopt'),
            'object_cache'          => __('Stores PHP objects in memory for fast retrieval, reducing the need to recreate objects, improving website performance. Needs Redis or Memcached to be installed.', 'wpopt'),
            'static_page_cache'     => __('Stores static HTML pages generated from dynamic content to avoid repeated processing.', 'wpopt')
        ];
    }

    protected function print_header(): string
    {
        ob_start();
        ?>
        <form id="wpopt-cache-reset-action-form" method="POST" autocapitalize="off" autocomplete="off" class="wpopt-cache-reset-action-form" hidden>
            <?php RequestActions::nonce_field($this->action_hook); ?>
        </form>
        <?php
        return ob_get_clean();
    }

    protected function print_before_settings_fields(): string
    {
        return $this->cache_reset_panel();
    }

    private function cache_reset_panel(): string
    {
        $layers = array_filter(
            $this->get_cache_layers(),
            static function (array $layer): bool {
                return !empty($layer['active']);
            }
        );

        if (empty($layers)) {
            return '';
        }

        ob_start();
        ?>
        <div class="wpopt-cache-reset-panel">
            <?php foreach ($layers as $layer_key => $layer) : ?>
                <row class="wps-custom-action wpopt-cache-reset-row">
                    <div class="wpopt-cache-reset-copy">
                        <b class="wpopt-cache-reset-name"><?php echo esc_html($layer['label']); ?></b>
                        <span class="wpopt-cache-reset-size">
                            <span><?php _e('Cache size', 'wpopt') ?></span>
                            <strong>
                                <?php
                                echo esc_html($this->cache_layer_size_label($layer));
                                ?>
                            </strong>
                        </span>
                    </div>
                    <button form="wpopt-cache-reset-action-form" class="wps wps-button wpopt-btn is-danger" type="submit" name="<?php echo esc_attr($this->action_hook); ?>" value="<?php echo esc_attr('reset_cache:' . $layer_key); ?>"><?php _e('Reset Cache', 'wpopt'); ?></button>
                </row>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function cache_layer_size_label(array $layer): string
    {
        if (!isset($layer['size']) || !is_callable($layer['size'])) {
            return __('N/A', 'wpopt');
        }

        try {
            $bytes = max(0, (int)call_user_func($layer['size']));
            $file_count = isset($layer['files']) && is_callable($layer['files']) ? max(0, (int)call_user_func($layer['files'])) : null;
            $file_count_label = $file_count !== null
                ? sprintf(
                    ' (%s %s)',
                    function_exists('number_format_i18n') ? number_format_i18n($file_count) : number_format($file_count),
                    $file_count === 1 ? __('file', 'wpopt') : __('files', 'wpopt')
                )
                : '';

            return sprintf(
                '%s%s',
                (string)size_format($bytes, 2),
                $file_count_label
            );
        } catch (\Throwable $exception) {
            return __('N/A', 'wpopt');
        }
    }

    private static function storage_group_size_bytes(string $layer): int
    {
        $storage = wps('wpopt')->storage;
        $storage_group = self::storage_group_for_layer($layer);

        if (method_exists($storage, 'get_size_bytes')) {
            return max(0, (int)$storage->get_size_bytes($storage_group));
        }

        if (method_exists($storage, 'get_path')) {
            return max(0, Disk::calc_size($storage->get_path($storage_group)));
        }

        return 0;
    }

    private static function storage_group_file_count(string $layer): int
    {
        $storage = wps('wpopt')->storage;

        if (!method_exists($storage, 'get_path')) {
            return 0;
        }

        return Disk::count_files($storage->get_path(self::storage_group_for_layer($layer)));
    }

    private static function storage_group_for_layer(string $layer): string
    {
        switch ($layer) {
            case 'wp_query':
                return QueryCache::get_storage_cache_group();
            case 'wp_db':
                return DBCache::get_storage_cache_group();
            case 'static_pages':
                return StaticCache::get_storage_cache_group();
        }

        return '';
    }

    protected function print_footer(): string
    {
        return '';
    }

    public function get_configuration_page_label(string $target): string
    {
        if ($target === 'wp_query') {
            return __('WP_Query cache configuration', 'wpopt');
        }

        if ($target === 'wp_db') {
            return __('Database query cache configuration', 'wpopt');
        }

        if ($target === 'static_pages') {
            return __('Static cache configuration', 'wpopt');
        }

        return __('Cache configuration', 'wpopt');
    }

    public function render_configuration_page(string $target): void
    {
        if ($this->restricted_access('settings')) {
            echo '<block><h2>' . esc_html__('This Module is disabled for you or for your settings.', 'wpopt') . '</h2></block>';
            return;
        }

        if (in_array($target, self::DYNAMIC_CACHE_LAYERS, true)) {
            $this->render_dynamic_cache_configuration_page($target);
            return;
        }

        if ($target !== 'static_pages') {
            echo '<block><h2>' . esc_html__('Configuration not found.', 'wpopt') . '</h2></block>';
            return;
        }

        $this->load_dependencies();

        $static_pages_active = (bool)$this->option('static_pages.active', false);
        $user_scope = $this->normalize_static_user_scope($this->option('static_pages.user_scope', self::DEFAULT_STATIC_USER_SCOPE));
        $static_lifespan = $this->normalize_static_lifespan($this->option('static_pages.lifespan', self::DEFAULT_STATIC_LIFESPAN));
        $cache_include_rules_only = (bool)$this->option('static_pages.cache_include_rules_only', false);
        $cache_query_args = (bool)$this->option('static_pages.cache_query_args', false);
        $auto_purge_content = (bool)$this->option('static_pages.auto_purge_content', true);
        $cache_admin_requests = !(bool)$this->option('static_pages.disable_admin_cache', self::DEFAULT_LAYER_DISABLE_ADMIN_CACHE);
        $user_agent_exclusions_enabled = (bool)$this->option('static_pages.user_agent_exclusions_enabled', false);
        $user_agent_exclusions = $this->normalize_static_user_agent_exclusions($this->option('static_pages.user_agent_exclusions', $this->default_static_user_agent_exclusions()));
        if (empty($user_agent_exclusions)) {
            $user_agent_exclusions = $this->default_static_user_agent_exclusions();
        }
        $no_cache_cookies_enabled = (bool)$this->option('static_pages.no_cache_cookies_enabled', true);
        $no_cache_cookies = $this->normalize_static_no_cache_cookies($this->option('static_pages.no_cache_cookies', array()));
        $direct_access_enabled = (bool)$this->option('static_pages.direct_access_enabled', false);
        $direct_access_status = $this->get_static_direct_access_status((array)$this->option('static_pages', array()));
        $status_policy_options = $this->get_static_status_cache_policy_options();
        $status_cache_policy = $this->normalize_static_status_cache_policy($this->option('static_pages.status_cache_policy', $this->default_static_status_cache_policy()));
        $status_cache_policy_label = $status_cache_policy
            ? implode(', ', array_slice($status_cache_policy, 0, 4))
            : __('No statuses selected', 'wpopt');
        $ignore_query_args = !$cache_query_args;
        $user_scope_options = array(
            'both' => __('Both', 'wpopt'),
            'logged_in' => __('Logged in', 'wpopt'),
            'not_logged_in' => __('Not logged in', 'wpopt'),
        );
        $option_name = wps($this->context)->settings->get_context();

        ?>
        <section class="wps-wrap wpopt-static-conf">
            <div class="wpopt-static-conf-back">
                <a class="button button-secondary" href="<?php echo esc_url(wps_module_setting_url('wpopt', $this->slug)); ?>">
                    <span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
                    <span class="wpopt-back-label"><?php esc_html_e('Back to Cache settings', 'wpopt'); ?></span>
                </a>
            </div>

            <block class="wps-gridRow wpopt-static-rules wpopt-static-conf-section wpopt-static-toggle-section">
                <div class="wpopt-static-rules-head">
                    <h3><?php esc_html_e('Caching options', 'wpopt'); ?></h3>
                </div>
                <?php if (!$static_pages_active) : ?>
                    <div class="wpopt-static-config-warning">
                        <strong><?php esc_html_e('Static page cache is disabled.', 'wpopt'); ?></strong>
                        <span><?php esc_html_e('These options are saved, but no pages are stored until Static pages is enabled from the main Cache settings.', 'wpopt'); ?></span>
                    </div>
                <?php endif; ?>
                <form id="wps-options" action="options.php" method="post" autocapitalize="off" autocomplete="off" class="wpopt-static-toggle-form">
                    <?php settings_fields("{$this->context}-settings"); ?>
                    <input type="hidden" name="option_panel" value="settings-<?php echo esc_attr($this->slug); ?>">
                    <input type="hidden" name="<?php echo esc_attr("{$option_name}[change]"); ?>" value="<?php echo esc_attr($this->slug); ?>">
                    <div class="wpopt-static-toggle">
                        <span>
                            <strong><?php esc_html_e('Lifespan', 'wpopt'); ?></strong>
                            <small><?php esc_html_e('How long each static page cache entry remains valid. Format: hours:minutes.', 'wpopt'); ?></small>
                        </span>
                        <div class="wpopt-static-time-control">
                            <div class="wps-time-stepper wpopt-static-time-stepper">
                                <button class="wps-time-stepper-btn" type="button" data-wps-time-step="-1" aria-label="<?php esc_attr_e('Decrease time', 'wpopt'); ?>">-</button>
                                <input class="wps-time-stepper-input" type="text" inputmode="numeric" pattern="[0-9]{2}:[0-9]{2}" maxlength="5" name="<?php echo esc_attr("{$option_name}[static_pages.lifespan]"); ?>" value="<?php echo esc_attr($static_lifespan); ?>" step="900" placeholder="00:00" aria-label="<?php esc_attr_e('Lifespan hours and minutes', 'wpopt'); ?>">
                                <button class="wps-time-stepper-btn" type="button" data-wps-time-step="1" aria-label="<?php esc_attr_e('Increase time', 'wpopt'); ?>">+</button>
                            </div>
                        </div>
                    </div>
                    <label class="wpopt-static-toggle">
                        <span>
                            <strong><?php esc_html_e('Cache only include regex rules', 'wpopt'); ?></strong>
                            <small><?php esc_html_e('When disabled, all eligible pages are cached and include rules only track matching pages.', 'wpopt'); ?></small>
                        </span>
                        <input type="hidden" name="<?php echo esc_attr("{$option_name}[static_pages.cache_include_rules_only]"); ?>" value="0">
                        <input class="wps-apple-switch" type="checkbox" name="<?php echo esc_attr("{$option_name}[static_pages.cache_include_rules_only]"); ?>" value="1" <?php checked($cache_include_rules_only); ?>>
                    </label>
                    <label class="wpopt-static-toggle">
                        <span>
                            <strong><?php esc_html_e('Ignore query args', 'wpopt'); ?></strong>
                            <small><?php esc_html_e('When enabled, query parameters are ignored for static cache. Search queries remain excluded.', 'wpopt'); ?></small>
                        </span>
                        <input type="hidden" name="<?php echo esc_attr("{$option_name}[static_pages.cache_query_args]"); ?>" value="1">
                        <input class="wps-apple-switch" type="checkbox" name="<?php echo esc_attr("{$option_name}[static_pages.cache_query_args]"); ?>" value="0" <?php checked($ignore_query_args); ?>>
                    </label>
                    <label class="wpopt-static-toggle">
                        <span>
                            <strong><?php esc_html_e('Auto purge on content changes', 'wpopt'); ?></strong>
                            <small><?php esc_html_e('When content changes, only related static pages are cleared instead of flushing the whole cache.', 'wpopt'); ?></small>
                        </span>
                        <input type="hidden" name="<?php echo esc_attr("{$option_name}[static_pages.auto_purge_content]"); ?>" value="0">
                        <input class="wps-apple-switch" type="checkbox" name="<?php echo esc_attr("{$option_name}[static_pages.auto_purge_content]"); ?>" value="1" <?php checked($auto_purge_content); ?>>
                    </label>
                    <label class="wpopt-static-toggle">
                        <span>
                            <strong><?php esc_html_e('Cache admin requests', 'wpopt'); ?></strong>
                            <small><?php esc_html_e('When enabled, static PHP cache can run on WordPress admin requests. Direct access remains outside wp-admin so WordPress admin bootstraps normally.', 'wpopt'); ?></small>
                        </span>
                        <input type="hidden" name="<?php echo esc_attr("{$option_name}[static_pages.disable_admin_cache]"); ?>" value="1">
                        <input class="wps-apple-switch" type="checkbox" name="<?php echo esc_attr("{$option_name}[static_pages.disable_admin_cache]"); ?>" value="0" <?php checked($cache_admin_requests); ?>>
                    </label>
                    <label class="wpopt-static-toggle">
                        <span>
                            <strong><?php esc_html_e('Direct access', 'wpopt'); ?></strong>
                            <small><?php esc_html_e('When enabled, server rules route eligible requests to the static cache script before WordPress loads.', 'wpopt'); ?></small>
                            <?php if ($direct_access_enabled) : ?>
                                <small class="wpopt-static-direct-status <?php echo $direct_access_status['ready'] ? 'is-ready' : 'is-warning'; ?>">
                                    <?php echo esc_html($direct_access_status['message']); ?>
                                </small>
                            <?php endif; ?>
                        </span>
                        <input type="hidden" name="<?php echo esc_attr("{$option_name}[static_pages.direct_access_enabled]"); ?>" value="0">
                        <input class="wps-apple-switch" type="checkbox" name="<?php echo esc_attr("{$option_name}[static_pages.direct_access_enabled]"); ?>" value="1" <?php checked($direct_access_enabled); ?>>
                    </label>
                    <label class="wpopt-static-toggle">
                        <span>
                            <strong><?php esc_html_e('User-agent exclusions', 'wpopt'); ?></strong>
                            <small><?php esc_html_e('When enabled, matching user agents skip static cache. Add one plain text or PHP regex pattern per line.', 'wpopt'); ?></small>
                        </span>
                        <input type="hidden" name="<?php echo esc_attr("{$option_name}[static_pages.user_agent_exclusions_enabled]"); ?>" value="0">
                        <input class="wps-apple-switch" type="checkbox" name="<?php echo esc_attr("{$option_name}[static_pages.user_agent_exclusions_enabled]"); ?>" value="1" <?php checked($user_agent_exclusions_enabled); ?> data-wpopt-user-agent-exclusions-toggle>
                    </label>
                    <label class="wpopt-static-toggle wpopt-static-toggle-textarea <?php echo $user_agent_exclusions_enabled ? '' : 'is-hidden'; ?>" data-wpopt-user-agent-patterns>
                        <span>
                            <strong><?php esc_html_e('User-agent patterns', 'wpopt'); ?></strong>
                            <small><?php esc_html_e('Examples: Googlebot, curl, /bot|crawler/i', 'wpopt'); ?></small>
                        </span>
                        <textarea class="wps wpopt-static-textarea" name="<?php echo esc_attr("{$option_name}[static_pages.user_agent_exclusions]"); ?>" rows="4" spellcheck="false"><?php echo esc_textarea(implode(PHP_EOL, $user_agent_exclusions)); ?></textarea>
                    </label>
                    <label class="wpopt-static-toggle">
                        <span>
                            <strong><?php esc_html_e('No caching cookies', 'wpopt'); ?></strong>
                            <small><?php esc_html_e('When enabled, matching request cookies skip static cache. Leave the list empty to match any cookie.', 'wpopt'); ?></small>
                            <?php if ($no_cache_cookies_enabled) : ?>
                                <small class="wpopt-static-direct-status is-warning"><?php esc_html_e('Admin/logged-in browser visits usually carry cookies and will not populate static cache.', 'wpopt'); ?></small>
                            <?php endif; ?>
                        </span>
                        <input type="hidden" name="<?php echo esc_attr("{$option_name}[static_pages.no_cache_cookies_enabled]"); ?>" value="0">
                        <input class="wps-apple-switch" type="checkbox" name="<?php echo esc_attr("{$option_name}[static_pages.no_cache_cookies_enabled]"); ?>" value="1" <?php checked($no_cache_cookies_enabled); ?> data-wpopt-no-cache-cookies-toggle>
                    </label>
                    <label class="wpopt-static-toggle wpopt-static-toggle-textarea <?php echo $no_cache_cookies_enabled ? '' : 'is-hidden'; ?>" data-wpopt-no-cache-cookies-patterns>
                        <span>
                            <strong><?php esc_html_e('No caching cookie list', 'wpopt'); ?></strong>
                            <small><?php esc_html_e('Add one cookie name or name fragment per line. Examples: wordpress_logged_in_, woocommerce_items_in_cart.', 'wpopt'); ?></small>
                        </span>
                        <textarea class="wps wpopt-static-textarea" name="<?php echo esc_attr("{$option_name}[static_pages.no_cache_cookies]"); ?>" rows="4" spellcheck="false"><?php echo esc_textarea(implode(PHP_EOL, $no_cache_cookies)); ?></textarea>
                    </label>
                    <label class="wpopt-static-toggle" for="wpopt-static-status-policy">
                        <span>
                            <strong><?php esc_html_e('HTTP status cache policy', 'wpopt'); ?></strong>
                            <small><?php esc_html_e('Choose which response status families can be stored in static cache.', 'wpopt'); ?></small>
                        </span>
                        <dropdown class="wps-dropdown wpopt-cache-multiselect" data-wpopt-cache-multiselect data-input-name="<?php echo esc_attr("{$option_name}[static_pages.status_cache_policy]"); ?>" data-empty-label="<?php esc_attr_e('No statuses selected', 'wpopt'); ?>">
                            <row class="wps-input__wrapper">
                                <input id="wpopt-static-status-policy" type="hidden" value="<?php echo esc_attr(implode(',', $status_cache_policy)); ?>" autocomplete="off">
                                <span hidden data-wpopt-cache-inputs>
                                    <?php if (empty($status_cache_policy)) : ?>
                                        <input type="hidden" name="<?php echo esc_attr("{$option_name}[static_pages.status_cache_policy]"); ?>" value="">
                                    <?php else : ?>
                                        <?php foreach ($status_cache_policy as $status_group) : ?>
                                            <input type="hidden" name="<?php echo esc_attr("{$option_name}[static_pages.status_cache_policy][]"); ?>" value="<?php echo esc_attr($status_group); ?>">
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </span>
                                <strong class="width100 wps-input" data-wpopt-cache-label><?php echo esc_html($status_cache_policy_label); ?></strong>
                                <div class="wps-dropdown__opener">
                                    <svg class="wps-icon wps-icon__arrow" viewBox="0 0 16 16" width="16" height="16">
                                        <path d="M11.293 8L4.646 1.354l.708-.708L12.707 8l-7.353 7.354-.708-.708z"></path>
                                    </svg>
                                </div>
                            </row>
                            <div class="wps-multiselect__wrapper">
                                <ul class="wps-multiselect">
                                    <?php foreach ($status_policy_options as $status_group => $label) : ?>
                                        <li data-value="<?php echo esc_attr($status_group); ?>" data-label="<?php echo esc_attr($label); ?>" class="wps-multiselect__element <?php echo in_array($status_group, $status_cache_policy, true) ? 'is-selected' : ''; ?>">
                                            <span><?php echo esc_html($label); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </dropdown>
                    </label>
                    <label class="wpopt-static-toggle" for="wpopt-static-user-scope">
                        <span>
                            <strong><?php esc_html_e('User cache scope', 'wpopt'); ?></strong>
                            <small><?php esc_html_e('Choose which visitor sessions can use static page cache.', 'wpopt'); ?></small>
                        </span>
                        <dropdown class="wps-dropdown">
                            <row class="wps-input__wrapper">
                                <input name="<?php echo esc_attr("{$option_name}[static_pages.user_scope]"); ?>" id="wpopt-static-user-scope" type="hidden" value="<?php echo esc_attr($user_scope); ?>" autocomplete="off">
                                <strong class="width100 wps-input" data-input="wpopt-static-user-scope"><?php echo esc_html($user_scope_options[$user_scope]); ?></strong>
                                <div class="wps-dropdown__opener">
                                    <svg class="wps-icon wps-icon__arrow" viewBox="0 0 16 16" width="16" height="16">
                                        <path d="M11.293 8L4.646 1.354l.708-.708L12.707 8l-7.353 7.354-.708-.708z"></path>
                                    </svg>
                                </div>
                            </row>
                            <div class="wps-multiselect__wrapper">
                                <ul class="wps-multiselect">
                                    <?php foreach ($user_scope_options as $scope => $label) : ?>
                                        <li data-value="<?php echo esc_attr($scope); ?>" class="wps-multiselect__element"><span><?php echo esc_html($label); ?></span></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </dropdown>
                    </label>
                </form>
            </block>

            <?php echo $this->render_static_rules_section(); ?>
        </section>
        <?php
    }

    private function render_dynamic_cache_configuration_page(string $layer): void
    {
        $layer = $this->normalize_cache_layer($layer);

        if (!in_array($layer, self::DYNAMIC_CACHE_LAYERS, true)) {
            echo '<block><h2>' . esc_html__('Configuration not found.', 'wpopt') . '</h2></block>';
            return;
        }

        $layer_active = (bool)$this->option("{$layer}.active", false);
        $layer_label = $this->cache_layer_label($layer);
        $lifespan = $this->normalize_static_lifespan($this->option("{$layer}.lifespan", self::DEFAULT_DYNAMIC_LIFESPAN), self::DEFAULT_DYNAMIC_LIFESPAN);
        $cache_include_rules_only = $this->cache_layer_supports_rules($layer)
            ? (bool)$this->option("{$layer}.cache_include_rules_only", false)
            : false;
        $cache_query_args = (bool)$this->option("{$layer}.cache_query_args", false);
        $auto_purge_content = (bool)$this->option("{$layer}.auto_purge_content", true);
        $cache_admin_requests = $layer !== 'wp_db'
            && !(bool)$this->option("{$layer}.disable_admin_cache", self::DEFAULT_LAYER_DISABLE_ADMIN_CACHE);
        $user_agent_exclusions_enabled = (bool)$this->option("{$layer}.user_agent_exclusions_enabled", false);
        $user_agent_exclusions = $this->normalize_static_user_agent_exclusions($this->option("{$layer}.user_agent_exclusions", $this->default_static_user_agent_exclusions()));
        if (empty($user_agent_exclusions)) {
            $user_agent_exclusions = $this->default_static_user_agent_exclusions();
        }
        $no_cache_cookies_enabled = (bool)$this->option("{$layer}.no_cache_cookies_enabled", true);
        $no_cache_cookies = $this->normalize_static_no_cache_cookies($this->option("{$layer}.no_cache_cookies", array()));
        $user_agent_exclusions_help = $this->cache_layer_supports_rules($layer)
            ? __('When enabled, matching user agents skip this cache engine. Add one plain text or PHP regex pattern per line.', 'wpopt')
            : __('When enabled, matching user-agent text fragments skip this cache engine. Add one text fragment per line.', 'wpopt');
        $query_type_options = $layer === 'wp_query' ? $this->get_wp_query_cache_type_options() : array();
        $query_types = $layer === 'wp_query'
            ? $this->normalize_wp_query_cache_types($this->option('wp_query.query_types', $this->default_wp_query_cache_types()))
            : array();
        $query_type_labels = array_values(array_intersect_key($query_type_options, array_flip($query_types)));
        $query_type_label = empty($query_type_labels)
            ? __('No query types selected', 'wpopt')
            : implode(', ', array_slice($query_type_labels, 0, 4)) . (count($query_type_labels) > 4 ? ', ...' : '');
        $db_table_options = $layer === 'wp_db' ? $this->get_db_cache_table_options() : array();
        $db_tables = $layer === 'wp_db'
            ? $this->normalize_db_cache_tables($this->option('wp_db.tables', $this->default_db_cache_tables()))
            : array();
        $db_table_labels = array_values(array_intersect_key($db_table_options, array_flip($db_tables)));
        $db_table_label = empty($db_table_labels)
            ? __('No tables selected', 'wpopt')
            : implode(', ', array_slice($db_table_labels, 0, 4)) . (count($db_table_labels) > 4 ? ', ...' : '');
        $ignore_query_args = !$cache_query_args;
        $option_name = wps($this->context)->settings->get_context();
        ?>
        <section class="wps-wrap wpopt-static-conf">
            <div class="wpopt-static-conf-back">
                <a class="button button-secondary" href="<?php echo esc_url(wps_module_setting_url('wpopt', $this->slug)); ?>">
                    <span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
                    <span class="wpopt-back-label"><?php esc_html_e('Back to Cache settings', 'wpopt'); ?></span>
                </a>
            </div>

            <block class="wps-gridRow wpopt-static-rules wpopt-static-conf-section wpopt-static-toggle-section">
                <div class="wpopt-static-rules-head">
                    <h3><?php echo esc_html(sprintf(__('%s options', 'wpopt'), $layer_label)); ?></h3>
                </div>
                <?php if (!$layer_active) : ?>
                    <div class="wpopt-static-config-warning">
                        <strong><?php echo esc_html(sprintf(__('%s is disabled.', 'wpopt'), $layer_label)); ?></strong>
                        <span><?php esc_html_e('These options are saved, but no entries are stored until this cache engine is enabled from the main Cache settings.', 'wpopt'); ?></span>
                    </div>
                <?php endif; ?>
                <form id="wps-options" action="options.php" method="post" autocapitalize="off" autocomplete="off" class="wpopt-static-toggle-form">
                    <?php settings_fields("{$this->context}-settings"); ?>
                    <input type="hidden" name="option_panel" value="settings-<?php echo esc_attr($this->slug); ?>">
                    <input type="hidden" name="<?php echo esc_attr("{$option_name}[change]"); ?>" value="<?php echo esc_attr($this->slug); ?>">
                    <div class="wpopt-static-toggle">
                        <span>
                            <strong><?php esc_html_e('Lifespan', 'wpopt'); ?></strong>
                            <small><?php esc_html_e('How long each cache entry remains valid. Format: hours:minutes.', 'wpopt'); ?></small>
                        </span>
                        <div class="wpopt-static-time-control">
                            <div class="wps-time-stepper wpopt-static-time-stepper">
                                <button class="wps-time-stepper-btn" type="button" data-wps-time-step="-1" aria-label="<?php esc_attr_e('Decrease time', 'wpopt'); ?>">-</button>
                                <input class="wps-time-stepper-input" type="text" inputmode="numeric" pattern="[0-9]{2}:[0-9]{2}" maxlength="5" name="<?php echo esc_attr("{$option_name}[{$layer}.lifespan]"); ?>" value="<?php echo esc_attr($lifespan); ?>" step="900" placeholder="00:00" aria-label="<?php esc_attr_e('Lifespan hours and minutes', 'wpopt'); ?>">
                                <button class="wps-time-stepper-btn" type="button" data-wps-time-step="1" aria-label="<?php esc_attr_e('Increase time', 'wpopt'); ?>">+</button>
                            </div>
                        </div>
                    </div>
                    <?php if ($layer === 'wp_query') : ?>
                        <label class="wpopt-static-toggle" for="wpopt-query-cache-types">
                            <span>
                                <strong><?php esc_html_e('Cached query types', 'wpopt'); ?></strong>
                                <small><?php esc_html_e('Choose which WP_Query types can be stored by this cache engine.', 'wpopt'); ?></small>
                            </span>
                            <dropdown class="wps-dropdown wpopt-cache-multiselect" data-wpopt-cache-multiselect data-input-name="<?php echo esc_attr("{$option_name}[wp_query.query_types]"); ?>" data-empty-label="<?php esc_attr_e('No query types selected', 'wpopt'); ?>">
                                <row class="wps-input__wrapper">
                                    <input id="wpopt-query-cache-types" type="hidden" value="<?php echo esc_attr(implode(',', $query_types)); ?>" autocomplete="off">
                                    <span hidden data-wpopt-cache-inputs>
                                        <?php if (empty($query_types)) : ?>
                                            <input type="hidden" name="<?php echo esc_attr("{$option_name}[wp_query.query_types]"); ?>" value="">
                                        <?php else : ?>
                                            <?php foreach ($query_types as $query_type) : ?>
                                                <input type="hidden" name="<?php echo esc_attr("{$option_name}[wp_query.query_types][]"); ?>" value="<?php echo esc_attr($query_type); ?>">
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </span>
                                    <strong class="width100 wps-input" data-wpopt-cache-label><?php echo esc_html($query_type_label); ?></strong>
                                    <div class="wps-dropdown__opener">
                                        <svg class="wps-icon wps-icon__arrow" viewBox="0 0 16 16" width="16" height="16">
                                            <path d="M11.293 8L4.646 1.354l.708-.708L12.707 8l-7.353 7.354-.708-.708z"></path>
                                        </svg>
                                    </div>
                                </row>
                                <div class="wps-multiselect__wrapper">
                                    <ul class="wps-multiselect">
                                        <?php foreach ($query_type_options as $query_type => $label) : ?>
                                            <li data-value="<?php echo esc_attr($query_type); ?>" data-label="<?php echo esc_attr($label); ?>" class="wps-multiselect__element <?php echo in_array($query_type, $query_types, true) ? 'is-selected' : ''; ?>">
                                                <span><?php echo esc_html($label); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </dropdown>
                        </label>
                    <?php endif; ?>
                    <?php if ($layer === 'wp_db') : ?>
                        <label class="wpopt-static-toggle" for="wpopt-db-cache-tables">
                            <span>
                                <strong><?php esc_html_e('Cached database tables', 'wpopt'); ?></strong>
                                <small><?php esc_html_e('Choose which database tables can be stored by this cache engine.', 'wpopt'); ?></small>
                            </span>
                            <dropdown class="wps-dropdown wpopt-cache-multiselect" data-wpopt-cache-multiselect data-input-name="<?php echo esc_attr("{$option_name}[wp_db.tables]"); ?>" data-empty-label="<?php esc_attr_e('No tables selected', 'wpopt'); ?>">
                                <row class="wps-input__wrapper">
                                    <input id="wpopt-db-cache-tables" type="hidden" value="<?php echo esc_attr(implode(',', $db_tables)); ?>" autocomplete="off">
                                    <span hidden data-wpopt-cache-inputs>
                                        <?php if (empty($db_tables)) : ?>
                                            <input type="hidden" name="<?php echo esc_attr("{$option_name}[wp_db.tables]"); ?>" value="">
                                        <?php else : ?>
                                            <?php foreach ($db_tables as $table) : ?>
                                                <input type="hidden" name="<?php echo esc_attr("{$option_name}[wp_db.tables][]"); ?>" value="<?php echo esc_attr($table); ?>">
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </span>
                                    <strong class="width100 wps-input" data-wpopt-cache-label><?php echo esc_html($db_table_label); ?></strong>
                                    <div class="wps-dropdown__opener">
                                        <svg class="wps-icon wps-icon__arrow" viewBox="0 0 16 16" width="16" height="16">
                                            <path d="M11.293 8L4.646 1.354l.708-.708L12.707 8l-7.353 7.354-.708-.708z"></path>
                                        </svg>
                                    </div>
                                </row>
                                <div class="wps-multiselect__wrapper">
                                    <ul class="wps-multiselect">
                                        <?php foreach ($db_table_options as $table => $label) : ?>
                                            <li data-value="<?php echo esc_attr($table); ?>" data-label="<?php echo esc_attr($label); ?>" class="wps-multiselect__element <?php echo in_array($table, $db_tables, true) ? 'is-selected' : ''; ?>">
                                                <span><?php echo esc_html($label); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </dropdown>
                        </label>
                    <?php endif; ?>
                    <?php if ($this->cache_layer_supports_rules($layer)) : ?>
                        <label class="wpopt-static-toggle">
                            <span>
                                <strong><?php esc_html_e('Cache only include regex rules', 'wpopt'); ?></strong>
                                <small><?php esc_html_e('When enabled, this engine stores entries only for requests matching active include rules. Exclude rules always skip caching.', 'wpopt'); ?></small>
                            </span>
                            <input type="hidden" name="<?php echo esc_attr("{$option_name}[{$layer}.cache_include_rules_only]"); ?>" value="0">
                            <input class="wps-apple-switch" type="checkbox" name="<?php echo esc_attr("{$option_name}[{$layer}.cache_include_rules_only]"); ?>" value="1" <?php checked($cache_include_rules_only); ?>>
                        </label>
                    <?php endif; ?>
                    <label class="wpopt-static-toggle">
                        <span>
                            <strong><?php esc_html_e('Ignore query args', 'wpopt'); ?></strong>
                            <small><?php esc_html_e('When enabled, request query parameters are not added to this engine cache key.', 'wpopt'); ?></small>
                        </span>
                        <input type="hidden" name="<?php echo esc_attr("{$option_name}[{$layer}.cache_query_args]"); ?>" value="1">
                        <input class="wps-apple-switch" type="checkbox" name="<?php echo esc_attr("{$option_name}[{$layer}.cache_query_args]"); ?>" value="0" <?php checked($ignore_query_args); ?>>
                    </label>
                    <label class="wpopt-static-toggle">
                        <span>
                            <strong><?php esc_html_e('Auto purge on content changes', 'wpopt'); ?></strong>
                            <small><?php esc_html_e('When content, terms, comments or users change, this cache layer is flushed automatically.', 'wpopt'); ?></small>
                        </span>
                        <input type="hidden" name="<?php echo esc_attr("{$option_name}[{$layer}.auto_purge_content]"); ?>" value="0">
                        <input class="wps-apple-switch" type="checkbox" name="<?php echo esc_attr("{$option_name}[{$layer}.auto_purge_content]"); ?>" value="1" <?php checked($auto_purge_content); ?>>
                    </label>
                    <?php if ($layer !== 'wp_db') : ?>
                        <label class="wpopt-static-toggle">
                            <span>
                                <strong><?php esc_html_e('Cache admin requests', 'wpopt'); ?></strong>
                                <small><?php esc_html_e('When enabled, this cache engine can run during WordPress admin requests.', 'wpopt'); ?></small>
                            </span>
                            <input type="hidden" name="<?php echo esc_attr("{$option_name}[{$layer}.disable_admin_cache]"); ?>" value="1">
                            <input class="wps-apple-switch" type="checkbox" name="<?php echo esc_attr("{$option_name}[{$layer}.disable_admin_cache]"); ?>" value="0" <?php checked($cache_admin_requests); ?>>
                        </label>
                    <?php endif; ?>
                    <label class="wpopt-static-toggle">
                        <span>
                            <strong><?php esc_html_e('User-agent exclusions', 'wpopt'); ?></strong>
                            <small><?php echo esc_html($user_agent_exclusions_help); ?></small>
                        </span>
                        <input type="hidden" name="<?php echo esc_attr("{$option_name}[{$layer}.user_agent_exclusions_enabled]"); ?>" value="0">
                        <input class="wps-apple-switch" type="checkbox" name="<?php echo esc_attr("{$option_name}[{$layer}.user_agent_exclusions_enabled]"); ?>" value="1" <?php checked($user_agent_exclusions_enabled); ?> data-wpopt-user-agent-exclusions-toggle>
                    </label>
                    <label class="wpopt-static-toggle wpopt-static-toggle-textarea <?php echo $user_agent_exclusions_enabled ? '' : 'is-hidden'; ?>" data-wpopt-user-agent-patterns>
                        <span>
                            <strong><?php esc_html_e('User-agent patterns', 'wpopt'); ?></strong>
                            <small><?php esc_html_e('Examples: Googlebot, curl, /bot|crawler/i', 'wpopt'); ?></small>
                        </span>
                        <textarea class="wps wpopt-static-textarea" name="<?php echo esc_attr("{$option_name}[{$layer}.user_agent_exclusions]"); ?>" rows="4" spellcheck="false"><?php echo esc_textarea(implode(PHP_EOL, $user_agent_exclusions)); ?></textarea>
                    </label>
                    <label class="wpopt-static-toggle">
                        <span>
                            <strong><?php esc_html_e('No caching cookies', 'wpopt'); ?></strong>
                            <small><?php esc_html_e('When enabled, matching request cookies skip this cache engine. Leave the list empty to match any cookie.', 'wpopt'); ?></small>
                        </span>
                        <input type="hidden" name="<?php echo esc_attr("{$option_name}[{$layer}.no_cache_cookies_enabled]"); ?>" value="0">
                        <input class="wps-apple-switch" type="checkbox" name="<?php echo esc_attr("{$option_name}[{$layer}.no_cache_cookies_enabled]"); ?>" value="1" <?php checked($no_cache_cookies_enabled); ?> data-wpopt-no-cache-cookies-toggle>
                    </label>
                    <label class="wpopt-static-toggle wpopt-static-toggle-textarea <?php echo $no_cache_cookies_enabled ? '' : 'is-hidden'; ?>" data-wpopt-no-cache-cookies-patterns>
                        <span>
                            <strong><?php esc_html_e('No caching cookie list', 'wpopt'); ?></strong>
                            <small><?php esc_html_e('Add one cookie name or name fragment per line. Examples: wordpress_logged_in_, woocommerce_items_in_cart.', 'wpopt'); ?></small>
                        </span>
                        <textarea class="wps wpopt-static-textarea" name="<?php echo esc_attr("{$option_name}[{$layer}.no_cache_cookies]"); ?>" rows="4" spellcheck="false"><?php echo esc_textarea(implode(PHP_EOL, $no_cache_cookies)); ?></textarea>
                    </label>
                </form>
            </block>

            <?php echo $this->cache_layer_supports_rules($layer) ? $this->render_cache_rules_section($layer) : ''; ?>
        </section>
        <?php
    }

    private function render_static_rules_section(): string
    {
        return $this->render_cache_rules_section('static_pages');
    }

    private function render_cache_rules_section(string $layer): string
    {
        $layer = $this->normalize_cache_layer($layer);

        if ($layer === '') {
            $layer = 'static_pages';
        }

        if (!$this->cache_layer_supports_rules($layer)) {
            return '';
        }

        $rules_report = StaticCacheRules::get_rules_report(
            $this->get_cache_layer_rules($layer),
            $this->cache_layer_storage_group($layer),
            $this->cache_layer_rules_namespace($layer)
        );
        $layer_label = $this->cache_layer_label($layer);
        $add_action = $layer === 'static_pages' ? 'add_static_rule' : 'add_cache_rule:' . $layer;

        ob_start();
        ?>
        <block class="wps-gridRow wpopt-static-rules wpopt-static-conf-section" data-wpopt-static-rules-section>
            <div class="wpopt-static-rules-head">
                <div>
                    <h3><?php echo esc_html(sprintf(__('%s regex rules', 'wpopt'), $layer_label)); ?></h3>
                    <p class="wpopt-muted"><?php esc_html_e('Create targeted rules for this cache engine and monitor their disk usage and cache activity.', 'wpopt'); ?></p>
                </div>
                <span class="wpopt-static-rules-mode"><?php esc_html_e('Regex mode', 'wpopt'); ?></span>
            </div>

            <form id="wpopt-static-conf-action-form" class="wpopt-static-rule-form" method="POST" autocapitalize="off" autocomplete="off" data-wpopt-static-rule-form>
                <?php RequestActions::nonce_field($this->action_hook); ?>
                <div class="wpopt-static-rule-fields">
                    <label class="wpopt-static-rule-field" for="wpopt-static-rule-name">
                        <span><?php esc_html_e('Rule name', 'wpopt'); ?></span>
                        <input id="wpopt-static-rule-name" class="regular-text" type="text" name="static_rule_name" value="" placeholder="<?php esc_attr_e('Listings pages', 'wpopt'); ?>">
                    </label>
                    <label class="wpopt-static-rule-field" for="wpopt-static-rule-pattern">
                        <span><?php esc_html_e('Regex rule', 'wpopt'); ?></span>
                        <input id="wpopt-static-rule-pattern" class="regular-text" type="text" name="static_rule_pattern" value="" placeholder="<?php esc_attr_e('^vendita-', 'wpopt'); ?>">
                    </label>
                    <fieldset class="wpopt-static-mode-field">
                        <legend><?php esc_html_e('Mode', 'wpopt'); ?></legend>
                        <span class="wpopt-static-mode-toggle">
                            <label>
                                <input type="radio" name="static_rule_mode" value="include" checked>
                                <span><?php esc_html_e('Include', 'wpopt'); ?></span>
                            </label>
                            <label>
                                <input type="radio" name="static_rule_mode" value="exclude">
                                <span><?php esc_html_e('Exclude', 'wpopt'); ?></span>
                            </label>
                        </span>
                    </fieldset>
                </div>
                <div class="wpopt-static-rule-actions">
                    <p class="description"><?php esc_html_e('Matched against request paths such as "categoria/prodotto". Full PHP regex delimiters are supported.', 'wpopt'); ?></p>
                    <button class="wps wps-button wpopt-btn is-info" type="submit" name="<?php echo esc_attr($this->action_hook); ?>" value="<?php echo esc_attr($add_action); ?>" data-wpopt-static-action="<?php echo esc_attr($add_action); ?>"><?php esc_html_e('Add', 'wpopt'); ?></button>
                </div>
            </form>

            <?php if (!empty($rules_report)) : ?>
                <div class="wpopt-static-rules-table-wrap">
                    <?php echo $this->render_cache_rules_table($rules_report, $layer); ?>
                </div>
            <?php else : ?>
                <div class="wpopt-static-empty">
                    <strong><?php _e('No regex rules configured', 'wpopt'); ?></strong>
                    <span><?php _e('Add a rule above to start tracking disk usage, hits and misses for this cache engine.', 'wpopt'); ?></span>
                </div>
            <?php endif; ?>
        </block>
        <?php
        return ob_get_clean();
    }

    private function render_cache_rules_table(array $rules_report, string $layer): string
    {
        $rows = array();

        foreach ($rules_report as $row) {
            $rule = $row['rule'];
            $stats = $row['stats'];
            $last_activity = max(absint($stats['last_hit']), absint($stats['last_miss']), absint($stats['last_write']));

            $rule_name = '<strong>' . esc_html($rule['name']) . '</strong>';

            if (empty($rule['active'])) {
                $rule_name .= '<br><span class="description">' . esc_html__('Inactive', 'wpopt') . '</span>';
            }

            if ($layer === 'static_pages') {
                $clear_action = 'clear_static_rule:' . $rule['id'];
                $reset_action = 'reset_static_rule_stats:' . $rule['id'];
                $toggle_action = 'toggle_static_rule_mode:' . $rule['id'];
                $delete_action = 'remove_static_rule:' . $rule['id'];
            }
            else {
                $clear_action = 'clear_cache_rule:' . $layer . ':' . $rule['id'];
                $reset_action = 'reset_cache_rule_stats:' . $layer . ':' . $rule['id'];
                $toggle_action = 'toggle_cache_rule_mode:' . $layer . ':' . $rule['id'];
                $delete_action = 'remove_cache_rule:' . $layer . ':' . $rule['id'];
            }

            $actions = '<a class="button button-secondary" href="' . esc_url(RequestActions::get_url($this->action_hook, $clear_action)) . '" data-wpopt-static-action="' . esc_attr($clear_action) . '">' . esc_html__('Clear cache', 'wpopt') . '</a>';
            $actions .= '<a class="button button-secondary" href="' . esc_url(RequestActions::get_url($this->action_hook, $reset_action)) . '" data-wpopt-static-action="' . esc_attr($reset_action) . '">' . esc_html__('Reset stats', 'wpopt') . '</a>';
            $actions .= '<a class="button button-secondary" href="' . esc_url(RequestActions::get_url($this->action_hook, $toggle_action)) . '" data-wpopt-static-action="' . esc_attr($toggle_action) . '">' . esc_html($rule['mode'] === 'exclude' ? __('Set include', 'wpopt') : __('Set exclude', 'wpopt')) . '</a>';
            $actions .= '<a class="button button-link-delete" href="' . esc_url(RequestActions::get_url($this->action_hook, $delete_action)) . '" data-wpopt-static-action="' . esc_attr($delete_action) . '" data-confirm="' . esc_attr__('Delete this rule and its stats?', 'wpopt') . '">' . esc_html__('Delete rule', 'wpopt') . '</a>';

            $rows[] = array(
                'rule' => $rule_name,
                'regex' => '<code>' . esc_html($rule['pattern']) . '</code>',
                'mode' => '<span class="wpopt-static-mode-pill is-' . esc_attr($rule['mode']) . '">' . esc_html($rule['mode'] === 'exclude' ? __('Exclude', 'wpopt') : __('Include', 'wpopt')) . '</span>',
                'disk_space' => esc_html(size_format((int)$stats['bytes'])),
                'files' => esc_html(number_format_i18n((int)$stats['entries'])),
                'hits' => esc_html(number_format_i18n((int)$stats['hits'])),
                'misses' => esc_html(number_format_i18n((int)$stats['misses'])),
                'writes' => esc_html(number_format_i18n((int)$stats['writes'])),
                'hit_ratio' => '<span class="wpopt-static-ratio">' . esc_html($this->format_static_rule_hit_ratio((int)$stats['hits'], (int)$stats['misses'])) . '</span>',
                'last_activity' => $last_activity ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $last_activity)) : esc_html__('Never', 'wpopt'),
                'actions' => array(
                    'content' => $actions,
                    'class' => 'wpopt-static-row-actions',
                ),
            );
        }

        return List_Table::generateHTML_table(array(
            'class' => 'striped wpopt-static-rules-table',
            'columns' => array(
                'rule' => __('Rule', 'wpopt'),
                'regex' => __('Regex', 'wpopt'),
                'mode' => __('Mode', 'wpopt'),
                'disk_space' => __('Disk space', 'wpopt'),
                'files' => __('Files', 'wpopt'),
                'hits' => __('Hits', 'wpopt'),
                'misses' => __('Misses', 'wpopt'),
                'writes' => __('Writes', 'wpopt'),
                'hit_ratio' => __('Hit ratio', 'wpopt'),
                'last_activity' => __('Last activity', 'wpopt'),
                'actions' => __('Actions', 'wpopt'),
            ),
            'rows' => $rows,
            'empty' => __('No static cache rules configured', 'wpopt'),
        ));
    }

    private function format_static_rule_hit_ratio(int $hits, int $misses): string
    {
        $total = $hits + $misses;

        if ($total <= 0) {
            return '0%';
        }

        return round(($hits / $total) * 100, 1) . '%';
    }
}

return __NAMESPACE__;
