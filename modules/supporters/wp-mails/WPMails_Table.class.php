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
        ?>
        <div class="tablenav <?php echo esc_attr($which); ?>">
            <?php
            $this->extra_tablenav($which);
            if ('top' == $which) {
                $this->search_box(__('Search', 'wpopt'), 'wpopt-mailLog');
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

        $filters = array(
            'filter_date',
            'filter_to_email'
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

    private function get_filtered_link($name = '', $value = ''): string
    {
        $base_page_url = menu_page_url('wpopt-wp_mail', false);

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

    private function render_array_value(string $value): string
    {
        $items = array_filter(array_map('trim', explode(',', $value)), static function ($item) {
            return '' !== $item;
        });

        return '<span>' . esc_html(var_export(array_values($items), true)) . '</span>';
    }

    private function format_message_text(string $message): string
    {
        if ('' === trim($message)) {
            return '';
        }

        $message = html_entity_decode($message, ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8');
        $message = preg_replace([
            '#<br\s*/?>#i',
            '#</(?:p|div|section|article|h[1-6]|table|ul|ol)>#i',
            '#<li[^>]*>#i',
            '#</li>#i',
            '#</td>#i',
            '#</tr>#i',
        ], [
            "\n",
            "\n\n",
            '• ',
            "\n",
            ' ',
            "\n",
        ], $message) ?: $message;

        $message = wp_strip_all_tags($message, false);
        $message = preg_replace("/\r\n|\r/", "\n", $message) ?: $message;
        $message = preg_replace("/[ \t]+\n/", "\n", $message) ?: $message;
        $message = preg_replace("/\n{3,}/", "\n\n", $message) ?: $message;

        return trim($message);
    }

    private function trim_message_preview(string $message, int $word_limit = 20): array
    {
        $single_line_message = preg_replace('/\s+/u', ' ', trim($message)) ?: trim($message);

        if ('' === $single_line_message) {
            return ['', false];
        }

        $words = preg_split('/\s+/u', $single_line_message, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($words) || count($words) <= $word_limit) {
            return [$single_line_message, false];
        }

        return [implode(' ', array_slice($words, 0, $word_limit)) . '...', true];
    }

    private function render_message_value($item): string
    {
        $message = $this->format_message_text((string)($item->message ?? ''));

        if ('' === $message) {
            return '<span>N/B</span>';
        }

        [$preview_text, $show_more] = $this->trim_message_preview($message, 20);
        $modal_id = 'wpopt-mail-message-' . absint((int)($item->id ?? 0));

        $output = '<div class="wpopt-mail-message-cell">';
        $output .= '<div class="wpopt-mail-message-preview">' . esc_html($preview_text) . '</div>';

        if ($show_more) {
            $output .= '<a href="#" class="wpopt-mail-message-more" data-mail-message-target="' . esc_attr($modal_id) . '">' . esc_html__('View more', 'wpopt') . '</a>';
            $output .= '<div id="' . esc_attr($modal_id) . '" class="hidden"><div class="wpopt-mail-message-modal-text">' . wpautop(esc_html($message), true) . '</div></div>';
        }

        $output .= '</div>';

        return $output;
    }

    public function column_default($item, $column_name)
    {
        switch ($column_name) {

            case 'to_email':
                $return = $this->render_filter_link('filter_to_email', $item->to_email, sanitize_email((string)$item->to_email));
                break;

            case 'subject':
                $return = '<span>' . esc_html(StringHelper::sanitize_text((string)$item->$column_name, true)) . '</span>';
                break;

            case 'message':
                $return = $this->render_message_value($item);
                break;

            case 'headers':
            case 'attachments':
            case 'attachments_file':
                $source_value = 'attachments' === $column_name ? (string)($item->attachments_file ?? '') : (string)$item->$column_name;
                $return = $this->render_array_value($source_value);
                break;

            case 'sent_date':

                $timestamp = strtotime($item->$column_name);

                $return = sprintf('<strong>' . __('%s ago', 'wpopt') . '</strong>', human_time_diff($timestamp, wps_time()));

                $return .= '<br/><a href="' . esc_url($this->get_filtered_link('filter_date', (string)$item->$column_name)) . '">' . esc_html(wps_time(get_option('date_format'), 0, false, $timestamp)) . '</a>';

                $return .= '<br/>' . esc_html(wps_time(get_option('time_format'), 0, false, $timestamp));
                break;

            default:
                $return = '<span>' . esc_html((string)($item->$column_name ?? 'N/B')) . '</span>';
        }

        return $return;
    }

    public function get_items($use_limit = false)
    {
        // get requested order and other filters from _wp_http_referer
        parse_str(parse_url($_REQUEST['_wp_http_referer'] ?? '', PHP_URL_QUERY) ?: '', $request);

        $search_term = $this->get_search_term($request);
        $use_php_search_filter = $this->uses_unsafe_like_subquery_pattern($search_term);

        $query = $this->parse_query($request)->output(ARRAY_A);

        $items_per_page = $this->get_items_per_page('edit_wpopt_mails_log_per_page', 50);

        $offset = ($this->get_pagenum() - 1) * $items_per_page;

        if ($use_php_search_filter) {
            // Keep subquery-shaped input out of the shared query builder.
            $items = $query->action('select')->columns('*')->query();
            $items = $this->filter_items_by_search($items, $search_term, ['message', 'subject', 'to_email']);

            return array_slice($items, $offset, $use_limit ? $items_per_page : null);
        }

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

        $search_term = $this->get_search_term($request);

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

        if ('' !== $search_term && !$this->uses_unsafe_like_subquery_pattern($search_term)) {
            $query->where(
                [
                    ['message' => $search_term, 'compare' => 'LIKE'],
                    ['subject' => $search_term, 'compare' => 'LIKE'],
                    ['to_email' => $search_term, 'compare' => 'LIKE']
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
        $search_term = $this->get_search_term($_REQUEST);
        $use_php_search_filter = $this->uses_unsafe_like_subquery_pattern($search_term);

        $items_per_page = $this->get_items_per_page('edit_wpopt_mails_log_per_page', 50);

        $this->_column_headers = array($this->get_columns(), $this->get_hidden_columns(), $this->get_sortable_columns());

        $offset = ($this->get_pagenum() - 1) * $items_per_page;

        if ($use_php_search_filter) {
            $items = $query->action('select')->columns('*')->query();
            $items = $this->filter_items_by_search($items, $search_term, ['message', 'subject', 'to_email']);
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


