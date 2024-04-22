<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

use WPS\core\Rewriter;
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

function wps_error_handler($hook, callable $callback = null, $notify_dev = true): void
{
    static $index = 0;

    $index++;

    $error_handler = (function ($nro, $string, $file, $line) use ($index, $callback, $hook, $notify_dev) {

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
                    "Conf: PHP:" . PHP_VERSION . ", WP:$wp_version",
                    "Request: {$_SERVER['REQUEST_URI']}"
                );

                if (wps_core()->online) {
                    wp_mail('dev.sh1zen@outlook.it', 'Fatal WordPress Error ' . wps_domain(), "$mail_content\n\nAutomatically sent message by wps framework.");
                }
                else {
                    wps_log("$mail_content\n\n", 'wps-error-handler.log');
                }
            }
        }

        if ($prev_error_handler = \WPS\core\Stack::getInstance()->get($index, 'prev_error_handler', null)) {
            call_user_func($prev_error_handler, $nro, $string, $file, $line);
        }

        return false;
    });

    $prev_error_handler = set_error_handler($error_handler, E_ALL);

    \WPS\core\Stack::getInstance('wps')->set($index, 'prev_error_handler', $prev_error_handler);
}