<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use WPS\core\UtilEnv;
use WPOptimizer\modules\supporters\FrontendAssets;
use WPOptimizer\modules\supporters\LcpImages;
use WPOptimizer\modules\supporters\ScriptTiming;
use WPOptimizer\modules\supporters\UsedCss;
use WPS\modules\Module;
use WPOptimizer\core\Compatibility;

/**
 * Frontend PageSpeed optimizations for rendered HTML.
 */
class Mod_Pagespeed extends Module
{
    public static ?string $name = "PageSpeed";


    protected string $context = 'wpopt';

    public function restricted_access($context = ''): bool
    {
        switch ($context) {

            case 'settings':
                return !current_user_can('manage_options');

            default:
                return false;
        }
    }

    public function init(): void
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        if (function_exists('wp_get_speculation_rules_configuration') &&
            ($this->is_enabled('page_prefetching') || $this->is_enabled('disable_native_speculative_loading'))) {
            add_filter('wp_speculation_rules_configuration', array($this, 'configure_native_prefetch'));
        }

        if (!$this->has_enabled_optimization()) {
            return;
        }

        if ($this->is_enabled('defer_js') || $this->is_enabled('delay_js')) {
            new ScriptTiming(array(
                'defer' => $this->is_enabled('defer_js'), 'delay' => $this->is_enabled('delay_js'),
                'delay_handles' => $this->option('delay_handles', array()), 'delay_urls' => $this->option('delay_urls', array()),
                'exclude_handles' => $this->option('js_exclude_handles', array()), 'exclude_urls' => $this->option('js_exclude_urls', array()),
                'exclude_pages' => $this->option('js_exclude_pages', array()),
            ));
            add_filter('wps_script_timing_allowed', static function ($allowed) { return $allowed && !Compatibility::should_bypass_optimization(); });
        }

        wps('wps')->services->get('html_output_buffer')->register(
            'wpopt.pagespeed',
            array($this, 'optimize_html'),
            100
        );
    }

    /**
     * Configure Core's speculation rules without re-enabling contexts Core has disabled.
     */
    public function configure_native_prefetch($configuration)
    {
        if ($this->is_enabled('disable_native_speculative_loading')) {
            return null;
        }

        if ($this->is_enabled('page_prefetching') && is_array($configuration) &&
            ($configuration['mode'] ?? 'auto') === 'auto' &&
            ($configuration['eagerness'] ?? 'auto') === 'auto' &&
            !$this->has_native_default_override()) {
            $configuration['mode'] = 'prefetch';
            $configuration['eagerness'] = 'moderate';
        }

        return $configuration;
    }

    /**
     * Preserve site-level defaults introduced in WordPress 7.1.
     */
    private function has_native_default_override(): bool
    {
        foreach (array('WP_SPECULATIVE_LOADING_DEFAULT_MODE', 'WP_SPECULATIVE_LOADING_DEFAULT_EAGERNESS') as $name) {
            if (defined($name) || getenv($name) !== false) {
                return true;
            }
        }

        return false;
    }

    public function optimize_html($buffer)
    {
        if (Compatibility::should_bypass_optimization() || !is_string($buffer) || $buffer === '' || !UtilEnv::is_safe_buffering()) {
            return $buffer;
        }

        if (stripos($buffer, '<html') === false || stripos($buffer, '</html>') === false) {
            return $buffer;
        }

        if ($this->is_enabled('remove_unused_css')) {
            $buffer = UsedCss::transform($buffer, array(
                'exclude_files' => $this->option('css_exclude_files', array()),
                'exclude_pages' => $this->option('css_exclude_pages', array()),
                'keep_selectors' => $this->option('css_keep_selectors', array()),
                'static_selectors' => $this->option('css_static_selectors', array()),
            ));
        }
        $buffer = LcpImages::transform($buffer, $this->is_enabled('auto_preload_largest_image'), $this->is_enabled('lazyload_images', true), array(
            'images' => FrontendAssets::rules($this->option('lcp_exclude_images', array())),
            'classes' => FrontendAssets::rules($this->option('lcp_exclude_classes', array())),
            'urls' => FrontendAssets::rules($this->option('lcp_exclude_urls', array())),
        ));

        if ($this->is_enabled('lazyload_iframes', true)) {
            $buffer = $this->add_lazy_loading($buffer, 'iframe');
        }

        if ($this->is_enabled('lazyload_video', true)) {
            $buffer = $this->add_video_lazy_loading($buffer);
        }

        if ($this->is_enabled('lazyload_fonts', false)) {
            $buffer = $this->add_lazy_font_stylesheets($buffer);
        }

        if ($this->is_enabled('force_font_display_swap', false)) {
            $buffer = $this->force_font_display_swap($buffer);
        }

        if ($this->is_enabled('add_missing_image_dimensions', true)) {
            $buffer = $this->add_missing_image_dimensions($buffer);
        }

        $head_injections = array();

        if ($this->is_enabled('page_prefetching', false) && !function_exists('wp_get_speculation_rules_configuration')) {
            $head_injections[] = $this->page_prefetching_script();
        }

        if (!empty($head_injections)) {
            $buffer = $this->inject_before_head_close($buffer, implode("\n", array_filter($head_injections)));
        }

        return $buffer;
    }

    protected function setting_fields($filter = ''): array
    {
        return $this->group_setting_fields(
            $this->group_setting_fields(
                $this->setting_field(__('Lazy loading', 'wpopt'), false, 'separator'),
                $this->setting_field(__('Lazyload images', 'wpopt'), 'lazyload_images', 'checkbox', array(
                    'default_value' => true,
                    'value'         => $this->option('lazyload_images', true),
                )),
                $this->setting_field(__('Lazy load iframes', 'wpopt'), 'lazyload_iframes', 'checkbox', array('default_value' => true)),
                $this->setting_field(__('Lazy load video', 'wpopt'), 'lazyload_video', 'checkbox', array('default_value' => true))
            ),
            $this->group_setting_fields(
                $this->setting_field(__('Font Optimization', 'wpopt'), false, 'separator'),
                $this->setting_field(__('Force Font Display Swap', 'wpopt'), 'force_font_display_swap', 'checkbox', array('default_value' => false)),
                $this->setting_field(__('Lazyload Fonts', 'wpopt'), 'lazyload_fonts', 'checkbox', array('default_value' => false))
            ),
            $this->group_setting_fields(
                $this->setting_field(__('LCP optimizations', 'wpopt'), false, 'separator'),
                $this->setting_field(__('Add missing images dimensions', 'wpopt'), 'add_missing_image_dimensions', 'checkbox', array('default_value' => false)),
                $this->setting_field(__('Auto Preload Largest Image', 'wpopt'), 'auto_preload_largest_image', 'checkbox', array('default_value' => false)),
                $this->list_field(__('Image IDs excluded from learning and lazy loading', 'wpopt'), 'lcp_exclude_images'),
                $this->list_field(__('Image classes excluded from learning and lazy loading', 'wpopt'), 'lcp_exclude_classes'),
                $this->list_field(__('Image URL fragments excluded from learning and lazy loading', 'wpopt'), 'lcp_exclude_urls')
            ),
            $this->group_setting_fields(
                $this->setting_field(__('JavaScript execution', 'wpopt'), false, 'separator'),
                $this->setting_field(__('Defer JavaScript with WordPress dependency checks', 'wpopt'), 'defer_js', 'checkbox', array('default_value' => false)),
                $this->setting_field(__('Delay selected background scripts until idle', 'wpopt'), 'delay_js', 'checkbox', array('default_value' => false)),
                $this->list_field(__('Handles to delay (exact names; one per line)', 'wpopt'), 'delay_handles'),
                $this->list_field(__('Script URL fragments to delay', 'wpopt'), 'delay_urls'),
                $this->list_field(__('Script handles excluded from execution changes', 'wpopt'), 'js_exclude_handles'),
                $this->list_field(__('Script URL fragments excluded from execution changes', 'wpopt'), 'js_exclude_urls'),
                $this->list_field(__('Page URL fragments excluded from execution changes', 'wpopt'), 'js_exclude_pages')
            ),
            $this->group_setting_fields(
                $this->setting_field(__('Used CSS (separate from minification)', 'wpopt'), false, 'separator'),
                $this->setting_field(__('Generate per-page Used CSS in the background', 'wpopt'), 'remove_unused_css', 'checkbox', array('default_value' => false)),
                $this->list_field(__('Stylesheet URL fragments to preserve', 'wpopt'), 'css_exclude_files'),
                $this->list_field(__('Page URL fragments excluded from Used CSS', 'wpopt'), 'css_exclude_pages'),
                $this->list_field(__('Selector fragments to preserve', 'wpopt'), 'css_keep_selectors'),
                $this->list_field(__('Static selectors safe to prune when absent (exact names)', 'wpopt'), 'css_static_selectors')
            ),
            $this->group_setting_fields(
                $this->setting_field(__('Navigation', 'wpopt'), false, 'separator'),
                $this->setting_field(__('Enable early page prefetching', 'wpopt'), 'page_prefetching', 'checkbox', array('default_value' => false)),
                $this->setting_field(__('Disable WordPress speculative loading', 'wpopt'), 'disable_native_speculative_loading', 'checkbox', array('default_value' => false))
            )
        );
    }

    protected function infos(): array
    {
        return array(
            'lazyload_images'              => __("Adds native loading=\"lazy\" to images that do not already define a loading strategy.", 'wpopt'),
            'force_font_display_swap'      => __("Adds font-display: swap to all @font-face declarations. Prevents invisible text while fonts load.", 'wpopt'),
            'lazyload_fonts'               => __("Loads matching font stylesheets with a print-media/onload switch. This does not generate Used CSS or Critical CSS.", 'wpopt'),
            'lazyload_iframes'             => __("Adds native loading=\"lazy\" to iframes that do not already define a loading strategy.", 'wpopt'),
            'lazyload_video'               => __("Prevents videos from preloading data before the browser needs them.", 'wpopt'),
            'add_missing_image_dimensions' => __("Adds width and height attributes to WordPress attachment images when metadata is available, reducing layout shifts and improving LCP stability.", 'wpopt'),
            'auto_preload_largest_image'   => __("Learns the LCP image per public page and viewport. Later responses contain an early image preload; the main image stays eager. Picture sources and declared backgrounds are supported. Clear the page cache after changing exclusions.", 'wpopt'),
            'defer_js' => __("Requests native defer on WordPress 6.3+. WordPress resolves dependency and inline-script constraints. Existing strategies are preserved.", 'wpopt'),
            'delay_js' => __("Only explicitly selected background scripts are delayed until idle, with a five-second fallback. Menus, forms, consent and commerce infrastructure remain immediate. Head scripts, scripts needed by immediate dependents and separate localized data are preserved. No clicks are intercepted. Native script modules outside WordPress handles remain unchanged.", 'wpopt'),
            'remove_unused_css' => __("A scheduled worker parses each page's local stylesheets. Originals remain until ready and on errors. Interactive states, complex selectors, keyframes and uncertain syntax are preserved. On pages with JavaScript, only selectors you declare static can be removed. This generates Used CSS, not Critical CSS. WordPress Cron must run.", 'wpopt'),
            'css_static_selectors' => __("Declare only plain class, ID or tag selectors that JavaScript never adds. Examples: .old-banner or #retired-panel. Incorrect declarations can hide interactive content.", 'wpopt'),
            'page_prefetching'             => __("On WordPress 6.8 or newer, uses Core's speculation rules with moderate eagerness when Core's defaults are unchanged. Explicit site settings are preserved. On older versions, prefetches same-origin links on hover or touch. WordPress may already load pages speculatively when this option is off.", 'wpopt'),
            'disable_native_speculative_loading' => __("Stops WordPress Core from prefetching or prerendering pages. Use only if speculative requests conflict with site behavior; disabling them does not speed up navigation. Has no effect before WordPress 6.8.", 'wpopt'),
        );
    }

    private function has_enabled_optimization(): bool
    {
        $defaults = array(
            'lazyload_images'              => true,
            'lazyload_fonts'               => false,
            'force_font_display_swap'      => false,
            'lazyload_iframes'             => true,
            'lazyload_video'               => true,
            'add_missing_image_dimensions' => true,
            'auto_preload_largest_image'   => false,
            'page_prefetching'             => false,
            'defer_js'                     => false,
            'delay_js'                     => false,
            'remove_unused_css'            => false,
        );

        foreach ($defaults as $option => $default) {
            if ($this->is_enabled($option, $default)) {
                return true;
            }
        }

        return false;
    }

    private function is_enabled(string $option, bool $default = false): bool
    {
        return (bool)$this->option($option, $default);
    }

    private function list_field(string $label, string $id): array
    {
        return $this->setting_field($label, $id, 'textarea_array', array('value' => implode("\n", FrontendAssets::rules($this->option($id, array())))));
    }

    protected function print_header(): string
    {
        $markup = '<details><summary>' . esc_html__('Last public-page optimization diagnostics', 'wpopt') . '</summary>';
        foreach (array('lcp', 'scripts', 'css') as $feature) {
            $result = get_transient('wps_frontend_diagnostic_' . $feature);
            if ($result) { $markup .= '<h3>' . esc_html(strtoupper($feature)) . '</h3><pre style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>'; }
        }
        return $markup . '<p>' . esc_html__('Diagnostics show the last generated public request. Purge page caches to inspect a fresh response after changing settings.', 'wpopt') . '</p></details>';
    }

    private function add_lazy_loading(string $buffer, string $tag): string
    {
        return preg_replace_callback(
            '#<' . preg_quote($tag, '#') . '\b([^>]*)>#i',
            static function ($matches) use ($tag) {
                $attributes = $matches[1];

                if (preg_match('#\sloading\s*=#i', $attributes) || preg_match('#\sfetchpriority=["\']high["\']#i', $attributes)) {
                    return $matches[0];
                }

                return '<' . $tag . $attributes . ' loading="lazy">';
            },
            $buffer
        );
    }

    private function add_video_lazy_loading(string $buffer): string
    {
        return preg_replace_callback(
            '#<video\b([^>]*)>#i',
            static function ($matches) {
                $attributes = $matches[1];

                if (preg_match('#\spreload\s*=#i', $attributes)) {
                    return $matches[0];
                }

                return '<video' . $attributes . ' preload="none">';
            },
            $buffer
        );
    }

    private function add_lazy_font_stylesheets(string $buffer): string
    {
        return preg_replace_callback(
            '#<link\b([^>]*)>#i',
            static function ($matches) {
                $tag = $matches[0];
                $attributes = $matches[1];

                if (!preg_match('#\srel=["\']stylesheet["\']#i', $attributes)) {
                    return $tag;
                }

                if (!preg_match('#\shref=["\'][^"\']*(font|fonts\.googleapis\.com)[^"\']*["\']#i', $attributes)) {
                    return $tag;
                }

                if (preg_match('#\smedia\s*=#i', $attributes) || preg_match('#\sonload\s*=#i', $attributes)) {
                    return $tag;
                }

                return preg_replace(
                    '#<link\b#i',
                    '<link media="print" onload="this.media=\'all\'"',
                    $tag,
                    1
                );
            },
            $buffer
        );
    }

    private function force_font_display_swap(string $buffer): string
    {
        if (stripos($buffer, '@font-face') === false) {
            return $buffer;
        }

        return preg_replace_callback(
            '#<style\b([^>]*)>(.*?)</style>#is',
            function ($matches) {
                return '<style' . $matches[1] . '>' . $this->force_font_display_swap_in_css($matches[2]) . '</style>';
            },
            $buffer
        );
    }

    private function force_font_display_swap_in_css(string $css): string
    {
        return preg_replace_callback(
            '#@font-face\s*\{[^{}]*\}#i',
            static function ($matches) {
                $block = $matches[0];

                if (preg_match('#font-display\s*:#i', $block)) {
                    return preg_replace('#font-display\s*:\s*[^;}\s]+#i', 'font-display: swap', $block, 1);
                }

                return preg_replace('#\}\s*$#', 'font-display: swap;}', $block, 1);
            },
            $css
        );
    }

    private function add_missing_image_dimensions(string $buffer): string
    {
        return preg_replace_callback(
            '#<img\b([^>]*)>#i',
            function ($matches) {
                $tag = $matches[0];
                $attributes = $matches[1];

                if (preg_match('#\swidth\s*=#i', $attributes) && preg_match('#\sheight\s*=#i', $attributes)) {
                    return $tag;
                }

                $attachment_id = $this->extract_attachment_id($tag);

                if ($attachment_id <= 0) {
                    return $tag;
                }

                $dimensions = $this->resolve_image_dimensions($attachment_id, $tag);

                if (empty($dimensions['width']) || empty($dimensions['height'])) {
                    return $tag;
                }

                $insert = '';

                if (!preg_match('#\swidth\s*=#i', $attributes)) {
                    $insert .= ' width="' . absint($dimensions['width']) . '"';
                }

                if (!preg_match('#\sheight\s*=#i', $attributes)) {
                    $insert .= ' height="' . absint($dimensions['height']) . '"';
                }

                if ($insert === '') {
                    return $tag;
                }

                return preg_replace('#<img\b#i', '<img' . $insert, $tag, 1);
            },
            $buffer
        );
    }

    private function extract_attachment_id(string $tag): int
    {
        if (preg_match('#\bwp-image-(\d+)\b#i', $tag, $matches)) {
            return absint($matches[1]);
        }

        if (preg_match('#\bdata-id=["\'](\d+)["\']#i', $tag, $matches)) {
            return absint($matches[1]);
        }

        return 0;
    }

    private function resolve_image_dimensions(int $attachment_id, string $tag): array
    {
        $metadata = wp_get_attachment_metadata($attachment_id);

        if (empty($metadata) || !is_array($metadata)) {
            return array();
        }

        $src_basename = $this->extract_image_src_basename($tag);

        if ($src_basename !== '' && !empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size) {
                if (!empty($size['file']) && basename((string)$size['file']) === $src_basename && !empty($size['width']) && !empty($size['height'])) {
                    return array(
                        'width'  => absint($size['width']),
                        'height' => absint($size['height']),
                    );
                }
            }
        }

        if (empty($metadata['width']) || empty($metadata['height'])) {
            return array();
        }

        return array(
            'width'  => absint($metadata['width']),
            'height' => absint($metadata['height']),
        );
    }

    private function extract_image_src_basename(string $tag): string
    {
        if (!preg_match('#\bsrc=["\']([^"\']+)["\']#i', $tag, $matches)) {
            return '';
        }

        $path = wp_parse_url(html_entity_decode($matches[1]), PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            return '';
        }

        return basename($path);
    }

    private function inject_before_head_close(string $buffer, string $markup): string
    {
        if ($markup === '') {
            return $buffer;
        }

        if (stripos($buffer, '</head>') === false) {
            return $buffer;
        }

        return preg_replace('#</head>#i', $markup . "\n</head>", $buffer, 1);
    }

    private function page_prefetching_script(): string
    {
        return <<<'HTML'
<script id="wpopt-pagespeed-prefetch">
(function(){if(window.wpoptPagePrefetch){return;}window.wpoptPagePrefetch=true;var prefetched={};function eligible(link){return link&&link.href&&link.origin===location.origin&&!link.hash&&!link.download&&link.target!=="_blank"&&!prefetched[link.href];}function prefetch(link){if(!eligible(link)){return;}prefetched[link.href]=true;var hint=document.createElement("link");hint.rel="prefetch";hint.href=link.href;document.head.appendChild(hint);}document.addEventListener("mouseover",function(event){var link=event.target.closest&&event.target.closest("a");if(link){prefetch(link);}}, {passive:true});document.addEventListener("touchstart",function(event){var link=event.target.closest&&event.target.closest("a");if(link){prefetch(link);}}, {passive:true});})();
</script>
HTML;
    }
}
