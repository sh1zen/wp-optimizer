<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

/**
 * Allow to easy schedule a callback action
 */
class RequestActions
{
    public static string $nonce_action = 'wps-action-ajax';

    public static string $nonce_name = 'wps_nonce';

    private static bool $suspend = false;

    /**
     * Request action structure:
     *  For the callback:
     *      wps-action => hook
     *      hook => callback action
     *  Internals
     *      self::$nonce_name => wp_create_nonce(self::$nonce_action)
     */
    public static function request($hook, callable $callback = null, $short_circuit = false, $remove_query_args = false)
    {
        if (!self::is_valid_request($hook, $short_circuit) or self::$suspend) {
            return;
        }

        if (!empty($_REQUEST[self::$nonce_name]) and !UtilEnv::verify_nonce(self::$nonce_action, $_REQUEST[self::$nonce_name])) {

            if (!wp_doing_ajax()) {
                return;
            }

            Ajax::response([
                'body'  => __('It seems that you are not allowed to do this request.', 'wps'),
                'title' => __('Request error', 'wps')
            ], 'error');
        }

        $response = call_user_func($callback, $_REQUEST[$hook] ?? '');

        if (!wp_doing_ajax()) {

            if ($remove_query_args) {
                self::remove_query_args(is_array($response) ? $response : []);
            }
            return;
        }

        Ajax::response([
            'body'  => $response ?: __('It seems that you are not allowed to do this request.', 'wps'),
            'title' => $response ? __('Request response', 'wps') : __('Request error', 'wps')
        ], $response ? 'success' : 'error');
    }

    public static function is_valid_request($hook, $short_circuit = false): bool
    {
        if (!isset($_REQUEST['wps-action']) or $_REQUEST['wps-action'] !== $hook) {
            return false;
        }

        if (!$short_circuit and !isset($_REQUEST[$hook])) {
            return false;
        }

        return true;
    }

    public static function remove_query_args($query_args = []): void
    {
        $rewriter = Rewriter::getClone();

        $hook = $rewriter->get_query_arg('wps-action');

        if ($hook) {
            $rewriter->remove_query_arg($hook);
            $rewriter->remove_query_arg('wps-action');
            $rewriter->remove_query_arg(self::$nonce_name);

            if (!empty($query_args)) {
                foreach ($query_args as $query => $value) {
                    $rewriter->set_query_arg($query, $value, true);
                }
            }

            $rewriter->redirect($rewriter->get_uri());
        }
    }

    public static function suspend(): void
    {
        self::$suspend = true;
    }

    public static function get_request($hook, $short_circuit = false): string
    {
        if (!self::is_valid_request($hook, $short_circuit)) {
            return '';
        }

        return esc_html($_REQUEST[$hook] ?? '');
    }

    public static function get_url($hook, $value, $ajax = false, $display = false): string
    {
        $rewriter = Rewriter::getClone();

        $rewriter->remove_query_arg('_wp_http_referer');

        if ($ajax) {
            $rewriter->set_base(admin_url('admin-ajax.php'));
        }

        $rewriter->set_query_arg('wps-action', $hook);
        $rewriter->set_query_arg($hook, $value);
        $rewriter->set_query_arg(self::$nonce_name, wp_create_nonce(self::$nonce_action));

        $url = $rewriter->get_uri();

        if ($display) {
            echo $url;
        }

        return $url;
    }

    public static function get_action_button($hook, $action, $text, $classes = 'button-secondary')
    {
        return Graphic::generate_field(array(
            'id'      => $hook,
            'value'   => $action,
            'name'    => $text,
            'classes' => $classes,
            'context' => 'button'
        ), false);
    }

    public static function nonce_field($hook, $referrer = false, $display = true): string
    {
        $fields = wp_nonce_field(self::$nonce_action, self::$nonce_name, $referrer, false);
        $fields .= "<input type='hidden' name='wps-action' value='$hook'/>";

        if ($display) {
            echo $fields;
        }

        return $fields;
    }
}