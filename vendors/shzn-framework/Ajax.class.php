<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core;

/**
 * Generic Ajax Handler for all WOModules
 *
 * Required parameters:
 * mod-action: action to execute
 * mod: module slug
 */
class Ajax
{
    private string $context;

    public function __construct($context)
    {
        $this->context = $context;

        add_action("wp_ajax_shzn", array($this, 'ajax_handler'), 10, 1);
    }

    /**
     * Note that nonce needs to be verified by each module
     */
    public function ajax_handler($args = null)
    {
        if (!isset($_REQUEST['mod'])) {
            return;
        }

        $request = array_merge(array(
            'mod'        => 'none',
            'mod_nonce'  => '',
            'mod_action' => 'none',
            'mod_args'   => '',
            'mod_form'   => ''
        ), $_REQUEST);

        if (!empty($request['mod_nonce']) and !UtilEnv::verify_nonce("{$this->context}-ajax-nonce", $request['mod_nonce'])) {
            self::response([
                'body'  => __('It seems that you are not allowed to do this request.', $this->context),
                'title' => __('Request error', $this->context)
            ], 'error');
        }

        $object = shzn($this->context)->moduleHandler->get_module_instance($request['mod']);

        $args = array(
            'action'    => sanitize_text_field($request['mod_action']),
            'nonce'     => $request['mod_nonce'],
            'options'   => $request['mod_args'],
            'form_data' => $request['mod_form'],
        );

        if (!is_null($object)) {

            if ($object->restricted_access('ajax')) {
                self::response([
                    'body'  => __('It seems that you are not allowed to do this request.', $this->context),
                    'title' => __('Request error', $this->context)
                ], 'error');
            }

            $object->ajax_handler($args);
        }
        else {

            self::response([
                'body'  => __('Wrong ajax request.', $this->context),
                'title' => __('Request error', $this->context)
            ], 'error');
        }
    }

    public static function response($data = null, $status = 'success', $options = 0)
    {
        switch ($status) {

            case 'info':
            case 'error':
            case 'success':
            case 'warning':
                $status_code = 200;
                break;

            default:
                $status_code = absint($status);
        }

        $response = array('success' => $status_code === 200);

        if (is_string($status)) {
            $response['status'] = $status;
        }

        if (!empty($data)) {
            $response['data'] = $data;
        }

        wp_send_json($response, $status_code, $options);
    }
}