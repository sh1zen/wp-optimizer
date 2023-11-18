<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C)  2022
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use SHZN\core\UtilEnv;
use SHZN\modules\Module;
use WPOptimizer\modules\supporters\Minify_HTML;

/**
 * Module for updates handling
 */
class Mod_Minify extends Module
{
    public $scopes = array('settings', 'autoload');

    public function __construct()
    {
        require_once WPOPT_SUPPORTERS . '/minifier/Minify.class.php';
        require_once WPOPT_SUPPORTERS . '/minifier/Minify_HTML.class.php';
        require_once WPOPT_SUPPORTERS . '/minifier/Minify_CSS.class.php';
        require_once WPOPT_SUPPORTERS . '/minifier/Minify_JS.class.php';

        parent::__construct('wpopt');

        ob_start([$this, "minify"]);
    }

    public function minify($buffer)
    {
        if (!UtilEnv::is_safe_buffering() or !$this->is_valid_buffer($buffer)) {
            return $buffer;
        }

        if (apply_filters("wpopt_allow_minify_html", $this->option('html.active'))) {

            $buffer = Minify_HTML::minify($buffer, [
                'comments'   => !$this->option('html.remove_comments', false),
                'minify_js'  => $this->option('html.minify_js', false),
                'minify_css' => $this->option('html.minify_css', false),
            ]);
        }

        /*
        if(apply_filters("wpopt_allow_minify_js", $this->option('js.active')))
        {

        }

        if(apply_filters("wpopt_allow_minify_css", $this->option('css.active')))
        {

        }
*/
        return $buffer;
    }

    /**
     * Returns true if given markup is considered valid/processable/optimizable.
     *
     * @param string $content Markup.
     *
     * @return bool
     */
    private function is_valid_buffer($content)
    {
        // Defaults to true.
        $valid = true;

        $has_no_html_tag = !str_contains($content, '<html');
        $has_xsl_stylesheet = (str_contains($content, '<xsl:stylesheet') or str_contains($content, '<?xml-stylesheet'));
        $has_html5_doctype = (preg_match('/^<!DOCTYPE.+html>/i', ltrim($content)) > 0);
        $has_noptimize_page = str_contains($content, '<!-- noptimize-page -->');

        if ($has_no_html_tag) {
            // Can't be valid amp markup without an html tag preceding it.
            $is_amp_markup = false;
        }
        else {
            $is_amp_markup = $this->is_amp_markup($content);
        }

        // If it's not html, or if it's amp or contains xsl stylesheets we don't touch it.
        if ($has_no_html_tag && !$has_html5_doctype || $is_amp_markup || $has_xsl_stylesheet || $has_noptimize_page) {
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
    private function is_amp_markup($content)
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

            $this->setting_field(__('Minify HTML', 'wpopt'), "html.active", "checkbox"),
            $this->setting_field(__('Remove Comments', 'wpopt'), "html.remove_comments", "checkbox", ['parent' => 'html.active']),
            $this->setting_field(__('Minify inline css', 'wpopt'), "html.minify_css", "checkbox", ['parent' => 'html.active']),
            $this->setting_field(__('Minify inline js', 'wpopt'), "html.minify_js", "checkbox", ['parent' => 'html.active']),

            /*
            $this->setting_field(__('Minify JavaScript', 'wpopt'), "js.active", "checkbox"),
            $this->setting_field(__('Try to combine scripts', 'wpopt'), "js.combine", "checkbox", ['parent' => 'js.active']),

            $this->setting_field(__('Minify CSS', 'wpopt'), "css.active", "checkbox"),
            $this->setting_field(__('Try to combine scripts', 'wpopt'), "css.combine", "checkbox", ['parent' => 'css.active']),
            */
        );
    }

    protected function setting_form_templates($context)
    {
        if ($context === 'header') {

            ob_start();
            ?>
            <block class="shzn">
                <h2><?php _e('Beta version.', 'wpopt'); ?></h2>
                <p>
                    <?php echo __('This module is under developing.'); ?><br>
                    <?php echo __('Should work fine, but to be safe activate this feature only if you know what to do in case of bugs. ', 'wpopt'); ?>
                </p>
            </block>
            <?php
            return ob_get_clean();
        }

        return '';
    }
}

return __NAMESPACE__;