<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\modules;

use SHZN\core\Ajax;
use SHZN\core\ModuleHandler;
use SHZN\core\StringHelper;
use SHZN\core\Graphic;
use SHZN\core\Settings;
use SHZN\core\UtilEnv;

class Module
{
    /**
     * set the module name
     */
    public static $name = null;

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
    public $slug;

    /**
     * Current module unique identifier
     */
    protected string $hash;

    protected string $context = '';
    protected string $action_hook;

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
        if (empty($this->context)) {
            die(__('A context must be set', 'wpopt'));
        }

        $this->slug = ModuleHandler::module_slug(get_class($this), true);

        $this->hash = md5($this->context . $this->slug);

        $this->settings = shzn($this->context)->settings->get($this->slug);

        $this->action_hook = "$this->context-$this->slug-action-hook";

        if (!shzn($this->context)->moduleHandler->module_is_active($this->slug)) {
            return;
        }

        $this->init();

        // check if this module loads on cron and do a cronjob
        if (wp_doing_cron()) {

            if (in_array('cron', $this->scopes) and shzn($this->context)->cron->is_active($this->slug)) {

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
            }

            add_action('admin_notices', array($this, 'admin_notices'));

            add_action('admin_init', array($this, 'actions'));
        }
        else {
            add_action('init', array($this, 'actions'));
        }
    }

    protected function init()
    {
    }

    public function enqueue_scripts(): void
    {
        wp_enqueue_style('vendor-shzn-css');
        wp_enqueue_script('vendor-shzn-js');
    }

    public function actions()
    {
    }

    public function admin_notices()
    {
        foreach ($this->notices as $notice) {
            echo "<div class='notice notice-{$notice['status']} is-dismissible'>";
            echo "<p>{$notice['message']}</p>";
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

    public function ajax_handler($args = array())
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

        $option_name = shzn($this->context)->settings->get_context();

        ob_start();

        if (!empty($setting_fields)) {

            $_divider = true;

            ?>
            <form action="options.php" method="post" autocomplete="off" autocapitalize="off">
                <?php

                if ($_header) {
                    echo "<h3 class='shzn'>$_header</h3>";
                }

                settings_fields("$this->context-settings");
                ?>
                <input type="hidden" name="option_panel" value="settings-<?php echo $this->slug; ?>">
                <input type="hidden" name="<?php echo "{$option_name}[change]" ?>" value="<?php echo $this->slug; ?>">
                <block class="shzn-options">
                    <?php Graphic::generate_fields($setting_fields, $this->infos(), array('name_prefix' => $option_name)); ?>
                </block>
                <section class="shzn-submit">
                    <input type="submit" class="button-primary" value="<?php _e('Save Changes', $this->context) ?>"/>
                </section>
            </form>
            <?php
        }

        $_footer = $this->print_footer();

        if (!empty($_footer)) {

            if ($_divider) {
                echo "<hr class='shzn-hr'>";
            }

            echo "<section class='shzn-setting-footer'>" . $_footer . "</section>";
        }

        return ob_get_clean();
    }

    public function restricted_access($context = ''): bool
    {
        return false;
    }

    private function render_disabled()
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
    }

    public function filter_settings()
    {
        // use the new settings available after import
        $this->settings = $this->validate_settings(shzn($this->context)->settings->get($this->slug), true);
        shzn($this->context)->settings->update($this->slug, $this->settings, true);
    }

    /**
     * Provides general setting validator
     * for custom settings : override it
     */
    public function validate_settings($input, $filtering = false): array
    {
        return $this->settings_validator(UtilEnv::array_flatter($this->setting_fields(), true), $input, $filtering);
    }

    /**
     * @param $status -> wrong, success, error, info
     * @param $message
     */
    protected function add_notices($status, $message)
    {
        $this->notices[] = array('status' => $status, 'message' => $message);
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
        $args = array_merge([
            'value'         => null,
            'default_value' => '',
            'allow_empty'   => true,
            'parent'        => false,
            'depend'        => false,
            'placeholder'   => '',
            'list'          => ''
        ], $args);

        if ($id or $type === 'link') {
            $value = is_null($args['value']) ? $this->option($id, $args['default_value']) : $args['value'];
        }
        else {
            $value = '';
        }

        if (empty($value) and !$args['allow_empty']) {
            $value = $args['default_value'];
        }

        return [
            'type'        => $type,
            'name'        => $name,
            'id'          => $id,
            'value'       => $value,
            'parent'      => $args['parent'],
            'depend'      => $args['depend'],
            'placeholder' => $args['placeholder'],
            'list'        => $args['list']
        ];
    }

    public function option($path_name = '', $default = false)
    {
        return Settings::get_option($this->settings, $path_name, $default);
    }

    protected function activating($setting_field, $new_settings): bool
    {
        return !$this->option($setting_field) and isset($new_settings[$setting_field]);
    }

    protected function deactivating($setting_field, $new_settings): bool
    {
        return $this->option($setting_field) and !isset($new_settings[$setting_field]);
    }
}