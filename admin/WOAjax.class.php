<?php

if (!defined('ABSPATH'))
    exit;

/**
 * Generic Ajax Handler for all WOModules
 *
 * Required parameters:
 * action: must be wpopt
 * womod: module slug
 *
 * since 1.2.0
 */
class WOAjax
{
    private static $_instance;

    public function __construct()
    {
        add_action('wp_ajax_wpopt', array($this, 'ajax_handler'));
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
        return self::$_instance = new self();
    }

    /**
     * Note that nonce needs to be verified by each module
     */
    public function ajax_handler()
    {
        if (isset($_GET['womod'])) {

            $module = sanitize_text_field($_GET['womod']);

            $object = WOModuleHandler::getInstance()->load_module($module);

            if (!is_null($object)) {
                $object->ajax_handler();
            }
            else {
                wp_send_json_error(
                    array(
                        'error' => __('WP Optimizer wrong ajax request.', 'wpopt'),
                    )
                );
            }
        }
    }
}