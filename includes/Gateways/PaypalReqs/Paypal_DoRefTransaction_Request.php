<?php
namespace InfCrowds\WPR\Gateways\PaypalReqs;
use INFCWDS_WC_Compatibility;
use \InfCrowds\WPR\OrderHelpers;

class Paypal_DoRefTransaction_Request extends Paypal_Base_Request {

    public function __construct(
        $deprecated_params, 
        $paypal_integration, 
        $session_id,
        $order) {
        parent::__construct($deprecated_params, $paypal_integration, $session_id);
        $this->_deprecated_params = true;
        $token = $paypal_integration->get_token($order);
        $this->_shipping_prefix = 'SHIPTO';
        $this->_args = array(
            "payment_action" => 'Sale'
        );
        $this->set_method( 'DoReferenceTransaction' );

        /**
		 * We unset the notify url as we do not want IPN for this call.
		 */
		// set base params
		$this->add_parameters( array(
			'REFERENCEID'      => $token,
			'BUTTONSOURCE'     => 'WooThemes_Cart',
            'RETURNFMFDETAILS' => 1,
        ));
    }
}