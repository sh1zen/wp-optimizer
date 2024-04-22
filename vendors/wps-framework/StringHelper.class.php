<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

class StringHelper
{
    private static string $REPLACER_HASH = 'e8c6d78284cffb10afdfeea37929bd75';

    /**
     * Internal helper function to escape a string before usage
     *
     * @param string $str String to sanitize.
     * @param bool $keep_newlines Optional. Whether to keep newlines. Default: false.
     * @param bool $pre_filter
     * @return string
     */
    public static function escape_text(string $str, bool $keep_newlines = false, bool $pre_filter = false): string
    {
        if ($pre_filter) {
            $str = self::filter_text($str, !$keep_newlines);
        }

        if (!preg_match('/[&<>"\']/', $str)) {
            return $str;
        }

        $str = wp_check_invalid_utf8($str);

        $str = htmlspecialchars($str, ENT_QUOTES, self::get_charset(), false);

        return self::clear($str, $keep_newlines and !$pre_filter, ' ');
    }

    /**
     * Internal helper function to sanitize a string from user input
     * Removes php code, script, style and html comments
     * strip_tags
     */
    public static function filter_text($text, $strip_new_lines = false): string
    {
        if (empty($text)) {
            return '';
        }

        // makes sure there is no HTML encode content
        $text = html_entity_decode($text, ENT_QUOTES, self::get_charset());

        // Don't bother if there are no < - saves some processing.
        if (str_contains($text, '<')) {

            // remove HTML comments
            $text = preg_replace('#<!--.*?-->#s', '', $text);

            // remove also style and scripts
            $text = preg_replace('#<(script|style)[^>]*?>.*?</\\1>#si', '', $text);

            // Remove PHP code
            $text = preg_replace('#<\?php.*?\?>#si', '', $text);

            // Remove HTML tags but try to keep < and > if there is space
            // $text = preg_replace('#<[^><]+>#s', '', $text);
            $text = preg_replace('#<(?!\s)[^><]+>#s', '', $text);
        }

        // Remove WordPress shortcodes raw and dirty method, but fine in most cases
        $text = preg_replace('#\[.*?]#', '', $text);

        return self::clear($text, !$strip_new_lines);
    }

    public static function get_charset()
    {
        static $charset;

        if (!isset($charset)) {
            $charset = get_option('blog_charset');
        }

        return $charset;
    }

    public static function clear(string $string, $keep_new_lines = false, $delimiter = ' '): string
    {
        if (empty($string)) {
            return '';
        }

        if (!$keep_new_lines) {
            $string = preg_replace('#[\r\n\t\s]+#', $delimiter, $string);
        }

        $delimiter = preg_quote($delimiter, '#');

        $string = preg_replace("#$delimiter+#", $delimiter, $string) ?: '';

        return trim($string);
    }

    /**
     * Internal helper function to sanitize a string from user input
     *
     * @param string $str String to sanitize.
     * @param bool $keep_newlines Optional. Whether to keep newlines. Default: false.
     * @return string Sanitized string.
     */
    public static function sanitize_text(string $str, bool $keep_newlines = false): string
    {
        if (empty($str)) {
            return '';
        }

        $str = wp_check_invalid_utf8(trim($str));

        if (empty($str)) {
            return '';
        }

        if (str_contains($str, '<')) {

            $str = preg_replace_callback('#<[^>]*?((?=<)|>|$)#', 'WPS\core\StringHelper::pre_kses_less_than_callback', $str);

            // remove also style and scripts
            $str = preg_replace('#<(script|style)[^>]*?>.*?</\\1>#si', '', $str);
            $str = strip_tags($str);

            /*
             * Use HTML entities in a special case to make sure that
             * later newline stripping stages cannot lead to a functional tag.
             */
            $str = str_replace("<\n", "&lt;\n", $str);
        }

        return self::clear($str, $keep_newlines, ' ');
    }

    /**
     * Searches for `$search` in `$content` (using either `preg_match()`
     * or `strpos()`, depending on whether `$search` is a valid regex pattern or not).
     * If something is found, it replaces `$content` using `$re_replace_pattern`,
     * effectively creating our named markers (`%%{$marker}%%`.
     *
     * @param string $marker Marker name (without percent characters).
     * @param string $search A string or full-blown regex pattern to search for in $content. Uses `strpos()` or `preg_match()`.
     * @param string $replace_pattern Regex pattern to use when replacing contents.
     * @param string $content Content to work on.
     *
     * @return string
     */
    public static function replace_contents_with_marker_if_exists(string $marker, string $search, string $replace_pattern, string $content): string
    {
        $is_regex = self::str_is_valid_regex($search);
        if ($is_regex) {
            $found = preg_match($search, $content);
        }
        else {
            $found = str_contains($content, $search);
        }

        if ($found) {
            $content = preg_replace_callback(
                $replace_pattern,
                function ($matches) use ($marker) {
                    return self::build_marker($marker, $matches[0]);
                },
                $content
            );
        }

        return $content;
    }

    public static function str_is_valid_regex(string $string): bool
    {
        set_error_handler(function () {
        }, E_WARNING);
        $is_regex = (false !== preg_match($string, ''));
        restore_error_handler();

        return $is_regex;
    }

    /**
     * Creates and returns a `%%`-style named marker which holds
     * the base64 encoded `$data`.
     * If `$hash` is provided, it's appended to the base64 encoded string
     * using `|` as the separator (in order to support building the
     * somewhat special/different INJECTLATER marker).
     *
     * @param string $name Marker name.
     * @param string $data Marker data which will be base64-encoded.
     * @param string|null $hash Optional.
     *
     * @return string
     */
    public static function build_marker(string $name, string $data, string $hash = null): string
    {
        // Start the marker, add the data.
        $marker = '%%' . $name . self::$REPLACER_HASH . '%%' . base64_encode($data);

        // Add the hash if provided.
        if (null !== $hash) {
            $marker .= '|' . $hash;
        }

        // Close the marker.
        $marker .= '%%' . $name . '%%';

        return $marker;
    }

    /**
     * @param string $marker Marker.
     * @param string $content Markup.
     *
     * @return string
     */
    public static function restore_marked_content(string $marker, string $content): string
    {
        if (str_contains($content, $marker)) {
            $content = preg_replace_callback(
                '#%%' . $marker . self::$REPLACER_HASH . '%%(.*?)%%' . $marker . '%%#is',
                function ($matches) {
                    return base64_decode($matches[1]);
                },
                $content
            );
        }

        return $content;
    }

    public static function inject_in_html($content, $payload, $where): string
    {
        $position = self::strpos($content, $where[0]);
        if (false !== $position) {
            // Found the tag, setup content/injection as specified.
            if ('after' === $where[1]) {
                $injection = $where[0] . $payload;
            }
            elseif ('replace' === $where[1]) {
                $injection = $payload;
            }
            else {
                $injection = $payload . $where[0];
            }
            // Place where specified.
            $content = self::substr_replace(
                $content,
                $injection,
                $position,
                // Using plain strlen() should be safe here for now, since
                // we're not searching for multibyte chars here still...
                strlen($where[0])
            );
        }
        else {
            // Couldn't find what was specified, just append and add a warning.
            $content .= $payload;

            $tag_display = str_replace(array('<', '>'), '', $where[0]);
            $content .= '<!--noptimize--><!-- Found a problem with the HTML in your Theme, tag `' . $tag_display . '` missing --><!--/noptimize-->';
        }

        return $content;
    }

    /**
     * Multibyte-capable strpos() if support is available on the server.
     * If not, it falls back to using \strpos().
     *
     * @param string $haystack Haystack.
     * @param string $needle Needle.
     * @param int $offset Offset.
     * @param string|null $encoding Encoding. Default null.
     *
     * @return int|false
     */

    public static function strpos(string $haystack, string $needle, int $offset = 0, string $encoding = null)
    {
        if (self::mbstring_available()) {
            return (null === $encoding) ? \mb_strpos($haystack, $needle, $offset) : \mb_strpos($haystack, $needle, $offset, $encoding);
        }
        else {
            return \strpos($haystack, $needle, $offset);
        }
    }

    /**
     * Returns true when mbstring is available.
     *
     * @param bool|null $override Allows overriding the decision.
     *
     * @return bool
     */
    public static function mbstring_available(bool $override = null): bool
    {
        static $available = null;

        if (null === $available) {
            $available = \extension_loaded('mbstring');
        }

        if (null !== $override) {
            $available = $override;
        }

        return $available;
    }

    /**
     * Our wrapper around implementations of \substr_replace()
     * that attempts to not break things horribly if at all possible.
     * Uses mbstring if available, before falling back to regular
     * substr_replace() (which works just fine in the majority of cases).
     *
     * @param string $string String.
     * @param string $replacement Replacement.
     * @param int $start Start offset.
     * @param int|null $length Length.
     * @param string|null $encoding Encoding.
     *
     * @return string
     */
    public static function substr_replace(string $string, string $replacement, int $start, int $length = null, string $encoding = null): string
    {
        if (self::mbstring_available()) {
            $strlen = self::strlen($string, $encoding);

            if ($start < 0) {
                if (-$start < $strlen) {
                    $start = $strlen + $start;
                }
                else {
                    $start = 0;
                }
            }
            elseif ($start > $strlen) {
                $start = $strlen;
            }

            if (null === $length) {
                $start2 = $strlen;
            }
            elseif ($length < 0) {
                $start2 = $strlen + $length;
                if ($start2 < $start) {
                    $start2 = $start;
                }
            }
            else {
                $start2 = $start + $length;
            }

            if (null === $encoding) {
                $leader = $start ? \mb_substr($string, 0, $start) : '';
                $trailer = ($start2 < $strlen) ? \mb_substr($string, $start2, null) : '';
            }
            else {
                $leader = $start ? \mb_substr($string, 0, $start, $encoding) : '';
                $trailer = ($start2 < $strlen) ? \mb_substr($string, $start2, null, $encoding) : '';
            }

            return "{$leader}{$replacement}{$trailer}";
        }

        return (null === $length) ? \substr_replace($string, $replacement, $start) : \substr_replace($string, $replacement, $start, $length);
    }

    /**
     * Attempts to return the number of characters in the given $string if
     * mbstring is available. Returns the number of bytes
     * (instead of characters) as fallback.
     *
     * @param string $string String.
     * @param string|null $encoding Encoding.
     *
     * @return int Number of characters or bytes in given $string
     *             (characters if/when supported, bytes otherwise).
     */
    public static function strlen(string $string, string $encoding = null): int
    {
        if (self::mbstring_available()) {
            return (null === $encoding) ? \mb_strlen($string) : \mb_strlen($string, $encoding);
        }
        else {
            return \strlen($string);
        }
    }

    public static function strtolower(string $string): string
    {
        if (self::mbstring_available()) {
            return mb_strtolower($string, self::get_charset());
        }

        return strtolower($string);
    }

    /**
     * Convert to snake case.
     *
     * @param \string $string The string to convert.
     * @return \string         The converted string.
     */
    public static function toSnakeCase(string $string): string
    {
        $string[0] = strtolower($string[0]);
        return \preg_replace_callback('/([A-Z])/', function ($value) {
            return '_' . strtolower($value[1]);
        }, $string);
    }

    /**
     * Convert to camel case.
     *
     * @param \string $string The string to convert.
     * @param bool $capitalize Whether to capitalize the first letter.
     * @return \string             The converted string.
     */
    public static function toCamelCase(string $string, bool $capitalize = false): string
    {
        $string[0] = strtolower($string[0]);
        if ($capitalize) {
            $string[0] = strtoupper($string[0]);
        }
        return preg_replace_callback('/_([a-z0-9])/', function ($value) {
            return strtoupper($value[1]);
        }, $string);
    }

    /**
     * Converts kebab case to camel case.
     *
     * @param \string $string The string to convert.
     * @param bool $capitalizeFirstCharacter
     * @return \string             The converted string.
     */
    public static function dashesToCamelCase(string $string, bool $capitalizeFirstCharacter = false): string
    {
        $string = str_replace(' ', '', ucwords(str_replace('-', ' ', $string)));
        if (!$capitalizeFirstCharacter) {
            $string[0] = strtolower($string[0]);
        }
        return $string;
    }

    /**
     * Truncates a given string.
     */
    public static function truncate($string, int $maxCharacters, $ellipsis = ''): string
    {
        if (empty($string) or strlen($string) < $maxCharacters) {
            return $string;
        }

        $truncatedText = substr($string, 0, $maxCharacters);
        $lastSpaceIndex = strrpos($truncatedText, ' ');

        if ($lastSpaceIndex !== false) {
            $truncatedText = substr($truncatedText, 0, $lastSpaceIndex);
        }

        return $truncatedText . $ellipsis;
    }

    /**
     * Truncates a given string.
     */
    public static function truncateWords($string, int $numWords, $ellipsis = ''): string
    {
        $words = str_word_count($string, 2); // Get an array of words

        if (count($words) <= $numWords) {
            return $string;
        }

        $truncatedWords = array_slice($words, 0, $numWords);
        $truncatedText = implode(' ', $truncatedWords);

        return $truncatedText . $ellipsis;
    }

    /**
     * Check if a string is JSON encoded or not.
     */
    public static function isJson(string $string): bool
    {
        json_decode($string);

        // Return a boolean whether the last error matches.
        return json_last_error() === JSON_ERROR_NONE;
    }

    public static function make_regex($regex, $delimiter): string
    {
        return str_replace($delimiter, "\\$delimiter", $regex);
    }

    public static function stringBuilder(...$strings): string
    {
        return implode("\n", $strings);
    }

    /**
     * Given an array of key/value pairs to replace in $string,
     * it does so by replacing the longest-matching strings first.
     *
     * @param string $string string in which to replace.
     * @param array $replacements to be replaced strings and replacement.
     *
     * @return string
     */
    protected static function replace_longest_matches_first(string $string, array $replacements = array()): string
    {
        if (!empty($replacements)) {
            // Sort the replacements array by key length in desc order (so that the longest strings are replaced first).
            $keys = array_map('strlen', array_keys($replacements));
            array_multisort($keys, SORT_DESC, $replacements);
            $string = str_replace(array_keys($replacements), array_values($replacements), $string);
        }

        return $string;
    }

    private static function pre_kses_less_than_callback($matches)
    {
        if (!str_contains($matches[0], '>')) {
            return htmlspecialchars($matches[0], ENT_QUOTES, self::get_charset());
        }
        return $matches[0];
    }
}