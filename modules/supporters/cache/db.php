<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

use WPS\core\Cache;
use WPOptimizer\core\Compatibility;
use WPOptimizer\modules\supporters\StaticCacheRules;

if (!defined('ABSPATH')) {
    return;
}

// WP 6.1+: wp-db.php è deprecato, usa class-wpdb.php
if (file_exists(ABSPATH . WPINC . '/class-wpdb.php')) {
    require_once ABSPATH . WPINC . '/class-wpdb.php';
} else {
    // fallback legacy
    require_once ABSPATH . WPINC . '/wp-db.php';
}

/**
 * IMPORTANT:
 * In db.php drop-in we are loaded during require_wp_db() very early.
 * DO NOT call WP functions like is_admin(), wp_doing_ajax(), wp_doing_cron(), get_option(), etc. here.
 *
 * Also wps('wpopt') framework may not be ready yet.
 */
$WPOPT_BOOTSTRAP_EARLY = true;

// We can consider "not early" only if pluggable set of functions is available.
// (is_admin is in wp-includes/load.php, but it relies on globals and constants that may not be stable here)
if (function_exists('did_action') && did_action('plugins_loaded')) {
    $WPOPT_BOOTSTRAP_EARLY = false;
}

$GLOBALS['wpdb'] = new WPOPT_DB(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);

/**
 * If you REALLY must avoid caching in admin/activation, do it lazily inside cache_disabled()
 * when WP functions are available, never here at top-level.
 */

class WPOPT_DB extends wpdb
{
    private bool $updating_cache_index = false;

    private static function get_cache_group(): string
    {
        return 'cache/db';
    }

    private function cache_config(): array
    {
        return defined('WPOPT_DB_CACHE_CONFIG') && is_array(WPOPT_DB_CACHE_CONFIG) ? WPOPT_DB_CACHE_CONFIG : array();
    }

    private function cache_config_enabled(string $key, bool $default = false): bool
    {
        $config = $this->cache_config();

        return array_key_exists($key, $config) ? (bool)$config[$key] : $default;
    }

    private function cache_lifetime(): int
    {
        if (defined('WPOPT_CACHE_DB_LIFETIME')) {
            return max(0, (int)WPOPT_CACHE_DB_LIFETIME);
        }

        return $this->parse_lifespan((string)($this->cache_config()['lifespan'] ?? '01:00'));
    }

    private function cache_store_threshold(): float
    {
        return defined('WPOPT_CACHE_DB_THRESHOLD_STORE') ? (float)WPOPT_CACHE_DB_THRESHOLD_STORE : 0.0;
    }

    private function parse_lifespan(string $lifespan): int
    {
        if (is_numeric($lifespan)) {
            return max(0, (int)$lifespan);
        }

        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($lifespan), $matches)) {
            return ((int)$matches[1] * 3600) + ((int)$matches[2] * 60) + (int)($matches[3] ?? 0);
        }

        return 3600;
    }

    /**
     * Cache is only enabled when the WPS framework storage is available
     * AND WP is sufficiently initialized to evaluate context (admin/ajax/cron).
     */
    private function cache_ready(): bool
    {
        // WPOPT constants must exist
        if (!defined('WPOPT_ABSPATH')) {
            return false;
        }

        // Framework function must exist
        if (!function_exists('wps')) {
            return false;
        }

        // wps('wpopt') may throw if container not ready; guard hard.
        try {
            $wpopt = wps('wpopt');
        } catch (\Throwable $e) {
            return false;
        }

        // storage must exist and be usable
        return isset($wpopt->storage) && $wpopt->storage;
    }

    private function cache_disabled($query): bool
    {
        // No query => no cache
        if (!$query) {
            return true;
        }

        if (function_exists('wpopt_cache_runtime_is_suspended') && wpopt_cache_runtime_is_suspended('wp_db')) {
            return true;
        }

        // If cache backend/framework not ready => disable caching (but still work)
        if (!$this->cache_ready()) {
            return true;
        }

        // Admin/AJAX/CRON checks are only safe when WP functions exist.
        // If they don't exist yet, assume early bootstrap => disable caching.
        if (!function_exists('is_admin') || !function_exists('wp_doing_cron') || !function_exists('wp_doing_ajax')) {
            return true;
        }

        // Disable cache in these contexts.
        if (wp_doing_cron() || wp_doing_ajax()) {
            return true;
        }

        if ((!defined('WPOPT_ADMIN_CACHE_DISABLED') || WPOPT_ADMIN_CACHE_DISABLED) && is_admin()) {
            return true;
        }

        if (
            $this->request_is_compatibility_excluded()
            || $this->query_uses_woocommerce_session_storage((string)$query)
            || $this->request_has_no_cache_cookie()
            || $this->user_agent_is_excluded()
            || !$this->query_matches_selected_tables((string)$query)
        ) {
            return true;
        }

        // Optional: don't cache options queries
        if (defined('WPOPT_CACHE_DB_OPTIONS') && !WPOPT_CACHE_DB_OPTIONS) {
            // $this->options is wpdb property holding options table name
            if (is_string($this->options) && strpos($query, $this->options) !== false) {
                return true;
            }
        }

        return false;
    }

    private function request_is_compatibility_excluded(): bool
    {
        if (class_exists(Compatibility::class, false)) {
            return Compatibility::cache_bypass_reason() !== '';
        }

        $query_keys = array(
            'add-to-cart', 'wc-api', 'wc-ajax', 'preview', 'preview_id', 'preview_nonce', 'elementor-preview', 'elementor_library', 'fl_builder', 'fl_builder_ui',
            'et_fb', 'et_bfb', 'et_pb_preview', 'bricks', 'bricks_preview', 'ct_builder',
            'oxygen_iframe', 'oxy_preview_revision', 'breakdance', 'breakdance_iframe', 'breakdance_builder',
        );
        foreach ($query_keys as $query_key) {
            if (array_key_exists($query_key, $_GET)) {
                return true;
            }
        }

        foreach (array_keys(is_array($_COOKIE ?? null) ? $_COOKIE : array()) as $cookie_name) {
            foreach (array('woocommerce_cart_hash', 'woocommerce_items_in_cart', 'wp_woocommerce_session_', 'woocommerce_recently_viewed', 'store_notice') as $pattern) {
                if (strpos((string)$cookie_name, $pattern) === 0) {
                    return true;
                }
            }
        }

        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = trim((string)preg_replace('#/+#', '/', rawurldecode((string)$path)), '/');
        foreach (array('cart', 'checkout', 'my-account') as $sensitive_path) {
            if ($path === $sensitive_path || strpos($path, $sensitive_path . '/') === 0) {
                return true;
            }
        }

        return false;
    }

    private function query_uses_woocommerce_session_storage(string $query): bool
    {
        $query = strtolower($query);

        return strpos($query, 'woocommerce_sessions') !== false || strpos($query, '_wc_session_') !== false;
    }

    private function generate_key($query, ...$args): string
    {
        if ($this->cache_config_enabled('cache_query_args', false)) {
            $args[] = $this->get_normalized_query_string();
        }

        // preg_replace prevents different keys when query contains LIKE %% passed to $wpdb->prepare(...)
        return Cache::generate_key(preg_replace("#{[^}]+}#", "", $query ?: ''), $args);
    }

    private function request_has_no_cache_cookie(): bool
    {
        if (!$this->cache_config_enabled('no_cache_cookies_enabled', false)) {
            return false;
        }

        if (empty($_COOKIE) || !is_array($_COOKIE)) {
            return false;
        }

        $patterns = $this->cache_config()['no_cache_cookies'] ?? array();
        if (empty($patterns) || !is_array($patterns)) {
            return true;
        }

        foreach (array_keys($_COOKIE) as $cookie_name) {
            foreach (array_filter($patterns) as $pattern) {
                if ($this->pattern_matches((string)$pattern, (string)$cookie_name)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function user_agent_is_excluded(): bool
    {
        if (!$this->cache_config_enabled('user_agent_exclusions_enabled', false)) {
            return false;
        }

        $patterns = $this->cache_config()['user_agent_exclusions'] ?? array();
        if (empty($patterns) || !is_array($patterns)) {
            return false;
        }

        $user_agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($user_agent === '') {
            return false;
        }

        foreach (array_filter($patterns) as $pattern) {
            if ($this->pattern_matches((string)$pattern, $user_agent)) {
                return true;
            }
        }

        return false;
    }

    private function query_matches_selected_tables(string $query): bool
    {
        $config = $this->cache_config();

        if (!array_key_exists('tables', $config)) {
            return true;
        }

        $tables = $config['tables'];

        if (!is_array($tables) || empty($tables)) {
            return false;
        }

        $selected = array_values(array_unique(array_map('strtolower', array_map('strval', $tables))));
        $query_tables = $this->extract_query_tables($query);

        if (empty($query_tables)) {
            return false;
        }

        return !empty(array_intersect($selected, $query_tables));
    }

    private function extract_query_tables(string $query): array
    {
        $query = $this->strip_sql_comments($query);

        if (!is_string($query) || trim($query) === '') {
            return array();
        }

        $tables = array();
        $pattern = '/\b(?:from|join|update|into|replace\s+into|delete\s+from)\s+((?:`[^`]+`|[a-zA-Z0-9_$]+)(?:\.(?:`[^`]+`|[a-zA-Z0-9_$]+))?)/i';

        if (preg_match_all($pattern, $query, $matches)) {
            foreach ($matches[1] as $table) {
                $table = $this->normalize_sql_table_identifier((string)$table);

                if ($table !== '') {
                    $tables[] = $table;
                }
            }
        }

        return array_values(array_unique($tables));
    }

    private function extract_mutated_query_tables(string $query): array
    {
        $query = $this->strip_sql_comments($query);

        if (!is_string($query) || trim($query) === '') {
            return array();
        }

        $identifier = '((?:`[^`]+`|[a-zA-Z0-9_$]+)(?:\.(?:`[^`]+`|[a-zA-Z0-9_$]+))?)';
        $patterns = array(
            '/\binsert\s+(?:low_priority\s+|delayed\s+|high_priority\s+|ignore\s+)*into\s+' . $identifier . '/i',
            '/\breplace\s+(?:low_priority\s+|delayed\s+)?(?:into\s+)?' . $identifier . '/i',
            '/\bupdate\s+(?:low_priority\s+|ignore\s+)*' . $identifier . '/i',
            '/\bdelete\s+from\s+' . $identifier . '/i',
            '/\btruncate\s+(?:table\s+)?' . $identifier . '/i',
            '/\balter\s+table\s+' . $identifier . '/i',
            '/\bdrop\s+table\s+(?:if\s+exists\s+)?' . $identifier . '/i',
            '/\bcreate\s+(?:temporary\s+)?table\s+(?:if\s+not\s+exists\s+)?' . $identifier . '/i',
            '/\brename\s+table\s+' . $identifier . '/i',
        );

        $tables = array();

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $query, $matches)) {
                continue;
            }

            $table = $this->normalize_sql_table_identifier((string)$matches[1]);
            if ($table !== '') {
                $tables[] = $table;
            }
        }

        return array_values(array_unique($tables));
    }

    private function strip_sql_comments(string $query): string
    {
        $query = preg_replace('/\/\*.*?\*\//s', ' ', $query);
        $query = preg_replace('/--[^\r\n]*/', ' ', (string)$query);

        return preg_replace('/#[^\r\n]*/', ' ', (string)$query);
    }

    private function normalize_sql_table_identifier(string $table): string
    {
        $table = trim($table);
        $table = trim($table, '`');

        if (strpos($table, '.') !== false) {
            $parts = explode('.', $table);
            $table = end($parts);
            $table = trim((string)$table, '`');
        }

        return strtolower($table);
    }

    private function pattern_matches(string $pattern, string $value): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return false;
        }

        return stripos($value, $pattern) !== false;
    }

    private function get_current_request_path(): string
    {
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = rawurldecode((string)($path ?: '/'));
        $path = preg_replace('#/+#', '/', $path);

        if (function_exists('home_url')) {
            $site_path = parse_url(home_url('/'), PHP_URL_PATH);
            $site_path = '/' . trim((string)$site_path, '/');

            if ($site_path !== '/' && (stripos($path, $site_path . '/') === 0 || strcasecmp($path, $site_path) === 0)) {
                $path = substr($path, strlen($site_path));
            }
        }

        return trim((string)$path, '/');
    }

    private function get_normalized_query_string(): string
    {
        if (empty($_GET) || !is_array($_GET)) {
            return '';
        }

        $args = function_exists('wp_unslash') ? wp_unslash($_GET) : $_GET;
        ksort($args);

        return http_build_query($args, '', '&', PHP_QUERY_RFC3986);
    }

    private function cache_index_ready(): bool
    {
        if (!function_exists('sanitize_key')) {
            return false;
        }

        if (!class_exists(StaticCacheRules::class)) {
            $rules_file = defined('WPOPT_SUPPORTERS')
                ? WPOPT_SUPPORTERS . 'cache/staticcache_rules.class.php'
                : __DIR__ . '/staticcache_rules.class.php';

            if (is_file($rules_file)) {
                require_once $rules_file;
            }
        }

        return class_exists(StaticCacheRules::class);
    }

    private function record_cache_entry(string $key, string $query): void
    {
        if ($this->updating_cache_index || !$this->cache_index_ready()) {
            return;
        }

        $tables = $this->extract_query_tables($query);
        if (empty($tables)) {
            return;
        }

        $this->updating_cache_index = true;

        try {
            StaticCacheRules::record_write(
                '',
                $key,
                $this->get_current_request_path(),
                0,
                'wp_db',
                array('tables' => $tables)
            );
        } finally {
            $this->updating_cache_index = false;
        }
    }

    private function purge_cached_tables(array $tables): void
    {
        if (
            $this->updating_cache_index
            || empty($tables)
            || !$this->cache_config_enabled('auto_purge_content', true)
            || !$this->cache_ready()
            || !$this->cache_index_ready()
        ) {
            return;
        }

        if (function_exists('wpopt_cache_auto_purge_is_suspended') && wpopt_cache_auto_purge_is_suspended('wp_db')) {
            return;
        }

        $this->updating_cache_index = true;

        try {
            StaticCacheRules::clear_by_dependencies(
                array('tables' => $tables),
                self::get_cache_group(),
                'wp_db'
            );
        } finally {
            $this->updating_cache_index = false;
        }
    }

    private function maybe_store_cache_result($wpopt, string $key, string $query, $result): void
    {
        $elapsed = $this->timer_stop();
        $lifetime = $this->cache_lifetime();

        if ($lifetime > 0 && $elapsed > $this->cache_store_threshold()) {
            $wpopt->storage->set($result, $key, self::get_cache_group(), $lifetime);
            $this->record_cache_entry($key, $query);
        }
    }

    public function query($query)
    {
        $mutated_tables = $this->updating_cache_index
            ? array()
            : $this->extract_mutated_query_tables((string)$query);

        $result = parent::query($query);

        if ($result !== false && !empty($mutated_tables)) {
            $this->purge_cached_tables($mutated_tables);
        }

        return $result;
    }

    public function get_var($query = null, $x = 0, $y = 0)
    {
        if ($this->cache_disabled($query)) {
            return parent::get_var($query, $x, $y);
        }

        $key = $this->generate_key($query, $x, $y);

        $wpopt = wps('wpopt');
        $result = $wpopt->storage->get($key, self::get_cache_group());

        if (!$result) {
            if (function_exists('wpopt_record_cache_metric')) {
                wpopt_record_cache_metric('db', 'miss');
            }

            $this->timer_start();
            $result = parent::get_var($query, $x, $y);
            $this->maybe_store_cache_result($wpopt, $key, (string)$query, $result);
        }
        else {
            if (function_exists('wpopt_record_cache_metric')) {
                wpopt_record_cache_metric('db', 'hit');
            }
        }

        return $result;
    }

    public function get_results($query = null, $output = OBJECT)
    {
        if ($this->cache_disabled($query)) {
            return parent::get_results($query, $output);
        }

        $key = $this->generate_key($query);

        $wpopt = wps('wpopt');
        $result = $wpopt->storage->get($key, self::get_cache_group());

        if (!$result) {
            if (function_exists('wpopt_record_cache_metric')) {
                wpopt_record_cache_metric('db', 'miss');
            }

            $this->timer_start();
            $result = parent::get_results($query, $output);
            $this->maybe_store_cache_result($wpopt, $key, (string)$query, $result);
        }
        else {
            if (function_exists('wpopt_record_cache_metric')) {
                wpopt_record_cache_metric('db', 'hit');
            }
        }

        return $result;
    }

    public function get_col($query = null, $x = 0)
    {
        if ($this->cache_disabled($query)) {
            return parent::get_col($query, $x);
        }

        $key = $this->generate_key($query, $x);

        $wpopt = wps('wpopt');
        $result = $wpopt->storage->get($key, self::get_cache_group());

        if (!$result) {
            if (function_exists('wpopt_record_cache_metric')) {
                wpopt_record_cache_metric('db', 'miss');
            }

            $this->timer_start();
            $result = parent::get_col($query, $x);
            $this->maybe_store_cache_result($wpopt, $key, (string)$query, $result);
        }
        else {
            if (function_exists('wpopt_record_cache_metric')) {
                wpopt_record_cache_metric('db', 'hit');
            }
        }

        return $result;
    }

    public function get_row($query = null, $output = OBJECT, $y = 0)
    {
        if ($this->cache_disabled($query)) {
            return parent::get_row($query, $output, $y);
        }

        $key = $this->generate_key($query, $y);

        $wpopt = wps('wpopt');
        $result = $wpopt->storage->get($key, self::get_cache_group());

        if (!$result) {
            if (function_exists('wpopt_record_cache_metric')) {
                wpopt_record_cache_metric('db', 'miss');
            }

            $this->timer_start();
            $result = parent::get_row($query, $output, $y);
            $this->maybe_store_cache_result($wpopt, $key, (string)$query, $result);
        }
        else {
            if (function_exists('wpopt_record_cache_metric')) {
                wpopt_record_cache_metric('db', 'hit');
            }
        }

        return $result;
    }
}
