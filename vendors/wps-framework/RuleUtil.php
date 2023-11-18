<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

class RuleUtil
{
    /**
     * Returns true if we can modify rules
     *
     * @param string $path
     * @return boolean
     */
    public static function can_modify_rules($path)
    {
        return UtilEnv::is_wpmu() and (UtilEnv::is_apache() or UtilEnv::is_litespeed() or UtilEnv::is_nginx());
    }

    /**
     * @param string $rules rules to add
     * @param string $start start marker
     * @param string $end end marker
     * @param array $order order where to place if some marker exists
     * @param bool $virtual_page
     * @return bool
     */
    public static function add_rules($rules, $start, $end, $order = array(), &$virtual_page = '')
    {
        // removal mode: rule doesn't exist
        if (empty($rules) and !str_contains($virtual_page, $start)) {
            return false;
        }

        // add mode: rule already exist
        if ($rules and str_contains(self::clean_rules($virtual_page), self::clean_rules($rules))) {
            return false;
        }

        $replace_start = strpos($virtual_page, $start);
        $replace_end = strpos($virtual_page, $end);

        if ($replace_start !== false and $replace_end !== false and $replace_start < $replace_end) {
            // old rules exists, replace mode
            $replace_length = $replace_end - $replace_start + strlen($end) + 1;
        }
        else {
            $replace_start = false;
            $replace_length = 0;

            foreach (array_reverse($order) as $string) {

                if (($pos = strpos($virtual_page, $string)) !== false) {

                    $length = str_contains($string, 'END') ? strlen($string) + 1 : 0;

                    $replace_start = $pos + $length;
                }

                if ($string === $start)
                    break;
            }
        }

        if ($replace_start !== false) {
            $virtual_page = self::trim_rules(substr_replace($virtual_page, $rules, $replace_start, $replace_length));
        }
        else {
            $virtual_page = self::trim_rules(rtrim($virtual_page) . "\n" . $rules);
        }

        return true;
    }

    /**
     * Cleanup rewrite rules
     *
     * @param string $rules
     * @return string
     */
    private static function clean_rules($rules)
    {
        $rules = preg_replace('#([\n\r]{2,})#m', "\n\n", $rules);
        return self::trim_rules($rules);
    }

    /**
     * Trim rules
     *
     * @param string $rules
     * @return string
     */
    private static function trim_rules($rules)
    {
        $rules = trim($rules);

        if ($rules != '') {
            $rules .= "\n";
        }

        return $rules;
    }

    public static function get_rules($item = '')
    {
        $path = self::get_rules_path();

        return Disk::read($path) ?: '';
    }

    /**
     * Returns path of core rules file
     *
     * @return string
     */
    public static function get_rules_path()
    {
        if (UtilEnv::is_apache() or UtilEnv::is_litespeed()) {
            return UtilEnv::normalize_path(ABSPATH, true) . '.htaccess';
        }
        elseif (UtilEnv::is_nginx()) {
            return UtilEnv::normalize_path(ABSPATH, true) . 'nginx.conf';
        }

        return false;
    }

    /**
     * Check if rules exist
     *
     * @param string $rules
     * @param string $start
     * @param string $end
     * @return int
     */
    public static function has_rule(string $rules, string $start, string $end)
    {
        return preg_match('~' . UtilEnv::preg_quote($start) . "\n.*?" . UtilEnv::preg_quote($end) . "\n*~s", $rules);
    }

    /**
     * Remove rules
     * @param $start
     * @param $end
     * @param string $virtual_page
     * @return bool
     */
    public static function remove_rules($start, $end, string &$virtual_page = '')
    {
        if (!str_contains($virtual_page, $start)) {
            return false;
        }

        $virtual_page = self::erase_rules($virtual_page, $start, $end);

        return true;
    }

    /**
     * Erases text from start to end
     *
     * @param string $rules
     * @param string $start
     * @param string $end
     * @return string
     */
    private static function erase_rules(string $rules, string $start, string $end)
    {
        $r = '~' . UtilEnv::preg_quote($start) . "\n.*?" . UtilEnv::preg_quote($end) . "\n*~s";

        $rules = preg_replace($r, '', $rules);

        return self::trim_rules($rules);
    }

    public static function write_rules($rules)
    {
        $path = self::get_rules_path();

        return Disk::write($path, self::clean_rules($rules), 0);
    }
}
