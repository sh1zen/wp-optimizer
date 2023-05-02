<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core;

class shzn_wrapper
{
    /**
     * @var PerformanceMeter
     */
    public $meter;

    /**
     * @var Cache
     */
    public $cache;

    /**
     * @var Storage
     */
    public $storage;

    /**
     * @var Utility
     */
    public $utility;

    /**
     * @var Settings
     */
    public $settings;

    /**
     * @var Cron
     */
    public $cron;

    /**
     * @var Ajax
     */
    public $ajax;

    /**
     * @var Options
     */
    public $options;

    /**
     * @var ModuleHandler
     */
    public $moduleHandler;

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
            'path'          => '',
            'use_memcache'  => false,
            'table_name'    => ''
        ], (array)$args);
    }

    private function filter_components($components)
    {
        $defaults = empty($this->components) ? [
            'meter'         => false,
            'cache'         => false,
            'storage'       => false,
            'settings'      => false,
            'cron'          => false,
            'ajax'          => false,
            'moduleHandler' => false,
            'utility'       => false,
            'options'       => false
        ] : $this->components;

        $this->components = array_merge($defaults, $components);
    }

    public function update_components($components, $args)
    {
        $this->filter_args($args);
        $this->filter_components($components);
        $this->setup();
    }

    public function setup()
    {
        if ($this->components['utility']) {
            $this->utility = new Utility();
        }

        if ($this->components['meter']) {
            $this->meter = new PerformanceMeter("loading-{$this->context}");
        }

        if ($this->components['cache']) {
            $this->cache = new Cache($this->args['use_memcache']);
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

        if ($this->components['cron']) {
            $this->cron = new Cron($this->context);
        }

        if ($this->components['ajax'] and wp_doing_ajax()) {
            $this->ajax = new Ajax($this->context);
        }

        if ($this->components['moduleHandler']) {
            $this->moduleHandler = new ModuleHandler($this->args['path'], $this->context);
        }
    }

    public function __get($name)
    {
        $fn = shzn_debug_backtrace(2);
        trigger_error("SHZN Framework >> object {$name} not defined in {$fn}.", E_USER_WARNING);
    }
}
