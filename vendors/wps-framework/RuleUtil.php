<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

class RuleUtil
{
    private string $rules = '';
    private bool $autoLine;

    public function __construct($autoLiner = true)
    {
        $this->autoLine = $autoLiner;
    }

    public static function add_rules($rules, $start, $end, $order = array(), &$virtual_page = ''): bool
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

    private static function clean_rules(string $rules): string
    {
        $rules = preg_replace('#([\n\r]{2,})#m', "\n\n", $rules);
        return self::trim_rules($rules);
    }

    private static function trim_rules(string $rules): string
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

    public static function has_rule(string $rules, string $start, string $end): bool
    {
        return (bool)preg_match('~' . UtilEnv::preg_quote($start) . "\n.*?" . UtilEnv::preg_quote($end) . "\n*~s", $rules);
    }

    public static function remove_rules($start, $end, string &$virtual_page = ''): bool
    {
        if (!str_contains($virtual_page, $start)) {
            return false;
        }

        $virtual_page = self::erase_rules($virtual_page, $start, $end);

        return true;
    }

    private static function erase_rules(string $rules, string $start, string $end): string
    {
        $r = '~' . UtilEnv::preg_quote($start) . "\n.*?" . UtilEnv::preg_quote($end) . "\n*~s";

        $rules = preg_replace($r, '', $rules);

        return self::trim_rules($rules);
    }

    public static function write_rules($rules): bool
    {
        $path = self::get_rules_path();

        return Disk::write($path, self::clean_rules($rules), 0);
    }

    public function autoLine($status): static
    {
        $this->autoLine = $status;
        return $this;
    }

    public function add(string $rule, int $indent = 0): static
    {
        $this->rules .= str_repeat(" ", $indent) . $rule . ($this->autoLine ? "\n" : '');
        return $this;
    }

    public function export(): string
    {
        return $this->rules;
    }

    public function reset(): static
    {
        $this->rules = '';
        return $this;
    }

    public function newLine(): static
    {
        $this->rules .= "\n";
        return $this;
    }
}
