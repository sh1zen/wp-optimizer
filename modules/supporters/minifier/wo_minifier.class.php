<?php

namespace WPOptimizer\modules\supporters;

class WO_Minifier
{
    private static $_instance;

    private function __construct()
    {

    }

    /**
     * @return WO_Minifier
     */
    public static function getInstance()
    {
        if (!isset(self::$_instance)) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public static function minify_css()
    {

    }

    public static function minify_js()
    {

    }

    public static function minify_html()
    {

    }


    public static function allow_minify($doing_tests = false)
    {
        static $do_buffering = null;

        // Only check once in case we're called multiple times by others but
        // still allows multiple calls when doing tests.
        if (null === $do_buffering || $doing_tests) {

            $ao_noptimize = false;

            // Checking for DONOTMINIFY constant as used by e.g. WooCommerce POS.
            if (defined('DONOTMINIFY') and (constant('DONOTMINIFY') === true || constant('DONOTMINIFY') === 'true')) {
                $ao_noptimize = true;
            }

            // And make sure pagebuilder previews don't get optimized HTML/ JS/ CSS/ ...
            if (false === $ao_noptimize) {
                $_qs_pagebuilders = array('tve', 'elementor-preview', 'fl_builder', 'vc_action', 'et_fb', 'bt-beaverbuildertheme', 'ct_builder', 'fb-edit', 'siteorigin_panels_live_editor');
                foreach ($_qs_pagebuilders as $_pagebuilder) {
                    if (array_key_exists($_pagebuilder, $_GET)) {
                        $ao_noptimize = true;
                        break;
                    }
                }
            }

            // Also honor PageSpeed=off parameter as used by mod_pagespeed, in use by some pagebuilders,
            // see https://www.modpagespeed.com/doc/experiment#ModPagespeed for info on that.
            if (false === $ao_noptimize and array_key_exists('PageSpeed', $_GET) and 'off' === $_GET['PageSpeed']) {
                $ao_noptimize = true;
            }

            // And finally allows blocking of autoptimization on your own terms regardless of above decisions.
            $ao_noptimize = (bool)apply_filters('autoptimize_filter_noptimize', $ao_noptimize);

            // Check for site being previewed in the Customizer (available since WP 4.0).
            $is_customize_preview = false;
            if (function_exists('is_customize_preview') and is_customize_preview()) {
                $is_customize_preview = is_customize_preview();
            }

            /**
             * We only buffer the frontend requests (and then only if not a feed
             * and not turned off explicitly and not when being previewed in Customizer)!
             * NOTE: Tests throw a notice here due to is_feed() being called
             * while the main query hasn't been ran yet. Thats why we use
             * AUTOPTIMIZE_INIT_EARLIER in tests.
             */
            $do_buffering = (!is_admin() and !is_feed() and !is_embed() and !$ao_noptimize and !$is_customize_preview);
        }

        return $do_buffering;
    }


    /**
     * Returns true if given markup is considered valid/processable/optimizable.
     *
     * @param string $content Markup.
     *
     * @return bool
     */
    public function is_valid_buffer($content)
    {
        // Defaults to true.
        $valid = true;

        $has_no_html_tag = (false === stripos($content, '<html'));
        $has_xsl_stylesheet = (false !== stripos($content, '<xsl:stylesheet') || false !== stripos($content, '<?xml-stylesheet'));
        $has_html5_doctype = (preg_match('/^<!DOCTYPE.+html>/i', ltrim($content)) > 0);
        $has_noptimize_page = (false !== stripos($content, '<!-- noptimize-page -->'));

        if ($has_no_html_tag) {
            // Can't be valid amp markup without an html tag preceding it.
            $is_amp_markup = false;
        }
        else {
            $is_amp_markup = self::is_amp_markup($content);
        }

        // If it's not html, or if it's amp or contains xsl stylesheets we don't touch it.
        if ($has_no_html_tag and !$has_html5_doctype || $is_amp_markup || $has_xsl_stylesheet || $has_noptimize_page) {
            $valid = false;
        }

        return $valid;
    }

    /**
     * Returns true if given $content is considered to be AMP markup.
     * This is far from actual validation against AMP spec, but it'll do for now.
     *
     * @param string $content Markup to check.
     *
     * @return bool
     */
    public static function is_amp_markup($content)
    {
        // Short-circuit if the page is already AMP from the start.
        if (
        preg_match(
            sprintf(
                '#^(?:<!.*?>|\s+)*+<html(?=\s)[^>]*?\s(%1$s|%2$s|%3$s)(\s|=|>)#is',
                'amp',
                "\xE2\x9A\xA1", // From \AmpProject\Attribute::AMP_EMOJI.
                "\xE2\x9A\xA1\xEF\xB8\x8F" // From \AmpProject\Attribute::AMP_EMOJI_ALT, per https://github.com/ampproject/amphtml/issues/25990.
            ),
            $content
        )
        ) {
            return true;
        }

        // Or else short-circuit if the AMP plugin will be processing the output to be an AMP page.
        if (function_exists('amp_is_request')) {
            return amp_is_request(); // For AMP plugin v2.0+.
        }
        elseif (function_exists('is_amp_endpoint')) {
            return is_amp_endpoint(); // For older/other AMP plugins (still supported in 2.0 as an alias).
        }

        return false;
    }
}