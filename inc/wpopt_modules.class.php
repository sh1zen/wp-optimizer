<?php

class wpopt_modules
{
    private static $_instance;

    public $modules;

    public function __construct()
    {
        /*
        foreach (glob(WPOPT_MODULES . '/*.php') as $filename) {

        }
        */
        $this->modules['wpopt_sysinfo'] = array(
            'page_title' => 'System Info',
            'menu_title' => 'System Info',
            'slug'       => 'wpopt-sysinfo'
        );
    }

    public static function getInstance()
    {
        if (!defined('ABSPATH'))
            exit();

        if (!isset(self::$_instance)) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }
}