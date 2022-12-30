<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C)  2022
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core;

use SHZN\modules\Module;

class ModuleHandler
{
    private $modules_path;

    private $modules;

    private $module_settings;

    private $context;

    public function __construct($load_path, $context)
    {
        $this->context = $context;
        $this->modules_path = $load_path;
        $this->module_settings = shzn($context)->settings->get('modules_handler', []);

        $this->init_modules($this->modules_path);
    }

    private function init_modules($load_path)
    {
        $this->modules = array();

        foreach (glob($load_path . '*.php') as $file) {

            $module_name = basename($file, '.class.php');

            $this->modules[] = array(
                'slug' => $module_name,
                'name' => $this->get_module_name($module_name, $module_name)
            );
        }
    }

    private function get_module_name($module, $default = '')
    {
        $module_name = $default;

        if ($class = $this->module2classname($module)) {

            if (!empty($class::$name)) {
                $module_name = $class::$name;
            }
        }

        return ucwords(str_replace('_', ' ', $module_name));
    }

    /**
     * Convert module name or wp-admin page slug to class name if exist
     * @param $name
     * @return bool|string
     */
    public function module2classname($name)
    {
        $base_name = self::module_slug($name, true);

        $class = shzn($this->context)->cache->get($base_name, 'modules-handler', null);

        if (is_null($class)) {

            $class = false;

            if (file_exists($this->modules_path . "{$base_name}.class.php")) {

                $namespace = include_once $this->modules_path . "{$base_name}.class.php";

                if (class_exists("{$namespace}\\Mod_" . $base_name)) {
                    $class = "{$namespace}\\Mod_" . $base_name;
                }
            }

            shzn($this->context)->cache->set($base_name, $class, 'modules-handler');
        }

        return $class;
    }

    public static function module_slug($name, $remove_namespace = false)
    {
        if (is_array($name) and isset($name['slug'])) {
            // get the page name of the module
            $name = $name['slug'];
        }

        if (!is_string($name))
            return false;

        // remove everything that is not a text char or - \ / _
        $name = preg_replace('/[^a-z\/\\\_-]/i', '', (string)$name);

        $name = strtolower($name);

        if ($remove_namespace) {
            $name = basename(str_replace('\\', DIRECTORY_SEPARATOR, $name));
        }

        return preg_replace("/(mod_|mod-)?/", '', $name);
    }

    /**
     * Return instance of the module
     * @param $module
     * @return object|null
     */
    public function get_module_instance($module)
    {
        return $this->load_module($module);
    }

    /**
     * Accept module name or wp-admin page name
     * @param string|array $name
     * @return Module
     */
    private function load_module($name)
    {
        $class = $this->module2classname($name);

        if (!$class)
            return null;

        if ($object = shzn($this->context)->cache->get($class, 'modules_object'))
            return $object;

        $object = new $class();

        shzn($this->context)->cache->set($class, $object, 'modules_object');

        return $object;
    }

    /**
     * Load active modules so they can perform their actions
     * Some modules can be loaded only if requested.
     *
     * The activation of a module is set by code.
     * If a user disable some modules, they will be loaded anyway.
     * Each module has to handle user settings
     * @param string $scope
     * @param bool $only_active
     */
    public function setup_modules(string $scope, bool $only_active = true)
    {
        foreach ($this->get_modules(array('scopes' => $scope), $only_active) as $index => $module) {

            self::load_module($module['slug']);
        }
    }

    /**
     * Get all modules filtered by:
     * methods -> available method
     * status -> 'autoload'
     *
     * @param array $filters
     * @param bool $only_active
     * @return array
     */
    public function get_modules($filters = array(), $only_active = true)
    {
        $modules = array();

        if ($filters === 'all')
            $filters = array('scopes' => 'all');

        $filters = array_merge(array(
            'methods' => false,
            'scopes'  => false,
            'excepts' => false,
            'compare' => 'AND'
        ), $filters);

        if ($filters['excepts'] and !($filters['scopes'] or $filters['methods']))
            $filters['scopes'] = 'all';

        foreach ($this->modules as $index => $module) {

            if ($only_active and !$this->module_is_active($module['slug'])) {
                continue;
            }

            if ($filters['excepts'] and in_array($module['slug'], (array)$filters['excepts'])) {
                continue;
            }

            if ($filters['scopes'] === 'all') {
                $modules[] = $module;
            }
            else {

                if ($filters['methods']) {
                    if ($this->module_has_method($module, $filters['methods'], $filters['compare']))
                        $modules[] = $module;
                }

                if ($filters['scopes']) {
                    if ($this->module_has_scope($module, $filters['scopes'], $filters['compare']))
                        $modules[] = $module;
                }
            }
        }

        return $modules;
    }

    /**
     * check if passed module slug has settings and if active parameter is true
     * @param $module_slug
     * @return bool
     */
    private function module_is_active($module_slug)
    {
        if (isset($this->module_settings[$module_slug]) and !$this->module_settings[$module_slug]) {
            return false;
        }

        return true;
    }

    /**
     * Accepts module name or wp-admin page name
     *
     * @param $module
     * @param $method
     * @param string $compare
     * @return bool
     */
    public function module_has_method($module, $method, $compare = 'AND')
    {
        if (is_null($module) or empty($method)) {
            return false;
        }

        if (!is_array($method)) {
            $method = array($method);
        }

        if (!$class = self::module2classname($module)) {
            return false;
        }

        $methods = array_intersect($method, get_class_methods($class));

        return ($compare === 'AND') ? (count($methods) === count($method)) : !empty($methods);
    }

    /**
     * Accepts module name or wp-admin page name
     *
     * @param $module
     * @param $scope
     * @param string $compare
     * @return bool
     */
    public function module_has_scope($module, $scope, $compare = 'AND')
    {
        if (is_null($module) or empty($scope)) {
            return false;
        }

        if (!is_array($scope)) {
            $scope = array($scope);
        }

        if (!$class = self::module2classname($module)) {
            return false;
        }

        $found = array_intersect($scope, get_class_vars($class)['scopes']);

        return ($compare === 'AND') ? (count($found) === count($scope)) : !empty($found);
    }
}