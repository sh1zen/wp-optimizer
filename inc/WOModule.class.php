<?php

class WOModule
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
     * settings      -> to display settings in wpopt-modules-options page,
     *                  load occurs when module setting page is rendering
     * core-settings -> to display settings in wpopt-settings page,
     *                  load occurs when setting page is rendering
     * admin-page    -> to display an admin page, load occurs after admin_menu hook
     *
     * default: empty - never loaded
     */
    public $scopes = array();

    /**
     * @todo remove
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
     * Module name without prefix WOMod_
     * @var string
     */
    public $slug;

    /**
     * keep a list of notices to display for current module
     */
    private $notices = array();

    public function __construct($args = array())
    {
        $this->slug = strtolower(str_replace('WOMod_', '', get_class($this)));

        $default_setting = isset($args['settings']) ? $args['settings'] : array();

        $this->settings = WOSettings::get($this->slug, $default_setting, true);

        // check if this module loads on cron and do a cronjob
        if (is_admin() or wp_doing_cron()) {

            if (in_array('cron', $this->scopes) and method_exists($this, 'cron_handler')) {

                $cron_defaults = isset($args['cron_settings']) ? $args['cron_settings'] : array();

                $this->cron_settings = WOCron::get_settings($this->slug, $cron_defaults);

                add_filter('wpopt_validate_cron_settings', array($this, 'cron_validate_settings'), 10, 2);
                add_filter('wpopt_cron_settings_fields', array($this, 'cron_setting_fields'), 10, 1);

                if (WOSettings::check($this->cron_settings, 'active')) {

                    add_action('wpopt_exec_cron', array($this, 'cron_handler'), 10, 1);
                }
            }

            if (is_admin()) {

                if (wpopt_is_on_screen($this->slug)) {
                    $this->enqueue_scripts();
                }

                add_action('admin_notices', array($this, 'admin_notices'));
            }
        }

        add_action('init', array($this, 'handle_process_custom_actions'));
    }

    public function enqueue_scripts()
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

    /**
     * check whether there is a POST action request for current module
     */
    public function handle_process_custom_actions()
    {
        if (isset($_POST["wpopt-{$this->slug}-custom-action"])) {

            $action_allowed = wp_verify_nonce($_POST["wpopt-{$this->slug}-custom-action"], "wpopt-{$this->slug}-custom-action");
            $response = false;

            $action = sanitize_text_field($_POST["action"]);

            if ($action_allowed) {
                $response = $this->process_custom_actions($action, $_POST);
            }

            if ($response)
                $this->add_notices('success', __('Action was correctly executed', 'wpopt'));
            else
                $this->add_notices('warning', __('Action execution failed', 'wpopt'));
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
        return $valid;
    }

    public function cron_setting_fields($cron_settings)
    {
        return $cron_settings;
    }

    public function ajax_handler($args = array())
    {
        wp_send_json_error(
            array(
                'error' => __('WP Optimizer::ajax_handler -> empty ajax handler for ' . $this->slug, 'wpopt'),
            )
        );
    }

    public function render_settings()
    {
        if ($this->restricted_access('settings')) {
            ob_start();
            $this->render_disabled();
            return ob_get_clean();
        }

        $_header = $this->setting_form_templates('header');
        $_footer = $this->setting_form_templates('footer');

        $_divider = false;

        $_setting_fields = $this->setting_fields();

        $option_name = WOSettings::$option_name;

        ob_start();

        if (!empty($_setting_fields)) {

            $_divider = true;

            ?>
            <form id="wpopt-uoptions" action="options.php" method="post">
                <?php

                if ($_header) {
                    echo "<h3 class='wpopt-setting-header'>{$_header}</h3>";
                }

                settings_fields('wpopt-settings');
                ?>
                <input type="hidden" name="<?php echo "{$option_name}[change]" ?>" value="<?php echo $this->slug; ?>">
                <table class="wpopt wpopt-settings">
                    <tbody>
                    <?php

                    wpopt_generate_fields($_setting_fields, array('name_prefix' => $option_name));

                    ?>
                    </tbody>
                </table>
                <p class="submit wpopt-submit">
                    <input type="submit" class="button-primary" value="<?php _e('Save Changes', 'wpopt') ?>"/>
                </p>
            </form>
            <?php
        }

        if (!empty($_footer)) {

            if ($_divider)
                echo "<hr class='wpopt-hr'>";

            $_divider = true;

            echo "<section class='wpopt-setting-footer'>" . $_footer . "</section>";
        }

        $custom_action_form = $this->custom_actions_form();

        if (!empty($custom_action_form)) {

            if ($_divider)
                echo "<hr class='wpopt-hr'>";

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
        <block><h2><?php _e('This Module is disabled for you or for your settings.', 'wpopt'); ?></h2></block>
        <?php
    }

    /**
     * Provides the setting page content
     * header, footer, sidebar
     *
     * @param $context
     * @return bool
     */
    protected function setting_form_templates($context)
    {
        return '';
    }

    protected function setting_fields()
    {
        return array();
    }

    private function custom_actions_form()
    {
        $options = $this->custom_actions();

        if (empty($options))
            return '';

        ob_start();

        foreach ($options as $option) {
            ?>
            <form class="wpopt-custom-action" method="POST">
                <?php

                wpopt_generate_field(array(
                    'type'    => 'hidden',
                    'id'      => "wpopt-{$this->slug}-custom-action",
                    'value'   => wp_create_nonce("wpopt-{$this->slug}-custom-action"),
                    'context' => 'nonce'
                ));

                $option['classes'] = "button {$option['button_types']} button-large";
                $option['context'] = "action";

                echo "<p>" . wpopt_generate_field($option, false) . "</p>";
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
        if ($this->restricted_access('render-admin'))
            $this->render_disabled();
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
        foreach ($this->setting_fields() as $field) {

            switch ($field['type']) {
                case 'checkbox':
                    $value = isset($input[$field['id']]);
                    break;

                case 'time':
                case 'text':
                case 'hidden':
                    $value = sanitize_text_field($input[$field['id']]);
                    break;

                case 'number':
                case 'numeric':
                    $value = intval($input[$field['id']]);
                    break;

                default:
                    continue 2;
            }

            $_valid = &$valid;
            foreach (explode('.', $field['id']) as $field_id)
            {
                if(!isset($_valid[$field_id]))
                    $_valid[$field_id] = array();

                $_valid = &$_valid[$field_id];
            }

            $_valid = $value;
        }

        return (array)$valid;
    }

    protected function activating($setting_field, $new_settings)
    {
        return !$this->option($setting_field) and isset($new_settings[$setting_field]);
    }

    protected function deactivating($setting_field, $new_settings)
    {
        return $this->option($setting_field) and !isset($new_settings[$setting_field]);
    }

    public function option($path_name = '', $default = false)
    {
        return WOSettings::get_option($this->settings, "{$path_name}", $default);
    }

    protected function cache_get($key, $group = 'wo_module', $default = false)
    {
        return WOCache::getInstance()->get_cache($key, $group, $default);
    }

    protected function cache_set($key, $data, $group = 'wo_module', $force = false)
    {
        return WOCache::getInstance()->set_cache($key, $data, $group, $force);
    }
}