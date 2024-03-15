<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

use WPS\core\UtilEnv;

function wps_time($format = 'timestamp', $offset = 0, $zoned = true, $basetime = false)
{
    if ($format === 'zero') {
        return '0000-00-00 00:00:00';
    }

    if (!$basetime) {
        $basetime = time();
    }

    $time = $basetime + intval($offset);

    if ('timestamp' === $format || 'U' === $format) {
        return $time;
    }

    if ('mysql' === $format) {
        $format = 'Y-m-d H:i:s';
    }

    if ($zoned) {
        $time += (int)(get_option('gmt_offset')) * HOUR_IN_SECONDS;
    }

    return date($format, $time);
}

function wps_str_to_time(string $timestamp, $gmt = true)
{
    $gmt_offset = $gmt ? (int)(get_option('gmt_offset')) * HOUR_IN_SECONDS : 0;

    return strtotime($timestamp, time() + $gmt_offset) - $gmt_offset;
}

function wps_get_user($user): ?WP_User
{
    if (is_numeric($user)) {
        $user = get_user_by('id', $user);
    }
    elseif (is_string($user) and is_email($user)) {
        $user = get_user_by('email', $user);
    }
    elseif (!$user instanceof WP_User) {
        $user = new WP_User($user);
    }

    return $user instanceof WP_User ? $user : null;
}

function wps_get_post($post): ?WP_Post
{
    if (is_numeric($post)) {
        $post = WP_Post::get_instance($post);
    }
    elseif (!$post instanceof WP_Post) {
        $post = get_post($post);
    }

    return $post instanceof WP_Post ? $post : null;
}

function wps_get_term($term, $taxonomy = '', $output = OBJECT, $filter = 'raw'): ?WP_Term
{
    if (is_numeric($term)) {
        $term = WP_Term::get_instance($term, $taxonomy);
    }
    elseif (!$term instanceof WP_Term) {
        $term = get_term($term, $taxonomy, $output, $filter);
    }

    return $term instanceof WP_Term ? $term : null;
}

function wps_get_user_meta($user_id, $field, $default = false, $single = true)
{
    if (!is_numeric($user_id)) {

        $user = wps_get_user($user_id);

        if (!$user) {
            return $default;
        }

        $user_id = $user->ID;
    }

    $value = get_metadata_raw('user', $user_id, $field, $single);

    if (!is_null($value)) {
        return maybe_unserialize($value);
    }

    return $default;
}

function wps_get_post_meta($meta_key, $default = '', $post_id = 0, $single = true)
{
    global $post;

    if (!$post_id) {

        if (!$post) {
            return $default;
        }

        $post_id = $post->ID;
    }

    $value = get_metadata_raw('post', $post_id, $meta_key, $single);

    if (!is_null($value)) {
        return maybe_unserialize($value);
    }

    return $default;
}

function wps_get_term_meta($meta_key, $default = '', $term_id = 0, $single = true)
{
    global $term;

    $term = wps_get_term($term_id ?: $term);

    if (!$term) {
        return $default;
    }

    $value = get_metadata_raw('term', $term->term_id, $meta_key, $single);

    if (!is_null($value)) {
        return maybe_unserialize($value);
    }

    return $default;
}

function wps_convert_to_javascript_object(array $arr, $sequential_keys = false, $quotes = false, $beautiful_json = false): string
{
    $output = "{";
    $count = 0;
    foreach ($arr as $key => $value) {

        if (wps_is_assoc($arr) or ($sequential_keys === true)) {
            $output .= ($quotes ? '"' : '') . $key . ($quotes ? '"' : '') . ' : ';
        }

        if (is_array($value)) {
            $output .= wps_convert_to_javascript_object($value, $sequential_keys, $quotes, $beautiful_json);
        }
        else if (is_bool($value)) {
            $output .= ($value ? 'true' : 'false');
        }
        else if (is_numeric($value)) {
            $output .= $value;
        }
        else {
            $output .= ($quotes || $beautiful_json ? '"' : '') . $value . ($quotes || $beautiful_json ? '"' : '');
        }

        if (++$count < count($arr)) {
            $output .= ', ';
        }
    }

    $output .= "}";

    return $output;
}

function wps_is_assoc(array $arr): bool
{
    if (empty($arr)) {
        return false;
    }
    return array_keys($arr) !== range(0, count($arr) - 1);
}

/**
 * generate slug and sanitize url
 * @param $raw_text
 * @param string $substitute
 * @param string $excepts
 * @return string|string[]|null
 */
function wps_generate_slug($raw_text, string $substitute = '-', string $excepts = '')
{
    if (empty($raw_text)) {
        return '';
    }

    if (is_array($raw_text)) {

        foreach ($raw_text as $key => $value) {
            $raw_text[$key] = wps_generate_slug($value, $substitute, $excepts);
        }

        return $raw_text;
    }

    if (!empty($excepts)) {
        // prepare excepts
        $excepts = preg_quote($excepts . $substitute, '#');
    }

    // replace non letter or digits by $substitute
    $text = preg_replace("#[^$excepts\w]#ui", $substitute, $raw_text);

    // substitute accents
    $text = remove_accents($text);

    // remove duplicate $substitute
    $substitute_regex = preg_quote($substitute, "#");
    $text = preg_replace("#$substitute_regex+#", $substitute, strtolower($text));

    return trim($text, $substitute);
}


function wps_array_key_next(array &$array)
{
    $key = key($array);
    next($array);
    return $key;
}

function wps_array_sort(array $array, $sorter, $default = ''): array
{
    $res = [];
    foreach ($sorter as $key) {
        $res[] = $array[$key] ?? $default;
    }
    return $res;
}

function wps_var_dump(...$vars): void
{
    foreach ($vars as $var => $var_data) {
        highlight_string("<?php\n$var =\n" . var_export($var_data, true) . ";\n?>");
    }
    echo '</br></br>';
}

function wps_debug_log($message, $level = 0): void
{
    if (!wps_utils()->debug) {
        return;
    }

    //exclude this function with $level + 1
    wps_log($message, 'debug.log', $level + 1);
}

function wps_debug_backtrace($level = 0, $full = false): string
{
    //exclude this function
    $level++;

    $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, $level + 1);

    if (!isset($trace[$level])) {
        return '';
    }

    $caller = $trace[$level];

    $r = (isset($caller['class']) ? "{$caller['class']}{$caller['type']}" : '') . $caller['function'];

    if ($full) {

        $r .= "(" . wps_stringify_arguments($caller['args']) . ")";
        $r .= " " . str_replace(UtilEnv::normalize_path(ABSPATH), '', UtilEnv::normalize_path($caller['file'])) . ":{$caller['line']}";
    }

    return $r;
}

function wps_stringify_arguments(...$args): string
{
    $argumentStrings = array_map(function ($arg) {
        if (is_array($arg)) {
            return 'array(...)';
        }
        elseif (is_object($arg)) {
            return 'object';
        }
        else {
            return "'$arg'";
        }
    }, ...$args);

    return implode(', ', $argumentStrings);
}

function wps_timestr2seconds($time = '')
{
    if (!$time or !preg_match("#^(\d{2}):(\d{2})#", "$time", $matches))
        return 0;

    return $matches[1] * HOUR_IN_SECONDS + $matches[2] * MINUTE_IN_SECONDS;
}

function wps_multi_mail($to, $subject, $message, $headers, $attachments)
{
    if (!is_array($to)) {
        return wp_mail($to, $subject, $message, $headers, $attachments);
    }

    $headers = array_merge([
        'Content-Type: text/html; charset=UTF-8',
        'Bcc: ' . implode(',', $to),
    ], $headers);

    $to = get_option('admin_email');

    return wp_mail($to, $subject, $message, $headers, $attachments);
}

function wps_string_mather($haystack, $needle, $regex)
{
    if ($regex) {
        return preg_match($needle, $haystack);
    }

    return str_starts_with($haystack, $needle);
}

/**
 * if we want to remove also array actions like [object, method]
 * action argument must be: object::method or ::method or object::
 */
function wps_remove_actions($hook, $action, $regex = false): int
{
    global $wp_filter;

    $items = 0;

    // Check if the $wp_filter global variable is set and the hook is registered
    if ($hook) {

        // Get callbacks for the hook
        $filters = isset($wp_filter[$hook]) ? [$hook => $wp_filter[$hook]] : [];
    }
    else {
        $filters = $wp_filter;
    }

    $check_array = str_contains($action, '::');

    foreach ($filters as $hook => $filter) {

        // Loop through each callback for the hook
        foreach ($filter->callbacks as $priority => $callbacks) {

            // Loop through each callback function
            foreach ($callbacks as $callback) {

                if (is_string($callback['function'])) {
                    $haystack = $callback['function'];
                }
                elseif ($check_array and is_array($callback['function'])) {
                    $haystack = (is_object($callback['function'][0]) ? get_class($callback['function'][0]) : $callback['function'][0]) . '::' . $callback['function'][1];
                }
                else {
                    //closures can't be handled
                    $haystack = false;
                }

                if ($haystack and wps_string_mather($haystack, $action, $regex)) {

                    // Remove the callback from the hook
                    remove_action($hook, $callback['function'], $priority);
                    $items++;
                }
            }
        }
    }

    return $items;
}

function wps_user_has_role($role, $user): bool
{
    $user = wps_get_user($user);

    $roles = ($user instanceof WP_User) ? $user->roles : [];

    return !empty(array_intersect(array($role), $roles));
}

function wps_log($message, $file_name = 'wps-debug.log', $skip_frames = 1, $mode = FILE_APPEND): void
{
    $trace = debug_backtrace();
    $callers = array();

    //exclude this function
    $skip_frames++;

    foreach ($trace as $index => $caller) {

        if ($skip_frames > $index) {
            continue;
        }
        elseif (isset($caller['class'])) {
            $callers[] = "{$caller['class']}{$caller['type']}{$caller['function']}(" . wps_stringify_arguments($caller['args']) . ")";
        }
        else {
            if (in_array($caller['function'], array('do_action', 'apply_filters', 'do_action_ref_array', 'apply_filters_ref_array'), true)) {
                $callers[] = "{$caller['function']}('{$caller['args'][0]}')";
            }
            elseif (in_array($caller['function'], array('include', 'include_once', 'require', 'require_once'), true)) {
                $filename = $caller['args'][0] ?? '';
                $callers[] = $caller['function'] . "('" . str_replace(UtilEnv::normalize_path(ABSPATH), '', UtilEnv::normalize_path($filename)) . "')";
            }
            else {
                $callers[] = "{$caller['function']}(" . wps_stringify_arguments($caller['args']) . ")";
            }
        }
    }

    $data = '[' . wps_time("d-M-Y H:i:s") . ']' . PHP_EOL . print_r($message, true) . PHP_EOL;

    foreach ($callers as $index => $caller) {
        $data .= "#$index $caller" . PHP_EOL;
    }

    $data .= "#URL " . $_SERVER['REQUEST_URI'] . PHP_EOL;
    $data .= "#FILE " . str_replace(UtilEnv::normalize_path(ABSPATH), '', UtilEnv::normalize_path($trace[$skip_frames]['file'] ?? '')) . ":" . ($trace[$skip_frames]['line'] ?? '') . PHP_EOL;

    file_put_contents(WP_CONTENT_DIR . DIRECTORY_SEPARATOR . $file_name, $data, $mode);
}