<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

class Settings
{
    private $settings;

    private string $context;

    public function __construct($context)
    {
        $this->context = $context;

        $this->settings = get_option($context) ?: [];

        if (is_admin()) {
            add_action('admin_init', array($this, 'register_hooks'));
        }
    }

    public static function check($settings, $key, $default = false)
    {
        $res = self::get_option($settings, $key, null);

        return $res === null ? $default : $res;
    }

    public static function get_option($settings, $setting_path, $default = false)
    {
        // remove consecutive dots and add a last one for while loop
        $setting_path = preg_replace('#\.+#', '.', $setting_path . '.');

        while (($pos = strpos($setting_path, '.')) !== false) {

            $slug = substr($setting_path, 0, $pos);

            if (empty($slug)) {
                break;
            }

            if (!isset($settings[$slug])) {
                return $default;
            }

            $settings = $settings[$slug];

            $setting_path = substr($setting_path, $pos + 1);
        }

        if (is_array($settings) or is_object($settings)) {
            $settings = wp_parse_args($settings, $default);
        }

        return $settings;
    }

    /**
     * Access to settings by path -> delimiter: "."
     *
     * @param string $context
     * @param mixed $default
     * @param bool $update -> if no option were found, update theme, with defaults values
     *
     * @return array|mixed|object|string
     */
    public function get(string $context = '', $default = [], bool $update = false)
    {
        $res = self::get_option($this->settings, $context, null);

        if (is_null($res)) {
            if ($update) {
                $this->update($context, $default);
            }

            return $default;
        }

        if (is_array($default) and is_array($res)) {
            $res = array_merge($default, $res);
        }

        return $res;
    }

    public function update($context, $option_data, $force = false): bool
    {
        if (empty($context)) {
            return false;
        }

        // remove consecutive dots and add a last one for while loop
        $setting_path = trim(preg_replace('#\.+#', '.', $context), '.');

        $settings = &$this->settings;

        while (($pos = strpos($setting_path, '.')) !== false) {

            $slug = substr($setting_path, 0, $pos);

            if (empty($slug)) {
                break;
            }

            if (!isset($settings[$slug])) {
                $settings[$slug] = [];
            }

            $settings = &$settings[$slug];

            $setting_path = substr($setting_path, $pos + 1);
        }

        if (!isset($this->settings[$context])) {
            $settings[$setting_path] = array();
        }

        if ($force) {
            $settings[$setting_path] = $option_data;
        }
        else {
            $settings[$setting_path] = wp_parse_args($option_data, $settings[$setting_path]);
        }

        return $this->reset($this->settings);
    }

    public function reset($options = array()): bool
    {
        $this->settings = $options;

        return update_option($this->context, $options, 'yes');
    }

    public function render_core_settings(): void
    {
        /**
         * Consider only modules with settings handlers
         */
        $modules = wps($this->context)->moduleHandler->get_modules(array('scopes' => array('core-settings')), false);

        settings_errors();
        ?>
        <section class="wps-wrap wps">
            <block class="wps">
                <section class='wps-header'><h1><?php _e('Core Settings', $this->context); ?></h1></section>
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
        $fields = array();

        foreach ($modules as $module) {

            $object = wps($this->context)->moduleHandler->get_module_instance($module);

            if (is_null($object)) {
                continue;
            }

            $field = array(
                'id'          => "settings-{$module['slug']}",
                'tab-title'   => $module['name'],
                'panel-title' => $module['name'] . " setup",
                'callback'    => array($object, 'render_settings')
            );

            $fields[] = $field;
        }

        return Graphic::generateHTML_tabs_panels($fields);
    }

    public function register_hooks(): void
    {
        register_setting("$this->context-settings", $this->context, array(
            'type'              => 'array',
            'sanitize_callback' => array($this, 'validate')
        ));
    }

    public function render_modules_settings()
    {
        $mod_handler = wps($this->context)->moduleHandler;

        /**
         * Consider only modules with settings handlers
         */
        $modules = $mod_handler->get_modules(array('scopes' => array('settings')));

        settings_errors();
        ?>
        <section class="wps-wrap wps">
            <block class="wps">
                <section class='wps-header'><h1><?php _e('Modules Settings', $this->context); ?></h1></section>
                <?php

                if (!empty($modules)) {
                    echo $this->generateHTML_tabpan($modules);
                }
                else {
                    echo "<h2>" . sprintf(__("No modules enabled. To enable them go <a href='%s'>here</a>.", $this->context), admin_url("admin.php?page={$this->context}-settings")) . "</h2>";
                }
                ?>
            </block>
        </section>
        <?php
    }

    public function activate()
    {
        $options = get_option($this->context, array());

        if (empty($options)) {

            /**
             * Load all modules to be allowed them to set up their options
             */
            wps($this->context)->moduleHandler->setup_modules('all');

            $this->reset($this->settings);
        }
    }

    public function validate($input)
    {
        if (!isset($input['change'])) {
            return $input;
        }

        $object = wps($this->context)->moduleHandler->get_module_instance($input['change']);

        if (is_null($object)) {
            die();
        }

        $valid = $object->validate_settings($input);

        $this->settings = wp_parse_args(array($object->slug => $valid), $this->settings);

        add_action('shutdown', function () {

            if (isset($_REQUEST['option_page'], $_REQUEST['option_panel'])) {

                $rewriter = Rewriter::getInstance();

                $rewriter->set_fragment($_REQUEST['option_panel']);

                $rewriter->replace_path($_REQUEST['_wp_http_referer']);
            }
        });

        return $this->settings;
    }

    public function export(): string
    {
        return base64_encode(serialize($this->settings));
    }

    public function import($import_settings): bool
    {
        $settings = unserialize(base64_decode($import_settings) ?: '');

        if (!$settings or !is_array($settings)) {
            return false;
        }

        return $this->reset($settings);
    }

    public function get_context(): string
    {
        return $this->context;
    }
}
