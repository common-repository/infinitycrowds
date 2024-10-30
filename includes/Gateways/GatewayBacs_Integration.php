<?php
namespace InfCrowds\WPR\Gateways;


class GatewayBacs_Integration extends GatewayIntegrationBase {

	protected $key = 'bacs';

	/**
	 * Constructor
	 */
	public function __construct($store, $order_maker_factory) {

		parent::__construct($store, $order_maker_factory);
    }
    
	public function has_token( $order ) {
		return true;
	}	

	public function process_charge( $order, $offer ) {
		return array(true, '1');
    }
    
}