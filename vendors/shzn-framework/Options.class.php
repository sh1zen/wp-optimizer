<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C)  2022
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core;

/**
 * Access to wpopt custom database table options and metadata
 */
class Options
{
    private string $environment;

    private string $table_name;

    private Cache $cache;

    public function __construct($context, $table_name)
    {
        $this->environment = $context;

        $this->cache = shzn($context)->cache;

        if (empty($table_name)) {
            trigger_error("SHZN Framework >> Options has not defined table name.", E_USER_WARNING);
        }

        $this->table_name = $table_name;

        /**
         * remove expired values once a day
         */
        if ($this->get($context, "clear_old_options", "core", 0) < time()) {
            $this->delete_expired();
            $this->update($context, "clear_old_options", time() + DAY_IN_SECONDS, "core", 0);
        }
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

    public function table_name()
    {
        global $wpdb;

        return $wpdb->prefix . $this->table_name;
    }

    public function remove($obj_id, $option, $context = 'core')
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

    public function delete_expired()
    {
        global $wpdb;

        $wpdb->query("DELETE FROM " . $this->table_name() . " WHERE expiration > 0 AND expiration < " . time());
    }

    /**
     * @param $obj_id
     * @param $option
     * @param bool $value
     * @param string $context
     * @param int $expiration could be 0, specific time, DAY_IN_SECONDS, or negative -> not persistent cache
     * @param string|int|null $container
     * @return bool
     */
    public function update($obj_id, $option, $value = false, $context = 'core', $expiration = 0, $container = null)
    {
        global $wpdb;

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

        $result = $wpdb->query(
            $wpdb->prepare("REPLACE INTO " . $this->table_name() . " (obj_id, context, item, value, container, expiration) VALUES (%s, %s, %s, %s, %s, %d)", $obj_id, $context, $option, $serialized_value, $container, $expiration)
        );

        if (!$result) {
            return false;
        }

        shzn($this->environment)->cache->set($obj_id . $option . $context, $value, 'db_cache', true);

        return true;
    }

    /**
     * @param $obj_id
     * @param $option
     * @param bool $value
     * @param string $context
     * @param int $expiration could be 0, specific time, DAY_IN_SECONDS, or negative -> not persistent cache
     * @param string|int|null $container
     * @return bool
     */
    public function add($obj_id, $option, $value = false, string $context = 'core', int $expiration = 0, $container = null)
    {
        global $wpdb;

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

        $serialized_value = maybe_serialize($value);

        $result = $wpdb->query($wpdb->prepare("REPLACE INTO " . $this->table_name() . " (obj_id, context, item, value, container, expiration) VALUES (%s, %s, %s, %s, %s, %d)", $obj_id, $context, $option, $serialized_value, $container, $expiration));

        if (!$result) {
            return false;
        }

        $this->cache->set($obj_id . $option . $context, $value, 'db_cache', true);

        return true;
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

        $this->cache->delete('db_cache');

        return true;
    }

    public function get_all($option, $context = 'core', $default = false, $limiter = false, $offset = 0)
    {
        global $wpdb;

        if (empty($option)) {
            return $default;
        }

        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . $this->table_name() . " WHERE item = %s AND context = %s " . ($limiter ? "LIMIT {$limiter}" : "") . " OFFSET {$offset}", $option, $context));

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

    public function remove_by_id($id)
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

    public function remove_by_container($container)
    {
        global $wpdb;

        return boolval($wpdb->query($wpdb->prepare("DELETE FROM " . $this->table_name() . " WHERE container = %s", $container)));
    }

    public function remove_by_value($value, $regex = false)
    {
        global $wpdb;

        return boolval($wpdb->query($wpdb->prepare("DELETE FROM " . $this->table_name() . " WHERE value " . ($regex ? 'REGEXP' : '=') . " %s", $value)));
    }
}