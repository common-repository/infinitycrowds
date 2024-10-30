<?php
/**
 * AuthorizeNetCIM Gateway API
 *
 *
 * @package   InfinityCrowds
 * @author    InfinityCrowds
 * @license   GPL-3.0
 * @link      https://infinitycrowds.com
 * @copyright 2019 InfinityCrowds (Pty) Ltd
 */
namespace InfCrowds\WPR\Endpoint\Gateways;
use \InfCrowds\WPR\ICRequest;
use \InfCrowds\WPR\Gateways\GatewayIntegrationBase;


class AuthorizeNetCIMApi extends GatewaysApi {
    
    public function __construct($ns, $store, $gateway_mgr)
    {
        parent::__construct($ns, $store, $gateway_mgr, 'authorize_net_cim_credit_card');
    }

    public function register_routes() {
    }

    protected function pay_for_upsell(
        $integration,
        $order,
        $session_id,
        $offer,
        $product,
        $pricing) {
        
        $response = $integration->process_charge($order, $offer);
        

        if(!$response[0]) {
            $request         = new stdClass();
            $request->error_reason = 'Authorize.Net pay with token failed';
            return $this->write_error_from_request(
                $request,
                $offer, 
                $order, 
                $integration,
                $session_id,
                'auth_net_cim_failed'
            );
        }

        return $this->handle_successful_charge(
            $integration, 
            $offer,
            $order,
            $session_id,
            $response[1]);
    }
}