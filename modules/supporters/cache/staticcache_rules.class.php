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
            $normalized[] = [
                'id'         => $id,
                'name'       => $name !== '' ? sanitize_text_field($name) : $pattern,
                'pattern'    => $pattern,
                'active'     => !empty($rule['active']),
                'created_at' => absint($rule['created_at'] ?? time()),
            ];
        }

        return $normalized;
    }

    public static function create_rule(string $name, string $pattern): array
    {
        $pattern = trim($pattern);
        $name = trim($name);

        return [
            'id'         => self::generate_rule_id($pattern),
            'name'       => $name !== '' ? sanitize_text_field($name) : $pattern,
            'pattern'    => $pattern,
            'active'     => true,
            'created_at' => time(),
        ];
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

        if (StringHelper::str_is_valid_regex($pattern)) {
            return true;
        }

        set_error_handler(static function () {
        }, E_WARNING);
        $is_valid = preg_match('#' . StringHelper::make_regex($pattern, '#') . '#iD', '') !== false;
        restore_error_handler();

        return $is_valid;
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

    private static function pattern_matches(string $pattern, string $subject): bool
    {
        set_error_handler(static function () {
        }, E_WARNING);

        if (StringHelper::str_is_valid_regex($pattern)) {
            $matched = (bool)preg_match($pattern, $subject);
            restore_error_handler();

            return $matched;
        }

        $matched = (bool)preg_match('#' . StringHelper::make_regex($pattern, '#') . '#iD', $subject);
        restore_error_handler();

        return $matched;
    }

    public static function record_hit(string $rule_id): void
    {
        self::increment_stats($rule_id, 'hits', 'last_hit');
    }

    public static function record_miss(string $rule_id): void
    {
        self::increment_stats($rule_id, 'misses', 'last_miss');
    }

    public static function record_write(string $rule_id, string $cache_key, string $request_path, int $bytes): void
    {
        if ($rule_id === '' || $cache_key === '') {
            return;
        }

        wps('wpopt')->options->update(
            $cache_key,
            self::ENTRY_OPTION,
            [
                'rule_id'    => $rule_id,
                'path'       => $request_path,
                'bytes'      => max(0, $bytes),
                'created_at' => time(),
                'updated_at' => time(),
            ],
            self::CONTEXT
        );

        self::increment_stats($rule_id, 'writes', 'last_write');
    }

    private static function increment_stats(string $rule_id, string $counter, string $timestamp_field): void
    {
        if ($rule_id === '') {
            return;
        }

        $stats = self::get_rule_stats($rule_id);
        $stats[$counter] = absint($stats[$counter] ?? 0) + 1;
        $stats[$timestamp_field] = time();

        wps('wpopt')->options->update($rule_id, self::STATS_OPTION, $stats, self::CONTEXT);
    }

    public static function get_rule_stats(string $rule_id): array
    {
        return wp_parse_args(
            (array)wps('wpopt')->options->get($rule_id, self::STATS_OPTION, self::CONTEXT, []),
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

    public static function get_rules_report(array $rules, string $cache_group): array
    {
        $entries = self::get_entries();
        $report = [];

        foreach (self::normalize_rules($rules) as $rule) {
            $stats = self::get_rule_stats($rule['id']);
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

    private static function get_entries(): array
    {
        return (array)wps('wpopt')->options->get_all(self::ENTRY_OPTION, self::CONTEXT, []);
    }

    public static function clear_rule(string $rule_id, string $cache_group): int
    {
        $deleted = 0;

        foreach (self::get_entries() as $entry) {
            if (($entry['value']['rule_id'] ?? '') !== $rule_id) {
                continue;
            }

            $cache_key = (string)$entry['obj_id'];
            $deleted += wps('wpopt')->storage->delete($cache_group, $cache_key);
            wps('wpopt')->options->remove($cache_key, self::ENTRY_OPTION, self::CONTEXT);
        }

        return $deleted;
    }

    public static function clear_all_entries(): void
    {
        wps('wpopt')->options->remove_all(self::CONTEXT, self::ENTRY_OPTION);
    }

    public static function delete_rule_stats(string $rule_id): void
    {
        wps('wpopt')->options->remove($rule_id, self::STATS_OPTION, self::CONTEXT);
    }
}
