<?php

/**
 * Module for updates handling
 */
class WOMod_WP_Customizer extends WO_Module
{
    public $scopes = array('settings', 'autoload');

    public function __construct()
    {
        $default = array(
            'blocks-editor'  => true,
            'core-blocks'    => true,
            'welcome-panel'  => true,
            'emoji'          => true,
            'core-sitemap'   => true,
            'show-admin-bar' => true,
            'feed-links'     => true,
            'wpautop'        => true
        );

        parent::__construct(
            array(
                'settings' => $default,
            )
        );

        add_action('init', array($this, 'disable_blocks'), 10, 0);
    }

    public function disable_blocks()
    {
        if (!WOSettings::check($this->settings, 'blocks-editor')) {
            remove_filter('the_content', 'do_blocks');

            add_filter('use_block_editor_for_post', '__return_false');
            add_filter('use_block_editor_for_post_type', '__return_false');
        }

        if (!WOSettings::check($this->settings, 'core-blocks')) {
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

        if (!WOSettings::check($this->settings, 'welcome-panel')) {
            remove_all_actions('welcome_panel');
        }

        if (!WOSettings::check($this->settings, 'core-sitemap')) {
            remove_all_actions('welcome_panel');
        }

        if (!WOSettings::check($this->settings, 'emoji')) {
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

        if (!WOSettings::check($this->settings, 'wpautop')) {
            remove_filter('the_content', 'wpautop');
            remove_filter('the_excerpt', 'wpautop');
        }

        if (!WOSettings::check($this->settings, 'feed-links')) {
            remove_action('wp_head', 'feed_links', 2);
            remove_action('wp_head', 'feed_links_extra', 3);
        }

        if (!WOSettings::check($this->settings, 'show-admin-bar')) {
            add_filter('show_admin_bar', '__return_false');
        }
    }

    protected function setting_fields()
    {
        return array(
            array('type' => 'checkbox', 'name' => __('Block Editor (Gutenberg)', 'wpopt'), 'id' => 'blocks-editor', 'value' => WOSettings::check($this->settings, 'blocks-editor')),
            array('type' => 'checkbox', 'name' => __('Core Blocks', 'wpopt'), 'id' => 'core-blocks', 'value' => WOSettings::check($this->settings, 'core-blocks')),
            array('type' => 'checkbox', 'name' => __('Dashboard Welcome Panel', 'wpopt'), 'id' => 'welcome-panel', 'value' => WOSettings::check($this->settings, 'welcome-panel')),
            array('type' => 'checkbox', 'name' => __('Emoji', 'wpopt'), 'id' => 'emoji', 'value' => WOSettings::check($this->settings, 'emoji')),
            array('type' => 'checkbox', 'name' => __('Core Sitemap', 'wpopt'), 'id' => 'core-sitemap', 'value' => WOSettings::check($this->settings, 'core-sitemap')),
            array('type' => 'checkbox', 'name' => __('Show Admin Bar', 'wpopt'), 'id' => 'show-admin-bar', 'value' => WOSettings::check($this->settings, 'show-admin-bar')),
            array('type' => 'checkbox', 'name' => __('Feed links', 'wpopt'), 'id' => 'feed-links', 'value' => WOSettings::check($this->settings, 'feed-links')),
            array('type' => 'checkbox', 'name' => __('Disable wpautop', 'wpopt'), 'id' => 'wpautop', 'value' => WOSettings::check($this->settings, 'wpautop')),
        );
    }

    protected function restricted_access($context = '')
    {
        return !current_user_can('administrator');
    }

}