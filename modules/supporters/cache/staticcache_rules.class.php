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
    private const STATS_OPTION = 'static_cache_rule_stats';
    private const ENTRY_BATCH_SIZE = 250;
    private const INDEX_WRITE_BATCH_SIZE = 250;
    private const BASE_DEPENDENCY_TYPE = '__entry';

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
        return self::match_normalized_rules(self::get_active_rules($rules), $request_path);
    }

    /** Match active normalized rules in their original order. */
    private static function match_normalized_rules(array $rules, string $request_path): array
    {
        $request_path = trim($request_path, '/');

        foreach ($rules as $rule) {
            if (self::pattern_matches($rule['pattern'], $request_path)) {
                return $rule;
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

    /** Normalize once, then apply exclusion precedence and the first matching include. */
    public static function cacheability(array $rules, string $request_path, bool $include_only = false): array
    {
        [$include_rules, $exclude_rules] = self::split_rules_by_mode($rules);

        if (!$include_rules && !$exclude_rules) {
            return array('cacheable' => true, 'rule_id' => '', 'reason' => '');
        }

        if (self::match_normalized_rules($exclude_rules, $request_path)) {
            return array('cacheable' => false, 'rule_id' => '', 'reason' => 'exclude_rule');
        }

        $matched_rule = self::match_normalized_rules($include_rules, $request_path);
        if ($matched_rule) {
            return array('cacheable' => true, 'rule_id' => (string)$matched_rule['id'], 'reason' => '');
        }

        return $include_only
            ? array('cacheable' => false, 'rule_id' => '', 'reason' => 'include_rule_required')
            : array('cacheable' => true, 'rule_id' => '', 'reason' => '');
    }

    /** Compile once and test the path with and without its leading slash. */
    private static function pattern_matches(string $pattern, string $subject): bool
    {
        $compiled_pattern = self::compile_pattern($pattern);
        if ($compiled_pattern === '') {
            return false;
        }

        set_error_handler(static function () {
        }, E_WARNING);
        $matched = (bool)preg_match($compiled_pattern, $subject)
            || (bool)preg_match($compiled_pattern, '/' . $subject);
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

        if (!self::cache_entries_table_is_ready()) {
            return;
        }

        $namespace = self::normalize_namespace($namespace);
        $request_path = trim($request_path, '/');
        $entry = array_merge(
            [
                'rule_id'    => $rule_id,
                'path'       => $request_path,
                'bytes'      => max(0, $bytes),
                'created_at' => time(),
                'updated_at' => time(),
            ],
            $metadata
        );

        $dependencies = self::normalize_dependency_map($metadata);

        self::delete_index_rows($cache_key, $namespace);
        self::insert_index_rows($cache_key, $namespace, $entry, $dependencies);

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

        wps('wpopt')->options->upsert($rule_id, self::stats_option($namespace), $stats, self::CONTEXT);
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

    /** Report SQL-index totals; $cache_group is retained for named and positional callers. */
    public static function get_rules_report(array $rules, string $cache_group, string $namespace = 'static'): array
    {
        $report = [];

        foreach (self::normalize_rules($rules) as $rule) {
            $stats = self::get_rule_stats($rule['id'], $namespace);
            $stats['entries'] = 0;
            $stats['bytes'] = 0;

            $report[$rule['id']] = [
                'rule'  => $rule,
                'stats' => $stats,
            ];
        }

        if (empty($report)) {
            return $report;
        }

        if (!self::cache_entries_table_is_ready()) {
            return $report;
        }

        global $wpdb;

        $namespace = self::normalize_namespace($namespace);
        $rule_ids = array_keys($report);
        $placeholders = implode(', ', array_fill(0, count($rule_ids), '%s'));
        $params = array_merge([$namespace, self::BASE_DEPENDENCY_TYPE], $rule_ids);
        $sql = 'SELECT rule_id, COUNT(*) AS entries, COALESCE(SUM(bytes), 0) AS bytes FROM ' . self::cache_entries_table() . " WHERE namespace = %s AND dependency_type = %s AND rule_id IN ($placeholders) GROUP BY rule_id";
        $rows = (array)$wpdb->get_results(self::prepare_sql($sql, $params), ARRAY_A);

        foreach ($rows as $row) {
            $rule_id = (string)($row['rule_id'] ?? '');

            if ($rule_id !== '' && isset($report[$rule_id])) {
                $report[$rule_id]['stats']['entries'] = absint($row['entries'] ?? 0);
                $report[$rule_id]['stats']['bytes'] = absint($row['bytes'] ?? 0);
            }
        }

        return $report;
    }

    public static function clear_rule(string $rule_id, string $cache_group, string $namespace = 'static'): int
    {
        $rule_id = sanitize_key($rule_id);

        if ($rule_id === '') {
            return 0;
        }

        return self::clear_indexed_entries(
            ['dependency_type = %s AND rule_id = %s'],
            [self::BASE_DEPENDENCY_TYPE, $rule_id],
            $cache_group,
            $namespace
        );
    }

    public static function clear_by_dependencies(array $criteria, string $cache_group, string $namespace = 'static'): int
    {
        $criteria = self::normalize_dependency_map($criteria);

        if (empty($criteria)) {
            return 0;
        }

        $where = [];
        $params = [];

        foreach ($criteria as $dependency_type => $values) {
            $hashes = array_map([self::class, 'hash_lookup_value'], $values);
            $where[] = 'dependency_type = %s AND dependency_value_hash IN (' . implode(', ', array_fill(0, count($hashes), '%s')) . ')';
            $params = array_merge($params, [$dependency_type], $hashes);
        }

        if (self::normalize_namespace($namespace) === 'wp_query') {
            $where[] = 'dependency_type = %s AND has_dependency_metadata = 0';
            $params[] = self::BASE_DEPENDENCY_TYPE;
        }

        return self::clear_indexed_entries($where, $params, $cache_group, $namespace);
    }

    public static function clear_paths(array $request_paths, string $cache_group, string $namespace = 'static'): int
    {
        $paths = array_values(array_unique(array_map(static function ($path): string {
            return trim((string)$path, '/');
        }, $request_paths)));

        if (empty($paths)) {
            return 0;
        }

        $hashes = array_map([self::class, 'hash_lookup_value'], $paths);

        return self::clear_indexed_entries(
            ['dependency_type = %s AND request_path_hash IN (' . implode(', ', array_fill(0, count($hashes), '%s')) . ')'],
            array_merge([self::BASE_DEPENDENCY_TYPE], $hashes),
            $cache_group,
            $namespace
        );
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

    private static function cache_entries_table(int $blog_id = 0): string
    {
        $table = defined('WPOPT_TABLE_CACHE_ENTRIES') ? (string)WPOPT_TABLE_CACHE_ENTRIES : '';
        if ($table !== '' && $blog_id && $blog_id !== get_current_blog_id()) {
            global $wpdb;
            $table = $wpdb->get_blog_prefix($blog_id) . substr($table, strlen($wpdb->prefix));
        }
        return $table;
    }

    private static function cache_entries_table_is_ready(int $blog_id = 0): bool
    {
        static $ready = array();
        $table = self::cache_entries_table($blog_id);

        if (isset($ready[$table])) {
            return $ready[$table];
        }

        if ($table === '') {
            return false;
        }

        global $wpdb;

        return $ready[$table] = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private static function normalize_namespace(string $namespace): string
    {
        $namespace = sanitize_key($namespace);

        return $namespace !== '' ? $namespace : 'static';
    }

    private static function hash_lookup_value(string $value): string
    {
        return md5($value);
    }

    /** Build shared entry fields once and insert bounded batches of dependency rows. */
    private static function insert_index_rows(string $cache_key, string $namespace, array $entry, array $dependencies): void
    {
        $request_path = trim((string)($entry['path'] ?? ''), '/');
        $common = array(self::normalize_namespace($namespace), $cache_key, self::hash_lookup_value($cache_key),
            sanitize_key((string)($entry['rule_id'] ?? '')), $request_path, self::hash_lookup_value($request_path),
            max(0, absint($entry['bytes'] ?? 0)));
        $timestamps = array(absint($entry['created_at'] ?? time()), absint($entry['updated_at'] ?? time()));
        $rows = array(array_merge($common, array(self::BASE_DEPENDENCY_TYPE, '', self::hash_lookup_value(''), !empty($dependencies) ? 1 : 0), $timestamps));
        foreach ($dependencies as $type => $values) {
            foreach ($values as $value) {
                $rows[] = array_merge($common, array($type, $value, self::hash_lookup_value($value), 1), $timestamps);
                if (count($rows) === self::INDEX_WRITE_BATCH_SIZE) {
                    self::write_index_batch($rows);
                    $rows = array();
                }
            }
        }
        if ($rows) { self::write_index_batch($rows); }
    }

    /** Publish a parameterized multi-row INSERT without per-dependency database calls. */
    private static function write_index_batch(array $rows): void
    {
        global $wpdb;
        $placeholders = array_fill(0, count($rows), '(%s, %s, %s, %s, %s, %s, %d, %s, %s, %s, %d, %d, %d)');
        $values = array();
        foreach ($rows as $row) { array_push($values, ...$row); }
        $sql = 'INSERT INTO ' . self::cache_entries_table()
            . ' (namespace, cache_key, cache_key_hash, rule_id, request_path, request_path_hash, bytes, dependency_type, dependency_value, dependency_value_hash, has_dependency_metadata, created_at, updated_at) VALUES '
            . implode(', ', $placeholders);
        $wpdb->query(self::prepare_sql($sql, $values));
    }

    private static function delete_index_rows(string $cache_key, string $namespace): void
    {
        if (!self::cache_entries_table_is_ready()) {
            return;
        }

        global $wpdb;

        $wpdb->delete(
            self::cache_entries_table(),
            [
                'namespace'      => self::normalize_namespace($namespace),
                'cache_key'      => $cache_key,
                'cache_key_hash' => self::hash_lookup_value($cache_key),
            ],
            ['%s', '%s', '%s']
        );
    }

    private static function clear_indexed_entries(array $where, array $params, string $cache_group, string $namespace): int
    {
        if (empty($where) || !self::cache_entries_table_is_ready()) {
            return 0;
        }

        global $wpdb;

        $deleted = 0;
        $namespace = self::normalize_namespace($namespace);

        do {
            $sql = 'SELECT DISTINCT cache_key FROM ' . self::cache_entries_table() . ' WHERE namespace = %s AND ((' . implode(') OR (', $where) . ')) LIMIT ' . self::ENTRY_BATCH_SIZE;
            $keys = (array)$wpdb->get_col(self::prepare_sql($sql, array_merge([$namespace], $params)));
            $keys = array_values(array_unique(array_filter(array_map('strval', $keys))));

            if (empty($keys)) {
                break;
            }

            foreach ($keys as $cache_key) {
                $deleted += wps('wpopt')->storage->delete($cache_group, $cache_key);
            }
            self::delete_index_batch($keys, $namespace, self::cache_entries_table());
        } while (count($keys) === self::ENTRY_BATCH_SIZE);

        return $deleted;
    }

    /** Remove every dependency row for a bounded batch of exact cache keys. */
    private static function delete_index_batch(array $keys, string $namespace, string $table, ?int $updated_before = null): void
    {
        if (!$keys) { return; }
        global $wpdb;
        $where = array();
        $params = array($namespace);
        foreach ($keys as $key) {
            $where[] = '(cache_key_hash = %s AND cache_key = %s)';
            $params[] = self::hash_lookup_value($key);
            $params[] = $key;
        }
        $sql = 'DELETE FROM ' . $table . ' WHERE namespace = %s AND (' . implode(' OR ', $where) . ')';
        if ($updated_before !== null) {
            $sql .= ' AND updated_at < %d';
            $params[] = $updated_before;
        }
        if ($wpdb->query(self::prepare_sql($sql, $params)) === false) {
            throw new \RuntimeException('Cannot remove cache index rows: ' . $wpdb->last_error);
        }
    }

    /** Reconcile the SQL index with disk after expiry cleanup, retaining files that survived. */
    public static function clear_missing_entries(string $cache_group, string $namespace, int $lifetime, int $blog_id = 0): void
    {
        if (!self::cache_entries_table_is_ready($blog_id)) { return; }
        global $wpdb;
        $table = self::cache_entries_table($blog_id);
        $namespace = self::normalize_namespace($namespace);
        $storage = wps('wpopt')->storage;
        $cursor = '';
        // Recent writes can still be waiting for Storage's shutdown autosave.
        $cutoff = time() - $lifetime;
        do {
            $sql = 'SELECT cache_key FROM ' . $table . ' WHERE namespace = %s AND dependency_type = %s AND cache_key_hash > %s AND updated_at < %d ORDER BY cache_key_hash LIMIT ' . self::ENTRY_BATCH_SIZE;
            $keys = $wpdb->get_col(self::prepare_sql($sql, array($namespace, self::BASE_DEPENDENCY_TYPE, $cursor, $cutoff)));
            if (!is_array($keys) || $wpdb->last_error !== '') {
                throw new \RuntimeException('Cannot read cache index rows: ' . $wpdb->last_error);
            }
            $missing = array();
            foreach ($keys as $key) {
                $path = $storage->get_path($cache_group, $key, $blog_id);
                clearstatcache(true, $path);
                if (!is_file($path)) { $missing[] = $key; }
                $cursor = self::hash_lookup_value($key);
            }
            self::delete_index_batch($missing, $namespace, $table, $cutoff);
        } while (count($keys) === self::ENTRY_BATCH_SIZE);
    }

    private static function prepare_sql(string $sql, array $params): string
    {
        global $wpdb;

        return call_user_func_array([$wpdb, 'prepare'], array_merge([$sql], $params));
    }

    public static function clear_all_entries(string $namespace = 'static'): void
    {
        if (self::cache_entries_table_is_ready()) {
            global $wpdb;

            $wpdb->delete(
                self::cache_entries_table(),
                ['namespace' => self::normalize_namespace($namespace)],
                ['%s']
            );
        }
    }

    public static function delete_rule_stats(string $rule_id, string $namespace = 'static'): void
    {
        wps('wpopt')->options->remove($rule_id, self::stats_option($namespace), self::CONTEXT);
    }

    private static function stats_option(string $namespace): string
    {
        $namespace = sanitize_key($namespace);

        return $namespace === '' || $namespace === 'static' ? self::STATS_OPTION : "{$namespace}_cache_rule_stats";
    }
}
