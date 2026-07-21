<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

class wps_wrapper
{
    public ?Services $services = null;

    public ?Cache $cache = null;

    public ?Storage $storage = null;

    public ?Settings $settings = null;

    public ?CronForModules $cron = null;

    public ?Ajax $ajax = null;

    public ?Options $options = null;

    public ?ModuleHandler $moduleHandler = null;

    private string $path = '';
    private array $args = [];
    private string $context;
    private array $components = [];

    public function __construct($context, $args, $components = [])
    {
        $this->context = $context;

        wps_core()->meter->lap("$context-loading");

        $this->filter_args($args);

        $this->path = $this->args['modules_path'];

        $this->filter_components($components);
    }

    private function filter_args($args): void
    {
        $this->args = array_merge($this->args, [
            'modules_path' => '',
            'table_name'   => ''
        ], (array)$args);
    }

    private function filter_components($components): void
    {
        if (!empty($this->components)) {
            $components = array_diff_key($components, array_filter($this->components));
        }

        $this->components = array_merge([
            'services'      => false,
            'cache'         => false,
            'storage'       => false,
            'cron'          => false,
            'ajax'          => false,
            'moduleHandler' => false,
            'options'       => false
        ], $components);
    }

    public function update_components($components, $args): void
    {
        $this->filter_args($args);
        $this->filter_components($components);
        $this->setup();
    }

    public function setup(): void
    {
        if ($this->components['services'] and is_null($this->services)) {
            $this->services = new Services();
            $this->services->register('html_output_buffer', static function (): HtmlOutputBuffer {
                return new HtmlOutputBuffer();
            });
        }

        if ($this->components['cache'] and is_null($this->cache)) {
            $this->cache = new Cache($this->context, defined('WP_PERSISTENT_CACHE') and WP_PERSISTENT_CACHE);
        }

        if ($this->components['storage'] and is_null($this->storage)) {
            $this->storage = new Storage($this->context);
        }

        if ($this->components['options'] and !empty($this->args['table_name']) and is_null($this->options)) {
            $this->options = new Options($this->context, $this->args['table_name']);
        }

        if (is_null($this->settings)) {
            $this->settings = new Settings($this->context);
        }

        if ($this->components['moduleHandler'] and !empty($this->args['modules_path']) and is_null($this->moduleHandler)) {
            $this->moduleHandler = new ModuleHandler($this->context, $this->args['modules_path']);
        }

        if ($this->components['ajax'] and wp_doing_ajax() and is_null($this->ajax)) {
            $this->ajax = new Ajax($this->context);
        }

        if ($this->components['cron'] and is_null($this->cron)) {
            $this->cron = new CronForModules($this->context);
        }
    }

    public function switch_to_blog(bool $create_options_table = false): void
    {
        if ($this->components['cache']) {
            $this->cache = new Cache($this->context, defined('WP_PERSISTENT_CACHE') and WP_PERSISTENT_CACHE);
        }

        if ($this->components['storage']) {
            $this->storage = new Storage($this->context);
        }

        $this->settings = new Settings($this->context);

        if ($this->components['options'] && !empty($this->args['table_name'])) {
            $this->options = new Options($this->context, $this->args['table_name'], $this->cache, $create_options_table);
        }

        if ($this->components['moduleHandler'] && !empty($this->args['modules_path'])) {
            $this->moduleHandler = new ModuleHandler($this->context, $this->args['modules_path']);
        }

        if ($this->components['cron']) {
            $this->cron = new CronForModules($this->context);
        }
    }

    public function get_path()
    {
        return $this->path;
    }

    public function __get($name)
    {
        wps_debug_log("WPS Framework >> object $name not defined");
    }
}
