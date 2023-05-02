<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax()
    {
        return apply_filters('wp_doing_ajax', defined('DOING_AJAX') and DOING_AJAX);
    }
}


if (!function_exists('wp_doing_cron')) {
    function wp_doing_cron()
    {
        return apply_filters('wp_doing_cron', defined('DOING_CRON') and DOING_CRON);
    }
}

/**
 * Normalize a filesystem path.
 */
if (!function_exists('wp_normalize_path')) {
    /**
     * WordPress function to normalize a filesystem path; was added to WP core in WP 3.9
     *
     * @see wp_normalize_path() https://developer.wordpress.org/reference/functions/wp_normalize_path/#source for the original source code
     *
     * @param string $path Path to normalize.
     * @return string Normalized path.
     */
    function wp_normalize_path($path)
    {
        $wrapper = '';
        if (wp_is_stream($path)) {
            list($wrapper, $path) = explode('://', $path, 2);
            $wrapper .= '://';
        }
        // Standardise all paths to use /
        $path = str_replace('\\', '/', $path);
        // Replace multiple slashes down to a singular, allowing for network shares having two slashes.
        $path = preg_replace('|(?<=.)/+|', '/', $path);
        // Windows paths should uppercase the drive letter
        if (':' === substr($path, 1, 1)) {
            $path = ucfirst($path);
        }
        return $wrapper . $path;
    }
}

/**
 * Unschedules all events attached to the hook.
 */

/**
 * Unschedules all events attached to the hook.
 *
 * Can be useful for plugins when deactivating to clean up the cron queue.
 *
 * Warning: This function may return Boolean FALSE, but may also return a non-Boolean
 * value which evaluates to FALSE. For information about casting to booleans see the
 * {@link https://www.php.net/manual/en/language.types.boolean.php PHP documentation}. Use
 * the `===` operator for testing the return value of this function.
 *
 * @param string $hook Action hook, the execution of which will be unscheduled.
 * @return int|false On success an integer indicating number of events unscheduled (0 indicates no
 *                   events were registered on the hook), false if unscheduling fails.
 * @since 4.9.0
 * @since 5.1.0 Return value added to indicate success or failure.
 *
 */
function as_wp_unschedule_hook($hook)
{

    if (function_exists('wp_unschedule_hook')) {
        return wp_unschedule_hook($hook);
    }

    /**
     * Filter to preflight or hijack clearing all events attached to the hook.
     *
     * Returning a non-null value will short-circuit the normal unscheduling
     * process, causing the function to return the filtered value instead.
     *
     * For plugins replacing wp-cron, return the number of events successfully
     * unscheduled (zero if no events were registered with the hook) or false
     * if unscheduling one or more events fails.
     *
     * @param null|int|false $pre Value to return instead. Default null to continue unscheduling the hook.
     * @param string $hook Action hook, the execution of which will be unscheduled.
     * @since 5.1.0
     *
     */
    $pre = apply_filters('pre_unschedule_hook', null, $hook);
    if (null !== $pre) {
        return $pre;
    }

    $crons = _get_cron_array();
    if (empty($crons)) {
        return 0;
    }

    $results = array();
    foreach ($crons as $timestamp => $args) {
        if (!empty($args[$hook])) {
            $results[] = count($args[$hook]);
        }
        unset($crons[$timestamp][$hook]);

        if (empty($crons[$timestamp])) {
            unset($crons[$timestamp]);
        }
    }

    /*
     * If the results are empty (zero events to unschedule), no attempt
     * to update the cron array is required.
     */
    if (empty($results)) {
        return 0;
    }
    if (_set_cron_array($crons)) {
        return array_sum($results);
    }
    return false;
}