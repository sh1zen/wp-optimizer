<?php

namespace WPOptimizer\modules;

/**
 * Module for updates handling
 */
class Mod_WP_Customizer extends Module
{
    public $scopes = array('settings', 'autoload');

    public function __construct()
    {
        parent::__construct();

        $this->customize();
    }

    public function customize()
    {
        if ($this->option('blocks-editor')) {
            remove_filter('the_content', 'do_blocks');

            remove_all_filters('use_block_editor_for_post');
            remove_all_filters('use_block_editor_for_post_type');

            add_filter('use_block_editor_for_post', '__return_false');
            add_filter('use_block_editor_for_post_type', '__return_false');

            add_action('wp_print_styles', function () {
                wp_deregister_style('wp-block-library');
                wp_dequeue_style('wp-block-library');
            });
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

        if ($this->option('welcome-panel')) {
            remove_all_actions('welcome_panel');
        }

        if ($this->option('core-sitemap')) {

            remove_all_filters('wp_sitemaps_enabled');

            add_filter('wp_sitemaps_enabled', '__return_false');
            remove_action('init', 'wp_sitemaps_get_server');
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

            remove_action('init', 'smilies_init');

            // Remove from TinyMCE
            add_filter('tiny_mce_plugins',
                function ($plugins) {
                    if (is_array($plugins)) {
                        return array_diff($plugins, array('wpemoji'));
                    }
                    else {
                        return array();
                    }
                }
            );
        }

        if ($this->option('wpautop')) {
            remove_filter('the_content', 'wpautop');
            remove_filter('the_excerpt', 'wpautop');
        }

        if ($this->option('feed-links')) {
            remove_action('wp_head', 'feed_links', 2);
            remove_action('wp_head', 'feed_links_extra', 3);
        }

        if ($this->option('admin-bar')) {
            add_filter('show_admin_bar', '__return_false');
        }

        if ($this->option('admin-bar-non-admins') and !current_user_can('manage_options')) {
            add_filter('show_admin_bar', '__return_false');
        }

        if ($this->option('wp-blog-panel')) {
            /*
            $base = array(
                'quick_press' => 'side',
                'primary'     => 'side',
            );
            foreach ($base as $mb => $place)
                remove_meta_box("dashboard_$mb", 'dashboard', $place);
*/

            add_action('wp_dashboard_setup', function () {
                remove_meta_box('dashboard_primary', get_current_screen(), 'side');
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
    }

    public function admin_bar_remove_items()
    {
        global $wp_admin_bar;

        if ($this->option('admin-bar-items.wp-logo')) {
            $wp_admin_bar->remove_menu('wp-logo');
        }


        if ($this->option('admin-bar-items.comments')) {
            $wp_admin_bar->remove_menu('comments');
        }

        if ($this->option('admin-bar-items.updates')) {
            $wp_admin_bar->remove_menu('updates');
        }
    }

    public function restricted_access($context = '')
    {
        if ($context === 'settings')
            return !current_user_can('manage_options');

        return false;
    }

    protected function setting_fields()
    {
        return $this->group_setting_fields(

            $this->setting_field(__('Dashboard', 'wpopt'), false, 'separator'),
            $this->setting_field(__('Disable Dashboard Welcome Panel', 'wpopt'), "welcome-panel", "checkbox"),
            $this->setting_field(__('Disable Dashboard WordPress Blog', 'wpopt'), "wp-blog-panel", "checkbox"),

            $this->setting_field(__('Admin Bar', 'wpopt'), false, 'separator'),
            $this->setting_field(__('Hide Admin Bar', 'wpopt'), "admin-bar-hide", "checkbox"),
            $this->setting_field(__('Hide Admin Bar for non admins', 'wpopt'), "admin-bar-non-admin", "checkbox"),
            $this->setting_field(__('Remove WP logo', 'wpopt'), "admin-bar-items.wp-logo", "checkbox"),
            $this->setting_field(__('Remove comments shortcut', 'wpopt'), "admin-bar-items.comments", "checkbox"),
            $this->setting_field(__('Remove updates shortcut', 'wpopt'), "admin-bar-items.updates", "checkbox"),

            $this->setting_field(__('PageEditor - SEO', 'wpopt'), false, 'separator'),
            $this->setting_field(__('Disable Block Editor (Gutenberg)', 'wpopt'), "blocks-editor", "checkbox"),
            $this->setting_field(__('Disable Core Blocks', 'wpopt'), "core-blocks", "checkbox"),
            $this->setting_field(__('Disable Auto Paragraph', 'wpopt'), "wpautop", "checkbox"),
            $this->setting_field(__('Disable Core Sitemap', 'wpopt'), "core-sitemap", "checkbox"),

            $this->setting_field(__('Miscellaneous', 'wpopt'), false, 'separator'),
            $this->setting_field(__('Disable Emoji', 'wpopt'), "emoji", "checkbox"),
            $this->setting_field(__('Disable Feed links', 'wpopt'), "feed-links", "checkbox"),
            $this->setting_field(__('Disable jQuery Migrate', 'wpopt'), "jquery-migrate", "checkbox")
        );
    }
}