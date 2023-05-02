<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core;

class StringHelper
{
    const REPLACER_HASH = 'e8c6d78284cffb10afdfeea37929bd75';

    /**
     * Internal helper function to sanitize a string from user input or from the db
     *
     * @param string $str String to sanitize.
     * @param bool $keep_newlines Optional. Whether to keep newlines. Default: false.
     * @return string Sanitized string.
     * @since 1.0.0
     *
     */
    public static function sanitize_text_field($str, $keep_newlines = false)
    {
        if (is_object($str) or is_array($str)) {
            return '';
        }

        $str = (string)$str;

        $filtered = wp_check_invalid_utf8($str);

        if (str_contains($filtered, '<')) {
            $filtered = wp_pre_kses_less_than($filtered);
            // This will strip extra whitespace for us.
            $filtered = wp_strip_all_tags($filtered, false);

            // Use HTML entities in a special case to make sure no later
            // newline stripping stage could lead to a functional tag.
            $filtered = str_replace("<\n", "&lt;\n", $filtered);
        }

        if (!$keep_newlines) {
            $filtered = preg_replace('/[\r\n\t ]+/', ' ', $filtered);
        }

        return trim($filtered);
    }

    /**
     * Searches for `$search` in `$content` (using either `preg_match()`
     * or `strpos()`, depending on whether `$search` is a valid regex pattern or not).
     * If something is found, it replaces `$content` using `$re_replace_pattern`,
     * effectively creating our named markers (`%%{$marker}%%`.
     *
     * @param string $marker Marker name (without percent characters).
     * @param string $search A string or full blown regex pattern to search for in $content. Uses `strpos()` or `preg_match()`.
     * @param string $re_replace_pattern Regex pattern to use when replacing contents.
     * @param string $content Content to work on.
     *
     * @return string
     */
    public static function replace_contents_with_marker_if_exists($marker, $search, $re_replace_pattern, $content)
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
                $re_replace_pattern,
                function ($matches) use ($marker) {
                    return self::build_marker($marker, $matches[0]);
                },
                $content
            );
        }

        return $content;
    }

    public static function str_is_valid_regex(string $string)
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
    public static function build_marker($name, $data, $hash = null)
    {
        // Start the marker, add the data.
        $marker = '%%' . $name . self::REPLACER_HASH . '%%' . base64_encode($data);

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
    public static function restore_marked_content($marker, $content)
    {
        if (str_contains($content, $marker)) {
            $content = preg_replace_callback(
                '#%%' . $marker . self::REPLACER_HASH . '%%(.*?)%%' . $marker . '%%#is',
                function ($matches) {
                    return base64_decode($matches[1]);
                },
                $content
            );
        }

        return $content;
    }

    public static function inject_in_html($content, $payload, $where)
    {
        $warned = false;
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
            if (!$warned) {
                $tag_display = str_replace(array('<', '>'), '', $where[0]);
                $content .= '<!--noptimize--><!-- WPOPT found a problem with the HTML in your Theme, tag `' . $tag_display . '` missing --><!--/noptimize-->';
                $warned = true;
            }
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
    public static function strpos($haystack, $needle, $offset = 0, $encoding = null)
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
    public static function mbstring_available($override = null)
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

    public static function strtolower(string $string)
    {
        if (self::mbstring_available()) {
            //get_option('blog_charset')
            return mb_strtolower($string, mb_detect_encoding($string));
        }

        return strtolower($string);
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
    public static function substr_replace($string, $replacement, $start, $length = null, $encoding = null)
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

            if (null === $length || '' === $length) {
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
    public static function strlen($string, $encoding = null)
    {
        if (self::mbstring_available()) {
            return (null === $encoding) ? \mb_strlen($string) : \mb_strlen($string, $encoding);
        }
        else {
            return \strlen($string);
        }
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
    protected static function replace_longest_matches_first($string, $replacements = array())
    {
        if (!empty($replacements)) {
            // Sort the replacements array by key length in desc order (so that the longest strings are replaced first).
            $keys = array_map('strlen', array_keys($replacements));
            array_multisort($keys, SORT_DESC, $replacements);
            $string = str_replace(array_keys($replacements), array_values($replacements), $string);
        }

        return $string;
    }

    /**
     * Returns the string after it is encoded with htmlspecialchars().
     *
     * @param \string $string The \string to encode.
     * @return \string         The encoded string.
     */
    public static function encodeOutputHtml($string)
    {
        return htmlspecialchars($string, ENT_COMPAT | ENT_HTML401, get_option('blog_charset'), false);
    }

    /**
     * Convert to snake case.
     *
     * @param \string $string The string to convert.
     * @return \string         The converted string.
     */
    public static function toSnakeCase($string)
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
     * @param bool $capitalize Whether or not to capitalize the first letter.
     * @return \string             The converted string.
     */
    public static function toCamelCase($string, $capitalize = false)
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
    public static function dashesToCamelCase($string, $capitalizeFirstCharacter = false)
    {
        $string = str_replace(' ', '', ucwords(str_replace('-', ' ', $string)));
        if (!$capitalizeFirstCharacter) {
            $string[0] = strtolower($string[0]);
        }
        return $string;
    }

    /**
     * Truncates a given string.
     *
     * @param \string $string The string.
     * @param int $maxCharacters The max. amount of characters.
     * @param boolean $shouldHaveEllipsis Whether the string should have a trailing ellipsis (defaults to true).
     * @return \string
     */
    public static function truncate($string, $maxCharacters, $shouldHaveEllipsis = true)
    {
        $length = strlen($string);
        $excessLength = $length - $maxCharacters;
        if (0 < $excessLength) {
            // If the string is longer than 65535 characters, we first need to shorten it due to the character limit of the regex pattern quantifier.
            if (65535 < $length) {
                $string = substr($string, 0, 65534);
            }
            $string = preg_replace("#[^\pZ\pP]*.{{$excessLength}}$#", '', $string);
            if ($shouldHaveEllipsis) {
                $string = $string . ' ...';
            }
        }
        return $string;
    }

    /**
     * Check if a string is JSON encoded or not.
     *
     * @param \string $string The string to check.
     * @return bool           True if it is JSON or false if not.
     */
    public function isJson($string)
    {
        if (!is_string($string)) {
            return false;
        }

        json_decode($string);

        // Return a boolean whether the last error matches.
        return json_last_error() === JSON_ERROR_NONE;
    }
}