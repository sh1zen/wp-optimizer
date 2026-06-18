<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\core;

use WPS\core\CronActions;
use WPS\core\Graphic;
use WPS\core\UtilEnv;

/**
 * Creates the menu page for the plugin.
 *
 * Provides the functionality necessary for rendering the page corresponding
 * to the menu with which this page is associated.
 */
class PagesHandler
{
    private const DASHBOARD_CACHE_TTL = 30;

    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_plugin_pages'));
        add_action('admin_enqueue_scripts', array($this, 'register_assets'), 20, 1);
        add_filter('admin_body_class', array($this, 'admin_body_classes'));
        add_filter('wps_wpopt_core_settings_pages', array($this, 'register_core_setting_pages'), 10, 2);

        add_action('admin_notices', [$this, 'notice'], 10, 0);
    }

    public function notice(): void
    {
        global $pagenow;

        $user_id = wps_core()->get_cuID();
        $dismiss_nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

        if (isset($_GET['wpopt-dismiss-notice']) && $dismiss_nonce && wp_verify_nonce($dismiss_nonce, 'wpopt-dismiss-notice')) {

            wps('wpopt')->options->add($user_id, 'dismissed', true, 'admin-notice', WEEK_IN_SECONDS);
        }
        elseif ($pagenow == 'index.php' and !wps('wpopt')->options->get($user_id, 'dismissed', 'admin-notice', false)) {
            $donation_url = $this->get_donation_url();
            $review_url = $this->get_review_url();
            $dismiss_url = wp_nonce_url(add_query_arg('wpopt-dismiss-notice', '1'), 'wpopt-dismiss-notice');

            ?>
            <div class="notice notice-info notice-alt is-dismissible">
                <h3><?php _e('Enjoying WP Optimizer?', 'wpopt'); ?></h3>
                <p><?php _e('If the plugin is saving you time or improving site performance, a donation helps fund maintenance and new features. A 5-star review helps more users discover it.', 'wpopt'); ?></p>
                <p>
                    <a class="button button-primary" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($donation_url); ?>"><?php _e('Support development', 'wpopt'); ?></a>
                    <a class="button button-secondary" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($review_url); ?>"><?php _e('Leave a 5-star review', 'wpopt'); ?></a>
                    <a class="button button-secondary" href="<?php echo esc_url($dismiss_url); ?>"><?php _e('Dismiss', 'wpopt'); ?></a>
                </p>
            </div>
            <?php
        }
    }

    public function add_plugin_pages(): void
    {
        add_menu_page(
            'WP Optimizer',
            'WP Optimizer',
            'customize',
            'wp-optimizer',
            array($this, 'render_main'),
            'dashicons-admin-site'
        );

        $this->add_plugin_submenu_pages('wp-optimizer', 'wpopt', 'wpopt');

        foreach (wps('wpopt')->moduleHandler->get_modules(array('scopes' => 'admin-page'), true) as $module) {
            wps('wpopt')->moduleHandler->get_module_instance($module)->register_panel(null);
        }

        add_submenu_page(null, __('WPOPT Settings', 'wpopt'), __('Settings', 'wpopt'), 'manage_options', 'wpopt-settings', array($this, 'render_core_settings'));
        add_submenu_page(null, __('WPOPT FAQ', 'wpopt'), __('FAQ', 'wpopt'), 'edit_posts', 'wpopt-faqs', array($this, 'render_faqs'));

        add_action('wpopt_enqueue_panel_scripts', [$this, 'enqueue_scripts']);
    }

    private function add_plugin_submenu_pages(string $parent_slug, string $context, string $text_domain): void
    {
        add_submenu_page(
            $parent_slug,
            __('Dashboard', $text_domain),
            __('Dashboard', $text_domain),
            'customize',
            $parent_slug,
            array($this, 'render_main')
        );

        $labels = array(
            'modules_handler' => __('Modules', $text_domain),
            'cron'            => __('Schedule', $text_domain),
            'cloudflare'      => __('Cloudflare', $text_domain),
            'settings'        => __('Settings', $text_domain),
            'tracking'        => __('Tracking', $text_domain),
        );

        foreach (wps($context)->settings->get_core_setting_pages() as $page) {
            if (!isset($labels[$page['id']])) {
                continue;
            }

            add_submenu_page(
                $parent_slug,
                $labels[$page['id']],
                $labels[$page['id']],
                'manage_options',
                $parent_slug . '&wps-page=setting-' . $page['id'],
                array($this, 'render_main')
            );
        }
    }

    public function register_core_setting_pages(array $pages, $settings = null): array
    {
        $definitions = array(
            'modules_handler' => array(
                'label'       => __('Modules', 'wpopt'),
                'icon'        => 'grid',
                'description' => __('Choose which WP Optimizer tools are available in the workspace.', 'wpopt'),
            ),
            'cron'            => array(
                'label'       => __('Schedule', 'wpopt'),
                'icon'        => 'calendar',
                'description' => __('Configure automatic optimization tasks and scheduled maintenance.', 'wpopt'),
            ),
            'cloudflare'      => array(
                'label'       => __('Cloudflare', 'wpopt'),
                'icon'        => 'server',
                'description' => __('Purge Cloudflare edge cache when WP Optimizer clears local page cache.', 'wpopt'),
            ),
            'settings'        => array(
                'label'       => __('Settings', 'wpopt'),
                'icon'        => 'settings',
                'description' => __('Manage import, export, reset and shared plugin options.', 'wpopt'),
            ),
            'tracking'        => array(
                'label'       => __('Tracking', 'wpopt'),
                'icon'        => 'chart',
                'description' => __('Control diagnostics and anonymous usage tracking.', 'wpopt'),
            ),
        );

        foreach ($definitions as $module_slug => $definition) {
            $object = wps('wpopt')->moduleHandler->get_module_instance($module_slug);

            if (!$object) {
                continue;
            }

            $pages[] = array_merge($definition, array(
                'id'       => $module_slug,
                'callback' => array($object, 'render_settings'),
            ));
        }

        return $pages;
    }

    public function render_modules_settings(): void
    {
        $this->enqueue_scripts();

        if (WPOPT_DEBUG) {
            wps_core()->meter->lap('Modules settings pre render');
        }

        wps('wpopt')->settings->render_modules_settings();

        if (WPOPT_DEBUG) {
            wps_core()->meter->lap('Modules settings rendered');
        }
    }

    public function enqueue_scripts(): void
    {
        wp_enqueue_style('wpopt_css');
        wp_enqueue_script('vendor-wps-js');
        wp_enqueue_script('wpopt_admin_js');
    }

    public function render_core_settings(): void
    {
        $this->enqueue_scripts();

        wps('wpopt')->settings->render_core_settings();

    }

    public function register_assets($hook_suffix = ''): void
    {
        $style_asset = UtilEnv::resolve_asset(WPOPT_ABSPATH, 'assets/style.css', wps_core()->online);
        $script_asset = UtilEnv::resolve_asset(WPOPT_ABSPATH, 'assets/admin.js', wps_core()->online);

        wp_register_style("wpopt_css", $style_asset['url'], ['vendor-wps-css'], $style_asset['version'] ?: WPOPT_VERSION);
        wp_register_script("wpopt_admin_js", $script_asset['url'], ['vendor-wps-js'], $script_asset['version'] ?: WPOPT_VERSION, true);

        if ($this->is_wp_optimizer_admin_screen($hook_suffix)) {
            $this->enqueue_scripts();
        }

        wps_localize([
            'text_na'            => __('N/A', 'wpopt'),
            'saved'              => __('Settings Saved', 'wpopt'),
            'error'              => __('Request fail', 'wpopt'),
            'success'            => __('Request succeed', 'wpopt'),
            'running'            => __('Running', 'wpopt'),
            'autosaving'         => __('Autosaving...', 'wpopt'),
            'autosaved'          => __('All changes saved', 'wpopt'),
            'autosave_failed'    => __('Autosave failed', 'wpopt'),
            'wpopt_ajax_nonce'   => wp_create_nonce('wpopt-ajax-nonce'),
            'text_close_warning' => __('WP Optimizer is running an action. If you leave now, it may not be completed.', 'wpopt'),
        ]);
    }

    private function is_wp_optimizer_admin_screen($hook_suffix): bool
    {
        $hook_suffix = (string)$hook_suffix;

        if (strpos($hook_suffix, 'wp-optimizer') !== false || strpos($hook_suffix, 'wpopt-') !== false) {
            return true;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        return $page === 'wp-optimizer' || strpos($page, 'wpopt-') === 0;
    }

    public function admin_body_classes(string $classes): string
    {
        if (!$this->is_wp_optimizer_admin_screen('')) {
            return $classes;
        }

        return trim($classes . ' wps-admin-screen wpopt-admin-screen');
    }

    public function render_faqs(): void
    {
        $this->enqueue_scripts();
        ?>
        <section class="wps-wrap wps-plugin-faq-page wpopt-faq-shell">
            <block class="wps">
                <section class="wps-header wpopt-hero">
                    <span class="wpopt-faq-hero-icon"><?php echo Graphic::icon('info'); ?></span>
                    <div>
                        <h1><?php _e('FAQ', 'wpopt'); ?></h1>
                        <p><?php _e('Find answers to the most common questions about WP Optimizer.', 'wpopt'); ?></p>
                    </div>
                </section>
                <section class="wps wpopt-faq-content">
                    <div class="wps-faq-list">
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wpopt-faq-toggle"><?php echo $this->faq_icon('tools'); ?><span class="wpopt-faq-question-text"><?php echo __('What can this plugin do and how does it work?', 'wpopt') ?></span>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p>
                                    <b><?php echo __('This plugin is privacy-oriented: optimization runs on your server and does not require sending your site data to third-party services.', 'wpopt'); ?></b>
                                </p>
                                <span><?php echo __('WP Optimizer is modular, so you can enable only the features you need to keep the plugin overhead low.', 'wpopt'); ?></span>
                                <ul class="wps-list">
                                    <li><?php _e('Server enhancements such as compression, browser caching, and .htaccess rules.', 'wpopt'); ?></li>
                                    <li><?php _e('Caching layers for queries, database results, objects, and static pages.', 'wpopt'); ?></li>
                                    <li><?php _e('Minification for HTML, CSS, and JavaScript.', 'wpopt'); ?></li>
                                    <li><?php _e('Local image optimization and conversion workflows.', 'wpopt'); ?></li>
                                    <li><?php _e('Database cleanup, optimization, and backups.', 'wpopt'); ?></li>
                                    <li><?php _e('Security hardening and WordPress behavior toggles.', 'wpopt'); ?></li>
                                    <li><?php _e('Update controls, diagnostics, logs, and admin utilities.', 'wpopt'); ?></li>
                                </ul>
                                <p><?php echo __('Because modules can affect different parts of WordPress, the safest approach is to enable one feature at a time and test the front end after each change.', 'wpopt'); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wpopt-faq-toggle"><?php echo $this->faq_icon('external'); ?><span class="wpopt-faq-question-text"><?php echo __('What should I enable first on a live site?', 'wpopt') ?></span>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php echo __('Start with lower-risk optimizations, then move to the more aggressive ones only after testing.', 'wpopt'); ?></p>
                                <ul class="wps-list">
                                    <li><?php echo sprintf(__('Begin with <a href="%s">browser cache and compression settings</a> because they usually improve delivery without changing page logic.', 'wpopt'), wps_module_setting_url('wpopt', 'wp_optimizer')); ?></li>
                                    <li><?php echo sprintf(__('Configure <a href="%s">media optimization</a> for new uploads before launching large bulk jobs.', 'wpopt'), wps_module_setting_url('wpopt', 'media')); ?></li>
                                    <li><?php echo sprintf(__('Create a <a href="%s">database backup</a> before cleanup tasks or bigger tuning sessions.', 'wpopt'), wps_module_panel_url('database', 'db-backup')); ?></li>
                                    <li><?php echo sprintf(__('Add <a href="%s">cache</a> and <a href="%s">minify</a> last, enabling one option at a time and verifying the front end after each step.', 'wpopt'), wps_module_setting_url('wpopt', 'cache'), wps_module_setting_url('wpopt', 'minify')); ?></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wpopt-faq-toggle"><?php echo $this->faq_icon('sliders'); ?><span class="wpopt-faq-question-text"><?php echo __('Where can I configure optimization parameters?', 'wpopt') ?></span>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php echo sprintf(__('Most optimization options are configurable in the <a href="%s">Modules Options panel</a>.', 'wpopt'), wps_module_setting_url('wpopt')); ?></p>
                                <p><?php echo sprintf(__('Global tasks such as cron, module activation, telemetry, reset, export, and restore are available from the <a href="%s">Settings page</a>.', 'wpopt'), admin_url('admin.php?page=wpopt-settings')); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wpopt-faq-toggle"><?php echo $this->faq_icon('box'); ?><span class="wpopt-faq-question-text"><?php echo __('Can cache or minify break my layout?', 'wpopt') ?></span>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php echo __('Yes. Aggressive caching and front-end minification can expose conflicts in themes or plugins that rely on specific asset order, inline scripts, or dynamic output.', 'wpopt'); ?></p>
                                <ul class="wps-list">
                                    <li><?php echo sprintf(__('Enable <a href="%s">minify</a> gradually: HTML, CSS, and JavaScript do not need to be turned on all at once.', 'wpopt'), wps_module_setting_url('wpopt', 'minify')); ?></li>
                                    <li><?php echo sprintf(__('After each change, clear or reset <a href="%s">cache</a> and test important pages while logged out as well.', 'wpopt'), wps_module_setting_url('wpopt', 'cache')); ?></li>
                                    <li><?php _e('If something breaks, disable the last option you enabled first. That is usually the fastest way to isolate the conflict.', 'wpopt'); ?></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wpopt-faq-toggle"><?php echo $this->faq_icon('image'); ?><span class="wpopt-faq-question-text"><?php echo __('How does the media optimizer work?', 'wpopt') ?></span>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php echo __('Media optimizer works in three different ways:', 'wpopt'); ?></p>
                                <ul class="wps-list">
                                    <li><?php echo sprintf(__("By a scheduled event it's able to collect and optimize any media uploaded daily. <a href='%s'>Here</a> you can configure all schedule related settings.", 'wpopt'), admin_url('admin.php?page=wpopt-settings#settings-cron')); ?></li>
                                    <li><?php _e('By a specific path scanner, Media optimizer will run a background activity to optimize all images present in the input path.', 'wpopt'); ?></li>
                                    <li><?php _e('By a whole database scanner, Media optimizer will run a background activity to check all images saved in your WordPress library optimizing each image and every thumbnail associated.', 'wpopt'); ?></li>
                                </ul>
                                <p><?php echo sprintf(__('Any image optimization will be run following your settings set <a href="%s">Here</a>.', 'wpopt'), wps_module_setting_url('wpopt', 'media')); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wpopt-faq-toggle"><?php echo $this->faq_icon('clock'); ?><span class="wpopt-faq-question-text"><?php echo __('Why are image optimization jobs not starting?', 'wpopt') ?></span>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php echo __('Bulk image optimization runs in background, so it depends on the plugin scheduler and on image libraries available on the server.', 'wpopt'); ?></p>
                                <ul class="wps-list">
                                    <li><?php echo sprintf(__('Verify that <a href="%s">cron is active</a>; background jobs will not progress if scheduled tasks are not running.', 'wpopt'), admin_url('admin.php?page=wpopt-settings#settings-cron')); ?></li>
                                    <li><?php _e('Check that Imagick or GD is available in PHP. Imagick gives the best coverage, but GD can still handle basic processing.', 'wpopt'); ?></li>
                                    <li><?php echo sprintf(__('Review your <a href="%s">media settings</a> before starting a full-library scan so the job uses the correct quality and format options.', 'wpopt'), wps_module_setting_url('wpopt', 'media')); ?></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wpopt-faq-toggle"><?php echo $this->faq_icon('database'); ?><span class="wpopt-faq-question-text"><?php echo __('Do I need Redis or Memcached for object cache?', 'wpopt') ?></span>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php echo __('Only the object cache feature requires Redis or Memcached. The other optimization modules can still be used without them.', 'wpopt'); ?></p>
                                <ul class="wps-list">
                                    <li><?php echo sprintf(__('If your server does not provide Redis or Memcached, leave <a href="%s">object cache</a> disabled.', 'wpopt'), wps_module_setting_url('wpopt', 'cache')); ?></li>
                                    <li><?php _e('Static page cache, database/query cache, browser cache, compression, and minify do not depend on an external object cache server.', 'wpopt'); ?></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wpopt-faq-toggle"><?php echo $this->faq_icon('database'); ?><span class="wpopt-faq-question-text"><?php echo __('How can I clean the database safely?', 'wpopt') ?></span>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php echo __('Database cleanup is most useful when you do it conservatively and with a rollback path ready.', 'wpopt'); ?></p>
                                <ul class="wps-list">
                                    <li><?php echo sprintf(__('Create a fresh <a href="%s">database backup</a> before deleting revisions, transients, orphaned data, or other leftovers.', 'wpopt'), wps_module_panel_url('database', 'db-backup')); ?></li>
                                    <li><?php echo sprintf(__('Run cleanup tasks from the <a href="%s">Database module</a> when the site is stable and no import, migration, or major update is running.', 'wpopt'), wps_module_setting_url('wpopt', 'database')); ?></li>
                                    <li><?php _e('If the result is not what you expected, restore the backup first instead of guessing which rows changed.', 'wpopt'); ?></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wpopt-faq-toggle"><?php echo $this->faq_icon('external'); ?><span class="wpopt-faq-question-text"><?php echo __('Can I export settings before major changes?', 'wpopt') ?></span>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php echo sprintf(__('Yes. The <a href="%s">Settings page</a> lets you export, import, reset, or restore plugin options.', 'wpopt'), admin_url('admin.php?page=wpopt-settings')); ?></p>
                                <p><?php _e('Use an export before testing aggressive cache, minify, or security changes so you can quickly return to a known-good configuration.', 'wpopt'); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="wps-faq-item">
                        <div class="wps-faq-question-wrapper ">
                            <div
                                    class="wps-faq-question wpopt-faq-toggle"><?php echo $this->faq_icon('settings'); ?><span class="wpopt-faq-question-text"><?php echo __('Does WP Optimizer work on multisite?', 'wpopt') ?></span>
                                <icon class="wps-collapse-icon">+</icon>
                            </div>
                            <div class="wps-faq-answer wps-collapse">
                                <p><?php _e('Yes. The plugin supports multisite and can be network-activated.', 'wpopt'); ?></p>
                                <p><?php echo sprintf(__('Even on multisite, test optimization changes gradually from the <a href="%s">module settings</a> because each subsite may use different themes, plugins, and traffic patterns.', 'wpopt'), wps_module_setting_url('wpopt')); ?></p>
                            </div>
                        </div>
                    </div>
                    </div>
                </section>
            </block>
        </section>
        <?php
    }

    private function faq_icon(string $icon): string
    {
        return '<span class="wpopt-faq-row-icon">' . Graphic::icon($icon) . '</span>';
    }

    /**
     * This function renders the contents of the page associated with the menu
     * that invokes the render method. In the context of this plugin, this is the
     * menu class.
     */
    public function render_main(): void
    {
        if (wps_get_page_args('do_welcome')) {
            $this->render_welcome();
            return;
        }

        $this->enqueue_scripts();

        $dashboard_cache_dirty = false;

        if (isset($_POST['wpopt-cron-run'])) {

            if (UtilEnv::verify_nonce('wpopt-nonce')) {
                CronActions::run_event('wpopt-cron');
                $dashboard_cache_dirty = true;
            }
        }
        elseif (isset($_POST['wpopt-cron-reset'])) {

            if (UtilEnv::verify_nonce('wpopt-nonce')) {
                wps('wpopt')->cron->reset_status();
                $dashboard_cache_dirty = true;
            }
        }

        if ($dashboard_cache_dirty) {
            $this->clear_dashboard_cache();
        }

        settings_errors();
        $this->render_admin_app();
    }

    private function get_dashboard_view_model(): array
    {
        $cache_key = $this->dashboard_cache_key();
        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        $module_handler = wps('wpopt')->moduleHandler;
        $excluded_modules = array('cron', 'cloudflare', 'modules_handler', 'settings', 'tracking');
        $all_modules = $module_handler->get_modules(array('excepts' => $excluded_modules), false);
        $module_icons = $this->dashboard_module_icons();
        $active_modules_count = 0;
        $module_cards = array();

        foreach ($all_modules as $index => $module) {
            $module_slug = $module['slug'];
            $module_active = $module_handler->module_is_active($module_slug);

            if ($module_active) {
                $active_modules_count++;
            }

            if ($index >= 5) {
                continue;
            }

            $module_cards[] = array(
                'name'   => $module['name'],
                'slug'   => $module_slug,
                'url'    => $module_handler->module_has_scope($module, 'admin-page') ? wps_module_panel_url($module_slug) : wps_module_setting_url('wpopt', $module_slug),
                'active' => $module_active,
                'icon'   => $module_icons[$module_slug] ?? 'dashicons-admin-tools',
            );
        }

        $is_cron_running = wps('wpopt')->settings->get('cron.running', false);
        $tracking_enabled = wps('wpopt')->settings->get('tracking.errors', true) || wps('wpopt')->settings->get('tracking.usage', true);
        $persistent_cache = defined('WP_PERSISTENT_CACHE');
        $health_score = 68 + ($tracking_enabled ? 8 : 0) + ($persistent_cache ? 14 : 0) + ($is_cron_running ? 0 : 10);

        $view_model = array(
            'active_modules_count' => $active_modules_count,
            'is_cron_running'      => $is_cron_running,
            'tracking_enabled'     => $tracking_enabled,
            'persistent_cache'     => $persistent_cache,
            'server_load'          => UtilEnv::get_server_load(),
            'health_score'         => min(100, $health_score),
            'module_cards'         => $module_cards,
            'last_updated'         => date_i18n(get_option('time_format')),
        );

        set_transient($cache_key, $view_model, self::DASHBOARD_CACHE_TTL);

        return $view_model;
    }

    private function dashboard_cache_key(): string
    {
        return 'wpopt_dashboard_' . get_current_blog_id() . '_' . WPOPT_VERSION;
    }

    private function clear_dashboard_cache(): void
    {
        delete_transient($this->dashboard_cache_key());
    }

    private function dashboard_module_icons(): array
    {
        return array(
            'database'      => 'dashicons-database',
            'media'         => 'dashicons-format-gallery',
            'wp_customizer' => 'dashicons-admin-customizer',
            'wp_optimizer'  => 'dashicons-performance',
            'wp_security'   => 'dashicons-shield',
            'cache'         => 'dashicons-admin-network',
            'minify'        => 'dashicons-editor-contract',
            'activitylog'   => 'dashicons-list-view',
            'wp_mail'       => 'dashicons-email-alt',
            'wp_updates'    => 'dashicons-update',
            'wp_info'       => 'dashicons-info',
        );
    }

    private function render_welcome()
    {
        $this->enqueue_scripts();

        $modules = wps('wpopt')->moduleHandler->get_modules(array('excepts' => array('cron', 'cloudflare', 'modules_handler', 'settings', 'tracking')), false);
        ?>
        <section class="wps-wrap-flex wps-wrap wps-home wpopt-shell">
            <section class="wps wpopt-main">
                <block class="wps wpopt-hero">
                    <block class="wps-header">
                        <h1>Welcome to WP Optimizer</h1>
                    </block>
                    <p class="wpopt-hero-subtitle"><?php _e('Build a faster WordPress stack using modular optimization, automation, and targeted performance tools.', 'wpopt'); ?></p>
                    <div class="wpopt-actions">
                        <?php
                        echo sprintf(__('<a class="wps wps-button wpopt-btn is-info" href="%s">Open Dashboard</a>', 'wpopt'), admin_url('admin.php?page=wp-optimizer'));
                        echo sprintf(__('<a class="wps wps-button wpopt-btn is-info" href="%s">Manage Modules</a>', 'wpopt'), admin_url('admin.php?page=wpopt-settings#settings-modules_handler'));
                        ?>
                    </div>
                </block>
                <block class="wps">
                    <h2><?php _e('All available modules', 'wpopt'); ?></h2>
                    <p class="wpopt-muted"><?php _e('Activate only what you need to keep your stack lean and efficient.', 'wpopt'); ?></p>
                    <div class="wps-gridRow wpopt-chip-grid">
                        <?php
                        foreach ($modules as $module) {
                            echo "<span class='wps-code'>{$module['name']}</span>";
                        }
                        ?>
                    </div>
                </block>
                <block class="wps">
                    <h2><?php echo __('Try to explore this plugin:', 'wpopt'); ?></h2>
                    <div class="wpopt-actions">
                        <?php
                        echo sprintf(__('<a class="wps wps-button wpopt-btn is-info" href="%s">Home</a>', 'wpopt'), admin_url('admin.php?page=wp-optimizer'));
                        echo sprintf(__('<a class="wps wps-button wpopt-btn is-info" href="%s">Manage Modules</a>', 'wpopt'), admin_url('admin.php?page=wpopt-settings#settings-modules_handler'));
                        echo sprintf(__('<a class="wps wps-button wpopt-btn is-info" href="%s">Configure Modules</a>', 'wpopt'), wps_module_setting_url('wpopt'));
                        echo sprintf(__('<a class="wps wps-button wpopt-btn is-info" href="%s">FAQ</a>', 'wpopt'), admin_url('admin.php?page=wpopt-faqs'));
                        ?>
                    </div>
                </block>
            </section>
            <?php $this->render_sidebar(); ?>
        </section>
        <?php
    }

    private function render_dashboard_hero_art(): void
    {
        ?>
        <svg class="wpopt-hero-svg" xmlns="http://www.w3.org/2000/svg" width="362" height="117" viewBox="0 0 362 117" fill="none" role="img" aria-label="<?php esc_attr_e('Dashboard illustration with speedometer', 'wpopt'); ?>">
            <defs>
                <linearGradient id="outerBlue" x1="196" y1="40" x2="309" y2="96" gradientUnits="userSpaceOnUse">
                    <stop offset="0" stop-color="#79B4FF"/>
                    <stop offset="0.42" stop-color="#3E8DFF"/>
                    <stop offset="1" stop-color="#075FDD"/>
                </linearGradient>
                <linearGradient id="outerHighlight" x1="207" y1="43" x2="274" y2="39" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#A5CEFF" stop-opacity="0.75"/>
                    <stop offset="1" stop-color="#FFFFFF" stop-opacity="0"/>
                </linearGradient>
                <linearGradient id="innerBlue" x1="218" y1="89" x2="284" y2="57" gradientUnits="userSpaceOnUse">
                    <stop offset="0" stop-color="#83BAFF"/>
                    <stop offset="0.62" stop-color="#4C96FF"/>
                    <stop offset="1" stop-color="#287AF1"/>
                </linearGradient>
                <linearGradient id="needleBlue" x1="250" y1="86" x2="272" y2="76" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#2F85FF"/>
                    <stop offset="1" stop-color="#176FE8"/>
                </linearGradient>
                <linearGradient id="pieBlue" x1="142" y1="71" x2="156" y2="85" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#4D93FF"/>
                    <stop offset="1" stop-color="#0C65E5"/>
                </linearGradient>
                <radialGradient id="centerGlow" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(200 58) rotate(90) scale(76 137)">
                    <stop stop-color="#EAF3FF"/>
                    <stop offset="1" stop-color="#EAF3FF" stop-opacity="0"/>
                </radialGradient>
            </defs>

            <ellipse cx="91" cy="42" rx="38" ry="27" fill="#EAF3FF" opacity="0.88"/>
            <ellipse cx="108" cy="104" rx="76" ry="61" fill="#EAF3FF" opacity="0.76"/>
            <ellipse cx="197" cy="58" rx="72" ry="53" fill="#EAF3FF" opacity="0.80"/>
            <ellipse cx="265" cy="61" rx="42" ry="36" fill="#EAF3FF" opacity="0.72"/>
            <ellipse cx="264" cy="112" rx="67" ry="57" fill="#EAF3FF" opacity="0.68"/>
            <circle cx="35" cy="73" r="3.1" fill="#B8D2FF"/>
            <circle cx="96" cy="25" r="2" fill="#78AFFF"/>
            <circle cx="287" cy="7" r="1.4" fill="#83B7FF"/>
            <circle cx="295" cy="42" r="1.4" fill="#C5DAFF" opacity="0.9"/>
            <circle cx="324" cy="68" r="3.1" fill="#AFCBFF"/>

            <circle cx="194" cy="35" r="11" fill="#FFFFFF"/>
            <path d="M194 28.5L196.25 32.8L200.5 35L196.25 37.2L194 41.5L191.75 37.2L187.5 35L191.75 32.8L194 28.5Z" fill="#3A8BFF"/>
            <path d="M224 12.5L225.25 16.2L228.5 17.5L225.25 18.85L224 22.5L222.75 18.85L219.5 17.5L222.75 16.2L224 12.5Z" fill="#8EBBFF" opacity="0.82"/>
            <path d="M315 18.5L316.55 22.1L320 23.5L316.55 24.9L315 28.5L313.45 24.9L310 23.5L313.45 22.1L315 18.5Z" fill="#9DC4FF" opacity="0.9"/>

            <rect x="68" y="46" width="98" height="67" rx="10" fill="#D8E7FF" opacity="0.22"/>
            <rect x="66" y="41" width="100" height="70" rx="10" fill="#FFFFFF"/>
            <rect x="83" y="50" width="58" height="6" rx="3" fill="#E7F0FF"/>
            <rect x="83" y="66" width="53" height="4" rx="2" fill="#E7F0FF"/>
            <rect x="83" y="77" width="37" height="4" rx="2" fill="#E7F0FF"/>
            <rect x="83" y="88" width="48" height="4" rx="2" fill="#E7F0FF"/>
            <circle cx="142" cy="85" r="15" fill="#E6F0FF"/>
            <path d="M142 85V71C149.73 71 156 77.27 156 85H142Z" fill="url(#pieBlue)"/>

            <g id="tachimetro">
                <path d="M193.5 95.5A57.5 57.5 0 0 1 308.5 95.5" stroke="#D9E9FF" stroke-width="16" stroke-linecap="round" opacity="0.82"/>
                <path d="M193.5 95.5A57.5 57.5 0 0 1 308.5 95.5" stroke="#BFD9FF" stroke-width="10" stroke-linecap="round" opacity="0.22"/>
                <path d="M195 95.5A56 56 0 0 1 307 95.5" stroke="url(#outerBlue)" stroke-width="7.8" stroke-linecap="round"/>
                <path d="M202.4 72.2A56 56 0 0 1 278.7 44.6" stroke="url(#outerHighlight)" stroke-width="3.1" stroke-linecap="round" opacity="0.45"/>

                <path d="M216.5 90.5A34.5 34.5 0 0 1 285.5 90.5" stroke="#D9E9FF" stroke-width="11.4" stroke-linecap="round" opacity="0.84"/>
                <path d="M216.5 90.5A34.5 34.5 0 0 1 285.5 90.5" stroke="url(#innerBlue)" stroke-width="6.7" stroke-linecap="round"/>
            </g>

            <path d="M191 117C193.4 107 203.8 100.7 217.8 101.5C222.2 95.2 232.4 91 244.4 91C261.3 91 274.3 100.5 277.8 112.7C288.6 111.1 300.3 112.8 307.5 117H191Z" fill="#FFFFFF"/>

            <path d="M251 85.4L270.8 76.2" stroke="#D6E8FF" stroke-width="7.4" stroke-linecap="round" opacity="0.45"/>
            <path d="M251 85.4L270.8 76.2" stroke="url(#needleBlue)" stroke-width="5" stroke-linecap="round"/>
            <circle cx="251" cy="85.4" r="7.1" fill="#E8F2FF" opacity="0.55"/>
            <circle cx="251" cy="85.4" r="5.4" fill="#2E84FF"/>
        </svg>
        <?php
    }

    private function render_sidebar(): void
    {
        $donation_url = $this->get_donation_url();
        $review_url = $this->get_review_url();
        ?>
        <aside class="wps wpopt-sidebar">
            <section class="wps-box wpopt-support-card-primary">
                <div class="wps-donation-wrap">
                    <span class="wpopt-support-icon"><?php echo Graphic::icon('star', 'wpopt-support-icon-svg'); ?></span>
                    <div class="wps-donation-title"><?php _e('WP Optimizer saves you time', 'wpopt'); ?></div>
                    <p class="wpopt-muted"><?php _e('Support maintenance, fixes, and new features with a donation, or help more users discover the plugin with a 5-star review.', 'wpopt'); ?></p>
                    <div class="wpopt-inline-actions wpopt-support-cta">
                        <a class="wps wps-button wpopt-btn is-success" href="<?php echo esc_url($donation_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo Graphic::icon('heart-fill', 'wpopt-btn-icon'); ?><?php _e('Donate with PayPal', 'wpopt'); ?></a>
                        <a class="wps wps-button wpopt-btn is-neutral" href="<?php echo esc_url($review_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo Graphic::icon('star-outline', 'wpopt-btn-icon'); ?><?php _e('Leave a 5-star review', 'wpopt'); ?></a>
                    </div>
                </div>
            </section>
            <section class="wps-box">
                <h3><?php echo Graphic::icon('headphones', 'wpopt-sidebar-title-icon'); ?><?php _e('Need help?', 'wpopt'); ?></h3>
                <ul class="wps wpopt-link-list">
                    <li>
                        <a href="https://translate.wordpress.org/projects/wp-plugins/wp-optimizer/"><?php echo Graphic::icon('translate', 'wpopt-link-icon'); ?><?php _e('Help me translating', 'wpopt'); ?></a>
                    </li>
                </ul>
            </section>
            <section class="wps-box">
                <h3><?php echo Graphic::icon('box', 'wpopt-sidebar-title-icon'); ?>WP Optimizer</h3>
                <ul class="wps wpopt-link-list">
                    <li>
                        <a href="https://github.com/sh1zen/wp-optimizer/"><?php echo Graphic::icon('code', 'wpopt-link-icon'); ?><?php _e('Source code', 'wpopt'); ?></a>
                    </li>
                    <li>
                        <a href="https://sh1zen.github.io/"><?php echo Graphic::icon('user', 'wpopt-link-icon'); ?><?php _e('About me', 'wpopt'); ?></a>
                    </li>
                </ul>
            </section>
        </aside>
        <?php
    }

    private function render_admin_app(): void
    {
        $route = $this->get_app_route();
        $route_label = $this->get_app_route_label($route);

        Graphic::render_admin_app([
            'title'      => 'WP Optimizer',
            'page_title' => $route === 'dashboard' ? __('WP Optimizer Dashboard', 'wpopt') : $route_label,
            'version'    => 'v' . WPOPT_VERSION,
            'context'    => 'wpopt',
            'active'     => $route,
            'breadcrumb' => $route_label,
            'status'     => __('Healthy', 'wpopt'),
            'brand_icon' => 'gauge',
            'nav'        => $this->get_app_nav(),
            'help'       => '<strong>' . esc_html__('Need help?', 'wpopt') . '</strong><p><a href="https://wordpress.org/support/plugin/wp-optimizer/" target="_blank" rel="noopener noreferrer">' . esc_html__('Open support', 'wpopt') . '</a></p>',
            'content'    => function () use ($route) {
                $this->render_app_content($route);
            },
        ]);
    }

    private function get_app_route(): string
    {
        return isset($_GET['wps-page']) ? sanitize_key(wp_unslash($_GET['wps-page'])) : 'dashboard';
    }

    private function get_app_nav(): array
    {
        $tools = array();

        foreach (wps('wpopt')->moduleHandler->get_modules(array('scopes' => 'admin-page'), true) as $module) {
            $tools[] = array(
                'id'    => 'module-' . $module['slug'],
                'label' => $module['name'],
                'icon'  => $this->get_app_module_icon($module['slug']),
                'url'   => wps_module_panel_url($module['slug']),
            );
        }

        $settings_items = $this->get_settings_nav_items('wpopt');
        $nav = array(
            array(
                'label' => __('Overview', 'wpopt'),
                'items' => array_merge(
                    array(array('id' => 'dashboard', 'label' => __('Dashboard', 'wpopt'), 'icon' => 'gauge', 'url' => wps_admin_route_url('wpopt'))),
                    $this->get_core_settings_nav_items('wpopt'),
                    array(array('id' => 'faq', 'label' => __('FAQ', 'wpopt'), 'icon' => 'info', 'url' => wps_admin_route_url('wpopt', 'faq')))
                ),
            ),
        );

        if (!empty($settings_items)) {
            $nav[] = array(
                'label' => __('Settings', 'wpopt'),
                'items' => $settings_items,
            );
        }

        $nav[] = array(
            'label' => __('Tools', 'wpopt'),
            'items' => $tools,
        );

        return $nav;
    }

    private function get_app_route_label(string $route): string
    {
        $labels = array(
            'dashboard' => __('Dashboard', 'wpopt'),
            'faq'       => __('FAQ', 'wpopt'),
        );

        if (isset($labels[$route])) {
            return $labels[$route];
        }

        if (str_starts_with($route, 'module-setting-')) {
            $setting_id = substr($route, 15);

            foreach (wps('wpopt')->settings->get_module_setting_pages() as $page) {
                if ($page['id'] === $setting_id) {
                    return $page['label'];
                }
            }
        }

        if (str_starts_with($route, 'module-')) {
            $slug = substr($route, 7);

            foreach (wps('wpopt')->moduleHandler->get_modules(array('scopes' => 'admin-page'), true) as $module) {
                if ($module['slug'] === $slug) {
                    return $module['name'];
                }
            }
        }

        if (str_starts_with($route, 'setting-')) {
            $setting_id = substr($route, 8);

            foreach (wps('wpopt')->settings->get_core_setting_pages() as $page) {
                if ($page['id'] === $setting_id) {
                    return $page['label'];
                }
            }
        }

        return __('Dashboard', 'wpopt');
    }

    private function render_app_content(string $route): void
    {
        if ($route === 'core-settings') {
            $this->render_first_core_setting_page('wpopt');
            return;
        }

        if (str_starts_with($route, 'setting-')) {
            wps('wpopt')->settings->render_core_setting_page(substr($route, 8), false);
            return;
        }

        if (str_starts_with($route, 'module-setting-')) {
            wps('wpopt')->settings->render_module_setting_page(substr($route, 15), false);
            return;
        }

        if ($route === 'faq') {
            $this->render_legacy_app_panel(array($this, 'render_faqs'));
            return;
        }

        if (str_starts_with($route, 'module-')) {
            $object = wps('wpopt')->moduleHandler->get_module_instance(substr($route, 7));

            if ($object) {
                $this->render_legacy_app_panel(function () use ($object) {
                    $object->render_admin_page(false);
                });
                return;
            }
        }

        $this->render_app_dashboard();
    }

    private function render_app_dashboard(): void
    {
        $dashboard = $this->get_dashboard_view_model();
        $active_modules_count = $dashboard['active_modules_count'];
        $is_cron_running = $dashboard['is_cron_running'];
        $tracking_enabled = $dashboard['tracking_enabled'];
        $persistent_cache = $dashboard['persistent_cache'];
        $server_load = $dashboard['server_load'];
        $memory_load = wps_core()->meter->get_memory(true, true);
        $execution_time = wps_core()->meter->get_time('wp_start', 'now', 3);
        $health_score = $dashboard['health_score'];
        ?>
        <section class="wps-wrap wpopt-shell wpopt-dashboard-shell wpopt-app-dashboard">
            <div class="wpopt-app-dashboard-main">
                <block class="wps wpopt-hero wpopt-dashboard-hero">
                    <div class="wpopt-hero-copy">
                        <h2><?php esc_html_e('Optimize. Accelerate. Simplify.', 'wpopt'); ?></h2>
                        <p class="wpopt-hero-subtitle"><?php esc_html_e('Centralize your optimization workflow with quick actions, live metrics, and direct module access to keep your site fast and healthy.', 'wpopt'); ?></p>
                        <div class="wpopt-actions">
                            <a class="wps wps-button wpopt-btn is-info" href="<?php echo esc_url(wps_admin_route_url('wpopt', 'setting-modules_handler')); ?>">
                                <span class="dashicons dashicons-screenoptions"></span><?php esc_html_e('Manage Modules', 'wpopt'); ?>
                            </a>
                            <a class="wps wps-button wpopt-btn is-neutral" href="<?php echo esc_url(wps_module_setting_url('wpopt')); ?>">
                                <span class="dashicons dashicons-admin-generic"></span><?php esc_html_e('Configure Modules', 'wpopt'); ?>
                            </a>
                        </div>
                    </div>
                    <div class="wpopt-hero-art" aria-hidden="true">
                        <?php $this->render_dashboard_hero_art(); ?>
                    </div>
                </block>
                <block class="wps wpopt-panel wpopt-overview-panel">
                    <div class="wpopt-panel-head">
                        <h2><span class="dashicons dashicons-chart-line"></span><?php esc_html_e('System Overview', 'wpopt'); ?></h2>
                        <span><?php echo esc_html(sprintf(__('Last updated: %s', 'wpopt'), $dashboard['last_updated'])); ?></span>
                    </div>
                    <div class="wpopt-scroll-x" role="region" aria-label="<?php esc_attr_e('System overview cards', 'wpopt'); ?>">
                        <div class="wpopt-kpi-grid">
                            <div class="wpopt-kpi-card is-blue">
                                <span class="wpopt-kpi-icon dashicons dashicons-admin-plugins"></span>
                                <span class="wpopt-kpi-label"><?php esc_html_e('Active modules', 'wpopt'); ?></span>
                                <strong class="wpopt-kpi-value"><?php echo esc_html($active_modules_count); ?></strong>
                                <small><?php esc_html_e('All systems operational', 'wpopt'); ?></small>
                            </div>
                            <div class="wpopt-kpi-card is-green">
                                <span class="wpopt-kpi-icon dashicons dashicons-clock"></span>
                                <span class="wpopt-kpi-label"><?php esc_html_e('Cron status', 'wpopt'); ?></span>
                                <strong class="wpopt-kpi-value"><?php echo $is_cron_running ? esc_html__('Running', 'wpopt') : esc_html__('Idle', 'wpopt'); ?></strong>
                                <small><?php echo $is_cron_running ? esc_html__('Optimization job in progress', 'wpopt') : esc_html__('Background scheduler ready', 'wpopt'); ?></small>
                            </div>
                            <div class="wpopt-kpi-card is-purple">
                                <span class="wpopt-kpi-icon dashicons dashicons-chart-area"></span>
                                <span class="wpopt-kpi-label"><?php esc_html_e('Tracking', 'wpopt'); ?></span>
                                <strong class="wpopt-kpi-value"><?php echo $tracking_enabled ? esc_html__('Enabled', 'wpopt') : esc_html__('Disabled', 'wpopt'); ?></strong>
                                <small><?php echo $tracking_enabled ? esc_html__('Monitoring is active', 'wpopt') : esc_html__('Monitoring is disabled', 'wpopt'); ?></small>
                            </div>
                            <div class="wpopt-kpi-card is-orange">
                                <span class="wpopt-kpi-icon dashicons dashicons-database"></span>
                                <span class="wpopt-kpi-label"><?php esc_html_e('Persistent cache', 'wpopt'); ?></span>
                                <strong class="wpopt-kpi-value"><?php echo $persistent_cache ? esc_html__('Enabled', 'wpopt') : esc_html__('Not configured', 'wpopt'); ?></strong>
                                <small><?php echo $persistent_cache ? esc_html__('Persistent layer detected', 'wpopt') : esc_html__('Setup recommended', 'wpopt'); ?></small>
                            </div>
                            <div class="wpopt-kpi-card is-mint">
                                <span class="wpopt-kpi-icon dashicons dashicons-shield-alt"></span>
                                <span class="wpopt-kpi-label"><?php esc_html_e('Optimization health', 'wpopt'); ?></span>
                                <strong class="wpopt-kpi-value"><?php echo $health_score >= 80 ? esc_html__('Good', 'wpopt') : esc_html__('Needs review', 'wpopt'); ?></strong>
                                <small class="wpopt-health-meter"><span style="width: <?php echo esc_attr($health_score); ?>%"></span><b><?php echo esc_html($health_score); ?>%</b></small>
                            </div>
                        </div>
                    </div>
                </block>
                <block class="wps wpopt-panel wpopt-modules-panel">
                    <div class="wpopt-panel-head">
                        <h2><span class="dashicons dashicons-admin-plugins"></span><?php esc_html_e('Modules', 'wpopt'); ?></h2>
                        <span><?php esc_html_e('Manage and access your optimization tools', 'wpopt'); ?></span>
                    </div>
                    <div class="wpopt-module-grid">
                        <?php foreach ($dashboard['module_cards'] as $module_card) : ?>
                            <a class="wpopt-module-card" href="<?php echo esc_url($module_card['url']); ?>">
                                <span class="wpopt-module-icon dashicons <?php echo esc_attr($module_card['icon']); ?>"></span>
                                <strong><?php echo esc_html($module_card['name']); ?></strong>
                                <small><?php echo $module_card['active'] ? esc_html__('Ready for optimization tasks.', 'wpopt') : esc_html__('Module currently disabled.', 'wpopt'); ?></small>
                                <span class="wpopt-module-foot">
                                    <em class="<?php echo $module_card['active'] ? 'is-active' : 'is-inactive'; ?>"><?php echo $module_card['active'] ? esc_html__('Active', 'wpopt') : esc_html__('Inactive', 'wpopt'); ?></em>
                                    <span class="dashicons dashicons-arrow-right-alt2"></span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="wpopt-view-all">
                        <a href="<?php echo esc_url(wps_admin_route_url('wpopt', 'setting-modules_handler')); ?>">
                            <span class="dashicons dashicons-screenoptions"></span><?php esc_html_e('View all modules', 'wpopt'); ?>
                        </a>
                    </div>
                </block>
                <block class="wps wpopt-panel wpopt-tracking-panel">
                    <div class="wpopt-panel-head">
                        <h2><span class="dashicons dashicons-chart-area"></span><?php esc_html_e('Tracking status', 'wpopt'); ?></h2>
                        <div class="wpopt-tracking-actions">
                            <form method="POST" class="wpopt-actions-form">
                                <?php wp_nonce_field('wpopt-nonce'); ?>
                                <button name="wpopt-cron-run" type="submit" <?php echo $is_cron_running ? 'disabled' : ''; ?> class="wps wps-button wpopt-btn is-neutral">
                                    <span class="dashicons dashicons-controls-play"></span><?php esc_html_e('Run now', 'wpopt'); ?>
                                </button>
                                <?php if ($is_cron_running) : ?>
                                    <button name="wpopt-cron-reset" type="submit" class="wps wps-button wpopt-btn is-danger">
                                        <span class="dashicons dashicons-update"></span><?php esc_html_e('Reset cron', 'wpopt'); ?>
                                    </button>
                                <?php endif; ?>
                            </form>
                            <a class="wps wps-button wpopt-btn is-neutral" href="<?php echo esc_url(wps_admin_route_url('wpopt', 'setting-tracking')); ?>"><?php esc_html_e('View full report', 'wpopt'); ?></a>
                        </div>
                    </div>
                    <div class="wpopt-tracking-grid">
                        <div class="wpopt-tracking-stat">
                            <span><?php esc_html_e('Active modules', 'wpopt'); ?></span>
                            <strong><?php echo esc_html($active_modules_count); ?></strong>
                            <small><?php echo esc_html(sprintf('%s%%', $health_score)); ?></small>
                        </div>
                        <div class="wpopt-tracking-stat">
                            <span><?php esc_html_e('Memory peak', 'wpopt'); ?></span>
                            <strong><?php echo esc_html($memory_load); ?></strong>
                            <small><?php esc_html_e('live', 'wpopt'); ?></small>
                        </div>
                        <div class="wpopt-tracking-stat">
                            <span><?php esc_html_e('Server load', 'wpopt'); ?></span>
                            <strong><?php echo esc_html($server_load); ?></strong>
                            <small><?php esc_html_e('live', 'wpopt'); ?></small>
                        </div>
                        <div class="wpopt-tracking-stat">
                            <span><?php esc_html_e('Execution time', 'wpopt'); ?></span>
                            <strong><?php echo esc_html(sprintf('%s s', $execution_time)); ?></strong>
                            <small><?php esc_html_e('live', 'wpopt'); ?></small>
                        </div>
                        <svg class="wpopt-tracking-chart" viewBox="0 0 360 96" role="img" aria-label="<?php esc_attr_e('Weekly optimization trend', 'wpopt'); ?>">
                            <polyline points="4,58 48,43 92,68 136,54 180,54 224,64 268,51 356,51" fill="none" stroke="#2f7cf6" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M4 58 L48 43 L92 68 L136 54 L180 54 L224 64 L268 51 L356 51 L356 96 L4 96 Z" fill="rgba(47,124,246,0.10)"/>
                            <g fill="#2f7cf6">
                                <circle cx="4" cy="58" r="3"/><circle cx="48" cy="43" r="3"/><circle cx="92" cy="68" r="3"/><circle cx="136" cy="54" r="3"/>
                                <circle cx="180" cy="54" r="3"/><circle cx="224" cy="64" r="3"/><circle cx="268" cy="51" r="3"/><circle cx="356" cy="51" r="3"/>
                            </g>
                        </svg>
                    </div>
                </block>
                <?php if (!defined('WP_PERSISTENT_CACHE')) : ?>
                    <block class="wps wpopt-panel">
                        <h2><?php esc_html_e('Persistent cache:', 'wpopt'); ?></h2>
                        <p><?php _e('WP-Optimizer supports <b>Redis</b> and <b>Memcached</b> systems.', 'wpopt'); ?></p>
                        <p><?php _e('To activate persistent cache for your site copy this <b>define(\'WP_PERSISTENT_CACHE\', true);</b> in wp-config.php', 'wpopt'); ?></p>
                    </block>
                <?php endif; ?>
                <block class="wps wpopt-panel">
                    <h2><?php esc_html_e('WordPress performances:', 'wpopt'); ?></h2>
                    <div class="wpopt-metrics-grid">
                        <div class="wpopt-metric-item">
                            <span><?php esc_html_e('Server load', 'wpopt'); ?></span>
                            <strong><?php echo esc_html($server_load); ?></strong>
                        </div>
                        <div class="wpopt-metric-item">
                            <span><?php esc_html_e('WordPress memory load', 'wpopt'); ?></span>
                            <strong><?php echo esc_html($memory_load); ?></strong>
                        </div>
                        <div class="wpopt-metric-item">
                            <span><?php esc_html_e('WordPress execution time', 'wpopt'); ?></span>
                            <strong><?php echo esc_html(sprintf('%s s', $execution_time)); ?></strong>
                        </div>
                    </div>
                </block>
            </div>
            <?php $this->render_sidebar(); ?>
        </section>
        <?php
    }

    private function render_app_support_panel(): void
    {
        ?>
        <section class="wps-app-panel">
            <h1><?php esc_html_e('FAQ', 'wpopt'); ?></h1>
            <p><?php esc_html_e('Use the sidebar to move between dashboard, module settings, global settings and optimization tools.', 'wpopt'); ?></p>
            <p><a class="button button-primary" href="https://wordpress.org/support/plugin/wp-optimizer/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open support', 'wpopt'); ?></a></p>
        </section>
        <?php
    }

    private function get_app_module_icon(string $slug): string
    {
        $icons = array(
            'database'            => 'database',
            'media'               => 'image',
            'wp_customizer'       => 'sliders',
            'wp_optimizer'        => 'gauge',
            'wp_security'         => 'shield',
            'cache'               => 'server',
            'minify'              => 'tools',
            'activitylog'         => 'list',
            'wp_mail'             => 'mail',
            'wp_updates'          => 'repeat',
            'wp_info'             => 'info',
            'performance_monitor' => 'chart',
        );

        return $icons[$slug] ?? 'tools';
    }

    private function get_settings_nav_items(string $context): array
    {
        $items = array();

        foreach (wps($context)->settings->get_module_setting_pages() as $page) {
            $items[] = array(
                'id'    => 'module-setting-' . $page['id'],
                'label' => $page['label'],
                'icon'  => $page['icon'],
                'url'   => wps_admin_route_url($context, 'module-setting-' . $page['id']),
            );
        }

        return $items;
    }

    private function get_core_settings_nav_items(string $context): array
    {
        $items = array();

        foreach (wps($context)->settings->get_core_setting_pages() as $page) {
            $items[] = array(
                'id'    => 'setting-' . $page['id'],
                'label' => $page['label'],
                'icon'  => $page['icon'],
                'url'   => wps_admin_route_url($context, 'setting-' . $page['id']),
            );
        }

        return $items;
    }

    private function render_first_core_setting_page(string $context): void
    {
        $pages = wps($context)->settings->get_core_setting_pages();
        $page_id = $pages[0]['id'] ?? '';

        wps($context)->settings->render_core_setting_page($page_id, false);
    }

    private function render_first_module_setting_page(string $context): void
    {
        $pages = wps($context)->settings->get_module_setting_pages();
        $page_id = $pages[0]['id'] ?? '';

        wps($context)->settings->render_module_setting_page($page_id, false);
    }

    private function render_legacy_app_panel(callable $callback): void
    {
        ob_start();
        call_user_func($callback);
        $content = ob_get_clean();

        echo $content;
    }

    private function get_donation_url(): string
    {
        return 'https://www.paypal.com/donate?business=dev.sh1zen%40outlook.it&item_name=Thank+you+in+advanced+for+the+kind+donations.+You+will+sustain+me+developing+WP-Optimizer.&currency_code=EUR';
    }

    private function get_review_url(): string
    {
        return 'https://wordpress.org/support/plugin/wp-optimizer/reviews/?filter=5';
    }
}

