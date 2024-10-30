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


class PaypalExpressCheckoutRefTransactionApi extends GatewaysApi {
    public function __construct($ns, $store, $gateway_mgr)
    {
        parent::__construct($ns, $store, $gateway_mgr, 'ppec_paypal_ref_trans');
    }

    protected function pay_for_upsell(
        $integration,
        $order,
        $session_id,
        $offer,
        $product,
        $pricing) {
        
        $is_success = $integration->process_charge($order, $offer);
        if($is_success) {
            return wp_send_json( array(
                'success' => true,
                'value' => 'ok'
            ), 201 );
        }

        return wp_send_json( array(
            'success' => false,
            'value' => 'Ref Transaction Failed'
        ), 400 );
    }
}