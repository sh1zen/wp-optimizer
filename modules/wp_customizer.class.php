<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use SHZN\core\Rewriter;
use SHZN\core\UtilEnv;
use SHZN\modules\Module;

/**
 * Module for updates handling
 */
class Mod_WP_Customizer extends Module
{
    public $scopes = array('settings', 'autoload');

    public function __construct()
    {
        parent::__construct('wpopt');

        $this->customize();

        add_action('init', [$this, 'customize_after_init']);
    }

    private function customize()
    {
        if ($this->option('blocks-editor')) {
            remove_filter('the_content', 'do_blocks');

            remove_all_filters('use_block_editor_for_post');
            remove_all_filters('use_block_editor_for_post_type');

            remove_action('setup_theme', 'wp_enable_block_templates');

            // Disable Gutenberg on the back end.
            add_filter('use_block_editor_for_post', '__return_false');

            // Disable Gutenberg for widgets.
            add_filter('use_widgets_blog_editor', '__return_false');

            add_filter('use_block_editor_for_post_type', '__return_false');

            add_action('wp_enqueue_scripts', function () {

                // Remove CSS on the front end.
                wp_dequeue_style('wp-block-library');

                // Remove Gutenberg theme.
                wp_dequeue_style('wp-block-library-theme');

                // Remove inline global CSS on the front end.
                wp_dequeue_style('global-styles');

            }, 100);
        }

        if ($this->option('core-blocks')) {
            remove_action('init', 'register_block_core_archives');
            remove_action('init', 'register_block_core_block');
            remove_action('init', 'register_block_core_calendar');
            remove_action('init', 'register_block_core_categories');
            remove_action('init', 'register_block_core_latest_comments');
            remove_action('init', 'register_block_core_latest_posts');
            remove_action('init', 'register_block_core_rss');
            remove_action('init', 'register_block_core_search');
            remove_action('init', 'register_block_core_shortcode');
            remove_action('init', 'register_block_core_social_link');
            remove_action('init', 'register_block_core_tag_cloud');
            remove_action('init', 'register_core_block_types_from_metadata');
        }

        if ($this->option('theme-block-disable')) {

            remove_action('wp_enqueue_scripts', 'wp_enqueue_classic_theme_styles');
            remove_action('plugins_loaded', '_wp_theme_json_webfonts_handler');
            remove_action('setup_theme', 'wp_enable_block_templates');
            remove_action('wp_loaded', '_add_template_loader_filters');
            remove_action('wp_enqueue_scripts', 'wp_common_block_scripts_and_styles');
            remove_filter('block_editor_settings_all', 'wp_add_editor_classic_theme_styles');

            // disable global styles
            remove_action('wp_enqueue_scripts', 'wp_enqueue_global_styles');
            remove_action('wp_footer', 'wp_enqueue_global_styles', 1);
        }

        if ($this->option('core-sitemap')) {

            remove_all_filters('wp_sitemaps_enabled');
            add_filter('wp_sitemaps_enabled', '__return_false');
            remove_action('init', 'wp_sitemaps_get_server');
        }

        if ($this->option('category-filter')) {

            $screen_base = Rewriter::getInstance()->get_base('.php');

            if ('post' === $screen_base or 'edit' === $screen_base) {
                wp_enqueue_script('wpopt-admin-script', UtilEnv::path_to_url(__DIR__, true) . '/supporters/filter-categories.js', array('jquery'), WPOPT_VERSION, true);
                wp_localize_script('wpopt-admin-script', 'fc_plugin', array(
                    'placeholder' => esc_html__('Filter %s', 'wpopt'),
                    'screenName'  => $screen_base
                ));
            }
        }

        if ($this->option('emoji')) {

            remove_action('wp_head', 'print_emoji_detection_script', 7);
            remove_action('admin_print_scripts', 'print_emoji_detection_script');
            remove_filter('comment_text_rss', 'wp_staticize_emoji');
            remove_filter('the_content_feed', 'wp_staticize_emoji');
            remove_action('wp_print_styles', 'print_emoji_styles');
            remove_action('admin_print_styles', 'print_emoji_styles');
            remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
            remove_action('embed_head', 'print_emoji_detection_script');

            remove_action('init', 'smilies_init', 5);

            // Remove from TinyMCE
            add_filter('tiny_mce_plugins',
                function ($plugins) {
                    return is_array($plugins) ? array_diff($plugins, array('wpemoji')) : array();
                }
            );
        }

        if ($this->option('wpautop')) {
            remove_filter('the_content', 'wpautop');
            remove_filter('the_excerpt', 'wpautop');
        }

        if ($this->option('ping')) {

            remove_all_filters('pings_open');
            add_filter('pings_open', '__return_false', 20, 2);

            remove_all_actions('do_pings');
            remove_all_actions('do_all_pings');
        }

        if ($this->option('selfping')) {

            add_action('pre_ping', function (&$links) {

                $home = get_option('home');

                foreach ($links as $l => $link) {
                    if (str_starts_with($link, $home)) {
                        unset($links[$l]);
                    }
                }
            });
        }

        if ($this->option('feed-links')) {
            remove_action('wp_head', 'feed_links', 2);
            remove_action('wp_head', 'feed_links_extra', 3);
        }

        if ($this->option('admin-bar.hide')) {
            add_filter('show_admin_bar', '__return_false');
        }

        if ($this->option('widgets-disable')) {
            remove_all_actions('widgets_init');

            remove_action('init', 'wp_widgets_init', 1);
            remove_action('after_setup_theme', 'wp_setup_widgets_block_editor', 1);
            remove_action('plugins_loaded', 'wp_maybe_load_widgets', 0);
        }

        /**
         * Dashboard related
         */
        if ($this->option('welcome-panel')) {
            remove_all_actions('welcome_panel');
        }

        if ($this->option('dashboard') or $this->option('admin-hide-comments')) {

            add_action('wp_dashboard_setup', function () {

                if ($this->option('admin-hide-comments')) {
                    // Remove comments metabox from dashboard
                    remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
                }

                if ($this->option('dashboard.wpblog')) {
                    // Remove WordPress Blog Widget
                    remove_meta_box('dashboard_primary', 'dashboard', 'side');
                    // Remove Other WordPress News Widget
                    remove_meta_box('dashboard_secondary', 'dashboard', 'side');
                }

                if ($this->option('dashboard.quickpress')) {
                    // Remove Quick Press Widget
                    remove_meta_box('dashboard_quick_press', 'dashboard', 'side');
                }
            });
        }

        if ($this->option('jquery-migrate')) {

            add_action('wp_default_scripts', function ($scripts) {

                if (!is_admin() and isset($scripts->registered['jquery'])) {
                    $script = $scripts->registered['jquery'];

                    if ($script->deps) {
                        // Check whether the script has any dependencies
                        $script->deps = array_diff($script->deps, array('jquery-migrate'));
                    }
                }
            });
        }

        if ($this->option('admin-bar-items')) {
            add_action('wp_before_admin_bar_render', array($this, 'admin_bar_remove_items'), 0);
        }

        /**
         * comments related
         */
        if ($this->option('disable-comments')) {

            // Close comments on the front-end
            add_filter('comments_open', '__return_false', 20, 0);
            add_filter('pings_open', '__return_false', 20, 0);

            // Hide existing comments
            add_filter('comments_array', '__return_empty_array', 10, 0);

            add_action('admin_init', function () {

                // Disable support for comments and trackbacks in post types
                foreach (get_post_types() as $post_type) {
                    if (post_type_supports($post_type, 'comments')) {
                        remove_post_type_support($post_type, 'comments');
                        remove_post_type_support($post_type, 'trackbacks');
                    }
                }
            });
        }

        if ($this->option('admin-hide-comments')) {

            // Remove comments page in menu
            add_action('admin_menu', function () {
                remove_menu_page('edit-comments.php');
            });
        }

        if ($this->option('disable.custom_css_cb')) {
            remove_action('wp_head', 'wp_custom_css_cb', 101);
        }

        if ($this->option('disable.dns-prefetch')) {
            remove_action('wp_head', 'wp_resource_hints', 2);
            remove_filter('login_head', 'wp_resource_hints', 8);
        }

        if ($this->option('disable.shortlink')) {
            remove_action('template_redirect', 'wp_shortlink_header', 11);
            remove_action('wp_head', 'wp_shortlink_wp_head', 10);
        }
    }

    public function customize_after_init()
    {
        if ($this->option('admin-bar.hide-non-admin') and !current_user_can('administrator')) {
            add_filter('show_admin_bar', '__return_false');
        }

        if ($this->option('admin-panel.block-non-admin') and !current_user_can('administrator') and !wp_doing_ajax()) {

            add_action('admin_init', function () {
                if (headers_sent()) {
                    echo '<script>window.location.replace("' . shzn()->utility->home_url . $this->option('non-admin-redirect_to', '') . '");</script>';
                }
                else {
                    wp_redirect(shzn()->utility->home_url . $this->option('non-admin-redirect_to', ''), 302);
                }

                exit;
            });
        }
    }

    public function admin_bar_remove_items()
    {
        global $wp_admin_bar;

        if ($this->option('admin-bar-items.wp-logo')) {
            $wp_admin_bar->remove_menu('wp-logo');
        }

        if ($this->option('admin-bar-items.comments') or $this->option('admin-hide-comments')) {
            $wp_admin_bar->remove_menu('comments');
        }

        if ($this->option('admin-bar-items.updates')) {
            $wp_admin_bar->remove_menu('updates');
        }
    }

    public function restricted_access($context = '')
    {
        if ($context === 'settings') {
            return !current_user_can('manage_options');
        }

        return false;
    }

    protected function setting_fields($filter = '')
    {
        return $this->group_setting_fields(

            $this->setting_field(__('Dashboard', 'wpopt'), false, 'separator'),
            $this->setting_field(__('Disable Dashboard Welcome Panel', 'wpopt'), "welcome-panel", "checkbox"),
            $this->setting_field(__('Disable Dashboard WordPress Blog', 'wpopt'), "dashboard.wpblog", "checkbox"),
            $this->setting_field(__('Disable Dashboard WordPress Quick Press', 'wpopt'), "dashboard.quickpress", "checkbox"),

            $this->setting_field(__('Admin Bar', 'wpopt'), false, 'separator'),
            $this->setting_field(__('Hide Admin Bar', 'wpopt'), "admin-bar.hide", "checkbox"),
            $this->setting_field(__('Hide Admin Bar for non admins', 'wpopt'), "admin-bar.hide-non-admin", "checkbox"),
            $this->setting_field(__('Prevent access to admin panel for non admins', 'wpopt'), "admin-panel.block-non-admin", "checkbox"),
            $this->setting_field(__('Redirect to', 'wpopt'), "non-admin-redirect_to", "text", ['parent' => 'admin-panel.block-non-admin']),
            $this->setting_field(__('Remove WP logo', 'wpopt'), "admin-bar-items.wp-logo", "checkbox"),
            $this->setting_field(__('Remove comments shortcut', 'wpopt'), "admin-bar-items.comments", "checkbox"),
            $this->setting_field(__('Remove updates shortcut', 'wpopt'), "admin-bar-items.updates", "checkbox"),

            $this->setting_field(__('Page Editor - SEO', 'wpopt'), false, 'separator'),
            $this->setting_field(__('Add a selection filter in category list', 'wpopt'), 'category-filter', 'checkbox'),
            $this->setting_field(__('Disable Block Editor (Gutenberg)', 'wpopt'), "blocks-editor", "checkbox"),
            $this->setting_field(__('Disable Core Blocks', 'wpopt'), "core-blocks", "checkbox"),
            $this->setting_field(__('Disable Auto Paragraph', 'wpopt'), "wpautop", "checkbox"),

            $this->setting_field(__('Theme', 'wpopt'), false, 'separator'),
            $this->setting_field(__('Disable theme blocks and global style', 'wpopt'), "theme-block-disable", "checkbox"),
            $this->setting_field(__('Disable theme widgets', 'wpopt'), "widgets-disable", "checkbox"),
            $this->setting_field(__('Disable custom css', 'wpopt'), "disable.custom_css_cb", "checkbox"),

            $this->setting_field(__('Comments', 'wpopt'), false, 'separator'),
            $this->setting_field(__("Disable comments", 'wpopt'), "disable-comments", "checkbox"),
            $this->setting_field(__("Hide comments shortcuts from admin pages", 'wpopt'), "admin-hide-comments", "checkbox"),

            $this->setting_field(__('Tips useful for speed up', 'wpopt'), false, 'separator'),
            $this->setting_field(__('Disable WordPress sitemap', 'wpopt'), "core-sitemap", "checkbox"),
            $this->setting_field(__('Disable DNS prefetch', 'wpopt'), "disable.dns-prefetch", "checkbox"),
            $this->setting_field(__('Disable short-link generator', 'wpopt'), "disable.shortlink", "checkbox"),
            $this->setting_field(__('Disable pings', 'wpopt'), "ping", "checkbox"),
            $this->setting_field(__('Disable Self ping', 'wpopt'), "selfping", "checkbox"),
            $this->setting_field(__('Disable WordPress Emoji support', 'wpopt'), "emoji", "checkbox"),
            $this->setting_field(__('Disable Feed links', 'wpopt'), "feed-links", "checkbox"),
            $this->setting_field(__('Disable jQuery Migrate', 'wpopt'), "jquery-migrate", "checkbox"),
        );
    }

    protected function infos()
    {
        return [
            'theme-block-disable'   => __('If your theme does not support blocks, this feature just slow down your site, safe to disable.', 'wpopt'),
            'disable.dns-prefetch'  => __("WordPress DNS prefetch, preloads domain name system (DNS) information for external resources, reducing the time it takes to connect to them. If no external resources is used it's possible to disable it.", 'wpopt'),
            'disable.shortlink'     => __("WordPress short-link generator creates shortened URLs for posts, pages or custom post types. In most cases not necessary.", 'wpopt'),
            'disable.custom_css_cb' => __("WordPress Custom CSS is a feature that allows users to add their own custom styles to a WordPress website, without modifying the theme files.", 'wpopt'),
            'jquery-migrate'        => __("WordPress jQuery Migrate is a plugin that restores deprecated jQuery features removed in newer versions, ensuring compatibility with older themes and plugins.", 'wpopt')
        ];
    }
}

return __NAMESPACE__;