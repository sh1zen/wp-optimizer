<?php

if (!defined('ABSPATH'))
    exit();

class wpoptModuleHandler
{
    private static $_instance;

    private $modules;

    private $modules_object;

    public function __construct()
    {
        $this->modules_object = wpoptPlCache::getInstance();

        $this->set_modules();
    }

    private function set_modules()
    {
        $this->modules = array();

        $this->modules['wpoptCron'] = array(
            'page_title'     => 'Cron',
            'menu_title'     => 'Cron',
            'slug'           => 'wpopt-cron',
            'autoload'       => true,
            'autoload_admin' => true
        );

        $this->modules['wpoptSysinfo'] = array(
            'page_title'     => 'System Info',
            'menu_title'     => 'System Info',
            'slug'           => 'wpopt-sysinfo',
            'autoload'       => false,
            'autoload_admin' => false,
        );
    }

    public static function Initialize()
    {
        return self::$_instance = new self();
    }

    public static function getInstance()
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
     * If array of modules is passed, theme will be loaded without any check
     *
     * @param array $modules
     */
    public function create_instances($modules = array())
    {
        if (!empty($modules)) {

            foreach ($this->modules as $module) {

                $this->load_module($module['slug']);
            }

            return;
        }

        // todo handle if disabled by user
        foreach ($this->modules as $module) {

            /**
             * Instance the module only if non admin scope
             */
            if (!is_admin() and $module['autoload']) {
                $this->load_module($module['slug']);
            }

            /**
             * Instance the module only if admin scope
             */
            if (is_admin() and $module['autoload_admin']) {
                $this->load_module($module['slug']);
            }
        }
    }

    /**
     * Accept module name or wp-admin page name
     * @param string|array $name
     * @return object|null
     */
    public function load_module($name)
    {
        $class = $this->module2classname($name);

        if ($object = $this->modules_object->get_cache($class, 'modules_object'))
            return $object;

        if (!$class)
            return null;

        $object = new $class();

        $this->modules_object->set_cache($class, $object, 'modules_object');

        return $object;
    }

    /**
     * Convert module name or wp-admin page name to class name if exist
     * @param $name
     * @return bool|string|string[]
     */
    private function module2classname($name)
    {
        if (is_array($name)) {
            // get the page name of the module
            $name = $name['slug'];
        }

        $name = sanitize_text_field($name);

        $class = str_replace('-', '', $name);

        $file_name = str_replace('wpopt-', '', $name);

        if (file_exists(WPOPT_ABSPATH . "/modules/{$file_name}.class.php")) {

            include_once WPOPT_ABSPATH . "/modules/{$file_name}.class.php";
        }

        if (!class_exists($class))
            return false;

        return $class;
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
     * status -> no autoload | autoload | admin autoload
     *
     * @param array $filters
     * @return array
     */
    public function get_modules($filters = array())
    {
        if (empty($filters))
            return $this->modules;

        $modules = $this->modules;

        $filters = wp_parse_args($filters, array(
            'methods' => false,
            'status'  => 'autoload',
        ));

        foreach ($modules as $name => $module) {

            if ($filters['methods']) {

                if (!$this->module_has_method($module, $filters['methods']))
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
}