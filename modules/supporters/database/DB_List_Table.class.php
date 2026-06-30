<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

use WPS\core\UtilEnv;

class DB_List_Table extends \WPS\core\List_Table
{
    /**
     * @var mixed|string|void
     */
    private $response_message;

    /**
     * @var mixed|string
     */
    private $response_type;

    /** Class constructor */
    public function __construct(string $pagination_base_url = '')
    {
        $this->response_type = 'updated';

        parent::__construct(array(
            'singular' => __('Table', 'wpopt'),
            'plural'   => __('Tables', 'wpopt'),
            'ajax'     => false
        ));
    }

    /** Text displayed when no customer data is available */
    public function no_items()
    {
        _e('No tables found.', 'wpopt');
    }

    /**
     * Render a column when no column specific method exist.
     *
     * @param array $item
     * @param string $column_name
     *
     * @return mixed
     */
    public function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'table_name':
                return "<span style='font-weight:bold;'>" . $item[$column_name] . "</span>";
            case 'data_length':
            case 'data_free':
                return size_format(absint($item[$column_name]));
            case 'status':
            case 'engine':
            case 'table_rows':
                return $item[$column_name];
            default:
                return print_r($item, true); //Show the whole array for troubleshooting purposes
        }
    }

    /**
     * Render the bulk edit checkbox
     *
     * @param array $item
     *
     * @return string
     */
    function column_cb($item)
    {
        return sprintf('<input type="checkbox" name="bulk-tables[]" value="%s" />', $item['table_name']);
    }

    /**
     * Returns an associative array containing the bulk action
     *
     * @return array
     */
    public function get_bulk_actions()
    {
        return array(
            'optimize' => __('Optimize', 'wpopt'),
            'repair'   => __('Repair', 'wpopt'),
            'empty'    => __('Empty rows', 'wpopt'),
            'delete'   => __('Delete', 'wpopt'),
            'innodb'   => __('Convert to InnoDB', 'wpopt'),
            'myisam'   => __('Convert to MyISAM', 'wpopt')
        );
    }

    /**
     * Handles data query and filter, sorting, and pagination.
     */
    public function prepare_items()
    {
        $columns = $this->get_columns();
        $hidden = $this->get_hidden_columns();
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = array($columns, $hidden, $sortable);

        /** Process bulk action */
        $this->process_bulk_action();

        $per_page = $this->get_items_per_page('tables_per_page', 25);
        $current_page = $this->get_pagenum();
        $total_items = self::record_count();

        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $per_page
        ));

        $this->items = self::get_tables_data($per_page, $current_page);

        return array($this->response_message, $this->response_type);
    }

    /**
     *  Associative array of columns
     *
     * @return array
     */
    public function get_columns()
    {
        return array(
            'cb'          => '<input type="checkbox" />',
            'table_name'  => __('Table name', 'wpopt'),
            'table_rows'  => __('Rows', 'wpopt'),
            'data_length' => __('Size', 'wpopt'),
            'data_free'   => __('Space lost', 'wpopt'),
            'status'      => __('Status', 'wpopt'),
            'engine'      => __('Table engine', 'wpopt'),
        );
    }

    /** WP: Get columns that should be hidden */
    function get_hidden_columns()
    {
        return [];
    }

    /**
     * Columns to make sortable.
     *
     * @return array
     */
    public function get_sortable_columns()
    {
        return array(
            'table_name'  => array('table_name', false),
            'table_rows'  => array('table_rows', false),
            'data_length' => array('data_length', false),
            'data_free'   => array('data_free', false),
            'engine'      => array('engine', false)
        );
    }

    public function process_bulk_action()
    {
        global $wpdb;

        if (!$this->current_action() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
            return;

        // security check!
        if (empty($_POST['_wpnonce']) || !UtilEnv::verify_nonce('bulk-' . $this->_args['plural'], $_POST['_wpnonce'])) {
            wp_die(esc_html__('Security check failed.', 'wpopt'));
        }

        wps('wpopt')->options->remove_all('cache', "get_tables_data");

        if (empty($_POST['bulk-tables'])) {
            $this->response_type = 'notice notice-warning';
            $this->response_message = __('Select at least one database table before applying a bulk action.', 'wpopt');

            return;
        }

        $tables = filter_input(INPUT_POST, 'bulk-tables', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);

        if (!$tables) {
            return;
        }

        $processed = DBSupport::manage_tables($this->current_action(), $tables);

        $action_translators = array(
            'optimize' => __('optimized', 'wpopt'),
            'repair'   => __('repaired', 'wpopt'),
            'empty'    => __('emptied', 'wpopt'),
            'delete'   => __('deleted', 'wpopt'),
            'innodb'   => __('converted to InnoDB', 'wpopt'),
            'myisam'   => __('converted to MyISAM', 'wpopt')
        );

        if ($processed != count($tables)) {
            $this->response_type = 'error';
        }

        $this->response_message = sprintf(
            __('%1$s / %2$s tables were %3$s successfully!', 'wpopt'),
            number_format_i18n($processed),
            number_format_i18n(count($tables)),
            $action_translators[$this->current_action()]
        );
    }

    /**
     * Returns the count of records in the database.
     *
     * @return null|string
     */
    public static function record_count()
    {
        global $wpdb;

        return $wpdb->get_var("SELECT count(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '" . DB_NAME . "'");
    }

    /**
     * Retrieve customers data from the database
     *
     * @param int $per_page
     * @param int $current_page
     *
     * @return array
     */
    public static function get_tables_data(int $per_page = 20, int $current_page = 1)
    {
        global $wpdb;

        if ($data = wps('wpopt')->options->get("{$per_page}.{$current_page}", "get_tables_data", "cache", false)) {
            return $data;
        }

        $sql = "SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, DATA_FREE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '" . DB_NAME . "'";

        if (!empty($_GET['orderby'])) {

            $order_by = esc_sql($_GET['orderby']);

            if (in_array($order_by, array("table_name", "table_rows", "data_length", "data_free", "engine"))) {
                $order = empty($_GET['order']) ? "ASC" : esc_sql($_GET['order']);

                $sql .= " ORDER BY {$order_by} {$order}";
            }
        }

        $sql .= " LIMIT $per_page";
        $sql .= ' OFFSET ' . ($current_page - 1) * $per_page;

        $items_to_display = array();

        foreach ((array)$wpdb->get_results($sql, ARRAY_A) as $table) {

            $status = __('Ok', 'wpopt');

            if (($table['DATA_FREE'] ?? 0) > 0) {
                $status = __('Optimize', 'wpopt');
            }

            $query_result = isset($table['TABLE_NAME']) ? $wpdb->get_results("CHECK TABLE " . $table['TABLE_NAME'] . " FAST") : [];

            foreach ((array)$query_result as $row) {
                if ($row->Msg_type == 'error') {
                    if (preg_match('/corrupt/i', $row->Msg_text)) {
                        $status = __('Repair', 'wpopt');
                        break;
                    }
                }
            }

            $items_to_display[] = array(
                'table_name'  => $table['TABLE_NAME'] ?? __('Table name N/A', 'wpopt'),
                'table_rows'  => $table['TABLE_ROWS'] ?? __('Rows N/A', 'wpopt'),
                'data_length' => $table['DATA_LENGTH'] ?? __('Length N/A', 'wpopt'),
                'data_free'   => $table['DATA_FREE'] ?? __('N/A', 'wpopt'),
                'status'      => $status,
                'engine'      => $table['ENGINE'] ?? __('Engine N/A', 'wpopt')
            );
        }

        wps('wpopt')->options->update("{$per_page}.{$current_page}", "get_tables_data", $items_to_display, 'cache', DAY_IN_SECONDS);

        return $items_to_display;
    }

    protected function extra_tablenav($which)
    {
    }
}

