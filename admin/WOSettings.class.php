<?php

class WOSettings
{
    /**
     * Plugin options name
     */
    public static $option_name = 'wpopt';

    private static $_instance;

    private $settings;

    public function __construct()
    {
        $this->settings = get_option(self::$option_name);

        if (!$this->settings)
            $this->settings = array();

        if (is_admin()) {
            add_action('admin_init', array($this, 'register_hooks'));
        }
    }

    public static function get_option($settings, $setting_path, $default = false)
    {
        // remove last separator
        $setting_path = rtrim($setting_path, '.');

        while (strlen($setting_path) > 0) {

            $pos = strpos($setting_path, '.');

            if ($pos === false)
                $pos = strlen($setting_path);

            $slug = substr($setting_path, 0, $pos);

            if (!isset($settings[$slug])) {
                return $default;
            }

            $settings = $settings[$slug];

            // update search key
            $setting_path = substr_replace($setting_path, '', 0, $pos + 1);
        }

        if (is_array($settings) or is_object($settings))
            $settings = wp_parse_args($settings, $default);

        return $settings;
    }

    /**
     * Access to settings by path -> delimiter: "."
     * @param string $setting_path
     * @param array $default
     * @param bool $update -> if no option were found, update theme, with defaults values
     * @return array|mixed|object|string
     */
    public static function get($setting_path = '', $default = array(), $update = false)
    {
        $settings = self::getInstance()->settings;

        if (empty($setting_path))
            return $settings;

        // remove last separator
        $setting_path = rtrim($setting_path, '.');

        // keep in memory
        $context = $setting_path;

        while (strlen($setting_path) > 0) {

            $pos = strpos($setting_path, '.');

            if ($pos === false)
                $pos = strlen($setting_path);

            $slug = substr($setting_path, 0, $pos);

            if (!isset($settings[$slug])) {

                if ($update) {
                    self::getInstance()->update($default, $context);
                }

                return $default;
            }

            $settings = $settings[$slug];

            // update search key
            $setting_path = substr_replace($setting_path, '', 0, $pos + 1);
        }

        if (is_array($settings) or is_object($settings))
            $settings = wp_parse_args($settings, $default);

        return $settings;
    }

    public static function getInstance()
    {
        if (!isset(self::$_instance)) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public function update($option_data, $context)
    {
        if (empty($context))
            return false;

        if (!isset($this->settings[$context]))
            $this->settings[$context] = array();

        $this->settings[$context] = wp_parse_args($option_data, $this->settings[$context]);

        return $this->reset($this->settings);
    }

    public function reset($options = array())
    {
        $this->settings = $options;
        return update_option(self::$option_name, $options);
    }

    public static function check($settings, $key, $default = false)
    {
        if (isset($settings[$key]))
            return $settings[$key];

        return $default;
    }

    public static function render_core_settings()
    {
        $mod_handler = WOModuleHandler::getInstance();

        /**
         * Consider only modules with settings handlers
         */
        $modules = $mod_handler->get_modules(array('scopes' => array('core-settings')));

        settings_errors();
        ?>
        <section class="wpopt-wrap wpopt">
            <h1><?php _e('Core Settings', 'wpopt'); ?></h1>
            <block class="wpopt">
                <?php

                if (!empty($modules)) {
                    echo self::generateHTML_tabpan($modules);
                }
                ?>
            </block>
        </section>
        <?php
    }

    private static function generateHTML_tabpan($modules)
    {
        $fields = array();

        foreach ($modules as $module) {

            $object = WOModuleHandler::get_module_instance($module);

            if (is_null($object))
                continue;

            $field = array(
                'id'          => "settings-{$module['slug']}",
                'tab-title'   => $module['name'],
                'panel-title' => $module['name'] . " setup",
                'callback'    => array($object, 'render_settings')
            );

            $fields[] = $field;
        }

        return wpopt_generateHTML_tabs_panels($fields);
    }

    public function register_hooks()
    {
        register_setting('wpopt-settings', self::$option_name, array(
            'type'              => 'array',
            'sanitize_callback' => array($this, 'validate')
        ));
    }

    public function render_modules_settings()
    {
        $mod_handler = WOModuleHandler::getInstance();

        /**
         * Consider only modules with settings handlers
         */
        $modules = $mod_handler->get_modules(array('scopes' => array('settings')));

        settings_errors();
        ?>
        <section class="wpopt-wrap wpopt">
            <h1><?php _e('Modules Settings', 'wpopt'); ?></h1>
            <block class="wpopt">
                <?php

                if (!empty($modules)) {
                    echo self::generateHTML_tabpan($modules);
                }
                else {
                    echo "<h2>" . __("No modules enabled. To enable them go <a href='" . admin_url('admin.php?page=wpopt-settings') . "'>here</a>.", 'wpopt') . "</h2>";
                }
                ?>
            </block>
        </section>
        <?php
    }

    public function activate()
    {
        $options = get_option(self::$option_name, array());

        if (!$options or empty($options)) {

            /**
             * Load all modules to be allow them to set up their options
             */
            WOModuleHandler::getInstance()->setup_modules('all');

            $this->reset($this->settings);
        }
    }

    public function validate($input)
    {
        if (!isset($input['change']))
            return $input;

        $module_slug = sanitize_text_field($input['change']);

        $object = WOModuleHandler::get_module_instance($module_slug);

        if (is_null($object))
            die();

        $valid = $object->validate_settings($input, $object->settings);

        $this->settings = wp_parse_args(array($object->slug => $valid), $this->settings);

        return $this->settings;
    }

    public function export()
    {
        return base64_encode(serialize($this->settings));
    }

    public function import($import_settings)
    {
        $settings = unserialize(base64_decode($import_settings));

        if (!$settings or !is_array($settings))
            return false;

        return $this->reset($settings);
    }
}
