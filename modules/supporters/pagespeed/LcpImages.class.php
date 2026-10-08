<?php
namespace WPOptimizer\modules\supporters;

/** Per-page image priority, lazy loading and LCP learning for PageSpeed. */
final class LcpImages
{
    /** Coordinate a server-owned image manifest, learned candidates and lazy loading. */
    public static function transform(string $html, bool $learn, bool $lazy, array $exclusions = array()): string
    {
        if (!class_exists('WP_HTML_Tag_Processor')) { return $html; }
        foreach (array('images', 'classes', 'urls') as $rule) { $exclusions[$rule] = FrontendAssets::rules($exclusions[$rule] ?? array()); }
        $page = FrontendAssets::page();
        $scan = new \WP_HTML_Tag_Processor($html);
        $images = array(); $sources = array(); $versions = array(); $fingerprint = array(); $owners = array();
        $picture = array(); $pictureExcluded = false; $index = 0;
        while ($learn && $scan->next_tag(array('tag_closers' => 'visit'))) {
            $tag = $scan->get_tag();
            if ($tag === 'PICTURE') {
                $picture = array();
                $pictureExcluded = !$scan->is_tag_closer() && (
                    FrontendAssets::matches((string)$scan->get_attribute('id'), $exclusions['images'] ?? array())
                    || FrontendAssets::matches((string)$scan->get_attribute('class'), $exclusions['classes'] ?? array()));
                $fingerprint[] = array('PICTURE', $pictureExcluded);
            }
            if ($scan->is_tag_closer()) { continue; }
            if (in_array($tag, array('IMG', 'SOURCE'), true)) {
                if ($tag === 'IMG') { $index++; }
                $src = (string)$scan->get_attribute('src');
                $set = (string)$scan->get_attribute('srcset');
                $id = (string)$scan->get_attribute('id');
                $class = (string)$scan->get_attribute('class');
                $fingerprint[] = array($tag, $src, $set, $id, $class, $scan->get_attribute('media'), $scan->get_attribute('sizes'),
                    $scan->get_attribute('type'), $scan->get_attribute('crossorigin'), $scan->get_attribute('referrerpolicy'));
                if ($pictureExcluded || FrontendAssets::matches($src . ' ' . $set, $exclusions['urls'] ?? array())
                    || FrontendAssets::matches($id, $exclusions['images'] ?? array())
                    || FrontendAssets::matches($class, $exclusions['classes'] ?? array())) {
                    if ($tag === 'IMG') { foreach ($picture as $url) { unset($images[$url], $sources[$url]); } }
                    continue;
                }
                $urls = array($src);
                foreach (explode(',', $set) as $candidate) { $urls[] = preg_split('/\s+/', trim($candidate))[0]; }
                foreach (array_filter($urls) as $url) {
                    $url = FrontendAssets::absolute($url, $page);
                    if ($url !== '') {
                        $images[$url] = true;
                        if (!isset($sources[$url]) || $tag === 'SOURCE') {
                            $sources[$url] = array('set' => $set, 'sizes' => (string)$scan->get_attribute('sizes'), 'tag' => $tag,
                                'media' => (string)$scan->get_attribute('media'), 'type' => (string)$scan->get_attribute('type'),
                                'crossorigin' => $scan->get_attribute('crossorigin'), 'referrerpolicy' => $scan->get_attribute('referrerpolicy'));
                        }
                        $versions[$url] = FrontendAssets::version($url);
                        if ($tag === 'SOURCE') { $picture[] = $url; }
                        else { $owners[$url][] = $index; }
                    }
                }
                if ($tag === 'IMG') {
                    foreach ($picture as $url) {
                        $owners[$url][] = $index;
                        if (empty($sources[$url]['sizes'])) { $sources[$url]['sizes'] = (string)$scan->get_attribute('sizes'); }
                        $sources[$url]['crossorigin'] = $scan->get_attribute('crossorigin');
                        $sources[$url]['referrerpolicy'] = $scan->get_attribute('referrerpolicy');
                    }
                }
            }
            // Include declared background resources, without accepting arbitrary client URLs.
            $css = (string)$scan->get_attribute('style');
            if ($tag === 'LINK' && strtolower((string)$scan->get_attribute('rel')) === 'stylesheet') {
                $href = FrontendAssets::absolute((string)$scan->get_attribute('href'), $page);
                $versions[$href] = FrontendAssets::version($href);
                $file = FrontendAssets::local($href);
                if ($file && filesize($file) < 1048576) { $css .= file_get_contents($file); }
                $base = $href;
            } else { $base = $page; }
            if ($tag === 'STYLE') { $css .= $scan->get_modifiable_text(); }
            if ($css !== '') {
                // URL discovery only; this expression never removes or rewrites CSS rules.
                preg_match_all('~url\(\s*["\']?([^"\')]+)~i', $css, $matches);
                foreach ($matches[1] as $url) {
                    $url = FrontendAssets::absolute($url, $base);
                    if (preg_match('~\.(avif|webp|png|jpe?g|gif|svg)(\?|$)~i', $url)
                        && !FrontendAssets::matches($url, $exclusions['urls'] ?? array())) {
                        $images[$url] = true; $versions[$url] = FrontendAssets::version($url);
                    }
                }
            }
        }
        $key = hash('sha256', $page . wp_json_encode($fingerprint) . wp_json_encode($versions) . wp_json_encode($exclusions) . FrontendAssets::revision());
        $known = $learn ? get_transient('wps_lcp_' . $key) : array();
        $known = is_array($known) ? $known : array();
        $hints = ''; $mainUrls = array(); $mainImages = array();
        foreach ($known as $viewport => $url) {
            if (!isset($images[$url]) || !in_array($viewport, array('mobile', 'desktop'), true)) { continue; }
            $mainUrls[] = $url;
            foreach ($owners[$url] ?? array() as $owner) { $mainImages[$owner] = true; }
            $media = $viewport === 'mobile' ? '(max-width: 767px)' : '(min-width: 768px)';
            if (!empty($sources[$url]['media'])) {
                // Complex media lists cannot safely be intersected; retain original discovery instead.
                if (strpos($sources[$url]['media'], ',') !== false || preg_match('/\bnot\b/i', $sources[$url]['media'])) { continue; }
                $media = $sources[$url]['media'] . ' and ' . $media;
            }
            $hint = '<link rel="preload" as="image" fetchpriority="high" href="' . esc_url($url) . '" media="' . esc_attr($media) . '"';
            if (!empty($sources[$url]['type'])) { $hint .= ' type="' . esc_attr($sources[$url]['type']) . '"'; }
            foreach (array('crossorigin', 'referrerpolicy') as $attribute) {
                if (isset($sources[$url][$attribute])) {
                    $hint .= ' ' . $attribute . '="' . esc_attr(is_string($sources[$url][$attribute]) ? $sources[$url][$attribute] : '') . '"';
                }
            }
            // Only the measured picture source's responsive set participates, never other art directions.
            if (!empty($sources[$url]['set'])) {
                $hint .= ' imagesrcset="' . esc_attr($sources[$url]['set']) . '" imagesizes="' . esc_attr($sources[$url]['sizes'] ?: '100vw') . '"';
            }
            $hints .= $hint . ' data-wps-lcp="' . $viewport . '">';
        }
        $edit = new \WP_HTML_Tag_Processor($html);
        $index = 0; $pictureExcluded = false;
        while ($edit->next_tag(array('tag_closers' => 'visit'))) {
            if ($edit->get_tag() === 'PICTURE') {
                $pictureExcluded = !$edit->is_tag_closer() && (
                    FrontendAssets::matches((string)$edit->get_attribute('id'), $exclusions['images'] ?? array())
                    || FrontendAssets::matches((string)$edit->get_attribute('class'), $exclusions['classes'] ?? array()));
            }
            if ($edit->get_tag() !== 'IMG' || $edit->is_tag_closer()) { continue; }
            $index++;
            $src = FrontendAssets::absolute((string)$edit->get_attribute('src'), $page);
            $set = (string)$edit->get_attribute('srcset');
            $main = strtolower((string)$edit->get_attribute('fetchpriority')) === 'high'
                || $edit->get_attribute('data-wps-lcp') !== null || isset($mainImages[$index]);
            foreach ($mainUrls as $url) {
                if ($url === $src || strpos($set, $url) !== false || strpos($set, wp_parse_url($url, PHP_URL_PATH) ?: $url) !== false) { $main = true; }
            }
            $exclude = $pictureExcluded || FrontendAssets::matches($src . ' ' . $set, $exclusions['urls'] ?? array())
                || FrontendAssets::matches((string)$edit->get_attribute('id'), $exclusions['images'] ?? array())
                || FrontendAssets::matches((string)$edit->get_attribute('class'), $exclusions['classes'] ?? array());
            if ($main || $exclude) {
                $edit->set_attribute('loading', 'eager');
                if ($main) { $edit->set_attribute('fetchpriority', 'high'); }
            } elseif ($lazy && $edit->get_attribute('loading') === null) { $edit->set_attribute('loading', 'lazy'); }
        }
        $html = $edit->get_updated_html();
        if ($learn && $images && FrontendAssets::eligible()) {
            // Cached pages carry a signed, expiring manifest, not an anonymous unrestricted write API.
            $expires = time() + 7 * DAY_IN_SECONDS;
            set_transient('wps_lcp_manifest_' . $key, array('urls' => $images, 'revision' => FrontendAssets::revision(), 'page' => $page), 8 * DAY_IN_SECONDS);
            $config = array('endpoint' => admin_url('admin-ajax.php'), 'key' => $key, 'expires' => $expires,
                'token' => hash_hmac('sha256', $key . ':' . $expires, wp_salt('auth')), 'exclusions' => $exclusions);
            $hints .= '<script id="wps-lcp-config" type="application/json">' . wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
            $hints .= '<script defer src="' . esc_url(FrontendAssets::runtime('lcp-images.js')) . '"></script>';
        }
        if ($learn) { FrontendAssets::diagnostic('lcp', array('preloads' => $known, 'manifest_images' => count($images), 'revision' => $key)); }
        return FrontendAssets::head($html, $hints);
    }

    public static function receive(): void
    {
        foreach (array('key', 'expires', 'token', 'viewport', 'url') as $field) {
            if (!isset($_POST[$field]) || !is_scalar($_POST[$field]) || strlen((string)$_POST[$field]) > 4096) {
                wp_send_json_error('Invalid measurement fields', 400);
            }
        }
        $key = (string)($_POST['key'] ?? ''); $expiry = (int)($_POST['expires'] ?? 0);
        $token = (string)($_POST['token'] ?? '');
        $viewport = (string)($_POST['viewport'] ?? '');
        $url = FrontendAssets::absolute(wp_unslash($_POST['url'] ?? ''), home_url('/'));
        $manifest = preg_match('/^[a-f0-9]{64}$/', $key) ? get_transient('wps_lcp_manifest_' . $key) : false;
        if (!$manifest || $expiry < time() || $expiry > time() + 8 * DAY_IN_SECONDS
            || !hash_equals(hash_hmac('sha256', $key . ':' . $expiry, wp_salt('auth')), $token)
            || $manifest['revision'] !== FrontendAssets::revision() || !isset($manifest['urls'][$url])
            || !in_array($viewport, array('mobile', 'desktop'), true)) { wp_send_json_error('Invalid or expired measurement', 403); }
        $known = get_transient('wps_lcp_' . $key) ?: array();
        if (($known[$viewport] ?? '') !== $url) {
            $rate = 'wps_lcp_rate_' . $key . '_' . $viewport;
            if (get_transient($rate)) { wp_send_json_success(); }
            set_transient($rate, true, MINUTE_IN_SECONDS);
            $known[$viewport] = $url;
            set_transient('wps_lcp_' . $key, $known, 7 * DAY_IN_SECONDS);
            do_action('wps_frontend_page_ready', $manifest['page']);
        }
        wp_send_json_success();
    }
}
