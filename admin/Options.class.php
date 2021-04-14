<?php

namespace WPOptimizer\core;

/**
 * Access to wpopt custom database table options and metadata
 */
class Options
{
    public static function update($option, $value = false)
    {
        global $wpdb;

        $option = trim($option);

        if (empty($option)) {
            return false;
        }

        if (is_object($value)) {
            $value = clone $value;
        }

        $old_value = self::get($option);

        if ($old_value === false) {
            self::add($option, $value);
        }

        $serialized_value = maybe_serialize($value);

        if ($value === $old_value or $serialized_value === maybe_serialize($old_value)) {
            return false;
        }

        $update_args = array(
            'value' => $serialized_value,
        );

        $result = $wpdb->update(self::table_name(), $update_args, array('item' => $option));

        if (!$result) {
            return false;
        }

        Cache::getInstance()->set_cache($option, $value, 'options', true);

        return true;
    }

    public static function get($option, $default = false)
    {
        global $wpdb;

        $option = trim($option);

        if (empty($option)) {
            return false;
        }

        if ($value = Cache::getInstance()->get_cache($option, 'options', false))
            return $value;

        $row = $wpdb->get_row($wpdb->prepare("SELECT value FROM " . self::table_name() . " WHERE item = %s LIMIT 1", $option));

        // Has to be get_row() instead of get_var() because of funkiness with 0, false, null values.
        if (is_object($row)) {
            $value = $row->value;
            $value = maybe_unserialize($value);
            Cache::getInstance()->set_cache($option, $value, 'options');
        }
        else {
            $value = $default;
        }

        return $value;
    }

    private static function table_name()
    {
        global $wpdb;

        return $wpdb->prefix . "wpopt_core";
    }

    public static function add($option, $value = false)
    {
        global $wpdb;

        $option = trim($option);

        if (empty($option)) {
            return false;
        }

        if (is_object($value)) {
            $value = clone $value;
        }

        $serialized_value = maybe_serialize($value);

        $result = $wpdb->query($wpdb->prepare("INSERT INTO " . self::table_name() . " (item, value) VALUES (%s, %s) ON DUPLICATE KEY UPDATE item = VALUES(item), value = VALUES(value)", $option, $serialized_value));

        if (!$result) {
            return false;
        }

        Cache::getInstance()->set_cache($option, $value, 'options', true);

        return true;
    }

    public static function remove($option)
    {
        global $wpdb;

        $option = trim($option);

        if (empty($option)) {
            return false;
        }

        $result = $wpdb->query($wpdb->prepare("DELETE FROM " . self::table_name() . " WHERE item = %s", $option));

        if (!$result) {
            return false;
        }

        Cache::getInstance()->delete_cache($option, 'options');

        return true;
    }
}