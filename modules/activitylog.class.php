<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use WPS\core\RequestActions;
use WPS\core\addon\Exporter;
use WPS\core\CronActions;
use WPS\core\Query;
use WPS\core\Rewriter;
use WPS\modules\Module;

use WPOptimizer\modules\supporters\ActivityLog;

/**
 *  Module for images optimization handling
 */
class Mod_ActivityLog extends Module
{
    public static ?string $name = 'Activity Log';

    public array $scopes = array('autoload', 'admin-page', 'settings');

    protected string $context = 'wpopt';

    public function actions(): void
    {
        if ($this->option('auto_clear')) {
            CronActions::schedule("WPOPT-ActivityLog", DAY_IN_SECONDS, function () {
                Query::getInstance()->delete(['time' => time() - ($this->option('lifetime') * DAY_IN_SECONDS), 'compare' => '<'], WPOPT_TABLE_ACTIVITY_LOG)->query();
            }, '08:00');
        }

        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        RequestActions::request($this->action_hook, function ($action) {

            require_once WPS_ADDON_PATH . 'Exporter.class.php';

            switch ($action) {

                case 'export':

                    require_once WPOPT_SUPPORTERS . '/activity-log/ActivityLog_Table.class.php';

                    $format = $_REQUEST['export-format'] ?? 'csv';

                    $table = new ActivityLog(['action_hook' => $this->action_hook, 'settings' => $this->option()]);

                    $exporter = new Exporter();

                    $exporter->format($format)->set_data($table->get_items())->prepare()->download('activity-log');
                    break;

                case 'reset':

                    Query::getInstance()->tables(WPOPT_TABLE_ACTIVITY_LOG)->action('TRUNCATE')->query();

                    Rewriter::getInstance(admin_url('admin.php'))->add_query_args(array(
                        'page'    => 'wpopt-' . $this->slug,
                        'message' => 'wpopt-actlog-data-erased',
                    ))->redirect();
                    break;
            }
        });
    }

    public function render_sub_modules(): void
    {
        $daily_series = $this->get_activity_daily_series(30);
        $summary = $this->get_activity_summary();

        require_once WPOPT_SUPPORTERS . '/activity-log/ActivityLog_Table.class.php';

        $table = new ActivityLog(['action_hook' => $this->action_hook, 'settings' => $this->option()]);

        $table->prepare_items();
        ?>
        <section class="wps-wrap">
            <block class="wps">
                <section class="wps-header"><h1>Activity Log</h1></section>
                <?php echo $this->render_activity_charts($daily_series, $summary); ?>
                <section class='wps'>
                    <form method="GET" autocapitalize="off" autocomplete="off">
                        <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>"/>
                        <?php $table->display(); ?>
                        <?php RequestActions::nonce_field($this->action_hook); ?>
                    </form>
                </section>
            </block>
            <block class="wps">
                <row class="wps-inline">
                    <strong>Actions:</strong>
                    <a href="<?php RequestActions::get_url($this->action_hook, 'reset', false, true); ?>"
                       class="wps wps-button wpopt-btn is-danger">
                        <?php _e('Reset Log', 'wpopt') ?>
                    </a>
                </row>
            </block>
        </section>
        <?php
    }

    public function restricted_access($context = ''): bool
    {
        switch ($context) {

            case 'settings':
            case 'render-admin':
                return !current_user_can('manage_options');

            default:
                return false;
        }
    }

    public function log_plugin_upgrade(\WP_Upgrader $upgrader, array $extra): void
    {
        if (!isset($extra['type']) or 'plugin' !== $extra['type'])
            return;

        if ('install' === $extra['action']) {

            $path = $upgrader->plugin_info();

            if (!$path) {
                return;
            }

            $data = get_plugin_data($upgrader->skin->result['local_destination'] . '/' . $path, true, false);

            $this->log('installed', 'plugin', [
                    'value' => "{$data['Name']} {$data['Version']}"
                ]
            );
        }

        if ('update' === $extra['action']) {
            if (isset($extra['bulk']) and $extra['bulk']) {
                $slugs = $extra['plugins'];
            }
            else {
                $plugin_slug = $upgrader->skin->plugin ?? $extra['plugin'];

                if (empty($plugin_slug)) {
                    return;
                }

                $slugs = array($plugin_slug);
            }

            foreach ($slugs as $slug) {
                $data = get_plugin_data(WP_PLUGIN_DIR . '/' . $slug, true, false);

                $this->log('upgraded', 'plugin', [
                        'value' => "{$data['Name']} {$data['Version']}"
                    ]
                );
            }
        }
    }

    private function log($action, $subject, $fields = []): void
    {
        global $wpdb, $wp_query;

        $fields = array_merge(
            [
                'action'     => $action,
                'context'    => $subject,
                'value'      => '',
                'user_id'    => wps_core()->get_cuID(),
                'object_id'  => false,
                'ip'         => $this->option('log.ip') ? $this->get_ip() : '',
                'user_agent' => $this->option('log.user_agent') ? $_SERVER['HTTP_USER_AGENT'] : '',
                'request'    => $this->option('log.requests') ? maybe_serialize($_REQUEST) : '',
                'time'       => time()
            ],
            $fields
        );

        if ($fields['object_id'] === false) {
            $fields['object_id'] = $wp_query->get_queried_object_id();
        }

        $fields['value'] = maybe_serialize($fields['value']);

        $wpdb->insert(WPOPT_TABLE_ACTIVITY_LOG, $fields);
    }

    private function get_ip(): string
    {
        $server_ip_keys = array(
            'HTTP_CF_CONNECTING_IP', // CloudFlare
            'HTTP_TRUE_CLIENT_IP', // CloudFlare Enterprise header
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR',
        );

        foreach ($server_ip_keys as $key) {
            if (isset($_SERVER[$key]) and filter_var($_SERVER[$key], FILTER_VALIDATE_IP)) {
                return $_SERVER[$key];
            }
        }

        return '127.0.0.1';
    }

    public function log_bad_queries(): void
    {
        $raw_url = Rewriter::getInstance()->raw_url;
        $payloads = $this->get_suspicious_payloads();
        $rules = [];

        if ($this->option('bad_query.xss')) {
            $rules[] = [
                'action' => 'maybe-xss',
                'regex'  => '~(?:<\s*script\b|javascript:|on(?:error|load|click|mouseover)\s*=|alert\s*\(|prompt\s*\(|confirm\s*\(|document\.(?:cookie|location)|window\.location|<\s*iframe\b|<\s*svg\b)~i',
            ];
        }

        if ($this->option('bad_query.sql')) {
            $rules[] = [
                'action' => 'maybe-sql-injection',
                'regex'  => '~(?:\bunion\b\s+(?:all\s+)?\bselect\b|\bselect\b.+\bfrom\b|\binsert\b.+\binto\b|\bupdate\b.+\bset\b|\bdelete\b.+\bfrom\b|\bdrop\b\s+\btable\b|\binformation_schema\b|\bsleep\s*\(|\bbenchmark\s*\(|\bload_file\s*\(|(?:or|and)\s+1\s*=\s*1)~i',
            ];
        }

        if ($this->option('bad_query.traversal')) {
            $rules[] = [
                'action' => 'maybe-path-traversal',
                'regex'  => '~(?:\.\./|\.\.\\\\|/etc/passwd|/proc/self/environ|boot\.ini|win\.ini|web\.config|[a-z]:\\\\(?:windows|inetpub))~i',
            ];
        }

        if ($this->option('bad_query.command')) {
            $rules[] = [
                'action' => 'maybe-command-injection',
                'regex'  => '~(?:;|\|\||&&|`|\$\().{0,40}(?:curl|wget|bash|sh|cmd(?:\.exe)?|powershell|nc|netcat|python|perl)\b~i',
            ];
        }

        if ($this->option('bad_query.pages')) {
            $rules[] = [
                'action' => 'maybe info gathering',
                'regex'  => '~(?:wp-config(?:\.php(?:\.bak|\.old|\.save)?)?|\.env|composer\.(?:json|lock)|phpinfo(?:\.php)?|readme(?:\.html|\.txt)?|license\.txt|debug\.log|\.git(?:/|%2f)|\.svn(?:/|%2f)|backup|dump(?:\.sql)?|adminer(?:\.php)?)~i',
            ];
        }

        if ($this->option('bad_query.probes')) {
            $rules[] = [
                'action' => 'maybe-probe',
                'regex'  => '~(?:/boaform/admin|/vendor/phpunit|/cgi-bin/|/HNAP1|/\.well-known/|xmlrpc\.php|wlwmanifest\.xml|phpunit|thinkphp|laravel|\.aws/|/actuator|/server-status|/owa/)~i',
            ];
        }

        foreach ($rules as $rule) {
            $match = $this->match_suspicious_rule($payloads, $rule['regex']);

            if (empty($match)) {
                continue;
            }

            $this->log($rule['action'], 'bad_query', [
                'object_id' => 0,
                'value'     => [
                    'url'    => $raw_url,
                    'source' => $match['source'],
                    'match'  => $match['match'],
                ]
            ]);
        }

        foreach ($this->option('bad_query.regexes', []) as $regex) {

            $regex = trim($regex);

            if (!$regex) {
                continue;
            }

            $match = $this->match_suspicious_rule($payloads, $regex);

            if (!empty($match)) {
                $this->log("custom-regex", 'bad_query', [
                    'user_id'   => 0,
                    'object_id' => 0,
                    'value'     => [
                        'url'    => $raw_url,
                        'regex'  => $regex,
                        'source' => $match['source'],
                        'match'  => $match['match'],
                    ]
                ]);
            }
        }
    }

    private function get_suspicious_payloads(): array
    {
        $payloads = [];

        $request_collections = [
            'url'          => Rewriter::getInstance()->raw_url ?? '',
            'query_string' => $_SERVER['QUERY_STRING'] ?? '',
            'request'      => $_REQUEST ?? [],
            'get'          => $_GET ?? [],
            'post'         => $_POST ?? [],
            'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'referer'      => $_SERVER['HTTP_REFERER'] ?? '',
            'uri'          => $_SERVER['REQUEST_URI'] ?? '',
        ];

        foreach ($request_collections as $source => $payload) {
            if (is_array($payload)) {
                $payload = wp_json_encode($payload);
            }

            $payload = $this->normalize_suspicious_payload($payload);

            if ($payload === '') {
                continue;
            }

            $payloads[] = [
                'source' => $source,
                'value'  => $payload,
            ];
        }

        return $payloads;
    }

    private function normalize_suspicious_payload($value): string
    {
        if (!is_scalar($value) || $value === '') {
            return '';
        }

        $value = (string)$value;

        for ($i = 0; $i < 2; $i++) {
            $decoded = rawurldecode($value);

            if ($decoded === $value) {
                break;
            }

            $value = $decoded;
        }

        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $value = strtolower($value);
        $value = preg_replace('/\s+/', ' ', $value);

        return trim(substr($value, 0, 4000));
    }

    private function match_suspicious_rule(array $payloads, string $regex): array
    {
        $pattern = $regex;

        if (@preg_match($pattern, '') === false) {
            $pattern = '~' . str_replace('~', '\~', $regex) . '~i';
        }

        if (@preg_match($pattern, '') === false) {
            return [];
        }

        foreach ($payloads as $payload) {
            if (!preg_match($pattern, $payload['value'], $matches)) {
                continue;
            }

            return [
                'source' => $payload['source'],
                'match'  => substr($matches[0] ?? $payload['value'], 0, 190),
            ];
        }

        return [];
    }

    protected function init(): void
    {
        $this->log_generator();

        if (filter_input(INPUT_GET, 'message') == 'wpopt-actlog-data-erased') {
            $this->add_notices('success', __('All activities have been successfully deleted.', 'wpopt'));
        }
    }

    private function log_generator(): void
    {
        if ($this->option('users')) {

            add_action('wp_login_failed', function ($user_login) {
                $password = wp_unslash((string)($_POST['pwd'] ?? $_POST['password'] ?? ''));

                $this->log('failed-login', 'user', [
                    'value' => array_merge(
                        [
                            'username' => sanitize_text_field((string)$user_login),
                        ],
                        wpopt_encrypt_activity_log_password($password)
                    )
                ]);
            }, 10, 1);

            add_action('wp_login', function ($user_login, \WP_User $user) {

                $this->log('logged-in', 'user', [
                    'object_id' => $user->ID,
                    'value'     => $user_login
                ]);
            }, 10, 2);

            add_action('profile_update', function ($user_id) {

                $this->log('updated-profile', 'user', [
                    'object_id' => $user_id,
                    'value'     => wps_get_user($user_id)->display_name ?? ''
                ]);

            }, 10, 1);

            add_action('user_register', function ($user_id) {

                $this->log('registered', 'user', [
                    'object_id' => $user_id,
                    'value'     => wps_get_user($user_id)->display_name ?? ''
                ]);
            }, 10, 1);

            add_action('delete_user', function ($user_id, $reassign, \WP_User $user) {

                $this->log('delete', 'user', [
                        'object_id' => $user_id,
                        'value'     => $user->display_name
                    ]
                );

            }, 10, 3);
        }

        if ($this->option('attachments')) {

            add_action('add_attachment', function ($attachment_id) {
                $post = wps_get_post($attachment_id);
                $this->log('insert', 'attachment', [
                    'object_id' => $attachment_id,
                    'value'     => esc_html($post->post_name)
                ]);
            }, 10, 1);

            add_action('edit_attachment', function ($attachment_id) {
                $post = wps_get_post($attachment_id);
                $this->log('edit', 'attachment', [
                    'object_id' => $attachment_id,
                    'value'     => esc_html($post->post_name)
                ]);
            }, 10, 1);

            add_action('delete_attachment', function ($attachment_id) {
                $post = wps_get_post($attachment_id);
                $this->log('delete', 'attachment', [
                    'object_id' => $attachment_id,
                    'value'     => esc_html($post->post_name)
                ]);
            }, 10, 1);
        }

        if ($this->option('options')) {

            add_action('updated_option', function ($option, $old_value, $value) {
                $this->log('update', 'option', [
                    'object_id' => $option,
                    'value'     => [
                        'old' => $old_value,
                        'new' => $value
                    ]
                ]);
            }, 10, 3);
        }

        if ($this->option('plugins')) {

            add_action('activated_plugin', function ($plugin) {
                $this->log('update', 'option', [
                    'value' => $plugin
                ]);
            }, 10, 1);

            add_action('deactivated_plugin', function ($plugin) {
                $this->log('update', 'option', [
                    'value' => $plugin
                ]);
            }, 10, 1);

            add_action('upgrader_process_complete', [$this, 'log_plugin_upgrade'], 10, 2);
        }

        if ($this->option('posts')) {

            add_action('save_post', function ($post_ID, $post, $update) {
                $this->log($update ? 'update' : 'insert', 'post', [
                    'object_id' => $post_ID,
                    'value'     => $post->post_name
                ]);
            }, 10, 3);

            add_action('before_delete_post', function ($post_ID, \WP_Post $post) {
                $this->log('delete', 'post', [
                    'object_id' => $post_ID,
                    'value'     => $post->post_name
                ]);
            }, 10, 2);
        }

        if ($this->option('terms')) {

            add_action('created_term', function ($term_id, $tt_id, $taxonomy) {

                $term = wps_get_term($term_id);

                $this->log('insert', 'term', [
                    'object_id' => $term_id,
                    'value'     => [
                        'name'     => $term->name,
                        'taxonomy' => $taxonomy
                    ]
                ]);
            }, 10, 3);

            add_action('edited_term', function ($term_id, $tt_id, $taxonomy) {

                $term = wps_get_term($term_id);

                $this->log('update', 'term', [
                    'object_id' => $term_id,
                    'value'     => [
                        'name'     => $term->name,
                        'taxonomy' => $taxonomy
                    ]
                ]);
            }, 10, 3);

            add_action('delete_term', function ($term_id, $tt_id, $taxonomy, \WP_Term $deleted_term) {
                $this->log('delete', 'term', [
                    'object_id' => $term_id,
                    'value'     => [
                        'name'     => $deleted_term->name,
                        'taxonomy' => $taxonomy
                    ]
                ]);
            }, 10, 4);
        }

        if ($this->option('on_404')) {

            add_action('template_redirect', function () {
                if (is_404()) {
                    $this->log('404', '404', [
                        'object_id' => 0,
                        'value'     => Rewriter::getInstance()->raw_url
                    ]);
                }
            }, 10, 0);
        }

        if ($this->option('bad_query.active')) {
            add_action('init', [$this, 'log_bad_queries'], 10, 0);
        }
    }

    protected function setting_fields($filter = ''): array
    {
        $auto_clear_active = (bool)$this->option('auto_clear', false);

        return $this->group_setting_fields(

            $this->group_setting_fields(
                $this->setting_field(__('Log IP addresses', 'wpopt'), "log.ip", "checkbox", ['default_value' => false]),
                $this->setting_field(__('Log User Agent', 'wpopt'), "log.user_agent", "checkbox", ['default_value' => false]),
                $this->setting_field(__('Log REQUEST arguments', 'wpopt'), "log.requests", "checkbox", ['default_value' => false]),

            ),

            $this->group_setting_fields(
                $this->setting_field(__('Auto clear logs', 'wpopt'), "auto_clear", "checkbox", ['default_value' => false]),
                $this->setting_field(
                    __('Keeps log for (days)', 'wpopt'),
                    "lifetime",
                    "numeric",
                    $auto_clear_active
                        ? ['default_value' => 90]
                        : ['default_value' => 90, 'parent' => 'auto_clear']
                ),
            ),

            $this->group_setting_fields(
                $this->setting_field(__('User activity log (login, registration, deletion)', 'wpopt'), "users", "checkbox", ['default_value' => true]),
                $this->setting_field(__('Posts activity log', 'wpopt'), "posts", "checkbox", ['default_value' => false]),
                $this->setting_field(__('Terms activity log', 'wpopt'), "terms", "checkbox", ['default_value' => false]),
                $this->setting_field(__('Attachments activity log', 'wpopt'), "attachments", "checkbox", ['default_value' => false]),
            ),

            $this->group_setting_fields(

                $this->setting_field(__('Options update log', 'wpopt'), "options", "checkbox", ['default_value' => false]),
                $this->setting_field(__('Plugin upgrades/installs log', 'wpopt'), "plugins", "checkbox", ['default_value' => true]),
            ),

            $this->group_setting_fields(
                $this->setting_field(__('404 log', 'wpopt'), "on_404", "checkbox", ['default_value' => false]),
                $this->setting_field(__('Bad query (maybe info gathering) activity log', 'wpopt'), "bad_query.active", "checkbox", ['default_value' => false]),
                $this->setting_field(__('Try to match xss attempts', 'wpopt'), "bad_query.xss", "checkbox", ['parent' => 'bad_query.active', 'default_value' => false]),
                $this->setting_field(__('Try to match sql injections attempts', 'wpopt'), "bad_query.sql", "checkbox", ['parent' => 'bad_query.active', 'default_value' => false]),
                $this->setting_field(__('Try to match path traversal attempts', 'wpopt'), "bad_query.traversal", "checkbox", ['parent' => 'bad_query.active', 'default_value' => false]),
                $this->setting_field(__('Try to match command injection attempts', 'wpopt'), "bad_query.command", "checkbox", ['parent' => 'bad_query.active', 'default_value' => false]),
                $this->setting_field(__('Try to match access to inconsistent data', 'wpopt'), "bad_query.pages", "checkbox", ['parent' => 'bad_query.active', 'default_value' => false]),
                $this->setting_field(__('Try to match probes and scanner requests', 'wpopt'), "bad_query.probes", "checkbox", ['parent' => 'bad_query.active', 'default_value' => false]),
                $this->setting_field(__('Custom rules (one per line)', 'wpopt'), "bad_query.regexes", "textarea_array", ['parent' => 'bad_query.active', 'value' => implode(PHP_EOL, $this->option('bad_query.regexes', []))]),
            )
        );
    }

    protected function infos(): array
    {
        return [
            'bad_query.command' => __('Track suspicious shell-style payloads and command execution attempts in request parameters.', 'wpopt'),
            'bad_query.pages'   => __('Track requests that look like reconnaissance for sensitive files, backups or environment dumps.', 'wpopt'),
            'bad_query.probes'  => __('Track automated probes against common exploit paths and scanner fingerprints.', 'wpopt'),
            'bad_query.regexes' => __('Provide a list of custom regexes that you want to use to monitor bad url.', 'wpopt'),
            'bad_query.sql'     => __('Track payloads that resemble SQL injection patterns in URLs, query strings and request bodies.', 'wpopt'),
            'bad_query.traversal' => __('Track attempts to reach files outside the web root through traversal patterns and sensitive OS paths.', 'wpopt'),
            'bad_query.xss'     => __('Track request payloads that resemble inline script injection or DOM-based XSS vectors.', 'wpopt'),
        ];
    }

    private function get_activity_summary(): array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            'SELECT COUNT(*) AS total_logs, COUNT(DISTINCT context) AS total_contexts, COUNT(DISTINCT action) AS total_actions FROM ' . WPOPT_TABLE_ACTIVITY_LOG,
            ARRAY_A
        );

        return [
            'total_logs'     => absint($row['total_logs'] ?? 0),
            'total_contexts' => absint($row['total_contexts'] ?? 0),
            'total_actions'  => absint($row['total_actions'] ?? 0),
        ];
    }

    private function get_activity_context_breakdown(int $limit = 8): array
    {
        global $wpdb;

        return (array)$wpdb->get_results(
            $wpdb->prepare(
                'SELECT context, COUNT(*) AS total
                 FROM ' . WPOPT_TABLE_ACTIVITY_LOG . '
                 GROUP BY context
                 ORDER BY total DESC
                 LIMIT %d',
                $limit
            ),
            ARRAY_A
        );
    }

    private function get_activity_action_breakdown(int $limit = 8): array
    {
        global $wpdb;

        return (array)$wpdb->get_results(
            $wpdb->prepare(
                'SELECT action, COUNT(*) AS total
                 FROM ' . WPOPT_TABLE_ACTIVITY_LOG . '
                 GROUP BY action
                 ORDER BY total DESC
                 LIMIT %d',
                $limit
            ),
            ARRAY_A
        );
    }

    private function get_activity_daily_series(int $days = 30): array
    {
        global $wpdb;

        $days = max(1, $days);
        $start_timestamp = strtotime('-' . ($days - 1) . ' days', current_time('timestamp'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT DATE(FROM_UNIXTIME(time)) AS bucket, context, COUNT(*) AS total
                 FROM ' . WPOPT_TABLE_ACTIVITY_LOG . '
                 WHERE time >= %d
                 GROUP BY DATE(FROM_UNIXTIME(time)), context
                 ORDER BY bucket ASC, total DESC',
                $start_timestamp
            ),
            ARRAY_A
        );

        $indexed = array();
        $context_totals = array();

        foreach ((array)$rows as $row) {
            $bucket = (string)$row['bucket'];
            $context = (string)$row['context'];
            $total = (int)$row['total'];

            if (!isset($indexed[$bucket])) {
                $indexed[$bucket] = array();
            }

            $indexed[$bucket][$context] = $total;
            $context_totals[$context] = ($context_totals[$context] ?? 0) + $total;
        }

        arsort($context_totals);
        $contexts = array_keys($context_totals);
        $palette = $this->get_activity_palette();
        $colors = array();

        foreach ($contexts as $index => $context) {
            $colors[$context] = $palette[$index % count($palette)];
        }

        $series = array();
        for ($i = 0; $i < $days; $i++) {
            $timestamp = strtotime('+' . $i . ' days', $start_timestamp);
            $bucket = wp_date('Y-m-d', $timestamp);
            $segments = array();
            $day_total = 0;

            foreach ($contexts as $context) {
                $value = (int)($indexed[$bucket][$context] ?? 0);
                $segments[$context] = $value;
                $day_total += $value;
            }

            $series[] = array(
                'bucket'   => $bucket,
                'label'    => wp_date('d M', $timestamp),
                'total'    => $day_total,
                'segments' => $segments,
            );
        }

        return array(
            'series'   => $series,
            'contexts' => $contexts,
            'colors'   => $colors,
        );
    }

    private function render_activity_charts(array $daily_series, array $summary): string
    {
        ob_start();
        ?>
        <style>
            .wpopt-activity-shell { margin: 4px 0 18px; }
            .wpopt-activity-kpis { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:16px; }
            .wpopt-activity-kpi { padding:14px 16px; border:1px solid rgba(15, 23, 42, 0.08); border-radius:16px; background:linear-gradient(135deg, #f8fafc 0%, #ffffff 100%); }
            .wpopt-activity-kpi span { display:block; font-size:11px; letter-spacing:.08em; text-transform:uppercase; color:#64748b; margin-bottom:6px; }
            .wpopt-activity-kpi strong { font-size:26px; color:#0f172a; }
            .wpopt-activity-daily { margin-bottom:16px; padding:18px; border:1px solid rgba(15, 23, 42, 0.08); border-radius:18px; background:linear-gradient(180deg, #ffffff 0%, #f8fafc 100%); }
            .wpopt-activity-legend { display:flex; flex-wrap:wrap; gap:8px; margin:12px 0 2px; }
            .wpopt-activity-legend-item { display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border-radius:999px; background:#ffffff; border:1px solid rgba(15, 23, 42, 0.08); font-size:12px; color:#334155; }
            .wpopt-activity-legend-swatch { width:10px; height:10px; border-radius:999px; display:inline-block; }
            .wpopt-activity-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px; }
            .wpopt-activity-chart { padding:16px; border:1px solid rgba(15, 23, 42, 0.08); border-radius:16px; background:linear-gradient(180deg, #ffffff 0%, #f8fafc 100%); }
            .wpopt-activity-chart h3 { margin:0 0 12px; }
            .wpopt-activity-empty { padding:18px; border:1px dashed #cbd5e1; border-radius:12px; color:#64748b; background:#f8fafc; }
            @media (max-width: 980px) {
                .wpopt-activity-grid { grid-template-columns:1fr; }
            }
        </style>
        <div class="wpopt-activity-shell">
            <div class="wpopt-activity-kpis">
                <div class="wpopt-activity-kpi">
                    <span><?php _e('Total logs', 'wpopt'); ?></span>
                    <strong><?php echo number_format_i18n($summary['total_logs']); ?></strong>
                </div>
                <div class="wpopt-activity-kpi">
                    <span><?php _e('Collected contexts', 'wpopt'); ?></span>
                    <strong><?php echo number_format_i18n($summary['total_contexts']); ?></strong>
                </div>
                <div class="wpopt-activity-kpi">
                    <span><?php _e('Collected actions', 'wpopt'); ?></span>
                    <strong><?php echo number_format_i18n($summary['total_actions']); ?></strong>
                </div>
            </div>
            <div class="wpopt-activity-daily">
                <?php echo !empty($daily_series) ? $this->render_activity_daily_chart($daily_series) : $this->render_activity_empty(); ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_activity_daily_chart(array $series): string
    {
        $daily_rows = $series['series'] ?? [];
        $contexts = $series['contexts'] ?? [];
        $colors = $series['colors'] ?? [];

        if (empty($daily_rows)) {
            return $this->render_activity_empty();
        }

        $max_value = 1;
        $total = 0;
        $peak_total = 0;
        $peak_label = '';

        foreach ($daily_rows as $point) {
            $value = (int)$point['total'];
            $max_value = max($max_value, $value);
            $total += $value;

            if ($value >= $peak_total) {
                $peak_total = $value;
                $peak_label = (string)$point['label'];
            }
        }

        $average = $total > 0 ? round($total / max(1, count($daily_rows)), 1) : 0;
        $width = 960;
        $height = 260;
        $padding_top = 26;
        $padding_right = 20;
        $padding_bottom = 42;
        $padding_left = 42;
        $plot_width = $width - $padding_left - $padding_right;
        $plot_height = $height - $padding_top - $padding_bottom;
        $count = max(1, count($daily_rows));
        $column_gap = 4;
        $column_width = max(8, (($plot_width - ($column_gap * ($count - 1))) / $count));

        ob_start();
        ?>
        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:14px;">
            <div>
                <div style="font-size:11px; letter-spacing:.08em; text-transform:uppercase; color:#64748b; margin-bottom:6px;"><?php _e('Daily trend', 'wpopt'); ?></div>
                <div style="font-size:20px; font-weight:600; color:#0f172a;"><?php _e('Activity volume by day', 'wpopt'); ?></div>
                <div style="font-size:13px; color:#64748b; margin-top:4px;"><?php echo esc_html(sprintf(_n('Trend for the last %s day.', 'Trend for the last %s days.', count($daily_rows), 'wpopt'), number_format_i18n(count($daily_rows)))); ?></div>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <div style="min-width:120px; padding:12px 14px; border-radius:14px; background:linear-gradient(135deg, #0f766e 0%, #115e59 100%); color:#f8fafc;">
                    <span style="display:block; font-size:10px; letter-spacing:.08em; text-transform:uppercase; opacity:.75;"><?php _e('Last period', 'wpopt'); ?></span>
                    <strong style="display:block; font-size:24px; line-height:1.1;"><?php echo number_format_i18n($total); ?></strong>
                </div>
                <div style="min-width:120px; padding:12px 14px; border-radius:14px; background:#f8fafc; border:1px solid rgba(15, 23, 42, 0.08); color:#0f172a;">
                    <span style="display:block; font-size:10px; letter-spacing:.08em; text-transform:uppercase; color:#64748b;"><?php _e('Avg/day', 'wpopt'); ?></span>
                    <strong style="display:block; font-size:24px; line-height:1.1;"><?php echo number_format_i18n($average, 1); ?></strong>
                </div>
                <div style="min-width:120px; padding:12px 14px; border-radius:14px; background:#fff7ed; border:1px solid rgba(234, 88, 12, 0.12); color:#9a3412;">
                    <span style="display:block; font-size:10px; letter-spacing:.08em; text-transform:uppercase; opacity:.7;"><?php _e('Peak day', 'wpopt'); ?></span>
                    <strong style="display:block; font-size:24px; line-height:1.1;"><?php echo number_format_i18n($peak_total); ?></strong>
                    <small style="font-size:12px;"><?php echo esc_html($peak_label); ?></small>
                </div>
            </div>
        </div>
        <?php if (!empty($contexts)): ?>
            <div class="wpopt-activity-legend">
                <?php foreach ($contexts as $context): ?>
                    <span class="wpopt-activity-legend-item">
                        <i class="wpopt-activity-legend-swatch" style="background:<?php echo esc_attr($colors[$context] ?? '#0f766e'); ?>"></i>
                        <?php echo esc_html($this->format_activity_context_label($context)); ?>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <svg viewBox="0 0 <?php echo $width; ?> <?php echo $height; ?>" width="100%" height="260" role="img" aria-label="<?php esc_attr_e('Activity log trend by day', 'wpopt'); ?>">
            <?php for ($step = 0; $step <= 4; $step++): ?>
                <?php $y = $padding_top + (($plot_height / 4) * $step); ?>
                <line x1="<?php echo $padding_left; ?>" y1="<?php echo round($y, 2); ?>" x2="<?php echo $padding_left + $plot_width; ?>" y2="<?php echo round($y, 2); ?>" stroke="#e2e8f0" stroke-width="1"></line>
            <?php endfor; ?>
            <line x1="<?php echo $padding_left; ?>" y1="<?php echo $padding_top; ?>" x2="<?php echo $padding_left; ?>" y2="<?php echo $padding_top + $plot_height; ?>" stroke="#cbd5e1" stroke-width="1"></line>
            <line x1="<?php echo $padding_left; ?>" y1="<?php echo $padding_top + $plot_height; ?>" x2="<?php echo $padding_left + $plot_width; ?>" y2="<?php echo $padding_top + $plot_height; ?>" stroke="#cbd5e1" stroke-width="1"></line>
            <text x="10" y="<?php echo $padding_top + 8; ?>" fill="#64748b" font-size="11"><?php echo number_format_i18n($max_value); ?></text>
            <text x="14" y="<?php echo $padding_top + $plot_height; ?>" fill="#64748b" font-size="11">0</text>
            <?php foreach ($daily_rows as $index => $point): ?>
                <?php
                $x = $padding_left + (($column_width + $column_gap) * $index);
                $cursor_y = $padding_top + $plot_height;
                ?>
                <?php foreach ($contexts as $context): ?>
                    <?php
                    $segment_total = (int)($point['segments'][$context] ?? 0);
                    if ($segment_total < 1) {
                        continue;
                    }
                    $segment_height = $plot_height * ($segment_total / $max_value);
                    $cursor_y -= $segment_height;
                    ?>
                    <rect x="<?php echo round($x, 2); ?>" y="<?php echo round($cursor_y, 2); ?>" width="<?php echo round($column_width, 2); ?>" height="<?php echo round($segment_height, 2); ?>" fill="<?php echo esc_attr($colors[$context] ?? '#0f766e'); ?>" opacity="0.92"></rect>
                <?php endforeach; ?>
                <?php if ($point['total'] > 0): ?>
                    <text x="<?php echo round($x + ($column_width / 2), 2); ?>" y="<?php echo max(14, round($cursor_y - 6, 2)); ?>" text-anchor="middle" fill="#334155" font-size="10"><?php echo number_format_i18n($point['total']); ?></text>
                <?php endif; ?>
                <?php if ($index % max(1, (int)floor(count($daily_rows) / 8)) === 0 || $index === count($daily_rows) - 1): ?>
                    <text x="<?php echo round($x + ($column_width / 2), 2); ?>" y="<?php echo $padding_top + $plot_height + 18; ?>" text-anchor="middle" fill="#64748b" font-size="10"><?php echo esc_html($point['label']); ?></text>
                <?php endif; ?>
            <?php endforeach; ?>
        </svg>
        <?php
        return ob_get_clean();
    }

    private function get_activity_palette(): array
    {
        return array(
            '#0f766e',
            '#ea580c',
            '#2563eb',
            '#7c3aed',
            '#dc2626',
            '#0891b2',
            '#65a30d',
            '#d97706',
            '#be185d',
            '#4f46e5',
            '#16a34a',
            '#334155',
        );
    }

    private function format_activity_context_label(string $context): string
    {
        return ucwords(str_replace(array('_', '-'), ' ', $context));
    }

    private function render_activity_bar_chart(array $rows, string $label_key, string $color, bool $translate_action = false): string
    {
        $width = 460;
        $height = 260;
        $padding_top = 18;
        $padding_right = 20;
        $padding_bottom = 26;
        $padding_left = 140;
        $plot_width = $width - $padding_left - $padding_right;
        $plot_height = $height - $padding_top - $padding_bottom;
        $count = max(1, count($rows));
        $slot_height = $plot_height / $count;
        $bar_height = min(22, $slot_height * 0.64);
        $max_value = max(1, ...array_map(static function ($row) {
            return (int)$row['total'];
        }, $rows));

        ob_start();
        ?>
        <svg viewBox="0 0 <?php echo $width; ?> <?php echo $height; ?>" width="100%" height="260" role="img">
            <?php foreach ($rows as $index => $row): ?>
                <?php
                $value = (int)$row['total'];
                $label = (string)$row[$label_key];
                if ($translate_action) {
                    $label = ucwords(str_replace('-', ' ', __($label, 'wpopt')));
                }
                else {
                    $label = ucwords(str_replace(['_', '-'], ' ', $label));
                }
                $y = $padding_top + ($slot_height * $index) + (($slot_height - $bar_height) / 2);
                $bar_width = $plot_width * ($value / $max_value);
                ?>
                <text x="<?php echo $padding_left - 10; ?>" y="<?php echo round($y + ($bar_height / 2) + 4, 2); ?>" text-anchor="end" fill="#334155" font-size="12"><?php echo esc_html($label); ?></text>
                <rect x="<?php echo $padding_left; ?>" y="<?php echo round($y, 2); ?>" width="<?php echo round($bar_width, 2); ?>" height="<?php echo round($bar_height, 2); ?>" rx="10" fill="<?php echo esc_attr($color); ?>" opacity="0.9"></rect>
                <text x="<?php echo round($padding_left + $bar_width + 8, 2); ?>" y="<?php echo round($y + ($bar_height / 2) + 4, 2); ?>" fill="#0f172a" font-size="12"><?php echo number_format_i18n($value); ?></text>
            <?php endforeach; ?>
        </svg>
        <?php
        return ob_get_clean();
    }

    private function render_activity_empty(): string
    {
        return '<div class="wpopt-activity-empty">' . esc_html__('No activity data available yet.', 'wpopt') . '</div>';
    }
}

return __NAMESPACE__;

