<?php
namespace InfCrowds\WPR\Gateways;
use  InfCrowds\WPR\Gateways\PaypalReqs\Paypal_SetExpressCheckout_Request;
use  InfCrowds\WPR\Gateways\PaypalReqs\Paypal_DoExpressCheckout_Request;
use INFCWDS_WC_Compatibility;


abstract class GatewayPayPalBase_Integration extends GatewayIntegrationBase {

    public function __construct($store, $order_maker_factory) {
		parent::__construct($store, $order_maker_factory);
    }



	/**
	 * There was a valid response.
	 *
	 * @param  array $posted Post data after wp_unslash.
	 */
	public function handle_paypal_ipn_and_record_response( $posted ) {
		InfcrwdsPlugin()->logger->log( 'Data collected from IPN' . print_r( $posted, true ) );
		$custom = json_decode( $posted['custom'] );
		$order_id = null;
		if ( $custom && is_object( $custom ) ) {
			$order_id = $custom->order_id;
		}
		if(empty($order_id)) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order && $order instanceof \WC_Order && isset( $posted['payment_status'] ) ) {
			$infcrwds_flag = INFCWDS_WC_Compatibility::get_order_meta($order, '_infcrwds');
			if(!empty($infcrwds_flag) && $infcrwds_flag === '1' && INFCWDS_WC_Compatibility::get_order_meta($order, '_infcrwds_internal_upsell') === 'yes') {
				$has_sibling = INFCWDS_WC_Compatibility::get_order_meta($order, '_infcrwds_sibling_order');
				if(!empty($has_sibling) && $has_sibling) {
					return;
				}
				# this is merged order

				$is_failed = false;
				switch ( $posted['payment_status'] ) {
					case 'failed':
					case 'denied':
					case 'expired':
						$is_failed = true;
						break;
					default:
						break;
				}
				if(!$is_failed) {
					return;
				}
				InfcrwdsPlugin()->logger->error("received failed infcrwds ipn response, chaning order to processing with order note");
				$item_name = $posted['item_name'];
  				$item_number = $posted['item_number'];
				$payment_amount = $posted['mc_gross'];
				$payment_currency = $posted['mc_currency'];

				$order->set_status( 'processing', sprintf( __( 'InfinityCrowds PayPal IPN Failed! Upsell Item %s (number %s) with the amount %s %s. do not ship this item!', 'infcrwds' ), 
					$item_name, $item_number, $payment_amount, $payment_currency));
			}
		}
	}

    public function begin_express_checkout_payment($use_deprecated_params,
		$session_id,
		$order,
		$has_shipping) {
		
		$request = new Paypal_SetExpressCheckout_Request($use_deprecated_params, $this, $session_id);
		
		$request->initialize(INFCWDS_WC_Compatibility::get_order_currency( $order ),
			$this->get_callback_url( 'infcrwds_paypal_return' ),
			$this->get_callback_url( 'cancel_url' ),
			$this->get_callback_url( 'notify_url' ),
			$has_shipping, 'Login');
		
		return $request;
    }
    
    public abstract function get_environment();
	/**
	 * Get the wc-api URL to redirect to
	 *
	 * @param string $action checkout action, either `set_express_checkout or `get_express_checkout_details`
	 *
	 * @return string URL
	 * @since 2.0
	 */
	public function get_callback_url( $action ) {
		return add_query_arg( 'action', $action, WC()->api_request_url( 'infcrwds_' . $this->get_key() ) );
    }
    
    public function handle_api_calls() {
		if ( ! isset( $_GET['action'] ) ) {
			return;
		}

		$selected_offer = $this->_store->get_selected_offer();
		$session_id = $this->_store->get_current_ic_session_id();
		$order = $this->_store->get_base_order();

		switch ( $_GET['action'] ) {

			case 'infcrwds_paypal_return':
				
				if ( isset( $_GET['token'] ) && ! empty( $_GET['token'] ) ) {
				
					$express_checkout_details_response = $this->get_express_checkout_details( $_GET['token'] );
					// InfcrwdsPlugin()->logger->log( '$express_checkout_details_response ' . print_r( $express_checkout_details_response, true ) );


					/***
					 * Get session ID from the response from the PayPal
					 */
					$get_session = $this->get_session_from_response( $express_checkout_details_response );

					if ( empty( $selected_offer) || empty($get_session) ) {
						InfcrwdsPlugin()->logger->log( 'PayPal Express Checkout API return does not have a valid offer set. ' );
						exit;
					}
					/**
					 * Setting up necessary data for this api call
					 */
					add_filter( 'infcrwds_valid_state_for_data_setup', '__return_true' );
					
					$api_response_result = false;
					/**
					 * get the data we saved while calling setExpressCheckout call
					 */
					$get_paypal_data = WC()->session->get( 'paypal_request_data', array(), 'paypal' );

					$transaction_id = null;
					/**
					 * Usually We do not process 0 amount process, we can safely assume here that if o amount is passed by the API we can treat it as successful upsell
					 */
					if ( $selected_offer['pricing']['sale_price'] > 0 ) {
						/**
						 * Prepare DoExpessCheckout Call to finally charge the user
						 */
						$do_express_checkout_data = array(
							'TOKEN'   => $express_checkout_details_response['TOKEN'],
							'PAYERID' => $express_checkout_details_response['PAYERID'],
							'METHOD'  => 'DoExpressCheckoutPayment',
						);

						$do_express_checkout_data = wp_parse_args( $do_express_checkout_data, $get_paypal_data );

						$environment      = $this->get_environment();
						$api_creds_prefix = '';
						if ( 'sandbox' === $environment ) {
							$api_creds_prefix = 'sandbox_';
						}

						/**
						 * Setup & perform DoExpressCheckout API Call
						 */
						$this->set_api_credentials( $this->get_key(), $environment, $this->get_wc_gateway()->get_option( $api_creds_prefix . 'api_username' ), $this->get_wc_gateway()->get_option( $api_creds_prefix . 'api_password' ), $this->get_wc_gateway()->get_option( $api_creds_prefix . 'api_signature' ) );
						$this->add_parameters( $do_express_checkout_data );
						$this->populate_credentials( $this->api_username, $this->api_password, $this->api_signature, 124 );

						$request         = new \stdClass();
						$request->path   = '';
						$request->method = 'POST';
						$request->body   = $this->to_string();

						$response_checkout = $this->perform_request( $request );
						InfcrwdsPlugin()->logger->error( 'PayPal In-offer transactions DoexpressCheckout response. ' . print_r( $response_checkout, true ) );

						if ( false === $this->has_api_error( $response_checkout ) ) {
							$transaction_id = $this->get_transaction_id( $response_checkout );
							WC()->session->set('_transaction_id', $transaction_id );
							$api_response_result = true;
						}
					} else {
						$api_response_result = true;
					}

					/**** DoExpressCheckout Call Completed ******/

					/**
					 * Allow our subscription addon to make subscription request
					 */
					$api_response_result = apply_filters( 'infcrwds_gateway_in_offer_transaction_paypal_after_express_checkout_response', $api_response_result, $express_checkout_details_response['TOKEN'], $express_checkout_details_response['PAYERID'], $this );
					if($api_response_result) {
						$this->write_success_order($selected_offer, $order, $session_id, $transaction_id);
					}

					exit;
				} else {
					/**
					 * Set the upsell package data so that order processing will process this
					 */
					$this->handle_upsell_cancelation($selected_offer, $order, $session_id, true);
				}

				break;

			case 'cancel_url':
				$selected_offer = $this->_store->get_selected_offer();
				$this->handle_upsell_cancelation($selected_offer, $order, $session_id, true);
				break;
		}
    }
    
    	/**
	 * @param WC_Order $order
	 */
	function maybe_add_shipping_address_params( $order, $prefix = 'PAYMENTREQUEST_0_SHIPTO' ) {

		$params = $this->get_shipping_address_params($order, $prefix);
		foreach ( $params as $key => $val ) {
			$this->add_parameter( $key, $val );
		}
	}

    public abstract function set_api_credentials( $gateway_id, $api_environment, $api_username, $api_password, $api_signature );

		/**
	 * @param WC_Order $order
	 */
	function get_shipping_address_params( $order, $prefix = 'PAYMENTREQUEST_0_SHIPTO' ) {

		if ( $order->has_shipping_address() ) {
			$shipping_first_name = $order->get_shipping_first_name();
			$shipping_last_name  = $order->get_shipping_last_name();
			$shipping_address_1  = $order->get_shipping_address_1();
			$shipping_address_2  = $order->get_shipping_address_2();
			$shipping_city       = $order->get_shipping_city();
			$shipping_state      = $order->get_shipping_state();
			$shipping_postcode   = $order->get_shipping_postcode();
			$shipping_country    = $order->get_shipping_country();
		} else {
			$shipping_first_name = $order->get_billing_first_name();
			$shipping_last_name  = $order->get_billing_last_name();
			$shipping_address_1  = $order->get_billing_address_1();
			$shipping_address_2  = $order->get_billing_address_2();
			$shipping_city       = $order->get_billing_city();
			$shipping_state      = $order->get_billing_state();
			$shipping_postcode   = $order->get_billing_postcode();
			$shipping_country    = $order->get_billing_country();
		}
		if ( empty( $shipping_country ) ) {
			$shipping_country = WC()->countries->get_base_country();
		}


		$shipping_phone = $order->get_billing_phone();


		$params = array(
			$prefix . 'NAME'        => $shipping_first_name . ' ' . $shipping_last_name,
			$prefix . 'STREET'      => $shipping_address_1,
			$prefix . 'STREET2'     => $shipping_address_2,
			$prefix . 'CITY'        => $shipping_city,
			$prefix . 'STATE'       => $shipping_state,
			$prefix . 'ZIP'         => $shipping_postcode,
			$prefix . 'COUNTRYCODE' => $shipping_country,
			$prefix . 'PHONENUM'    => $shipping_phone,
		);

		return $params;
    }
    
	/**
	 * Checks if currency in setting supports 0 decimal places.
	 *
	 * @since 1.2.0
	 *
	 * @return bool Returns true if currency supports 0 decimal places
	 */
	public function is_currency_supports_zero_decimal() {
		return in_array( get_woocommerce_currency(), array( 'HUF', 'JPY', 'TWD' ) );
	}

	/**
	 * Get number of digits after the decimal point.
	 *
	 * @since 1.2.0
	 *
	 * @return int Number of digits after the decimal point. Either 2 or 0
	 */
	public function get_number_of_decimal_digits() {
		return $this->is_currency_supports_zero_decimal() ? 0 : 2;
	}
	
    /**
	 * PayPal cannot properly calculate order totals when prices include tax (due
	 * to rounding issues), so line items are skipped and the order is sent as
	 * a single item
	 *
	 * @since 2.0.9
	 *
	 * @param WC_Order $order Optional. The WC_Order object. Default null.
	 *
	 * @return bool true if line items should be skipped, false otherwise
	 */
	public function skip_line_items( $order = null ) {

		$skip_line_items = false;
		// Also check actual totals add up just in case totals have been manually modified to amounts that can not round correctly, see https://github.com/Prospress/woocommerce-subscriptions/issues/2213
		if ( ! is_null( $order ) ) {


			$rounded_total = 0;
			$decimals      = $this->get_number_of_decimal_digits();
			foreach ( $order->get_items() as $cart_item_key => $values ) {
				$amount        = round( $values['line_subtotal'] / $values['qty'], $decimals );
				$rounded_total += round( $amount * $values['qty'], $decimals );
			}


			$discounts = $order->get_total_discount();

			$items = array();
			foreach ( $order->get_items() as $cart_item_key => $values ) {
				$amount = round( $values['line_subtotal'] / $values['qty'], $decimals );
				$item   = array(
					'name'     => $values['name'],
					'quantity' => $values['qty'],
					'amount'   => $amount,
				);

				$items[] = $item;
			}
			$details                = array(
				'total_item_amount' => round( $order->get_subtotal(), $decimals ) + $discounts,
				'order_tax'         => round( $order->get_total_tax(), $decimals ),
				'shipping'          => round( $order->get_shipping_total(), $decimals ),
				'items'             => $items,
			);
			$details['order_total'] = round( $details['total_item_amount'] + $details['order_tax'] + $details['shipping'], $decimals );

			if ( $details['total_item_amount'] != $rounded_total ) {
				$skip_line_items = true;
			}
		}


		/**
		 * Filter whether line items should be skipped or not
		 *
		 * @since 3.3.0
		 *
		 * @param bool $skip_line_items True if line items should be skipped, false otherwise
		 * @param WC_Order /null $order The WC_Order object or null.
		 */
		return apply_filters( 'infcrwds_paypal_skip_line_items', $skip_line_items, $order );
    }
    

	/**
	 * Construct an PayPal Express request object
	 *
	 * @param string $api_username the API username
	 * @param string $api_password the API password
	 * @param string $api_signature the API signature
	 * @param string $api_version the API version
	 *
	 * @since 2.0
	 */
	public function create_credentials( $api_username, $api_password, $api_signature, $api_version ) {

		return array(
			'USER'      => $api_username,
			'PWD'       => $api_password,
			'SIGNATURE' => $api_signature,
			'VERSION'   => $api_version,
		);
    }
    


	/**
	 * Returns the request parameters after validation & filtering
	 *
	 * @throws \Exception invalid amount
	 * @return array request parameters
	 * @since 2.0
	 */
	public function parse_parameters($parameters) {

		/**
		 * Filter PPE request parameters.
		 *
		 * Use this to modify the PayPal request parameters prior to validation
		 *
		 * @param array $parameters
		 * @param \WC_PayPal_Express_API_Request $this instance
		 */
		$parameters = apply_filters( 'wcs_paypal_request_params', $parameters, $this );

		// validate parameters
		foreach ( $parameters as $key => $value ) {

			// remove unused params
			if ( '' === $value || is_null( $value ) ) {
				unset( $parameters[ $key ] );
			}

			// format and check amounts
			if ( false !== strpos( $key, 'AMT' ) ) {

				// amounts must be 10,000.00 or less for USD
				if ( isset( $parameters['PAYMENTREQUEST_0_CURRENCYCODE'] ) && 'USD' == $parameters['PAYMENTREQUEST_0_CURRENCYCODE'] && $value > 10000 ) {

					throw new Exception( sprintf( '%s amount of %s must be less than $10,000.00', $key, $value ) );
				}

				// PayPal requires locale-specific number formats (e.g. USD is 123.45)
				// PayPal requires the decimal separator to be a period (.)
				$parameters[ $key ] = $this->price_format( $value );
			}
		}
		return $parameters;
    }
    

    public function get_api_error( $response ) {


		if ( 'Failure' == $this->get_value_from_response( $response, 'ACK' ) ) {
			return $this->get_value_from_response( $response, 'L_LONGMESSAGE0' );
		}

		return '';
    }
    

	/**
	 * Format prices.
	 *
	 * @since 2.2.12
	 *
	 * @param float|int $price
	 * @param int $decimals Optional. The number of decimal points.
	 *
	 * @return string
	 */
	public function price_format( $price, $decimals = 2 ) {
		return number_format( $price, $decimals, '.', '' );
	}
}