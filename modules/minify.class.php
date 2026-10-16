<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use WPS\core\Disk;
use WPS\core\UtilEnv;
use WPS\modules\Module;
use WPOptimizer\core\Compatibility;
use WPOptimizer\modules\supporters\FrontendAssets;
use WPOptimizer\modules\supporters\Minify_CSS;
use WPOptimizer\modules\supporters\Minify_HTML;
use WPOptimizer\modules\supporters\Minify_JS;

/**
 * Minify HTML and prepare local assets through a scheduled worker.
 */
class Mod_Minify extends Module
{
    public static ?string $name = 'Minify';


    protected string $context = 'wpopt';

    private const ASSET_BUILD_HOOK = 'wpopt_minify_asset_build';

    public function cleanup(array $settings = array(), array $all_settings = array()): bool
    {
        wp_clear_scheduled_hook(self::ASSET_BUILD_HOOK);
        Disk::delete(WPOPT_STORAGE . 'minify');

        return true;
    }

    public function minify($buffer)
    {
        if (Compatibility::should_bypass_optimization() || !UtilEnv::is_safe_buffering() or !$this->is_valid_buffer($buffer)) {
            return $buffer;
        }

        if (!Compatibility::buffer_contains_page_builder_markup($buffer) && apply_filters("wpopt_allow_minify_html", $this->option('html.active'))) {

            $buffer = Minify_HTML::minify($buffer, [
                    'comments'   => !$this->option('html.remove_comments', false),
                    'minify_js'  => $this->option('html.minify_js', false),
                    'minify_css' => $this->option('html.minify_css', false),
            ]);
        }

        if (apply_filters("wpopt_allow_minify_js", $this->option('js.active'))) {

            $buffer = preg_replace_callback('#<script.*src=["\']([^"\']+)["\'].*></script>#iU', function ($matches) {

                list($script, $original_url) = $matches;

                if (Compatibility::is_optimization_sensitive_asset($original_url)) {
                    return $script;
                }

                if (!str_contains($original_url, 'min') and str_starts_with($original_url, wps_core()->home_url)) {

                    $script = $this->rewrite_asset($script, $original_url, 'js');
                }

                return $script;

            }, $buffer);
        }

        if (apply_filters("wpopt_allow_minify_css", $this->option('css.active'))) {

            $buffer = preg_replace_callback('#<link.*href=["\'\s]+([^"\']+)["\'\s]+.*/?>#iU', function ($matches) {

                list($script, $original_url) = $matches;

                // Used CSS is immutable, parser-generated output with its own asset version.
                if (str_contains($script, 'data-wps-used-css')) {
                    return $script;
                }

                if (Compatibility::is_optimization_sensitive_asset($original_url)) {
                    return $script;
                }

                if (!str_contains($original_url, 'min') and str_starts_with($original_url, wps_core()->home_url) and str_contains($script, 'stylesheet')) {

                    $script = $this->rewrite_asset($script, $original_url, 'css');
                }

                return $script;

            }, $buffer);
        }

        return $buffer;
    }

    /** Use ready output; cold requests only enqueue work and keep the original URL. */
    private function rewrite_asset(string $tag, string $original_url, string $type): string
    {
        $file = $this->asset_path($original_url, $type);
        if (is_file($file)) {
            $url = UtilEnv::path_to_url($file, true);
            return $url !== '' ? str_replace($original_url, $url, $tag) : $tag;
        }

        $args = array($original_url, $type);
        if (!wp_next_scheduled(self::ASSET_BUILD_HOOK, $args)) {
            $scheduled = wp_schedule_single_event(time() + 10, self::ASSET_BUILD_HOOK, $args, true);
            if (is_wp_error($scheduled) || !$scheduled) {
                do_action('wpopt_minify_asset_failed', $original_url, $type,
                    is_wp_error($scheduled) ? $scheduled->get_error_message() : 'Cannot schedule asset minification');
            }
        }
        return $tag;
    }

    /** Keep generated asset names compatible with existing cached markup. */
    private function asset_path(string $url, string $type): string
    {
        return WPOPT_STORAGE . 'minify/' . $type . '/' . md5($url) . '.' . $type;
    }

    /** Resolve local input in Cron, lock without waiting, and publish complete output. */
    public function build_asset(string $original_url, string $type): void
    {
        if (!in_array($type, array('css', 'js'), true)
            || !apply_filters('wpopt_allow_minify_' . $type, $this->option($type . '.active'))
            || Compatibility::is_optimization_sensitive_asset($original_url)) {
            return;
        }

        $file = $this->asset_path($original_url, $type);
        $lock = null;
        $temporary = '';
        try {
            if (is_file($file)) { return; }
            $source = FrontendAssets::local($original_url);
            if ($source === '' || strtolower(pathinfo($source, PATHINFO_EXTENSION)) !== $type) {
                throw new \RuntimeException('Minification requires a local ' . $type . ' file');
            }
            if (!Disk::make_path(dirname($file))) {
                throw new \RuntimeException('Generated asset directory unavailable');
            }
            $lock = fopen($file . '.lock', 'c');
            if ($lock === false) { throw new \RuntimeException('Cannot open asset generation lock'); }
            if (!flock($lock, LOCK_EX | LOCK_NB)) { return; }
            // Another worker may have published while this request resolved the source.
            clearstatcache(true, $file);
            if (is_file($file)) { return; }
            $content = file_get_contents($source);
            if ($content === false) { throw new \RuntimeException('Cannot read minification source'); }
            $data = $type === 'js' ? Minify_JS::minify($content)
                : Minify_CSS::minify($content, array('file_path' => dirname($source) . '/'));
            $temporary = $file . '.tmp';
            if (file_put_contents($temporary, $data, LOCK_EX) !== strlen($data) || !rename($temporary, $file)) {
                throw new \RuntimeException('Cannot publish minified asset');
            }
            $temporary = '';
        } catch (\Throwable $error) {
            do_action('wpopt_minify_asset_failed', $original_url, $type, $error->getMessage());
        } finally {
            if ($temporary !== '' && is_file($temporary)) { unlink($temporary); }
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            // Keep the lock file: unlinking it would let workers lock different inodes.
        }
    }

    /**
     * Returns true if given markup is considered valid/processable/optimizable.
     */
    private function is_valid_buffer($content): bool
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
     * @param string $content Markup to check
     * @return bool
     */
    private function is_amp_markup($content): bool
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

    public function restricted_access($context = ''): bool
    {
        if ($context === 'settings') {
            return !current_user_can('manage_options');
        }

        return false;
    }

    private function has_active_minifiers(): bool
    {
        return (bool)$this->option('html.active', false)
            || (bool)$this->option('js.active', false)
            || (bool)$this->option('css.active', false);
    }

    public function init(): void
    {
        if (!$this->has_active_minifiers()) {
            return;
        }

        require_once WPOPT_SUPPORTERS . '/minifier/Minify.class.php';
        require_once WPOPT_SUPPORTERS . '/minifier/Minify_HTML.class.php';
        require_once WPOPT_SUPPORTERS . '/minifier/Minify_CSS.class.php';
        require_once WPOPT_SUPPORTERS . '/minifier/Minify_JS.class.php';

        add_action(self::ASSET_BUILD_HOOK, array($this, 'build_asset'), 10, 2);

        wps('wps')->services->get('html_output_buffer')->register(
            'wpopt.minify',
            array($this, 'minify'),
            1000
        );
    }

    protected function setting_fields($filter = ''): array
    {
        return $this->group_setting_fields(
                $this->group_setting_fields(
                        $this->setting_field(__('Minify HTML', 'wpopt'), "html.active", "checkbox", ['default_value' => true]),
                        $this->setting_field(__('Remove Comments', 'wpopt'), "html.remove_comments", "checkbox", ['parent' => 'html.active']),
                        $this->setting_field(__('Minify inline css', 'wpopt'), "html.minify_css", "checkbox", ['parent' => 'html.active', 'default_value' => true]),
                        $this->setting_field(__('Minify inline js', 'wpopt'), "html.minify_js", "checkbox", ['parent' => 'html.active', 'default_value' => false]),
                ),
                $this->group_setting_fields(
                        $this->setting_field(__('Minify JavaScript', 'wpopt'), "js.active", "checkbox"),
                        $this->setting_field(__('Try to combine scripts', 'wpopt'), "js.combine", "checkbox", ['parent' => 'js.active']),
                ),
                $this->group_setting_fields(
                        $this->setting_field(__('Minify CSS', 'wpopt'), "css.active", "checkbox"),
                        $this->setting_field(__('Try to combine scripts', 'wpopt'), "css.combine", "checkbox", ['parent' => 'css.active']),
                )
        );
    }

    protected function print_header(): string
    {
        return '';
    }
}

