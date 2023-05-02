<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core;

class Rewriter
{
    private static Rewriter $_instance;

    public $request_path;
    public $request_args;
    public $matched_rule;
    private $home_url;
    private $query_vars;
    private $matches;
    private $matched_query;

    private $rewrite_rules;

    private function __construct()
    {
        $this->home_url = get_option('home') . '/';

        $this->reset();

        $this->rewrite_rules = [];

        $this->parse_request();

        add_filter('do_parse_request', [$this, 'query_matcher'], 100, 2);
    }

    public function reset()
    {
        $this->matched_rule = false;
        $this->query_vars = [];
        $this->matches = [];
        $this->matched_query = '';
    }

    private function parse_request()
    {
        $request = explode('?', $_SERVER['REQUEST_URI']);
        $req_uri = $request[0];
        $req_args = $request[1] ?? '';

        $home_path = trim(parse_url($this->home_url, PHP_URL_PATH), '/');
        $home_path_regex = sprintf('|^%s|i', preg_quote($home_path, '|'));

        $req_uri = trim($req_uri, '/');
        $req_uri = preg_replace($home_path_regex, '', $req_uri);

        $this->request_path = trim($req_uri, '/');

        $this->request_args = [];

        if (!empty($req_args)) {
            parse_str($_SERVER['QUERY_STRING'], $this->request_args);
        }
    }

    public static function getInstance()
    {
        if (!isset(self::$_instance)) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public function get_base($extension = '')
    {
        return basename($this->request_path, $extension);
    }

    /**
     * @param $rule string[]
     * @param $callback callable
     */
    public function add_rewrite_rule($rule, $callback, $callback_arguments = [])
    {
        if (!is_array($callback_arguments)) {
            $callback_arguments = [$callback_arguments];
        }

        $this->rewrite_rules[] = ['rule' => $rule, 'callback' => $callback, 'args' => $callback_arguments];
    }

    public function get_matches($index = false)
    {
        if ($index === false) {
            return $this->matches;
        }

        return isset($this->matches[$index]) ? $this->matches[$index] : false;
    }

    public function get_query_var($item = '', $default = false)
    {
        if (isset($this->query_vars[$item])) {
            if (empty($this->query_vars[$item]))
                return $default;

            return $this->query_vars[$item];
        }

        return $default;
    }

    public function query_matcher($do_parse, $wp)
    {
        global $paged;

        remove_filter('do_parse_request', [$this, 'query_matcher']);

        foreach ($this->rewrite_rules as $rewrite_rule) {

            if ($this->rewrite_rules_matcher($rewrite_rule['rule'])) {

                $wp->query_vars = array();

                $wp->request = $this->request_path;
                $wp->matched_rule = $this->matched_rule;
                $wp->matched_query = $this->get_query_matched_query();
                $wp->query_vars = $this->get_query_vars();

                if (isset($wp->query_vars['paged'])) {
                    $paged = max(1, absint($wp->query_vars['paged']));
                }

                if (is_callable($rewrite_rule['callback'])) {
                    call_user_func_array($rewrite_rule['callback'], $rewrite_rule['args']);
                    return false;
                }
            }
        }

        return $do_parse;
    }

    /**
     * Check if a rewrite rule match with the current queried url
     */
    public function rewrite_rules_matcher($rewrite_rules = array())
    {
        $this->reset();

        $matched_rule = false;
        $matches = array();
        $query = '';

        foreach ($rewrite_rules as $regex => $query) {

            if (preg_match("#^$regex#", urldecode($this->request_path), $matches)) {
                $matched_rule = $regex;
                break;
            }
        }

        if ($matched_rule) {
            $this->matches = $matches;
            $this->matched_query = $query;
            $this->populate_query_vars();
            $this->matched_rule = $matched_rule;
        }

        return $matched_rule;
    }

    private function populate_query_vars()
    {
        // Trim the query of everything up to the '?'.
        $query = preg_replace('!^.+\?!', '', $this->matched_query);

        // Substitute the substring matches into the query.
        $query = addslashes($this->MatchesMapRegex_apply($query, $this->matches));

        // Parse the query.
        parse_str($query, $this->query_vars);
    }

    private function MatchesMapRegex_apply($subject, $_matches)
    {
        return preg_replace_callback('(\$matches\[[1-9]+[0-9]*])', function ($matches) use ($_matches) {

            $index = intval(substr($matches[0], 9, -1));
            return (isset($_matches[$index]) ? urlencode($_matches[$index]) : '');

        }, $subject);
    }

    public function get_query_matched_query()
    {
        return $this->matched_query;
    }

    public function get_query_vars()
    {
        return $this->query_vars;
    }

    public function match($regex, $url = '')
    {
        if (!$url) {
            $url = $this->request_path;
        }

        $matched_rule = false;

        if (preg_match("#^$regex#", urldecode($url), $matches)) {
            $matched_rule = $regex;
        }

        return $matched_rule;
    }

    public function contain($word, $url = '')
    {
        if (!$url) {
            $url = $this->request_path;
        }

        if (empty($word)) {
            return $url === '';
        }

        return str_contains(strtolower(urldecode($url)), $word);
    }
}

