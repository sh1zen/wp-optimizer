<?php

class WO_Module
{
    /**
     * set the module name
     */
    public static $name = null;

    /**
     * List of Module loading contests
     *
     * cron -> to be loaded in cron context,
     *         to execute a cronjob event cron_handler method must be added
     * ajax -> to be loaded in ajax context,
     *         to response to an ajax request ajax_handler method must be added
     * admin -> to be loaded in admin context, to process custom actions, must be set
     * web-view -> to be loaded in website view context
     * autoload -> to be loaded in every context
     *
     * settings -> used to display settings in wpopt-settings page,
     *             load occurs when setting page is rendering
     * admin-page -> to be loaded if needs a admin page, load occurs after admin_menu hook
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
     * Module name without prefix WOMod_
     * @var string
     */
    public $slug;

    /**
     * Determine if this module is on rendering process
     * @var bool
     */
    protected $on_screen;

    /**
     * keep a list of notices to display for current module
     */
    protected $notices = array();

    public function __construct($args = array())
    {
        $this->slug = strtolower(str_replace('WOMod_', '', get_class($this)));

        $default_setting = isset($args['settings']) ? $args['settings'] : array();

        $this->settings = WOSettings::getInstance()->get_settings($this->slug, $default_setting, true);

        // check if this module loads on cron and do a cronjob
        if (in_array('cron', $this->scopes) and method_exists($this, 'cron_handler')) {

            $cron_defaults = isset($args['cron_settings']) ? $args['cron_settings'] : array();

            $this->cron_settings = WOCron::get_settings($this->slug, $cron_defaults);

            add_filter('wpopt_validate_cron_settings', array($this, 'cron_validate_settings'), 10, 2);
            add_filter('wpopt_cron_settings_fields', array($this, 'cron_setting_fields'), 10, 1);

            if (WOSettings::check($this->cron_settings, 'active')) {

                add_action('wpopt_exec_cron', array($this, 'cron_handler'), 10, 1);
            }
        }

        //for plugin with admin-page
        $this->on_screen = wpopt_is_on_screen($this->slug);

        if ($this->on_screen) {
            $this->enqueue_scripts();
        }

        add_action('init', array($this, 'handle_process_custom_actions'), 10, 0);

        add_action('admin_notices', array($this, 'admin_notices'), 10, 0);
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
                $this->notices[] = array('status' => 'success', 'message' => __('Action was correctly executed', 'wpopt'));
            else
                $this->notices[] = array('status' => 'warning', 'message' => __('Action execution failed', 'wpopt'));
        }
    }

    protected function process_custom_actions($action, $options)
    {
        return false;
    }

    public function cron_validate_settings($valid, $input)
    {
        return $valid;
    }

    public function cron_setting_fields($cron_settings)
    {
        return $cron_settings;
    }

    public function ajax_handler()
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

        $_header = $this->get_setting_form_content('header');
        $_footer = $this->get_setting_form_content('footer');

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
                    foreach ($_setting_fields as $field) {

                        if ($field['type'] === 'divide') {
                            echo "<tr class='blank-row'></tr>";
                            continue;
                        }
                        elseif ($field['type'] === 'separator') {
                            echo "</tbody></table>";

                            if (isset($field['name']))
                                echo "<h3 class='wpopt-setting-header'>{$field['name']}</h3>";

                            echo "<table class='wpopt wpopt-settings'><tbody>";

                            continue;
                        }
                        elseif ($field['type'] === 'hidden') {
                            echo "<input type='hidden' name='{$option_name}[{$field['id']}]' id='{$field['id']}' value='{$field['value']}'>";
                            continue;
                        }
                        ?>
                        <tr>
                            <td class="option"><b><?php _e($field['name'], 'wpopt'); ?>:</b></td>
                            <td class="value">
                                <label for="<?php echo $field['id'] ?>"></label>
                                <?php
                                switch ($field['type']) {

                                    case "time":
                                        echo "<input type='time' name='{$option_name}[{$field['id']}]' id='{$field['id']}' value='{$field['value']}'>";
                                        break;

                                    case "text":
                                    case "checkbox":

                                        $_field_html_args = '';

                                        switch ($field['type']) {

                                            case "checkbox":
                                                $_field_html_args .= " class='apple-switch'";
                                        }

                                        echo "<input {$_field_html_args} type='{$field['type']}' name='{$option_name}[{$field['id']}]' id='{$field['id']}' value='{$field['value']}'" . checked(1, $field['value'], false) . "/>";
                                        break;

                                    case "textarea":
                                        echo "<textarea rows='4' cols='50' type='{$field['type']}' name='{$option_name}[{$field['id']}]' id='{$field['id']}'" . checked(1, $field['value'], false) . "/>{$field['value']}</textarea>";

                                        break;
                                } ?>

                            </td>
                        </tr>
                        <?php
                    }
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

    protected function restricted_access($context = '')
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
    protected function get_setting_form_content($context)
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
                <input type="hidden" name="<?php echo "wpopt-{$this->slug}-custom-action"; ?>"
                       value="<?php echo wp_create_nonce("wpopt-{$this->slug}-custom-action"); ?>">
                <?php

                $option = array_merge(array(
                    'before'       => false,
                    'name'         => '',
                    'value'        => '',
                    'button_types' => '',
                ), $option);

                echo "<p>";

                if ($option['before']) {
                    echo "<block class='wpopt-options--before'>{$option['before']}</block>";
                }

                echo "<input name='action' type='hidden' value='{$option['name']}'>";

                echo "<input name='{$option['name']}' type='submit' value='{$option['value']}' class='button {$option['button_types']} button-large'>";

                echo '</p>';
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
                    $valid[$field['id']] = isset($input[$field['id']]);
                    break;

                case 'time':
                case 'text':
                case 'hidden':
                    $valid[$field['id']] = sanitize_text_field($input[$field['id']]);
                    break;

                default:
                    die("Settings failed to validate '{$field['type']}':: WO_Module -> validate_settings");
            }
        }

        return $valid;
    }

    protected function activating($setting_field, $new_settings)
    {
        return !WOSettings::check($this->settings, $setting_field) and isset($new_settings[$setting_field]);
    }

    protected function deactivating($setting_field, $new_settings)
    {
        return WOSettings::check($this->settings, $setting_field) and !isset($new_settings[$setting_field]);
    }
}