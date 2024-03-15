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
 * @since 2.1.0
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
        if ('top' == $which) {
            $this->search_box(__('Search', 'wpopt'), 'wpopt-al-search');
        }
        ?>
        <div class="tablenav <?php echo esc_attr($which); ?>">
            <?php
            $this->extra_tablenav($which);
            $this->pagination($which);
            ?>
            <br class="clear"/>
        </div>
        <?php
    }

    public function search_box($text, $input_id)
    {
        $search_data = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';

        $input_id = $input_id . '-search-input';
        ?>
        <p class="search-box">
            <label class="screen-reader-text" for="<?php echo $input_id ?>"><?php echo $text; ?>:</label>
            <input type="search" id="<?php echo $input_id ?>" name="s" value="<?php echo esc_attr($search_data); ?>"/>
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
            printf('<option value="%s" %s>%s</option>', $key, selected($_REQUEST['filter_date'], $key, false), $value);
        }
        echo '</select>';

        submit_button(__('Filter', 'wpopt'), 'button', 'aal-filter', false, array('id' => 'activity-query-submit'));

        $actions = Query::getInstance()->tables(WPOPT_ACTIVITY_LOG_TABLE)->orderby('action', 'ASC', WPOPT_ACTIVITY_LOG_TABLE)->select('DISTINCT action')->query(false, true);

        if ($actions) {

            if (!isset($_REQUEST['filter_action'])) {
                $_REQUEST['filter_action'] = '';
            }

            echo '<select name="filter_action" id="hs-filter-filter_action">';
            printf('<option value="">%s</option>', __('All Actions', 'wpopt'));
            foreach ($actions as $action) {
                printf('<option value="%s"%s>%s</option>', $action, selected($_REQUEST['filter_action'], $action, false), $this->get_action_label($action));
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
                echo '<a href="' . $this->get_filtered_link() . '"><span class="dashicons dashicons-dismiss"></span>' . __('Reset Filters', 'wpopt') . '</a>';
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
        <?php echo RequestActions::get_action_button($this->action_hook, 'export', __('Export Data', 'wpopt'), 'button button-primary'); ?>
        <?php
    }

    public function get_action_label($action): string
    {
        return ucwords(str_replace('-', ' ', __($action, 'wpopt')));
    }

    private function get_filtered_link($name = '', $value = ''): string
    {
        $base_page_url = menu_page_url('activitylog', false);

        if (empty($name)) {
            return $base_page_url;
        }

        return add_query_arg($name, $value, $base_page_url);
    }

    public function column_default($item, $column_name)
    {
        $return = false;

        switch ($item->context) {

            case 'plugin':
            case 'attachments':
                if ($column_name == 'meta') {
                    $return = "<span>" . sanitize_text_field($item->value) . "</span>";
                }
                break;

            case '404':
                if ($column_name == 'meta') {
                    $value = esc_url($item->value);
                    $return = "<a target='_blank' href='$value'>$value</a>";
                }
                break;

            case 'user':

                switch ($column_name) {

                    case 'user_id':
                        $user = wps_get_user($item->user_id ?: $item->object_id);
                        $return = $user ? "<a href='" . $this->get_filtered_link('filter_user', $user->ID) . "'>$user->display_name</a>" : "<span>N/A</span>";
                        break;

                    case 'meta':
                        $user_data = array_merge(['username' => '', 'password' => ''], (array)maybe_unserialize($item->value) ?: []);

                        $return = $item->object_id ?
                            "<a href='" . $this->get_filtered_link('filter_object_id', $item->object_id) . "'>" . esc_html($item->value) . "</a>" :
                            "<span>username: <b>{$user_data['username']}</b></span><br><span>password: <b>{$user_data['password']}</b></span>";
                        break;
                }
                break;

            case 'post':
                if ($column_name == 'meta') {
                    $return = "<a href='" . $this->get_filtered_link('filter_object_id', $item->object_id) . "'>" . esc_html($item->value) . "</a>";
                }
                break;

            case 'term':
                if ($column_name == 'meta') {
                    $term_data = array_merge(['taxonomy' => '', 'name' => ''], (array)maybe_unserialize($item->value) ?: []);
                    $return = "<a href='" . $this->get_filtered_link('filter_object_id', $item->object_id) . "'>{$term_data['taxonomy']} > {$term_data['name']}</a>";
                }
                break;
        }

        if ($return) {
            return $return;
        }

        switch ($column_name) {

            case 'user_id':
                $user = wps_get_user($item->user_id);
                $return = $user ? "<a href='" . $this->get_filtered_link('filter_user', $user->ID) . "'>$user->display_name</a>" : "<span>N/A</span>";
                break;

            case 'ip':
                $return = "<a href='" . $this->get_filtered_link('filter_ip', $item->ip) . "'>$item->ip</a>";
                break;

            case 'action':
                $return = "<a href='" . $this->get_filtered_link('filter_action', $item->action) . "'>" . $this->get_action_label($item->action) . "</a>";
                break;

            case 'request':
                $return = "<span>" . var_export(maybe_unserialize($item->request), true) . "</span>";
                break;

            case 'time':
                $return = sprintf('<strong>' . __('%s ago', 'wpopt') . '</strong>', human_time_diff($item->time, time()));

                $return .= '<br/><a href="' . $this->get_filtered_link('filter_date', wp_date('d/m/Y', $item->time)) . '">' . date_i18n(get_option('date_format'), $item->time) . '</a>';

                $return .= '<br/>' . date_i18n(get_option('time_format'), $item->time);
                break;

            case 'meta':
                $return = '<span>' . esc_html($item->value ?? 'N/B') . '</span>';
                break;

            default:
                $return = '<span>' . esc_html($item->$column_name ?? 'N/B') . '</span>';
        }

        return $return;
    }

    private function parse_query($request = ''): Query
    {
        if (empty($request)) {
            $request = $_REQUEST;
        }

        $query = Query::getInstance();
        $query->tables(WPOPT_ACTIVITY_LOG_TABLE);

        if (!isset($request['orderby']) or !in_array($request['orderby'], array('time', 'ip', 'action', 'context', 'user_id', 'object_id'))) {
            $request['orderby'] = 'time';
        }

        $query->orderby($request['orderby'], $request['order'] ?? 'DESC', WPOPT_ACTIVITY_LOG_TABLE);

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

        if (!empty($request['s'])) {
            $query->where(
                [
                    ['value' => $request['s'], 'compare' => 'LIKE'],
                    ['user_agent' => $request['s'], 'compare' => 'LIKE'],
                    ['request' => $request['s'], 'compare' => 'LIKE']
                ],
                'OR'
            );
        }

        return $query;
    }

    public function get_items($use_limit = false)
    {
        // get requested order and other filters from _wp_http_referer
        parse_str(parse_url($_REQUEST['_wp_http_referer'] ?? '', PHP_URL_QUERY), $request);

        $query = $this->parse_query($request)->output(ARRAY_A);

        $items_per_page = $this->get_items_per_page('edit_wpopt_logs_per_page', 50);

        $offset = ($this->get_pagenum() - 1) * $items_per_page;

        if ($use_limit) {
            $query->limit($items_per_page);
        }

        return $query->offset($offset)->action('select')->columns('*')->query();
    }

    public function prepare_items()
    {
        $query = $this->parse_query();

        $items_per_page = $this->get_items_per_page('edit_wpopt_logs_per_page', 50);
        $this->_column_headers = array($this->get_columns(), $this->get_hidden_columns(), $this->get_sortable_columns());

        $offset = ($this->get_pagenum() - 1) * $items_per_page;

        $total_items = $query->action('select')->columns('COUNT(*)')->query(true);

        $this->items = $query->limit($items_per_page)->offset($offset)->action('select')->columns('*')->recompile()->query();

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