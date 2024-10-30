<?php
namespace InfCrowds\WPR\Gateways;

/**
 * GatewayTest_Integration class.
 *
 * @extends GatewayIntegrationBase
 */
class GatewayTest_Integration extends GatewayIntegrationBase {


	public $key = 'infcrwds_test';
	public $token = false;


	/**
	 * Constructor
	 */
	public function __construct($store, $order_maker_factory) {

		parent::__construct($store, $order_maker_factory);

	}

	/**
	 * Try and get the payment token saved by the gateway
	 *
	 * @param WC_Order $order
	 *
	 * @return true on success false otherwise
	 */
	public function has_token( $order ) {

		return true;

	}


	public function process_charge( $order, $offer ) {

		$is_successful = true;

		return "1";
	}


}
