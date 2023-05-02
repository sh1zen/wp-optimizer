<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

use SHZN\core\UtilEnv;

/**
 * Core class used to implement displaying media items in a list table.
 *
 * @since 1.5.0
 *
 * @see \WP_List_Table
 */
class Media_Table extends \WP_List_Table
{
    private int $total_count = 0;

    /**
     * Constructor.
     *
     * @param array $args An associative array of arguments.
     * @see \WP_List_Table::__construct() for more information on default arguments.
     *
     */
    public function __construct($args = array())
    {
        $this->modes = array(
            'list' => __('List view', 'wpopt'),
        );

        parent::__construct(
            array(
                'singular' => 'media',
                'plural'   => 'media',
                'ajax'     => false,
                'screen'   => $args['screen'] ?? null,
            )
        );

    }

    public static function process_bulk_action()
    {
        if (isset($_REQUEST['filter_action']) && !empty($_REQUEST['filter_action'])) {
            return false;
        }

        if (!isset($_POST['_wpnonce']) or !isset($_REQUEST['action'])) {
            return false;
        }

        $action = $_REQUEST['action'];

        if (in_array($action, ['delete', 'ignore'])) {

            if (!wp_verify_nonce($_POST['_wpnonce'], 'bulk-media')) {
                wp_die('Security check failed!');
            }

            foreach ($_POST['bulk-media'] as $media) {
                ImagesProcessor::remove(absint($media), $action === 'delete');
            }

            return true;
        }

        return false;
    }

    /**
     * @return bool
     */
    public function ajax_user_can()
    {
        return current_user_can('upload_files');
    }

    public function set_items($items)
    {
        $this->items = array_slice($items, ($this->get_pagenum() - 1) * $this->get_per_page(), $this->get_per_page());
        $this->total_count = count($items);
    }

    private function get_per_page()
    {
        return 50;
    }

    /**
     * @global string $mode List table view mode.
     * @global \WP_Query $wp_query WordPress Query object.
     * @global array $post_mime_types
     * @global array $avail_post_mime_types
     */
    public function prepare_items()
    {
        $columns = $this->get_columns();
        $hidden = $this->get_hidden_columns();
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = array($columns, $hidden, $sortable);

        $this->set_pagination_args(array(
            'total_items' => $this->total_count,
            'per_page'    => $this->get_per_page()
        ));
    }

    /**
     * @return array
     */
    public function get_columns()
    {
        return array(
            'cb'    => '<input type="checkbox" />',
            'image' => __('Image', 'wpopt'),
            'path' => __('Path', 'wpopt'),
            'size'  => __('Size', 'wpopt'),
            'cdate' => __('Last Modify Date', 'wpopt')
        );
    }

    /** WP: Get columns that should be hidden */
    function get_hidden_columns()
    {
        return array();
    }

    /**
     * @return array
     */
    protected function get_sortable_columns()
    {
        return array(
            'name'  => 'name',
            'size'  => 'size',
            'cdate' => 'cdate',
        );
    }

    /**
     */
    public function no_items()
    {
        _e('No media files found.', 'wpopt');
    }

    /**
     * Handles the checkbox column output.
     * @param $item
     * @return string
     */
    public function column_cb($item)
    {
        return sprintf('<input type="checkbox" name="bulk-media[]" value="%s" />', $item['id']);
    }

    /**
     * Handles output for the default column.
     *
     * @param $item
     * @param string $column_name Current column name.
     * @return bool|string
     */
    public function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'name':
                return "<span style='font-weight:bold;'>" . basename($item['obj_id']) . "</span>";
            case 'path':
                return "<span style='font-weight:bold;'>" . pathinfo($item['obj_id'], PATHINFO_DIRNAME) . "</span>";
            case 'size':
                return size_format(absint($item['value'][$column_name]));
            case 'cdate':
                return date("Y-m-d H:i:s", $item['value']['time']);
            default:
                return print_r($item, true);
        }

    }

    public function column_image($item)
    {
        ?>
        <strong class='has-media-icon'>
        <span class='media-icon image-icon'>
                <img width='48' height='64' src='<?php echo UtilEnv::path_to_url($item['obj_id'], true); ?>'
                     class='attachment-60x60 size-60x60' loading='lazy'>
            </span>
        <p class="filename">
            <span class="screen-reader-text"><?php _e('File name:'); ?> </span>
            <?php
            echo esc_html(wp_basename($item['obj_id']));
            ?>
        </p>
        <?php
    }

    /**
     * @return array
     */
    protected function get_bulk_actions()
    {
        return array(
            'delete' => __('Delete', 'wpopt'),
            'ignore' => __('Ignore', 'wpopt')
        );
    }

    /**
     * Generates and displays row action links.
     *
     * @param $item
     * @param string $column_name Current column name.
     * @param string $primary Primary column name.
     * @return string Row actions output for media attachments, or an empty string
     *                if the current column is not the primary column.
     */
    protected function handle_row_actions($item, $column_name, $primary)
    {
        if ($primary !== $column_name) {
            return '';
        }

        return $this->row_actions($this->_get_row_actions($item));
    }

    /**
     * @param $item
     * @return array
     */
    private function _get_row_actions($item)
    {
        return [
            'view' => sprintf(
                '<a href="%s" target="_blank">%s</a>',
                UtilEnv::path_to_url($item['obj_id'], true),
                __('View')
            )
        ];
    }
}
