<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

use WPS\core\Rewriter;
use WPS\core\Stack;
use WPS\core\StringHelper;
use WPS\core\TextReplacer;

if (!defined('WPS_ERROR_FATALS')) {
    define('WPS_ERROR_FATALS', E_ERROR | E_PARSE | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR);
}

// early load dependencies
require_once WPS_FRAMEWORK . 'Stack.php';

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

function wps_domain()
{
    return Rewriter::getInstance()->host();
}

function wps_server_addr(): string
{
    static $addr = null;

    if (!isset($addr)) {

        global $is_IIS;

        if ($is_IIS and isset($_SERVER['LOCAL_ADDR'])) {
            $addr = $_SERVER['LOCAL_ADDR'];
        }
        else {
            $addr = $_SERVER['SERVER_ADDR'] ?? '';
        }

        if (empty($addr) and isset($_SERVER['SERVER_NAME'])) {
            $addr = gethostbyname($_SERVER['SERVER_NAME']);
        }

        $addr = trim($addr);
    }

    return $addr;
}

function wps_error_handler_should_notify(string $file, int $line, string $error): bool
{
    if (!function_exists('get_option') or !function_exists('update_option')) {
        return true;
    }

    $year = function_exists('current_time') ? current_time('Y') : date('Y');
    $option_name = "wps_error_handler_notified_sources_$year";
    $source = str_replace('\\', '/', trim($file)) . ':' . $line;
    $source_key = md5($source);
    $sources = get_option($option_name, []);

    if (!is_array($sources)) {
        $sources = [];
    }

    if (isset($sources[$source_key])) {
        return false;
    }

    $sources[$source_key] = [
        'file'       => $file,
        'line'       => $line,
        'first_seen' => function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s'),
        'error'      => $error,
    ];

    update_option($option_name, $sources, false);

    return true;
}

function wps_error_handler($hook, ?callable $callback = null, $notify_dev = true, array $tracking_context = []): void
{
    static $index = 0;

    $index++;

    $error_handler = (function ($nro, $string, $file, $line) use ($index, $callback, $hook, $notify_dev, $tracking_context) {

        global $wp_version;

        if (str_contains($file, $hook)) {

            if (is_callable($callback)) {
                call_user_func($callback, $nro, $string, $file, $line);
            }

            if ($notify_dev) {
                $mail_content = StringHelper::stringBuilder(
                    "Details:",
                    "Err: $string",
                    "File $file:$line",
                    "Conf: PHP:" . PHP_VERSION . ", WP:$wp_version, WPS:" . (defined('WPS_VERSION') ? WPS_VERSION : 'n/a'),
                    "Plugin: " . ($tracking_context['plugin_name'] ?? $hook) . " " . ($tracking_context['plugin_version'] ?? 'n/a'),
                    "Request: {$_SERVER['REQUEST_URI']}"
                );

                if (wps_core()->online) {
                    if (wps_error_handler_should_notify($file, (int)$line, $string)) {
                        wp_mail('dev.sh1zen@outlook.it', 'Fatal WordPress Error ' . wps_domain(), "$mail_content\n\nAutomatically sent message by wps framework.");
                    }
                }
                else {
                    wps_log("$mail_content\n\n", 'wps-error-handler.log');
                }
            }
        }

        if ($prev_error_handler = Stack::getInstance()->get($index, 'prev_error_handler', null)) {
            call_user_func($prev_error_handler, $nro, $string, $file, $line);
        }

        return false;
    });

    $prev_error_handler = set_error_handler($error_handler, E_ALL & ~E_NOTICE & ~E_DEPRECATED);

    Stack::getInstance()->set($index, 'prev_error_handler', $prev_error_handler);
}
