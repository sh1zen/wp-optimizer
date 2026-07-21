<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

class Settings
{
    private $settings;

    private string $context;

    public function __construct($context)
    {
        $this->context = $context;

        $this->settings = get_option($context) ?: [];

        if (is_admin()) {
            add_action('admin_init', array($this, 'register_hooks'));
        }
    }

    public function switch_to_blog(): void
    {
        $this->settings = get_option($this->context) ?: array();
    }

    public static function check($settings, $key, $default = false)
    {
        $res = self::get_option($settings, $key, null);

        return $res === null ? $default : $res;
    }

    public static function get_option($settings, $setting_path, $default = false)
    {
        $setting_path = (string)$setting_path;

        if ($setting_path === '') {
            if (is_array($settings) or is_object($settings)) {
                $settings = wp_parse_args($settings, $default);
            }

            return $settings;
        }

        // remove consecutive dots and add a last one for while loop
        $setting_path = preg_replace('#\.+#', '.', $setting_path);

        if ($setting_path === '' or $setting_path[0] === '.') {
            if (is_array($settings) or is_object($settings)) {
                $settings = wp_parse_args($settings, $default);
            }

            return $settings;
        }

        foreach (explode('.', $setting_path) as $slug) {

            if ($slug === '') {
                continue;
            }

            if (!isset($settings[$slug])) {
                return $default;
            }

            $settings = $settings[$slug];
        }

        if (is_array($settings) or is_object($settings)) {
            $settings = wp_parse_args($settings, $default);
        }

        return $settings;
    }

    /**
     * Access to settings by path -> delimiter: "."
     *
     * @param string $context
     * @param mixed $default
     * @param bool $update -> if no option were found, update theme, with defaults values
     *
     * @return array|mixed|object|string
     */
    public function get(string $context = '', $default = [], bool $update = false)
    {
        $res = self::get_option($this->settings, $context, null);

        if (is_null($res)) {
            if ($update) {
                $this->update($context, $default);
            }

            return $default;
        }

        if (is_array($default) and is_array($res)) {
            $res = array_merge($default, $res);
        }

        return $res;
    }

    public function update($context, $option_data, $force = false): bool
    {
        if (empty($context)) {
            return false;
        }

        // remove consecutive dots and add a last one for while loop
        $setting_path = trim(preg_replace('#\.+#', '.', $context), '.');

        $settings = &$this->settings;

        while (($pos = strpos($setting_path, '.')) !== false) {

            $slug = substr($setting_path, 0, $pos);

            if (empty($slug)) {
                break;
            }

            if (!isset($settings[$slug])) {
                $settings[$slug] = [];
            }

            $settings = &$settings[$slug];

            $setting_path = substr($setting_path, $pos + 1);
        }

        if (!isset($this->settings[$context])) {
            $settings[$setting_path] = array();
        }

        if ($force) {
            $settings[$setting_path] = $option_data;
        }
        else {
            $settings[$setting_path] = wp_parse_args($option_data, $settings[$setting_path]);
        }

        return $this->reset($this->settings);
    }

    public function reset($options = array()): bool
    {
        $this->settings = $options;

        $updated = update_option($this->context, $options, 'yes');

        if (function_exists('wps_loaded') && wps_loaded($this->context, 'moduleHandler')) {
            wps($this->context)->moduleHandler->refresh_settings(is_array($options) ? $options : array());
        }

        return $updated;
    }

    public function render_core_settings(bool $standalone = true): void
    {
        $pages = $this->get_core_setting_pages();
        $classes = $standalone
            ? array('wps-wrap', 'wps', 'wps-core-settings-page', "{$this->context}-core-settings-page")
            : array('wps-core-settings-page', "{$this->context}-core-settings-page");

        settings_errors();

        if (!$standalone) {
            $this->render_core_setting_page('', false);
            return;
        }

        ?>
        <section class="<?php echo esc_attr(implode(' ', $classes)); ?>">
            <block class="wps">
                <section class='wps-header'>
                    <h1><?php esc_html_e('Core Settings', $this->get_text_domain()); ?></h1>
                    <p><?php echo esc_html($this->get_core_settings_header_description()); ?></p>
                </section>
                <?php

                if (!empty($pages)) {
                    $this->render_registered_setting_page($pages, '');
                }

                ?>
            </block>
        </section>
        <?php
    }

    private function generateHTML_tabpan($modules)
    {
        $fields = array();

        foreach ($modules as $module) {

            $object = wps($this->context)->moduleHandler->get_module_instance($module);

            if (is_null($object)) {
                continue;
            }

            $field = array(
                'id'                => "settings-{$module['slug']}",
                'tab-title'         => $module['name'],
                'tab-icon'          => $this->get_settings_icon($module['slug']),
                'panel-title'       => $module['name'] . " setup",
                'panel-icon'        => $this->get_settings_icon($module['slug']),
                'panel-description' => $this->get_settings_description($module['slug'], $module['name']),
                'panel-status'      => $this->get_settings_status($module['slug']),
                'callback'          => array($object, 'render_settings')
            );

            $fields[] = $field;
        }

        return Graphic::generateHTML_tabs_panels($fields);
    }

    public function get_core_setting_pages(): array
    {
        $pages = apply_filters("wps_{$this->context}_core_settings_pages", array(), $this);

        if (empty($pages)) {
            $pages = $this->build_module_setting_pages(array('core-settings'));
        }

        return $this->normalize_setting_pages($pages);
    }

    public function get_module_setting_pages(): array
    {
        $pages = apply_filters("wps_{$this->context}_module_settings_pages", array(), $this);

        if (empty($pages)) {
            $pages = $this->build_module_setting_pages(array('settings'), true);
        }

        return $this->normalize_setting_pages($pages);
    }

    public function build_admin_app_nav_update(array $module_settings = array(), array $labels = array(), array $tool_icons = array()): array
    {
        $labels = array_merge(array(
            'settings' => __('Settings', $this->get_text_domain()),
            'tools'    => __('Tools', $this->get_text_domain()),
        ), $labels);

        $sections = array();
        $setting_items = array();

        foreach ($this->get_module_setting_pages() as $page) {
            if (!$this->module_is_enabled_for_nav((string)$page['id'], $module_settings)) {
                continue;
            }

            $setting_items[] = array(
                'id'    => 'module-setting-' . $page['id'],
                'label' => $page['label'],
                'icon'  => $page['icon'],
                'url'   => wps_admin_route_url($this->context, 'module-setting-' . $page['id']),
            );
        }

        if (!empty($setting_items)) {
            $sections[] = array(
                'kind'  => 'settings',
                'label' => $labels['settings'],
                'items' => $setting_items,
            );
        }

        $tool_items = array();

        foreach (wps($this->context)->moduleHandler->get_modules(array('scopes' => 'admin-page'), true) as $module) {
            $slug = sanitize_key((string)($module['slug'] ?? ''));

            if (!$slug) {
                continue;
            }

            if (!$this->module_is_enabled_for_nav($slug, $module_settings)) {
                continue;
            }

            $tool_items[] = array(
                'id'    => 'module-' . $slug,
                'label' => (string)($module['name'] ?? $slug),
                'icon'  => (string)($tool_icons[$slug] ?? $this->get_settings_icon($slug)),
                'url'   => wps_admin_route_url($this->context, 'module-' . $slug),
            );
        }

        if (!empty($tool_items)) {
            $sections[] = array(
                'kind'  => 'tools',
                'label' => $labels['tools'],
                'items' => $tool_items,
            );
        }

        return array('sections' => $sections);
    }

    private function module_is_enabled_for_nav(string $slug, array $module_settings): bool
    {
        return !array_key_exists($slug, $module_settings) || (bool)$module_settings[$slug];
    }

    public function render_core_setting_page(string $page_id = '', bool $standalone = false): void
    {
        $pages = $this->get_core_setting_pages();

        if ($standalone) {
            settings_errors();
        }

        $this->render_registered_setting_page($pages, $page_id);
    }

    public function render_module_setting_page(string $page_id = '', bool $standalone = false): void
    {
        $pages = $this->get_module_setting_pages();

        if ($standalone) {
            settings_errors();
        }

        $this->render_registered_setting_page($pages, $page_id);
    }

    private function build_module_setting_pages(array $scopes, bool $only_active = false): array
    {
        $pages = array();
        $modules = wps($this->context)->moduleHandler->get_modules(array('scopes' => $scopes), $only_active);

        foreach ($modules as $module) {
            $object = wps($this->context)->moduleHandler->get_module_instance($module);

            if (is_null($object)) {
                continue;
            }

            $pages[] = array(
                'id'          => $module['slug'],
                'label'       => $module['name'],
                'icon'        => $this->get_settings_icon($module['slug']),
                'description' => $this->get_settings_description($module['slug'], $module['name']),
                'status'      => $this->get_settings_status($module['slug']),
                'callback'    => array($object, 'render_settings'),
            );
        }

        return $pages;
    }

    private function normalize_setting_pages(array $pages): array
    {
        $normalized = array();

        foreach ($pages as $page) {
            if (empty($page['id']) || empty($page['callback']) || !is_callable($page['callback'])) {
                continue;
            }

            $id = sanitize_key((string)$page['id']);

            $normalized[$id] = array(
                'id'          => $id,
                'label'       => (string)($page['label'] ?? $id),
                'icon'        => (string)($page['icon'] ?? 'settings'),
                'description' => (string)($page['description'] ?? ''),
                'status'      => (string)($page['status'] ?? ''),
                'callback'    => $page['callback'],
            );
        }

        return array_values($normalized);
    }

    private function render_registered_setting_page(array $pages, string $page_id): void
    {
        if (empty($pages)) {
            ?>
            <section class="wps-app-panel wps-core-settings-page <?php echo esc_attr("{$this->context}-core-settings-page"); ?>">
                <h1><?php esc_html_e('Settings', $this->get_text_domain()); ?></h1>
                <p><?php esc_html_e('No settings are registered for this plugin.', $this->get_text_domain()); ?></p>
            </section>
            <?php
            return;
        }

        $page_id = sanitize_key($page_id);
        $selected = $pages[0];

        foreach ($pages as $page) {
            if ($page['id'] === $page_id) {
                $selected = $page;
                break;
            }
        }

        ?>
        <section class="wps-app-panel wps-core-settings-page <?php echo esc_attr("{$this->context}-core-settings-page"); ?>">
            <?php
            $content = call_user_func($selected['callback']);

            if (is_string($content)) {
                echo $content;
            }
            ?>
        </section>
        <?php
    }

    private function get_text_domain(): string
    {
        $text_domains = array(
            'wpopt' => 'wpopt',
            'wpfs'  => 'wpfs',
            'wpmc'  => 'members-control',
        );

        return $text_domains[$this->context] ?? $this->context;
    }

    private function get_core_settings_header_description(): string
    {
        $descriptions = array(
            'wpopt' => __('Manage global modules, cron, telemetry, import and export tools.', 'wpopt'),
            'wpfs'  => __('Configure Flexy SEO modules and metadata tools.', 'wpfs'),
            'wpmc'  => __('Configure Members Control modules and membership workflows.', 'members-control'),
        );

        return $descriptions[$this->context] ?? __('Configure plugin settings and shared tools.', $this->get_text_domain());
    }

    private function get_modules_settings_header_description(): string
    {
        $descriptions = array(
            'wpopt' => __('Configure and manage settings for WP Optimizer modules.', 'wpopt'),
            'wpfs'  => __('Configure Flexy SEO module preferences.', 'wpfs'),
            'wpmc'  => __('Configure Members Control module preferences.', 'members-control'),
        );

        return $descriptions[$this->context] ?? __('Configure and manage module settings.', $this->get_text_domain());
    }

    private function get_settings_icon(string $module_slug): string
    {
        $icons = array(
            'cron'            => 'calendar',
            'modules_handler' => 'grid',
            'settings'        => 'settings',
            'tracking'        => 'chart',
            'database'        => 'database',
            'media'           => 'image',
            'minify'          => 'code',
            'pagespeed'       => 'pagespeed',
            'performance_monitor' => 'chart',
            'widget'          => 'box',
            'wp_customizer'   => 'sliders',
            'wp_optimizer'    => 'gauge',
            'wp_security'     => 'shield',
            'cache'           => 'server',
            'activitylog'     => 'list',
            'wp_mail'         => 'mail',
            'wp_updates'      => 'repeat',
            'wp_info'         => 'info',
        );

        return $icons[$module_slug] ?? 'tools';
    }

    private function get_settings_description(string $module_slug, string $module_name): string
    {
        if ($module_slug === 'cron') {
            return __('Configure the automatic optimization tasks that keep your site fast, clean and running smoothly.', 'wpopt');
        }

        if ($module_slug === 'modules_handler') {
            return __('Choose which tools are available in the optimizer workspace.', 'wpopt');
        }

        if ($module_slug === 'tracking') {
            return __('Control diagnostics and anonymous usage tracking for maintenance and troubleshooting.', 'wpopt');
        }

        return sprintf(__('Manage %s preferences for this plugin.', 'wpopt'), $module_name);
    }

    private function get_settings_status(string $module_slug): string
    {
        if ($module_slug !== 'cron') {
            return '';
        }

        $cron_settings = $this->get('cron', array(
            'active'         => false,
            'execution-time' => '01:00',
        ));

        $active = UtilEnv::to_boolean($cron_settings['active'] ?? false);
        $execution_time = (string)($cron_settings['execution-time'] ?? '01:00');

        return sprintf(
            '<div class="wps-panel-status %s"><strong><span></span>%s</strong><small>%s</small>%s</div>',
            $active ? 'is-active' : 'is-inactive',
            $active ? esc_html__('Active', 'wpopt') : esc_html__('Inactive', 'wpopt'),
            esc_html(sprintf(__('Next run: today at %s', 'wpopt'), $execution_time)),
            Graphic::icon('calendar', 'wps-panel-status-icon')
        );
    }

    public function register_hooks(): void
    {
        register_setting("$this->context-settings", $this->context, array(
            'type'              => 'array',
            'sanitize_callback' => array($this, 'validate')
        ));
    }

    public function render_modules_settings(bool $standalone = true)
    {
        $pages = $this->get_module_setting_pages();
        $page_id = $pages[0]['id'] ?? '';

        settings_errors();

        $this->render_module_setting_page($page_id, $standalone);
    }

    public function activate()
    {
        $options = get_option($this->context, array());

        if (empty($options)) {

            /**
             * Load all modules to be allowed them to set up their options
             */
            wps($this->context)->moduleHandler->setup_modules('all');

            $this->reset($this->settings);
        }
    }

    public function validate($input)
    {
        if (!isset($input['change'])) {
            return $input;
        }

        $object = wps($this->context)->moduleHandler->get_module_instance($input['change']);

        if (is_null($object)) {
            die();
        }

        $valid = $object->validate_settings($input);

        $new_settings = wp_parse_args(array($object->slug => $valid), $this->settings);

        if ($object->slug === 'modules_handler') {
            wps($this->context)->moduleHandler->apply_module_status_changes($valid, $new_settings);
        }

        $this->settings = $new_settings;
        wps($this->context)->moduleHandler->refresh_settings($this->settings);

        add_action('shutdown', function () {

            if (isset($_REQUEST['option_page'], $_REQUEST['option_panel'])) {

                $rewriter = Rewriter::getInstance();

                $rewriter->set_fragment($_REQUEST['option_panel']);

                $rewriter->replace_path($_REQUEST['_wp_http_referer']);
            }
        });

        return $this->settings;
    }

    public function export(): string
    {
        return base64_encode(serialize($this->settings));
    }

    public function import($import_settings): bool
    {
        $decoded_settings = base64_decode((string)$import_settings, true);

        if (!is_string($decoded_settings) || '' === $decoded_settings) {
            return false;
        }

        $settings = @unserialize($decoded_settings, ['allowed_classes' => false]);

        if (!$settings or !is_array($settings)) {
            return false;
        }

        return $this->reset($settings);
    }

    public function get_context(): string
    {
        return $this->context;
    }
}
