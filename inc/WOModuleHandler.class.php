<?php

class WOModuleHandler
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
                'name' => self::get_module_name($module_name, ucfirst($module_name))
            );
        }
    }

    private static function get_module_name($module, $default = '')
    {
        if (!$class = self::module2classname($module))
            return $default;

        if (!isset($class::$name))
            return $default;

        return is_null($class::$name) ? $default : $class::$name;
    }

    /**
     * Convert module name or wp-admin page slug to class name if exist
     * @param $name
     * @return bool|string|string[]
     */
    public static function module2classname($name)
    {
        if (is_array($name)) {
            // get the page name of the module
            $name = $name['slug'];
        }

        $name = sanitize_text_field($name);

        $file_name = str_replace('womod-', '', $name);

        // to allow also class name as parameter
        $file_name = str_replace('WOMod_', '', $file_name);

        $class = "WOMod_" . $file_name;

        if (file_exists(WPOPT_MODULES . "{$file_name}.class.php")) {

            include_once WPOPT_MODULES . "{$file_name}.class.php";
        }

        if (!class_exists($class))
            return false;

        return $class;
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
     * @return WO_Module
     */
    private static function load_module($name)
    {
        $class = self::module2classname($name);

        if (!$class)
            return null;

        if ($object = WOCache::getInstance()->get_cache($class, 'modules_object'))
            return $object;

        $object = new $class();

        WOCache::getInstance()->set_cache($class, $object, 'modules_object');

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
     */
    public function setup_modules($scope)
    {
        foreach ($this->modules as $index => $module) {

            if ($this->module_has_scope($module['slug'], $scope) or $scope === 'all') {
                self::load_module($module['slug']);
            }
        }
    }

    /**
     * Accepts module name or wp-admin page name
     *
     * @param $module
     * @param $scope
     * @param string $compare
     * @return bool
     */
    private function module_has_scope($module, $scope, $compare = 'AND')
    {
        if (is_null($module) or empty($scope))
            return false;

        if (!is_array($scope))
            $scope = array($scope);

        if (!$class = self::module2classname($module))
            return false;

        $found = array_intersect($scope, get_class_vars($class)['scopes']);

        if($compare === 'AND')
            return count($found) === count($scope);
        else
           return !empty($found);
    }

    /**
     * Get all modules filtered by:
     * methods -> available method
     * status -> 'autoload'
     *
     * @param array $filters
     * @return array
     */
    public function get_modules($filters = array())
    {
        if (empty($filters))
            return $this->modules;

        $filters = array_merge(array(
            'methods' => false,
            'scopes'  => false,
            'vars'    => false,
            'compare' => 'AND'
        ), $filters);

        $modules = array();

        foreach ($this->modules as $index => $module) {

            if ($filters['methods']) {
                if ($this->module_has_method($module, $filters['methods']))
                    $modules[] = $module;
            }

            if ($filters['vars']) {
                if ($this->module_has_var($module, $filters['vars']))
                    $modules[] = $module;
            }

            if ($filters['scopes']) {
                if ($this->module_has_scope($module, $filters['scopes'], $filters['compare']))
                    $modules[] = $module;
            }
        }

        return $modules;
    }

    /**
     * Accepts module name or wp-admin page name
     *
     * @param $module
     * @param $method
     * @return bool
     */
    private function module_has_method($module, $method)
    {
        if (is_null($module) or empty($method))
            return false;

        if (!is_array($method))
            $method = array($method);

        if (!$class = self::module2classname($module))
            return false;

        $methods = array_intersect($method, get_class_methods($class));

        return !empty($methods);
    }

    /**
     * Accepts module name or wp-admin page name
     *
     * @param $module
     * @param $var
     * @return bool
     */
    private function module_has_var($module, $var)
    {
        if (is_null($module) or empty($var))
            return false;

        if (!is_array($var))
            $var = array($var);

        if (!$class = self::module2classname($module))
            return false;

        $methods = array_intersect($var, get_class_vars($class));

        return !empty($methods);
    }
}