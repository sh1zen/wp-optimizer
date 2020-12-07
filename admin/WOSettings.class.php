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

        //necessary check
        if (!$this->settings)
            $this->settings = array();

        add_action('admin_init', array($this, 'register_hooks'));
    }

    public static function check($settings, $key)
    {
        return isset($settings[$key]) and $settings[$key];
    }

    public static function getInstance()
    {
        if (!isset(self::$_instance)) {
            self::$_instance = new self();
        }

        return self::$_instance;
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
                    echo $this->generateHTML_tabpan($modules);
                }
                else {
                    echo "<h2>" . __("No modules enabled. To enable them go <a href='" . admin_url('admin.php?page=wpopt-settings') . "'>here</a>.", 'wpopt') . "</h2>";
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

            $object = WOModuleHandler::get_module_instance($module);

            if (is_null($object))
                continue;

            $field = array(
                'id'          => "settings-{$module['slug']}",
                'tab-title'   => $module['name'],
                'panel-title' => $module['name'] . " setup",
            );

            $field['callback'] = array($object, 'render_settings');

            $fields[] = $field;
        }

        return wpopt_generateHTML_tabs_panels($fields);
    }

    public function render_core_settings()
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
                    echo $this->generateHTML_tabpan($modules);
                }

                ?>
            </block>
        </section>
        <?php
    }

    public function activate()
    {
        $options = get_option(self::$option_name, array());

        if (empty($options)) {

            /**
             * Load all modules to be allow them to set up their options
             */
            WOModuleHandler::getInstance()->setup_modules('all');

            $this->reset_options($this->settings);
        }
    }

    public function reset_options($options = array())
    {
        $this->settings = $options;
        return update_option(self::$option_name, $options);
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

    /**
     * @param string $context
     * @param array $default
     * @param bool $update -> if no option were found, update theme, with defaults values
     * @return array|object|string
     */
    public function get_settings($context = '', $default = array(), $update = false)
    {
        if (empty($context))
            return $this->settings;

        if (isset($this->settings[$context]))
            $options = $this->settings[$context];
        else {
            if ($update) {
                $this->update_settings($default, $context);
            }
            $options = array();
        }

        return wp_parse_args($options, $default);
    }

    public function update_settings($option_data, $context)
    {
        if (empty($context))
            return false;

        if (!isset($this->settings[$context]))
            $this->settings[$context] = array();

        $this->settings[$context] = wp_parse_args($option_data, $this->settings[$context]);

        return $this->reset_options($this->settings);
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

        return $this->reset_options($settings);
    }
}
