<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

class wps_wrapper
{
    public ?Cache $cache = null;

    public ?Storage $storage = null;

    public ?Settings $settings = null;

    public ?CronForModules $cron = null;

    public ?Ajax $ajax = null;

    public ?Options $options = null;

    public ?ModuleHandler $moduleHandler = null;

    private array $args = [];

    private string $context;

    private array $components = [];

    public function __construct($context, $args, $components = [])
    {
        $this->context = $context;

        wps_utils()->meter->lap("{$context}-loading");

        $this->filter_args($args);

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
        if ($this->components['cache']) {
            $this->cache = new Cache($this->context, defined('WP_PERSISTENT_CACHE') and WP_PERSISTENT_CACHE);
        }

        if ($this->components['storage']) {
            $this->storage = new Storage($this->context);
        }

        if ($this->components['options']) {
            $this->options = new Options($this->context, $this->args['table_name']);
        }

        $this->settings = new Settings($this->context);

        if ($this->components['moduleHandler']) {
            $this->moduleHandler = new ModuleHandler($this->context, $this->args['modules_path']);
        }

        if ($this->components['ajax'] and wp_doing_ajax()) {
            $this->ajax = new Ajax($this->context);
        }

        if ($this->components['cron']) {
            $this->cron = new CronForModules($this->context);
        }
    }

    public function __get($name)
    {
        wps_debug_log("WPS Framework >> object $name not defined");
    }
}
