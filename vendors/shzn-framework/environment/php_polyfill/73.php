<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C)  2022
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

if (!function_exists('is_countable')) {
    function is_countable($value)
    {
        return is_array($value) || $value instanceof Countable || $value instanceof ResourceBundle || $value instanceof SimpleXmlElement;
    }
}

if (!function_exists('array_key_first')) {
    function array_key_first(array $array)
    {
        foreach ($array as $key => $value) {
            return $key;
        }
    }
}

if (!function_exists('array_key_last')) {
    function array_key_last(array $array)
    {
        return key(array_slice($array, -1, 1, true));
    }
}