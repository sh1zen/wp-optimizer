<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */


if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        if (function_exists('mb_strpos')) {
            return '' === $needle || false !== mb_strpos($haystack, $needle, 0, get_option('blog_charset'));
        }

        return '' === $needle || false !== strpos($haystack, $needle);
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return 0 === strncmp($haystack, $needle, \strlen($needle));
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return '' === $needle || ('' !== $haystack && 0 === substr_compare($haystack, $needle, -\strlen($needle)));
    }
}

if (!function_exists('preg_last_error_msg')) {
    function preg_last_error_msg(): string
    {
        switch (preg_last_error()) {
            case \PREG_INTERNAL_ERROR:
                return 'Internal error';
            case \PREG_BAD_UTF8_ERROR:
                return 'Malformed UTF-8 characters, possibly incorrectly encoded';
            case \PREG_BAD_UTF8_OFFSET_ERROR:
                return 'The offset did not correspond to the beginning of a valid UTF-8 code point';
            case \PREG_BACKTRACK_LIMIT_ERROR:
                return 'Backtrack limit exhausted';
            case \PREG_RECURSION_LIMIT_ERROR:
                return 'Recursion limit exhausted';
            case \PREG_JIT_STACKLIMIT_ERROR:
                return 'JIT stack limit exhausted';
            case \PREG_NO_ERROR:
                return 'No error';
            default:
                return 'Unknown error';
        }
    }
}

if (!function_exists('fdiv')) {
    function fdiv(float $dividend, float $divisor): float
    {
        return @($dividend / $divisor);
    }
}