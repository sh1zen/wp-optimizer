<?php

namespace WPOptimizer\core;

class ModuleHandler
{
    private static $_instance;

    private $modules;

    private function __construct()
    {
        $this->init_modules();
    }

    private function init_modules()
    {
        $this->modules = array();

        foreach (glob(WPOPT_MODULES . '*.php') as $file) {
            $module_name = basename($file, '.class.php');

            $this->modules[] = array(
                'slug' => $module_name,
                'name' => self::get_module_name($module_name, $module_name)
            );
        }
    }

    private static function get_module_name($module, $default = '')
    {
        $module_name = $default;

        if ($class = self::module2classname($module)) {

            if (isset($class::$name) and !is_null($class::$name))
                $module_name = $default;
        }

        return ucwords(str_replace('_', ' ', $module_name));
    }

    /**
     * Convert module name or wp-admin page slug to class name if exist
     * @param $name
     * @return bool|string
     */
    public static function module2classname($name)
    {
        $base_name = self::module_slug($name);


        $class = "\WPOptimizer\modules\Mod_" . $base_name;

        if (file_exists(WPOPT_MODULES . "{$base_name}.class.php")) {

            include_once WPOPT_MODULES . "{$base_name}.class.php";
        }

        if (!class_exists($class))
            return false;

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

        $name = preg_replace("/(\\\?wpoptimizer\\\modules\\\)?(mod_|mod-)?/i", '', $name);

        if ($remove_namespace) {
            $name = basename(str_replace('\\', DIRECTORY_SEPARATOR, $name));
        }

        return strtolower($name);
    }

    public static function getInstance()
    {
        if (!isset(self::$_instance)) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    /**
     * Return instance of the module
     * @param $module
     * @return object|null
     */
    public static function get_module_instance($module)
    {
        return self::load_module($module);
    }

    /**
     * Accept module name or wp-admin page name
     * @param string|array $name
     * @return \WPOptimizer\modules\Module
     */
    private static function load_module($name)
    {
        $class = self::module2classname($name);

        if (!$class)
            return null;

        if ($object = Cache::getInstance()->get_cache($class, 'modules_object'))
            return $object;

        $object = new $class();

        Cache::getInstance()->set_cache($class, $object, 'modules_object');

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
    public function setup_modules($scope, $only_active = true)
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
        $module_settings = Settings::get('modules_handler');

        if (isset($module_settings[$module_slug]) and !$module_settings[$module_slug])
            return false;

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
        if (is_null($module) or empty($method))
            return false;

        if (!is_array($method))
            $method = array($method);

        if (!$class = self::module2classname($module))
            return false;

        $methods = array_intersect($method, get_class_methods($class));

        if ($compare === 'AND')
            return count($methods) === count($method);
        else
            return !empty($methods);
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
        if (is_null($module) or empty($scope))
            return false;

        if (!is_array($scope))
            $scope = array($scope);

        if (!$class = self::module2classname($module))
            return false;

        $found = array_intersect($scope, get_class_vars($class)['scopes']);

        if ($compare === 'AND')
            return count($found) === count($scope);
        else
            return !empty($found);
    }
}