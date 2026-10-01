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
    private const DEFINITION_FILE = 0;
    private const DEFINITION_CLASS = 1;
    private const DEFINITION_NAME = 2;
    private const DEFINITION_SCOPES = 3;

    private array $module_catalog = array();

    private array $module_instances = array();

    private array $failed_modules = array();

    private static array $normalized_catalog_cache = array();

    private static array $logged_catalog_errors = array();

    private $module_settings;

    private string $context;

    private array $filtered_modules_cache = [];

    private array $module_scope_cache = [];

    private array $module_method_cache = [];

    private array $setup_modules_cache = [];

    public function __construct(string $context, $catalog_source = null)
    {
        $this->context = $context;
        $this->initialize_catalog($catalog_source);

        $this->module_settings = wps($context)->settings->get('modules_handler', []);
    }

    private function initialize_catalog($catalog_source): void
    {
        $cache_key = $this->catalog_cache_key($catalog_source);

        if ($cache_key !== '' && isset(self::$normalized_catalog_cache[$cache_key])) {
            $this->module_catalog = self::$normalized_catalog_cache[$cache_key];
            return;
        }

        $catalog = $this->load_catalog($catalog_source);

        if (empty($catalog)) {
            $this->log_catalog_error('Module catalog is missing or empty. No modules were registered.');
            return;
        }

        $namespace = isset($catalog['namespace']) && is_string($catalog['namespace'])
            ? trim($catalog['namespace'], " \t\n\r\0\x0B\\")
            : '';
        $directory = $this->resolve_module_directory($catalog['directory'] ?? null, $catalog_source);
        $modules = $catalog['modules'] ?? null;

        if (!$this->is_valid_class_name($namespace) || $directory === '' || !is_array($modules)) {
            $this->log_catalog_error('Module catalog header is invalid. A namespace, relative directory, and modules array are required.');
            return;
        }

        foreach ($modules as $catalog_slug => $entry) {
            if (!is_array($entry)) {
                $this->log_catalog_error(sprintf('Module catalog entry "%s" must be an array.', (string)$catalog_slug));
                continue;
            }

            $catalog_key = is_string($catalog_slug) ? self::module_slug($catalog_slug, true) : false;
            $slug = self::module_slug((string)($entry['slug'] ?? $catalog_slug), true);
            $name = isset($entry['name']) && is_string($entry['name']) ? trim($entry['name']) : '';
            $scopes = $entry['scopes'] ?? null;

            if (
                !$slug
                || !$catalog_key
                || $catalog_key !== $slug
                || isset($this->module_catalog[$slug])
                || $name === ''
                || !is_array($scopes)
            ) {
                $this->log_catalog_error(sprintf('Module catalog entry "%s" is invalid and was skipped.', (string)$catalog_slug));
                continue;
            }

            $scopes = array_values(array_unique(array_filter(array_map(static function ($scope): string {
                return is_scalar($scope) ? trim((string)$scope) : '';
            }, $scopes))));

            $this->module_catalog[$slug] = array(
                self::DEFINITION_FILE   => $directory . DIRECTORY_SEPARATOR . $slug . '.class.php',
                self::DEFINITION_CLASS  => $namespace . '\\Mod_' . $slug,
                self::DEFINITION_NAME   => $name,
                self::DEFINITION_SCOPES => $scopes,
            );
        }

        if (empty($this->module_catalog)) {
            $this->log_catalog_error('Module catalog contains no valid entries. No modules were registered.');
            return;
        }

        if ($cache_key !== '') {
            self::$normalized_catalog_cache[$cache_key] = $this->module_catalog;
        }
    }

    private function resolve_module_directory($directory, $catalog_source): string
    {
        if (!is_string($directory) || trim($directory) === '') {
            return '';
        }

        $directory = rtrim(trim($directory), '/\\');

        if (!$this->is_absolute_path($directory)) {
            if (!is_string($catalog_source) || !$this->is_absolute_path($catalog_source)) {
                return '';
            }

            $directory = dirname($catalog_source) . DIRECTORY_SEPARATOR . $directory;
        }

        $resolved = realpath($directory);

        return $resolved !== false && is_dir($resolved) ? $resolved : '';
    }

    private function catalog_cache_key($catalog_source): string
    {
        if (!is_string($catalog_source) || !$this->is_absolute_path($catalog_source)) {
            return '';
        }

        $real_path = realpath($catalog_source);

        return $real_path === false ? '' : str_replace('\\', '/', $real_path);
    }

    private function load_catalog($catalog_source): array
    {
        if (is_array($catalog_source)) {
            return $catalog_source;
        }

        if (!is_string($catalog_source) || !$this->is_absolute_path($catalog_source) || !is_file($catalog_source) || !is_readable($catalog_source)) {
            return array();
        }

        try {
            $catalog = require $catalog_source;
        }
        catch (\Throwable $throwable) {
            $this->log_catalog_error(sprintf('Catalog file "%s" could not be loaded: %s', $catalog_source, $throwable->getMessage()));
            return array();
        }

        if (!is_array($catalog)) {
            $this->log_catalog_error(sprintf('Catalog file "%s" must return an array.', $catalog_source));
            return array();
        }

        return $catalog;
    }

    private function is_absolute_path(string $path): bool
    {
        return $path !== '' && (bool)preg_match('#^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)#', $path);
    }

    private function is_valid_class_name(string $class): bool
    {
        return $class !== '' && (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $class);
    }

    private function log_catalog_error(string $message): void
    {
        $message = sprintf('WPS module catalog [%s]: %s', $this->context, $message);

        if (isset(self::$logged_catalog_errors[$message])) {
            return;
        }

        self::$logged_catalog_errors[$message] = true;
        error_log($message);
    }

    /**
     * Convert module name or wp-admin page slug to class name if exist
     * @param $name
     * @return bool|string
     */
    public function module2classname($name)
    {
        $module_slug = self::module_slug($name, true);

        if (!$module_slug || isset($this->failed_modules[$module_slug]) || !isset($this->module_catalog[$module_slug])) {
            return false;
        }

        $definition = $this->module_catalog[$module_slug];
        $class = $definition[self::DEFINITION_CLASS];

        if (!class_exists($class, false)) {
            if (!is_file($definition[self::DEFINITION_FILE]) || !is_readable($definition[self::DEFINITION_FILE])) {
                $this->failed_modules[$module_slug] = true;
                $this->log_catalog_error(sprintf('Module "%s" file "%s" is unavailable.', $module_slug, $definition[self::DEFINITION_FILE]));
                return false;
            }

            try {
                require_once $definition[self::DEFINITION_FILE];
            }
            catch (\Throwable $throwable) {
                $this->failed_modules[$module_slug] = true;
                $this->log_catalog_error(sprintf('Module "%s" could not be loaded: %s', $module_slug, $throwable->getMessage()));
                return false;
            }
        }

        if (!class_exists($class, false) || !is_subclass_of($class, Module::class)) {
            $this->failed_modules[$module_slug] = true;
            $this->log_catalog_error(sprintf('Module "%s" did not load its declared class "%s".', $module_slug, $class));
            return false;
        }

        return $class;
    }

    public static function module_slug($name, $remove_namespace = false)
    {
        if (is_array($name) and isset($name['slug'])) {
            $name = $name['slug'];
        }

        if (!is_string($name)) {
            return false;
        }

        $name = preg_replace('#[^a-z/\\\_-]#', '', strtolower($name));

        if ($remove_namespace) {
            $name = basename(str_replace('\\', DIRECTORY_SEPARATOR, $name));
        }

        return preg_replace("#(mod_|mod-)#", '', $name);
    }

    /**
     * Load active modules for a request scope.
     * Catalog filtering happens before module files are loaded.
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

        foreach ($this->module_catalog as $slug => $definition) {

            $module = array(
                'slug' => $slug,
                'name' => $definition[self::DEFINITION_NAME],
            );

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

        $module_slug = self::module_slug($module, true);

        if (!$module_slug || !isset($this->module_catalog[$module_slug])) {
            return false;
        }

        $cache_Key = $module_slug . '|' . $compare . '|' . (is_array($scope) ? maybe_serialize($scope) : (string)$scope);

        if (isset($this->module_scope_cache[$cache_Key])) {
            return $this->module_scope_cache[$cache_Key];
        }

        if (!is_array($scope)) {
            $scope = array($scope);
        }

        $registered_scopes = $this->module_catalog[$module_slug][self::DEFINITION_SCOPES];
        $res = $compare === 'AND';

        foreach ($scope as $requested_scope) {
            $found = in_array($requested_scope, $registered_scopes, true);

            if (($compare === 'AND' && !$found) || ($compare !== 'AND' && $found)) {
                $res = $found;
                break;
            }
        }

        $this->module_scope_cache[$cache_Key] = $res;

        return $res;
    }

    /**
     * Return instance of the module
     */
    public function get_module_instance($module): ?Module
    {
        $module_slug = self::module_slug($module, true);

        if (!$module_slug) {
            return null;
        }

        if (isset($this->module_instances[$module_slug])) {
            return $this->module_instances[$module_slug];
        }

        $class = $this->module2classname($module);

        if (!$class) {
            return null;
        }

        $object = new $class();

        $this->module_instances[$module_slug] = $object;

        return $object;
    }

    public function upgrade(): bool
    {
        foreach ($this->get_modules('all', false) as $module) {
            $module_object = $this->get_module_instance($module);

            if ($module_object) {
                $module_object->filter_settings();
            }
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

        if (!isset($this->module_catalog[$module_slug])) {
            return null;
        }

        $object = $this->get_module_instance($module_slug);

        if (is_null($object)) {
            return null;
        }

        return array(
            'object' => $object,
            'name'   => $this->module_catalog[$module_slug][self::DEFINITION_NAME],
            'slug'   => $module_slug,
        );
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

        if (!$class = $this->module2classname($module)) {
            return false;
        }

        $methods = array_intersect($method, get_class_methods($class));

        $res = ($compare === 'AND') ? (count($methods) === count($method)) : !empty($methods);

        $this->module_method_cache[$cache_key] = $res;

        return $res;
    }
}
