<?php
namespace InfCrowds\WPR\Gateways;

use INFCWDS_WC_Compatibility;


class GatewayAuthorizeNetCIM_Integration extends GatewayIntegrationBase {

	public $token = false;
	public $customer_id = false;
	public $unset_opaque_value = false;
	public $order = false;
	protected $key = 'authorize_net_cim_credit_card';

	/**
	 * Constructor
	 */
	public function __construct($store, $order_maker_factory) {

		parent::__construct($store, $order_maker_factory);

		/**
		 * Telling Authorize gateway to force tokenize and do not ask user as an option during checkout
		 */
		add_filter( 'wc_payment_gateway_' . $this->get_key() . '_tokenization_forced', array( $this, 'maybe_force_tokenization' ) );

		/**
		 * For a non logged in mode when accept js is turned off, we just need to tokenize the card after the main charge gets completed
		 */
		add_action( 'woocommerce_pre_payment_complete', array( $this, 'maybe_create_token' ), 10, 1 );

		/**
		 * For the case when User is not logged in and accept js is on.
		 * We have to get the full control of the main checkout payment.
		 */
		// add_filter( 'wc_payment_gateway_' . $this->get_key() . '_process_payment', array( $this, 'process_payment' ), 10, 2 );

		add_action( 'infcrwds_upsell_success_order_updated', function () {
			remove_action( 'woocommerce_pre_payment_complete', array( $this, 'maybe_create_token' ), 10, 1 );
		}, - 1 );

		add_action( 'wc_payment_gateway_' . $this->get_key() . '_add_transaction_data', array( $this, 'maybe_add_shipping_address_id_order_for_guests' ), 10, 1 );

		$this->refund_supported = true;

		//Modifying refund request data in case of offer refund to add offer transaction id
		// add_filter( 'wc_authorize_net_cim_api_request_data', array( $this, 'wfocu_modify_refund_request_data' ), 10, 3 );

	}

	public function maybe_force_tokenization( $is_tokenize ) {

		return $this->is_enabled() ? true : $is_tokenize;
	}


	public function process_payment( $result, $order_id ) {

		$result = true;
		$order  = $this->get_wc_gateway()->get_order( $order_id );

		if ( $this->should_tokenize() && $this->get_wc_gateway()->is_accept_js_enabled() && 0 == $order->get_user_id() ) {
			
			try {

				// using an existing tokenized payment method
				if ( isset( $order->payment->token ) && $order->payment->token ) {

					$this->get_wc_gateway()->add_transaction_data( $order );

				} else {
					$order_for_shipping = $order;
					// otherwise tokenize the payment method
					try {
						$order                   = $this->get_wc_gateway()->get_payment_tokens_handler()->create_token( $order );
						$this->is_order_modified = true;
						$this->modified_order    = $order;
					} catch ( Exception $e ) {

						$re  = '/[0-9]+/';
						$str = $e->getMessage();

						preg_match_all( $re, $str, $matches, PREG_SET_ORDER, 0 );

						if ( $matches && is_array( $matches ) && isset( $matches[0][0] ) && '00039' == $matches[0][0] ) {

							$get_order_by_meta = new WP_Query( array(
								'post_type'   => 'shop_order',
								'post_status' => 'any',
								'meta_query'  => array(
									array(
										'key'     => '_wc_authorize_net_cim_credit_card_customer_id',
										'value'   => $matches[1][0],
										'compare' => '=',
									),
								),
								'fields'      => 'ids',
								'order'       => 'ASC',
							) );

							if ( is_array( $get_order_by_meta->posts ) && count( $get_order_by_meta->posts ) > 0 ) {

								$this->_store->session_set( 'infcrwds_authorize_net_cim_order_id', $get_order_by_meta->posts[0] );
								$order_for_shipping = $this->get_wc_gateway()->get_order( $get_order_by_meta->posts[0] );
								$this->_store->session_set( 'infcrwds_authorize_net_cim_customer_id', $matches[1][0] );
							}
						}
					}
					// otherwise tokenize the payment method
					$this->unset_opaque_value = true;
					$order                    = $this->get_order( $order );
					$this->get_wc_gateway()->add_transaction_data( $order );
					/**
					 * We need to create shipping ID for the current user on Authorize.Net CIM API
					 * As ShippingAddressID is important for the cases when business owner has shipping-filters enabled in their merchant account.
					 *
					 */
					try {

						/**
						 * When we are in a case when there is a returning user & not logged in then in this case there are chances that shipping API request might fail.
						 * In this case we need to try and get shipping ID from the order meta and set this up for further.
						 */
						$response = $this->get_wc_gateway()->get_api()->create_shipping_address( $order );

					} catch ( Exception $e ) {

						$response = intval( $order_for_shipping->get_meta( '_authorize_cim_shipping_address_id' ) );

					}

					$shipping_address_id                 = is_numeric( $response ) ? $response : $response->get_shipping_address_id();
					$order->payment->shipping_address_id = $shipping_address_id;
					$this->_store->session_set( 'infcrwds_authorize_net_cim_shipping_id', $order->payment->shipping_address_id);

					$this->get_wc_gateway()->add_transaction_data( $order );
					$this->do_main_transaction( $order );
				}

				$result = array(
					'result'   => 'success',
					'redirect' => $this->get_wc_gateway()->get_return_url( $order ),
				);
			} catch ( Exception $e ) {
				$result = array(
					'result'  => 'failure',
					'message' => $e->getMessage(),
				);

			}
		}

		return $result;
	}

	public function get_order( $order ) {

		if ( $order instanceof \WC_Order && $this->key === $order->get_payment_method() ) {

			if ( $this->has_token( $order ) && ! is_checkout_pay_page() ) {

				// retrieve the payment token
				$order->payment->token = $this->get_wc_gateway()->get_order_meta( INFCWDS_WC_Compatibility::get_order_data( $order, 'id' ), 'payment_token' );
				$token_from_gateway    = $this->get_token( $order );
				if ( empty( $order->payment->token ) && ! empty( $token_from_gateway ) ) {
					$order->payment->token = $token_from_gateway;
				}
				// retrieve the optional customer id
				$order->customer_id = $this->get_wc_gateway()->get_order_meta( INFCWDS_WC_Compatibility::get_order_data( $order, 'id' ), 'customer_id' );

				$customer_id_from_session = $this->_store->session_get( 'infcrwds_authorize_net_cim_customer_id' );
				if ( empty( $order->customer_id ) && ! empty( $customer_id_from_session ) ) {
					$order->customer_id = $this->_store->session_get( 'infcrwds_authorize_net_cim_customer_id' );
				}
				// set token data on order
				if ( $this->get_wc_gateway()->get_payment_tokens_handler()->user_has_token( $order->get_user_id(), $order->payment->token ) ) {

					// an existing registered user with a saved payment token
					$token = $this->get_wc_gateway()->get_payment_tokens_handler()->get_token( $order->get_user_id(), $order->payment->token );

					// account last four
					$order->payment->account_number = $token->get_last_four();

					if ( $this->get_wc_gateway()->is_credit_card_gateway() ) {

						// card type
						$order->payment->card_type = $token->get_card_type();

						// exp month/year
						$order->payment->exp_month = $token->get_exp_month();
						$order->payment->exp_year  = $token->get_exp_year();

					} elseif ( $this->get_wc_gateway()->is_echeck_gateway() ) {

						// account type (checking/savings)
						$order->payment->account_type = $token->get_account_type();
					}
				} else {

					// a guest user means that token data must be set from the original order

					// account number
					$order->payment->account_number = $this->get_wc_gateway()->get_order_meta( INFCWDS_WC_Compatibility::get_order_data( $order, 'id' ), 'account_four' );

					if ( $this->get_wc_gateway()->is_credit_card_gateway() ) {

						// card type
						$order->payment->card_type = $this->get_wc_gateway()->get_order_meta( INFCWDS_WC_Compatibility::get_order_data( $order, 'id' ), 'card_type' );

						// expiry date
						if ( $expiry_date = $this->get_wc_gateway()->get_order_meta( INFCWDS_WC_Compatibility::get_order_data( $order, 'id' ), 'card_expiry_date' ) ) {
							list( $exp_year, $exp_month ) = explode( '-', $expiry_date );
							$order->payment->exp_month = $exp_month;
							$order->payment->exp_year  = $exp_year;
						}
					} elseif ( $this->get_wc_gateway()->is_echeck_gateway() ) {

						// account type
						$order->payment->account_type = $this->get_wc_gateway()->get_order_meta( INFCWDS_WC_Compatibility::get_order_data( $order, 'id' ), 'account_type' );
					}
				}
			}

			$response = intval( $order->get_meta( '_authorize_cim_shipping_address_id' ) );
			if ( ! empty( $response ) ) {
				$order->payment->shipping_address_id = $response;
			}

			if ( true === $this->unset_opaque_value && isset( $order->payment->opaque_value ) ) {
				unset( $order->payment->opaque_value );
			}
		}

		return $order;
	}

	public function add_payment_options($order, $payment_options) {
		if($this->has_token($order)) {
			return parent::add_payment_options($order, $payment_options);
		}
		return $payment_options;
	}
	/**
	 * Try and get the payment token saved by the gateway
	 *
	 * @param WC_Order $order
	 *
	 * @return true on success false otherwise
	 */

	public function has_token( $order ) {
		$get_id = INFCWDS_WC_Compatibility::get_order_id( $order );

		$this->token = get_post_meta( $get_id, '_wc_' . $this->get_key() . '_payment_token', true );

		if ( ! empty( $this->token ) ) {
			return true;
		}

		/**
		 * Fallback when token is not present in the parent order
		 */
		$get_secondary_order = $this->_store->session_get( 'infcrwds_authorize_net_cim_order_id' );

		if ( empty( $get_secondary_order ) ) {
			return false;
		}

		$this->token = get_post_meta( $get_secondary_order, '_wc_' . $this->get_key() . '_payment_token', true );

		if ( ! empty( $this->token ) ) {
			return true;
		}

		return false;

	}

	/**
	 * Try and get the payment token saved by the gateway
	 *
	 * @param WC_Order $order
	 *
	 * @return true on success false otherwise
	 */

	public function get_token( $order ) {
		$get_id = INFCWDS_WC_Compatibility::get_order_id( $order );

		$this->token = get_post_meta( $get_id, '_wc_' . $this->get_key() . '_payment_token', true );

		if ( ! empty( $this->token ) ) {
			return $this->token;
		}

		/**
		 * Fallback when token is not present in the parent order
		 */
		$get_secondary_order = $this->_store->session_get( 'authorize_net_cim_order_id' );

		if ( empty( $get_secondary_order ) ) {
			return '';
		}

		$this->token = get_post_meta( $get_secondary_order, '_wc_' . $this->get_key() . '_payment_token', true );

		if ( ! empty( $this->token ) ) {
			return $this->token;
		}

		return '';

	}

	/**
	 * We cloned the function that we need to fire main transaction in the case when accept.js in is action and user is not logged in.
	 *
	 * @param WC_Order $order
	 */
	private function do_main_transaction( $order ) {
		try {

			// order description
			$order->description = sprintf( __( '%1$s - Release Payment for Order %2$s', 'woocommerce-plugin-framework' ), esc_html( $this->sv_helper_compatibility( 'get_site_name' ) ), $order->get_order_number() );

			// token is required
			if ( ! $order->payment->token ) {
				throw new Exception( __( 'Payment token missing/invalid.', 'woocommerce-plugin-framework' ) );
			}

			// perform the transaction
			if ( $this->get_wc_gateway()->is_credit_card_gateway() ) {

				if ( $this->get_wc_gateway()->perform_credit_card_charge( $order ) ) {
					$response = $this->get_wc_gateway()->get_api()->credit_card_charge( $order );
				} else {
					$response = $this->get_wc_gateway()->get_api()->credit_card_authorization( $order );
				}
			} elseif ( $this->get_wc_gateway()->is_echeck_gateway() ) {
				$response = $this->get_wc_gateway()->get_api()->check_debit( $order );
			}

			// success! update order record
			if ( $response->transaction_approved() ) {

				$last_four = substr( $order->payment->account_number, - 4 );

				// order note based on gateway type
				if ( $this->get_wc_gateway()->is_credit_card_gateway() ) {

					$message = sprintf( __( '%1$s %2$s Release Payment Approved: %3$s ending in %4$s (expires %5$s)', 'woocommerce-plugin-framework' ), $this->get_wc_gateway()->get_method_title(), $this->get_wc_gateway()->perform_credit_card_authorization( $order ) ? 'Authorization' : 'Charge', $this->sv_wc_payment_gateway( 'payment_type_to_name', array( ! empty( $order->payment->card_type ) ? $order->payment->card_type : 'card' ) ), $last_four, ( ! empty( $order->payment->exp_month ) && ! empty( $order->payment->exp_year ) ? $order->payment->exp_month . '/' . substr( $order->payment->exp_year, - 2 ) : 'n/a' ) );

				} elseif ( $this->get_wc_gateway()->is_echeck_gateway() ) {

					// account type (checking/savings) may or may not be available, which is fine
					$message = sprintf( __( '%1$s eCheck Release Payment Approved: %2$s ending in %3$s', 'woocommerce-plugin-framework' ), $this->get_gateway()->get_method_title(), $this->sv_wc_payment_gateway( 'payment_type_to_name', array(
						( ! empty( $order->payment->account_type ) ? $order->payment->account_type : 'bank' ),
						$last_four,
					) ) );

				}

				// adds the transaction id (if any) to the order note
				if ( $response->get_transaction_id() ) {
					$message .= ' ' . sprintf( __( '(Transaction ID %s)', 'woocommerce-plugin-framework' ), $response->get_transaction_id() );
				}

				$order->add_order_note( $message );
			}

			if ( $response->transaction_approved() || $response->transaction_held() ) {

				// add the standard transaction data
				$this->get_wc_gateway()->add_transaction_data( $order, $response );

				// allow the concrete class to add any gateway-specific transaction data to the order
				$this->get_wc_gateway()->add_payment_gateway_transaction_data( $order, $response );

				// if the transaction was held (ie fraud validation failure) mark it as such
				if ( $response->transaction_held() || ( $this->get_wc_gateway()->supports( 'authorization' ) && $this->get_wc_gateway()->perform_credit_card_authorization( $order ) ) ) {

					$this->get_wc_gateway()->mark_order_as_held( $order, $this->get_wc_gateway()->supports( 'authorization' ) && $this->get_wc_gateway()->perform_credit_card_authorization( $order ) ? __( 'Authorization only transaction', 'woocommerce-plugin-framework' ) : $response->get_status_message(), $response );

					wc_reduce_stock_levels( $order->get_id() );
				} else {
					// otherwise complete the order
					$order->payment_complete();
				}
			} else {

				// failure
				throw new Exception( sprintf( '%s: %s', $response->get_status_code(), $response->get_status_message() ) );

			}
		} catch ( Exception $e ) {

			// Mark order as failed
			$this->get_wc_gateway()->mark_order_as_failed( $order, sprintf( __( 'Pre-Order Release Payment Failed: %s', 'woocommerce-plugin-framework' ), $e->getMessage() ) );

		}
	}

	protected function sv_helper_compatibility( $function_name, $args = array() ) {

		if ( class_exists( 'SkyVerge\WooCommerce\PluginFramework\v5_1_4\SV_WC_Helper' ) ) {
			return call_user_func_array( array( 'SkyVerge\WooCommerce\PluginFramework\v5_1_4\SV_WC_Helper', $function_name ), $args );
		} elseif ( class_exists( 'SkyVerge\WooCommerce\PluginFramework\v5_1_2\SV_WC_Helper' ) ) {
			return call_user_func_array( array( 'SkyVerge\WooCommerce\PluginFramework\v5_1_2\SV_WC_Helper', $function_name ), $args );
		} elseif ( class_exists( 'SkyVerge\WooCommerce\PluginFramework\v5_3_0\SV_WC_Helper' ) ) {
			return call_user_func_array( array( 'SkyVerge\WooCommerce\PluginFramework\v5_3_0\SV_WC_Helper', $function_name ), $args );
		} else {
			return call_user_func_array( array( 'SV_WC_Helper', $function_name ), $args );

		}
	}

	protected function sv_wc_payment_gateway( $function_name, $args = array() ) {

		if ( class_exists( 'SkyVerge\WooCommerce\PluginFramework\v5_3_0\SV_WC_Payment_Gateway_Helper' ) ) {
			return call_user_func_array( array( 'SkyVerge\WooCommerce\PluginFramework\v5_3_0\SV_WC_Payment_Gateway_Helper', $function_name ), $args );
		} elseif ( class_exists( 'SkyVerge\WooCommerce\PluginFramework\v5_1_4\SV_WC_Payment_Gateway_Helper' ) ) {
			return call_user_func_array( array( 'SkyVerge\WooCommerce\PluginFramework\v5_1_4\SV_WC_Payment_Gateway_Helper', $function_name ), $args );
		} elseif ( class_exists( 'SkyVerge\WooCommerce\PluginFramework\v5_1_2\SV_WC_Payment_Gateway_Helper' ) ) {
			return call_user_func_array( array( 'SkyVerge\WooCommerce\PluginFramework\v5_1_2\SV_WC_Payment_Gateway_Helper', $function_name ), $args );
		} else {
			return call_user_func_array( array( 'SV_WC_Payment_Gateway_Helper', $function_name ), $args );

		}
	}

	public function maybe_create_token( $order ) {

		$order_base = wc_get_order( $order );
		if ( $order_base instanceof \WC_Order && $this->key === $order_base->get_payment_method() ) {

			$order = $this->get_wc_gateway()->get_order( $order );
			if ( $this->should_tokenize() && 0 == $order->get_user_id() ) {

				if ( isset( $order->payment->token ) && $order->payment->token ) {

					$this->get_wc_gateway()->add_transaction_data( $order );

				} else {
					/**
					 * Handling some error from Authorize.net CIM API throwing error
					 * This error shows up when same phone number/name/email used to create token
					 */
					$order_for_shipping = $order;
					// otherwise tokenize the payment method
					try {
						$order                   = $this->get_wc_gateway()->get_payment_tokens_handler()->create_token( $order );
						$this->is_order_modified = true;
						$this->modified_order    = $order;
					} catch ( Exception $e ) {

						$re  = '/[0-9]+/';
						$str = $e->getMessage();

						preg_match_all( $re, $str, $matches, PREG_SET_ORDER, 0 );

						if ( $matches && is_array( $matches ) && isset( $matches[0][0] ) && '00039' == $matches[0][0] ) {

							$get_order_by_meta = new \WP_Query( array(
								'post_type'   => 'shop_order',
								'post_status' => 'any',
								'meta_query'  => array(
									array(
										'key'     => '_wc_authorize_net_cim_credit_card_customer_id',
										'value'   => $matches[1][0],
										'compare' => '=',
									),
								),
								'fields'      => 'ids',
								'order'       => 'ASC',
							) );

							if ( is_array( $get_order_by_meta->posts ) && count( $get_order_by_meta->posts ) > 0 ) {

								$this->_store->session_set( 'infcrwds_authorize_net_cim_order_id', $get_order_by_meta->posts[0]);
								$order_for_shipping = $this->get_wc_gateway()->get_order( $get_order_by_meta->posts[0] );
								$this->_store->session_set( 'infcrwds_authorize_net_cim_customer_id', $matches[1][0] );
							}
						}
					}

					/**
					 * We need to create shipping ID for the current user on Authorize.Net CIM API
					 * As ShippingAddressID is important for the cases when business owner has shipping-filters enabled in their merchant account.
					 *
					 */
					try {

						/**
						 * When we are in a case when there is a returning user & not logged in then in this case there are chances that shipping API request might fail.
						 * In this case we need to try and get shipping ID from the order meta and set this up for further.
						 */
						$response = $this->get_wc_gateway()->get_api()->create_shipping_address( $order );

					} catch ( Exception $e ) {

						$response = intval( $order_for_shipping->get_meta( '_authorize_cim_shipping_address_id' ) );

					}

					$shipping_address_id                 = is_numeric( $response ) ? $response : $response->get_shipping_address_id();
					$order->payment->shipping_address_id = $shipping_address_id;
					$this->_store->session_set( 'infcrwds_authorize_net_cim_shipping_id', $order->payment->shipping_address_id );
				}
			}
		}
	}

	public function process_charge( $order, $offer ) {

		$is_successful = false;
		$trans_id = null;
		try {
			$api         = $this->get_wc_gateway()->get_api();
			$environment = $this->get_wc_gateway()->get_environment();
			$url         = ( 'production' === $environment ) ? $api::PRODUCTION_ENDPOINT : $api::TEST_ENDPOINT;

			$gateway = $this->get_wc_gateway();
			/**
			 * Modify order object and populate payment related info as per different scenarios
			 */
			add_filter( 'wc_payment_gateway_' . $this->get_key() . '_get_order', array( $this, 'get_order' ), 999 );

			$this->order = $gateway->get_order( $order );
			$request     = $this->create_transaction_request( 'capture', $offer );
			InfcrwdsPlugin()->logger->error( 'AUTHORIZE CIM REQUEST :', print_r( $request, true ) );

			$response = wp_safe_remote_request( $url, $this->get_request_attributes( $request ) );
			$body     = wp_remote_retrieve_body( $response );
			$body     = preg_replace( '/\xEF\xBB\xBF/', '', $body );
			$result   = json_decode( $body, true );
			if ( is_wp_error( $response ) ) {
				InfcrwdsPlugin()->logger->error( 'AUTHORIZE CIM RESPONSE :', print_r( $response, true ) );
				$is_successful = false;
			} else {

				if ( isset( $result['messages'] ) && isset( $result['messages']['resultCode'] ) && 'Error' == $result['messages']['resultCode'] ) {
					InfcrwdsPlugin()->logger->error( 'AUTHORIZE CIM ERROR :', print_r( $result, true ) );
					$order_note = sprintf( __( 'OCU - Authorize.net CIM Transaction Failed (%s)', 'infcrwds' ), $result['messages']['message']['text'] );
					$order->add_order_note( $order_note );
					$is_successful = false;
				} else {
					$trans_id = $this->get_transaction_id( $result['directResponse'] );
					// $this->_store->session_set( '_transaction_id', $trans_id );

					$is_successful = true;
				}
			}
		} catch ( Exception $e ) {
			InfcrwdsPlugin()->logger->error( 'AUTHORIZE CIM ERROR :', print_r( $e, true ) );

			$order_note = sprintf( __( 'OCU - Authorize.net CIM Transaction Failed (%s)', 'infcrwds' ), $e->getMessage() );
			$order->add_order_note( $order_note );
		}

		return array($is_successful, $trans_id);
	}

	protected function create_transaction_request( $type, $offer ) {
		$order            = $this->order;
		$transaction_type = ( 'auth_only' === $type ) ? 'profileTransAuthOnly' : 'profileTransAuthCapture';
		// $get_package      = $this->_store->session_get( '_upsell_package' );

		/**
		 * We need to create shipping ID for the current user on Authorize.Net CIM API
		 * As ShippingAddressID is important for the cases when business owner has shipping-filters enabled in their merchant account.
		 *
		 */
		$maybe_get_shipping_id_from_session = $this->_store->session_get( 'infcrwds_authorize_net_cim_shipping_id');

		if ( isset( $order->payment ) && isset( $order->payment->shipping_address_id ) && ! empty( $order->payment->shipping_address_id ) ) {
			$shipping_address_id = $order->payment->shipping_address_id;
		} elseif ( ! empty( $maybe_get_shipping_id_from_session ) ) {
			$shipping_address_id = $maybe_get_shipping_id_from_session;
		} else {
			$response = $this->get_wc_gateway()->get_api()->create_shipping_address( $order );

			InfcrwdsPlugin()->logger->error( 'Log for shipping address-' . print_r( $response, true ) );

			$shipping_address_id = is_numeric( $response ) ? $response : $response->get_shipping_address_id();

		}
		$pricing = $offer['pricing'];
		$taxes = $pricing['tax_cost'] + $pricing['shipping_tax'];
		$shipping_price = $pricing['shipping_price'];
		$item_price = $pricing['sale_price'];
		return array(
			'createCustomerProfileTransactionRequest' => array(
				'merchantAuthentication' => array(
					'name'           => wc_clean( $this->get_wc_gateway()->get_api_login_id() ),
					'transactionKey' => wc_clean( $this->get_wc_gateway()->get_api_transaction_key() ),
				),
				'refId'                  => $this->get_order_number( $order, $offer ),
				'transaction'            => array(
					$transaction_type => array(
						'amount'                    => $taxes + $shipping_price + $item_price,
						'tax'                       => $this->get_taxes(),
						'shipping'                  => $this->get_shipping(),
						'lineItems'                 => $this->get_line_items(),
						'customerProfileId'         => $this->get_customer_id( $order ),
						'customerPaymentProfileId'  => $this->get_token( $order ),
						'customerShippingAddressId' => $shipping_address_id,
						'order'                     => array(
							'invoiceNumber'       => ltrim( $this->get_order_number( $order, $offer ), _x( '#', 'hash before the order number', 'woocommerce-gateway-authorize-net-cim' ) ),
							'description'         => $this->sv_helper_compatibility( 'str_truncate', array( $this->order->description . '::' . $this->get_order_number( $order ), 255 ) ),
							'purchaseOrderNumber' => $this->sv_helper_compatibility( 'str_truncate', array( preg_replace( '/\W/', '', $this->order->payment->po_number ), 25 ) ),
						),

					),
				),

				'extraOptions' => $this->get_extra_options(),

			),
		);
	}

	/**
	 * Adds tax information to the request.
	 *
	 * @since 2.0.0
	 * @return array
	 */
	protected function get_taxes() {

		if ( $this->order->get_total_tax() > 0 ) {

			$taxes = array();

			foreach ( $this->order->get_tax_totals() as $tax_code => $tax ) {

				$taxes[] = sprintf( '%s (%s) - %s', $tax->label, $tax_code, $tax->amount );
			}

			return array(
				'amount'      => $this->sv_helper_compatibility( 'number_format', array( $this->order->get_total_tax() ) ),
				'name'        => __( 'Order Taxes', 'woocommerce-gateway-authorize-net-cim' ),
				'description' => $this->sv_helper_compatibility( 'str_truncate', array( implode( ', ', $taxes ), 255 ) ),
			);

		} else {

			return array();
		}
	}

	/**
	 * Adds shipping information to the request.
	 *
	 * @since 2.0.0
	 * @return array
	 */
	protected function get_shipping() {

		if ( $this->order->get_total_shipping() > 0 ) {

			return array(
				'amount'      => $this->sv_helper_compatibility( 'number_format', array( $this->order->get_total_shipping() ) ),
				'name'        => __( 'Order Shipping', 'woocommerce-gateway-authorize-net-cim' ),
				'description' => $this->sv_helper_compatibility( 'str_truncate', array( $this->order->get_shipping_method(), 255 ) ),
			);

		} else {

			return array();
		}
	}

	/**
	 * Adds order line items to the request.
	 *
	 * @since 2.0.0
	 * @return array
	 */
	protected function get_line_items() {

		$line_items = array();
		$package    = $this->_store->session_get( '_upsell_package' );

		if ( isset( $package['products'] ) && is_array( $package['products'] ) && count( $package['products'] ) > 0 ) {
			foreach ( $package['products'] as $product_data ) {
				/**
				 * @var WC_Product $product_data ['data']
				 */
				$line_items[] = array(
					'itemId'      => $this->sv_helper_compatibility( 'str_truncate', array( $product_data['data']->get_id(), 31 ) ),
					'name'        => $this->sv_helper_compatibility( 'str_to_sane_utf8', array( $this->sv_helper_compatibility( 'str_truncate', array( $product_data['data']->get_name(), 31 ) ) ) ),
					'description' => $this->sv_helper_compatibility( 'str_to_sane_utf8', array(
						$this->sv_helper_compatibility( 'str_truncate', array(
							$product_data['data']->get_description(),
							255,
						) ),
					) ),
					'quantity'    => $product_data['qty'],
					'unitPrice'   => $this->sv_helper_compatibility( 'number_format', array( $product_data['price'] ) ),
				);
			}
		}

		// maximum of 30 line items per order
		if ( count( $line_items ) > 30 ) {
			$line_items = array_slice( $line_items, 0, 30 );
		}

		return $line_items;
	}

	/**
	 * Try and get the payment token saved by the gateway
	 *
	 * @param WC_Order $order
	 *
	 * @return true on success false otherwise
	 */

	public function get_customer_id( $order ) {
		$get_id = INFCWDS_WC_Compatibility::get_order_id( $order );

		$this->customer_id = get_post_meta( $get_id, '_wc_' . $this->get_key() . '_customer_id', true );

		if ( ! empty( $this->customer_id ) ) {
			return $this->customer_id;
		}

		/**
		 * Fallback when token is not present in the parent order
		 */
		$get_secondary_order = $this->_store->session_get( 'infcrwds_authorize_net_cim_order_id');

		if ( empty( $get_secondary_order ) ) {
			return '';
		}

		$this->customer_id = get_post_meta( $get_secondary_order, '_wc_' . $this->get_key() . '_customer_id', true );

		if ( ! empty( $this->customer_id ) ) {
			return $this->customer_id;
		}

		return '';

	}

	/**
	 * Get extra options for the CIM transaction.
	 *
	 * Extra options are fields that auth.net accepts but aren't part of the CIM API
	 *
	 * @since 2.0.0
	 * @return string
	 */
	protected function get_extra_options() {

		$options = array(
			'x_solution_id'      => 'A1000065',
			'x_customer_ip'      => INFCWDS_WC_Compatibility::get_order_data( $this->order, 'customer_ip_address' ),
			'x_currency_code'    => INFCWDS_WC_Compatibility::get_order_data( $this->order, 'currency' ),
			// TODO: this can be improved by detecting certain failure conditions (AVS/CVV failures) and dynamically setting the duplicate window to 0 as needed @MR
			'x_duplicate_window' => 0,
			'x_delim_char'       => '|',
			'x_encap_char'       => ':',
		);

		return http_build_query( $options, '', '&' );
	}

	public function get_request_attributes( $request ) {
		return array(
			'method'      => 'POST',
			'timeout'     => MINUTE_IN_SECONDS,
			'redirection' => 0,
			'httpversion' => '1.0',
			'sslverify'   => true,
			'blocking'    => true,
			//'user-agent'  => $this->get_request_user_agent(),
			'headers'     => array(
				'content-type' => 'application/json',
				'accept'       => 'application/json',
			),
			'body'        => $this->get_request_body( $request ),
			'cookies'     => array(),
		);
	}

	protected function get_request_body( $request ) {
		return wp_json_encode( $request );
	}

	private function get_transaction_id( $response ) {
		// TODO: direct response from a customer/payment profile transaction
		// in liveMode validation can't use the extraOptions request param
		// to set the response delimiter or encapulsation character, so we
		// may need to provide a filter for the delim/encaps chars used here
		// in case someone uses the liveMode filter and cannot set their merchant
		// acount to the values we use @MR

		// adjust response based on our hybrid delimiter :|: (delimiter = | encapsulation = :)
		// remove the leading encap character and add a trailing delimiter/encap character
		// so explode works correctly (direct response string starts and ends with an encapsulation
		// character)
		$direct_response = ltrim( strval( $response ), ':' ) . '|:';

		// parse response
		$response = explode( ':|:', $direct_response );

		if ( empty( $response ) ) {
			return '';
		}

		// offset array by 1 to match Authorize.Net's order, mainly for readability
		array_unshift( $response, null );

		$new_direct_response = array();

		// direct response fields are URL encoded, but we currently do not use any fields
		// (e.g. billing/shipping details) that would be affected by that
		$response_fields = array(
			'response_code'        => 1,
			'response_subcode'     => 2,
			'response_reason_code' => 3,
			'response_reason_text' => 4,
			'authorization_code'   => 5,
			'avs_response'         => 6,
			'transaction_id'       => 7,
			'amount'               => 10,
			'account_type'         => 11, // CC or ECHECK
			'transaction_type'     => 12, // AUTH_ONLY or AUTH_CAPTUREVOID probably
			'csc_response'         => 39,
			'cavv_response'        => 40,
			'account_last_four'    => 51,
			'card_type'            => 52,
		);

		foreach ( $response_fields as $field => $order ) {

			$new_direct_response[ $field ] = ( isset( $response[ $order ] ) ) ? $response[ $order ] : '';
		}

		return isset( $new_direct_response['transaction_id'] ) && '' !== $new_direct_response['transaction_id'] ? $new_direct_response['transaction_id'] : '';
	}

	public function get_shipping_addr( $order ) {
		// address fields

		$shipping_address = trim( INFCWDS_WC_Compatibility::get_order_data( $order, 'shipping_address_1' ) . ' ' . INFCWDS_WC_Compatibility::get_order_data( $order, 'shipping_address_2' ) );

		$fields = array(
			'firstName' => array(
				'value' => INFCWDS_WC_Compatibility::get_order_data( $order, 'shipping_first_name' ),
				'limit' => 50,
			),
			'lastName'  => array(
				'value' => INFCWDS_WC_Compatibility::get_order_data( $order, 'shipping_last_name' ),
				'limit' => 50,
			),
			'company'   => array(
				'value' => INFCWDS_WC_Compatibility::get_order_data( $order, 'shipping_company' ),
				'limit' => 50,
			),
			'address'   => array(
				'value' => $shipping_address,
				'limit' => 60,
			),
			'city'      => array(
				'value' => INFCWDS_WC_Compatibility::get_order_data( $order, 'shipping_city' ),
				'limit' => 40,
			),
			'state'     => array(
				'value' => INFCWDS_WC_Compatibility::get_order_data( $order, 'shipping_state' ),
				'limit' => 40,
			),
			'zip'       => array(
				'value' => INFCWDS_WC_Compatibility::get_order_data( $order, 'shipping_postcode' ),
				'limit' => 20,
			),
			'country'   => array(
				'value' => INFCWDS_WC_Compatibility::get_order_data( $order, 'shipping_country' ),
				'limit' => 60,
			),
		);

		return $fields;

	}

	/**
	 * @param WC_Order $order
	 */
	public function maybe_add_shipping_address_id_order_for_guests( $order ) {
		if ( isset( $order->payment ) && isset( $order->payment->shipping_address_id ) && ! empty( $order->payment->shipping_address_id ) ) {
			$order->update_meta_data( '_authorize_cim_shipping_address_id', $order->payment->shipping_address_id );
			$order->save_meta_data();
		}
	}

	/**
	 * Handling refund offer
	 *
	 * @param $order
	 *
	 * @return bool
	 */
	public function process_refund_offer( $order ) {
		$refund_data = $_POST;

		$txn_id   = isset( $refund_data['txn_id'] ) ? $refund_data['txn_id'] : '';
		$amnt     = isset( $refund_data['amt'] ) ? $refund_data['amt'] : '';
		$offer_id = isset( $refund_data['offer_id'] ) ? $refund_data['offer_id'] : '';
		$api      = $this->get_wc_gateway()->get_api();
		$gateway  = $this->get_wc_gateway();

		$txn_voided = false;

		$reason = sprintf( __( ' - Reason: refunded offer ID: %1$s and Transaction ID: %2$s', 'woofunnels-upstroke-one-click-upsell' ), $offer_id, $txn_id );

		// add refund info
		$order->refund         = new stdClass();
		$order->refund->amount = number_format( $amnt, 2, '.', '' );

		$order->refund->trans_id = $txn_id;

		$order->refund->reason = $reason;

		// profile refund/void
		$order->refund->customer_profile_id         = $gateway->get_order_meta( $order, 'customer_id' );
		$order->refund->customer_payment_profile_id = $gateway->get_order_meta( $order, 'payment_token' );

		$response = $api->refund( $order );

		$trasaction_id = $response->get_transaction_id();

		InfcrwdsPlugin()->logger->error( "WFOCU Authorize Offer refund transaction ID: $trasaction_id response: " . print_r( $response, true ) );

		if ( ! $trasaction_id ) {
			$response      = $api->void( $order );
			$trasaction_id = $response->get_transaction_id();
			$txn_voided    = true;
			InfcrwdsPlugin()->logger->error( "WFOCU Authorize Offer void transaction id: $trasaction_id response: " . print_r( $response, true ) );
		}

		if ( ! $trasaction_id ) {
			return false;
		} else {
			/* translators: 1) dollar amount 2) transaction id 3) refund message */
			$refund_message = ( $txn_voided ) ? sprintf( __( 'Voided %1$s - Void Txn ID: %2$s %3$s', 'woofunnels-upstroke-one-click-upsell' ), $amnt, $trasaction_id, $reason ) : sprintf( __( 'Refunded %1$s - Refund Txn ID: %2$s %3$s', 'woofunnels-upstroke-one-click-upsell' ), $amnt, $trasaction_id, $reason );

			$order->add_order_note( $refund_message );

			return $trasaction_id;
		}

	}

	/**
	 * Modifying refund request data Auth Offer post modified request data
	 *
	 * @param $request_data
	 * @param $order
	 * @param $gateway
	 */
	public function infcrwds_modify_refund_request_data( $request_data, $order, $gateway ) {

		if ( isset( $_POST['action'] ) && 'infcrwds_admin_refund_offer' === $_POST['action'] ) {
			InfcrwdsPlugin()->logger->error( 'Auth request data: ' . print_r( $request_data, true ) );

			$refund_data = $_POST;

			$offer_id = isset( $refund_data['offer_id'] ) ? $refund_data['offer_id'] : '';
			$order_id = INFCWDS_WC_Compatibility::get_order_id( $order );

			if ( isset( $request_data['createCustomerProfileTransactionRequest'] ) && isset( $request_data['createCustomerProfileTransactionRequest']['refId'] ) ) {
				$request_data['createCustomerProfileTransactionRequest']['refId'] = $order_id . '_' . $offer_id;
			}

			if ( isset( $request_data['createCustomerProfileTransactionRequest'] ) && isset( $request_data['createCustomerProfileTransactionRequest']['transaction'] ) && isset( $request_data['createCustomerProfileTransactionRequest']['transaction']['profileTransRefund'] ) && isset( $request_data['createCustomerProfileTransactionRequest']['transaction']['profileTransRefund']['order'] ) && isset( $request_data['createCustomerProfileTransactionRequest']['transaction']['profileTransRefund']['order']['invoiceNumber'] ) ) {
				$request_data['createCustomerProfileTransactionRequest']['transaction']['profileTransRefund']['order']['invoiceNumber'] = $order_id . '_' . $offer_id;
			}
			InfcrwdsPlugin()->logger->error( 'Auth Offer post modified request data: ' . print_r( $request_data, true ) );
		}

		return $request_data;
	}

	/**
	 * @param $transaction_id
	 * @param $order_id
	 *
	 * @return mixed
	 */
	public function get_transaction_link( $transaction_id, $order_id ) {

		return $transaction_id;
	}   

}