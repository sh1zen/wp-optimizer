<?php

class WOMod_Folder_Size extends WO_Module
{
    public static $name = "Directory Size";

    public $scopes = array('settings');

    /**
     * Transient time 8 hours
     *
     * @since   1.0
     */
    private $time = 28800;

    /**
     * Transient prefix
     *
     * @since   1.0
     */
    private $transient_prefix = 'wpopt_folder_sizes_';

    private $paths = array();

    private $cache = false;

    private $update_cache = false;

    /**
     * Adds actions and such.
     *
     * @uses    add_action
     * @since   1.0
     * @access  public
     */
    public function __construct()
    {
        $default = array(
            'active' => true,
            'paths'  => array(ABSPATH, WP_CONTENT_DIR)
        );

        parent::__construct(
            array(
                'disabled' => false, //!current_user_can('install_plugins'),
                'settings' => $default
            )
        );

        if (!WOSettings::check($this->settings, 'active'))
            return;

        $this->paths = array_filter($this->settings['paths']);

        if (empty($this->paths))
            return;

        add_action("wp_dashboard_setup", array($this, 'dashboard_setup'));
    }

    public function setting_fields()
    {
        return array(
            array('type' => 'checkbox', 'name' => 'Active', 'id' => 'active', 'value' => WOSettings::check($this->settings, 'active')),
            array('type' => 'textarea', 'name' => 'paths', 'id' => 'paths', 'value' => implode(PHP_EOL, $this->settings['paths'])),
        );
    }

    public function validate_settings($input, $valid)
    {
        $valid['active'] = isset($input['active']);
        $valid['paths'] = array_map('sanitize_text_field', explode(PHP_EOL, $input['paths']));

        return $valid;
    }

    /**
     * Hooked into `template_redirect`.  Adds the admin bar stick/unstick
     * button if we're on a single post page and the current user can edit
     * the post
     *
     * @since   1.0
     * @access  public
     * @uses    add_action
     */
    public function dashboard_setup()
    {
        foreach ($this->paths as $path) {

            wp_add_dashboard_widget($this->generate_id($path), basename($path) . " size", array($this, "widget"), array($this, 'widget_handle'), array('path' => $path));
        }

        add_action('admin_head', array($this, "head_style"));
    }

    private function generate_id($path)
    {
        $path = trim($path, '/\\');
        return "wpopt_" . basename($path) . "_size";
    }

    /**
     * Prints table styles in dashboard head
     *
     * @since 1.0
     * @access public
     */
    public function head_style()
    {
        echo '<style type="text/css">
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

    public function widget($var, $args = array())
    {
        $path = isset($args['args']['path']) ? $args['args']['path'] : ABSPATH;

        $path = wp_normalize_path($path);
        $dir_list = glob($path . '/*', GLOB_ONLYDIR);

        $this->cache = get_transient($this->transient_prefix . basename($path));

        $this->printFullTable('Files', $path, $dir_list);

        if ($this->update_cache)
            set_transient($this->transient_prefix . basename($path), $this->cache, $this->time);
    }

    /**
     * Print widgets contents
     *
     * @param string $title
     * @param string $root Initial directory to scan
     * @param array $dir_list Directory list of folders
     *
     * @since 1.0
     * @access private
     * @uses set_transient
     */
    private function printFullTable($title, $root, $dir_list)
    {
        if (!isset($this->cache['root_folder'])) {
            $root_size = $this->dirSize($root);
            $cache['root_folder'] = $root_size;
            $this->update_cache = true;
        }
        else
            $root_size = $this->cache['root_folder'];

        $this->printTable($title, $dir_list, $root_size);
    }

    /**
     * Iterates through a folder and get its size
     *
     * @param string $directory
     * @return string Formatted size
     *
     * @since 1.0
     * @access private
     */
    private function dirSize($directory)
    {
        $size = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file)
            $size += $file->getSize();

        return $this->format_size($size);
    }

    /**
     * Formats the size into human readable
     *
     * @param integer $size
     * @return string
     *
     * @since 1.0
     * @access private
     */
    private function format_size($size)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
        $mod = 1024;
        for ($i = 0; $size > $mod; $i++)
            $size /= $mod;

        $endIndex = strpos($size, ".") + 3;
        return substr($size, 0, $endIndex) . ' ' . $units[$i];
    }

    /**
     * Prints the start of the table
     *
     * @param string $title
     *
     * @param $dir_list
     * @param $root_size
     * @since 1.0
     * @access private
     */
    private function printTable($title, $dir_list, $root_size)
    {
        ?>
        <table class="widefat wpopt-dash-widget">
            <thead>
            <tr>
                <th class="row-title"><strong><?php echo $title; ?></strong></th>
                <th><strong>Size</strong></th>
            </tr>
            </thead>
            <tbody>
            <?php
            $this->printDirectoryList($dir_list);
            ?>
            </tbody>
            <tfoot>
            <tr>
                <th class="row-title"><?php echo __('Total', 'wpopt'); ?></th>
                <th><?php echo $root_size; ?></th>
            </tr>
            </tfoot>
        </table>
        <?php
    }

    /**
     * Prints the list of folders and its sizes
     *
     * @param array $directories List of folders inside a directory
     *
     * @since 1.0
     * @access private
     */
    private function printDirectoryList($directories)
    {
        $count = 0;
        $transi = array();
        if (!$this->cache):
            foreach ($directories as $dir) {
                $alt = (++$count % 2) ? 'alternate' : '';
                $name = basename($dir);
                $size = $this->dirSize($dir);
                $transi[$name] = $size;
                printf(
                    '<tr class="%s">
						<td class="row">%s</td>
						<td>%s</td>
					</tr>', $alt, $name, $size
                );
            }
            $this->update_cache = true;
            $this->cache['dir_list'] = $transi;
        else:
            foreach ($this->cache['dir_list'] as $name => $size) {
                $alt = (++$count % 2) ? 'alternate' : '';
                printf(
                    '<tr class="%s">
						<td class="row">%s</td>
						<td>%s</td>
					</tr>', $alt, $name, $size
                );
            }
        endif;
    }

    /**
     * Used for both Widgets configuration
     *
     * @since 1.0
     * @access public
     */
    public function widget_handle()
    {
        if (isset($_POST[$this->transient_prefix])) {

            foreach ($this->paths as $path) {
                delete_transient($this->transient_prefix . basename($path));
            }
        }
        $name = $this->transient_prefix;
        $cache_msg = count($this->paths) > 1 ? __('Clears all widgets caches', 'wpopt') : __('Clear widget cache', 'wpopt');
        echo "<p><label><input name='$name' id='$name' type='checkbox' value='1' />" . __('Check to empty the cache', 'wpopt') . "</label><br /><em style='margin-left: 23px'>$cache_msg</em></p>";
    }
}