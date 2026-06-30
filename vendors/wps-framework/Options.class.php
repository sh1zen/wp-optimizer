<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

/**
 * Access to custom database table options and metadata
 */
class Options
{
    private const DB_LOCK_RETRY_ATTEMPTS = 5;

    private const DB_LOCK_RETRY_DELAY_US = 25000;

    private const DB_DEADLOCK_ERROR_CODE = 1213;

    private const DB_LOCK_WAIT_TIMEOUT_ERROR_CODE = 1205;

    private string $table_name;

    private Cache $cache;

    public function __construct($context, $table_name, $cache_object = null)
    {
        global $wpdb;

        if ($cache_object and $cache_object instanceof Cache) {
            $this->cache = $cache_object;
        }
        else {
            $this->cache = wps_loaded($context) ? wps($context)->cache : new Cache($context);
        }

        if (empty($table_name)) {
            wps_debug_log("WPS Framework >> Options has not defined table name");
        }
        else {
            if (!str_starts_with($table_name, $wpdb->prefix)) {
                $table_name = $wpdb->prefix . $table_name;
            }
        }

        $this->table_name = $table_name;

        $this->maybe_create_options_table();

        wps_hook('init', function () use ($context) {
            CronActions::schedule("$context-clear-options", 'daily', function () {
                /**
                 * remove expired values once a day
                 */
                $this->delete_expired();
            }, '23:00');
        });
    }

    private function maybe_create_options_table(): void
    {
        global $wpdb;

        if ($this->table_name and UtilEnv::table_exist($this->table_name)) {
            return;
        }

        UtilEnv::db_create(
            $this->table_name,
            [
                "fields"      => [
                    "id"         => "bigint NOT NULL AUTO_INCREMENT",
                    "obj_id"     => "varchar(255)",
                    "context"    => "varchar(255)",
                    "item"       => "varchar(255)",
                    "value"      => "longtext NOT NULL",
                    "container"  => "varchar(255) NULL DEFAULT NULL",
                    "expiration" => "bigint NOT NULL DEFAULT 0"
                ],
                "primary_key" => "id"
            ],
            true
        );

        $wpdb->query("ALTER TABLE $this->table_name ADD UNIQUE speeder (context, item, obj_id) USING BTREE;");
        $wpdb->query("ALTER TABLE $this->table_name ADD UNIQUE speeder_container (container, item, obj_id) USING BTREE;");
    }

    public function delete_expired(): void
    {
        global $wpdb;

        $wpdb->query("DELETE FROM " . $this->table_name() . " WHERE expiration > 0 AND expiration < " . time());
    }

    public function table_name(): string
    {
        return $this->table_name;
    }

    public function update($obj_id, $option, $value = false, $context = 'core', $expiration = 0, $container = null): bool
    {
        if (empty($option)) {
            return false;
        }

        if (!$expiration) {
            $expiration = 0;
        }

        $old_value = $this->get($obj_id, $option, $context, null);

        if ($old_value === null) {
            return $this->add($obj_id, $option, $value, $context, $expiration, $container);
        }

        if (is_object($value)) {
            $value = clone $value;
        }

        $serialized_value = maybe_serialize($value);

        if ($value === $old_value or $serialized_value === maybe_serialize($old_value)) {
            return false;
        }

        if ($expiration > 0 and $expiration < time()) {
            $expiration += time();
        }

        return $this->add($obj_id, $option, $value, $context, $expiration, $container);
    }

    public function get($obj_id, $option, $context = 'core', $default = false, $cache = true)
    {
        global $wpdb;

        if (empty($option)) {
            return $default;
        }

        $cache_key = $obj_id . $option . $context;

        if ($cache and !is_null($value = $this->cache->get($cache_key, 'db_cache', null))) {
            return $value;
        }

        $value = $default;

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . $this->table_name() . " WHERE obj_id = %s AND item = %s AND context = %s LIMIT 1", $obj_id, $option, $context));

        if (is_null($row)) {
            return $default;
        }

        if (is_object($row)) {

            $expiration = $row->expiration ? intval($row->expiration) : false;

            if (!$expiration or $expiration >= time()) {
                $value = maybe_unserialize($row->value);
            }
            else {
                $this->remove($obj_id, $option, $context);
            }
        }

        if ($cache) {
            $this->cache->set($cache_key, $value, 'db_cache');
        }

        return $value;
    }

    public function remove($obj_id, $option, $context = 'core'): bool
    {
        global $wpdb;

        if (empty($option) or !$obj_id) {
            return false;
        }

        $result = $wpdb->query($wpdb->prepare("DELETE FROM " . $this->table_name() . " WHERE item = %s AND obj_id = %s AND context = %s", $option, $obj_id, $context));

        if (!$result) {
            return false;
        }

        $this->cache->delete($obj_id . $option . $context, 'db_cache');

        return true;
    }

    public function add($obj_id, $option, $value = false, string $context = 'core', int $expiration = 0, $container = null): bool
    {
        if (empty($option) or !$obj_id) {
            return false;
        }

        if (!$expiration) {
            $expiration = 0;
        }

        if ($expiration > 0 and $expiration < time()) {
            $expiration += time();
        }

        if (is_object($value)) {
            $value = clone $value;
        }

        $container_sql = 'NULL';
        $query_params = [
            $obj_id,
            $context,
            $option,
            maybe_serialize($value),
        ];

        if ($container !== null) {
            $container_sql = '%s';
            $query_params[] = $container;
        }

        $query_params[] = $expiration;

        global $wpdb;

        $result = $this->execute_with_db_lock_retries(
            $wpdb->prepare(
                "REPLACE INTO " . $this->table_name() . " (obj_id, context, item, value, container, expiration)
                VALUES (%s, %s, %s, %s, {$container_sql}, %d)",
                ...$query_params
            )
        );

        if (!$result) {
            return false;
        }

        $this->cache->set($obj_id . $option . $context, $value, 'db_cache', true);

        return true;
    }

    private function execute_with_db_lock_retries(string $sql)
    {
        global $wpdb;

        $previous_suppress_errors = $wpdb->suppress_errors(true);

        try {
            for ($attempt = 0; $attempt < self::DB_LOCK_RETRY_ATTEMPTS; $attempt++) {
                if ($attempt + 1 >= self::DB_LOCK_RETRY_ATTEMPTS) {
                    $wpdb->suppress_errors($previous_suppress_errors);
                }

                $result = $wpdb->query($sql);

                if ($result !== false) {
                    return $result;
                }

                if (!$this->is_retryable_db_lock_error()) {
                    return false;
                }

                if ($attempt + 1 >= self::DB_LOCK_RETRY_ATTEMPTS) {
                    $this->report_db_lock_retry_issue($attempt, 0, true);
                    return false;
                }

                $delay = $this->db_lock_retry_delay($attempt);
                $this->report_db_lock_retry_issue($attempt, $delay, false);
                usleep($delay);
            }
        }
        finally {
            $wpdb->suppress_errors($previous_suppress_errors);
        }

        return false;
    }

    private function db_lock_retry_delay(int $attempt): int
    {
        $delay = self::DB_LOCK_RETRY_DELAY_US * (2 ** $attempt);

        return $delay + mt_rand(0, self::DB_LOCK_RETRY_DELAY_US);
    }

    private function is_retryable_db_lock_error(): bool
    {
        global $wpdb;

        $error_code = $this->db_error_code();

        if (in_array($error_code, [self::DB_DEADLOCK_ERROR_CODE, self::DB_LOCK_WAIT_TIMEOUT_ERROR_CODE], true)) {
            return true;
        }

        $last_error = strtolower((string)$wpdb->last_error);

        return str_contains($last_error, 'deadlock found') or str_contains($last_error, 'lock wait timeout');
    }

    private function db_error_code(): int
    {
        global $wpdb;

        if (class_exists('\mysqli') and $wpdb->dbh instanceof \mysqli) {
            return (int)mysqli_errno($wpdb->dbh);
        }

        return 0;
    }

    private function report_db_lock_retry_issue(int $attempt, int $delay_us, bool $exhausted): void
    {
        global $wpdb;

        $message = sprintf(
            'WPS Options database write encountered a retryable lock error on table "%s" (attempt %d/%d, errno %d: %s). %s',
            $this->table_name(),
            $attempt + 1,
            self::DB_LOCK_RETRY_ATTEMPTS,
            $this->db_error_code(),
            $wpdb->last_error ?: 'unknown database lock error',
            $exhausted
                ? 'Retry attempts exhausted; the write was not completed.'
                : sprintf('Retrying after %d microseconds.', $delay_us)
        );

        if (function_exists('wp_trigger_error')) {
            wp_trigger_error(__METHOD__, $message, E_USER_WARNING);
            return;
        }

        trigger_error($message, E_USER_WARNING);
    }

    public function remove_all($context, $option = '')
    {
        global $wpdb;

        if (empty($option)) {
            $sql = $wpdb->prepare("DELETE FROM " . $this->table_name() . " WHERE context = %s", $context);
        }
        else {
            $sql = $wpdb->prepare("DELETE FROM " . $this->table_name() . " WHERE context = %s AND item = %s", $context, $option);
        }

        $result = $wpdb->query($sql);

        if (!$result) {
            return false;
        }

        $this->cache->flush_group('db_cache');

        return true;
    }

    public function get_all($option, $context = 'core', $default = false, $limiter = false, $offset = 0)
    {
        global $wpdb;

        if (empty($option)) {
            return $default;
        }

        $limit = $limiter ? max(0, intval($limiter)) : 0;
        $offset = max(0, intval($offset));
        $pagination = '';

        if ($limit > 0) {
            $pagination = " LIMIT {$limit}";
        }

        if ($offset > 0) {
            $pagination .= $limit > 0 ? " OFFSET {$offset}" : " LIMIT 18446744073709551615 OFFSET {$offset}";
        }

        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . $this->table_name() . " WHERE item = %s AND context = %s" . $pagination, $option, $context));

        if (!$rows) {
            return $default;
        }

        $values = $default;

        $wpdb->flush();

        foreach ($rows as $row) {

            $expiration = $row->expiration ? intval($row->expiration) : false;

            if (!$expiration or $expiration >= time()) {
                $values[] = [
                    'id'         => $row->id,
                    'obj_id'     => $row->obj_id,
                    'context'    => $row->context,
                    'item'       => $row->item,
                    'value'      => maybe_unserialize($row->value),
                    'expiration' => $row->expiration,
                ];
            }
            else {
                $this->remove($row->obj_id, $option, $context);
            }
        }

        return $values;
    }

    public function count_all($option, $context = 'core'): int
    {
        global $wpdb;

        if (empty($option)) {
            return 0;
        }

        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . $this->table_name() . " WHERE item = %s AND context = %s AND (expiration = 0 OR expiration >= %d)",
            $option,
            $context,
            time()
        ));
    }

    public function remove_by_id($id): bool
    {
        global $wpdb;

        $row = $this->get_by_id($id);

        if ($row) {
            $this->cache->delete($row['obj_id'] . $row['item'] . $row['context'], 'db_cache');
            $this->cache->delete($row['obj_id'], 'db_cache');
        }

        return boolval($wpdb->query($wpdb->prepare("DELETE FROM " . $this->table_name() . " WHERE id = %s", $id)));
    }

    public function get_by_id($id)
    {
        global $wpdb;

        $res = false;

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . $this->table_name() . " WHERE id = %s", $id), OBJECT);

        if (!$row) {
            return false;
        }

        $expiration = $row->expiration ? intval($row->expiration) : false;

        if (!$expiration or $expiration >= time()) {
            $res = [
                'id'         => $row->id,
                'obj_id'     => $row->obj_id,
                'context'    => $row->context,
                'item'       => $row->item,
                'value'      => maybe_unserialize($row->value),
                'expiration' => $row->expiration,
            ];

            $this->cache->set($row->obj_id . $row->item . $row->context, $res['value'], 'db_cache', true);
            $this->cache->set($row->id, $res, 'db_cache', true);
        }
        else {
            $this->remove($row->obj_id, $row->item, $row->context);
        }

        return $res;
    }

    public function remove_by_container($container): bool
    {
        if (empty($container)) {
            return true;
        }
        return (bool)Query::getInstance()->delete(['container' => $container], $this->table_name())->query();
    }

    public function remove_by_value($value, $regex = false)
    {
        global $wpdb;

        return boolval($wpdb->query($wpdb->prepare("DELETE FROM " . $this->table_name() . " WHERE value " . ($regex ? 'REGEXP' : '=') . " %s", $value)));
    }

    public function get_list(array $obj_ids, $option, $context = 'core', $default = [], $cache = true)
    {
        if (empty($option)) {
            return $default;
        }

        $cache_key = Cache::generate_key($option, $context, ...$obj_ids);

        if ($cache and !is_null($values = $this->cache->get($cache_key, 'db_cache', null))) {
            return $values;
        }

        $values = $default;

        $rows = Query::getInstance()->tables($this->table_name())->where(['obj_id' => $obj_ids, 'item' => $option, 'context' => $context])->query_multi();

        if (empty($rows)) {
            return $default;
        }

        foreach ($rows as $row) {

            $expiration = $row->expiration ? intval($row->expiration) : false;

            if (!$expiration or $expiration >= time()) {
                $values[$row->obj_id] = maybe_unserialize($row->value);
            }
            else {
                $this->remove($row->obj_id, $option, $context);
            }
        }

        if ($cache) {
            $this->cache->set($cache_key, $values, 'db_cache');
        }

        return $values;
    }
}
