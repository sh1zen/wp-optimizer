<?php

class WO_Module
{
    /**
     * List of Module active set
     * values: settings, admin-page, autoload, cron
     * default: empty
     */
    public $scopes = array();

    /**
     * Module status based on current user, default false
     * @var bool
     */
    public $disabled;
    /**
     * Module settings
     * @var array
     */
    public $settings;

    /**
     * Module name without prefix WOMod_
     * @var string
     */
    public $slug;

    /**
     * Default ajax response query
     * @var int
     */
    protected $ajax_limit;
    /**
     * Module default settings
     * @var array
     */
    protected $default_setting;
    /**
     * Determine if this module is on rendering process
     * @var bool
     */
    protected $on_screen;

    public function __construct($args = array())
    {
        $this->disabled = isset($args['disabled']) ? $args['disabled'] : false;

        $this->slug = strtolower(str_replace('WOMod_', '', get_class($this)));

        $this->default_setting = isset($args['settings']) ? $args['settings'] : array();

        $this->ajax_limit = isset($args['ajax_limit']) ? $args['ajax_limit'] : 100;

        $this->settings = WOSettings::getInstance()->get_settings($this->slug, $this->default_setting);

        $this->on_screen = wpopt_is_on_screen($this->slug);

        if ($this->on_screen) {
            $this->enqueue_scripts();
        }
    }

    public function enqueue_scripts()
    {
    }

    public function ajax_handler()
    {
        wp_send_json_error(
            array(
                'error' => __('WP Optimizer::ajax_handler -> empty ajax handler.', 'wpopt'),
            )
        );
    }

    public function render_settings()
    {
        if ($this->disabled) {
            ob_start();
            $this->render_disabled();
            return ob_get_clean();
        }

        $_header = $this->get_setting_content('header');
        $_footer = $this->get_setting_content('footer');
        //$_sidebar = $this->get_setting_content('sidebar');

        $option_name = WOSettings::getInstance()->option_name;

        ob_start();
        ?>
        <form action="options.php" method="post" style="max-width: 500px">
            <?php
            if ($_header)
                echo "<h3 class='wpopt-setting-header'>{$_header}</h3>";
            ?>
            <input type="hidden" name="<?php echo "{$option_name}[change]" ?>"
                   value="<?php echo $this->slug; ?>">
            <?php
            settings_fields('wpopt-settings');
            ?>
            <table class="wpopt">
                <tbody>
                <?php
                foreach ($this->setting_fields() as $field) {

                    if ($field['type'] === 'separator') {
                        echo "</tbody></table>";

                        if (isset($field['name'])) echo "<h3 class='wpopt-setting-header'>{$field['name']}</h3>";

                        echo "<table class='wpopt'><tbody>";

                        continue;
                    }
                    ?>
                    <tr>
                        <td><b><?php _e($field['name'], 'wpopt'); ?>:</b></td>
                        <td>
                            <label for="<?php echo $field['id'] ?>"></label>
                            <?php
                            switch ($field['type']) {

                                case "time":
                                    echo "<input type='time' name='{$option_name}[{$field['id']}]' id='{$field['id']}' value='{$field['value']}'>";
                                    break;

                                case "checkbox":
                                    echo "<input class='apple-switch' type='checkbox' name='{$option_name}[{$field['id']}]' id='{$field['id']}' value='{$field['value']}'" . checked(1, $field['value'], false) . "/>";
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
            <?php
            if ($_footer)
                echo "<h4 class='wpopt-setting-footer'>{$_footer}</h4>";
            ?>
        </form>
        <?php
        return ob_get_clean();
    }

    public function render_disabled()
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
    public function get_setting_content($context)
    {
        return false;
    }

    public function setting_fields()
    {
        return array();
    }

    public function render()
    {
        if ($this->disabled)
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
                    $valid[$field['id']] = isset($input[$field['id']]) ? 'true' : 'false';
                    break;

                case 'time':
                    $valid[$field['id']] = sanitize_text_field($input[$field['id']]);
                    break;

                default:
                    die("Settings failed to validate '{$field['type']}':: WO_Module -> validate_settings");
            }
        }

        return $valid;
    }
}