<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use WPS\core\Graphic;
use WPS\core\List_Table;
use WPS\core\UtilEnv;
use WPS\modules\Module;

class Mod_WP_Info extends Module
{
    public static ?string $name = "System Info";


    protected string $context = 'wpopt';

    protected function render_sub_modules(bool $standalone = true): void
    {
        ?>
        <section class="wpopt-system-info">
            <section class="wpopt-system-info-list">
                <?php foreach ($this->get_info() as $name => $table) : ?>
                    <section class="wpopt-system-info-card" id="<?php echo esc_attr(sanitize_title($name)); ?>">
                        <h2 class="sysinfo-title">
                            <span class="wpopt-system-info-title-icon"><?php echo Graphic::icon($this->section_icon($name)); ?></span>
                            <span><?php echo esc_html($name); ?></span>
                        </h2>
                        <?php echo $this->render_system_info_table($table); ?>
                    </section>
                <?php endforeach; ?>
            </section>
        </section>
        <?php
    }

    private function render_system_info_table($table): string
    {
        $rows = array();
        $table = is_array($table) ? $table : array();

        if (!$table) {
            return '<div class="wpopt-system-info-empty">' . esc_html__('No system information available.', 'wp-optimizer') . '</div>';
        }

        foreach ($table as $_name => $value) {
            $rows[] = array(
                'name' => '<b>' . esc_html($_name) . ':</b>',
                'value' => $this->format_info_value($value),
            );
        }

        return List_Table::generateHTML_table(array(
            'class' => 'wps wpopt-system-info-table',
            'columns' => array(
                'name' => __('Name', 'wp-optimizer'),
                'value' => __('Value', 'wp-optimizer'),
            ),
            'rows' => $rows,
            'empty' => __('No system information available.', 'wp-optimizer'),
        ));
    }

    private function format_info_value($value): string
    {
        if (is_scalar($value) || $value === null) {
            $value = (string)$value;
            $escaped_value = esc_html($value);

            if (filter_var($value, FILTER_VALIDATE_URL)) {
                return '<a href="' . esc_url($value) . '" target="_blank" rel="noopener noreferrer">' . $escaped_value . '</a>';
            }

            return $escaped_value;
        }

        return esc_html(wp_json_encode($value));
    }

    private function section_icon(string $name): string
    {
        $name = strtolower(wp_strip_all_tags($name));

        if (str_contains($name, 'server')) {
            return 'server';
        }

        if (str_contains($name, 'php')) {
            return 'code';
        }

        if (str_contains($name, 'mysql') || str_contains($name, 'database')) {
            return 'database';
        }

        if (str_contains($name, 'wordpress') || str_contains($name, 'theme')) {
            return 'settings';
        }

        if (str_contains($name, 'plugin')) {
            return 'box';
        }

        if (str_contains($name, 'session') || str_contains($name, 'cookie')) {
            return 'shield';
        }

        return 'info';
    }

    public function get_info()
    {
        global $wpdb;

        $plugins = $this->get_plugins();

        $settings = array(
            "Server" => array(
                __('SITE URL', 'wp-optimizer')             => site_url(),
                __('HOME URL', 'wp-optimizer')             => home_url(),
                __('Server IP : port', 'wp-optimizer')     => wps_server_addr() . ' : ' . $_SERVER['SERVER_PORT'],
                __("OS", 'wp-optimizer')                   => PHP_OS,
                __("Server", 'wp-optimizer')               => $_SERVER["SERVER_SOFTWARE"],
                __('Server Document Root', 'wp-optimizer') => $_SERVER['DOCUMENT_ROOT'],
                __('Server Date/Time', 'wp-optimizer')     => mysql2date(sprintf(__('%s @ %s', 'wp-optimizer'), get_option('date_format'), get_option('time_format')), current_time('mysql')),
                __('Server Load', 'wp-optimizer')          => $this->server_load(),
                __('Web Server Info', 'wp-optimizer')      => $_SERVER['SERVER_SOFTWARE'],
                __('User Agent', 'wp-optimizer')           => $_SERVER['HTTP_USER_AGENT'],
                __('Filesystem Method', 'wp-optimizer')    => get_filesystem_method(),
                __('SSL SUPPORT', 'wp-optimizer')          => extension_loaded('openssl') ? __('SSL extension loaded', 'wp-optimizer') : __('SSL extension NOT loaded', 'wp-optimizer'),
                __('MB String', 'wp-optimizer')            => extension_loaded('mbstring') ? __('MB String extensions loaded', 'wp-optimizer') : __('MB String extensions NOT loaded', 'wp-optimizer'),
                __("GD Version", 'wp-optimizer')           => $this->get_gd_version(),
            ),

            "PHP" => array(
                __("Version", 'wp-optimizer')                => PHP_VERSION,
                __('Memory Limit', 'wp-optimizer')           => ini_get('memory_limit'),
                __('Post Max Size', 'wp-optimizer')          => ini_get('post_max_size'),
                __('Upload Max File size', 'wp-optimizer')   => ini_get('upload_max_filesize'),
                __('Upload Max Files', 'wp-optimizer')       => ini_get('max_file_uploads'),
                __('Script execution limit', 'wp-optimizer') => ini_get('max_execution_time') . ' s',
                __('FPM', 'wp-optimizer')                    => str_starts_with(php_sapi_name(), 'fpm') ? __('On', 'wp-optimizer') : __('Off', 'wp-optimizer'),
                __('Short Tag', 'wp-optimizer')              => ini_get('short_open_tag') ? __('On', 'wp-optimizer') : __('Off', 'wp-optimizer'),
            ),

            "MySQL" => array(
                __("Database User", 'wp-optimizer')             => DB_USER,
                __("Database Name", 'wp-optimizer')             => DB_NAME,
                __("Database Host", 'wp-optimizer')             => DB_HOST,
                __("Database Password", 'wp-optimizer')         => (current_user_can('manage_options') ? DB_PASSWORD : '**********'),
                __("Version", 'wp-optimizer')                   => $wpdb->db_version(),
                __('Database Data Disk Usage', 'wp-optimizer')  => size_format($this->get_mysql_usages('data')),
                __('Database Index Disk Usage', 'wp-optimizer') => size_format($this->get_mysql_usages('index')),
                __('Maximum Packet Size', 'wp-optimizer')       => size_format($this->get_mysql_variable('max_allowed_packet')),
                __('Maximum No. Connection', 'wp-optimizer')    => number_format_i18n($this->get_mysql_variable('max_connections')),
                __('Query Cache Size', 'wp-optimizer')          => size_format($this->get_mysql_variable('query_cache_size')),
            ),

            "WordPress" => array(
                __('Multi-site', 'wp-optimizer')             => is_multisite() ? __('Yes', 'wp-optimizer') : __('No', 'wp-optimizer'),
                __('Blog id', 'wp-optimizer')                => get_current_blog_id(),
                __('WordPress Version', 'wp-optimizer')      => get_bloginfo('version'),
                __('Permalink Structure', 'wp-optimizer')    => get_option('permalink_structure'),
                __('WP_DEBUG', 'wp-optimizer')               => defined('WP_DEBUG') ? (WP_DEBUG ? __('Enabled', 'wp-optimizer') : __('Disabled', 'wp-optimizer')) : __('Not set', 'wp-optimizer'),
                __('DISPLAY ERRORS', 'wp-optimizer')         => ini_get('display_errors') ? sprintf(__('On ( %s )', 'wp-optimizer'), ini_get('display_errors')) : __('N/A', 'wp-optimizer'),
                __('WP Table Prefix', 'wp-optimizer')        => sprintf(__('Length: %s Status: %s', 'wp-optimizer'), strlen($wpdb->prefix), (strlen($wpdb->prefix) > 16 ? __('ERROR: Too Long', 'wp-optimizer') : __('Acceptable', 'wp-optimizer'))),
                __('WP DB Charset/Collate', 'wp-optimizer')  => $wpdb->get_charset_collate(),
                __('WordPress Memory Limit', 'wp-optimizer') => size_format((int)WP_MEMORY_LIMIT * 1048576),
                __('WordPress Upload Size', 'wp-optimizer')  => size_format(wp_max_upload_size()),
            ),

            "Session & Cookies" => array(
                __('Session', 'wp-optimizer')          => isset($_SESSION) ? __('Enabled', 'wp-optimizer') : __('Disabled', 'wp-optimizer'),
                __('Session Name', 'wp-optimizer')     => esc_html(ini_get('session.name')),
                __('Cookie Path', 'wp-optimizer')      => esc_html(ini_get('session.cookie_path')),
                __('Save Path', 'wp-optimizer')        => esc_html(ini_get('session.save_path')),
                __('Use Cookies', 'wp-optimizer')      => ini_get('session.use_cookies') ? __('On', 'wp-optimizer') : __('Off', 'wp-optimizer'),
                __('Use Only Cookies', 'wp-optimizer') => ini_get('session.use_only_cookies') ? __('On', 'wp-optimizer') : __('Off', 'wp-optimizer'),
            ),

            __('Active plugins', 'wp-optimizer')   => $plugins['active'] ?? array(),
            __('Inactive plugins', 'wp-optimizer') => $plugins['inactive'] ?? array(),
            __('Current theme', 'wp-optimizer')    => $this->get_current_theme(),
        );

        return apply_filters('wpopt_system_info', $settings);
    }

    public function get_plugins()
    {
        $plugins = array();

        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', array());

        foreach ($all_plugins as $plugin_path => $plugin) {
            // If the plugin isn't active, don't show it.
            if (!in_array($plugin_path, $active_plugins)) {
                $plugins['inactive'][$plugin['Name']] = $plugin['Version'];
            }
            else {
                $plugins['active'][$plugin['Name']] = $plugin['Version'];
            }
        }

        return $plugins;
    }

    private function server_load()
    {
        return UtilEnv::get_server_load();
    }

    private function get_gd_version()
    {
        if (function_exists('gd_info')) {
            $gd = gd_info();
            $gd = $gd["GD Version"];
        }
        else {
            ob_start();
            phpinfo(INFO_MODULES);
            $phpinfo = ob_get_contents();
            ob_end_clean();
            $phpinfo = strip_tags($phpinfo);
            $phpinfo = stristr($phpinfo, "gd version");
            $phpinfo = stristr($phpinfo, "version");
            $gd = substr($phpinfo, 0, strpos($phpinfo, "\n"));
        }
        if (empty($gd)) {
            $gd = __('N/A', 'wp-optimizer');
        }
        return $gd;
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

    private function get_mysql_variable($variable)
    {
        global $wpdb;

        $res = $wpdb->get_row("SHOW VARIABLES LIKE '{$variable}'");

        if (!$res) {
            return 0;
        }

        return $res->Value;
    }

    public function get_current_theme()
    {
        $theme_data = wp_get_theme();

        return array(
            __('name', 'wp-optimizer')      => $theme_data->get('Name'),
            __('Version', 'wp-optimizer')   => $theme_data->get('Version'),
            __('Author', 'wp-optimizer')    => $theme_data->get('Author'),
            __('AuthorURI', 'wp-optimizer') => $theme_data->get('AuthorURI')
        );
    }

}

