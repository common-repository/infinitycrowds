<?php

class INFCWDS_Compatibility_With_WooMultiCurrency_F {

	public function __construct() {
		// if ( defined( 'WOOMULTI_CURRENCY_F_VERSION' ) ) {
		// 	add_filter( 'woocommerce_get_checkout_order_received_url', array( $this, 'maybe_add_currency_converter_url' ), 999, 2 );

		// }

	}

	public function is_enable() {
		if ( defined( 'WOOMULTI_CURRENCY_F_VERSION' ) ) {
			return true;
		}

		return false;
	}

	/**
	 *
	 * @param $url
	 * @param WC_Order $order
	 *
	 * @return string
	 */
	public function maybe_add_currency_converter_url( $url, $order ) {


		if ( ! $order instanceof WC_Order ) {
			return $url;
		}

		return add_query_arg( array( 'wmc-currency' => strtoupper( $order->get_currency() ) ), $url );
	}


	/**
	 *
	 * Modifies the amount for the fixed discount given by the admin in the currency selected.
	 *
	 * @param integer|float $price
	 *
	 * @return float
	 */
	public function alter_fixed_amount( $price, $currency = null ) {

		return wmc_get_price( $price, $currency );
	}

	function get_fixed_currency_price_reverse( $price, $from = null, $base = null ) {
		$data  = new WOOMULTI_CURRENCY_F_Data();
		$from = ( is_null( $from ) ) ? $data->get_current_currency() : $from;
		$base = ( is_null( $base ) ) ? get_option( 'woocommerce_currency' ) : $base;


		$rates = $data->get_exchange( $from, $base );
		if ( is_array( $rates ) && isset( $rates[ $base ] ) ) {
			$price = $price * $rates[ $base ];
		}

		return $price;
	}


}

INFCWDS_Plugin_Compatibilities::register( new INFCWDS_Compatibility_With_WooMultiCurrency_F(), 'woomulticurrency_f' );



