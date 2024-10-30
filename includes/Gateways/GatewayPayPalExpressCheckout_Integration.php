<?php
namespace InfCrowds\WPR\Gateways;

use INFCWDS_WC_Compatibility;

class GatewayPayPalExpressCheckout_Integration extends GatewayPayPalBase_Integration {
	protected static $ins = null;
	protected $key = 'ppec_paypal';
	/**
	 * List of locales supported by PayPal.
	 *
	 * @var array
	 */
	protected $_supported_locales = array(
		'da_DK',
		'de_DE',
		'en_AU',
		'en_GB',
		'en_US',
		'es_ES',
		'fr_CA',
		'fr_FR',
		'he_IL',
		'id_ID',
		'it_IT',
		'ja_JP',
		'nl_NL',
		'no_NO',
		'pl_PL',
		'pt_BR',
		'pt_PT',
		'ru_RU',
		'sv_SE',
		'th_TH',
		'tr_TR',
		'zh_CN',
		'zh_HK',
		'zh_TW',
	);

	public function __construct($store, $order_maker_factory) {
		parent::__construct($store, $order_maker_factory);

        add_filter( 'woocommerce_paypal_express_checkout_request_body', array( $this, 'maybe_modify_paypal_arguments' ), 999 );
        add_action( 'woocommerce_api_infcrwds_ppec_paypal', array( $this, 'handle_api_calls' ) );

		/**
		 * Changing transaction id in offer refund function to set it of offer transaciton in stead of parent order,
		 * Changing API credentials for express checkout instead of paypal standard
		 */
		add_filter( 'woocommerce_paypal_refund_request', array( $this, 'infcrwds_woocommerce_paypal_refund_request_data' ), 10, 3 );
		add_action( 'woocommerce_paypal_express_checkout_valid_ipn_request', array( $this, 'handle_paypal_ipn_and_record_response' ), - 1 );

		$this->refund_supported = true;

	}


	public function maybe_modify_paypal_arguments( $array ) {

		/**
		 * Modifying the data so that initial call to set express checkout would tokenize the card
		 */
		if ( true === $this->is_enabled() && true === $this->is_reference_trans_enabled() && $array && isset( $array['METHOD'] ) && 'SetExpressCheckout' == $array['METHOD'] && ! isset( $array['L_BILLINGTYPE0'] ) ) {
			$array['RETURNURL']                      = add_query_arg( array( 'create-billing-agreement' => true ), $array['RETURNURL'] );
			$array['L_BILLINGTYPE0']                 = 'MerchantInitiatedBillingSingleAgreement';
			$array['L_BILLINGAGREEMENTDESCRIPTION0'] = $this->_get_billing_agreement_description();
			$array['L_BILLINGAGREEMENTCUSTOM0']      = '';
		}

		/**
		 * correcting refrerence transaction params to
		 * @todo calucalate all the item total and other arguments that take part in totals & pass them to paypal
		 */
		if ( true === $this->is_enabled() && true === $this->is_reference_trans_enabled() && $this->is_enabled() && $array && isset( $array['METHOD'] ) && 'DoReferenceTransaction' == $array['METHOD'] ) {
            return $array;
            // $offer = $this->_store->get_selected_offer();
            // $current_order = $this->_store->get_base_order();
            // $product = wc_get_product($offer['product_id']);
			// /**
			//  * if we do not have the current order set that means its not the upsell accept call but the call containing subscriptions.
			//  */
			// if ( false === $current_order || $offer === false || empty($product) || $product === false) {
			// 	return $array;
            // }
            // $pricing = $offer['pricing'];
            // $total = $pricing['sale_price'] + $pricing['shipping_price'] + $pricing['tax_cost'] + $pricing['shipping_tax'];
			// $array['AMT']     = $total;
			// $array['ITEMAMT'] = $total;

			// /**
			//  * When shipping amount is a negative number, means user opted for free shipping offer
			//  * In this case we setup shippingamt as 0 and shipping discount amount is that negative amount that is coming.
			//  */
			// if ( 0 > $pricing['shipping_price'] ) {
			// 	$array['SHIPPINGAMT'] = 0;
			// 	$array['SHIPDISCAMT'] = $pricing['shipping_price'];

			// } else {
			// 	$array['SHIPPINGAMT'] = $pricing['shipping_price'];
			// 	$array['SHIPDISCAMT'] = 0;
			// }

			// $array['TAXAMT']       = $pricing['tax_cost'] + $pricing['shipping_tax'];
			// $array['INVNUM']       = 'WC-' . $this->get_order_number( $current_order, $offer );
			// $array['INSURANCEAMT'] = 0;
			// $array['HANDLINGAMT']  = 0;
			// $array                 = $this->remove_previous_line_items( $array );

			// $item_loop = 0;
			// $ITEMAMT   = 0;
			

            // $array[ 'L_NAME' . $item_loop ] = $product->get_name();
            // $array[ 'L_DESC' . $item_loop ] = wp_trim_words( $product->get_description(), 10 );
            // $array[ 'L_AMT' . $item_loop ]  = wc_format_decimal( $pricing['sale_price'] / $pricing['quantity'], 2 );
            // $array[ 'L_QTY' . $item_loop ]  = $pricing['quantity'];

            // $ITEMAMT += $pricing['sale_price'];
        
			// $array['ITEMAMT'] = $ITEMAMT;

		}

		if ( true === $this->is_enabled() && true === $this->is_reference_trans_enabled() && isset( $array['METHOD'] ) && 'DoExpressCheckoutPayment' == $array['METHOD'] ) {

			if ( isset( $array['PAYMENTREQUEST_0_CUSTOM'] ) ) {
				$get_custom_attrs = json_decode( $array['PAYMENTREQUEST_0_CUSTOM'] );
				if ( isset( $get_custom_attrs->order_id ) ) {
					$get_order = wc_get_order( $get_custom_attrs->order_id );

					if ( true === $this->is_enabled( $get_order ) ) {
						try {
							$checkout         = wc_gateway_ppec()->checkout;
							$checkout_details = $checkout->get_checkout_details( $array['TOKEN'] );

							$checkout->create_billing_agreement( $get_order, $checkout_details );
							$token = INFCWDS_WC_Compatibility::get_order_meta($get_order, '_ppec_billing_agreement_id', true);
							if ( ! empty( $token ) ) {

								//saving meta by our own
								//do not need to rely over shutdown
								update_post_meta( INFCWDS_WC_Compatibility::get_order_id( $get_order ), '_ppec_billing_agreement_id', $token );
							}
						} catch ( Exception $e ) {
                            InfcrwdsPlugin()->logger->error('Order #' . INFCWDS_WC_Compatibility::get_order_id( $get_order ) . ': Unable to create a token for express checkout for order' );
							InfcrwdsPlugin()->logger->error( 'Details Below: ' . print_r( $e->getMessage(), true ) );
						}
					}
				}
			}
		}

		return $array;
    }
    public function get_environment() {
        return $this->get_wc_gateway()->environment;
    }

	public function add_payment_options($order, $payment_options) {
		if($this->has_token($order)) {
			$payment_options[] = array(
				'gateway' => $this->key,
				'gateway_info' => array(
					'type' => 'ref_trans'
				)
			);
		}
		$no_payer_id = $this->get_payer_id() === false;
		if($no_payer_id) {
			return $payment_options;
		}
		$environment = $this->get_environment();
		$payment_options[] = array(
			'gateway' => $this->key,
			'gateway_info' => array(
				'type' => 'in_context',
				'environment' => $environment,
				'payer_id' => $this->get_payer_id(),
				'paypal_local' => $this->get_paypal_locale()
			));
		return $payment_options;
	}

	public function is_reference_trans_enabled() {
		return $this->_store->get('is_pp_ref_trans_enabled', false);
	}

	/**
	 * Get billing agreement description to be passed to PayPal.
	 *
	 * @since 1.2.0
	 *
	 * @return string Billing agreement description
	 */
	protected function _get_billing_agreement_description() {
		/* translators: placeholder is blogname */
		$description = sprintf( _x( 'Orders with %s', 'data sent to PayPal', 'woocommerce-subscriptions' ), get_bloginfo( 'name' ) );

		if ( strlen( $description ) > 127 ) {
			$description = substr( $description, 0, 124 ) . '...';
		}

		return html_entity_decode( $description, ENT_NOQUOTES, 'UTF-8' );
	}

	public function remove_previous_line_items( $array ) {

		if ( is_array( $array ) && count( $array ) > 0 ) {
			foreach ( $array as $key => $val ) {
				if ( false !== strpos( strtoupper( $key ), 'L_' ) ) {
					unset( $array[ $key ] );
				}
			}
		}

		return $array;
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
		$token  = INFCWDS_WC_Compatibility::get_order_meta($order, '_ppec_billing_agreement_id' , true);

		if ( ! empty( $token ) ) {
			return true;
		}

		return false;

	}

	public function process_charge( $order, $offer ) {

		$is_successful = false;
		try {
			
			$client = wc_gateway_ppec()->client;
			$pricing = $offer['pricing'];
			$taxes = $pricing['tax_cost'] + $pricing['shipping_tax'];
			$shipping_price = $pricing['shipping_price'];
			$sale_price = $pricing['sale_price'];
			$tmp_params = $client->get_do_reference_transaction_params( array(
				'reference_id' => $this->get_token( $order ),
				'amount'       => 0,
				'order_id'     => INFCWDS_WC_Compatibility::get_order_id( $order ),
			) );
			$params = array(
				'REFERENCEID'   => $tmp_params['REFERENCEID'],
				'AMT'           => $this->round($taxes + $shipping_price + $sale_price),
				'CURRENCYCODE'  => get_woocommerce_currency(),
				'NOTIFYURL'     => $tmp_params['NOTIFYURL'],
				'PAYMENTACTION' => $tmp_params['PAYMENTACTION'],
				'INVNUM'        => $tmp_params['INVNUM'] . '_special_offer',
				'CUSTOM'        => $tmp_params['CUSTOM'],
			);

			// if ( false === $order->has_shipping_address() ) {
			// 	unset( $params['SHIPTONAME'] );
			// 	unset( $params['SHIPTOSTREET'] );
			// 	unset( $params['SHIPTOSTREET2'] );
			// 	unset( $params['SHIPTOCITY'] );
			// 	unset( $params['SHIPTOSTATE'] );
			// 	unset( $params['SHIPTOZIP'] );
			// 	unset( $params['SHIPTOCOUNTRY'] );
			// }

			$resp = $client->do_reference_transaction( $params );

			InfcrwdsPlugin()->logger->log( 'Order: ' . INFCWDS_WC_Compatibility::get_order_id( $order ) . ': Transaction response for PPEC:' . print_r( $resp, true ) );
			if ( $client->response_has_success_status( $resp ) ) {
				// WFOCU_Core()->data->set( '_transaction_id', $resp['TRANSACTIONID'] );

				$is_successful = $resp['TRANSACTIONID'];

			} else {

				$is_successful = false;

			}
		} catch ( Exception $e ) {
			$is_successful = false;
		}

		return $is_successful;
	}

	public function get_token( $order ) {

		$get_id = INFCWDS_WC_Compatibility::get_order_id( $order );

		$token = INFCWDS_WC_Compatibility::get_order_meta($order, '_ppec_billing_agreement_id', true);

		if ( ! empty( $token ) ) {
			return $token;
		}

		return false;
	}


	/************************************** PAYPAL IN_OFFER TRANSACTION STARTS *********************/


	/** Helper Methods ******************************************************/

	/**
	 * Returns the string representation of this request with any and all
	 * sensitive elements masked or removed
	 *
	 * @see SV_WC_Payment_Gateway_API_Request::to_string_safe()
	 * @return string the pretty-printed request array string representation, safe for logging
	 * @since 2.0
	 */
	public function to_string_safe() {

		$request = $this->get_parameters();

		$sensitive_fields = array( 'USER', 'PWD', 'SIGNATURE' );

		foreach ( $sensitive_fields as $field ) {

			if ( isset( $request[ $field ] ) ) {

				$request[ $field ] = str_repeat( '*', strlen( $request[ $field ] ) );
			}
		}

		return print_r( $request, true );
	}

	/**
	 * Returns the request parameters after validation & filtering
	 *
	 * @throws \Exception invalid amount
	 * @return array request parameters
	 * @since 2.0
	 */
	public function get_parameters() {

		/**
		 * Filter PPE request parameters.
		 *
		 * Use this to modify the PayPal request parameters prior to validation
		 *
		 * @param array $parameters
		 * @param \WC_PayPal_Express_API_Request $this instance
		 */
		$this->parameters = apply_filters( 'wcs_paypal_request_params', $this->parameters, $this );

		// validate parameters
		foreach ( $this->parameters as $key => $value ) {

			// remove unused params
			if ( '' === $value || is_null( $value ) ) {
				unset( $this->parameters[ $key ] );
			}

			// format and check amounts
			if ( false !== strpos( $key, 'AMT' ) ) {

				// amounts must be 10,000.00 or less for USD
				if ( isset( $this->parameters['PAYMENTREQUEST_0_CURRENCYCODE'] ) && 'USD' == $this->parameters['PAYMENTREQUEST_0_CURRENCYCODE'] && $value > 10000 ) {

					throw new Exception( sprintf( '%s amount of %s must be less than $10,000.00', $key, $value ) );
				}

				// PayPal requires locale-specific number formats (e.g. USD is 123.45)
				// PayPal requires the decimal separator to be a period (.)
				$this->parameters[ $key ] = $this->price_format( $value );
			}
		}

		return $this->parameters;
	}

	public function get_order_from_response( $response ) {

		if ( $response && isset( $response['CUSTOM'] ) ) {
			$getjson = json_decode( $response['CUSTOM'], true );

			return wc_get_order( $getjson['order_id'] );
		}
	}

	public function get_session_from_response( $response ) {

		if ( $response && isset( $response['CUSTOM'] ) ) {
			$getjson = json_decode( $response['CUSTOM'], true );

			return ( $getjson['_infcrwds_session_id'] );
		}
	}

	public function is_run_without_token() {
		return true;
	}


	/**
	 * Get payer ID from API.
	 */
	public function get_payer_id() {
		$client = wc_gateway_ppec()->client;

		return $client->get_payer_id();
	}

	/**
	 * Get locale for PayPal.
	 *
	 * @return string
	 */
	public function get_paypal_locale() {
		$locale = get_locale();
		if ( ! in_array( $locale, $this->_supported_locales ) ) {
			$locale = 'en_US';
		}

		return $locale;
	}


	/**
	 * Sets the prams for setExpressCheckout call and executes it
	 *
	 * @param array $args
	 *
	 * @return object
	 * @throws Exception
	 */
	public function set_express_checkout( $args ) {

		$environment = $this->get_wc_gateway()->get_option( 'environment', 'live' );

		if ( 'live' === $environment ) {
			$api_username  = $this->get_wc_gateway()->get_option( 'api_username' );
			$api_password  = $this->get_wc_gateway()->get_option( 'api_password' );
			$api_signature = $this->get_wc_gateway()->get_option( 'api_signature' );
		} else {
			$api_username  = $this->get_wc_gateway()->get_option( 'sandbox_api_username' );
			$api_password  = $this->get_wc_gateway()->get_option( 'sandbox_api_password' );
			$api_signature = $this->get_wc_gateway()->get_option( 'sandbox_api_signature' );

		}

		$this->set_api_credentials( $this->get_key(), $environment, $api_username, $api_password, $api_signature );
		$this->set_express_checkout_args( $args );
		$this->populate_credentials( $this->api_username, $this->api_password, $this->api_signature, 124 );

		$request         = new stdClass();
		$request->path   = '';
		$request->method = 'POST';
		$request->body   = $this->to_string();
		WFOCU_Core()->data->set( 'paypal_request_data', $this->get_parameters(), 'paypal' );

		return $this->perform_request( $request );
	}

	/**
	 * Sets up API credentials to the class that we need later during the API call
	 *
	 * @param $gateway_id
	 * @param $api_environment
	 * @param $api_username
	 * @param $api_password
	 * @param $api_signature
	 */
	public function set_api_credentials( $gateway_id, $api_environment, $api_username, $api_password, $api_signature ) {
		// tie API to gateway
		$this->gateway_id = $gateway_id;

		// request URI does not vary per-request
		$this->request_uri = wc_gateway_ppec()->client->get_endpoint();

		// PayPal requires HTTP 1.1
		$this->request_http_version = '1.1';

		$this->api_username  = $api_username;
		$this->api_password  = $api_password;
		$this->api_signature = $api_signature;
	}

	/**
	 * Sets up the express checkout transaction
	 *
	 * @link https://developer.paypal.com/docs/classic/express-checkout/integration-guide/ECGettingStarted/#id084RN060BPF
	 * @link https://developer.paypal.com/webapps/developer/docs/classic/api/merchant/SetExpressCheckout_API_Operation_NVP/
	 *
	 * @param array $args {
	 *
	 * @type string 'currency'              (Optional) A 3-character currency code (default is store's currency).
	 * @type string 'billing_type'          (Optional) Type of billing agreement for reference transactions. You must have permission from PayPal to use this field. This field must be set to one of the following values: MerchantInitiatedBilling - PayPal creates a billing agreement for each transaction associated with buyer. You must specify version 54.0 or higher to use this option; MerchantInitiatedBillingSingleAgreement - PayPal creates a single billing agreement for all transactions associated with buyer. Use this value unless you need per-transaction billing agreements. You must specify version 58.0 or higher to use this option.
	 * @type string 'billing_description'   (Optional) Description of goods or services associated with the billing agreement. This field is required for each recurring payment billing agreement if using MerchantInitiatedBilling as the billing type, that means you can use a different agreement for each subscription/order. PayPal recommends that the description contain a brief summary of the billing agreement terms and conditions (but this only makes sense when the billing type is MerchantInitiatedBilling, otherwise the terms will be incorrectly displayed for all agreements). For example, buyer is billed at "9.99 per month for 2 years".
	 * @type string 'maximum_amount'        (Optional) The expected maximum total amount of the complete order and future payments, including shipping cost and tax charges. If you pass the expected average transaction amount (default 25.00). PayPal uses this value to validate the buyer's funding source.
	 * @type string 'no_shipping'           (Optional) Determines where or not PayPal displays shipping address fields on the PayPal pages. For digital goods, this field is required, and you must set it to 1. It is one of the following values: 0 – PayPal displays the shipping address on the PayPal pages; 1 – PayPal does not display shipping address fields whatsoever (default); 2 – If you do not pass the shipping address, PayPal obtains it from the buyer's account profile.
	 * @type string 'page_style'            (Optional) Name of the Custom Payment Page Style for payment pages associated with this button or link. It corresponds to the HTML variable page_style for customizing payment pages. It is the same name as the Page Style Name you chose to add or edit the page style in your PayPal Account profile.
	 * @type string 'brand_name'            (Optional) A label that overrides the business name in the PayPal account on the PayPal hosted checkout pages. Default: store name.
	 * @type string 'landing_page'          (Optional) Type of PayPal page to display. It is one of the following values: 'login' – PayPal account login (default); 'Billing' – Non-PayPal account.
	 * @type string 'payment_action'        (Optional) How you want to obtain payment. If the transaction does not include a one-time purchase, this field is ignored. Default 'Sale' – This is a final sale for which you are requesting payment (default). Alternative: 'Authorization' – This payment is a basic authorization subject to settlement with PayPal Authorization and Capture. You cannot set this field to Sale in SetExpressCheckout request and then change the value to Authorization or Order in the DoExpressCheckoutPayment request. If you set the field to Authorization or Order in SetExpressCheckout, you may set the field to Sale.
	 * @type string 'return_url'            (Required) URL to which the buyer's browser is returned after choosing to pay with PayPal.
	 * @type string 'cancel_url'            (Required) URL to which the buyer is returned if the buyer does not approve the use of PayPal to pay you.
	 * @type string 'custom'                (Optional) A free-form field for up to 256 single-byte alphanumeric characters
	 * }
	 * @since 2.0
	 */
	public function set_express_checkout_args( $args ) {

		// translators: placeholder is blogname
		$default_description = sprintf( _x( 'Orders with %s', 'data sent to paypal', 'woocommerce-subscriptions' ), get_bloginfo( 'name' ) );

		$defaults = array(
			'currency'            => get_woocommerce_currency(),
			'billing_type'        => 'MerchantInitiatedBillingSingleAgreement',
			'billing_description' => html_entity_decode( apply_filters( 'woocommerce_subscriptions_paypal_billing_agreement_description', $default_description, $args ), ENT_NOQUOTES, 'UTF-8' ),
			'maximum_amount'      => null,
			'no_shipping'         => 1,
			'page_style'          => null,
			'brand_name'          => html_entity_decode( get_bloginfo( 'name' ), ENT_NOQUOTES, 'UTF-8' ),
			'landing_page'        => 'login',
			'payment_action'      => 'Sale',
			'custom'              => '',
			'addressoverride'     => '1',
		);

		$args = wp_parse_args( $args, $defaults );

		$this->set_method( 'SetExpressCheckout' );

		$this->add_parameters( array(

			'RETURNURL'   => $args['return_url'],
			'CANCELURL'   => $args['cancel_url'],
			'PAGESTYLE'   => $args['page_style'],
			'BRANDNAME'   => $args['brand_name'],
			'LANDINGPAGE' => 'Billing',

			'ADDROVERRIDE' => $args['addressoverride'],
			'NOSHIPPING'   => $args['no_shipping'],

			'MAXAMT' => $args['maximum_amount'],
		) );


		// if we have an order, the request is to create a subscription/process a payment (not just check if the PayPal account supports Reference Transactions)
		if ( isset( $args['order'] ) ) {
			$this->add_payment_details_parameters( $args['order'], $args['payment_action'], false );
		}
		if ( empty( $args['no_shipping'] ) ) {

			$this->maybe_add_shipping_address_params( $args['order'] );

		}
		$set_express_checkout_params = apply_filters( 'wfocu_gateway_ppec_param_setexpresscheckout', $this->get_parameters(), true );


		$this->clean_params();
		$this->add_parameters( $set_express_checkout_params );

	}

	/**
	 * Set the method for the request, currently using:
	 *
	 * + `SetExpressCheckout` - setup transaction
	 * + `GetExpressCheckout` - gets buyers info from PayPal
	 * + `DoExpressCheckoutPayment` - completes the transaction
	 * + `DoCapture` - captures a previously authorized transaction
	 *
	 * @param string $method
	 *
	 * @since 2.0
	 */
	private function set_method( $method ) {
		$this->add_parameter( 'METHOD', $method );
	}

	/**
	 * Add a parameter
	 *
	 * @param string $key
	 * @param string|int $value
	 *
	 * @since 2.0
	 */
	private function add_parameter( $key, $value ) {
		$this->parameters[ $key ] = $value;
	}

	/**
	 * Add multiple parameters
	 *
	 * @param array $params
	 *
	 * @since 2.0
	 */
	public function add_parameters( array $params ) {
		foreach ( $params as $key => $value ) {
			$this->add_parameter( $key, $value );
		}
	}

	/**
	 * Set up the payment details for a DoExpressCheckoutPayment or DoReferenceTransaction request
	 *
	 * @since 2.0.9
	 *
	 * @param WC_Order $order order object
	 * @param string $type the type of transaction for the payment
	 * @param bool $use_deprecated_params whether to use deprecated PayPal NVP parameters (required for DoReferenceTransaction API calls)
	 */
	protected function add_payment_details_parameters( WC_Order $order, $type, $use_deprecated_params = false ) {

		$calculated_total = 0;
		$order_subtotal   = 0;
		$item_count       = 0;
		$order_items      = array();

		$offer_package = WFOCU_Core()->data->get( '_upsell_package' );


		foreach ( $offer_package['products'] as $item ) {

			$product = $item['data'];

			$order_items[] = array(
				'NAME'    => $product->get_title(),
				'DESC'    => $this->get_item_description( $item, $product ),
				'AMT'     => $this->round( $item['price'] ),
				'QTY'     => 1,
				'ITEMURL' => $product->get_permalink(),
			);

			$order_subtotal += $item['args']['total'];
		}


		/**
		 * Code for reference transaction
		 */
		$total_amount = $offer_package['total'];

		$item_names = array();

		foreach ( $order_items as $item ) {
			$item_names[] = sprintf( '%1$s x %2$s', $item['NAME'], $item['QTY'] );
		}

		$item_count = 0;
		// add individual order items
		foreach ( $order_items as $item ) {
			$this->add_line_item_parameters( $item, $item_count ++, $use_deprecated_params );
		}
		/**
		 * When shipping amount is a negative number, means user opted for free shipping offer
		 * In this case we setup shippingamt as 0 and shipping discount amount is that negative amount that is coming.
		 */

		if ( ( isset( $offer_package['shipping'] ) && isset( $offer_package['shipping']['diff'] ) ) && 0 > $offer_package['shipping']['diff']['cost'] ) {
			$this->add_payment_parameters( array(
				'AMT'              => $total_amount,
				'CURRENCYCODE'     => WFOCU_WC_Compatibility::get_order_currency( $order ),
				'ITEMAMT'          => $this->round( $order_subtotal ),
				'SHIPPINGAMT'      => 0,
				'SHIPDISCAMT'      => ( isset( $offer_package['shipping'] ) && isset( $offer_package['shipping']['diff'] ) ) ? $offer_package['shipping']['diff']['cost'] : 0,
				'INVNUM'           => $this->get_wc_gateway()->get_option( 'invoice_prefix' ) . $this->get_order_number( $order ),
				'PAYMENTACTION'    => $type,
				'PAYMENTREQUESTID' => WFOCU_WC_Compatibility::get_order_id( $order ),
				'TAXAMT'           => ( isset( $offer_package['taxes'] ) ) ? $offer_package['taxes'] : 0,
				'CUSTOM'           => json_encode( array(
					'_wfocu_o_id'       => $this->get_wc_gateway()->get_option( 'invoice_prefix' ) . $this->get_order_number( $order ),
					'_wfocu_session_id' => WFOCU_Core()->data->get_transient_key(),
				) ),
			) );
		} else {
			$this->add_payment_parameters( array(
				'AMT'              => $total_amount,
				'CURRENCYCODE'     => WFOCU_WC_Compatibility::get_order_currency( $order ),
				'ITEMAMT'          => $this->round( $order_subtotal ),
				'SHIPPINGAMT'      => ( isset( $offer_package['shipping'] ) && isset( $offer_package['shipping']['diff'] ) ) ? $offer_package['shipping']['diff']['cost'] : 0,
				'INVNUM'           => $this->get_wc_gateway()->get_option( 'invoice_prefix' ) . $this->get_order_number( $order ),
				'PAYMENTACTION'    => $type,
				'PAYMENTREQUESTID' => WFOCU_WC_Compatibility::get_order_id( $order ),
				'TAXAMT'           => ( isset( $offer_package['taxes'] ) ) ? $offer_package['taxes'] : 0,
				'CUSTOM'           => json_encode( array(
					'_wfocu_o_id'       => $this->get_wc_gateway()->get_option( 'invoice_prefix' ) . $this->get_order_number( $order ),
					'_wfocu_session_id' => WFOCU_Core()->data->get_transient_key(),
				) ),
			) );
		}

	}

	/**
	 * Helper method to return the item description, which is composed of item
	 * meta flattened into a comma-separated string, if available. Otherwise the
	 * product SKU is included.
	 *
	 * The description is automatically truncated to the 127 char limit.
	 *
	 * @param array $item cart or order item
	 * @param \WC_Product $product product data
	 *
	 * @return string
	 * @since 2.0
	 */
	private function get_item_description( $item, $product ) {

		$item_desc = wp_strip_all_tags( wp_staticize_emoji( $product->get_short_description() ) );
		$item_desc = preg_replace( '/[\x00-\x1F\x80-\xFF]/', '', $item_desc );
		$item_desc = str_replace( "\n", ', ', rtrim( $item_desc ) );
		if ( strlen( $item_desc ) > 127 ) {
			$item_desc = substr( $item_desc, 0, 124 ) . '...';
		}

		return html_entity_decode( $item_desc, ENT_NOQUOTES, 'UTF-8' );

	}

	/**
	 * Round a float
	 *
	 * @since 2.0.9
	 *
	 * @param float $number
	 * @param int $precision Optional. The number of decimal digits to round to.
	 */
	private function round( $number, $precision = 2 ) {
		return round( (float) $number, $precision );
	}


	/**
	 * Adds a line item parameters to the request, auto-prefixes the parameter key
	 * with `L_PAYMENTREQUEST_0_` for convenience and readability
	 *
	 * @param array $params
	 * @param int $item_count current item count
	 *
	 * @since 2.0
	 */
	private function add_line_item_parameters( array $params, $item_count, $use_deprecated_params = false ) {
		foreach ( $params as $key => $value ) {
			if ( $use_deprecated_params ) {
				$this->add_parameter( "L_{$key}{$item_count}", $value );
			} else {
				$this->add_parameter( "L_PAYMENTREQUEST_0_{$key}{$item_count}", $value );
			}
		}
	}


	/**
	 * Tell the system to run without a token or not
	 * @return bool
	 */

	/**
	 * Add payment parameters, auto-prefixes the parameter key with `PAYMENTREQUEST_0_`
	 * for convenience and readability
	 *
	 * @param array $params
	 *
	 * @since 2.0
	 */
	private function add_payment_parameters( array $params ) {
		foreach ( $params as $key => $value ) {
			$this->add_parameter( "PAYMENTREQUEST_0_{$key}", $value );
		}
	}

	public function clean_params() {
		$this->parameters = array();
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
	public function populate_credentials( $api_username, $api_password, $api_signature, $api_version ) {

		$this->add_parameters( array(
			'USER'      => $api_username,
			'PWD'       => $api_password,
			'SIGNATURE' => $api_signature,
			'VERSION'   => $api_version,
		) );
	}

	/**
	 * Returns the string representation of this request
	 *
	 * @see SV_WC_Payment_Gateway_API_Request::to_string()
	 * @return string the request query string
	 * @since 2.0
	 */
	public function to_string() {
		\InfcrwdsPlugin()->logger->log( print_r( $this->get_parameters(), true ) );

		return http_build_query( $this->get_parameters(), '', '&' );
	}

	// /**
	//  * Get the wc-api URL to redirect to
	//  *
	//  * @param string $action checkout action, either `set_express_checkout or `get_express_checkout_details`
	//  *
	//  * @return string URL
	//  * @since 2.0
	//  */
	// public function get_callback_url( $action ) {
	// 	return add_query_arg( 'action', $action, WC()->api_request_url( 'wfocu_paypal_ppec' ) );
	// }

	public function allow_paypal_express_check_action( $actions ) {
		array_push( $actions, 'wfocu_front_create_express_checkout_token_ppec' );

		return $actions;
	}

	/**
	 * Get PayPal redirect URL.
	 *
	 * @param string $token Token
	 * @param bool $commit If set to true, 'useraction' parameter will be set
	 *                       to 'commit' which makes PayPal sets the button text
	 *                       to **Pay Now** ont the PayPal _Review your information_
	 *                       page.
	 * @param bool $ppc Whether to use PayPal credit.
	 *
	 * @return string PayPal redirect URL
	 */
	public function get_paypal_redirect_url( $token, $commit = false, $ppc = false ) {
		$url = 'https://www.';

		$url .= 'sandbox.';

		$url .= 'paypal.com/checkoutnow?token=' . urlencode( $token );

		if ( $commit ) {
			$url .= '&useraction=commit';
		}

		if ( $ppc ) {
			$url .= '#/checkout/chooseCreditOffer';
		}

		return $url;
	}

	
	/**
	 * Get Details about the passed express checkout token
	 *
	 * @param $token
	 *
	 * @return object
	 * @throws Exception
	 */
	public function get_express_checkout_details( $token ) {
		$environment = $this->get_wc_gateway()->get_option( 'environment', 'live' );

		if ( 'live' === $environment ) {
			$api_username  = $this->get_wc_gateway()->get_option( 'api_username' );
			$api_password  = $this->get_wc_gateway()->get_option( 'api_password' );
			$api_signature = $this->get_wc_gateway()->get_option( 'api_signature' );
		} else {
			$api_username  = $this->get_wc_gateway()->get_option( 'sandbox_api_username' );
			$api_password  = $this->get_wc_gateway()->get_option( 'sandbox_api_password' );
			$api_signature = $this->get_wc_gateway()->get_option( 'sandbox_api_signature' );

		}

		$this->set_api_credentials( $this->get_key(), $environment, $api_username, $api_password, $api_signature );

		$this->get_express_checkout_args( $token );
		$this->populate_credentials( $this->api_username, $this->api_password, $this->api_signature, 124 );

		$request         = new \stdClass();
		$request->path   = '';
		$request->method = 'POST';
		$request->body   = $this->to_string();

		return $this->perform_request( $request );
	}

	/**
	 * Sets up GetExpressCheckoutDetails API call arguments
	 * @see WFOCU_Gateway_Integration_PayPal_Standard::get_express_checkout_details()
	 *
	 * @param string $token
	 */
	public function get_express_checkout_args( $token ) {

		$this->set_method( 'GetExpressCheckoutDetails' );
		$this->add_parameter( 'TOKEN', $token );
	}

	public function has_api_error( $response ) {
		// assume something went wrong if ACK is missing
		if ( ! isset( $response['ACK'] ) ) {
			return true;
		}

		// any non-success ACK is considered an error, see
		// https://developer.paypal.com/docs/classic/api/NVPAPIOverview/#id09C2F0K30L7
		return ( 'Success' !== $this->get_value_from_response( $response, 'ACK' ) && 'SuccessWithWarning' !== $this->get_value_from_response( $response, 'ACK' ) );

	}

	public function get_value_from_response( $response, $key ) {

		if ( $response && isset( $response[ $key ] ) ) {

			return $response[ $key ];
		}
	}

	public function get_transaction_id( $response ) {

		if ( is_array( $response ) && isset( $response['PAYMENTINFO_0_TRANSACTIONID'] ) ) {
			return $response['PAYMENTINFO_0_TRANSACTIONID'];
		}

		return '';
	}

	/**
	 * Handling refund offer exceptions
	 *
	 * @param $order
	 *
	 * @return bool
	 */
	public function process_refund_offer( $order ) {
		
		$order_id    = INFCWDS_WC_Compatibility::get_order_id( $order );

		$txn_id   = isset( $_POST['txn_id'] ) ? sanitize_text_field($_POST['txn_id']) : '';
		$amnt     = isset( $_POST['amt'] ) ? sanitize_text_field($_POST['amt']) : '';
		$offer_id = isset( $_POST['offer_id'] ) ? sanitize_text_field($_POST['offer_id']) : '';

		$refund_reason = __( " - Reason: refunded offer ID: $offer_id and Transaction ID: $txn_id", 'infcrwds' );

		$response = false;

		if ( ! is_null( $amnt ) && class_exists( 'WC_Gateway_Paypal' ) ) {
			$available_gateways = WC()->payment_gateways->payment_gateways();

			if ( isset( $available_gateways['paypal'] ) ) {
				$paypal   = $available_gateways['paypal'];
				$response = $paypal->process_refund( $order_id, $amnt, $refund_reason );
			}

			InfcrwdsPlugin()->logger->log( 'INFCRWDS Paypal Express Offer refund response: ' . print_r( $response, true ) );
		}

		if ( ! $response || is_wp_error( $response ) ) {
			return false;
		} else {
			return true;
		}

	}


	/********************** PAYPAL IN-OFFER PURCHASE ********************************/

	/**
	 * @hooked over woocommerce_paypal_refund_request
	 *
	 * Changing transaction id in offer refund function to set it of offer transaciton in stead of parent order
	 */
	public function infcrwds_woocommerce_paypal_refund_request_data( $request, $order, $amount ) {

		$payment_method = $order->get_payment_method();

		if ( $this->key !== $payment_method ) {
			return $request;
		}

		InfcrwdsPlugin()->logger->log( 'Paypal Express Refund Request: ' . print_r( $request, true ) );

		if ( isset( $_POST['txn_id'] ) && ! empty( $_POST['txn_id'] ) ) {
			$request['TRANSACTIONID'] = sanitize_text_field($_POST['txn_id']);

			$environment = $this->get_wc_gateway()->get_option( 'environment', 'live' );

			if ( 'live' === $environment ) {
				$request['USER']      = $this->get_wc_gateway()->get_option( 'api_username' );
				$request['PWD']       = $this->get_wc_gateway()->get_option( 'api_password' );
				$request['SIGNATURE'] = $this->get_wc_gateway()->get_option( 'api_signature' );
			} else {
				$request['USER']      = $this->get_wc_gateway()->get_option( 'sandbox_api_username' );
				$request['PWD']       = $this->get_wc_gateway()->get_option( 'sandbox_api_password' );
				$request['SIGNATURE'] = $this->get_wc_gateway()->get_option( 'sandbox_api_signature' );

			}
		}

		InfcrwdsPlugin()->logger->log( 'Paypal Express Modified Refund Request: ' . print_r( $request, true ) );

		return $request;
	}

	/**
	 *  Creating transaction URL
	 *
	 * @param $transaction_id
	 * @param $order_id
	 *
	 * @return string
	 */
	public function get_transaction_link( $transaction_id, $order_id ) {

		$testmode = $this->get_wc_gateway()->environment;

		if ( $transaction_id ) {
			if ( $testmode ) {
				$view_transaction_url = sprintf( 'https://www.sandbox.paypal.com/cgi-bin/webscr?cmd=_view-a-trans&id=%s', $transaction_id );
			} else {
				$view_transaction_url = sprintf( 'https://www.paypal.com/cgi-bin/webscr?cmd=_view-a-trans&id=%s', $transaction_id );
			}
		}

		if ( ! empty( $view_transaction_url ) && ! empty( $transaction_id ) ) {
			$return_url = sprintf( '<a href="%s">%s</a>', $view_transaction_url, $transaction_id );

			return $return_url;
		}

		return $transaction_id;
	}

	/**
	 * Return the parsed response object for the request
	 *
	 * @since 2.2.0
	 *
	 * @param string $raw_response_body
	 *
	 * @return object
	 */
	protected function get_parsed_response( $raw_response_body ) {

		wp_parse_str( urldecode( $raw_response_body ), $this->response_params );

		return $this->response_params;
	}

	/**
	 * @param WC_Order $order
	 */
	function maybe_add_shipping_address_params( $order, $prefix = 'PAYMENTREQUEST_0_SHIPTO' ) {

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
		foreach ( $params as $key => $val ) {
			$this->add_parameter( $key, $val );
		}


	}

}
