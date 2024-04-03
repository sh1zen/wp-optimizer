<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use WPS\core\Rewriter;
use WPS\core\UtilEnv;
use WPS\modules\Module;

/**
 * Module for updates handling
 */
class Mod_WP_Customizer extends Module
{
    public array $scopes = array('settings', 'autoload');

    protected string $context = 'wpopt';

    public function customize_after_init(): void
    {
        if ($this->option('admin-bar.hide-non-admin') and !current_user_can('administrator')) {
            add_filter('show_admin_bar', '__return_false');
        }

        if ($this->option('admin-panel.block-non-admin') and !current_user_can('administrator') and !wp_doing_ajax()) {

            add_action('admin_init', function () {
                if (headers_sent()) {
                    echo '<script>window.location.replace("' . wps_core()->home_url . $this->option('non-admin-redirect_to', '') . '");</script>';
                }
                else {
                    Rewriter::getInstance()->redirect($this->option('non-admin-redirect_to', '/') ?: '/', 302);
                }

                exit;
            });
        }
    }

    public function admin_bar_remove_items(): void
    {
        global $wp_admin_bar;

        if ($this->option('admin-bar-items.wp-logo')) {
            $wp_admin_bar->remove_menu('wp-logo');
        }

        if ($this->option('admin-hide-comments')) {
            $wp_admin_bar->remove_menu('comments');
        }

        if ($this->option('admin-bar-items.updates')) {
            $wp_admin_bar->remove_menu('updates');
        }
    }

    public function restricted_access($context = ''): bool
    {
        if ($context === 'settings') {
            return !current_user_can('manage_options');
        }

        return false;
    }

    protected function init(): void
    {
        $this->customize();

        add_action('init', [$this, 'customize_after_init']);
    }

    private function customize(): void
    {
        if ($this->option('blocks-editor')) {

            // theme related things
            remove_action('wp_enqueue_scripts', 'wp_enqueue_classic_theme_styles');
            remove_action('wp_enqueue_scripts', 'wp_common_block_scripts_and_styles');
            remove_filter('block_editor_settings_all', 'wp_add_editor_classic_theme_styles');

            remove_all_filters('use_block_editor_for_post');
            remove_all_filters('use_block_editor_for_post_type');

            add_filter('use_block_editor_for_post', '__return_false');
            add_filter('use_block_editor_for_post_type', '__return_false');

            add_action('wp_enqueue_scripts', function () {

                // Remove CSS on the front end.
                wp_dequeue_style('wp-block-library');

                // Remove Gutenberg theme.
                wp_dequeue_style('wp-block-library-theme');
            }, 100);

            remove_filter('the_content', 'do_blocks', 9);
            remove_filter('widget_block_content', 'do_blocks', 9);

            remove_filter('render_block_context', '_block_template_render_without_post_block_context');
            remove_filter('pre_wp_unique_post_slug', 'wp_filter_wp_template_unique_post_slug', 10, 5);
            remove_action('save_post_wp_template_part', 'wp_set_unique_slug_on_create_template_part');
            remove_action('wp_enqueue_scripts', 'wp_enqueue_block_template_skip_link');
            remove_action('wp_footer', 'the_block_template_skip_link');
            remove_action('after_setup_theme', 'wp_enable_block_templates', 1);
            remove_action('wp_loaded', '_add_template_loader_filters');

            // Footnotes Block.
            remove_action('init', '_wp_footnotes_kses_init');
            remove_action('set_current_user', '_wp_footnotes_kses_init');
            remove_filter('force_filtered_html_on_import', '_wp_footnotes_force_filtered_html_on_import_filter', 999);

            add_filter('use_widgets_block_editor', '__return_false');
        }

        if ($this->option('core-blocks')) {
            wps_remove_actions('init', 'register_block_core_');
        }

        if ($this->option('fonts-management')) {
            remove_action('wp_head', 'wp_print_font_faces', 50);
            remove_action('deleted_post', '_wp_after_delete_font_family', 10);
            remove_action('before_delete_post', '_wp_before_delete_font_face', 10);
            remove_action('init', '_wp_register_default_font_collections');
        }

        if ($this->option('global-style-disable')) {

            // disable global styles
            remove_action('wp_enqueue_scripts', 'wp_enqueue_global_styles');
            remove_action('wp_footer', 'wp_enqueue_global_styles', 1);
        }

        if ($this->option('core-sitemap')) {

            remove_all_filters('wp_sitemaps_enabled');
            add_filter('wp_sitemaps_enabled', '__return_false');
            remove_action('init', 'wp_sitemaps_get_server');
        }

        if ($this->option('category-filter') and is_admin()) {

            $screen_base = Rewriter::getInstance()->get_basename('.php');

            if ('post' === $screen_base or 'edit' === $screen_base) {

                add_action('admin_enqueue_scripts', function () use ($screen_base) {
                    wp_enqueue_script('wpopt-admin-script', UtilEnv::path_to_url(__DIR__, true) . '/supporters/filter-categories.js', array('jquery'), WPOPT_VERSION, true);
                    wp_localize_script('wpopt-admin-script', 'fc_plugin', array(
                        'placeholder' => esc_html__('Filter %s', 'wpopt'),
                        'screenName'  => $screen_base
                    ));
                });
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

        // disable feed links
        if ($this->option('disable.feed-links')) {
            remove_action('wp_head', 'feed_links', 2);
            remove_action('wp_head', 'feed_links_extra', 3);
        }

        // disable xml rpc
        if ($this->option('disable.xmlrpc')) {

            // Disable use XML-RPC
            add_filter('xmlrpc_enabled', '__return_false');
            remove_action('wp_head', 'rsd_link');
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

        if ($this->option('disable.oembed_and_rest')) {

            add_filter('rest_authentication_errors', function () {
                return new \WP_Error('rest_disabled', __('The REST API has been disabled on this site.', 'wpopt'), array('status' => 403));
            });

            // Remove the REST API lines from the HTML Header
            remove_action('wp_head', 'rest_output_link_wp_head', 10);
            remove_action('wp_head', 'wp_oembed_add_discovery_links', 10);

            //  remove_action('init', 'rest_api_init');
            remove_action('rest_api_init', 'rest_api_default_filters', 10);
            remove_action('rest_api_init', 'register_initial_settings', 10);
            remove_action('rest_api_init', 'create_initial_rest_routes', 99);
            remove_action('rest_api_init', 'wp_oembed_register_route');

            // Turn off oEmbed auto discovery.
            add_filter('embed_oembed_discover', '__return_false');

            // Don't filter oEmbed results.
            remove_filter('oembed_dataparse', 'wp_filter_oembed_result', 10);
            remove_filter('oembed_dataparse', 'wp_filter_oembed_iframe_title_attribute', 5);
            remove_filter('oembed_response_data', 'get_oembed_response_data_rich', 10);
            remove_filter('pre_oembed_result', 'wp_filter_pre_oembed_result', 10);
            remove_filter('embed_oembed_html', 'wp_maybe_enqueue_oembed_host_js');

            // Remove oEmbed-specific JavaScript from the front-end and back-end.
            remove_action('wp_head', 'wp_oembed_add_host_js');

            remove_action('template_redirect', 'rest_output_link_header', 11);
        }

        if ($this->option('disable.post_relational_links')) {
            remove_action('wp_head', 'start_post_rel_link');
            remove_action('wp_head', 'parent_post_rel_link');
            remove_action('wp_head', 'index_rel_link');
            remove_action('wp_head', 'adjacent_posts_rel_link');
        }
    }

    protected function setting_fields($filter = ''): array
    {
        return $this->group_setting_fields(

            $this->group_setting_fields(
                $this->setting_field(__('Dashboard', 'wpopt'), false, 'separator'),
                $this->setting_field(__('Disable Dashboard Welcome Panel', 'wpopt'), "welcome-panel", "checkbox", ['default_value' => true]),
                $this->setting_field(__('Disable Dashboard WordPress Blog', 'wpopt'), "dashboard.wpblog", "checkbox", ['default_value' => true]),
                $this->setting_field(__('Disable Dashboard WordPress Quick Press', 'wpopt'), "dashboard.quickpress", "checkbox", ['default_value' => true]),
            ),
            $this->group_setting_fields(
                $this->setting_field(__('Admin', 'wpopt'), false, 'separator'),
                $this->setting_field(__('Hide Admin Bar', 'wpopt'), "admin-bar.hide", "checkbox"),
                $this->setting_field(__('Hide Admin Bar for non admins', 'wpopt'), "admin-bar.hide-non-admin", "checkbox", ['default_value' => true]),
                $this->setting_field(__('Prevent access to admin dashboard for non admins', 'wpopt'), "admin-panel.block-non-admin", "checkbox"),
                $this->setting_field(__('Redirect to', 'wpopt'), "non-admin-redirect_to", "text", ['parent' => 'admin-panel.block-non-admin', 'default_value' => '/']),
                $this->setting_field(__('Remove WP logo', 'wpopt'), "admin-bar-items.wp-logo", "checkbox"),
                $this->setting_field(__('Remove updates shortcut', 'wpopt'), "admin-bar-items.updates", "checkbox", ['default_value' => true]),
            ),
            $this->group_setting_fields(
                $this->setting_field(__('Page Editor', 'wpopt'), false, 'separator'),
                $this->setting_field(__('Add a selection filter in category list', 'wpopt'), 'category-filter', 'checkbox'),
                $this->setting_field(__('Disable Block Editor (Gutenberg)', 'wpopt'), "blocks-editor", "checkbox"),
                $this->setting_field(__('Disable Core Blocks', 'wpopt'), "core-blocks", "checkbox", ['parent' => 'blocks-editor']),
                $this->setting_field(__('Disable Fonts Management', 'wpopt'), "fonts-management", "checkbox", ['parent' => 'blocks-editor']),
                $this->setting_field(__('Disable Auto Paragraph', 'wpopt'), "wpautop", "checkbox"),
            ),
            $this->group_setting_fields(
                $this->setting_field(__('Theme', 'wpopt'), false, 'separator'),
                $this->setting_field(__('Disable Global-Style', 'wpopt'), "global-style-disable", "checkbox"),
                $this->setting_field(__('Disable theme widgets', 'wpopt'), "widgets-disable", "checkbox"),
                $this->setting_field(__('Disable custom css', 'wpopt'), "disable.custom_css_cb", "checkbox"),
            ),
            $this->group_setting_fields(
                $this->setting_field(__('Comments', 'wpopt'), false, 'separator'),
                $this->setting_field(__("Disable comments", 'wpopt'), "disable-comments", "checkbox"),
                $this->setting_field(__("Hide comments shortcuts from admin pages", 'wpopt'), "admin-hide-comments", "checkbox"),
            ),
            $this->group_setting_fields(
                $this->setting_field(__('Link Tags Feed WP-JSON', 'wpopt'), false, 'separator'),
                $this->setting_field(__('Disable DNS prefetch', 'wpopt'), "disable.dns-prefetch", "checkbox"),
                $this->setting_field(__('Disable short-link generator', 'wpopt'), "disable.shortlink", "checkbox", ['default_value' => true]),
                $this->setting_field(__('Disable Feed links', 'wpopt'), "disable.feed-links", "checkbox"),
                $this->setting_field(__('Disable WP-JSON API and oembed', 'wpopt'), "disable.oembed_and_rest", "checkbox"),
                $this->setting_field(__('Disable Post Relational Links', 'wpopt'), "disable.post_relational_links", "checkbox"),
                $this->setting_field(__('Disable XML-RPC', 'wpopt'), "disable.xmlrpc", "checkbox"),
            ),
            $this->group_setting_fields(
                $this->setting_field(__('Miscellanea', 'wpopt'), false, 'separator'),
                $this->setting_field(__('Disable WordPress sitemap', 'wpopt'), "core-sitemap", "checkbox"),
                $this->setting_field(__('Disable pings', 'wpopt'), "ping", "checkbox"),
                $this->setting_field(__('Disable Self ping', 'wpopt'), "selfping", "checkbox", ['default_value' => true]),
                $this->setting_field(__('Disable WordPress Emoji support', 'wpopt'), "emoji", "checkbox"),
                $this->setting_field(__('Disable jQuery Migrate', 'wpopt'), "jquery-migrate", "checkbox"),
            )
        );
    }

    protected function infos(): array
    {
        return [
            'global-style-disable'  => __('If your theme does not support blocks, this feature is safe to disable.', 'wpopt'),
            'disable.dns-prefetch'  => __("WordPress DNS prefetch, preloads domain name system (DNS) information for external resources, reducing the time it takes to connect to them. If no external resources is used it's possible to disable it.", 'wpopt'),
            'disable.shortlink'     => __("WordPress short-link generator creates shortened URLs for posts, pages or custom post types. In most cases not necessary.", 'wpopt'),
            'disable.custom_css_cb' => __("WordPress Custom CSS is a feature that allows users to add their own custom styles to a WordPress website, without modifying the theme files.", 'wpopt'),
            'jquery-migrate'        => __("WordPress jQuery Migrate is a plugin that restores deprecated jQuery features removed in newer versions, ensuring compatibility with older themes and plugins.", 'wpopt')
        ];
    }
}

return __NAMESPACE__;