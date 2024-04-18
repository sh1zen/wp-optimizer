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

use WPS\core\UtilEnv;

/**
 * Core class used to display media items in a list table.
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

        add_screen_option(
            'per_page',
            array(
                'default' => 50,
                'label'   => __('Images per page', 'wpopt'),
                'option'  => 'media_cleaner_images_per_page',
            )
        );

        add_filter('set-screen-option', array($this, 'set_screen_option'), 10, 3);
    }

    public function set_screen_option($status, $option, $value)
    {
        if ('media_cleaner_images_per_page' === $option)
            return $value;

        return $status;
    }

    public static function process_bulk_action()
    {
        if (!empty($_REQUEST['filter_action'])) {
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

    public function set_items($items)
    {
        $this->items = array_slice($items, ($this->get_pagenum() - 1) * $this->get_items_per_page('media_cleaner_images_per_page', 50), $this->get_items_per_page('media_cleaner_images_per_page', 50));
        $this->total_count = count($items);
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

        $this->_column_headers = array($columns, [], []);

        $this->set_pagination_args(array(
            'total_items' => $this->total_count,
            'per_page'    => $this->get_items_per_page('media_cleaner_images_per_page', 50)
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
            'path'  => __('Path', 'wpopt'),
            'size'  => __('Size', 'wpopt'),
            'cdate' => __('Last Modify Date', 'wpopt')
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
                return wp_date("Y-m-d H:i:s", $item['value']['time']);
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
