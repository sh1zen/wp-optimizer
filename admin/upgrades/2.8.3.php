<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2026.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

global $wpdb;

WPS\core\UtilEnv::db_create(
    WPOPT_TABLE_CACHE_ENTRIES,
    [
        'fields'      => [
            'id'                      => 'bigint unsigned NOT NULL AUTO_INCREMENT',
            'namespace'               => "varchar(32) NOT NULL DEFAULT 'static'",
            'cache_key'               => 'varchar(191) NOT NULL',
            'cache_key_hash'          => 'char(32) NOT NULL',
            'rule_id'                 => "varchar(191) NOT NULL DEFAULT ''",
            'request_path'            => 'text NOT NULL',
            'request_path_hash'       => "char(32) NOT NULL DEFAULT ''",
            'bytes'                   => 'bigint unsigned NOT NULL DEFAULT 0',
            'dependency_type'         => "varchar(64) NOT NULL DEFAULT '__entry'",
            'dependency_value'        => "varchar(191) NOT NULL DEFAULT ''",
            'dependency_value_hash'   => "char(32) NOT NULL DEFAULT ''",
            'has_dependency_metadata' => 'tinyint(1) NOT NULL DEFAULT 0',
            'created_at'              => 'int unsigned NOT NULL DEFAULT 0',
            'updated_at'              => 'int unsigned NOT NULL DEFAULT 0',
        ],
        'primary_key' => 'id'
    ],
    false
);

$wpopt_283_get_table_indexes = static function (string $table) use ($wpdb): array {
    $indexes = array();
    $rows = (array)$wpdb->get_results('SHOW INDEX FROM ' . $table, ARRAY_A);

    foreach ($rows as $row) {
        $key_name = (string)($row['Key_name'] ?? '');

        if ($key_name !== '') {
            $indexes[$key_name] = true;
        }
    }

    return $indexes;
};

$wpopt_283_add_index = static function (string $name, string $definition) use ($wpdb, $wpopt_283_get_table_indexes): void {
    $indexes = $wpopt_283_get_table_indexes(WPOPT_TABLE_CACHE_ENTRIES);

    if (isset($indexes[$name])) {
        return;
    }

    $wpdb->query('ALTER TABLE ' . WPOPT_TABLE_CACHE_ENTRIES . ' ADD ' . $definition);
};

$wpopt_283_add_index(
    'uniq_cache_dependency',
    'UNIQUE `uniq_cache_dependency` (`namespace`, `cache_key_hash`, `dependency_type`, `dependency_value_hash`) USING BTREE'
);
$wpopt_283_add_index('idx_cache_key', 'INDEX `idx_cache_key` (`namespace`, `cache_key_hash`) USING BTREE');
$wpopt_283_add_index('idx_rule', 'INDEX `idx_rule` (`namespace`, `dependency_type`, `rule_id`, `cache_key_hash`) USING BTREE');
$wpopt_283_add_index('idx_path', 'INDEX `idx_path` (`namespace`, `dependency_type`, `request_path_hash`, `cache_key_hash`) USING BTREE');
$wpopt_283_add_index('idx_dependency', 'INDEX `idx_dependency` (`namespace`, `dependency_type`, `dependency_value_hash`, `cache_key_hash`) USING BTREE');
$wpopt_283_add_index('idx_updated', 'INDEX `idx_updated` (`namespace`, `updated_at`) USING BTREE');

$wpopt_283_entry_option = static function (string $namespace): string {
    $namespace = sanitize_key($namespace);

    return $namespace === '' || $namespace === 'static' ? 'static_cache_entry' : "{$namespace}_cache_entry";
};

$wpopt_283_normalize_dependency_values = static function (array $values): array {
    return array_values(array_unique(array_filter(array_map(static function ($value): string {
        return sanitize_key((string)$value);
    }, $values))));
};

$wpopt_283_normalize_dependency_map = static function (array $map) use ($wpopt_283_normalize_dependency_values): array {
    $normalized = array();

    foreach ($map as $key => $values) {
        $values = $wpopt_283_normalize_dependency_values((array)$values);

        if (!empty($values)) {
            $normalized[sanitize_key((string)$key)] = $values;
        }
    }

    return $normalized;
};

$wpopt_283_insert_index_row = static function (string $cache_key, string $namespace, array $entry, string $dependency_type, string $dependency_value, bool $has_dependency_metadata) use ($wpdb): void {
    $namespace = sanitize_key($namespace) ?: 'static';
    $request_path = trim((string)($entry['path'] ?? ''), '/');
    $dependency_type = sanitize_key($dependency_type);
    $dependency_value = sanitize_key($dependency_value);

    $wpdb->replace(
        WPOPT_TABLE_CACHE_ENTRIES,
        [
            'namespace'               => $namespace,
            'cache_key'               => $cache_key,
            'cache_key_hash'          => md5($cache_key),
            'rule_id'                 => sanitize_key((string)($entry['rule_id'] ?? '')),
            'request_path'            => $request_path,
            'request_path_hash'       => md5($request_path),
            'bytes'                   => max(0, absint($entry['bytes'] ?? 0)),
            'dependency_type'         => $dependency_type,
            'dependency_value'        => $dependency_value,
            'dependency_value_hash'   => md5($dependency_value),
            'has_dependency_metadata' => $has_dependency_metadata ? 1 : 0,
            'created_at'              => absint($entry['created_at'] ?? time()),
            'updated_at'              => absint($entry['updated_at'] ?? time()),
        ],
        ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%d']
    );
};

if (function_exists('wps') && isset(wps('wpopt')->options) && method_exists(wps('wpopt')->options, 'table_name')) {
    $wpopt_283_options_table = wps('wpopt')->options->table_name();

    foreach (array('static', 'wp_query', 'wp_db') as $namespace) {
        $last_id = 0;
        $entry_option = $wpopt_283_entry_option($namespace);

        do {
            $rows = (array)$wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $wpopt_283_options_table WHERE item = %s AND context = %s AND id > %d ORDER BY id ASC LIMIT 250",
                $entry_option,
                'cache',
                $last_id
            ));

            foreach ($rows as $row) {
                $last_id = max($last_id, absint($row->id ?? 0));

                $expiration = !empty($row->expiration) ? absint($row->expiration) : 0;
                if ($expiration > 0 && $expiration < time()) {
                    continue;
                }

                $cache_key = (string)($row->obj_id ?? '');
                $value = maybe_unserialize($row->value ?? '');
                $entry = is_array($value) ? $value : array();

                if ($cache_key === '' || empty($entry)) {
                    continue;
                }

                $dependency_source = array_diff_key(
                    $entry,
                    array_flip(array('rule_id', 'path', 'bytes', 'created_at', 'updated_at'))
                );
                $dependencies = $wpopt_283_normalize_dependency_map($dependency_source);

                $wpopt_283_insert_index_row($cache_key, $namespace, $entry, '__entry', '', !empty($dependencies));

                foreach ($dependencies as $dependency_type => $values) {
                    foreach ($values as $value) {
                        $wpopt_283_insert_index_row($cache_key, $namespace, $entry, $dependency_type, $value, true);
                    }
                }
            }

        } while (count($rows) === 250);

        $wpdb->delete($wpopt_283_options_table, ['context' => 'cache', 'item' => $entry_option], ['%s', '%s']);
    }
}
