<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

use WPS\core\Settings;
use WPS\core\StringHelper;

class CacheRequestPolicy
{
    private array $options;

    public function __construct(array $options)
    {
        $this->options = $options;
    }

    public function rule_cacheability(string $request_path): array
    {
        $rules = StaticCacheRules::get_active_rules((array)Settings::get_option($this->options, 'rules', []));
        if (empty($rules)) {
            return array('cacheable' => true, 'rule_id' => '', 'reason' => '');
        }

        [$include_rules, $exclude_rules] = StaticCacheRules::split_rules_by_mode($rules);

        if (StaticCacheRules::match_rule($exclude_rules, $request_path)) {
            return array('cacheable' => false, 'rule_id' => '', 'reason' => 'exclude_rule');
        }

        $matched_rule = StaticCacheRules::match_rule($include_rules, $request_path);
        if (!empty($matched_rule)) {
            return array('cacheable' => true, 'rule_id' => (string)$matched_rule['id'], 'reason' => '');
        }

        if ((bool)Settings::get_option($this->options, 'cache_include_rules_only', false)) {
            return array('cacheable' => false, 'rule_id' => '', 'reason' => 'include_rule_required');
        }

        return array('cacheable' => true, 'rule_id' => '', 'reason' => '');
    }

    public function has_no_cache_cookie(bool $default_enabled = false): bool
    {
        if (!(bool)Settings::get_option($this->options, 'no_cache_cookies_enabled', $default_enabled)) {
            return false;
        }

        if (empty($_COOKIE) || !is_array($_COOKIE)) {
            return false;
        }

        $patterns = Settings::get_option($this->options, 'no_cache_cookies', array());
        if (empty($patterns) || !is_array($patterns)) {
            return true;
        }

        foreach (array_keys($_COOKIE) as $cookie_name) {
            foreach (array_filter($patterns) as $pattern) {
                if ($this->pattern_matches((string)$pattern, (string)$cookie_name)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function user_agent_is_excluded(): bool
    {
        if (!(bool)Settings::get_option($this->options, 'user_agent_exclusions_enabled', false)) {
            return false;
        }

        $patterns = Settings::get_option($this->options, 'user_agent_exclusions', array());
        if (empty($patterns) || !is_array($patterns)) {
            return false;
        }

        $user_agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($user_agent === '') {
            return false;
        }

        foreach (array_filter($patterns) as $pattern) {
            if ($this->pattern_matches((string)$pattern, $user_agent)) {
                return true;
            }
        }

        return false;
    }

    public static function normalize_request_path(): string
    {
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = rawurldecode((string)($path ?: '/'));
        $path = preg_replace('#/+#', '/', $path);

        if (function_exists('home_url')) {
            $site_path = parse_url(home_url('/'), PHP_URL_PATH);
            $site_path = '/' . trim((string)$site_path, '/');

            if ($site_path !== '/' && (stripos($path, $site_path . '/') === 0 || strcasecmp($path, $site_path) === 0)) {
                $path = substr($path, strlen($site_path));
            }
        }

        return trim((string)$path, '/');
    }

    public static function normalized_query_string(array $excluded_keys = array()): string
    {
        if (empty($_GET) || !is_array($_GET)) {
            return '';
        }

        $args = function_exists('wp_unslash') ? wp_unslash($_GET) : $_GET;

        foreach ($excluded_keys as $key) {
            unset($args[$key]);
        }

        if (empty($args)) {
            return '';
        }

        ksort($args);

        return http_build_query($args, '', '&', PHP_QUERY_RFC3986);
    }

    public static function pattern_matches_text(string $pattern, string $value): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return false;
        }

        if (StringHelper::str_is_valid_regex($pattern)) {
            set_error_handler(static function () {
            }, E_WARNING);
            $matched = preg_match($pattern, $value) === 1;
            restore_error_handler();

            return $matched;
        }

        return stripos($value, $pattern) !== false;
    }

    private function pattern_matches(string $pattern, string $value): bool
    {
        if (!empty($this->options['plain_text_patterns_only'])) {
            $pattern = trim($pattern);

            return $pattern !== '' && stripos($value, $pattern) !== false;
        }

        return self::pattern_matches_text($pattern, $value);
    }
}
