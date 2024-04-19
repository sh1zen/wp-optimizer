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
use WPS\core\StringHelper;
use WPS\core\UtilEnv;

/**
 * Core class used to display activity logs.
 *
 * @see \WP_List_Table
 */
class WPMails extends \WP_List_Table
{
    private string $action_hook;

    public function __construct($args = array())
    {
        $this->modes = array(
            'list' => __('List view', 'wpopt'),
        );

        //$this->settings = $args['settings'];
        $this->action_hook = $args['action_hook'] ?? '';

        parent::__construct(
            array(
                'singular' => __('mail', 'wpopt'),
                'plural'   => __('mails', 'wpopt'),
                'ajax'     => false,
                'screen'   => get_current_screen(),
            )
        );
    }

    public function display_tablenav($which)
    {
        if ('top' == $which) {
            $this->search_box(__('Search', 'wpopt'), 'wpopt-mailLog');
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

        $filters = array(
            'filter_date',
            'filter_to_email'
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

    private function get_filtered_link($name = '', $value = ''): string
    {
        $base_page_url = menu_page_url('wpopt-wp_mail', false);

        if (empty($name)) {
            return $base_page_url;
        }

        return add_query_arg($name, $value, $base_page_url);
    }

    public function column_default($item, $column_name)
    {
        switch ($column_name) {

            case 'to_email':
                $return = "<a href='" . $this->get_filtered_link('filter_to_email', $item->to_email) . "'>" . sanitize_email($item->to_email) . "</a>";
                break;

            case 'subject':
            case 'message':
                $return = "<span>" . StringHelper::sanitize_text($item->$column_name, true) . "</span>";
                break;

            case 'headers':
            case 'attachments_file':
                $return = "<span>" . var_export(explode(',', $item->$column_name), true) . "</span>";
                break;

            case 'sent_date':

                $timestamp = strtotime($item->$column_name);

                $return = sprintf('<strong>' . __('%s ago', 'wpopt') . '</strong>', human_time_diff($timestamp, wps_time()));

                $return .= '<br/><a href="' . $this->get_filtered_link('filter_date', $item->$column_name) . '">' . wps_time(get_option('date_format'), 0, false, $timestamp) . '</a>';

                $return .= '<br/>' . wps_time(get_option('time_format'), 0, false, $timestamp);
                break;

            default:
                $return = '<span>' . esc_html($item->$column_name ?? 'N/B') . '</span>';
        }

        return $return;
    }

    public function get_items($use_limit = false)
    {
        // get requested order and other filters from _wp_http_referer
        parse_str(parse_url($_REQUEST['_wp_http_referer'] ?? '', PHP_URL_QUERY), $request);

        $query = $this->parse_query($request)->output(ARRAY_A);

        $items_per_page = $this->get_items_per_page('edit_wpopt_mails_log_per_page', 50);

        $offset = ($this->get_pagenum() - 1) * $items_per_page;

        if ($use_limit) {
            $query->limit($items_per_page);
        }

        return $query->offset($offset)->action('select')->columns('*')->query();
    }

    private function parse_query($request = ''): Query
    {
        if (empty($request)) {
            $request = $_REQUEST;
        }

        $query = Query::getInstance();
        $query->tables(WPOPT_TABLE_LOG_MAILS);

        if (!isset($request['orderby']) or !in_array($request['orderby'], array('id', 'sent_date', 'to_email', 'sent_date_gmt'))) {
            $request['orderby'] = 'id';
        }

        $query->orderby($request['orderby'], $request['order'] ?? 'DESC', WPOPT_TABLE_LOG_MAILS);

        if (!empty($request['filter_to_email'])) {

            $query->where(['to_email' => sanitize_text_field($request['filter_to_email'])]);
        }

        if (!empty($request['filter_date'])) {

            list($start_time, $end_time) = UtilEnv::epochs_timestamp($request['filter_date']);

            if (!empty($start_time) && !empty($end_time)) {

                $query->where([
                    ['sent_date' => $start_time, 'compare' => '>'],
                    ['sent_date' => $end_time, 'compare' => '<'],
                ]);
            }
        }

        if (!empty($request['s'])) {
            $query->where(
                [
                    ['message' => $request['s'], 'compare' => 'LIKE'],
                    ['subject' => $request['s'], 'compare' => 'LIKE'],
                    ['to_email' => $request['s'], 'compare' => 'LIKE']
                ],
                'OR'
            );
        }

        return $query;
    }

    protected function get_items_per_page($option, $default_value = 20)
    {
        return 30;
    }

    public function prepare_items()
    {
        $query = $this->parse_query();

        $items_per_page = $this->get_items_per_page('edit_wpopt_mails_log_per_page', 50);

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
            'id'          => __('N', 'wpopt'),
            'to_email'    => __('To', 'wpopt'),
            'subject'     => __('Subject', 'wpopt'),
            'message'     => __('Message', 'wpopt'),
            'attachments' => __('Attachments', 'wpopt'),
            'sent_date'   => __('Date', 'wpopt'),
        );
    }

    protected function get_hidden_columns()
    {
        return [];
    }

    protected function get_sortable_columns()
    {
        return array(
            'id'        => array('id', 'desc'),
            'sent_date' => array('sent_date', 'asc'),
        );
    }

    public function no_items()
    {
        _e('No Mails found.', 'wpopt');
    }
}