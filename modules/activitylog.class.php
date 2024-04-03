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

global $wpdb;

define('WPOPT_ACTIVITY_LOG_TABLE', "{$wpdb->prefix}wpopt_activity_log");

/**
 *  Module for images optimization handling
 */
class Mod_ActivityLog extends Module
{
    public static $name = 'Activity Log';

    public array $scopes = array('autoload', 'admin-page', 'settings');

    protected string $context = 'wpopt';

    public function actions(): void
    {
        if ($this->option('auto_clear')) {
            CronActions::schedule("WPOPT-ActivityLog", DAY_IN_SECONDS, function () {
                Query::getInstance()->delete(['time' => time() - ($this->option('lifetime') * DAY_IN_SECONDS), 'compare' => '<'], WPOPT_ACTIVITY_LOG_TABLE)->query();
            }, '08:00');
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

                    Query::getInstance()->tables(WPOPT_ACTIVITY_LOG_TABLE)->action('TRUNCATE')->query();

                    Rewriter::getInstance(admin_url('admin.php'))->add_query_args(array(
                        'page'    => 'activitylog',
                        'message' => 'wpopt-actlog-data-erased',
                    ))->redirect();
                    break;
            }
        });
    }

    public function render_sub_modules(): void
    {
        require_once WPOPT_SUPPORTERS . '/activity-log/ActivityLog_Table.class.php';

        $table = new ActivityLog(['action_hook' => $this->action_hook, 'settings' => $this->option()]);

        $table->prepare_items();
        ?>
        <section class="wps-wrap">
            <block class="wps">
                <section class="wps-header"><h1>Activity Log</h1></section>
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
                       class="button button-primary">
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

            if (!$path)
                return;

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

        $wpdb->insert(WPOPT_ACTIVITY_LOG_TABLE, $fields);
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
        $rewriter = Rewriter::getInstance();

        if ($this->option('bad_query.xss') and $rewriter->match("('|\"|-).*(prompt|alert|script|eval)|<(%|/)?\w+|\*/", false)) {

            $this->log('maybe-xss', 'bad_query', [
                'object_id' => 0,
                'value'     => Rewriter::getInstance()->raw_url
            ]);
        }
        elseif ($this->option('bad_query.sql') and $rewriter->match("\b(UNION|SELECT|FROM|INSERT|UPDATE|SET|DELETE|EXEC|CREATE|PROC|CAST|TABLE|'|\"|VARCHAR|DECLARE)\b", false)) {

            $this->log('maybe-sql-injection', 'bad_query', [
                'object_id' => 0,
                'value'     => Rewriter::getInstance()->raw_url
            ]);
        }
        elseif ($this->option('bad_query.pages') and $rewriter->match('wp-config', false)) {

            $this->log('maybe info gathering', 'bad_query', [
                'object_id' => 0,
                'value'     => Rewriter::getInstance()->raw_url
            ]);
        }

        foreach ($this->option('bad_query.regexes', []) as $regex) {

            $regex = trim($regex);

            if ($regex and $rewriter->match($regex, false)) {
                $this->log("custom-regex", 'bad_query', [
                    'user_id'   => 0,
                    'object_id' => 0,
                    'value'     => [
                        'url'   => Rewriter::getInstance()->raw_url,
                        'regex' => $regex
                    ]
                ]);
            }
        }
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

                $this->log('failed-login', 'user', [
                    'value' => [
                        'username' => esc_sql($user_login),
                        'password' => esc_sql($_POST['pwd'] ?? $_POST['password'] ?? '')
                    ]
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
        return $this->group_setting_fields(

            $this->group_setting_fields(
                $this->setting_field(__('Log IP addresses', 'wpopt'), "log.ip", "checkbox", ['default_value' => false]),
                $this->setting_field(__('Log User Agent', 'wpopt'), "log.user_agent", "checkbox", ['default_value' => false]),
                $this->setting_field(__('Log REQUEST arguments', 'wpopt'), "log.requests", "checkbox", ['default_value' => false]),

            ),

            $this->group_setting_fields(
                $this->setting_field(__('Auto clear logs', 'wpopt'), "auto_clear", "checkbox", ['default_value' => false]),
                $this->setting_field(__('Keeps log for (days)', 'wpopt'), "lifetime", "numeric", ['default_value' => 90, 'parent' => 'auto_clear']),
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
                $this->setting_field(__('Try to match access to inconsistent data', 'wpopt'), "bad_query.pages", "checkbox", ['parent' => 'bad_query.active', 'default_value' => false]),
                $this->setting_field(__('Custom rules (one per line)', 'wpopt'), "bad_query.regexes", "textarea_array", ['parent' => 'bad_query.active', 'value' => implode(PHP_EOL, $this->option('bad_query.regexes', []))]),
            )
        );
    }

    protected function infos(): array
    {
        return [
            'bad_query.regexes' => __('Provide a list of custom regexes that you want to use to monitor bad url.', 'wpopt'),
        ];
    }
}

return __NAMESPACE__;
