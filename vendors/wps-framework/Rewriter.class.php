<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

class Rewriter
{
    private static ?Rewriter $_Instance;
    public string $matched_rule;
    public string $raw_url;
    private string $base_url;
    private string $request_path;
    private array $matches;
    private array $matched_query_vars;
    private string $matched_query;
    private string $query;
    private array $query_args = [];
    private string $fragment = '';
    private string $request_uri;

    private function __construct($url = null, $base = null)
    {
        if (!$url) {
            $url = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}");
            $base = $base ?: get_option('home') . '/';
        }

        if (str_starts_with($url, '/')) {
            $url = get_option('home') . $url;
        }

        $this->raw_url = $url;

        $parsed = parse_url($this->raw_url);

        $this->base_url = $base ?: "{$parsed['scheme']}://{$parsed['host']}/";
        $this->request_uri = $parsed['path'] ?? '';

        // handle query args
        $this->query = $parsed['query'] ?? '';
        if (!empty($this->query)) {
            parse_str($this->query, $this->query_args);
        }

        $this->request_path = $this->filter_prefix($this->base_url, $this->request_uri);

        $this->reset();
    }

    private function filter_prefix($base_url, $req_uri): string
    {
        $prefix = parse_url($base_url, PHP_URL_PATH);
        return trim(str_starts_with($req_uri, $prefix) ? substr($req_uri, strlen($prefix)) : $req_uri, '/');
    }

    public function reset(): void
    {
        $this->matched_query_vars = [];
        $this->matches = [];
        $this->matched_query = '';
        $this->matched_rule = '';
    }

    public static function getClone($url = null, $base = null): Rewriter
    {
        if (!empty($url) or !empty($base)) {
            return self::getInstance();
        }

        return clone self::getInstance();
    }

    public static function getInstance($url = null, $base = null): Rewriter
    {
        if ($url) {
            return new self($url, $base);
        }

        if (!isset(self::$_Instance)) {
            self::$_Instance = new self($url, $base);
        }

        return self::$_Instance;
    }

    public static function compile_endpoint($endpoint)
    {
        $endpoint = preg_replace('#\(/\)#', '/?', $endpoint);

        // compile optional parameters
        $regex_endpoint = preg_replace("#:([^:]+):\?#", "(?<$1>[^/]*)", $endpoint);
        $regex_endpoint = preg_replace("#/(\(\?<\w+>\[\^/]\*\))+#", "/?$1?", $regex_endpoint);

        // compile requested parameters
        $regex_endpoint = preg_replace("#:([^:]+):#", "(?<$1>[^/]+)", $regex_endpoint);

        $regex_endpoint = rtrim($regex_endpoint, '/');

        return preg_replace('#/+#', '/', "^$regex_endpoint/?$");
    }

    public static function reload()
    {
        header("Refresh:0");
        exit();
    }

    public function get_requestedUri(): string
    {
        return $this->base_url . $this->request_path;
    }

    public function get_basename($extension = ''): string
    {
        return basename($this->request_path, $extension);
    }

    public function get_matches($index = false)
    {
        if ($index === false) {
            return $this->matches;
        }

        return $this->matches[$index] ?? false;
    }

    public function get_query_var($item = '', $default = false)
    {
        if (isset($this->matched_query_vars[$item])) {
            if (empty($this->matched_query_vars[$item])) {
                return $default;
            }

            return $this->matched_query_vars[$item];
        }

        return $default;
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

            if (preg_match("#^$regex#Ui", urldecode($this->request_path), $matches)) {
                $matched_rule = $regex;
                break;
            }
        }

        if ($matched_rule) {
            $this->matches = $matches;
            $this->matched_query = $query;
            $this->matched_rule = $matched_rule;
            $this->populate_query_vars();
        }

        return $matched_rule;
    }

    private function populate_query_vars(): void
    {
        // Trim the query of everything up to the '?'.
        $query = preg_replace('#^.+\?#', '', $this->matched_query);

        // Substitute the substring matches into the query.
        $query = addslashes($this->MatchesMapRegex_apply($query, $this->matches));

        // Parse the query.
        parse_str($query, $this->matched_query_vars);
    }

    private function MatchesMapRegex_apply($subject, $_matches)
    {
        return preg_replace_callback('(\$matches\[[1-9]+\d*])', function ($matches) use ($_matches) {

            $index = intval(substr($matches[0], 9, -1));
            return (isset($_matches[$index]) ? urlencode($_matches[$index]) : '');

        }, $subject);
    }

    public function get_matched_query(): string
    {
        return $this->matched_query;
    }

    public function get_matched_query_vars(): array
    {
        return $this->matched_query_vars;
    }

    public function match($regex, $start = true, $url = '')
    {
        $url = $url ? urldecode($url) : $this->request_path;

        $matched_rule = false;

        if ($start and !str_starts_with($regex, '^')) {
            $regex = '^' . $regex;
        }

        if (preg_match("#$regex#iD", $url, $matches)) {
            $matched_rule = true;
        }

        return $matched_rule ? $matches : false;
    }

    public function contain($word, $url = ''): bool
    {
        $url = $url ? urldecode($url) : $this->request_path;

        if (empty($word)) {
            return $url === '';
        }

        return str_contains(strtolower($url), $word);
    }

    public function get_next_endpoint($base): string
    {
        $base = trim($base, '/');

        if (preg_match("#$base/([^/?]+)/?#i", urldecode($this->request_path), $matches)) {
            return $matches[1] ?: '';
        }

        return '';
    }

    public function replace($search, $replace, $status = false)
    {
        $res = str_replace($search, $replace, urldecode($this->raw_url));

        if ($status) {
            $this->redirect($res ?: '/', 301);
        }

        return $res;
    }

    public function redirect($path = '', $status = 302, $x_redirect_by = '')
    {
        if (empty($path)) {
            $path = $this->get_uri();
        }
        else {
            $path = filter_var($path, FILTER_VALIDATE_URL) ? $path : $this->home_url($path);
        }

        if (headers_sent()) {
            echo "<script>window.location.replace('" . sec_js_str($path, false) . "');</script>";
        }
        else {
            require_once(ABSPATH . 'wp-includes/pluggable.php');
            wp_redirect($path, $status, $x_redirect_by);
        }

        exit;
    }

    public function get_uri($trailingslashit = true): string
    {
        $url = $this->base_url . $this->get_request_path($trailingslashit);

        $query_args = http_build_query($this->query_args);

        if (!empty($query_args)) {
            $query_args = "?$query_args";
        }

        return $url . $query_args . $this->fragment;
    }

    public function get_request_path(bool $trailingslashit = false): string
    {
        $url = $this->request_path;

        if ($trailingslashit and !(str_ends_with($url, '.php')) and !(str_contains($url, '?'))) {
            $url = trailingslashit($url);
        }

        return $url;
    }

    public function home_url(string $path = '', bool $trailingslash = false, bool $display = false): string
    {
        if (!empty($path)) {
            $path = ltrim(self::sanitize_url($path), '/');
            $path = preg_replace("#[/\\\\]+#", "/", $path);
        }

        $url = $this->base_url . $path;

        if ($trailingslash and !preg_match("#[?\#]#U", $url)) {
            $url = rtrim($url, '/') . '/';
        }

        if ($display) {
            echo $url;
        }

        return $url;
    }

    public static function sanitize_url(string $url, bool $remove_accents = false): string
    {
        if (empty($url)) {
            return '';
        }

        $url = str_replace(["'", " "], ["", "-"], $url);

        $url = trim(preg_replace('#-+#', '-', $url), '-');

        if ($remove_accents) {

            $pre_filter_url = $url;

            $url = iconv('UTF-8', 'ASCII//TRANSLIT', $url);

            if (!$url) {
                $url = remove_accents($pre_filter_url);
            }
        }

        return preg_replace("#[^a-z\dà-ù.\-_~:/?\#[\]@!\$&'()*+,;=]+#Ui", "", $url);
    }

    public function get_base(bool $trailingslashit = false): string
    {
        $url = $this->base_url;

        if ($trailingslashit and !(str_ends_with($url, '.php'))) {
            $url = trailingslashit($url);
        }

        return $url;
    }

    public function remove_query_arg(string $item, $default = '')
    {
        $value = $this->get_query_arg($item, $default);
        unset($this->query_args[$item]);
        return $value;
    }

    public function get_query_arg($item = '', $default = false)
    {
        return $this->query_args[$item] ?? $default;
    }

    public function add_query_args(array $query_arg): Rewriter
    {
        foreach ($query_arg as $item => $value) {
            $this->set_query_arg($item, $value, false);
        }

        return $this;
    }

    public function set_query_arg($item, $value, $replace = true): bool
    {
        if (!$replace and isset($this->query_args[$item])) {
            return false;
        }

        $this->query_args[$item] = $value;

        return true;
    }

    public function remove_query_args(): Rewriter
    {
        $this->query_args = [];
        return $this;
    }

    public function replace_path($new_base): void
    {
        $this->request_path = $this->filter_prefix($this->base_url, $new_base);

        $this->redirect();
    }

    public function set_fragment(string $fragment): Rewriter
    {
        $this->fragment = empty($fragment) ? '' : "#$fragment";
        return $this;
    }

    public function set_base(?string $admin_url): Rewriter
    {
        $this->base_url = $admin_url;
        return $this;
    }
}