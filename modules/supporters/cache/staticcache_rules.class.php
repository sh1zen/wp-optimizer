<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

use WPS\core\StringHelper;

class StaticCacheRules
{
    private const CONTEXT = 'cache';
    private const ENTRY_OPTION = 'static_cache_entry';
    private const STATS_OPTION = 'static_cache_rule_stats';

    public static function normalize_rules(array $rules): array
    {
        $normalized = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $pattern = trim((string)($rule['pattern'] ?? ''));
            if ($pattern === '') {
                continue;
            }

            $id = sanitize_key((string)($rule['id'] ?? ''));
            if ($id === '') {
                $id = self::generate_rule_id($pattern);
            }

            $name = trim((string)($rule['name'] ?? ''));
            $mode = self::normalize_rule_mode((string)($rule['mode'] ?? 'include'));

            $normalized[] = [
                'id'         => $id,
                'name'       => $name !== '' ? sanitize_text_field($name) : $pattern,
                'pattern'    => $pattern,
                'mode'       => $mode,
                'active'     => !empty($rule['active']),
                'created_at' => absint($rule['created_at'] ?? time()),
            ];
        }

        return $normalized;
    }

    public static function create_rule(string $name, string $pattern, string $mode = 'include'): array
    {
        $pattern = trim($pattern);
        $name = trim($name);

        return [
            'id'         => self::generate_rule_id($pattern),
            'name'       => $name !== '' ? sanitize_text_field($name) : $pattern,
            'pattern'    => $pattern,
            'mode'       => self::normalize_rule_mode($mode),
            'active'     => true,
            'created_at' => time(),
        ];
    }

    public static function normalize_rule_mode(string $mode): string
    {
        return in_array($mode, ['include', 'exclude'], true) ? $mode : 'include';
    }

    private static function generate_rule_id(string $pattern): string
    {
        return 'rule-' . substr(md5($pattern . '|' . microtime(true)), 0, 12);
    }

    public static function pattern_is_valid(string $pattern): bool
    {
        $pattern = trim($pattern);

        if ($pattern === '') {
            return false;
        }

        return self::compile_pattern($pattern) !== '';
    }

    public static function get_active_rules(array $rules): array
    {
        return array_values(array_filter(self::normalize_rules($rules), static function (array $rule): bool {
            return !empty($rule['active']);
        }));
    }

    public static function match_rule(array $rules, string $request_path): array
    {
        $request_path = trim($request_path, '/');
        $candidates = array_unique([$request_path, '/' . $request_path]);

        foreach (self::get_active_rules($rules) as $rule) {
            foreach ($candidates as $candidate) {
                if (self::pattern_matches($rule['pattern'], $candidate)) {
                    return $rule;
                }
            }
        }

        return [];
    }

    public static function split_rules_by_mode(array $rules): array
    {
        $include_rules = [];
        $exclude_rules = [];

        foreach (self::get_active_rules($rules) as $rule) {
            if (($rule['mode'] ?? 'include') === 'exclude') {
                $exclude_rules[] = $rule;
            }
            else {
                $include_rules[] = $rule;
            }
        }

        return [$include_rules, $exclude_rules];
    }

    private static function pattern_matches(string $pattern, string $subject): bool
    {
        $compiled_pattern = self::compile_pattern($pattern);
        if ($compiled_pattern === '') {
            return false;
        }

        set_error_handler(static function () {
        }, E_WARNING);
        $matched = (bool)preg_match($compiled_pattern, $subject);
        restore_error_handler();

        return $matched;
    }

    private static function compile_pattern(string $pattern): string
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return '';
        }

        if (StringHelper::str_is_valid_regex($pattern)) {
            return $pattern;
        }

        $raw_pattern = '#' . StringHelper::make_regex($pattern, '#') . '#iD';
        if (self::regex_compiles($raw_pattern)) {
            return $raw_pattern;
        }

        if (strpos($pattern, '*') !== false || strpos($pattern, '?') !== false) {
            $wildcard_pattern = preg_quote($pattern, '#');
            $wildcard_pattern = str_replace(['\*', '\?'], ['.*', '.'], $wildcard_pattern);

            return '#' . $wildcard_pattern . '#iD';
        }

        return '';
    }

    private static function regex_compiles(string $pattern): bool
    {
        set_error_handler(static function () {
        }, E_WARNING);
        $is_valid = preg_match($pattern, '') !== false;
        restore_error_handler();

        return $is_valid;
    }

    public static function record_hit(string $rule_id, string $namespace = 'static'): void
    {
        self::increment_stats($rule_id, 'hits', 'last_hit', $namespace);
    }

    public static function record_miss(string $rule_id, string $namespace = 'static'): void
    {
        self::increment_stats($rule_id, 'misses', 'last_miss', $namespace);
    }

    public static function record_write(string $rule_id, string $cache_key, string $request_path, int $bytes, string $namespace = 'static', array $metadata = array()): void
    {
        if ($cache_key === '') {
            return;
        }

        wps('wpopt')->options->update(
            $cache_key,
            self::entry_option($namespace),
            array_merge(
                [
                'rule_id'    => $rule_id,
                'path'       => $request_path,
                'bytes'      => max(0, $bytes),
                'created_at' => time(),
                'updated_at' => time(),
                ],
                $metadata
            ),
            self::CONTEXT
        );

        if ($rule_id !== '') {
            self::increment_stats($rule_id, 'writes', 'last_write', $namespace);
        }
    }

    private static function increment_stats(string $rule_id, string $counter, string $timestamp_field, string $namespace = 'static'): void
    {
        if ($rule_id === '') {
            return;
        }

        $stats = self::get_rule_stats($rule_id, $namespace);
        $stats[$counter] = absint($stats[$counter] ?? 0) + 1;
        $stats[$timestamp_field] = time();

        wps('wpopt')->options->update($rule_id, self::stats_option($namespace), $stats, self::CONTEXT);
    }

    public static function get_rule_stats(string $rule_id, string $namespace = 'static'): array
    {
        return wp_parse_args(
            (array)wps('wpopt')->options->get($rule_id, self::stats_option($namespace), self::CONTEXT, []),
            [
                'hits'       => 0,
                'misses'     => 0,
                'writes'     => 0,
                'last_hit'   => 0,
                'last_miss'  => 0,
                'last_write' => 0,
            ]
        );
    }

    public static function get_rules_report(array $rules, string $cache_group, string $namespace = 'static'): array
    {
        $entries = self::get_entries($namespace);
        $report = [];

        foreach (self::normalize_rules($rules) as $rule) {
            $stats = self::get_rule_stats($rule['id'], $namespace);
            $stats['entries'] = 0;
            $stats['bytes'] = 0;

            foreach ($entries as $entry) {
                if (($entry['value']['rule_id'] ?? '') !== $rule['id']) {
                    continue;
                }

                $path = wps('wpopt')->storage->get_path($cache_group, $entry['obj_id']);
                if (!is_file($path)) {
                    continue;
                }

                $stats['entries']++;
                $stats['bytes'] += (int)filesize($path);
            }

            $report[$rule['id']] = [
                'rule'  => $rule,
                'stats' => $stats,
            ];
        }

        return $report;
    }

    private static function get_entries(string $namespace = 'static'): array
    {
        return (array)wps('wpopt')->options->get_all(self::entry_option($namespace), self::CONTEXT, []);
    }

    public static function clear_rule(string $rule_id, string $cache_group, string $namespace = 'static'): int
    {
        $deleted = 0;

        foreach (self::get_entries($namespace) as $entry) {
            if (($entry['value']['rule_id'] ?? '') !== $rule_id) {
                continue;
            }

            $cache_key = (string)$entry['obj_id'];
            $deleted += wps('wpopt')->storage->delete($cache_group, $cache_key);
            wps('wpopt')->options->remove($cache_key, self::entry_option($namespace), self::CONTEXT);
        }

        return $deleted;
    }

    public static function clear_by_dependencies(array $criteria, string $cache_group, string $namespace = 'static'): int
    {
        $criteria = self::normalize_dependency_map($criteria);

        if (empty($criteria)) {
            return 0;
        }

        $deleted = 0;

        foreach (self::get_entries($namespace) as $entry) {
            $value = is_array($entry['value'] ?? null) ? $entry['value'] : array();

            if (!self::entry_matches_dependencies($value, $criteria) && ($namespace !== 'wp_query' || self::entry_has_dependency_metadata($value))) {
                continue;
            }

            $cache_key = (string)$entry['obj_id'];
            $deleted += wps('wpopt')->storage->delete($cache_group, $cache_key);
            wps('wpopt')->options->remove($cache_key, self::entry_option($namespace), self::CONTEXT);
        }

        return $deleted;
    }

    private static function entry_has_dependency_metadata(array $entry): bool
    {
        foreach (array('post_ids', 'post_types', 'authors', 'terms', 'taxonomies') as $key) {
            if (array_key_exists($key, $entry)) {
                return true;
            }
        }

        return false;
    }

    public static function clear_paths(array $request_paths, string $cache_group, string $namespace = 'static'): int
    {
        $paths = array_values(array_unique(array_map(static function ($path): string {
            return trim((string)$path, '/');
        }, $request_paths)));

        if (empty($paths)) {
            return 0;
        }

        $deleted = 0;

        foreach (self::get_entries($namespace) as $entry) {
            $path = trim((string)($entry['value']['path'] ?? ''), '/');

            if (!in_array($path, $paths, true)) {
                continue;
            }

            $cache_key = (string)$entry['obj_id'];
            $deleted += wps('wpopt')->storage->delete($cache_group, $cache_key);
            wps('wpopt')->options->remove($cache_key, self::entry_option($namespace), self::CONTEXT);
        }

        return $deleted;
    }

    private static function entry_matches_dependencies(array $entry, array $criteria): bool
    {
        foreach ($criteria as $key => $values) {
            if (empty($values)) {
                continue;
            }

            $entry_values = self::normalize_dependency_values((array)($entry[$key] ?? array()));

            if (!empty(array_intersect($entry_values, $values))) {
                return true;
            }
        }

        return false;
    }

    private static function normalize_dependency_map(array $map): array
    {
        $normalized = array();

        foreach ($map as $key => $values) {
            $values = self::normalize_dependency_values((array)$values);

            if (!empty($values)) {
                $normalized[sanitize_key((string)$key)] = $values;
            }
        }

        return $normalized;
    }

    private static function normalize_dependency_values(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(static function ($value): string {
            return sanitize_key((string)$value);
        }, $values))));
    }

    public static function clear_all_entries(string $namespace = 'static'): void
    {
        wps('wpopt')->options->remove_all(self::CONTEXT, self::entry_option($namespace));
    }

    public static function delete_rule_stats(string $rule_id, string $namespace = 'static'): void
    {
        wps('wpopt')->options->remove($rule_id, self::stats_option($namespace), self::CONTEXT);
    }

    private static function entry_option(string $namespace): string
    {
        $namespace = sanitize_key($namespace);

        return $namespace === '' || $namespace === 'static' ? self::ENTRY_OPTION : "{$namespace}_cache_entry";
    }

    private static function stats_option(string $namespace): string
    {
        $namespace = sanitize_key($namespace);

        return $namespace === '' || $namespace === 'static' ? self::STATS_OPTION : "{$namespace}_cache_rule_stats";
    }
}
