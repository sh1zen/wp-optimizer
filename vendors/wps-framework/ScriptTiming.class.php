<?php
namespace WPS\core;

/** Native WordPress defer; opt-in background-script delay with dependency guards. */
final class ScriptTiming
{
    private array $config;
    private array $delayed = array();
    private array $reasons = array();
    private array $pending = array();
    private bool $loader = false;

    public function __construct(array $config)
    {
        $this->config = $config;
        add_action('wp_print_scripts', array($this, 'plan'), 0);
        add_action('wp_print_footer_scripts', array($this, 'plan'), 0);
        add_filter('script_loader_tag', array($this, 'tag'), PHP_INT_MAX, 3);
        add_action('shutdown', array($this, 'report'));
    }

    private function excluded(string $handle, string $url): bool
    {
        return in_array($handle, FrontendAssets::rules($this->config['exclude_handles'] ?? array()), true)
            || FrontendAssets::matches($url, $this->config['exclude_urls'] ?? array());
    }

    public function plan(): void
    {
        global $wp_version;
        if (!FrontendAssets::eligible() || FrontendAssets::matches(FrontendAssets::page(), $this->config['exclude_pages'] ?? array())
            || !apply_filters('wps_script_timing_allowed', true)) { return; }
        $scripts = wp_scripts();
        $active = array();
        $visit = function ($handle) use (&$visit, &$active, $scripts) {
            if (isset($active[$handle]) || !isset($scripts->registered[$handle])) { return; }
            $active[$handle] = $scripts->registered[$handle];
            foreach ($active[$handle]->deps as $dependency) { $visit($dependency); }
        };
        foreach ($scripts->queue as $handle) { $visit($handle); }
        $selected = array();
        foreach ($active as $handle => $script) {
            $url = (string)$script->src;
            $reason = 'immediate: not selected for delay';
            // Interactive infrastructure remains available before any visitor action.
            $critical = preg_match('~jquery|consent|cookie|checkout|woocommerce|wc-|captcha|form|contact|menu|navigation|wps-~i', $handle . ' ' . $url);
            $allow = in_array($handle, FrontendAssets::rules($this->config['delay_handles'] ?? array()), true)
                || FrontendAssets::matches($url, $this->config['delay_urls'] ?? array());
            if ($this->excluded($handle, $url)) { $reason = 'immediate: manual exclusion'; }
            elseif ($critical) { $reason = 'immediate: interactive infrastructure'; }
            elseif (!empty($this->config['delay']) && $allow && $url !== '' && !in_array($handle, $scripts->done, true)) {
                // Localized data is printed outside script_loader_tag. Never detach a potentially executable block.
                $inline = implode("\n", array_merge((array)($script->extra['before'] ?? array()), (array)($script->extra['after'] ?? array())));
                if (empty($script->extra['group']) || !empty($script->extra['data']) || !empty($script->extra['conditional']) || !empty($script->extra['strategy'])
                    || preg_match('/DOMContentLoaded|document\.write|addEventListener\s*\(\s*["\']load["\']/', $inline)) {
                    $reason = 'immediate: head placement, separate inline data, lifecycle handler or existing strategy';
                } else { $selected[$handle] = true; $reason = 'delay: explicit allowlist'; }
            }
            $this->reasons[$handle] = $reason;
        }
        // Fixed point: a selected dependency cannot be delayed under an immediate dependent.
        do {
            $changed = false;
            foreach ($active as $handle => $script) {
                if (isset($selected[$handle]) || (isset($this->delayed[$handle]) && in_array($handle, $scripts->done, true))) { continue; }
                foreach ($script->deps as $dependency) {
                    if (isset($selected[$dependency])) {
                        unset($selected[$dependency]); $changed = true;
                        $this->reasons[$dependency] = 'immediate: required by ' . $handle;
                    }
                }
            }
        } while ($changed);
        // Reconcile unprinted handles again in the footer: enqueueing may have added dependents.
        foreach ($this->pending + $this->delayed as $handle => $_) {
            if (!isset($selected[$handle]) && !in_array($handle, $scripts->done, true)) {
                unset($this->delayed[$handle]);
                wp_script_add_data($handle, 'wps_delay_selected', false);
            }
        }
        $this->pending = $selected;
        if (!doing_action('wp_print_scripts')) { $this->delayed += $selected; }
        foreach ($selected as $handle => $_) { wp_script_add_data($handle, 'wps_delay_selected', true); }
        foreach ($active as $handle => $script) {
            if (isset($this->delayed[$handle]) || !empty($script->extra['wps_delay_selected'])) { continue; }
            if (!empty($this->config['defer']) && !$this->excluded($handle, (string)$script->src)
                && !preg_match('~jquery|consent|cookie|checkout|woocommerce|wc-|captcha|form|contact|menu|navigation~i', $handle . ' ' . $script->src)
                && empty($script->extra['strategy']) && version_compare($wp_version, '6.3', '>=')) {
                wp_script_add_data($handle, 'strategy', 'defer');
                $this->reasons[$handle] .= '; defer requested (WordPress resolves final strategy)';
            }
        }
        FrontendAssets::diagnostic('scripts', $this->reasons);
    }

    public function report(): void
    {
        if ($this->reasons) { FrontendAssets::diagnostic('scripts', $this->reasons); }
    }

    public function tag(string $tag, string $handle, string $src): string
    {
        if (!class_exists('WP_HTML_Tag_Processor')) { return $tag; }
        if (!isset($this->delayed[$handle])) {
            $actual = new \WP_HTML_Tag_Processor($tag);
            while ($actual->next_tag('SCRIPT')) {
                if ($actual->get_attribute('src') !== null) {
                    $strategy = $actual->get_attribute('defer') !== null ? 'defer' : ($actual->get_attribute('async') !== null ? 'async' : 'blocking');
                    $this->reasons[$handle] = ($this->reasons[$handle] ?? 'existing script') . '; actual: ' . $strategy;
                    break;
                }
            }
            return $tag;
        }
        $runtime = $this->loader ? '' : FrontendAssets::inline_runtime('script-delay.js', 'wps-delay-runtime');
        if (!$this->loader && $runtime === '') {
            $this->reasons[$handle] = 'immediate: delay runtime unavailable';
            return $tag;
        }
        $processor = new \WP_HTML_Tag_Processor($tag);
        while ($processor->next_tag('SCRIPT')) {
            $type = (string)$processor->get_attribute('type');
            if (!in_array(strtolower($type), array('', 'text/javascript', 'application/javascript', 'module'), true)
                || $processor->get_attribute('nomodule') !== null) {
                $this->reasons[$handle] = 'immediate: unsupported script type';
                return $tag;
            }
            $processor->set_attribute('data-wps-type', $type);
            $processor->set_attribute('type', 'application/x-wps-delayed');
            $processor->set_attribute('data-wps-handle', $handle);
            $processor->set_attribute('data-wps-deps', implode(' ', wp_scripts()->registered[$handle]->deps));
            $url = $processor->get_attribute('src');
            if ($url !== null) {
                $processor->set_attribute('data-wps-src', $url);
                $processor->remove_attribute('src');
            }
            $processor->remove_attribute('async'); $processor->remove_attribute('defer');
        }
        $output = $processor->get_updated_html();
        $this->reasons[$handle] = 'delay applied: explicit allowlist, ordered inline/external bundle';
        if (!$this->loader) {
            $this->loader = true;
            $output = $runtime . $output;
        }
        return $output;
    }
}
