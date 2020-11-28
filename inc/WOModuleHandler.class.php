<?php

class WOModuleHandler
{
    private static $_instance;

    private $modules;

    private $modules_object;

    private function __construct()
    {
        $this->modules_object = WOCache::getInstance();
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
        if (!isset(self::$_instance)) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    /**
     * Load active modules so they can perform their actions
     * Some modules can be loaded only if requested.
     *
     * The activation of a module is set by code.
     * If a user disable some modules, they will be loaded anyway.
     * Each module has to handle user settings
     *
     */
    public function setup_modules()
    {
        $this->modules = array();

        foreach (glob(WPOPT_MODULES . '*.php') as $file) {
            $module_name = basename($file, '.class.php');

            $this->modules[] = array(
                'slug' => $module_name,
                'name' => $this->get_module_name($module_name, ucfirst($module_name))
            );

            if ($this->module_has_scope($module_name, 'autoload')) {
                $this->load_module($module_name);
            }
        }
    }

    private function get_module_name($module, $default = '')
    {
        if (!$class = $this->module2classname($module))
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
    public function module2classname($name)
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

    /**
     * Accepts module name or wp-admin page name
     *
     * @param $module
     * @param $scope
     * @return bool
     */
    public function module_has_scope($module, $scope)
    {
        if (is_null($module) or empty($scope))
            return false;

        if (!is_array($scope))
            $scope = array($scope);

        if (!$class = $this->module2classname($module))
            return false;

        $methods = array_intersect($scope, get_class_vars($class)['scopes']);

        return !empty($methods);
    }

    /**
     * Accept module name or wp-admin page name
     * @param string|array $name
     * @return WO_Module
     */
    public function load_module($name)
    {
        $class = $this->module2classname($name);

        if (!$class)
            return null;

        if ($object = $this->modules_object->get_cache($class, 'modules_object'))
            return $object;

        $object = new $class();

        $this->modules_object->set_cache($class, $object, 'modules_object');

        return $object;
    }

    /**
     * Return instance of the module
     * @param $module
     * @return object|null
     */
    public function module_object($module)
    {
        return $this->load_module($module);
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

        $modules = $this->modules;

        $filters = array_merge(array(
            'methods' => false,
            'scopes'  => false,
            'vars'    => false,
        ), $filters);

        foreach ($modules as $name => $module) {

            if ($filters['methods']) {
                if (!$this->module_has_method($module, $filters['methods']))
                    unset($modules[$name]);
            }

            if ($filters['vars']) {
                if (!$this->module_has_var($module, $filters['vars']))
                    unset($modules[$name]);
            }

            if ($filters['scopes']) {
                if (!$this->module_has_scope($module, $filters['scopes']))
                    unset($modules[$name]);
            }
        }

        return array_filter($modules);
    }

    /**
     * Accepts module name or wp-admin page name
     *
     * @param $module
     * @param $method
     * @return bool
     */
    public function module_has_method($module, $method)
    {
        if (is_null($module) or empty($method))
            return false;

        if (!is_array($method))
            $method = array($method);

        if (!$class = $this->module2classname($module))
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
    public function module_has_var($module, $var)
    {
        if (is_null($module) or empty($var))
            return false;

        if (!is_array($var))
            $var = array($var);

        if (!$class = $this->module2classname($module))
            return false;

        $methods = array_intersect($var, get_class_vars($class));

        return !empty($methods);
    }
}