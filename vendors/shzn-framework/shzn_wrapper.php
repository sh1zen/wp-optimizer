<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core;

class shzn_wrapper
{
    public ?PerformanceMeter $meter = null;

    public ?Cache $cache = null;

    public ?Storage $storage = null;

    public ?Settings $settings = null;

    public ?Cron $cron = null;

    public ?Ajax $ajax = null;

    public ?Options $options = null;

    public ?ModuleHandler $moduleHandler = null;

    private array $args = [];

    private string $context;

    private array $components = [];

    public function __construct($context, $args, $components = [])
    {
        $this->context = $context;

        $this->filter_args($args);

        $this->filter_components($components);
    }

    private function filter_args($args)
    {
        $this->args = array_merge($this->args, [
            'path'       => '',
            'table_name' => ''
        ], (array)$args);
    }

    private function filter_components($components)
    {
        if (!empty($this->components)) {
            $components = array_diff_key($components, array_filter($this->components));
        }

        $this->components = array_merge([
            'meter'         => false,
            'cache'         => false,
            'storage'       => false,
            'settings'      => false,
            'cron'          => false,
            'ajax'          => false,
            'moduleHandler' => false,
            'options'       => false
        ], $components);
    }

    public function update_components($components, $args)
    {
        $this->filter_args($args);
        $this->filter_components($components);
        $this->setup();
    }

    public function setup()
    {
        if ($this->components['meter']) {
            $this->meter = new PerformanceMeter("loading-$this->context");
        }

        if ($this->components['cache']) {
            $this->cache = new Cache(defined('WP_PERSISTENT_CACHE') ? WP_PERSISTENT_CACHE : false);
        }

        if ($this->components['storage']) {
            $this->storage = new Storage($this->context);
        }

        if ($this->components['options']) {
            $this->options = new Options($this->context, $this->args['table_name']);
        }

        if ($this->components['settings']) {
            $this->settings = new Settings($this->context);
        }

        if ($this->components['moduleHandler']) {
            $this->moduleHandler = new ModuleHandler($this->context, $this->args['path']);
        }

        if ($this->components['ajax'] and wp_doing_ajax()) {
            $this->ajax = new Ajax($this->context);
        }

        if ($this->components['cron']) {
            $this->cron = new Cron($this->context);
        }
    }

    public function __get($name)
    {
        $fn = shzn_debug_backtrace(2);
        trigger_error("SHZN Framework >> object $name not defined in $fn.", E_USER_WARNING);
    }
}
