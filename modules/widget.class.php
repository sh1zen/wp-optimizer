<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use WPS\core\Disk;
use WPS\core\List_Table;
use WPS\core\UtilEnv;
use WPS\modules\Module;

class Mod_Widget extends Module
{
    public static ?string $name = "Widget";

    public array $scopes = array('settings', 'admin');

    private array $paths = [];

    private array $cache = [];

    protected string $context = 'wpopt';

    public function cleanup(array $settings = array(), array $all_settings = array()): bool
    {
        wps('wpopt')->options->remove_all('cache', 'folder_size');

        return true;
    }

    protected function init(): void
    {
        $this->paths = array_filter($this->option('folder-size.paths', []));

        add_action("wp_dashboard_setup", array($this, 'dashboard_setup'));
    }

    /**
     * Hooked into `template_redirect`.  Adds the admin bar stick/unstick
     * button if we're on a single post page and the current user can edit
     * the post
     */
    public function dashboard_setup(): void
    {
        $hasWidget = false;

        if ($this->option('folder-size.active', false)) {

            foreach ($this->paths as $path) {

                wp_add_dashboard_widget($this->generate_id($path), basename($path) . " size", array($this, "wp_dashboard_foldersize"), array($this, 'widget_handle'), array('path' => $path));
            }

            $hasWidget = true;
        }

        if ($this->option('server-info.active', false)) {

            wp_add_dashboard_widget($this->generate_id('server-info'), 'Server info', array($this, "wp_dashboard_serverinfo"));

            $hasWidget = true;
        }

        if ($hasWidget) {
            add_action('admin_head', array($this, "head_style"));
        }
    }

    private function generate_id($path): string
    {
        $path = preg_replace('/[^a-z0-9-_.]/', '', strtolower($path));
        return "wpopt_" . substr($path, -8) . "_widget";
    }

    /**
     * Prints table styles in dashboard head
     */
    public function head_style(): void
    {
        echo '<style>
		#wpopt_folder_sizes .inside, #wpopt_root_sizes .inside {
			margin:0;padding:0
		} 
		.wpopt-dash-widget tbody tr:hover {
			background-color: #cde9ff
		} 
		.alternate{
			font-weight:bold
		}
		#wpopt_folder_sizes .dashboard-widget-control-form,
		#wpopt_root_sizes .dashboard-widget-control-form {
			padding: 5px 0 20px 20px;
		}';
        echo '</style>';
    }

    public function wp_dashboard_serverinfo(): void
    {
        global $wpdb;

        $serverInfo = [
            __('OS', 'wpopt')                      => PHP_OS,
            __('Server', 'wpopt')                  => $_SERVER["SERVER_SOFTWARE"],
            __('Hostname', 'wpopt')                => $_SERVER['SERVER_NAME'],
            __('IP:Port', 'wpopt')                 => wps_server_addr() . ':' . $_SERVER['SERVER_PORT'],
            __('Document Root', 'wpopt')           => $_SERVER['DOCUMENT_ROOT'],
            "PHP"                                  => '',
            __('version', 'wpopt')                 => PHP_VERSION,
            __('Memory Limit', 'wpopt')            => ini_get('memory_limit'),
            __('Max Script Execute Time', 'wpopt') => ini_get('max_execution_time'),
            __('Max Post Size', 'wpopt')           => ini_get('post_max_size'),
            __('Max Upload Size', 'wpopt')         => ini_get('upload_max_filesize'),
            "MYSQL"                                => '',
            __('version', 'wpopt')                 => $wpdb->db_version(),
            __('Data Disk Usage', 'wpopt')         => size_format($this->get_mysql_usages('data')),
            __('Index Disk Usage', 'wpopt')        => size_format($this->get_mysql_usages('index')),
        ];

        echo $this->render_dashboard_info_table($serverInfo);
        ?>
        <br>
        <div class="wps-row">
            <a class="wps wps-button wpopt-btn is-info"
               href="<?php echo admin_url('admin.php?page=sysinfo') ?>"><?php _e('View all', 'wpopt') ?></a>
        </div>
        <?php
    }

    private function get_mysql_usages($scope)
    {
        global $wpdb;

        $usage = 0;
        foreach ($wpdb->get_results("SHOW TABLE STATUS") as $tablestatus) {
            switch ($scope) {
                case  'data' :
                    $usage += $tablestatus->Data_length;
                    break;
                case  'index' :
                    $usage += $tablestatus->Index_length;
                    break;
            }
        }

        return $usage;
    }

    public function wp_dashboard_foldersize($var, $args = array()): void
    {
        $this->reset_folder_size_state();

        $path = $args['args']['path'] ?? ABSPATH;

        $path = UtilEnv::normalize_path($path);

        $this->cache['root_folder'] = size_format(Disk::calc_size($path));

        $directories = glob($path . '/*', \GLOB_ONLYDIR);
        echo $this->render_dashboard_folders_table(is_array($directories) ? $directories : array());
    }

    private function render_dashboard_info_table(array $serverInfo): string
    {
        $rows = array();

        foreach ($serverInfo as $info => $data) {
            $rows[] = array(
                'label' => '<b>' . esc_html($info) . '</b>',
                'value' => '<strong>' . esc_html($data) . '</strong>',
            );
        }

        return List_Table::generateHTML_table(array(
            'class' => 'wps wpopt-dash-widget',
            'columns' => array(
                'label' => __('Info', 'wpopt'),
                'value' => __('Value', 'wpopt'),
            ),
            'rows' => $rows,
            'empty' => __('No server information available.', 'wpopt'),
        ));
    }

    private function render_dashboard_folders_table(array $directories): string
    {
        $rows = $this->get_directory_rows($directories);
        $rows[] = array(
            'files' => '<strong>' . esc_html__('Total', 'wpopt') . '</strong>',
            'size' => '<strong>' . esc_html($this->cache['root_folder']) . '</strong>',
        );

        return List_Table::generateHTML_table(array(
            'class' => 'wpopt-dash-widget',
            'columns' => array(
                'files' => __('Files', 'wpopt'),
                'size' => __('size', 'wpopt'),
            ),
            'rows' => $rows,
            'empty' => __('No folder information available.', 'wpopt'),
        ));
    }

    private function reset_folder_size_state()
    {
        $this->cache = [];
    }

    /**
     * Prints the list of folders and its sizes
     *
     * @param array $directories List of folders inside a directory
     */
    private function get_directory_rows(array $directories): array
    {
        $rows = array();

        foreach ($directories as $dir) {
            $name = basename($dir);
            $size = size_format(Disk::calc_size($dir));
            $this->cache['dir_list'][$name] = $size;
            $rows[] = array(
                'files' => esc_html($name),
                'size' => esc_html($size),
            );
        }

        return $rows;
    }

    /**
     * Used for both Widgets configuration
     */
    public function widget_handle(): void
    {
        if (isset($_POST['wpopt_folder_sizes'])) {

            foreach ($this->paths as $path) {
                wps('wpopt')->options->remove(basename($path), 'folder_size', 'cache');
            }
        }
        $name = 'wpopt_folder_sizes';
        $cache_msg = __('Removes saved folder-size snapshots from older versions. Current sizes are calculated from disk each time.', 'wpopt');
        echo "<p><label><input name='$name' id='$name' type='checkbox' value='1' />" . __('Check to empty the saved snapshots', 'wpopt') . "</label><br /><em style='margin-left: 23px'>$cache_msg</em></p>";
    }

    protected function setting_fields($filter = ''): array
    {
        return $this->group_setting_fields(
            $this->setting_field(__('Installation size', 'wpopt'), "folder-size.active", "checkbox"),
            $this->setting_field(__('Paths (one per line)', 'wpopt'), "folder-size.paths", "textarea_array", ['parent' => "folder-size.active", 'value' => implode(PHP_EOL, $this->option('folder-size.paths', []))]),
            $this->setting_field(__('Server info', 'wpopt'), "server-info.active", "checkbox"),
        );
    }
}

return __NAMESPACE__;
