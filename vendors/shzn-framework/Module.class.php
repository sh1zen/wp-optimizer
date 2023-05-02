<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\modules;

use SHZN\core\Ajax;
use SHZN\core\StringHelper;
use SHZN\core\Graphic;
use SHZN\core\Settings;

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
    public $scopes = array();

    /**
     * Module settings
     * @var array
     */
    public $settings;

    /**
     * Module Cron settings
     * @var array
     */
    public $cron_settings;

    /**
     * Module name without prefix Mod_
     * @var string
     */
    public $slug;

    /**
     * keep a list of notices to display for current module
     */
    private $notices = array();

    private $context;

    public function __construct($context, $args = array())
    {
        $this->context = $context;

        $this->slug = shzn($this->context)->moduleHandler->module_slug(get_class($this), true);

        $default_setting = $args['settings'] ?? array();

        $this->settings = shzn($this->context)->settings->get($this->slug, $default_setting, true);

        // check if this module loads on cron and do a cronjob
        if (is_admin() or wp_doing_cron()) {

            if (in_array('cron', $this->scopes)) {

                $cron_defaults = $args['cron_settings'] ?? array();

                $this->cron_settings = shzn($this->context)->cron->get_settings($this->slug, $cron_defaults);

                add_filter("{$this->context}_validate_cron_settings", array($this, 'cron_validate_settings'), 10, 2);
                add_filter("{$this->context}_cron_settings_fields", array($this, 'cron_setting_fields'), 10, 1);

                if (Settings::check($this->cron_settings, 'active')) {

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
            }
        }

        add_action('init', array($this, 'handle_process_custom_actions'));
    }

    public function enqueue_scripts()
    {
        wp_enqueue_style('vendor-shzn-css');
        wp_enqueue_script('vendor-shzn-js');
    }

    public function admin_notices()
    {
        foreach ($this->notices as $notice) {
            echo "<div class='notice notice-{$notice['status']} is-dismissible'>";
            echo "<p>{$notice['message']}</p>";
            echo "</div>";
        }
    }

    /**
     * check whether there is a POST action request for current module
     */
    public function handle_process_custom_actions()
    {
        if (isset($_POST["{$this->context}-{$this->slug}-custom-action"])) {

            $action_allowed = wp_verify_nonce($_POST["{$this->context}-{$this->slug}-custom-action"], "{$this->context}-{$this->slug}-custom-action");
            $response = false;

            if ($action_allowed) {
                $action = sanitize_text_field($_POST["action"]);
                $response = $this->process_custom_actions($action, $_POST);
            }

            if ($response) {
                $this->add_notices('success', __('Action was correctly executed', $this->context));
            }
            else {
                $this->add_notices('warning', __('Action execution failed', $this->context));
            }
        }
    }

    protected function process_custom_actions($action, $options)
    {
        return false;
    }

    /**
     * @param $status -> wrong, success, error, info
     * @param $message
     */
    protected function add_notices($status, $message)
    {
        $this->notices[] = array('status' => $status, 'message' => $message);
    }

    public function cron_validate_settings($valid, $input)
    {
        return $this->internal_validate_settings($this->cron_setting_fields(), $input, $valid);
    }

    private function internal_validate_settings($settings, $input, $valid)
    {
        foreach ($settings as $field) {

            switch ($field['type']) {
                case 'checkbox':
                    $value = isset($input[$field['id']]);
                    break;

                case 'time':
                case 'text':
                case 'hidden':
                case 'dropdown':
                case 'textarea':
                case 'upload-input':
                    $value = StringHelper::sanitize_text_field($input[$field['id']]);
                    break;

                case 'number':
                case 'numeric':
                    $value = intval($input[$field['id']]);
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

        return (array)$valid;
    }

    public function cron_setting_fields($cron_settings = [])
    {
        return $cron_settings;
    }

    public function cron_handler($args = array())
    {
        return true;
    }

    public function ajax_handler($args = array())
    {
        Ajax::response([
            'body'  => sprintf(__('Wrong ajax request for %s', $this->context), $this->slug),
            'title' => __('Request error', $this->context)
        ], 'error');
    }

    public function render_settings($filter = '')
    {
        if ($this->restricted_access('settings')) {
            ob_start();
            $this->render_disabled();
            return ob_get_clean();
        }

        $_header = $this->setting_form_templates('header');

        $_divider = false;

        $setting_fields = $this->setting_fields($filter);

        $option_name = shzn($this->context)->settings->option_name;

        ob_start();

        if (!empty($setting_fields)) {

            $_divider = true;

            ?>
            <form action="options.php" method="post" autocomplete="off" autocapitalize="off">
                <?php

                if ($_header) {
                    echo "<h3 class='shzn'>{$_header}</h3>";
                }

                settings_fields("{$this->context}-settings");
                ?>
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

        $_footer = $this->setting_form_templates('footer');

        if (!empty($_footer)) {

            if ($_divider) {
                echo "<hr class='shzn-hr'>";
            }

            $_divider = true;

            echo "<section class='shzn-setting-footer'>" . $_footer . "</section>";
        }

        $custom_action_form = $this->custom_actions_form();

        if (!empty($custom_action_form)) {

            if ($_divider) {
                echo "<hr class='shzn-hr'>";
            }

            echo $custom_action_form;
        }

        return ob_get_clean();
    }

    public function restricted_access($context = '')
    {
        return false;
    }

    private function render_disabled()
    {
        ?>
        <block><h2><?php _e('This Module is disabled for you or for your settings.', $this->context); ?></h2></block>
        <?php
    }

    /**
     * Provides the setting page content
     * header, footer, sidebar
     *
     * @param $context
     * @return string
     */
    protected function setting_form_templates($context)
    {
        return '';
    }

    protected function setting_fields($filter = '')
    {
        return array();
    }

    protected function infos()
    {
        return [];
    }

    private function custom_actions_form()
    {
        $options = $this->custom_actions();

        if (empty($options))
            return '';

        ob_start();

        foreach ($options as $option) {
            ?>
            <form class="shzn-custom-action" method="POST">
                <?php

                Graphic::generate_field(array(
                    'type'    => 'hidden',
                    'id'      => "{$this->context}-{$this->slug}-custom-action",
                    'value'   => wp_create_nonce("{$this->context}-{$this->slug}-custom-action"),
                    'context' => 'nonce'
                ));

                $option['classes'] = "button {$option['button_types']} button-large";
                $option['context'] = "action";

                Graphic::generate_field($option);
                ?>
            </form>
            <?php
        }
        return ob_get_clean();
    }

    protected function custom_actions()
    {
        return array();
    }

    public function render_admin_page()
    {
        if ($this->restricted_access('render-admin')) {
            $this->render_disabled();
        }
    }

    /**
     * Provides general setting validator
     * for custom settings : override it
     *
     * @param $input
     * @param $valid
     * @return array
     */
    public function validate_settings($input, $valid)
    {
        return $this->internal_validate_settings($this->setting_fields(), $input, $valid);
    }

    protected function group_setting_fields(...$args)
    {
        return array_merge(array_filter($args));
    }

    protected function group_setting_sections($fields, $filter = '')
    {
        $res = array();

        if (!empty($filter)) {
            foreach ((array)$filter as $_filter) {
                if (isset($fields[$_filter]))
                    $res = array_merge($res, $fields[$_filter]);
            }
        }
        else {
            $res = call_user_func_array('array_merge', array_values($fields));
        }

        return $res;
    }

    protected function setting_field($name, $id = false, $type = 'text', $args = [])
    {
        $args = array_merge([
            'value'         => false,
            'tooltips'      => false,
            'default_value' => '',
            'allow_empty'   => true,
            'parent'        => false,
            'depend'        => false,
            'placeholder'   => '',
            'list'          => ''
        ], $args);

        if ($id or $type === 'link') {
            $value = ($args['value'] === false) ? $this->option($id, $args['default_value']) : $args['value'];
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
        return Settings::get_option($this->settings, "{$path_name}", $default);
    }

    protected function activating($setting_field, $new_settings)
    {
        return !$this->option($setting_field) and isset($new_settings[$setting_field]);
    }

    protected function deactivating($setting_field, $new_settings)
    {
        return $this->option($setting_field) and !isset($new_settings[$setting_field]);
    }

    protected function cache_get($key, $group = '', $default = false)
    {
        if (empty($group)) {
            $group = "module_{$this->slug}";
        }

        return shzn($this->context)->cache->get($key, $group, $default);
    }

    protected function cache_set($key, $data, $group = '', $force = false)
    {
        if (empty($group)) {
            $group = "module_{$this->slug}";
        }

        return shzn($this->context)->cache->set($key, $data, $group, $force);
    }
}