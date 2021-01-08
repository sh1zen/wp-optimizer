<?php

/**
 * Module for updates handling
 */
class WOMod_WP_Customizer extends WOModule
{
    public $scopes = array('settings', 'autoload');

    public function __construct()
    {
        parent::__construct();

        add_action('init', array($this, 'customize'));
    }

    public function customize()
    {
        if ($this->option( 'blocks-editor')) {
            remove_filter('the_content', 'do_blocks');

            add_filter('use_block_editor_for_post', '__return_false');
            add_filter('use_block_editor_for_post_type', '__return_false');
        }

        if ($this->option( 'core-blocks')) {
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

        if ($this->option( 'welcome-panel')) {
            remove_all_actions('welcome_panel');
        }

        if ($this->option( 'core-sitemap')) {
            remove_all_actions('welcome_panel');
        }

        if ($this->option( 'emoji')) {
            remove_action('wp_head', 'print_emoji_detection_script');
            remove_action('admin_print_scripts', 'print_emoji_detection_script');

            remove_filter('comment_text_rss', 'wp_staticize_emoji');
            remove_filter('the_content_feed', 'wp_staticize_emoji');

            remove_action('wp_print_styles', 'print_emoji_styles');
            remove_action('admin_print_styles', 'print_emoji_styles');
            remove_filter('wp_mail', 'wp_staticize_emoji_for_email');

            remove_filter('the_content_feed', 'wp_staticize_emoji');
            remove_action('embed_head', 'print_emoji_detection_script');

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

        if ($this->option( 'wpautop')) {
            remove_filter('the_content', 'wpautop');
            remove_filter('the_excerpt', 'wpautop');
        }

        if ($this->option( 'feed-links')) {
            remove_action('wp_head', 'feed_links', 2);
            remove_action('wp_head', 'feed_links_extra', 3);
        }

        if ($this->option( 'admin-bar')) {
            add_filter('show_admin_bar', '__return_false');
        }

        if ($this->option( 'admin-bar-non-admins') and !current_user_can('manage_options')) {
            add_filter('show_admin_bar', '__return_false');
        }

        if ($this->option( 'wp-version')) {
            // remove version from head
            remove_action('wp_head', 'wp_generator');
            // remove version from rss
            add_filter('the_generator', '__return_empty_string');
        }

        if ($this->option( 'wp-blog-panel')) {
            remove_action('welcome_panel', 'wp_welcome_panel');
        }
    }

    protected function setting_fields()
    {
        return array(
            array('type' => 'checkbox', 'name' => __('Disable Dashboard Welcome Panel', 'wpopt'), 'id' => 'welcome-panel', 'value' => $this->option( 'welcome-panel')),
            array('type' => 'checkbox', 'name' => __('Disable Dashboard WordPress Blog', 'wpopt'), 'id' => 'wp-blog-panel', 'value' => $this->option( 'wp-blog-panel')),
            array('type' => 'checkbox', 'name' => __('Hide Admin Bar', 'wpopt'), 'id' => 'admin-bar', 'value' => $this->option( 'admin-bar')),
            array('type' => 'checkbox', 'name' => __('Hide Admin Bar for non admins', 'wpopt'), 'id' => 'admin-bar-non-admin', 'value' => $this->option( 'admin-bar-non-admin')),
            array('type' => 'divide'),
            array('type' => 'checkbox', 'name' => __('Disable Block Editor (Gutenberg)', 'wpopt'), 'id' => 'blocks-editor', 'value' => $this->option( 'blocks-editor')),
            array('type' => 'checkbox', 'name' => __('Disable Core Blocks', 'wpopt'), 'id' => 'core-blocks', 'value' => $this->option( 'core-blocks')),
            array('type' => 'checkbox', 'name' => __('Disable Core Sitemap', 'wpopt'), 'id' => 'core-sitemap', 'value' => $this->option( 'core-sitemap')),
            array('type' => 'checkbox', 'name' => __('Disable Auto Paragraph', 'wpopt'), 'id' => 'wpautop', 'value' => $this->option( 'wpautop')),
            array('type' => 'checkbox', 'name' => __('Hide WordPress Version', 'wpopt'), 'id' => 'wp-version', 'value' => $this->option( 'wp-version')),
            array('type' => 'divide'),
            array('type' => 'checkbox', 'name' => __('Disable Emoji', 'wpopt'), 'id' => 'emoji', 'value' => $this->option( 'emoji')),
            array('type' => 'checkbox', 'name' => __('Disable Feed links', 'wpopt'), 'id' => 'feed-links', 'value' => $this->option( 'feed-links')),
        );
    }

    public function restricted_access($context = '')
    {
        if ($context === 'settings')
            return !current_user_can('manage_options');

        return false;
    }
}