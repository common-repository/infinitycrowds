<?php
/**
 * TestGatewayApi
 *
 *
 * @package   InfinityCrowds
 * @author    InfinityCrowds
 * @license   GPL-3.0
 * @link      https://infinitycrowds.com
 * @copyright 2019 InfinityCrowds (Pty) Ltd
 */
namespace InfCrowds\WPR\Endpoint\Gateways;


class TestGatewayApi extends GatewaysApi {

    public function __construct($ns, $store, $gateway_mgr)
    {
        parent::__construct($ns, $store, $gateway_mgr, 'infcrwds_test');
    }

    protected function pay_for_upsell(
        $integration,
        $order,
        $session_id,
        $offer,
        $product,
        $pricing) {
        
        // $integration->write_payment_failed_order($offer,
        //     $order,
        //     $session_id,
        //     null,
        //     "err",
        //     $redirect=false);

        // return wp_send_json( array(
        //     'success' => false,
        //     'value' => "err"
        // ), 400 );
        
        $trans_id = $integration->process_charge($order, $offer);
        return $this->handle_successful_charge(
            $integration, 
            $offer,
            $order,
            $session_id,
            $trans_id);
    }
}