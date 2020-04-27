<?php

if (!defined('ABSPATH'))
    exit;

class wpoptSettings
{

    private static $_instance;

    private static $defaults = array(
        'cron'       => array(
            'clear-time'  => '05:00:00',
            'active'      => false,
            'images'      => false,
            'database'    => false,
            'save_report' => false
        ),
        'mime-types' => array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'gif'          => 'image/gif',
            'png'          => 'image/png',
            'ico'          => 'image/x-icon',
            'pjpeg'        => 'image/pjpeg'
        )
    );

    private $settings;

    private $option_name;

    public function __construct($option_name = 'wpopt')
    {
        $this->option_name = $option_name;

        $this->register_hooks();

        $this->settings = wp_parse_args(get_option($option_name, array()), self::$defaults);
    }

    private function register_hooks()
    {
        register_setting('wpopt-settings', $this->option_name, array(
            'type'              => 'array',
            'sanitize_callback' => array($this, 'validate')
        ));
    }

    public static function getInstance()
    {
        if (!isset(self::$_instance)) {
            self::Initialize();
        }

        return self::$_instance;
    }

    public static function Initialize()
    {
        return self::$_instance = new self();
    }

    public function checkOption()
    {
        if (!get_option($this->option_name, false)) {
            update_option($this->option_name, $this->settings);
        }
    }

    public function render_page()
    {
        $mod_handler = wpoptModuleHandler::getInstance();

        /**
         * Consider only modules with settings handlers
         */
        $modules = $mod_handler->get_modules(array('methods' => array('setting_fields')));

        settings_errors();
        ?>
        <section class="wpopt-wrap wpopt">
            <h1>WP Optimizer - Settings</h1>
            <block>
                <?php

                if (!empty($modules)) {

                    $this->generateHTML_tabs($modules);
                    $this->generateHTML_panels($modules);

                }
                ?>
            </block>
        </section>
        <?php
    }

    private function generateHTML_tabs($modules)
    {
        ?>
        <div class="ar-tabs-container" id="ar-tabs">
            <ul class="ar-tab-wrapper" aria-label="settings-menu" role="tablist">
                <?php

                $aria_selected = true;

                foreach ($modules as $module) {
                    ?>
                    <li class="ar-tab" role="tab" tabindex="0" aria-controls="settings-<?php echo $module['slug']; ?>"
                        aria-selected="<?php if ($aria_selected) {
                            echo 'true';
                            $aria_selected = false;
                        }
                        else echo 'false'; ?>">
                        <?php _e($module['menu_title'], 'wpopt'); ?>
                    </li>
                    <?php
                }

                ?>
            </ul>
        </div>
        <?php
    }

    private function generateHTML_panels($modules)
    {
        $mod_handler = wpoptModuleHandler::getInstance();

        $aria_hidden = false;

        foreach ($modules as $mod_name => $module) {
            ?>
            <div id="settings-<?php echo $module['slug']; ?>" class="tab-content" role="tabpanel"
                 aria-hidden="<?php if ($aria_hidden) {
                     echo 'true';
                 }
                 else {
                     echo 'false';
                     $aria_hidden = true;
                 } ?>">
                <h2><?php _e($module['menu_title'] . " setup", 'wpopt'); ?></h2>
                <p></p><?php

                $object = $mod_handler->module_object($module);

                if (!is_null($object)) {

                    if ($mod_handler->module_has_method($module, 'render_settings')) {
                        $object->render_settings();
                    }
                    else {
                        $this->generateHTML_form($module, $object->setting_fields());
                    }
                }
                ?>
            </div>
            <?php
        }
    }

    private function generateHTML_form($module, $fields)
    {
        ?>
        <form action="options.php" method="post">
            <input type="hidden" name="<?php echo $this->option_name ?>[change]" value="<?php echo $module['slug']; ?>">
            <?php
            settings_fields('wpopt-settings');
            ?>
            <table>
                <?php

                foreach ($fields as $field) {
                    ?>
                    <tr>
                        <td>
                            <p><label for="<?php echo $field['id']; ?>"><?php _e($field['name'], 'wpopt'); ?>:</label>
                                <?php
                                switch ($field['type']) {
                                    case "time":
                                        echo "<input type='time' name='{$this->option_name}[{$field['id']}]' id='{$field['id']}' value='{$field['value']}'>";
                                        break;
                                    case "checkbox":
                                        echo "<input class='apple-switch' type='checkbox' name='{$this->option_name}[{$field['id']}]' id='{$field['id']}' value='{$field['value']}'" . checked(1, $field['value'], false) . "/>";
                                        break;

                                } ?>
                            </p>
                        </td>
                    </tr>
                    <?php
                }
                ?>
            </table>
            <p class="submit">
                <input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>"/>
            </p>
        </form>

        <?php
    }

    public function validate($input)
    {
        $object = wpoptModuleHandler::getInstance()->load_module($input['change']);

        if(is_null($object))
            die();

        $valid = $this->settings[$object->inneropt_name];

        if(method_exists($object, 'validate_settings'))
        {
            $valid = $object->validate_settings($input, $valid);
        }
        else {

            foreach ($object->setting_fields() as $field) {
                switch ($field['type']) {
                    case 'checkbox':
                        $valid[$field['id']] = isset($input[$field['id']]);
                        break;

                    case 'time':
                        $valid[$field['id']] = sanitize_text_field($input[$field['id']]);
                        break;
                }
            }
        }

        $valid = array_filter($valid);

        $this->settings = wp_parse_args(array($object->inneropt_name => $valid), $this->settings);

        return $this->settings;
    }

    public function get_settings($context = '', $default = array())
    {
        if (empty($context))
            return $this->settings;

        if (isset($this->settings[$context]))
            $options = $this->settings[$context];
        else
            $options = array();

        return wp_parse_args($options, $default);
    }

    public function update_settings($option_data, $context)
    {
        if (!isset($context) or empty($context))
            return false;

        $this->settings[$context] = wp_parse_args($option_data, $this->settings[$context]);

        return update_option($this->option_name, $this->settings);
    }

    private function output_options()
    {
        if (is_array($this->settings))
            echo implode(PHP_EOL, $this->settings);
        else
            echo $this->settings;
    }

}
