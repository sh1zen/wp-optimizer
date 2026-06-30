<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class List_Table extends \WP_List_Table
{
    public static function generateHTML_table(array $args): string
    {
        $columns = $args['columns'] ?? array();
        $rows = $args['rows'] ?? array();
        $footer_rows = $args['footer_rows'] ?? array();

        if (!$columns) {
            return '';
        }

        $table_classes = array('widefat', 'wp-list-table', 'wps-table');

        foreach (preg_split('/\s+/', (string)($args['class'] ?? '')) as $class) {
            $class = sanitize_html_class($class);

            if ($class) {
                $table_classes[] = $class;
            }
        }

        $orderby = sanitize_key((string)($args['orderby'] ?? ''));
        $order = strtoupper(sanitize_key((string)($args['order'] ?? 'ASC')));

        if (!in_array($order, array('ASC', 'DESC'), true)) {
            $order = 'ASC';
        }

        $sort_url = (string)($args['sort_url'] ?? '');
        $tbody_attributes = self::render_table_attributes($args['tbody_attributes'] ?? array());
        $show_empty = array_key_exists('show_empty', $args) ? (bool)$args['show_empty'] : true;

        ob_start();
        ?>
        <table class="<?php echo esc_attr(implode(' ', array_unique($table_classes))); ?>">
            <thead>
            <tr>
                <?php foreach ($columns as $column_key => $column) : ?>
                    <?php
                    $column_key = sanitize_key((string)$column_key);
                    $column = is_array($column) ? $column : array('label' => (string)$column);
                    $label = (string)($column['label'] ?? $column_key);
                    $classes = array('column-' . $column_key);

                    if (!empty($column['class'])) {
                        foreach (preg_split('/\s+/', (string)$column['class']) as $class) {
                            $class = sanitize_html_class($class);

                            if ($class) {
                                $classes[] = $class;
                            }
                        }
                    }

                    if (!empty($column['sortable'])) {
                        $classes[] = 'is-sortable';
                    }

                    if ($orderby === $column_key) {
                        $classes[] = 'is-sorted';
                        $classes[] = strtolower($order);
                    }
                    ?>
                    <th scope="col" class="<?php echo esc_attr(implode(' ', array_unique($classes))); ?>">
                        <?php echo self::render_table_header_label($label, $column_key, $column, $sort_url, $orderby, $order); ?>
                    </th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody<?php echo $tbody_attributes; ?>>
            <?php if (!$rows) : ?>
                <?php if ($show_empty) : ?>
                    <tr class="no-items">
                        <td colspan="<?php echo esc_attr((string)count($columns)); ?>"><?php echo esc_html((string)($args['empty'] ?? __('No items found.', 'wps'))); ?></td>
                    </tr>
                <?php endif; ?>
            <?php else : ?>
                <?php foreach ($rows as $row) : ?>
                    <?php
                    $row_classes = array();
                    $cells = $row;

                    if (isset($row['cells']) && is_array($row['cells'])) {
                        $cells = $row['cells'];

                        foreach (preg_split('/\s+/', (string)($row['class'] ?? '')) as $class) {
                            $class = sanitize_html_class($class);

                            if ($class) {
                                $row_classes[] = $class;
                            }
                        }
                    }
                    ?>
                    <tr class="<?php echo esc_attr(implode(' ', array_unique($row_classes))); ?>">
                        <?php $skip_columns = 0; ?>
                        <?php foreach ($columns as $column_key => $column) : ?>
                            <?php
                            if ($skip_columns > 0) {
                                $skip_columns--;
                                continue;
                            }

                            $column_key = sanitize_key((string)$column_key);
                            $column = is_array($column) ? $column : array('label' => (string)$column);
                            $cell = $cells[$column_key] ?? '';
                            $cell_content = is_array($cell) ? (string)($cell['content'] ?? '') : (string)$cell;
                            $cell_label = (string)($column['label'] ?? $column_key);
                            $cell_classes = array('column-' . $column_key);
                            $cell_attributes = is_array($cell) && isset($cell['attributes']) && is_array($cell['attributes']) ? $cell['attributes'] : array();

                            if (!empty($column['class'])) {
                                foreach (preg_split('/\s+/', (string)$column['class']) as $class) {
                                    $class = sanitize_html_class($class);

                                    if ($class) {
                                        $cell_classes[] = $class;
                                    }
                                }
                            }

                            if (is_array($cell) && !empty($cell['class'])) {
                                foreach (preg_split('/\s+/', (string)$cell['class']) as $class) {
                                    $class = sanitize_html_class($class);

                                    if ($class) {
                                        $cell_classes[] = $class;
                                    }
                                }
                            }

                            $colspan = 1;

                            if (isset($cell_attributes['colspan'])) {
                                $colspan = max(1, (int)$cell_attributes['colspan']);
                                $cell_attributes['colspan'] = $colspan;
                            }

                            $skip_columns = $colspan - 1;
                            ?>
                            <td class="<?php echo esc_attr(implode(' ', array_unique($cell_classes))); ?>" data-label="<?php echo esc_attr($cell_label); ?>" data-colname="<?php echo esc_attr($cell_label); ?>"<?php echo self::render_table_attributes($cell_attributes); ?>>
                                <?php echo $cell_content; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            <?php if ($footer_rows) : ?>
                <tfoot>
                <?php foreach ($footer_rows as $row) : ?>
                    <?php
                    $row_classes = array();
                    $cells = $row;

                    if (isset($row['cells']) && is_array($row['cells'])) {
                        $cells = $row['cells'];

                        foreach (preg_split('/\s+/', (string)($row['class'] ?? '')) as $class) {
                            $class = sanitize_html_class($class);

                            if ($class) {
                                $row_classes[] = $class;
                            }
                        }
                    }
                    ?>
                    <tr class="<?php echo esc_attr(implode(' ', array_unique($row_classes))); ?>">
                        <?php $skip_columns = 0; ?>
                        <?php foreach ($columns as $column_key => $column) : ?>
                            <?php
                            if ($skip_columns > 0) {
                                $skip_columns--;
                                continue;
                            }

                            $column_key = sanitize_key((string)$column_key);
                            $column = is_array($column) ? $column : array('label' => (string)$column);
                            $cell = $cells[$column_key] ?? '';
                            $cell_content = is_array($cell) ? (string)($cell['content'] ?? '') : (string)$cell;
                            $cell_label = (string)($column['label'] ?? $column_key);
                            $cell_classes = array('column-' . $column_key);
                            $cell_attributes = is_array($cell) && isset($cell['attributes']) && is_array($cell['attributes']) ? $cell['attributes'] : array();

                            if (!empty($column['class'])) {
                                foreach (preg_split('/\s+/', (string)$column['class']) as $class) {
                                    $class = sanitize_html_class($class);

                                    if ($class) {
                                        $cell_classes[] = $class;
                                    }
                                }
                            }

                            if (is_array($cell) && !empty($cell['class'])) {
                                foreach (preg_split('/\s+/', (string)$cell['class']) as $class) {
                                    $class = sanitize_html_class($class);

                                    if ($class) {
                                        $cell_classes[] = $class;
                                    }
                                }
                            }

                            $colspan = 1;

                            if (isset($cell_attributes['colspan'])) {
                                $colspan = max(1, (int)$cell_attributes['colspan']);
                                $cell_attributes['colspan'] = $colspan;
                            }

                            $skip_columns = $colspan - 1;
                            ?>
                            <td class="<?php echo esc_attr(implode(' ', array_unique($cell_classes))); ?>" data-label="<?php echo esc_attr($cell_label); ?>" data-colname="<?php echo esc_attr($cell_label); ?>"<?php echo self::render_table_attributes($cell_attributes); ?>>
                                <?php echo $cell_content; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tfoot>
            <?php endif; ?>
        </table>
        <?php
        return ob_get_clean();
    }

    private static function render_table_attributes($attributes): string
    {
        if (!is_array($attributes) || empty($attributes)) {
            return '';
        }

        $output = '';

        foreach ($attributes as $name => $value) {
            $name = preg_replace('/[^a-zA-Z0-9_:-]/', '', (string)$name);

            if ($name === '') {
                continue;
            }

            if ($value === true || $value === '') {
                $output .= ' ' . esc_attr($name);
                continue;
            }

            if ($value === false || $value === null) {
                continue;
            }

            $output .= ' ' . esc_attr($name) . '="' . esc_attr((string)$value) . '"';
        }

        return $output;
    }

    private static function render_table_header_label(string $label, string $column_key, array $column, string $sort_url, string $orderby, string $order): string
    {
        if (empty($column['sortable']) || '' === $sort_url) {
            return '<span>' . esc_html($label) . '</span>';
        }

        $next_order = ($orderby === $column_key && 'ASC' === $order) ? 'DESC' : 'ASC';
        $url = add_query_arg(array(
                'orderby' => $column_key,
                'order'   => $next_order,
        ), $sort_url);
        $url = remove_query_arg('paged', $url);

        $indicator = $orderby === $column_key ? strtolower($order) : 'none';

        return sprintf(
                '<a href="%1$s" data-wps-table-sort="%2$s"><span>%3$s</span><span class="wps-table-sort %4$s" aria-hidden="true"></span></a>',
                esc_url($url),
                esc_attr($column_key),
                esc_html($label),
                esc_attr($indicator)
        );
    }

    public function display()
    {
        $this->display_wps_tablenav('top');

        if ($this->screen && method_exists($this->screen, 'render_screen_reader_content')) {
            $this->screen->render_screen_reader_content('heading_list');
        }

        echo self::generateHTML_table(array(
                'class'    => 'wps-list-table',
                'columns'  => $this->get_wps_table_columns(),
                'rows'     => $this->get_wps_table_rows(),
                'empty'    => $this->get_wps_no_items_text(),
                'sort_url' => $this->get_wps_sort_url(),
                'orderby'  => $this->get_wps_orderby(),
                'order'    => $this->get_wps_order(),
        ));

        $this->display_wps_tablenav('bottom');
    }

    protected function display_wps_tablenav(string $which): void
    {
        if ('top' === $which) {
            wp_nonce_field('bulk-' . $this->_args['plural']);
        }
        ?>
        <div class="tablenav <?php echo esc_attr($which); ?>">
            <div class="wps-table-controls">
                <?php if ($this->has_items() && !empty($this->get_bulk_actions())) : ?>
                    <div class="alignleft actions bulkactions">
                        <?php $this->bulk_actions($which); ?>
                    </div>
                <?php endif; ?>
                <?php $this->extra_tablenav($which); ?>
            </div>
            <?php if ('top' === $which && $this->has_wps_search_box()) : ?>
                <?php $this->search_box($this->get_wps_search_label(), $this->get_wps_search_input_id()); ?>
            <?php endif; ?>
            <?php $this->pagination($which); ?>
            <br class="clear"/>
        </div>
        <?php
    }

    protected function has_wps_search_box(): bool
    {
        return true;
    }

    protected function get_wps_search_label(): string
    {
        return __('Search');
    }

    protected function get_wps_search_input_id(): string
    {
        $singular = isset($this->_args['singular']) ? sanitize_key((string)$this->_args['singular']) : 'wps-list';

        return ($singular ?: 'wps-list') . '-search';
    }

    protected function pagination($which)
    {
        if (empty($this->_pagination_args)) {
            return;
        }

        $total_items = (int)($this->_pagination_args['total_items'] ?? 0);
        $total_pages = (int)($this->_pagination_args['total_pages'] ?? 0);

        if ($total_items <= 0 || $total_pages <= 1) {
            return;
        }

        $current = max(1, min((int)$this->get_pagenum(), $total_pages));
        $base_url = remove_query_arg('paged', $_SERVER['REQUEST_URI'] ?? '');

        $page_url = static function (int $page) use ($base_url): string {
            return esc_url(add_query_arg('paged', max(1, $page), $base_url));
        };

        $nav_button = static function (string $class, string $label, string $symbol, ?string $url): string {
            if ($url === null) {
                return '';
            }

            return '<a class="' . esc_attr($class) . ' button" href="' . $url . '"><span class="screen-reader-text">' . esc_html($label) . '</span><span aria-hidden="true">' . esc_html($symbol) . '</span></a>';
        };

        $first_url = $current > 1 ? $page_url(1) : null;
        $prev_url = $current > 1 ? $page_url($current - 1) : null;
        $next_url = $current < $total_pages ? $page_url($current + 1) : null;
        $last_url = $current < $total_pages ? $page_url($total_pages) : null;
        ?>
        <div class="tablenav-pages <?php echo esc_attr((string)$which); ?>">
            <span class="displaying-num"><?php echo esc_html(sprintf(_n('%s item', '%s items', $total_items), number_format_i18n($total_items))); ?></span>
            <span class="pagination-links">
                <?php echo $nav_button('first-page', __('First page'), '<<', $first_url); ?>
                <?php echo $nav_button('prev-page', __('Previous page'), '<', $prev_url); ?>
                <span class="paging-input wps-pagination-status" aria-label="<?php echo esc_attr(sprintf(__('Page %1$s of %2$s'), number_format_i18n($current), number_format_i18n($total_pages))); ?>">
                    <span class="current-page" aria-current="page"><?php echo esc_html(number_format_i18n($current)); ?></span>
                    <span class="wps-page-separator" aria-hidden="true">...</span>
                    <span class="wps-total-pages"><?php echo esc_html(number_format_i18n($total_pages)); ?></span>
                </span>
                <?php echo $nav_button('next-page', __('Next page'), '>', $next_url); ?>
                <?php echo $nav_button('last-page', __('Last page'), '>>', $last_url); ?>
            </span>
        </div>
        <?php
    }

    protected function get_wps_table_columns(): array
    {
        list($columns, $hidden, $sortable) = $this->get_column_info();
        $hidden = array_flip((array)$hidden);
        $rendered = array();

        foreach ((array)$columns as $key => $label) {
            if (isset($hidden[$key])) {
                continue;
            }

            $rendered[$key] = array(
                    'label'    => 'cb' === $key ? '' : wp_strip_all_tags((string)$label),
                    'sortable' => isset($sortable[$key]),
                    'class'    => 'cb' === $key ? 'check-column' : '',
            );
        }

        return $rendered;
    }

    protected function get_wps_table_rows(): array
    {
        list($columns, $hidden) = $this->get_column_info();
        $hidden = array_flip((array)$hidden);
        $rows = array();

        foreach ((array)$this->items as $item) {
            $cells = array();

            foreach ((array)$columns as $column_name => $label) {
                if (isset($hidden[$column_name])) {
                    continue;
                }

                $cells[$column_name] = $this->get_wps_column_content($item, (string)$column_name);
            }

            $rows[] = $cells;
        }

        return $rows;
    }

    protected function get_wps_column_content($item, string $column_name): string
    {
        if ('cb' === $column_name && method_exists($this, 'column_cb')) {
            return (string)$this->column_cb($item);
        }

        $method = 'column_' . $column_name;

        if (method_exists($this, $method)) {
            return (string)$this->{$method}($item);
        }

        if (method_exists($this, 'column_default')) {
            return (string)$this->column_default($item, $column_name);
        }

        return '';
    }

    protected function get_wps_no_items_text(): string
    {
        ob_start();
        $this->no_items();
        return trim((string)ob_get_clean());
    }

    protected function get_wps_sort_url(): string
    {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';

        if ('' === $request_uri) {
            return '';
        }

        return remove_query_arg(array('orderby', 'order', 'paged'), $request_uri);
    }

    protected function get_wps_orderby(): string
    {
        return isset($_REQUEST['orderby']) && is_scalar($_REQUEST['orderby']) ? sanitize_key((string)wp_unslash($_REQUEST['orderby'])) : '';
    }

    protected function get_wps_order(): string
    {
        $order = isset($_REQUEST['order']) && is_scalar($_REQUEST['order']) ? strtoupper(sanitize_key((string)wp_unslash($_REQUEST['order']))) : 'ASC';

        return in_array($order, array('ASC', 'DESC'), true) ? $order : 'ASC';
    }
}
