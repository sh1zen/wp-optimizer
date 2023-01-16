<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C)  2022
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

function shzn_get_user($user)
{
    if (!is_object($user)) {
        if (is_email($user)) {
            $user = get_user_by('email', $user);
        }
        else {
            $user = get_user_by('id', $user);
        }
    }

    return $user instanceof WP_User ? $user : null;
}

function shzn_get_post($post)
{
    if (is_numeric($post)) {
        $post = WP_Post::get_instance($post);
    }
    elseif (!$post instanceof WP_Post) {
        $post = get_post($post);
    }

    return $post instanceof WP_Post ? $post : null;
}

function shzn_get_term($term, $taxonomy = '', $output = OBJECT, $filter = 'raw')
{
    if (is_numeric($term)) {
        $term = WP_Term::get_instance($term, $taxonomy);
    }
    elseif (!$term instanceof WP_Term) {
        $term = get_term($term, $taxonomy, $output, $filter);
    }

    return $term instanceof WP_Term ? $term : null;
}

function shzn_localize($data = [])
{
    global $wp_scripts;

    if (empty($data) or !($wp_scripts instanceof WP_Scripts)) {
        return false;
    }

    if (wp_scripts()->query("vendor-shzn-js", 'done')) {
        echo "<script type='text/javascript'>shzn.locale.add(" . json_encode($data) . ")</script>";
    }
    else {
        return $wp_scripts->add_inline_script("vendor-shzn-js", "shzn.locale.add(" . json_encode($data) . ")", 'after');
    }

    return true;
}

function shzn_convert_to_javascript_object(array $arr, $sequential_keys = false, $quotes = false, $beautiful_json = false)
{
    $output = "{";
    $count = 0;
    foreach ($arr as $key => $value) {

        if (shzn_is_assoc($arr) or ($sequential_keys === true)) {
            $output .= ($quotes ? '"' : '') . $key . ($quotes ? '"' : '') . ' : ';
        }

        if (is_array($value)) {
            $output .= shzn_convert_to_javascript_object($value, $sequential_keys, $quotes, $beautiful_json);
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

function shzn_is_assoc(array $arr)
{
    if (array() === $arr) return false;
    return array_keys($arr) !== range(0, count($arr) - 1);
}

function shzn_module_panel_url($module = '', $panel = '')
{
    return admin_url("admin.php?page={$module}#{$panel}");
}

function shzn_module_setting_url($context, $panel = '')
{
    return admin_url("admin.php?page={$context}-modules-settings#settings-{$panel}");
}

function shzn_setting_panel_url($context, $panel = '')
{
    return admin_url("admin.php?page={$context}-settings#settings-{$panel}");
}

function shzn_var_dump(...$vars)
{
    foreach ($vars as $var => $var_data) {
        highlight_string("<?php\n$var =\n" . var_export($var_data, true) . ";\n?>");
    }
    echo '</br></br>';
}

/**
 * @return string
 */
function shzn_debug_backtrace($level = 2)
{
    $caller = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, $level + 1);

    if (isset($caller[$level])) {

        $caller = $caller[$level];
        $r = $caller['function'] . '()';
        if (isset($caller['class'])) {
            $r .= ' in ' . $caller['class'];
        }
        if (isset($caller['object'])) {
            $r .= ' (' . get_class($caller['object']) . ')';
        }

        return $r;
    }

    return var_export($caller, true);
}


function shzn_timestr2seconds($time = '')
{
    if (!$time)
        return 0;

    list($hour, $minute) = explode(':', $time);

    return $hour * HOUR_IN_SECONDS + $minute * MINUTE_IN_SECONDS;
}

function shzn_add_timezone($timestamp = false)
{
    if (!$timestamp) {
        $timestamp = time();
    }

    $timezone = get_option('gmt_offset') * HOUR_IN_SECONDS;

    return $timestamp - $timezone;
}