<?php
/**
 * CardCom Gateway API
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


class CardComApi extends GatewaysApi {
    
    public function __construct($ns, $store, $gateway_mgr)
    {
        parent::__construct($ns, $store, $gateway_mgr, 'cardcom');
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
            $request         = new \stdClass();
            $request->error_reason = 'CardCom pay with token failed'; 
            return $this->write_error_from_request(
                $request,
                $offer, 
                $order, 
                $integration,
                $session_id,
                'cardcom_failed'
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