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

    public array $scopes = array('admin-page');

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

    private function render_system_info_table(array $table): string
    {
        $rows = array();

        foreach ($table as $_name => $value) {
            $rows[] = array(
                'name' => '<span class="wpopt-system-info-row-icon">' . Graphic::icon($this->info_icon($_name)) . '</span><b>' . esc_html($_name) . ':</b>',
                'value' => $this->format_info_value($value),
            );
        }

        return List_Table::generateHTML_table(array(
            'class' => 'wps wpopt-system-info-table',
            'columns' => array(
                'name' => __('Name', 'wpopt'),
                'value' => __('Value', 'wpopt'),
            ),
            'rows' => $rows,
            'empty' => __('No system information available.', 'wpopt'),
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

    private function info_icon(string $name): string
    {
        $name = strtolower(wp_strip_all_tags($name));

        if (str_contains($name, 'url') || str_contains($name, 'uri')) {
            return 'external';
        }

        if (str_contains($name, 'server') || str_contains($name, 'host') || str_contains($name, 'ip') || str_contains($name, 'os')) {
            return 'server';
        }

        if (str_contains($name, 'database') || str_contains($name, 'mysql') || str_contains($name, 'table') || str_contains($name, 'packet') || str_contains($name, 'query')) {
            return 'database';
        }

        if (str_contains($name, 'time') || str_contains($name, 'date') || str_contains($name, 'execution')) {
            return 'clock';
        }

        if (str_contains($name, 'memory') || str_contains($name, 'size') || str_contains($name, 'upload') || str_contains($name, 'disk')) {
            return 'external';
        }

        if (str_contains($name, 'ssl') || str_contains($name, 'password') || str_contains($name, 'debug') || str_contains($name, 'error')) {
            return 'shield';
        }

        if (str_contains($name, 'agent') || str_contains($name, 'user') || str_contains($name, 'author')) {
            return 'user';
        }

        if (str_contains($name, 'theme') || str_contains($name, 'plugin')) {
            return 'box';
        }

        if (str_contains($name, 'version') || str_contains($name, 'php') || str_contains($name, 'gd') || str_contains($name, 'string') || str_contains($name, 'tag')) {
            return 'code';
        }

        return 'settings';
    }

    public function get_info()
    {
        global $wpdb;

        $plugins = $this->get_plugins();

        $settings = array(
            "Server" => array(
                __('SITE URL', 'wpopt')             => site_url(),
                __('HOME URL', 'wpopt')             => home_url(),
                __('Server IP : port', 'wpopt')     => wps_server_addr() . ' : ' . $_SERVER['SERVER_PORT'],
                __("OS", 'wpopt')                   => PHP_OS,
                __("Server", 'wpopt')               => $_SERVER["SERVER_SOFTWARE"],
                __('Server Document Root', 'wpopt') => $_SERVER['DOCUMENT_ROOT'],
                __('Server Date/Time', 'wpopt')     => mysql2date(sprintf(__('%s @ %s', 'wpopt'), get_option('date_format'), get_option('time_format')), current_time('mysql')),
                __('Server Load', 'wpopt')          => $this->server_load(),
                __('Web Server Info', 'wpopt')      => $_SERVER['SERVER_SOFTWARE'],
                __('User Agent', 'wpopt')           => $_SERVER['HTTP_USER_AGENT'],
                __('Filesystem Method', 'wpopt')    => get_filesystem_method(),
                __('SSL SUPPORT', 'wpopt')          => extension_loaded('openssl') ? __('SSL extension loaded', 'wpopt') : __('SSL extension NOT loaded', 'wpopt'),
                __('MB String', 'wpopt')            => extension_loaded('mbstring') ? __('MB String extensions loaded', 'wpopt') : __('MB String extensions NOT loaded', 'wpopt'),
                __("GD Version", 'wpopt')           => $this->get_gd_version(),
            ),

            "PHP" => array(
                __("Version", 'wpopt')                => PHP_VERSION,
                __('Memory Limit', 'wpopt')           => ini_get('memory_limit'),
                __('Post Max Size', 'wpopt')          => ini_get('post_max_size'),
                __('Upload Max File size', 'wpopt')   => ini_get('upload_max_filesize'),
                __('Upload Max Files', 'wpopt')       => ini_get('max_file_uploads'),
                __('Script execution limit', 'wpopt') => ini_get('max_execution_time') . ' s',
                __('FPM', 'wpopt')                    => str_starts_with(php_sapi_name(), 'fpm') ? __('On', 'wpopt') : __('Off', 'wpopt'),
                __('Short Tag', 'wpopt')              => ini_get('short_open_tag') ? __('On', 'wpopt') : __('Off', 'wpopt'),
            ),

            "MySQL" => array(
                __("Database User", "wpopt")             => DB_USER,
                __("Database Name", "wpopt")             => DB_NAME,
                __("Database Host", "wpopt")             => DB_HOST,
                __("Database Password", "wpopt")         => (current_user_can('manage_options') ? DB_PASSWORD : '**********'),
                __("Version", 'wpopt')                   => $wpdb->db_version(),
                __('Database Data Disk Usage', 'wpopt')  => size_format($this->get_mysql_usages('data')),
                __('Database Index Disk Usage', 'wpopt') => size_format($this->get_mysql_usages('index')),
                __('Maximum Packet Size', 'wpopt')       => size_format($this->get_mysql_variable('max_allowed_packet')),
                __('Maximum No. Connection', 'wpopt')    => number_format_i18n($this->get_mysql_variable('max_connections')),
                __('Query Cache Size', 'wpopt')          => size_format($this->get_mysql_variable('query_cache_size')),
            ),

            "WordPress" => array(
                __('Multi-site', 'wpopt')             => is_multisite() ? __('Yes', 'wpopt') : __('No', 'wpopt'),
                __('Blog id', 'wpopt')                => get_current_blog_id(),
                __('WordPress Version', 'wpopt')      => get_bloginfo('version'),
                __('Permalink Structure', 'wpopt')    => get_option('permalink_structure'),
                __('WP_DEBUG', 'wpopt')               => defined('WP_DEBUG') ? (WP_DEBUG ? __('Enabled', 'wpopt') : __('Disabled', 'wpopt')) : __('Not set', 'wpopt'),
                __('DISPLAY ERRORS', 'wpopt')         => ini_get('display_errors') ? sprintf(__('On ( %s )', 'wpopt'), ini_get('display_errors')) : __('N/A', 'wpopt'),
                __('WP Table Prefix', 'wpopt')        => sprintf(__('Length: %s Status: %s', 'wpopt'), strlen($wpdb->prefix), (strlen($wpdb->prefix) > 16 ? __('ERROR: Too Long', 'wpopt') : __('Acceptable', 'wpopt'))),
                __('WP DB Charset/Collate', 'wpopt')  => $wpdb->get_charset_collate(),
                __('WordPress Memory Limit', 'wpopt') => size_format((int)WP_MEMORY_LIMIT * 1048576),
                __('WordPress Upload Size', 'wpopt')  => size_format(wp_max_upload_size()),
            ),

            "Session & Cookies" => array(
                __('Session', 'wpopt')          => isset($_SESSION) ? __('Enabled', 'wpopt') : __('Disabled', 'wpopt'),
                __('Session Name', 'wpopt')     => esc_html(ini_get('session.name')),
                __('Cookie Path', 'wpopt')      => esc_html(ini_get('session.cookie_path')),
                __('Save Path', 'wpopt')        => esc_html(ini_get('session.save_path')),
                __('Use Cookies', 'wpopt')      => ini_get('session.use_cookies') ? __('On', 'wpopt') : __('Off', 'wpopt'),
                __('Use Only Cookies', 'wpopt') => ini_get('session.use_only_cookies') ? __('On', 'wpopt') : __('Off', 'wpopt'),
            ),

            __('Active plugins', 'wpopt')   => $plugins['active'] ?? 0,
            __('Inactive plugins', 'wpopt') => $plugins['inactive'] ?? 0,
            __('Current theme', 'wpopt')    => $this->get_current_theme(),
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
            $gd = __('N/A', 'wpopt');
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
            __('name', 'wpopt')      => $theme_data->get('Name'),
            __('Version', 'wpopt')   => $theme_data->get('Version'),
            __('Author', 'wpopt')    => $theme_data->get('Author'),
            __('AuthorURI', 'wpopt') => $theme_data->get('AuthorURI')
        );
    }

    private function get_phpinfo()
    {
        if (!class_exists('DOMDocument'))
            return;

        ob_start();
        phpinfo();
        $phpinfo = ob_get_clean();

        // Use DOMDocument to parse phpinfo()
        $html = new \DOMDocument('1.0', 'UTF-8');
        $html->loadHTML($phpinfo);

        // Style process
        $tables = $html->getElementsByTagName('table');
        foreach ($tables as $table) {
            $table->setAttribute('class', 'widefat');
        }

        // We only need the <body>
        $xpath = new \DOMXPath($html);
        $body = $xpath->query('/html/body');

        // Save HTML fragment
        $phpinfo_html = $html->saveXml($body->item(0));

        echo '<div class="wrap" id="PHPinfo" style="display: none;">';
        echo '<h2>PHP ' . phpversion() . '</h2>';

        echo $phpinfo_html;
        echo '</div>';
    }
}

return __NAMESPACE__;
