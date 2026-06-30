<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
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

    private array $filtered_modules_cache = [];

    private array $module_scope_cache = [];

    private array $module_method_cache = [];

    private array $class_vars_cache = [];

    private array $setup_modules_cache = [];

    public function __construct($context, $load_path)
    {
        $this->context = $context;
        $this->modules_path = $load_path;

        $this->init_modules($this->modules_path);

        $this->module_settings = wps($context)->settings->get('modules_handler', []);
    }

    private function init_modules($load_path): void
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
        $cache_key = $scope . ':' . ($only_active ? '1' : '0');

        if (isset($this->setup_modules_cache[$cache_key])) {
            return;
        }

        foreach ($this->get_modules($scope, $only_active) as $module) {

            $this->get_module_instance($module['slug']);
        }

        $this->setup_modules_cache[$cache_key] = true;
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

        $cache_key = md5(serialize([$filters, $only_active]));

        if (isset($this->filtered_modules_cache[$cache_key])) {
            return $this->filtered_modules_cache[$cache_key];
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

        $this->filtered_modules_cache[$cache_key] = $modules;

        return $modules;
    }

    /**
     * check if passed module slug has settings and if active parameter is true
     */
    public function module_is_active($module_slug): bool
    {
        return $this->module_is_active_in_settings($module_slug, $this->module_settings);
    }

    public function module_is_active_in_settings($module_slug, array $module_settings): bool
    {
        $module_slug = self::module_slug($module_slug, true);

        if (isset($module_settings[$module_slug]) and !$module_settings[$module_slug]) {
            return false;
        }

        return true;
    }

    public function refresh_settings(?array $settings = null): void
    {
        $this->module_settings = is_array($settings)
            ? ($settings['modules_handler'] ?? [])
            : wps($this->context)->settings->get('modules_handler', []);

        $this->filtered_modules_cache = [];
        $this->setup_modules_cache = [];
    }

    /**
     * Accepts module name or wp-admin page name
     */
    public function module_has_scope($module, $scope, $compare = 'AND'): bool
    {
        if (is_null($module) or empty($scope)) {
            return false;
        }

        $cache_Key = $module['slug'] . maybe_serialize($scope) . $compare;

        if (isset($this->module_scope_cache[$cache_Key])) {
            return $this->module_scope_cache[$cache_Key];
        }

        if (!is_null($found = wps($this->context)->cache->get($cache_Key, 'module_has_scope', null))) {
            $this->module_scope_cache[$cache_Key] = $found;
            return $found;
        }

        if (!is_array($scope)) {
            $scope = array($scope);
        }

        if (!$class = self::module2classname($module)) {
            return false;
        }

        if (!isset($this->class_vars_cache[$class])) {
            $this->class_vars_cache[$class] = get_class_vars($class);
        }

        $found = array_intersect($scope, $this->class_vars_cache[$class]['scopes']);

        $res = ($compare === 'AND') ? (count($found) === count($scope)) : !empty($found);

        $this->module_scope_cache[$cache_Key] = $res;

        wps($this->context)->cache->set($cache_Key, $res, 'module_has_scope', true, DAY_IN_SECONDS);

        return $res;
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

    public function cleanup_modules(?array $settings = null, bool $only_active = true): bool
    {
        return $this->run_modules_lifecycle('cleanup', $settings, $only_active);
    }

    public function reset_modules(?array $settings = null, bool $only_active = true): bool
    {
        return $this->run_modules_lifecycle('reset', $settings, $only_active);
    }

    public function get_resettable_module(string $module_slug, array $excluded_modules = array()): ?array
    {
        $module_slug = self::module_slug($module_slug, true);
        $excluded_modules = array_unique(array_merge(array('modules_handler', 'settings'), $excluded_modules));

        if (!$module_slug || in_array($module_slug, $excluded_modules, true)) {
            return null;
        }

        foreach ($this->get_modules('all', false) as $module) {
            if ((string)($module['slug'] ?? '') !== $module_slug) {
                continue;
            }

            $object = $this->get_module_instance($module_slug);

            if (is_null($object)) {
                return null;
            }

            return array(
                'object' => $object,
                'name'   => (string)($module['name'] ?? $module_slug),
                'slug'   => $module_slug,
            );
        }

        return null;
    }

    public function reset_module_to_factory(string $module_slug, array $excluded_modules = array()): array
    {
        $module_data = $this->get_resettable_module($module_slug, $excluded_modules);

        if (!$module_data) {
            return array(
                'success' => false,
                'reason'  => 'invalid_module',
            );
        }

        $module = $module_data['object'];
        $current_settings = wps($this->context)->settings->get('', array());
        $settings = $current_settings;
        $reset = $this->run_module_lifecycle($module->slug, 'reset', $settings);

        unset($settings[$module->slug]);

        $settings_changed = maybe_serialize($settings) !== maybe_serialize($current_settings);
        $saved = !$settings_changed || wps($this->context)->settings->reset($settings);
        $cleanup = $this->run_module_lifecycle($module->slug, 'cleanup', $settings);

        return array(
            'success' => $reset && $saved && $cleanup,
            'module'  => $module->slug,
            'name'    => $module_data['name'],
            'reset'   => $reset,
            'saved'   => $saved,
            'cleanup' => $cleanup,
        );
    }

    public function activate_modules(?array $settings = null, bool $only_active = true): bool
    {
        return $this->run_modules_lifecycle('activate', $settings, $only_active);
    }

    public function activate_modules_for_settings(array $settings): bool
    {
        $previous_module_settings = $this->module_settings;
        $this->refresh_settings($settings);

        $response = $this->activate_modules($settings, true);

        $this->module_settings = $previous_module_settings;
        $this->filtered_modules_cache = [];
        $this->setup_modules_cache = [];

        return $response;
    }

    public function apply_module_status_changes(array $new_module_settings, ?array $settings = null): bool
    {
        $settings = is_array($settings) ? $settings : wps($this->context)->settings->get('', []);
        $old_module_settings = is_array($this->module_settings) ? $this->module_settings : [];
        $response = true;

        foreach ($this->get_modules('all', false) as $module) {
            $slug = $module['slug'];
            $was_active = $this->module_is_active_in_settings($slug, $old_module_settings);
            $is_active = $this->module_is_active_in_settings($slug, $new_module_settings);

            if ($was_active && !$is_active) {
                $response = $this->run_module_lifecycle($slug, 'cleanup', $settings) && $response;
            }
            elseif (!$was_active && $is_active) {
                $response = $this->run_module_lifecycle($slug, 'activate', $settings) && $response;
            }
        }

        return $response;
    }

    private function run_modules_lifecycle(string $method, ?array $settings = null, bool $only_active = true): bool
    {
        $settings = is_array($settings) ? $settings : wps($this->context)->settings->get('', []);
        $response = true;

        foreach ($this->get_modules('all', $only_active) as $module) {
            $response = $this->run_module_lifecycle($module['slug'], $method, $settings) && $response;
        }

        return $response;
    }

    public function run_module_lifecycle(string $module, string $method, ?array $settings = null): bool
    {
        $settings = is_array($settings) ? $settings : wps($this->context)->settings->get('', []);
        $module_object = $this->get_module_instance($module);

        if (is_null($module_object) || !method_exists($module_object, $method)) {
            return true;
        }

        $module_settings = $settings[$module_object->slug] ?? array();
        $module_settings = is_array($module_settings) ? $module_settings : array();

        return (bool)$module_object->{$method}($module_settings, $settings);
    }

    /**
     * Accepts module name or wp-admin page name
     */
    public function module_has_method($module, $method, $compare = 'AND'): bool
    {
        $cache_key = self::module_slug($module, true) . maybe_serialize($method) . $compare;

        if (isset($this->module_method_cache[$cache_key])) {
            return $this->module_method_cache[$cache_key];
        }

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

        $res = ($compare === 'AND') ? (count($methods) === count($method)) : !empty($methods);

        $this->module_method_cache[$cache_key] = $res;

        return $res;
    }
}
