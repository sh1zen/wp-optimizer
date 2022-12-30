<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C)  2022
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core;

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
     * @param bool $data
     * @return bool
     */
    public static function add_rules($rules, $start, $end, $order = array(), $data = false)
    {
        if ($data) {
            $write_out = false;
        }
        else {
            $write_out = true;
            $data = self::get_rules();
        }

        if (empty($rules)) {
            // rules removal mode
            if (!str_contains($data, $start)) {
                return true;
            }
        }
        else {
            // rules creation mode
            if (str_contains(RuleUtil::clean_rules($data), RuleUtil::clean_rules($rules))) {
                return true;
            }
        }

        $replace_start = strpos($data, $start);
        $replace_end = strpos($data, $end);

        if ($replace_start !== false and $replace_end !== false and $replace_start < $replace_end) {
            // old rules exists, replace mode
            $replace_length = $replace_end - $replace_start + strlen($end) + 1;
        }
        else {
            $replace_start = false;
            $replace_length = 0;

            foreach (array_reverse($order) as $string) {

                if (($pos = strpos($data, $string)) !== false) {

                    $length = str_contains($string, 'END') ? strlen($string) + 1 : 0;

                    $replace_start = $pos + $length;
                }

                if ($string === $start)
                    break;
            }
        }

        if ($replace_start != false) {
            $data = RuleUtil::trim_rules(substr_replace($data, $rules, $replace_start, $replace_length));
        }
        else {
            $data = RuleUtil::trim_rules(rtrim($data) . "\n" . $rules);
        }

        if ($write_out)
            return self::write_rules($data);

        return $data;
    }

    public static function get_rules()
    {
        $path = self::get_rules_path();

        $data = Disk::read($path);

        if (!$data)
            $data = '';

        return $data;
    }

    /**
     * Returns path of core rules file
     *
     * @return string
     */
    public static function get_rules_path()
    {
        switch (true) {
            case UtilEnv::is_apache():
            case UtilEnv::is_litespeed():
                return UtilEnv::normalize_path(ABSPATH, true) . '.htaccess';

            case UtilEnv::is_nginx():
                return UtilEnv::normalize_path(ABSPATH, true) . 'nginx.conf';
        }

        return false;
    }

    /**
     * Cleanup rewrite rules
     *
     * @param string $rules
     * @return string
     */
    private static function clean_rules($rules)
    {
        $rules = preg_replace('~[\n]+~', "\n", $rules);
        $rules = preg_replace('~[\r\n]+~', "\n", $rules);
        $rules = preg_replace('~^\s+~m', '', $rules);
        $rules = RuleUtil::trim_rules($rules);

        return $rules;
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

    private static function write_rules($rules)
    {
        $path = self::get_rules_path();

        return Disk::write($path, $rules);
    }

    public static function export_rule()
    {
        return self::clean_rules(self::get_rules());
    }

    /**
     * Check if rules exist
     *
     * @param string $rules
     * @param string $start
     * @param string $end
     * @return int
     */
    public static function has_rules(string $rules, string $start, string $end)
    {
        return preg_match('~' . UtilEnv::preg_quote($start) . "\n.*?" . UtilEnv::preg_quote($end) . "\n*~s", $rules);
    }

    /**
     * Remove rules
     * @param $start
     * @param $end
     * @param bool $data
     * @return bool
     */
    public static function remove_rules($start, $end, bool $data = false)
    {
        if ($data) {
            $write_out = false;
        }
        else {
            $write_out = true;
            $data = self::get_rules();
        }

        if (!str_contains($data, $start)) {
            return false;
        }

        $data = RuleUtil::erase_rules($data, $start, $end);

        if ($write_out) {
            return self::write_rules($data);
        }

        return $data;
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

        return RuleUtil::trim_rules($rules);
    }

    /**
     * Returns true if we can check rules
     *
     * @return bool
     */
    public static function can_check_rules()
    {
        return UtilEnv::is_apache() or UtilEnv::is_litespeed() or UtilEnv::is_nginx() or UtilEnv::is_iis();
    }
}
