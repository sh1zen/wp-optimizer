<?php

namespace WPOptimizer\core;

/**
 * Generic Ajax Handler for all WOModules
 *
 * Required parameters:
 * wpopt-action: action to execute
 * womod: module slug
 */
class Ajax
{
    private static $_instance;

    public function __construct()
    {
        add_action('wp_ajax_wpopt', array($this, 'ajax_handler'), 10, 1);
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
        if (!isset($_REQUEST['womod']))
            return;

        $request = array_merge(array(
            'womod'        => 'none',
            'wpopt_nonce'  => '',
            'wpopt_action' => 'none',
            'wpopt_args'   => '',
            'wpopt_form'   => ''
        ), $_REQUEST);

        if (!empty($request['wpopt_nonce']) and !UtilEnv::verify_nonce('wpopt-ajax-nonce', $request['wpopt_nonce'])) {
            wp_send_json_error(array(
                'response' => __('WPOPT Error: It seems that you are not allowed to do this request.', 'wpopt'),
            ));
        }

        $action = sanitize_text_field($request['wpopt_action']);

        $object = ModuleHandler::get_module_instance(sanitize_text_field($request['womod']));

        $args = array(
            'action'    => $action,
            'nonce'     => $request['wpopt_nonce'],
            'options'   => $request['wpopt_args'],
            'form_data' => $request['wpopt_form'],
        );

        if (!is_null($object)) {

            if ($object->restricted_access('ajax')) {
                wp_send_json_error(array(
                    'response' => __('WPOPT Error: It seems that you are not allowed to do this request.', 'wpopt'),
                ));
            }

            $object->ajax_handler($args);
        }
        else {
            wp_send_json_error(
                array(
                    'error' => __('WPOPT Error: wrong ajax request.', 'wpopt'),
                )
            );
        }

    }
}