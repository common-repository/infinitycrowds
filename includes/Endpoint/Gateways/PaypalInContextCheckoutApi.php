<?php
/**
 * Gateways API
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


class PaypalInContextCheckoutApi extends GatewaysApi {
    
    public function __construct($ns, $store, $gw_key, $gateway_mgr)
    {
        parent::__construct($ns, $store, $gateway_mgr, $gw_key . '_in_context_checkout');
    }

    public function register_routes() {
    }

    protected function pay_for_upsell($integration,
        $order,
        $session_id,
        $offer,
        $product,
        $pricing) {
    
        $request = $integration->begin_express_checkout_payment(false,
        $session_id,
        $order,
        $pricing['has_shipping']);

        $request->set_payment_parameters_for_offer($order, $offer, 
            $pricing, 
            $product);
        
        $token = $request->finalize();
            
        if ( $token ) {
            return wp_send_json( array(
                'success' => true,
                'value' => $token
            ), 201 );
        } else {
            return $this->write_error_from_request(
                $request,
                $offer, 
                $order, 
                $integration,
                $session_id,
                'create_token_failed'
            );
        }
    }
}