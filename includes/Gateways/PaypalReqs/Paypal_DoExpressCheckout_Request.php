<?php
namespace InfCrowds\WPR\Gateways\PaypalReqs;
use INFCWDS_WC_Compatibility;
use \InfCrowds\WPR\OrderHelpers;

class Paypal_DoExpressCheckout_Request extends Paypal_Base_Request {
    public function __construct(
        $deprecated_params, 
        $paypal_integration,
        $payer_id, 
        $token, $session_id, $payment_action = 'Sale') {
        parent::__construct($deprecated_params, $paypal_integration, $session_id);
        $this->_args = array(
            "payment_action" => $payment_action
        );
        $this->set_method( 'DoExpressCheckoutPayment' );

        // set base params
		$this->add_parameters( array(
			'TOKEN'            => $token,
			'PAYERID'          => $payer_id,
			'BUTTONSOURCE'     => 'WooThemes_Cart',
			'RETURNFMFDETAILS' => 1,
        ) );
    }
}