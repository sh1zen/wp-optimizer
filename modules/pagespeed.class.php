<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use WPS\core\UtilEnv;
use WPS\modules\Module;

/**
 * Frontend PageSpeed optimizations for rendered HTML.
 */
class Mod_Pagespeed extends Module
{
    public static ?string $name = "PageSpeed";

    public array $scopes = array('autoload', 'settings');

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

        if (!$this->has_enabled_optimization()) {
            return;
        }

        ob_start(array($this, 'optimize_html'));
    }

    public function optimize_html($buffer)
    {
        if (!is_string($buffer) || $buffer === '' || !UtilEnv::is_safe_buffering()) {
            return $buffer;
        }

        if (stripos($buffer, '<html') === false || stripos($buffer, '</html>') === false) {
            return $buffer;
        }

        if ($this->is_enabled('lazyload_images', $this->legacy_media_lazyload_enabled())) {
            $buffer = $this->add_lazy_loading($buffer, 'img');
        }

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

        if ($this->is_enabled('auto_preload_largest_image', false)) {
            $head_injections[] = $this->largest_image_preload_script();
        }

        if ($this->is_enabled('page_prefetching', false)) {
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
                    'value'         => $this->option('lazyload_images', $this->legacy_media_lazyload_enabled()),
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
                $this->setting_field(__('Add missing images dimensions', 'wpopt'), 'add_missing_image_dimensions', 'checkbox', array('default_value' => true)),
                $this->setting_field(__('Auto Preload Largest Image', 'wpopt'), 'auto_preload_largest_image', 'checkbox', array('default_value' => false))
            ),
            $this->group_setting_fields(
                $this->setting_field(__('Navigation', 'wpopt'), false, 'separator'),
                $this->setting_field(__('Enable page prefetching', 'wpopt'), 'page_prefetching', 'checkbox', array('default_value' => false))
            )
        );
    }

    protected function infos(): array
    {
        return array(
            'lazyload_images'              => __("Adds native loading=\"lazy\" to images that do not already define a loading strategy.", 'wpopt'),
            'force_font_display_swap'      => __("Adds font-display: swap to all @font-face declarations. Prevents invisible text while fonts load.", 'wpopt'),
            'lazyload_fonts'               => __("Strips @font-face blocks from Used CSS so they don't block rendering. Fonts load after the critical CSS.", 'wpopt'),
            'lazyload_iframes'             => __("Adds native loading=\"lazy\" to iframes that do not already define a loading strategy.", 'wpopt'),
            'lazyload_video'               => __("Prevents videos from preloading data before the browser needs them.", 'wpopt'),
            'add_missing_image_dimensions' => __("Adds width and height attributes to WordPress attachment images when metadata is available, reducing layout shifts and improving LCP stability.", 'wpopt'),
            'auto_preload_largest_image'   => __("Detects the Largest Contentful Paint image at runtime and preloads it with fetchpriority=\"high\".", 'wpopt'),
            'page_prefetching'             => __("Prefetches same-origin pages on hover or touch intent to speed up likely next navigations.", 'wpopt'),
        );
    }

    private function has_enabled_optimization(): bool
    {
        $defaults = array(
            'lazyload_images'              => $this->legacy_media_lazyload_enabled(),
            'lazyload_fonts'               => false,
            'force_font_display_swap'      => false,
            'lazyload_iframes'             => true,
            'lazyload_video'               => true,
            'add_missing_image_dimensions' => true,
            'auto_preload_largest_image'   => false,
            'page_prefetching'             => false,
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

    private function legacy_media_lazyload_enabled(): bool
    {
        return (bool)wps('wpopt')->settings->get('media.loading_lazy', true);
    }

    private function add_lazy_loading(string $buffer, string $tag): string
    {
        return preg_replace_callback(
            '#<' . preg_quote($tag, '#') . '\b([^>]*)>#i',
            static function ($matches) use ($tag) {
                $attributes = $matches[1];

                if (preg_match('#\sloading\s*=#i', $attributes)) {
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

    private function largest_image_preload_script(): string
    {
        return <<<'HTML'
<script id="wpopt-pagespeed-lcp-preload">
(function(){if(!("PerformanceObserver"in window)||window.wpoptLcpPreload){return;}window.wpoptLcpPreload=true;var done=false;function alreadyPreloaded(url){var links=document.querySelectorAll('link[rel="preload"][as="image"]');for(var i=0;i<links.length;i++){if(links[i].href===url){return true;}}return false;}function preload(url,img){if(done||!url||alreadyPreloaded(url)){return;}done=true;if(img&&!img.hasAttribute("fetchpriority")){img.setAttribute("fetchpriority","high");}var link=document.createElement("link");link.rel="preload";link.as="image";link.href=url;link.setAttribute("fetchpriority","high");document.head.appendChild(link);}try{new PerformanceObserver(function(list){var entries=list.getEntries();var entry=entries[entries.length-1];if(entry&&entry.element&&entry.element.tagName==="IMG"){preload(entry.element.currentSrc||entry.element.src,entry.element);}}).observe({type:"largest-contentful-paint",buffered:true});}catch(e){}})();
</script>
HTML;
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

return __NAMESPACE__;
