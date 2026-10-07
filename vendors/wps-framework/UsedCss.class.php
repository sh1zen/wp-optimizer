<?php
namespace WPS\core;

use WPSVendor\Sabberworm\CSS\Parser;
use WPSVendor\Sabberworm\CSS\Settings;
use WPSVendor\Sabberworm\CSS\CSSList\CSSList;
use WPSVendor\Sabberworm\CSS\CSSList\AtRuleBlockList;
use WPSVendor\Sabberworm\CSS\RuleSet\DeclarationBlock;
use WPSVendor\Sabberworm\CSS\Value\URL;

/** Per-page Used CSS. Parsing and writing happen exclusively in a scheduled worker. */
final class UsedCss
{
    public static function transform(string $html, array $config): string
    {
        if (!FrontendAssets::eligible() || !class_exists('WP_HTML_Tag_Processor')
            || FrontendAssets::matches(FrontendAssets::page(), $config['exclude_pages'] ?? array())) { return $html; }
        $scan = new \WP_HTML_Tag_Processor($html);
        $tokens = array(); $assets = array(); $dynamic = false;
        while ($scan->next_tag()) {
            $tag = $scan->get_tag(); $tokens[strtolower($tag)] = true;
            $id = $scan->get_attribute('id');
            if (is_string($id) && $id !== '') { $tokens['#' . $id] = true; }
            foreach (preg_split('/\s+/', trim((string)$scan->get_attribute('class'))) as $class) {
                if ($class !== '') { $tokens['.' . $class] = true; }
            }
            if ($tag === 'SCRIPT' && !in_array((string)$scan->get_attribute('type'), array('application/json', 'application/ld+json', 'importmap'), true)) { $dynamic = true; }
            if (!method_exists($scan, 'get_attribute_names_with_prefix') || $scan->get_attribute_names_with_prefix('on')
                || $scan->get_attribute('contenteditable') !== null
                || preg_match('/^\s*javascript:/i', (string)$scan->get_attribute('href'))) { $dynamic = true; }
            if ($tag !== 'LINK' || strtolower((string)$scan->get_attribute('rel')) !== 'stylesheet'
                || $scan->get_attribute('integrity') !== null || $scan->get_attribute('onload') !== null
                || $scan->get_attribute('disabled') !== null || $scan->get_attribute('title') !== null) { continue; }
            $url = FrontendAssets::absolute((string)$scan->get_attribute('href'), FrontendAssets::page());
            $file = FrontendAssets::local($url);
            if (!$file || !preg_match('/\.css$/i', $file) || filesize($file) > 1048576
                || FrontendAssets::matches($url, $config['exclude_files'] ?? array())) { continue; }
            $assets[$url] = array('file' => $file, 'version' => hash_file('sha256', $file));
        }
        if (!$assets || count($assets) > 40 || count($tokens) > 12000) { return $html; }
        ksort($tokens);
        $job = array('page' => FrontendAssets::page(), 'revision' => FrontendAssets::revision(), 'tokens' => $tokens,
            'assets' => $assets, 'dynamic' => $dynamic, 'config' => $config);
        $key = hash('sha256', wp_json_encode($job));
        $ready = get_transient('wps_used_css_ready_' . $key);
        if (!is_array($ready)) {
            if (!get_transient('wps_used_css_job_' . $key) && !get_transient('wps_used_css_failed_' . $key)) {
                set_transient('wps_used_css_job_' . $key, $job, DAY_IN_SECONDS);
                if (!wp_next_scheduled('wps_used_css_build', array($key))) {
                    wp_schedule_single_event(time() + 10, 'wps_used_css_build', array($key));
                }
            }
            FrontendAssets::diagnostic('css', array('status' => get_transient('wps_used_css_failed_' . $key) ?: 'queued; original stylesheets retained', 'key' => $key));
            return $html;
        }
        $edit = new \WP_HTML_Tag_Processor($html); $replaced = 0;
        while ($edit->next_tag('LINK')) {
            if (strtolower((string)$edit->get_attribute('rel')) !== 'stylesheet' || $edit->get_attribute('integrity') !== null
                || $edit->get_attribute('onload') !== null || $edit->get_attribute('disabled') !== null || $edit->get_attribute('title') !== null) { continue; }
            $url = FrontendAssets::absolute((string)$edit->get_attribute('href'), FrontendAssets::page());
            if (isset($ready['files'][$url]) && is_file($ready['files'][$url])) {
                $edit->set_attribute('data-wps-original-href', $url);
                $edit->set_attribute('href', UtilEnv::path_to_url($ready['files'][$url], true));
                $edit->set_attribute('data-wps-used-css', substr($key, 0, 12));
                $replaced++;
            }
        }
        FrontendAssets::diagnostic('css', array('status' => 'ready', 'replaced_files' => $replaced,
            'removed_selectors' => $ready['removed'], 'dynamic_policy' => $dynamic ? 'JS present: only declared static selectors may be pruned' : 'static document'));
        $updated = $edit->get_updated_html();
        $runtime = $replaced ? FrontendAssets::inline_runtime('used-css-fallback.js', 'wps-used-css-fallback') : '';
        return $replaced ? ($runtime !== '' ? FrontendAssets::head($updated, $runtime) : $html) : $updated;
    }

    public static function build(string $key): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $key)) { return; }
        $job = get_transient('wps_used_css_job_' . $key);
        if (!$job || $job['revision'] !== FrontendAssets::revision()) { delete_transient('wps_used_css_job_' . $key); return; }
        // add_option is atomic: concurrent cron requests cannot generate the same job twice.
        $lock = 'wps_used_css_lock_' . $key;
        if (!add_option($lock, time(), '', false)) {
            if ((int)get_option($lock) < time() - 300) { delete_option($lock); }
            return;
        }
        $files = array(); $removed = 0;
        try {
            $uploads = wp_upload_dir();
            if (!empty($uploads['error'])) { throw new \RuntimeException('Uploads directory unavailable'); }
            $dir = $uploads['basedir'] . '/wps-used-css';
            if (!wp_mkdir_p($dir)) { throw new \RuntimeException('Generated CSS directory unavailable'); }
            foreach ($job['assets'] as $url => $asset) {
                // Re-resolve the server URL and verify immutable input before publishing any result.
                $file = FrontendAssets::local($url);
                if (!$file || $file !== $asset['file'] || hash_file('sha256', $file) !== $asset['version']) {
                    throw new \RuntimeException('Stylesheet changed while queued');
                }
                $css = file_get_contents($file);
                $result = self::reduce($css, $job['tokens'], $job['dynamic'], $job['config'], $url);
                if ($result['removed'] === 0) { continue; }
                $dest = $dir . '/' . $key . '-' . substr(hash('sha256', $url), 0, 16) . '.css';
                $tmp = $dest . '.tmp';
                if (file_put_contents($tmp, $result['css'], LOCK_EX) === false || !rename($tmp, $dest)) {
                    throw new \RuntimeException('Cannot publish generated stylesheet');
                }
                $files[$url] = $dest; $removed += $result['removed'];
            }
            if ($job['revision'] !== FrontendAssets::revision()) { throw new \RuntimeException('Content changed while building'); }
            set_transient('wps_used_css_ready_' . $key, array('files' => $files, 'removed' => $removed), 7 * DAY_IN_SECONDS);
            do_action('wps_frontend_page_ready', $job['page']);
            // Immutable outputs outlive the ready record and normal seven-day page caches.
            foreach (array_slice(glob($dir . '/*.css') ?: array(), 0, 100) as $old) {
                if (filemtime($old) < time() - 30 * DAY_IN_SECONDS) { unlink($old); }
            }
        } catch (\Throwable $error) {
            set_transient('wps_used_css_failed_' . $key, 'fallback: ' . $error->getMessage(), HOUR_IN_SECONDS);
            FrontendAssets::diagnostic('css', array('status' => 'original stylesheets retained', 'reason' => $error->getMessage()));
        } finally { delete_option($lock); delete_transient('wps_used_css_job_' . $key); }
    }

    /** The parser owns CSS structure; only plain selectors are eligible for removal. */
    public static function reduce(string $css, array $tokens, bool $dynamic, array $config, string $base): array
    {
        $document = (new Parser($css, Settings::create()->withLenientParsing(false)))->parse();
        // Imports, nesting and newer syntax need complete understanding. Keep the original file.
        if (preg_match('/@import\b|@namespace\b|image-set\s*\(/i', $css)) { return array('css' => $css, 'removed' => 0); }
        $removed = 0;
        $walk = function (CSSList $list) use (&$walk, &$removed, $tokens, $dynamic, $config) {
            foreach ($list->getContents() as $item) {
                if ($item instanceof DeclarationBlock) {
                    $keep = array();
                    foreach ($item->getSelectors() as $selector) {
                        $text = $selector->getSelector();
                        $simple = preg_match('/^(?:[a-zA-Z][a-zA-Z0-9_-]*|[.#][a-zA-Z_][a-zA-Z0-9_-]*)$/D', $text);
                        $static = in_array($text, FrontendAssets::rules($config['static_selectors'] ?? array()), true);
                        $token = ($text[0] ?? '') === '.' || ($text[0] ?? '') === '#' ? $text : strtolower($text);
                        if (!$simple || isset($tokens[$token]) || FrontendAssets::matches($text, $config['keep_selectors'] ?? array())
                            || ($dynamic && !$static)) { $keep[] = $selector; }
                        else { $removed++; }
                    }
                    if (!$keep) { $list->remove($item); }
                    else { $item->setSelectors($keep); }
                } elseif ($item instanceof AtRuleBlockList && in_array(strtolower($item->atRuleName()), array('media', 'supports'), true)) {
                    $walk($item);
                }
                // Preserve font faces, keyframes, custom properties, layers and unknown at-rules.
            }
        };
        $walk($document);
        if (!$removed) { return array('css' => $css, 'removed' => 0); }
        foreach ($document->getAllValues(null, true) as $value) {
            if ($value instanceof URL) {
                $url = $value->getURL()->getString();
                if ($url !== '' && $url[0] !== '#' && !preg_match('/^(data|blob):/i', $url)) {
                    $value->getURL()->setString(FrontendAssets::absolute($url, $base));
                }
            }
        }
        return array('css' => $document->render(), 'removed' => $removed);
    }
}
