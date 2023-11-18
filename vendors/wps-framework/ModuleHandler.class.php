<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

use WPS\modules\Module;

class ModuleHandler
{
    private string $modules_path;

    private array $modules;

    private $module_settings;

    private string $context;

    public function __construct($context, $load_path)
    {
        $this->context = $context;
        $this->modules_path = $load_path;

        $this->init_modules($this->modules_path);

        $this->module_settings = wps($context)->settings->get('modules_handler', []);
    }

    private function init_modules($load_path)
    {
        $this->modules = array();

        //$this_hash = $this->hash_file_sec(__FILE__);

        foreach (glob($load_path . '*.php') as $file) {

            // prevent external added content to module path.
            // only files with same owner, group and permissions are loaded
            // todo think of a better solution
            //if ($this->hash_file_sec($file) !== $this_hash) {
            //    continue;
            //}

            $module_slug = $this->load_module($file);

            if (empty($module_slug)) {
                continue;
            }

            $this->modules[] = array(
                'slug' => $module_slug,
                'name' => $this->get_module_name($module_slug, $module_slug)
            );
        }
    }

    /**
     * create a hash from file permission, owner and group
     */
    private function hash_file_sec($filename): string
    {
        return md5(fileowner($filename) . filegroup($filename) . fileperms($filename) . WPS_SALT);
    }

    private function load_module($file): string
    {
        if (!file_exists($file)) {
            return '';
        }

        $module_name = basename($file, '.class.php');

        $namespace = include_once($file);

        $module_slug = self::module_slug($module_name, true);

        if (class_exists("$namespace\\Mod_" . $module_slug)) {

            $class = "$namespace\\Mod_" . $module_slug;

            wps($this->context)->cache->set($module_slug, $class, 'modules-handler', true, false);
        }

        wps($this->context)->cache->set($module_name, $namespace, 'modules-to-namespace', true, false);

        return $module_name;
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
        $name = preg_replace('#[^a-z/\\\_-]#', '', strtolower($name));

        if ($remove_namespace) {
            $name = basename(str_replace('\\', DIRECTORY_SEPARATOR, $name));
        }

        return preg_replace("#(mod_|mod-)#", '', $name);
    }

    private function get_module_name($module, $default = ''): string
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
        return wps($this->context)->cache->get(self::module_slug($name, true), 'modules-handler', false);
    }

    /**
     * Load active modules, so they can perform their actions
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
        foreach ($this->get_modules($scope, $only_active) as $module) {

            $this->get_module_instance($module['slug']);
        }
    }

    /**
     * Get all modules filtered by:
     * scopes -> available method
     * status -> 'autoload'
     */
    public function get_modules($filters = array(), bool $only_active = true): array
    {
        $modules = array();

        if (is_string($filters)) {
            $filters = array('scopes' => $filters);
        }

        $filters = array_merge(array(
            'scopes'  => false,
            'excepts' => false,
            'compare' => 'AND'
        ), $filters);

        if ($filters['excepts'] and !$filters['scopes']) {
            $filters['scopes'] = 'all';
        }

        foreach ($this->modules as $module) {

            if ($only_active and !$this->module_is_active($module['slug'])) {
                continue;
            }

            if ($filters['excepts'] and in_array($module['slug'], (array)$filters['excepts'])) {
                continue;
            }

            if ($filters['scopes'] === 'all') {
                $modules[] = $module;
            }
            elseif ($filters['scopes'] and $this->module_has_scope($module, $filters['scopes'], $filters['compare'])) {
                $modules[] = $module;
            }
        }

        return $modules;
    }

    /**
     * check if passed module slug has settings and if active parameter is true
     */
    public function module_is_active($module_slug): bool
    {
        if (isset($this->module_settings[$module_slug]) and !$this->module_settings[$module_slug]) {
            return false;
        }

        return true;
    }

    /**
     * Accepts module name or wp-admin page name
     */
    public function module_has_scope($module, $scope, $compare = 'AND'): bool
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

    /**
     * Return instance of the module
     */
    public function get_module_instance($module): ?Module
    {
        $class = $this->module2classname($module);

        if (!$class) {
            return null;
        }

        if ($object = wps($this->context)->cache->get($class, 'modules_object', null, false)) {
            return $object;
        }

        $object = new $class();

        wps($this->context)->cache->set($class, $object, 'modules_object', true, false);

        return $object;
    }

    public function upgrade(): bool
    {
        foreach ($this->get_modules('all', false) as $module) {
            $module_object = $this->get_module_instance($module);
            $module_object->filter_settings();
        }

        return true;
    }

    /**
     * Accepts module name or wp-admin page name
     */
    public function module_has_method($module, $method, $compare = 'AND'): bool
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
}