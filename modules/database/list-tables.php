<?php

class WO_DB_Tables_List extends WP_List_Table
{
    private static $_instance;
    /**
     * @var mixed|string|void
     */
    private $response_message;
    /**
     * @var mixed|string
     */
    private $response_type;
    /**
     * @var int
     */
    private $total_lost = 0;
    /**
     * @var mixed
     */
    private $per_page = 25;

    /** Class constructor */
    public function __construct()
    {
        $this->response_type = 'updated';

        parent::__construct([
            'singular' => __('Table', 'sp'),
            'plural'   => __('Tables', 'sp'),
            'ajax'     => false
        ]);
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
                return "<span style='font-weight:bold;'>" .  $item[$column_name] . "</span>" ;
                break;
            case 'data_length':
            case 'data_free':
                return wpopt_bytes2size($item[$column_name]);
                break;
            case 'status':
            case 'engine':
            case 'table_rows':
            case 'site_id':
            case 'table_belongs_to':
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
            'delete'   => __('Delete', 'wpopt')
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

        $per_page = $this->get_items_per_page('tables_per_page', $this->per_page);
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
    function get_columns()
    {
        return array(
            'cb'               => '<input type="checkbox" />',
            'site_id'          => __('Site', 'wpopt'),
            'table_name'       => __('Table name', 'wpopt'),
            'table_rows'       => __('Rows', 'wpopt'),
            'data_length'      => __('Size', 'wpopt'),
            'data_free'        => __('Space lost', 'wpopt'),
            'status'           => __('Status', 'wpopt'),
            'engine'           => __('Table engine', 'wpopt'),
            'table_belongs_to' => __('Belongs to', 'wpopt')
        );
    }

    /** WP: Get columns that should be hidden */
    function get_hidden_columns()
    {
        // If MU, nothing to hide, else hide Side ID column
        if (function_exists('is_multisite') && is_multisite()) {
            return array();
        }
        else {
            return array('site_id');
        }
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
        if (!$this->current_action())
            return;

        // security check!
        if (isset($_POST['_wpnonce']) and !empty($_POST['_wpnonce'])) {

            $nonce = filter_input(INPUT_POST, '_wpnonce', FILTER_SANITIZE_STRING);

            if (!wp_verify_nonce($nonce, 'bulk-' . $this->_args['plural']))
                wp_die('Security check failed!');
        }

        $tables = filter_input(INPUT_POST, 'bulk-tables', FILTER_SANITIZE_STRING, FILTER_REQUIRE_ARRAY);

        if (!$tables)
            return;

        switch ($this->current_action()) {

            case 'delete':

                WOPerformer::manage_tables('delete', $tables);
                $this->response_message = __('Selected tables cleaned successfully!', 'wpopt');

                break;

            case 'optimize':

                WOPerformer::manage_tables('optimize', $tables);
                $this->response_message = __('Selected tables optimized successfully!', 'wpopt');

                break;
            case 'empty':

                WOPerformer::manage_tables('empty', $tables);
                $this->response_message = __('Selected tables emptied successfully!', 'wpopt');

                break;

            case 'repair':

                if (WOPerformer::manage_tables('repair', $tables)) {
                    $this->response_message = __('Selected tables repaired successfully!', 'wpopt');
                }
                else {
                    $this->response_type = "error";
                    $this->response_message = __('Some of your tables cannot be repaired!', 'wpopt');
                }

                break;
        }
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
     * @return mixed
     */
    public static function get_tables_data($per_page = 20, $current_page = 1)
    {
        global $wpdb;

        $sql = "SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, DATA_FREE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '" . DB_NAME . "'";

        if (!empty($_GET['orderby'])) {

            $order_by = esc_sql($_GET['orderby']);

            if(in_array($order_by, array("table_name", "table_rows", "data_length", "data_free", "engine", "site_id")))
            {
                $order = empty($_GET['order']) ? "ASC" : esc_sql($_GET['order']);

                $sql .= " ORDER BY {$order_by} {$order}";
            }

        }

        $sql .= " LIMIT $per_page";
        $sql .= ' OFFSET ' . ($current_page - 1) * $per_page;

        $items_to_display = array();

        $plugin_name = __('Uncategorized', 'wpopt');
        //__('Belongs to', 'wpopt')

        foreach ((array)$wpdb->get_results($sql, ARRAY_A) as $table) {

            $status = __('Ok', 'wpopt');

            if ($table['DATA_FREE'] > 0) {
                self::getInstance()->total_lost += $table['DATA_FREE'];
                $status = __('Optimize', 'wpopt');
            }

            $query_result = $wpdb->get_results("CHECK TABLE " . $table['TABLE_NAME'] . " FAST");
            foreach ((array)$query_result as $row) {
                if ($row->Msg_type == 'error') {
                    if (preg_match('/corrupt/i', $row->Msg_text)) {
                        $status = __('Repair', 'wpopt');
                        break;
                    }
                }
            }

            $site_name = '';

            if (is_multisite()) {
                $res = explode('_', $table['TABLE_NAME']);

                if (is_numeric($res[2])) {

                    $blog_id = $res[2];
                    $site_name = get_blog_details(array('blog_id' => $blog_id))->blogname;
                }
            }

            $items_to_display[] = array(
                'table_name'       => $table['TABLE_NAME'],
                'table_rows'       => $table['TABLE_ROWS'],
                'data_length'      => $table['DATA_LENGTH'],
                'data_free'        => $table['DATA_FREE'],
                'status'           => $status,
                'engine'           => $table['ENGINE'],
                'site_id'          => $site_name,
                'table_belongs_to' => $plugin_name
            );
        }

        // Sort items if necessary
        /*if (!empty($_GET['orderby'])) {
            $order_by = esc_sql($_GET['orderby']);
            $order = empty($_GET['order']) ? "asc" : esc_sql($_GET['order']);

            $elements = array();
            foreach ($items_to_display as $items) {
                $elements[] = $items[$order_by];
            }

            if (in_array($order_by, array("table_size", "table_rows", "data_free", "site_id"))) {
                if ($order == "asc") {
                    array_multisort($elements, SORT_ASC, $items_to_display, SORT_NUMERIC);
                }
                else {
                    array_multisort($elements, SORT_DESC, $items_to_display, SORT_NUMERIC);
                }
            }
            else {
                if ($order == "asc") {
                    array_multisort($elements, SORT_ASC, $items_to_display, SORT_REGULAR);
                }
                else {
                    array_multisort($elements, SORT_DESC, $items_to_display, SORT_REGULAR);
                }
            }
        }*/

        return $items_to_display;
    }

    public static function getInstance()
    {
        if (!isset(self::$_instance)) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

}

