<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

use WPS\core\TextReplacer;

/**
 * Replace `%%variable_placeholders%%` with their real value based on the current requested page/post/cpt.
 *
 * @param string $string The string to replace the variables in.
 * @param int $object_id
 * @param string $type
 * @return string
 */
function wps_replace_vars(string $string, int $object_id = 0, string $type = 'post'): string
{
    return TextReplacer::replace($string, $object_id, $type);
}

/**
 * Add a custom replacement rule with query type support
 *
 * @param string $rule The rule ex. `%%custom_replace%%`
 * @param String|callable $replacement
 * @param string $type
 */
function wps_add_replacement_rule(string $rule, $replacement, string $type = ''): void
{
    TextReplacer::add_replacer($rule, $replacement, $type);
}