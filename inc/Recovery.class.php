<?php
/**
 * Automatic recovery for WP Optimizer-caused fatal errors.
 */

namespace WPOptimizer\core;

use WPS\core\Disk;
use WPS\core\Settings;

class Recovery
{
    private const BACKUP_OPTION = 'wpopt_configuration_backups';
    private const BACKUP_ITEM = 'configuration_backups';
    private const BACKUP_CONTEXT = 'settings';
    private const STATE_OPTION = 'wpopt_recovery_state';
    private const RECOVERY_FLAG = 'WPOPT_RECOVERY_RUNNING';
    private const RECOVER_ACTION = 'wpopt_recovery_try_recover';
    private const RESET_ACTION = 'wpopt_recovery_factory_reset';
    private const BACKUP_MAX_ENTRIES = 50;

    private const FATAL_TYPES = array(
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
        E_USER_ERROR,
        E_RECOVERABLE_ERROR,
    );

    public static function bootstrap(): void
    {
        register_shutdown_function(array(__CLASS__, 'handle_shutdown'));

        if (is_admin()) {
            add_action('admin_init', array(__CLASS__, 'maybe_render_recovery_page'), 0);
        }
    }

    public static function handle_shutdown(): void
    {
        $error = error_get_last();
        $state = self::get_state();

        if (!self::is_fatal_error($error)) {
            if (in_array(($state['status'] ?? ''), array('testing_backup', 'probing_fixed_request'), true) && !defined(self::RECOVERY_FLAG)) {
                delete_option(self::STATE_OPTION);
            }

            return;
        }

        if (!self::is_wpopt_error($error)) {
            return;
        }

        self::recover_from_fatal($error, $state);
    }

    private static function recover_from_fatal(array $error, array $state): void
    {
        self::define_recovery_flag();

        $tried = array_values(array_filter((array)($state['tried'] ?? array())));

        if (($state['status'] ?? '') === 'testing_backup' && self::try_next_configuration_backup($error, $tried)) {
            return;
        }

        $probe_failed = ($state['status'] ?? '') === 'probing_fixed_request';

        self::set_state(array(
            'status'     => 'reset_pending',
            'tried'      => array_values(array_unique($tried)),
            'last_error' => self::format_error($error),
            'message'    => $probe_failed ? __('The error is still present. Choose Try Recover or Reset to continue.', 'wpopt') : '',
            'probe_failed' => $probe_failed,
            'updated_at' => time(),
        ));
    }

    private static function factory_reset_from_recovery(array $error, array $tried): void
    {
        self::define_recovery_flag();

        self::factory_reset_options();

        self::set_state(array(
            'status'     => 'factory_reset_done',
            'tried'      => array_values(array_unique($tried)),
            'last_error' => self::format_error($error),
            'updated_at' => time(),
        ));
    }

    private static function factory_reset_options(): void
    {
        self::define_recovery_flag();

        if (wps('wpopt') && isset(wps('wpopt')->moduleHandler)) {
            wps('wpopt')->moduleHandler->reset_modules(null, false);
        }

        if (wps('wpopt') && isset(wps('wpopt')->settings)) {
            wps('wpopt')->settings->reset();

            if (isset(wps('wpopt')->moduleHandler)) {
                wps('wpopt')->moduleHandler->upgrade();
            }
        }
        else {
            update_option('wpopt', array(), 'yes');
        }

        self::disable_external_optimizations();
    }

    private static function disable_external_optimizations(): void
    {
        if (wps('wpopt') && isset(wps('wpopt')->moduleHandler)) {
            wps('wpopt')->moduleHandler->cleanup_modules(null, false);
        }
        else {
            self::reset_cache_dropins();
            self::reset_storage_cache();
            self::reset_server_rules();
        }

        if (wps('wpopt') && isset(wps('wpopt')->cron)) {
            wps('wpopt')->cron->deactivate();
        }
    }

    public static function maybe_render_recovery_page(): void
    {
        $state = self::get_state();
        $status = (string)($state['status'] ?? '');

        if (!in_array($status, array('reset_pending', 'disabled', 'factory_reset_done'), true) || !current_user_can('manage_options')) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        if ($page !== 'wp-optimizer' && $page !== 'wpopt-settings') {
            return;
        }

        if ($status === 'disabled') {
            $state['status'] = 'reset_pending';
            $state['message'] = __('This page was opened from a previous recovery state. Choose Try Recover or Reset to continue.', 'wpopt');
            $state['probe_failed'] = true;
            self::set_state($state);
            $status = 'reset_pending';
        }

        $actions_available = in_array($status, array('reset_pending', 'factory_reset_done'), true);

        if ($actions_available && self::handle_manual_recovery_action($state)) {
            return;
        }

        if ($actions_available && self::maybe_probe_fixed_request($state)) {
            return;
        }

        $error = (array)($state['last_error'] ?? array());
        $message = sprintf(
            '<p>%s</p><p><strong>%s</strong></p><p><code>%s:%s</code></p><p>%s</p>%s',
            esc_html__('WP Optimizer detected a fatal error caused by its own code. No options were reset automatically.', 'wpopt'),
            esc_html((string)($error['message'] ?? '')),
            esc_html((string)($error['file'] ?? '')),
            esc_html((string)($error['line'] ?? '')),
            $status === 'factory_reset_done'
                ? esc_html__('WP Optimizer was reset to factory settings. Cache drop-ins and local server rules were removed, and scheduled optimizer tasks were disabled. Review the error before re-enabling modules.', 'wpopt')
                : esc_html__('Try Recover restores configuration backups one by one until a request completes without this fatal error. Reset restores WP Optimizer to factory settings and disables cache drop-ins, local server rules and scheduled optimizer tasks.', 'wpopt'),
            $actions_available ? self::render_manual_recovery_forms($state) : ''
        );

        wp_die($message, esc_html__('WP Optimizer recovery mode', 'wpopt'), array('response' => 200));
    }

    private static function handle_manual_recovery_action(array $state): bool
    {
        $action = isset($_POST['wpopt-recovery-action']) ? sanitize_key(wp_unslash($_POST['wpopt-recovery-action'])) : '';

        if (!in_array($action, array(self::RECOVER_ACTION, self::RESET_ACTION), true)) {
            return false;
        }

        check_admin_referer($action);

        $error = (array)($state['last_error'] ?? array());
        $tried = (array)($state['tried'] ?? array());

        if ($action === self::RECOVER_ACTION) {
            if (!self::try_next_configuration_backup($error, $tried)) {
                self::set_state(array(
                    'status'     => 'reset_pending',
                    'tried'      => array_values(array_unique($tried)),
                    'last_error' => self::format_error($error),
                    'message'    => __('No valid configuration backup is available for recovery.', 'wpopt'),
                    'updated_at' => time(),
                ));
            }
        }
        else {
            self::factory_reset_from_recovery($error, $tried);
        }

        wp_safe_redirect(remove_query_arg(array('_wpnonce')));
        exit;
    }

    private static function maybe_probe_fixed_request(array $state): bool
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return false;
        }

        unset($state['message'], $state['probe_failed']);
        $state['status'] = 'probing_fixed_request';
        $state['updated_at'] = time();
        self::set_state($state);

        return true;
    }

    private static function render_manual_recovery_forms(array $state): string
    {
        ob_start();
        ?>
        <?php if (!empty($state['message'])) : ?>
            <p><em><?php echo esc_html((string)$state['message']); ?></em></p>
        <?php endif; ?>
        <div style="display:flex; justify-content:center; align-items:center; gap:12px; flex-wrap:wrap; margin:28px auto 0;">
            <form method="post" style="margin:0;">
                <?php wp_nonce_field(self::RECOVER_ACTION); ?>
                <input type="hidden" name="wpopt-recovery-action" value="<?php echo esc_attr(self::RECOVER_ACTION); ?>">
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Try Recover', 'wpopt'); ?>
                </button>
            </form>
            <form method="post" style="margin:0;">
                <?php wp_nonce_field(self::RESET_ACTION); ?>
                <input type="hidden" name="wpopt-recovery-action" value="<?php echo esc_attr(self::RESET_ACTION); ?>">
                <button type="submit" class="button">
                    <?php esc_html_e('Reset', 'wpopt'); ?>
                </button>
            </form>
        </div>
        <?php

        return (string)ob_get_clean();
    }

    private static function try_next_configuration_backup(array $error, array $tried): bool
    {
        self::define_recovery_flag();

        $backup = self::next_backup($tried);

        if (!$backup) {
            return false;
        }

        $settings = self::decode_backup_settings($backup);

        if (!is_array($settings)) {
            $tried[] = (string)$backup['id'];
            return self::try_next_configuration_backup($error, $tried);
        }

        $tried[] = (string)$backup['id'];
        update_option('wpopt', $settings, 'yes');
        self::apply_restored_settings($settings);
        self::set_state(array(
            'status'       => 'testing_backup',
            'tried'        => array_values(array_unique($tried)),
            'last_error'   => self::format_error($error),
            'updated_at'   => time(),
        ));

        return true;
    }

    private static function apply_restored_settings(array $settings): void
    {
        if (self::apply_restored_settings_with_lifecycle($settings)) {
            return;
        }

        self::sync_server_rules($settings);
        self::sync_cache_dropins($settings['cache'] ?? array());
        self::sync_static_direct_access($settings['cache'] ?? array());
    }

    private static function apply_restored_settings_with_lifecycle(array $settings): bool
    {
        if (!wps('wpopt') || !isset(wps('wpopt')->moduleHandler)) {
            return false;
        }

        wps('wpopt')->moduleHandler->refresh_settings($settings);
        wps('wpopt')->moduleHandler->reset_modules($settings, false);
        wps('wpopt')->moduleHandler->activate_modules_for_settings($settings);

        return true;
    }

    private static function sync_server_rules(array $settings): void
    {
        if (!self::load_server_rules_writer()) {
            return;
        }

        $optimizer = is_array($settings['wp_optimizer'] ?? null) ? $settings['wp_optimizer'] : array();
        self::sync_rule_group($optimizer, array('srv_enhancements', 'srv_compression', 'srv_browser_cache'));

        self::sync_static_direct_access_rule(is_array($settings['cache'] ?? null) ? $settings['cache'] : array());

        if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) {
            return;
        }

        $security = is_array($settings['wp_security'] ?? null) ? $settings['wp_security'] : array();
        self::sync_rule_group($security, array('srv_security'));
    }

    private static function sync_rule_group(array $settings, array $rules): void
    {
        $writer = new \WPOptimizer\modules\supporters\WP_Htaccess($settings);

        foreach ($rules as $rule) {
            $writer->toggle_rule($rule, Settings::get_option($settings, "$rule.active"));
        }

        if ($writer->edited()) {
            $writer->write();
        }
    }

    private static function sync_static_direct_access_rule(array $cache_settings): void
    {
        $static_settings = is_array($cache_settings['static_pages'] ?? null) ? $cache_settings['static_pages'] : array();
        $cache_settings['static_pages'] = $static_settings;
        $enabled = Settings::get_option($static_settings, 'active') && Settings::get_option($static_settings, 'direct_access_enabled');

        $writer = new \WPOptimizer\modules\supporters\WP_Htaccess($cache_settings);
        $writer->toggle_rule('static_direct_access', $enabled);

        if ($writer->edited()) {
            $writer->write();
        }
    }

    private static function reset_server_rules(): void
    {
        if (!self::load_server_rules_writer()) {
            return;
        }

        $writer = new \WPOptimizer\modules\supporters\WP_Htaccess(array());

        foreach (array('srv_mime_types', 'srv_compression', 'srv_enhancements', 'srv_security', 'srv_browser_cache', 'static_direct_access') as $rule) {
            $writer->remove_rule($rule);
        }

        if ($writer->edited()) {
            $writer->write();
        }
    }

    private static function sync_cache_dropins(array $settings): void
    {
        if (Settings::get_option($settings, 'object_cache.active')) {
            self::write_object_cache_dropin();
        }
        else {
            self::delete_dropin(WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'object-cache.php');
        }

        if (Settings::get_option($settings, 'wp_db.active')) {
            self::write_db_cache_dropin();
        }
        else {
            self::delete_dropin(WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'db.php');
        }
    }

    private static function sync_static_direct_access(array $settings): void
    {
        $static_settings = is_array($settings['static_pages'] ?? null) ? $settings['static_pages'] : array();
        $enabled = Settings::get_option($static_settings, 'active') && Settings::get_option($static_settings, 'direct_access_enabled');

        if (!self::load_static_direct_access()) {
            self::delete_static_direct_runtime_files();
            return;
        }

        if ($enabled) {
            \WPOptimizer\modules\supporters\StaticCacheDirectAccess::activate($static_settings);
        }
        else {
            \WPOptimizer\modules\supporters\StaticCacheDirectAccess::deactivate();
        }
    }

    private static function reset_cache_dropins(): void
    {
        self::delete_dropin(WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'object-cache.php');
        self::delete_dropin(WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'db.php');
        self::delete_static_direct_runtime_files();
    }

    private static function reset_storage_cache(): void
    {
        if (defined('WPOPT_STORAGE')) {
            Disk::delete(WPOPT_STORAGE . 'cache');
            Disk::delete(WPOPT_STORAGE . 'minify');
            Disk::delete(WPOPT_STORAGE . 'direct-static');
        }
    }

    private static function delete_static_direct_runtime_files(): void
    {
        if (defined('ABSPATH')) {
            $bootstrap = trailingslashit(ABSPATH) . 'wpopt-static-direct.php';
            if (is_file($bootstrap)) {
                @unlink($bootstrap);
            }
        }

        if (defined('WP_CONTENT_DIR')) {
            self::delete_directory(trailingslashit(WP_CONTENT_DIR) . 'wpopt/direct-static');
        }
    }

    private static function delete_directory(string $path): void
    {
        $path = realpath($path);
        $base = defined('WP_CONTENT_DIR') ? realpath(WP_CONTENT_DIR) : false;

        if (!$path || !$base || strpos(self::normalize_path($path), self::normalize_path(trailingslashit($base))) !== 0) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            }
            else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }

    private static function write_object_cache_dropin(): void
    {
        Disk::write(
            WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'object-cache.php',
            "<?php\n\n// WP-Optimizer object cache drop-in\n" .
            "include_once('" . WPOPT_SUPPORTERS . "cache/object-cache.php');\n",
            0
        );
    }

    private static function write_db_cache_dropin(): void
    {
        Disk::write(
            WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'db.php',
            "<?php" . PHP_EOL . PHP_EOL . "include_once('" . WPOPT_SUPPORTERS . "cache/db.php');",
            0
        );
    }

    private static function delete_dropin(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $contents = (string)@file_get_contents($path);

        if (strpos($contents, 'WP-Optimizer') !== false || strpos($contents, 'WPOPT_SUPPORTERS') !== false) {
            @unlink($path);
        }
    }

    private static function next_backup(array $tried): ?array
    {
        foreach (self::get_backups() as $backup) {
            if (!in_array((string)$backup['id'], $tried, true)) {
                return $backup;
            }
        }

        return null;
    }

    private static function get_backups(): array
    {
        $backups = array();

        if (function_exists('wps') && isset(wps('wpopt')->options)) {
            $stored_backups = wps('wpopt')->options->get(self::BACKUP_OPTION, self::BACKUP_ITEM, self::BACKUP_CONTEXT, array(), false);
            $backups = is_array($stored_backups) ? $stored_backups : array();
        }

        if (!is_array($backups)) {
            return array();
        }

        $backups = array_values(array_filter($backups, static function ($backup) {
            return is_array($backup) && !empty($backup['id']) && !empty($backup['created_at']) && !empty($backup['settings']);
        }));

        usort($backups, static function ($a, $b) {
            return (int)$b['created_at'] <=> (int)$a['created_at'];
        });

        return array_slice(array_values($backups), 0, self::BACKUP_MAX_ENTRIES);
    }

    private static function decode_backup_settings(array $backup): ?array
    {
        $decoded = base64_decode((string)($backup['settings'] ?? ''), true);

        if (!is_string($decoded) || $decoded === '') {
            return null;
        }

        $settings = @unserialize($decoded, array('allowed_classes' => false));

        return is_array($settings) ? $settings : null;
    }

    private static function is_fatal_error($error): bool
    {
        return is_array($error) && in_array((int)($error['type'] ?? 0), self::FATAL_TYPES, true);
    }

    private static function is_wpopt_error(array $error): bool
    {
        $file = self::normalize_path((string)($error['file'] ?? ''));
        $plugin_path = self::normalize_path(WPOPT_ABSPATH);

        if ($file !== '' && strpos($file, $plugin_path) === 0) {
            return true;
        }

        foreach (array('object-cache.php', 'db.php') as $dropin) {
            $dropin_path = self::normalize_path(WP_CONTENT_DIR . DIRECTORY_SEPARATOR . $dropin);

            if ($file === $dropin_path && self::dropin_is_wpopt($dropin_path)) {
                return true;
            }
        }

        return false;
    }

    private static function dropin_is_wpopt(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $contents = (string)@file_get_contents($path);

        return strpos($contents, 'WP-Optimizer') !== false || strpos($contents, 'WPOPT_SUPPORTERS') !== false;
    }

    private static function load_server_rules_writer(): bool
    {
        if (!class_exists('\WPOptimizer\modules\supporters\WP_Htaccess')) {
            require_once WPOPT_SUPPORTERS . 'optisec/localConf.php';
        }

        return class_exists('\WPOptimizer\modules\supporters\WP_Htaccess');
    }

    private static function load_static_direct_access(): bool
    {
        if (!class_exists('\WPOptimizer\modules\supporters\StaticCacheDirectAccess')) {
            require_once WPOPT_SUPPORTERS . 'cache/staticcache_direct.class.php';
        }

        return class_exists('\WPOptimizer\modules\supporters\StaticCacheDirectAccess');
    }

    private static function define_recovery_flag(): void
    {
        if (!defined(self::RECOVERY_FLAG)) {
            define(self::RECOVERY_FLAG, true);
        }
    }

    private static function get_state(): array
    {
        $state = get_option(self::STATE_OPTION, array());

        return is_array($state) ? $state : array();
    }

    private static function set_state(array $state): void
    {
        update_option(self::STATE_OPTION, $state, 'no');
    }

    private static function format_error(array $error): array
    {
        return array(
            'type'    => (int)($error['type'] ?? 0),
            'message' => (string)($error['message'] ?? ''),
            'file'    => (string)($error['file'] ?? ''),
            'line'    => (int)($error['line'] ?? 0),
            'time'    => time(),
        );
    }

    private static function normalize_path(string $path): string
    {
        $real = realpath($path);
        $path = $real ?: $path;

        return strtolower(str_replace('\\', '/', rtrim($path, '/\\')));
    }
}
