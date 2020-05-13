<?php

if (!defined('ABSPATH'))
    exit;

class WOSettings
{
    private static $_instance;

    private static $defaults = array(
        'mime-types' => array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'gif'          => 'image/gif',
            'png'          => 'image/png',
            'ico'          => 'image/x-icon',
            'pjpeg'        => 'image/pjpeg'
        )
    );

    /**
     * Plugin options name
     */
    public $option_name;

    private $settings;

    public function __construct()
    {
        $this->option_name = 'wpopt';

        $this->register_hooks();

        $this->settings = wp_parse_args(get_option($this->option_name, array()), self::$defaults);
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
            return self::Initialize();
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

    public function render()
    {
        $mod_handler = WOModuleHandler::getInstance();

        /**
         * Consider only modules with settings handlers
         */
        $modules = $mod_handler->get_modules(array('scopes' => array('settings')));

        settings_errors();
        ?>
        <section class="wpopt-wrap wpopt">
            <h1><?php _e('WP Optimizer - Settings', 'wpopt'); ?></h1>
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

    private function generateHTML_tabpan($modules)
    {
        $mod_handler = WOModuleHandler::getInstance();

        $fields = array();

        foreach ($modules as $module) {

            $object = $mod_handler->module_object($module);

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

    public function validate($input)
    {
        if (!isset($input['change']))
            return $input;

        $module_slug = sanitize_text_field($input['change']);

        $object = WOModuleHandler::getInstance()->module_object($module_slug);

        if (is_null($object))
            die();

        $valid = $object->validate_settings($input, $object->settings);

        $valid = array_filter($valid);

        $this->settings = wp_parse_args(array($object->slug => $valid), $this->settings);

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
