<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\modules;

use WPS\core\Ajax;
use WPS\core\ModuleHandler;
use WPS\core\Rewriter;
use WPS\core\StringHelper;
use WPS\core\Graphic;
use WPS\core\Settings;
use WPS\core\UtilEnv;

class Module
{
    /**
     * set the module name
     */
    public static ?string $name = null;

    /**
     * List of Module loading contests
     *
     * cron       -> to be loaded in cron context
     *               cron_handler method must be added to execute a schedule
     * ajax       -> to be loaded in ajax context,
     *               to response to an ajax request ajax_handler method must be added
     * admin      -> to be loaded in admin context, to process custom actions, must be set
     * web-view   -> to be loaded in website view context
     * autoload   -> to be loaded in every context
     *
     *
     * settings      -> to display settings in modules-options page,
     *                  load occurs when module setting page is rendering
     * core-settings -> to display settings in settings page,
     *                  load occurs when setting page is rendering
     * admin-page    -> to display an admin page, load occurs after admin_menu hook
     *
     * default: empty - never loaded
     */
    public array $scopes = array();

    /**
     * Module name without prefix Mod_
     */
    public string $slug;

    /**
     * Current module unique identifier
     */
    protected string $module_id;

    protected string $context;

    protected string $action_hook;

    protected string $action_hook_page;

    /**
     * Module settings
     */
    private array $settings;
    /**
     * keep a list of notices to display for current module
     */
    private array $notices = array();

    public function __construct()
    {
        if (!isset($this->context)) {
            trigger_error(__('A context must be set', 'wps'), E_WARNING);
            return;
        }

        $this->slug = ModuleHandler::module_slug(get_class($this), true);

        // before activation to fix error while upgrading
        $this->settings = wps($this->context)->settings->get($this->slug);

        if (!wps($this->context)->moduleHandler->module_is_active($this->slug)) {
            return;
        }

        $this->module_id = wps_core()->uid();

        $this->action_hook = "$this->context-$this->slug-action";
        $this->action_hook_page = "$this->action_hook-page";

        $this->init();

        // check if this module loads on cron and do a cronjob
        if (wp_doing_cron()) {

            if (in_array('cron', $this->scopes) and wps($this->context)->cron->is_active($this->slug)) {

                add_action("{$this->context}_exec_cron", array($this, 'cron_handler'), 10, 1);
            }
        }

        if (is_admin()) {

            if (Graphic::is_on_screen($this->slug)) {

                if (did_action('admin_enqueue_scripts')) {
                    $this->enqueue_scripts();
                }
                else {
                    add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
                }

                /**
                 * Add url passed notice
                 */
                $notice = Rewriter::getInstance()->get_query_arg('wps-notice');
                if ($notice) {
                    $status = Rewriter::getInstance()->get_query_arg('wps-status');
                    $this->add_notices($status, $notice);
                }
            }

            add_action('admin_notices', array($this, 'admin_notices'));

            add_action('admin_init', array($this, 'actions'));
        }
        else {
            add_action('init', array($this, 'actions'));
        }
    }

    protected function init(): void
    {
    }

    public function enqueue_scripts(): void
    {
        wp_enqueue_style('vendor-wps-css');
        wp_enqueue_script('vendor-wps-js');
    }

    /**
     * @param $status -> wrong, success, error, info
     * @param $message
     */
    protected function add_notices($status, $message): void
    {
        $this->notices[] = array('status' => $status, 'message' => $message);
    }

    public function actions(): void
    {
    }

    public function admin_notices(): void
    {
        foreach ($this->notices as $notice) {
            echo "<div class='notice notice-{$notice['status']} is-dismissible'>";
            echo "<p>" . esc_html($notice['message']) . "</p>";
            echo "</div>";
        }
    }

    public function cron_validate_settings($input, $filtering = false): array
    {
        return $this->settings_validator($this->cron_setting_fields(), $input, $filtering);
    }

    private function settings_validator($settings, $input, $filtering = false): array
    {
        $valid = [];

        foreach ($settings as $field) {

            if ($filtering) {
                $field_value = Settings::get_option($input, $field['id'], $field['value']);
            }
            else {
                $field_value = $input[$field['id']] ?? $field['value'];
            }

            switch ($field['type']) {

                case 'checkbox':
                    $value = $filtering ? $field_value : isset($input[$field['id']]);
                    break;

                case 'time':
                case 'text':
                case 'hidden':
                case 'dropdown':
                case 'textarea':
                case 'upload-input':
                    $value = StringHelper::sanitize_text($field_value, false);
                    break;

                case 'textarea_array':

                    if ($filtering) {
                        $value = is_array($field_value) ? $field_value : [];
                    }
                    else {
                        $value = array_filter(
                            array_map(
                                'trim',
                                preg_split("#[\r\n]+#", StringHelper::sanitize_text($field_value, true))
                            )
                        );
                    }
                    break;

                case 'number':
                case 'numeric':
                    $value = intval($field_value);
                    break;

                default:
                    continue 2;
            }

            $_valid = &$valid;
            foreach (explode('.', $field['id']) as $field_id) {

                if (!is_array($_valid)) {
                    $_valid = array($field_id => $_valid);
                }

                if (!isset($_valid[$field_id])) {
                    $_valid[$field_id] = array();
                }

                $_valid = &$_valid[$field_id];
            }

            $_valid = $value;
        }

        return $valid;
    }

    public function cron_setting_fields(): array
    {
        return [];
    }

    public function cron_handler($args = array())
    {
    }

    public function ajax_handler($args = array()): void
    {
        Ajax::response([
            'body'  => sprintf(__('Wrong ajax request for %s', $this->context), $this->slug),
            'title' => __('Request error', $this->context)
        ], 'error');
    }

    public function render_settings($filter = ''): string
    {
        if ($this->restricted_access('settings')) {
            ob_start();
            $this->render_disabled();
            return ob_get_clean();
        }

        $_header = $this->print_header();

        $_divider = false;

        $setting_fields = $this->setting_fields($filter);

        $option_name = wps($this->context)->settings->get_context();

        ob_start();

        if (!empty($setting_fields)) {

            $_divider = true;

            ?>
            <form action="options.php" method="post" autocomplete="off" autocapitalize="off">
                <?php

                if ($_header) {
                    echo "<h3 class='wps'>$_header</h3>";
                }

                settings_fields("$this->context-settings");
                ?>
                <input type="hidden" name="option_panel" value="settings-<?php echo $this->slug; ?>">
                <input type="hidden" name="<?php echo "{$option_name}[change]" ?>" value="<?php echo $this->slug; ?>">
                <block class="wps-options">
                    <?php Graphic::generate_fields($setting_fields, $this->infos(), array('name_prefix' => $option_name)); ?>
                </block>
                <section class="wps-submit">
                    <input type="submit" class="button-primary" value="<?php _e('Save Changes', $this->context) ?>"/>
                </section>
            </form>
            <?php
        }

        $_footer = $this->print_footer();

        if (!empty($_footer)) {

            if ($_divider) {
                echo "<hr class='wps-hr'>";
            }

            echo "<section class='wps-setting-footer'>" . $_footer . "</section>";
        }

        return ob_get_clean();
    }

    public function restricted_access($context = ''): bool
    {
        return false;
    }

    private function render_disabled(): void
    {
        ?>
        <block><h2><?php _e('This Module is disabled for you or for your settings.', $this->context); ?></h2></block>
        <?php
    }

    protected function print_header(): string
    {
        return '';
    }

    protected function setting_fields($filter = ''): array
    {
        return array();
    }

    protected function infos(): array
    {
        return [];
    }

    protected function print_footer(): string
    {
        return '';
    }

    public function render_admin_page(): void
    {
        if ($this->restricted_access('render-admin')) {
            $this->render_disabled();
        }
        else {
            do_action("{$this->context}_enqueue_panel_scripts");
            //$this->remove_browser_query_args();
            $this->render_sub_modules();
        }

        if (WPS_DEBUG) {
            wps_core()->meter->lap("page-$this->slug");
        }
    }

    protected function render_sub_modules(): void
    {
        $subModules = [];

        try {
            $iterator = new \DirectoryIterator($this->get_modulePath() . '/sub-modules/' . $this->slug);

            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isFile()) {

                    $fileName = pathinfo($fileInfo->getFilename(), PATHINFO_FILENAME);

                    $subModules[] = array(
                        'id'          => wps_generate_slug($fileName),
                        'panel-title' => ucfirst(str_replace('.', ' ', $fileName)),
                        'callback'    => $fileInfo->getRealPath(),
                        'context'     => $this
                    );
                }
            }
        } catch (\Exception $e) {
        }

        if (!empty($subModules)) {
            ?>
            <section class="wps-wrap">
                <block class="wps">
                    <section class="wps-header"><h1><?php echo static::$name; ?></h1></section>
                    <?php echo Graphic::generateHTML_tabs_panels($subModules); ?>
                </block>
            </section>
            <?php
        }
    }

    protected function get_modulePath(): string
    {
        $reflection = new \ReflectionClass($this);
        return dirname($reflection->getFileName());
    }

    public function filter_settings(): void
    {
        // use the new settings available after import
        $this->settings = $this->validate_settings(wps($this->context)->settings->get($this->slug), true);
        wps($this->context)->settings->update($this->slug, $this->settings, true);
    }

    /**
     * Provides general setting validator
     * for custom settings : override it
     */
    public function validate_settings($input, $filtering = false): array
    {
        return $this->settings_validator(UtilEnv::array_flatter_one_level($this->setting_fields()), $input, $filtering);
    }

    public function register_panel($parent, $capability = 'customize')
    {
        if (!$this->has_panel()) {
            return false;
        }

        return add_submenu_page($parent, 'WPOPT ' . static::$name, static::$name, $capability, "$this->context-$this->slug", array($this, 'render_admin_page'));
    }

    public function has_panel(): bool
    {
        return in_array('admin-page', $this->scopes);
    }

    protected function remove_browser_query_args($items = null): void
    {
        $items = is_array($items) ? array_filter($items) : [
            'wps-notice',
            'wps-status',
            'wps-action',
            $this->action_hook
        ];
        ?>
        <script>
            jQuery(document).ready(function () {
                <?php
                foreach ($items as $item) {
                    echo "wps().remove_query_arg('" . esc_js($item) . "');";
                }
                ?>
            });
        </script>
        <?php
    }

    protected function group_setting_fields(...$args): array
    {
        return array_filter($args);
    }

    protected function group_setting_sections($fields, $filter = ''): array
    {
        $res = array();

        if (empty($filter)) {
            $res = array_values($fields);
        }
        else {

            if (!is_array($filter)) {
                $filter = [$filter];
            }

            foreach ($filter as $_filter) {
                if (isset($fields[$_filter])) {
                    $res = array_merge($res, $fields[$_filter]);
                }
            }
        }

        return $res;
    }

    protected function setting_field($name, $id = false, $type = 'text', $args = []): array
    {
        $args['default_value'] = $this->option($id, $args['default_value'] ?? '');

        return Graphic::newField($name, $id, $type, $args);
    }

    public function option($path_name = '', $default = false)
    {
        return Settings::get_option($this->settings, $path_name, $default);
    }

    protected function activating($setting_field, $new_settings): bool
    {
        return !$this->option($setting_field) and Settings::get_option($new_settings, $setting_field, false);
    }

    protected function deactivating($setting_field, $new_settings): bool
    {
        return $this->option($setting_field) and !Settings::get_option($new_settings, $setting_field, false);
    }
}