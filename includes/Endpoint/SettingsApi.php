<?php
/**
 * InfCrowds
 *
 *
 * @package   InfCrowds
 * @author    InfCrowds
 * @license   GPL-3.0
 * @link      https://goInfCrowds.com
 * @copyright 2017 InfCrowds (Pty) Ltd
 */
namespace InfCrowds\WPR\Endpoint;
use InfCrowds\WPR;


class SettingsApi extends BaseApi
{
    function __construct($ns, $store, $gw_manager)
    {
        parent::__construct($ns, '/settings/');
        $this->_store = $store;
        $this->gateway_mgr = $gw_manager;
    }


    public function register_routes()
    {
        $this->add_route('get_setting', array());
        $this->add_create_route('set_setting', array());
        $this->add_route('get_supported_gateways', array(), $suffix='gateways');
    }

    public function get_setting() {
        $keys = $_GET['key'];
        if(!is_array($keys)) {
            $keys = array($keys);
        }
        if (empty($keys)) {
            return new \WP_REST_Response( array(
                'success' => false,
                'value' => $keys
            ), 400 );
        }
        $res = array();

        foreach ($keys as $key) {
            $res[$key] = $this->_store->get(sanitize_key($key));
        }

        return new \WP_REST_Response( array(
            'success' => true,
            'value' => $res
        ), 200 );
    }
    public function get_supported_gateways($request) {
        
        return new \WP_REST_Response( array(
            'success' => 200,
            'value' => array_keys($this->gateway_mgr->get_supported_gateways())
        ), 200 );
    }

    public function set_setting( $request ) {
        $params = $request->get_json_params();
        if (empty($params)) {
            return new \WP_REST_Response( array(
                'success' => false,
                'value' => null
            ), 400 );
        }
        foreach ($params as $key => $value) {
            $this->_store->set( $key, $value );
        }
        return new \WP_REST_Response( array(
            'success'   => true,
            'value'     => $params
        ), 200 );
    }
}
