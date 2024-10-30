<?php
/**
 * PaypalRefTransactionCheckoutApi
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
use \InfCrowds\WPR\Gateways\PaypalReqs\Paypal_DoRefTransaction_Request;


class PaypalRefTransactionCheckoutApi extends GatewaysApi {
    
    public function __construct($ns, $store, $gateway_mgr)
    {
        parent::__construct($ns, $store, $gateway_mgr, 'paypal_ref_trans');
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
        
        $request = new Paypal_DoRefTransaction_Request(false, $integration, $session_id, $order);
        $request->set_payment_parameters_for_offer($order, $offer, $pricing, $product);
        
        $response = $request->finalize();
        if(!$response) {
            return $this->write_error_from_request(
                $request,
                $offer, 
                $order, 
                $integration,
                $session_id,
                'ref_trans_failed'
            );
        }
        return $this->handle_successful_charge(
            $integration, 
            $offer,
            $order,
            $session_id,
            $response['TRANSACTIONID']);
    }
}