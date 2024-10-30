<?php
/**
 * BacsGatewayApi
 *
 *
 * @package   InfinityCrowds
 * @author    InfinityCrowds
 * @license   GPL-3.0
 * @link      https://infinitycrowds.com
 * @copyright 2019 InfinityCrowds (Pty) Ltd
 */
namespace InfCrowds\WPR\Endpoint\Gateways;


class BacsApi extends GatewaysApi {

    public function __construct($ns, $store, $gateway_mgr)
    {
        parent::__construct($ns, $store, $gateway_mgr, 'bacs');
    }

    protected function pay_for_upsell(
        $integration,
        $order,
        $session_id,
        $offer,
        $product,
        $pricing) {
        
        $response = $integration->process_charge($order, $offer, $pricing);
        

        if(!$response[0]) {
            $request         = new \stdClass();
            $request->error_reason = 'Bacs pay with token failed'; 
            return $this->write_error_from_request(
                $request,
                $offer, 
                $order, 
                $integration,
                $session_id,
                'bacs_failed'
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