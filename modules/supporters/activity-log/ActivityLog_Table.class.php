<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

use WPS\core\RequestActions;
use WPS\core\Query;
use WPS\core\Settings;
use WPS\core\UtilEnv;

/**
 * Core class used to display activity logs.
 *
 * @see \WP_List_Table
 */
class ActivityLog extends \WP_List_Table
{
    private $settings;

    private string $action_hook;

    public function __construct($args = array())
    {
        $this->modes = array(
            'list' => __('List view', 'wpopt'),
        );

        $this->settings = $args['settings'];
        $this->action_hook = $args['action_hook'] ?? '';

        parent::__construct(
            array(
                'singular' => __('activity', 'wpopt'),
                'plural'   => __('activities', 'wpopt'),
                'ajax'     => false,
                'screen'   => get_current_screen(),
            )
        );

        add_screen_option(
            'per_page',
            array(
                'default' => 50,
                'label'   => __('Activities', 'wpopt'),
                'option'  => 'edit_wpopt_logs_per_page',
            )
        );

        add_filter('set-screen-option', array($this, 'set_screen_option'), 10, 3);
    }

    public function set_screen_option($status, $option, $value)
    {
        if ('edit_wpopt_logs_per_page' === $option)
            return $value;

        return $status;
    }

    public function display_tablenav($which)
    {
        ?>
        <div class="tablenav <?php echo esc_attr($which); ?>">
            <?php
            $this->extra_tablenav($which);
            if ('top' == $which) {
                $this->search_box(__('Search', 'wpopt'), 'wpopt-activityLog');
            }
            $this->pagination($which);
            ?>
        </div>
        <?php
    }

    public function search_box($text, $input_id)
    {
        $search_data = $this->get_search_term($_REQUEST);

        $input_id = $input_id . '-search-input';
        ?>
        <p class="search-box">
            <label class="screen-reader-text" for="<?php echo esc_attr($input_id); ?>"><?php echo esc_html($text); ?>:</label>
            <input type="search" id="<?php echo esc_attr($input_id); ?>" name="s" value="<?php echo esc_attr($search_data); ?>"/>
            <?php submit_button($text, 'button', false, false, array('id' => 'search-submit')); ?>
        </p>
        <?php
    }

    public function extra_tablenav($which)
    {
        if ('top' !== $which) {
            if ('bottom' === $which) {
                $this->extra_tablenav_footer();
            }
            return;
        }

        echo '<div class="alignleft actions">';


        if (!isset($_REQUEST['filter_date'])) {
            $_REQUEST['filter_date'] = '';
        }

        $date_options = array(
            ''          => __('All Time', 'wpopt'),
            'today'     => __('Today', 'wpopt'),
            'yesterday' => __('Yesterday', 'wpopt'),
            'week'      => __('This Week', 'wpopt'),
            'month'     => __('This Month', 'wpopt'),
        );

        echo '<select name="filter_date" id="hs-filter-date">';
        foreach ($date_options as $key => $value) {
            printf('<option value="%s" %s>%s</option>', esc_attr($key), selected($_REQUEST['filter_date'], $key, false), esc_html($value));
        }
        echo '</select>';

        submit_button(__('Filter', 'wpopt'), 'button', 'aal-filter', false, array('id' => 'activity-query-submit'));

        $actions = Query::getInstance()->tables(WPOPT_TABLE_ACTIVITY_LOG)->orderby('action', 'ASC', WPOPT_TABLE_ACTIVITY_LOG)->select('DISTINCT action')->query(false, true);

        if ($actions) {

            if (!isset($_REQUEST['filter_action'])) {
                $_REQUEST['filter_action'] = '';
            }

            echo '<select name="filter_action" id="hs-filter-filter_action">';
            printf('<option value="">%s</option>', esc_html__('All Actions', 'wpopt'));
            foreach ($actions as $action) {
                printf('<option value="%s"%s>%s</option>', esc_attr((string)$action), selected($_REQUEST['filter_action'], $action, false), esc_html($this->get_action_label($action)));
            }
            echo '</select>';
        }

        $filters = array(
            'filter_date',
            'filter_user',
            'filter_action',
        );

        foreach ($filters as $filter) {
            if (!empty($_REQUEST[$filter])) {
                echo '<a href="' . esc_url($this->get_filtered_link()) . '"><span class="dashicons dashicons-dismiss"></span>' . esc_html__('Reset Filters', 'wpopt') . '</a>';
                break;
            }
        }

        echo '</div>';
    }

    public function extra_tablenav_footer()
    {
        $actions = [
            'csv'        => 'CSV',
            'json'       => 'JSON',
            'xml'        => 'XML',
            'ods'        => 'Spreadsheet',
            'serialized' => 'Serialized',
            'php_array'  => 'PHP ARRAY'
        ];
        ?>
        <div class="alignleft actions recordactions">
            <select name="export-format">
                <option value=""><?php echo esc_attr__('Export File Format', 'wpopt'); ?></option>
                <?php foreach ($actions as $action_key => $action_title) : ?>
                    <option value="<?php echo esc_attr($action_key); ?>"><?php echo esc_html($action_title); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php echo RequestActions::get_action_button($this->action_hook, 'export', __('Export Data', 'wpopt'), 'wps wps-button wpopt-btn is-info'); ?>
        <?php
    }

    public function get_action_label($action): string
    {
        return ucwords(str_replace('-', ' ', __($action, 'wpopt')));
    }

    private function get_filtered_link($name = '', $value = ''): string
    {
        $base_page_url = menu_page_url('wpopt-activitylog', false);

        if (empty($name)) {
            return $base_page_url;
        }

        return add_query_arg($name, $value, $base_page_url);
    }

    private function get_search_term($request): string
    {
        $search = $request['s'] ?? '';

        if (!is_scalar($search)) {
            return '';
        }

        return sanitize_text_field(wp_unslash((string)$search));
    }

    private function uses_unsafe_like_subquery_pattern(string $search): bool
    {
        return '' !== $search && 1 === preg_match('#^[(\s]*SELECT\s+#i', $search);
    }

    private function filter_items_by_search(array $items, string $search, array $fields): array
    {
        if ('' === $search) {
            return $items;
        }

        return array_values(array_filter($items, static function ($item) use ($search, $fields) {
            foreach ($fields as $field) {
                $value = '';

                if (is_array($item) && isset($item[$field])) {
                    $value = (string)$item[$field];
                }
                elseif (is_object($item) && isset($item->$field)) {
                    $value = (string)$item->$field;
                }

                if ('' !== $value && false !== stripos($value, $search)) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function render_filter_link(string $name, $value, string $label): string
    {
        return sprintf(
            '<a href="%s">%s</a>',
            esc_url($this->get_filtered_link($name, (string)$value)),
            esc_html($label)
        );
    }

    private function render_plain_text(string $value): string
    {
        return '<span>' . esc_html($value) . '</span>';
    }

    private function render_serialized_value($value): string
    {
        return '<span>' . esc_html(var_export(maybe_unserialize($value), true)) . '</span>';
    }

    private function get_failed_login_meta($value): array
    {
        $user_data = maybe_unserialize($value);

        if (!is_array($user_data)) {
            $user_data = [];
        }

        $user_data = array_merge([
            'username'           => '',
            'password'           => '',
            'password_encrypted' => '',
            'password_present'   => false,
            'enc_version'        => 0,
        ], $user_data);

        if ('' === (string)$user_data['password'] && '' !== (string)$user_data['password_encrypted']) {
            $user_data['password'] = wpopt_decrypt_activity_log_password($user_data);
        }

        return $user_data;
    }

    public function column_default($item, $column_name)
    {
        $return = false;

        switch ($item->context) {

            case 'plugin':
            case 'attachments':
                if ($column_name == 'meta') {
                    $return = $this->render_plain_text((string)$item->value);
                }
                break;

            case '404':
                if ($column_name == 'meta') {
                    $value = esc_url((string)$item->value);
                    $return = sprintf('<a target="_blank" rel="noopener noreferrer" href="%s">%s</a>', $value, esc_html($value));
                }
                break;

            case 'user':

                switch ($column_name) {

                    case 'user_id':
                        $user = wps_get_user($item->user_id ?: $item->object_id);
                        $return = $user ? $this->render_filter_link('filter_user', $user->ID, $user->display_name) : '<span>N/A</span>';
                        break;

                    case 'meta':
                        $user_data = $this->get_failed_login_meta($item->value);

                        $return = $item->object_id ?
                            $this->render_filter_link('filter_object_id', $item->object_id, (string)$item->value) :
                            '<span>username: <b>' . esc_html((string)$user_data['username']) . '</b></span><br><span>password: <b>' . esc_html((string)$user_data['password']) . '</b></span>';
                        break;
                }
                break;

            case 'post':
                if ($column_name == 'meta') {
                    $return = $this->render_filter_link('filter_object_id', $item->object_id, (string)$item->value);
                }
                break;

            case 'term':
                if ($column_name == 'meta') {
                    $term_data = array_merge(['taxonomy' => '', 'name' => ''], (array)maybe_unserialize($item->value) ?: []);
                    $return = $this->render_filter_link('filter_object_id', $item->object_id, trim($term_data['taxonomy'] . ' > ' . $term_data['name'], ' >'));
                }
                break;
        }

        if ($return) {
            return $return;
        }

        switch ($column_name) {

            case 'user_id':
                $user = wps_get_user($item->user_id);
                $return = $user ? $this->render_filter_link('filter_user', $user->ID, $user->display_name) : '<span>N/A</span>';
                break;

            case 'ip':
                $return = $this->render_filter_link('filter_ip', $item->ip, (string)$item->ip);
                break;

            case 'action':
                $return = $this->render_filter_link('filter_action', $item->action, $this->get_action_label($item->action));
                break;

            case 'request':
                $return = $this->render_serialized_value($item->request);
                break;

            case 'time':
                $return = sprintf('<strong>' . __('%s ago', 'wpopt') . '</strong>', human_time_diff($item->time, time()));

                $return .= '<br/><a href="' . esc_url($this->get_filtered_link('filter_date', wp_date('d/m/Y', $item->time))) . '">' . esc_html(date_i18n(get_option('date_format'), $item->time)) . '</a>';

                $return .= '<br/>' . esc_html(date_i18n(get_option('time_format'), $item->time));
                break;

            case 'meta':
                $return = '<span>' . esc_html((string)($item->value ?? 'N/B')) . '</span>';
                break;

            default:
                $return = '<span>' . esc_html((string)($item->$column_name ?? 'N/B')) . '</span>';
        }

        return $return;
    }

    private function parse_query($request = ''): Query
    {
        if (empty($request)) {
            $request = $_REQUEST;
        }

        $search_term = $this->get_search_term($request);

        $query = Query::getInstance();
        $query->tables(WPOPT_TABLE_ACTIVITY_LOG);

        if (!isset($request['orderby']) or !in_array($request['orderby'], array('time', 'ip', 'action', 'context', 'user_id', 'object_id'))) {
            $request['orderby'] = 'time';
        }

        $query->orderby($request['orderby'], $request['order'] ?? 'DESC', WPOPT_TABLE_ACTIVITY_LOG);

        if (!empty($request['filter_action'])) {

            $query->where(['action' => sanitize_text_field($request['filter_action'])]);
        }

        if (!empty($request['filter_ip'])) {

            $query->where(['ip' => sanitize_text_field($request['filter_ip'])]);
        }

        if (!empty($request['filter_user'])) {

            $query->where(['user_id' => sanitize_text_field($request['filter_user'])]);
        }

        if (!empty($request['filter_object_id'])) {

            $query->where(['object_id' => sanitize_text_field($request['filter_object_id'])]);
        }

        if (!empty($request['filter_date'])) {

            list($start_time, $end_time) = UtilEnv::epochs_timestamp($request['filter_date']);

            if (!empty($start_time) && !empty($end_time)) {

                $query->where([
                    ['time' => $start_time, 'compare' => '>'],
                    ['time' => $end_time, 'compare' => '<'],
                ]);
            }
        }

        if ('' !== $search_term && !$this->uses_unsafe_like_subquery_pattern($search_term)) {
            $query->where(
                [
                    ['value' => $search_term, 'compare' => 'LIKE'],
                    ['user_agent' => $search_term, 'compare' => 'LIKE'],
                    ['request' => $search_term, 'compare' => 'LIKE']
                ],
                'OR'
            );
        }

        return $query;
    }

    public function get_items($use_limit = false)
    {
        // get requested order and other filters from _wp_http_referer
        parse_str(parse_url($_REQUEST['_wp_http_referer'] ?? '', PHP_URL_QUERY) ?: '', $request);

        $search_term = $this->get_search_term($request);
        $use_php_search_filter = $this->uses_unsafe_like_subquery_pattern($search_term);

        $query = $this->parse_query($request)->output(ARRAY_A);

        $items_per_page = $this->get_items_per_page('edit_wpopt_logs_per_page', 50);

        $offset = ($this->get_pagenum() - 1) * $items_per_page;

        if ($use_php_search_filter) {
            // Keep subquery-shaped input out of the shared query builder.
            $items = $query->action('select')->columns('*')->query();
            $items = $this->filter_items_by_search($items, $search_term, ['value', 'user_agent', 'request']);

            return array_slice($items, $offset, $use_limit ? $items_per_page : null);
        }

        if ($use_limit) {
            $query->limit($items_per_page);
        }

        return $query->offset($offset)->action('select')->columns('*')->query();
    }

    public function prepare_items()
    {
        $query = $this->parse_query();
        $search_term = $this->get_search_term($_REQUEST);
        $use_php_search_filter = $this->uses_unsafe_like_subquery_pattern($search_term);

        $items_per_page = $this->get_items_per_page('edit_wpopt_logs_per_page', 50);
        $this->_column_headers = array($this->get_columns(), $this->get_hidden_columns(), $this->get_sortable_columns());

        $offset = ($this->get_pagenum() - 1) * $items_per_page;

        if ($use_php_search_filter) {
            $items = $query->action('select')->columns('*')->query();
            $items = $this->filter_items_by_search($items, $search_term, ['value', 'user_agent', 'request']);
            $total_items = count($items);
            $this->items = array_slice($items, $offset, $items_per_page);
        }
        else {
            $total_items = $query->action('select')->columns('COUNT(*)')->query(true);
            $this->items = $query->limit($items_per_page)->offset($offset)->action('select')->columns('*')->recompile()->query();
        }

        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $items_per_page,
            'total_pages' => ceil($total_items / $items_per_page),
        ));
    }

    public function get_columns()
    {
        return array(
            'time'       => __('Time', 'wpopt'),
            'user_id'    => __('User', 'wpopt'),
            'ip'         => __('IP', 'wpopt'),
            'meta'       => __('Meta', 'wpopt'),
            'action'     => __('Action', 'wpopt'),
            'user_agent' => __('User-Agent', 'wpopt'),
            'request'    => __('Request', 'wpopt'),
        );
    }

    protected function get_sortable_columns()
    {
        return array(
            'action'    => array('action', 'desc'),
            'user_id'   => array('user_id', 'desc'),
            'object_id' => array('object_id', 'desc'),
            'ip'        => array('ip', 'desc'),
            'time'      => array('time', 'asc'),
        );
    }

    public function no_items()
    {
        _e('No Logs found.', 'wpopt');
    }

    private function get_hidden_columns(): array
    {
        $columns = get_hidden_columns($this->screen);

        if (!Settings::get_option($this->settings, 'log.requests', true)) {
            $columns[] = 'request';
        }

        if (!Settings::get_option($this->settings, 'log.user_agent', true)) {
            $columns[] = 'user_agent';
        }

        if (!Settings::get_option($this->settings, 'log.ip', true)) {
            $columns[] = 'ip';
        }

        return $columns;
    }
}


