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
use WPOptimizer\modules\supporters\StaticCacheRules;

class Mod_Cache extends Module
{
    public static ?string $name = 'Cache';

    public static string $storage_internal = 'cache';

    public array $scopes = array('settings', 'autoload');

    protected string $context = 'wpopt';

    private bool $dependencies_loaded = false;

    public function validate_settings($input, $filtering = false): array
    {
        $new_valid = parent::validate_settings($input, $filtering);

        $this->load_dependencies();

        $new_valid['static_pages']['rules'] = StaticCacheRules::normalize_rules($this->get_static_page_rules());

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
        if ($this->dependencies_loaded) {
            return;
        }

        require_once WPOPT_SUPPORTERS . 'cache/cache_dispatcher.class.php';

        require_once WPOPT_SUPPORTERS . 'cache/staticcache_rules.class.php';
        require_once WPOPT_SUPPORTERS . 'cache/dbcache.class.php';
        require_once WPOPT_SUPPORTERS . 'cache/querycache.class.php';
        require_once WPOPT_SUPPORTERS . 'cache/staticcache.class.php';
        require_once WPOPT_SUPPORTERS . 'cache/objectcache.class.php';

        $this->dependencies_loaded = true;
    }

    public function flush_cache_blog($blog_id): void
    {
        $this->load_dependencies();

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
        if (!$this->has_active_cache_layers()) {
            return;
        }

        $this->load_dependencies();

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
        if ($this->has_active_cache_layers()) {
            CronActions::schedule("WPOPT-ClearCache", HOUR_IN_SECONDS * 4, function () {

                /**
                 * check old files every 4 Hours to prevent cache space explosion,
                 * especially with static-cache
                 */
                $this->flush_cache(true);
            }, '06:00');
        }

        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        RequestActions::request($this->action_hook, function ($action) {

            $this->load_dependencies();

            $response = false;

            if ($action === 'reset_cache') {

                QueryCache::flush();
                StaticCache::flush();
                DBCache::flush();

                ObjectCache::flush();

                $response = true;
            }
            elseif ($action === 'add_static_rule') {
                $response = $this->add_static_rule();
            }
            elseif (strpos($action, 'clear_static_rule:') === 0) {
                $response = $this->clear_static_rule(substr($action, strlen('clear_static_rule:')));
            }
            elseif (strpos($action, 'remove_static_rule:') === 0) {
                $response = $this->remove_static_rule(substr($action, strlen('remove_static_rule:')));
            }

            if ($response) {
                $this->add_notices('success', __('Action was correctly executed', $this->context));
            }
            else {
                $this->add_notices('warning', __('Action execution failed', $this->context));
            }
        });
    }

    private function add_static_rule(): bool
    {
        $raw_name = $_POST['static_rule_name'] ?? '';
        $raw_pattern = $_POST['static_rule_pattern'] ?? '';

        $name = is_scalar($raw_name) ? sanitize_text_field(wp_unslash((string)$raw_name)) : '';
        $pattern = is_scalar($raw_pattern) ? sanitize_text_field(wp_unslash((string)$raw_pattern)) : '';

        if (!StaticCacheRules::pattern_is_valid($pattern)) {
            return false;
        }

        $rules = StaticCacheRules::normalize_rules($this->get_static_page_rules());
        $rules[] = StaticCacheRules::create_rule($name, $pattern);

        return $this->update_static_page_rules($rules);
    }

    private function clear_static_rule(string $rule_id): bool
    {
        $rule_id = sanitize_key($rule_id);

        if ($rule_id === '') {
            return false;
        }

        StaticCacheRules::clear_rule($rule_id, StaticCache::get_static_cache_group());

        return true;
    }

    private function remove_static_rule(string $rule_id): bool
    {
        $rule_id = sanitize_key($rule_id);

        if ($rule_id === '') {
            return false;
        }

        $removed = false;
        $rules = array_values(array_filter(
            StaticCacheRules::normalize_rules($this->get_static_page_rules()),
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

        StaticCacheRules::clear_rule($rule_id, StaticCache::get_static_cache_group());
        StaticCacheRules::delete_rule_stats($rule_id);

        return $this->update_static_page_rules($rules);
    }

    private function get_static_page_rules(): array
    {
        return (array)wps('wpopt')->settings->get($this->slug . '.static_pages.rules', []);
    }

    private function update_static_page_rules(array $rules): bool
    {
        return wps('wpopt')->settings->update(
            $this->slug . '.static_pages.rules',
            StaticCacheRules::normalize_rules($rules),
            true
        );
    }

    protected function init(): void
    {
        if (!$this->has_active_cache_layers()) {
            return;
        }

        $this->load_dependencies();

        $this->cache_flush_hooks();

        $this->loader();
    }

    private function has_active_cache_layers(): bool
    {
        return (bool)$this->option('wp_query.active')
            || (bool)$this->option('wp_db.active')
            || (bool)$this->option('static_pages.active');
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
        ob_start();
        ?>
        <div class="wpopt-cache-reset-panel">
            <row class="wps-custom-action">
                <b>
                    <?php _e('Cache size', 'wpopt') ?> : <?php echo wps('wpopt')->storage->get_size(self::$storage_internal) ?>
                </b>
                <button form="wpopt-cache-reset-action-form" class="wps wps-button wpopt-btn is-danger" type="submit" name="<?php echo esc_attr($this->action_hook); ?>" value="reset_cache"><?php _e('Reset Cache', 'wpopt'); ?></button>
            </row>
        </div>
        <?php
        return ob_get_clean();
    }

    protected function print_footer(): string
    {
        $this->load_dependencies();

        $rules_report = StaticCacheRules::get_rules_report($this->get_static_page_rules(), StaticCache::get_static_cache_group());

        ob_start();
        ?>
        <block class="wps-gridRow wpopt-static-rules" id="wpopt-static-rules-panel">
            <div class="wpopt-static-rules-head">
                <div>
                    <h3><?php _e('Static cache regex rules', 'wpopt'); ?></h3>
                    <p class="wpopt-muted"><?php _e('Create targeted rules for static page caching and monitor their disk usage and cache activity.', 'wpopt'); ?></p>
                </div>
                <span class="wpopt-static-rules-mode"><?php _e('Regex mode', 'wpopt'); ?></span>
            </div>

            <div class="wpopt-static-rule-form">
                <div class="wpopt-static-rule-fields">
                    <label class="wpopt-static-rule-field" for="wpopt-static-rule-name">
                        <span><?php _e('Rule name', 'wpopt'); ?></span>
                        <input form="wpopt-static-rule-action-form" id="wpopt-static-rule-name" class="regular-text" type="text" name="static_rule_name" value="" placeholder="<?php esc_attr_e('Listings pages', 'wpopt'); ?>">
                    </label>
                    <label class="wpopt-static-rule-field" for="wpopt-static-rule-pattern">
                        <span><?php _e('Regex rule', 'wpopt'); ?></span>
                        <input form="wpopt-static-rule-action-form" id="wpopt-static-rule-pattern" class="regular-text" type="text" name="static_rule_pattern" value="" placeholder="<?php esc_attr_e('^vendita-', 'wpopt'); ?>">
                    </label>
                </div>
                <div class="wpopt-static-rule-actions">
                    <p class="description"><?php _e('Matched against request paths such as "categoria/prodotto". Full PHP regex delimiters are supported.', 'wpopt'); ?></p>
                    <button form="wpopt-static-rule-action-form" class="wps wps-button wpopt-btn is-info" type="submit" name="<?php echo esc_attr($this->action_hook); ?>" value="add_static_rule"><?php _e('Add rule', 'wpopt'); ?></button>
                </div>
            </div>

            <?php if (!empty($rules_report)) : ?>
                <div class="wpopt-static-rules-table-wrap">
                    <table class="widefat striped wpopt-static-rules-table">
                        <thead>
                        <tr>
                            <th><?php _e('Rule', 'wpopt'); ?></th>
                            <th><?php _e('Regex', 'wpopt'); ?></th>
                            <th><?php _e('Disk space', 'wpopt'); ?></th>
                            <th><?php _e('Files', 'wpopt'); ?></th>
                            <th><?php _e('Hits', 'wpopt'); ?></th>
                            <th><?php _e('Misses', 'wpopt'); ?></th>
                            <th><?php _e('Writes', 'wpopt'); ?></th>
                            <th><?php _e('Hit ratio', 'wpopt'); ?></th>
                            <th><?php _e('Last activity', 'wpopt'); ?></th>
                            <th><?php _e('Actions', 'wpopt'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rules_report as $row) : ?>
                            <?php
                            $rule = $row['rule'];
                            $stats = $row['stats'];
                            $last_activity = max(absint($stats['last_hit']), absint($stats['last_miss']), absint($stats['last_write']));
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($rule['name']); ?></strong>
                                    <?php if (empty($rule['active'])) : ?>
                                        <br><span class="description"><?php _e('Inactive', 'wpopt'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo esc_html($rule['pattern']); ?></code></td>
                                <td><?php echo esc_html(size_format((int)$stats['bytes'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n((int)$stats['entries'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n((int)$stats['hits'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n((int)$stats['misses'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n((int)$stats['writes'])); ?></td>
                                <td><span class="wpopt-static-ratio"><?php echo esc_html($this->format_static_rule_hit_ratio((int)$stats['hits'], (int)$stats['misses'])); ?></span></td>
                                <td><?php echo $last_activity ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $last_activity)) : esc_html__('Never', 'wpopt'); ?></td>
                                <td class="wpopt-static-row-actions">
                                    <a class="button button-secondary" href="<?php echo esc_url(RequestActions::get_url($this->action_hook, 'clear_static_rule:' . $rule['id'])); ?>"><?php _e('Clear', 'wpopt'); ?></a>
                                    <a class="button button-link-delete" href="<?php echo esc_url(RequestActions::get_url($this->action_hook, 'remove_static_rule:' . $rule['id'])); ?>"><?php _e('Remove', 'wpopt'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <div class="wpopt-static-empty">
                    <strong><?php _e('No regex rules configured', 'wpopt'); ?></strong>
                    <span><?php _e('Add a rule above to start tracking disk usage, hits and misses for targeted static pages.', 'wpopt'); ?></span>
                </div>
            <?php endif; ?>
        </block>

        <form id="wpopt-static-rule-action-form" method="POST" autocapitalize="off" autocomplete="off">
            <?php RequestActions::nonce_field($this->action_hook); ?>
        </form>

        <script>
            jQuery(function ($) {
                var $panel = $('#wpopt-static-rules-panel');
                var $staticBlock = $('.wps-options .wps-row-title').filter(function () {
                    return $.trim($(this).text()).indexOf('<?php echo esc_js(__('Static Pages Cache', 'wpopt')); ?>') !== -1;
                }).closest('wps-block');

                if ($panel.length && $staticBlock.length) {
                    $staticBlock.addClass('wpopt-static-rules-block');
                    $staticBlock.append($panel);
                }
            });
        </script>
        <?php
        return ob_get_clean();
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

