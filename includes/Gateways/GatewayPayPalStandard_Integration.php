<?php
namespace InfCrowds\WPR\Gateways;
use  InfCrowds\WPR\Gateways\PaypalReqs\Paypal_SetExpressCheckout_Request;
use  InfCrowds\WPR\Gateways\PaypalReqs\Paypal_DoExpressCheckout_Request;
use INFCWDS_WC_Compatibility;
/**
 *  We have managed to integrate paypal standard with infcrwds.
 * The trick is to fire Paypal Express che  ckout calls and modify the paypal arguments in such a way that further payment processing will be managed by Paypal Express checkout and not standard.
 * We have also integrated SV_API_Base class to fire remote requests
 */
class GatewayPayPalStandard_Integration extends GatewayPayPalBase_Integration {
	protected $key = 'paypal';
	protected static $ins = null;
	/** the production endpoint */
	const PRODUCTION_ENDPOINT = 'https://api-3t.paypal.com/nvp';

	/** the sandbox endpoint */
	const SANDBOX_ENDPOINT = 'https://api-3t.sandbox.paypal.com/nvp';


	/** @var array the request parameters */
	private $parameters = array();
	private $response_params = array();
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
		// When necessary, set the PayPal args to be for a subscription instead of shopping cart
		add_action( 'woocommerce_api_infcrwds_paypal', array( $this, 'maybe_handle_paypal_api_call' ) );
		add_filter( 'woocommerce_paypal_args', array( $this, 'modify_paypal_args' ), 10, 2 );

		add_action( 'wp_head', array( $this, 'maybe_check_ref_transaction' ) );

		add_filter( 'infcrwds_allow_ajax_actions_for_charge_setup', array( $this, 'allow_paypal_express_check_action' ) );

		/**
		 * handle pdt on offer pages
		 */
		add_action( 'template_redirect', array( $this, 'maybe_handle_pdt' ), 11 );
		add_action( 'valid-paypal-standard-ipn-request', array( $this, 'handle_paypal_ipn_and_record_response' ), - 1 );
	}

	public function maybe_handle_paypal_api_call() {
		$this->maybe_create_billing();
		$this->handle_api_calls();
	}

	public function maybe_check_ref_transaction() {

		if ( isset( $_GET['infcrwds_paypal_check'] ) && current_user_can( 'manage_woocommerce' ) ) {
			$response = $this->set_express_checkout( array(
				'currency'   => 'usd',
				'return_url' => $this->get_callback_url( 'infcrwds_paypal_create_billing_agreement' ),
				'cancel_url' => $this->get_callback_url( 'infcrwds_paypal_create_billing_agreement' ),
				'notify_url' => $this->get_callback_url( 'infcrwds_paypal_create_billing_agreement' ),
			) );
			?>
            <div class="notice notice-warning">
                <pre><?php print_r( $response ); ?></pre>
            </div>
			<?php
		}

	}

	/**
	 * Modify paypal arguments & pass express checkout arguments
	 *
	 * @param array $args
	 * @param WC_Order $order
	 *
	 * @return array
	 */
	public function modify_paypal_args( $args, $order ) {

		if ( false == $this->should_tokenize() ) {
			//InfcrwdsPlugin()->logger->log( 'Switching back to paypal Standard: Reason: should_tokenize() false.' );

			return $args;
		}

		if ( false === $this->has_api_credentials_set() ) {
			//InfcrwdsPlugin()->logger->log( 'Switching back to paypal Standard: Reason: Credentials are not set.' );

			return $args;
		}

		/**
		 * Check if gateway is enabled and we have reference transactions turned off.
		 */
		if ( true === $this->is_enabled() && false === $this->is_reference_trans_enabled() ) {

			/**
			 * In this case we have to initiate the funnel manually and we do not need to wait for payment complete to perform the action.
			 */
			// WFOCU_Core()->public->maybe_setup_upsell( INFCWDS_WC_Compatibility::get_order_id( $order ) );

			// $order_behavior = WFOCU_Core()->funnels->get_funnel_option( 'order_behavior' );
			// $is_batching_on = ( 'batching' === $order_behavior ) ? true : false;

			// if ( true === $is_batching_on && 0 != did_action( 'infcrwds_front_init_funnel_hooks' ) ) {
			// 	WFOCU_Core()->orders->maybe_set_funnel_running_status( $order );
			// }

			/**
			 * Set return URL for the PayPal, as the data setup completes, we can safely assume that this return URL would be a valid offer URL.
			 */
            
            $args['return'] = $this->get_wc_gateway()->get_return_url( $order );
			//InfcrwdsPlugin()->logger->log( 'PayPal Args from infcrwds: ' . print_R( $args, true ) );

			return $args;
		} else {

			if ( true !== $this->is_enabled( $order ) ) {
				// InfcrwdsPlugin()->logger->log( 'Switching back to paypal Standard: Reason: Paypal Standard is not enabled in settings.' );

				return $args;
			}

			// First we need to request an express checkout token for setting up a billing agreement, to do that, we need to pull details of the transaction from the PayPal Standard args and massage them into the Express Checkout params
			$req = new Paypal_SetExpressCheckout_Request(false, $this, null);

			$req->initialize($args['currency_code'],
				$this->get_callback_url( 'infcrwds_paypal_create_billing_agreement'),
				$args['cancel_return'],
				$args['notify_url'],
				$args['custom']
			);

			$req->set_payment_params_for_order($order);
			$req->set_reference_transaction();
			$token = $req->finalize();

			if ( !$token) {

				// InfcrwdsPlugin()->logger->log( 'Switching back to paypal Standard: Reason: Unable to set Express checkout' );
				// InfcrwdsPlugin()->logger->log( 'Result For setExpressCheckout' . print_r( $response, true ) );

				return $args;
			}

			// WFOCU_Core()->data->set( 'transient_key', '_infcrwds_funnel_data_' . INFCWDS_WC_Compatibility::get_order_id( $order ) );
			// WFOCU_Core()->data->save();
			$paypal_args = array(
				'cmd'   => '_express-checkout',
				'token' => $token,
			);

			return $paypal_args;
		}
	}

	public function get_environment() {
		return (true === $this->get_wc_gateway()->testmode) ? 'sandbox' : 'production';
	}

	public function has_api_credentials_set() {
		$credentials_are_set = false;
		$environment         = $this->get_environment();

		$api_creds_prefix = '';
		if ( 'sandbox' === $environment ) {
			$api_creds_prefix = 'sandbox_';
		}

		if ( '' !== $this->get_wc_gateway()->get_option( $api_creds_prefix . 'api_username' ) && '' !== $this->get_wc_gateway()->get_option( $api_creds_prefix . 'api_password' ) && '' !== $this->get_wc_gateway()->get_option( $api_creds_prefix . 'api_signature' ) ) {
			$credentials_are_set = true;
		}

		return $credentials_are_set;
	}

	/**
	 * Sets the prams for setExpressCheckout call and executes it
	 *
	 * @param array $args
	 * @param bool $is_upsell
	 *
	 * @return object
	 * @throws Exception
	 */
	public function set_express_checkout( $args, $is_upsell = false ) {

		$environment = $this->get_environment();

		$api_creds_prefix = '';
		if ( 'sandbox' === $environment ) {
			$api_creds_prefix = 'sandbox_';
		}

		$this->set_api_credentials( $this->get_key(), $environment, $this->get_wc_gateway()->get_option( $api_creds_prefix . 'api_username' ), $this->get_wc_gateway()->get_option( $api_creds_prefix . 'api_password' ), $this->get_wc_gateway()->get_option( $api_creds_prefix . 'api_signature' ) );
		$this->set_express_checkout_args( $args, $is_upsell );
		$this->populate_credentials( $this->api_username, $this->api_password, $this->api_signature, 124 );

		$request         = new stdClass();
		$request->path   = '';
		$request->method = 'POST';
        $request->body   = $this->to_string();
        WC()->session->set( 'paypal_request_data', $this->get_parameters());

		return $this->perform_request( $request );
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
		$environment      = $this->get_environment();
		$api_creds_prefix = '';
		if ( 'sandbox' === $environment ) {
			$api_creds_prefix = 'sandbox_';
		}
		$this->set_api_credentials( $this->get_key(), $environment, $this->get_wc_gateway()->get_option( $api_creds_prefix . 'api_username' ), $this->get_wc_gateway()->get_option( $api_creds_prefix . 'api_password' ), $this->get_wc_gateway()->get_option( $api_creds_prefix . 'api_signature' ) );
		$this->get_express_checkout_args( $token );
		$this->populate_credentials( $this->api_username, $this->api_password, $this->api_signature, 124 );
		$request         = new \stdClass();
		$request->path   = '';
		$request->method = 'POST';
		$request->body   = $this->to_string();

		return $this->perform_request( $request );
	}


	/**
	 * Sets up arguments and performs DoExpressCheckout call
	 *
	 * @param $token
	 * @param $order
	 * @param $args
	 *
	 * @return object
	 * @throws Exception
	 */
	public function do_express_checkout( $token, $order, $args ) {
		$environment      = $this->get_environment();
		$api_creds_prefix = '';
		if ( 'sandbox' === $environment ) {
			$api_creds_prefix = 'sandbox_';
		}
		$this->set_api_credentials( $this->get_key(), $environment, $this->get_wc_gateway()->get_option( $api_creds_prefix . 'api_username' ), $this->get_wc_gateway()->get_option( $api_creds_prefix . 'api_password' ), $this->get_wc_gateway()->get_option( $api_creds_prefix . 'api_signature' ) );
		$this->set_do_express_checkout_args( $token, $order, $args );
		$this->populate_credentials( $this->api_username, $this->api_password, $this->api_signature, 124 );
		$request         = new stdClass();
		$request->path   = '';
		$request->method = 'POST';
		$request->body   = $this->to_string();

		return $this->perform_request( $request );
	}


	/**
	 * Sets up arguments and performs DoReferenceTransaction call
	 *
	 * @param $billing_agreement_id
	 * @param $order
	 * @param $args
	 *
	 * @return object
	 * @throws Exception
	 */
	public function do_reference_transaction( $billing_agreement_id, $order, $args ) {
		$environment      = $this->get_environment();
		$api_creds_prefix = '';
		if ( 'sandbox' === $environment ) {
			$api_creds_prefix = 'sandbox_';
		}
		$this->set_api_credentials( $this->get_key(), $environment, $this->get_wc_gateway()->get_option( $api_creds_prefix . 'api_username' ), $this->get_wc_gateway()->get_option( $api_creds_prefix . 'api_password' ), $this->get_wc_gateway()->get_option( $api_creds_prefix . 'api_signature' ) );
		$this->do_reference_transaction_args( $billing_agreement_id, $order, $args );
		$this->populate_credentials( $this->api_username, $this->api_password, $this->api_signature, 124 );
		$request         = new stdClass();
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

	/**
	 * Sets up DoExpressCheckoutPayment API Call arguments
	 *
	 * @param string $token Unique token of the payment initiated
	 * @param WC_Order $order
	 * @param array $args
	 */
	public function set_do_express_checkout_args( $token, $order, $args ) {
		$this->set_method( 'DoExpressCheckoutPayment' );

		// set base params
		$this->add_parameters( array(
			'TOKEN'            => $token,
			'PAYERID'          => $args['payer_id'],
			'BUTTONSOURCE'     => 'WooThemes_Cart',
			'RETURNFMFDETAILS' => 1,
		) );

		$this->add_payment_details_parameters( $order, $args['payment_action'] );
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
		$this->request_uri = ( 'production' === $api_environment ) ? self::PRODUCTION_ENDPOINT : self::SANDBOX_ENDPOINT;

		// PayPal requires HTTP 1.1
		$this->request_http_version = '1.1';

		$this->api_username  = $api_username;
		$this->api_password  = $api_password;
		$this->api_signature = $api_signature;
	}

	/**
	 * @hooked over `wc_api_infcrwds_paypal`
	 * Its a redirect from paypal and contains success
	 *
	 */
	public function maybe_create_billing() {
		if ( ! isset( $_GET['action'] ) ) {
			return;
		}

		switch ( $_GET['action'] ) {

			// called when the customer is returned from PayPal after authorizing their payment, used for retrieving the customer's checkout details
			case 'infcrwds_paypal_create_billing_agreement':
				// bail if no token
				if ( ! isset( $_GET['token'] ) ) {
					return;
				}

				// get token to retrieve checkout details with
				$token = esc_attr( $_GET['token'] );

				try {

					$express_checkout_details_response = $this->get_express_checkout_details( $token );

					// Make sure the billing agreement was accepted
					if ( 1 == $express_checkout_details_response['BILLINGAGREEMENTACCEPTEDSTATUS'] ) {

						$order = $this->get_order_from_response( $express_checkout_details_response );

						if ( is_null( $order ) ) {
							throw new Exception( __( 'Unable to find order for PayPal billing agreement.', 'woocommerce-subscriptions' ) );
						}

						// we need to process an initial payment
						if ( $order->get_total() > 0 ) {
							$expcheckout_req = new Paypal_DoExpressCheckout_Request(
								false,
								$this,
								$this->get_value_from_response( $express_checkout_details_response, 'PAYERID' ),
								$token,
								$this->_store->get_current_ic_session_id());
							
							$expcheckout_req->set_payment_params_for_order($order);
							$expcheckout_req->finalize();
							$billing_agreement_response = $expcheckout_req->response;

						} else {
							$redirect_url = add_query_arg( 'utm_nooverride', '1', $order->get_checkout_order_received_url() );

							// redirect customer to order received page
							wp_safe_redirect( esc_url_raw( $redirect_url ) );
							exit;

						}

						if ( $this->has_api_error( $billing_agreement_response ) ) {

							// InfcrwdsPlugin()->logger->log( 'Order #' . INFCWDS_WC_Compatibility::get_order_id( $order ) . ' Billing agreement Failure found. Report Below' );
							// InfcrwdsPlugin()->logger->log( print_r( $billing_agreement_response, true ) );

							$redirect_url = add_query_arg( 'utm_nooverride', '1', $order->get_checkout_order_received_url() );

							// redirect customer to order received page
							wp_safe_redirect( esc_url_raw( $redirect_url ) );
							exit;
						}

						$order->set_payment_method( 'paypal' );

						// InfcrwdsPlugin()->logger->log( 'Order #' . INFCWDS_WC_Compatibility::get_order_id( $order ) . ': DoexpressCheckoutREsponse' . print_r( $billing_agreement_response, true ) );

						// Store the billing agreement ID on the order and subscriptions
						update_post_meta( INFCWDS_WC_Compatibility::get_order_id( $order ), '_paypal_subscription_id', $this->get_value_from_response( $billing_agreement_response, 'BILLINGAGREEMENTID' ) );
						$order->payment_complete( $billing_agreement_response['PAYMENTINFO_0_TRANSACTIONID'] );

						$redirect_url = add_query_arg( 'utm_nooverride', '1', $order->get_checkout_order_received_url() );

						// redirect customer to order received page
						wp_safe_redirect( esc_url_raw( $redirect_url ) );
						exit;

					} else {

						wp_safe_redirect( wc_get_cart_url() );
						exit;

					}
				} catch ( Exception $e ) {

					wc_add_notice( __( 'An error occurred, please try again or try an alternate form of payment.', 'woocommerce-subscriptions' ), 'error' );

					wp_redirect( wc_get_cart_url() );
				}

				exit;

		}
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
	 * Returns the string representation of this request
	 *
	 * @see SV_WC_Payment_Gateway_API_Request::to_string()
	 * @return string the request query string
	 * @since 2.0
	 */
	public function to_string() {
		//InfcrwdsPlugin()->logger->log( print_r( $this->get_parameters(), true ) );

		return http_build_query( $this->get_parameters(), '', '&' );
	}


	/**
	 * Try and get the payment token saved by the gateway
	 *
	 * @param WC_Order $order
	 *
	 * @return true on success false otherwise
	 */
	public function has_token( $order ) {

		$get_token = $this->get_token( $order );

		if ( false === $get_token ) {
			return false;
		}

		if ( '' === $get_token ) {
			return false;
		}

		if ( null === $get_token ) {
			return false;
		}

		return true;

	}

	public function get_token( $order ) {

        if ( false == is_null( $this->token ) ) {
            return $this->token;
		}
        $get_id = INFCWDS_WC_Compatibility::get_order_id( $order );

		$this->token = $order->get_meta( '_paypal_subscription_id' );
		if ( '' == $this->token ) {
			$this->token = get_post_meta( $get_id, '_paypal_subscription_id', true );
		}
		if ( ! empty( $this->token ) ) {
			return $this->token;
		}

		return apply_filters( 'infcrwds_front_gateway_integration_get_token', false, $this );
	}


	public function process_charge( $order, $offer ) {

		$is_successful = false;
		try {

			$response = $this->do_reference_transaction( $this->get_token( $order ), $order, array() );

			if ( $this->has_api_error( $response ) ) {
				InfcrwdsPlugin()->logger->error( 'PayPal DoReferenceTransactionCall Failed: Response Below' );
				InfcrwdsPlugin()->logger->error( print_r( $response, true ) );
				$is_successful = false;

			} else {
				
				$is_successful = true;

			}
		} catch ( Exception $e ) {

			InfcrwdsPlugin()->logger->error( 'PayPal DoReferenceTransactionCall Failed: Response Below' );
			InfcrwdsPlugin()->logger->error( print_r( $response, true ) );
		}

		return true;
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

		$this->add_parameters($this->create_credentials(
			$api_username,
			$api_password,
			$api_signature,
			$api_version
		));
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
	public function set_express_checkout_args( $args, $is_upsell = false ) {

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
			'LANDINGPAGE' => ( 'login' == $args['landing_page'] && $is_upsell == false ) ? 'Login' : 'Billing',
			'NOSHIPPING'  => $args['no_shipping'],

			'ADDROVERRIDE' => $args['addressoverride'],
			'MAXAMT'       => $args['maximum_amount'],
		) );

		if ( false === $is_upsell ) {
			$this->add_parameter( 'L_BILLINGTYPE0', $args['billing_type'] );
			$this->add_parameter( 'L_BILLINGAGREEMENTDESCRIPTION0', get_bloginfo( 'name' ) );
			$this->add_parameter( 'L_BILLINGAGREEMENTCUSTOM0', '' );
		}
		// if we have an order, the request is to create a subscription/process a payment (not just check if the PayPal account supports Reference Transactions)
		if ( isset( $args['order'] ) ) {

			if ( true === $is_upsell ) {
				$this->add_payment_details_parameters( $args['order'], $args['payment_action'], false, true );

			} else {
				$this->add_payment_details_parameters( $args['order'], $args['payment_action'] );

			}
		}
		if ( empty( $args['no_shipping'] ) ) {
			$this->maybe_add_shipping_address_params( $args['order'] );

		}
		$set_express_checkout_params = apply_filters( 'infcrwds_gateway_paypal_param_setexpresscheckout', $this->get_parameters(), $is_upsell );
		$this->clean_params();
		$this->add_parameters( $set_express_checkout_params );
	}


	/**
	 * Create a billing agreement, required when a subscription sign-up has no initial payment
	 *
	 * @link https://developer.paypal.com/docs/classic/express-checkout/integration-guide/ECReferenceTxns/#id094TB0Y0J5Z__id094TB4003HS
	 * @link https://developer.paypal.com/docs/classic/api/merchant/CreateBillingAgreement_API_Operation_NVP/
	 *
	 * @param string $token token from SetExpressCheckout response
	 *
	 * @since 2.0
	 */
	public function create_billing_agreement( $token ) {

		$this->set_method( 'CreateBillingAgreement' );
		$this->add_parameter( 'TOKEN', $token );
	}

	/**
	 * Charge a payment against a reference token
	 *
	 * @link https://developer.paypal.com/docs/classic/express-checkout/integration-guide/ECReferenceTxns/#id094UM0DA0HS
	 * @link https://developer.paypal.com/docs/classic/api/merchant/DoReferenceTransaction_API_Operation_NVP/
	 *
	 * @param string $reference_id the ID of a reference object, e.g. billing agreement ID.
	 * @param WC_Order $order order object
	 * @param array $args {
	 *
	 * @type string 'payment_type'         (Optional) Specifies type of PayPal payment you require for the billing agreement. It is one of the following values. 'Any' or 'InstantOnly'. Echeck is not supported for DoReferenceTransaction requests.
	 * @type string 'payment_action'       How you want to obtain payment. It is one of the following values: 'Authorization' - this payment is a basic authorization subject to settlement with PayPal Authorization and Capture; or 'Sale' - This is a final sale for which you are requesting payment.
	 * @type string 'return_fraud_filters' (Optional) Flag to indicate whether you want the results returned by Fraud Management Filters. By default, you do not receive this information.
	 * }
	 * @since 2.0
	 */
	public function do_reference_transaction_args( $reference_id, $order, $args = array() ) {
		$get_package = WFOCU_Core()->data->get( '_upsell_package' );

		$defaults = array(
			'amount'               => $get_package['total'],
			'payment_type'         => 'Any',
			'payment_action'       => 'Sale',
			'return_fraud_filters' => 1,
			'notify_url'           => WC()->api_request_url( 'WC_Gateway_Paypal' ),
			'invoice_number'       => $this->get_order_number( $order ),
		);

		$args = wp_parse_args( $args, $defaults );

		$this->set_method( 'DoReferenceTransaction' );

		/**
		 * We unset the notify url as we do not want IPN for this call.
		 */
		// set base params
		$this->add_parameters( array(
			'REFERENCEID'      => $reference_id,
			'BUTTONSOURCE'     => 'WooThemes_Cart',
			'RETURNFMFDETAILS' => $args['return_fraud_filters'],
		) );

		$this->add_payment_details_parameters( $order, $args['payment_action'], true, true );
		if ( true === WFOCU_Core()->process_offer->package_needs_shipping() ) {
			$this->maybe_add_shipping_address_params( $order, 'SHIPTO' );
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
	protected function add_payment_details_parameters( WC_Order $order, $type, $use_deprecated_params = false, $is_offer_charge = false ) {

		$calculated_total = 0;
		$order_subtotal   = 0;
		$item_count       = 0;
		$order_items      = array();

		$offer_package = WFOCU_Core()->data->get( '_upsell_package' );

		if ( true === $is_offer_charge ) {

			foreach ( $offer_package['products'] as $item ) {

				$product = $item['data'];

				$order_items[] = array(
					'NAME'    => $product->get_title(),
					'DESC'    => $this->get_item_description( $product, $is_offer_charge ),
					'AMT'     => $this->round( $item['price'] ),
					'QTY'     => 1,
					'ITEMURL' => $product->get_permalink(),
				);

				$order_subtotal += $item['args']['total'];
			}
		} else {
			// add line items
			foreach ( $order->get_items() as $item ) {

				$product = new WC_Product( $item['product_id'] );

				$order_items[] = array(
					'NAME'    => $product->get_title(),
					'DESC'    => $this->get_item_description( $product, $is_offer_charge ),
					'AMT'     => $this->round( $order->get_item_subtotal( $item ) ),
					'QTY'     => ( ! empty( $item['qty'] ) ) ? absint( $item['qty'] ) : 1,
					'ITEMURL' => $product->get_permalink(),
				);

				$order_subtotal += $item['line_total'];
			}

			// add fees
			foreach ( $order->get_fees() as $fee ) {

				$order_items[] = array(
					'NAME' => ( $fee['name'] ),
					'AMT'  => $this->round( $fee['line_total'] ),
					'QTY'  => 1,
				);

				$order_subtotal += $fee['line_total'];
			}
			if ( $order->get_total_discount() > 0 ) {

				$order_items[] = array(
					'NAME' => __( 'Total Discount', 'woocommerce-subscriptions' ),
					'QTY'  => 1,
					'AMT'  => - $this->round( $order->get_total_discount() ),
				);
			}
		}

		/**Do things for the main order **/
		if ( false === $is_offer_charge ) {
			if ( $this->skip_line_items( $order ) ) {

				$total_amount = $this->round( $order->get_total() );

				// calculate the total as PayPal would
				$calculated_total += $this->round( $order_subtotal + $order->get_cart_tax() ) + $this->round( $order->get_total_shipping() + $order->get_shipping_tax() );

				// offset the discrepancy between the WooCommerce cart total and PayPal's calculated total by adjusting the order subtotal
				if ( $this->price_format( $total_amount ) !== $this->price_format( $calculated_total ) ) {
					$order_subtotal = $order_subtotal - ( $calculated_total - $total_amount );
				}

				$item_names = array();

				foreach ( $order_items as $item ) {
					$item_names[] = sprintf( '%1$s x %2$s', $item['NAME'], $item['QTY'] );
				}

				// add a single item for the entire order
				$this->add_line_item_parameters( array(
					// translators: placeholder is blogname
					'NAME' => sprintf( __( '%s - Order', 'woocommerce-subscriptions' ), get_option( 'blogname' ) ),
					'DESC' => $this->get_item_description( implode( ', ', $item_names ) ),
					'AMT'  => $this->round( $order_subtotal + $order->get_cart_tax() ),
					'QTY'  => 1,
				), 0, $use_deprecated_params );


				// add order-level parameters
				//  - Do not send the TAXAMT due to rounding errors
				if ( $use_deprecated_params ) {
					$this->add_parameters( array(
						'AMT'              => $total_amount,
						'CURRENCYCODE'     => INFCWDS_WC_Compatibility::get_order_currency( $order ),
						'ITEMAMT'          => $this->round( $order_subtotal + $order->get_cart_tax() ),
						'SHIPPINGAMT'      => $this->round( $order->get_total_shipping() + $order->get_shipping_tax() ),
						'INVNUM'           => $this->get_wc_gateway()->get_option( 'invoice_prefix' ) . WFOCU_Common::str_to_ascii( ltrim( $order->get_order_number(), _x( '#', 'hash before the order number. Used as a character to remove from the actual order number', 'woocommerce-subscriptions' ) ) ),
						'PAYMENTACTION'    => $type,
						'PAYMENTREQUESTID' => INFCWDS_WC_Compatibility::get_order_id( $order ),
						'CUSTOM'           => json_encode( array(
							'_wfocu_o_id'       => INFCWDS_WC_Compatibility::get_order_id( $order ),
							'_wfocu_session_id' => WFOCU_Core()->data->get_transient_key(),
						) ),
					) );
				} else {
					$this->add_payment_parameters( array(
						'AMT'              => $total_amount,
						'CURRENCYCODE'     => INFCWDS_WC_Compatibility::get_order_currency( $order ),
						'ITEMAMT'          => $this->round( $order_subtotal + $order->get_cart_tax() ),
						'SHIPPINGAMT'      => $this->round( $order->get_total_shipping() + $order->get_shipping_tax() ),
						'INVNUM'           => $this->get_wc_gateway()->get_option( 'invoice_prefix' ) . WFOCU_Common::str_to_ascii( ltrim( $order->get_order_number(), _x( '#', 'hash before the order number. Used as a character to remove from the actual order number', 'woocommerce-subscriptions' ) ) ),
						'PAYMENTACTION'    => $type,
						'PAYMENTREQUESTID' => INFCWDS_WC_Compatibility::get_order_id( $order ),
						'CUSTOM'           => json_encode( array(
							'_wfocu_o_id'       => INFCWDS_WC_Compatibility::get_order_id( $order ),
							'_wfocu_session_id' => WFOCU_Core()->data->get_transient_key(),
						) ),
					) );
				}
			} else {

				// add individual order items
				foreach ( $order_items as $item ) {
					$this->add_line_item_parameters( $item, $item_count ++, $use_deprecated_params );
				}

				$total_amount = $this->round( $order->get_total() );
				// add order-level parameters
				if ( $use_deprecated_params ) {
					$this->add_parameters( array(
						'AMT'              => $total_amount,
						'CURRENCYCODE'     => INFCWDS_WC_Compatibility::get_order_currency( $order ),
						'ITEMAMT'          => $this->round( $order_subtotal ),
						'SHIPPINGAMT'      => $this->round( $order->get_total_shipping() ),
						'TAXAMT'           => $this->round( $order->get_total_tax() ),
						'INVNUM'           => $this->get_wc_gateway()->get_option( 'invoice_prefix' ) . $this->get_order_number( $order ),
						'PAYMENTACTION'    => $type,
						'PAYMENTREQUESTID' => INFCWDS_WC_Compatibility::get_order_id( $order ),

					) );
				} else {
					$this->add_payment_parameters( array(
						'AMT'              => $total_amount,
						'CURRENCYCODE'     => INFCWDS_WC_Compatibility::get_order_currency( $order ),
						'ITEMAMT'          => $this->round( $order_subtotal ),
						'SHIPPINGAMT'      => $this->round( $order->get_total_shipping() ),
						'TAXAMT'           => $this->round( $order->get_total_tax() ),
						'INVNUM'           => $this->get_wc_gateway()->get_option( 'invoice_prefix' ) . $this->get_order_number( $order ),
						'PAYMENTACTION'    => $type,
						'PAYMENTREQUESTID' => INFCWDS_WC_Compatibility::get_order_id( $order ),
						'CUSTOM'           => json_encode( array(
							'_wfocu_o_id'       => INFCWDS_WC_Compatibility::get_order_id( $order ),
							'_wfocu_session_id' => WFOCU_Core()->data->get_transient_key(),

						) ),
					) );
				}
			}
		} /** Handle paypal data setup for the offers */

		else {

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
			 * Check if this is a referencetransaction call then send depreceated params
			 */
			if ( true === $use_deprecated_params && true === $is_offer_charge ) {
				/**
				 * When shipping amount is a negative number, means user opted for free shipping offer
				 * In this case we setup shippingamt as 0 and shipping discount amount is that negative amount that is coming.
				 */
				if ( ( isset( $offer_package['shipping'] ) && isset( $offer_package['shipping']['diff'] ) ) && 0 > $offer_package['shipping']['diff']['cost'] ) {
					$this->add_parameters( array(
						'AMT'              => $total_amount,
						'CURRENCYCODE'     => INFCWDS_WC_Compatibility::get_order_currency( $order ),
						'ITEMAMT'          => $this->round( $order_subtotal ),
						'SHIPPINGAMT'      => 0,
						'SHIPDISCAMT'      => ( isset( $offer_package['shipping'] ) && isset( $offer_package['shipping']['diff'] ) ) ? $offer_package['shipping']['diff']['cost'] : 0,
						'INVNUM'           => $this->get_wc_gateway()->get_option( 'invoice_prefix' ) . $this->get_order_number( $order ),
						'PAYMENTACTION'    => $type,
						'PAYMENTREQUESTID' => INFCWDS_WC_Compatibility::get_order_id( $order ),
						'TAXAMT'           => ( isset( $offer_package['taxes'] ) ) ? $offer_package['taxes'] : 0,
						'CUSTOM'           => json_encode( array(
							'_wfocu_o_id'       => $this->get_wc_gateway()->get_option( 'invoice_prefix' ) . $this->get_order_number( $order ),
							'_wfocu_session_id' => WFOCU_Core()->data->get_transient_key(),
						) ),
					) );
				} else {
					$this->add_parameters( array(
						'AMT'              => $total_amount,
						'CURRENCYCODE'     => INFCWDS_WC_Compatibility::get_order_currency( $order ),
						'ITEMAMT'          => $this->round( $order_subtotal ),
						'SHIPPINGAMT'      => ( isset( $offer_package['shipping'] ) && isset( $offer_package['shipping']['diff'] ) ) ? $offer_package['shipping']['diff']['cost'] : 0,
						'INVNUM'           => $this->get_wc_gateway()->get_option( 'invoice_prefix' ) . $this->get_order_number( $order ),
						'PAYMENTACTION'    => $type,
						'PAYMENTREQUESTID' => INFCWDS_WC_Compatibility::get_order_id( $order ),
						'TAXAMT'           => ( isset( $offer_package['taxes'] ) ) ? $offer_package['taxes'] : 0,
						'CUSTOM'           => json_encode( array(
							'_wfocu_o_id'       => $this->get_wc_gateway()->get_option( 'invoice_prefix' ) . $this->get_order_number( $order ),
							'_wfocu_session_id' => WFOCU_Core()->data->get_transient_key(),
						) ),
					) );
				}
			} else {
				if ( ( isset( $offer_package['shipping'] ) && isset( $offer_package['shipping']['diff'] ) ) && 0 > $offer_package['shipping']['diff']['cost'] ) {
					$this->add_payment_parameters( array(
						'AMT'              => $total_amount,
						'CURRENCYCODE'     => INFCWDS_WC_Compatibility::get_order_currency( $order ),
						'ITEMAMT'          => $this->round( $order_subtotal ),
						'SHIPPINGAMT'      => 0,
						'SHIPDISCAMT'      => ( isset( $offer_package['shipping'] ) && isset( $offer_package['shipping']['diff'] ) ) ? $offer_package['shipping']['diff']['cost'] : 0,
						'INVNUM'           => $this->get_wc_gateway()->get_option( 'invoice_prefix' ) . $this->get_order_number( $order ),
						'PAYMENTACTION'    => $type,
						'PAYMENTREQUESTID' => INFCWDS_WC_Compatibility::get_order_id( $order ),
						'TAXAMT'           => ( isset( $offer_package['taxes'] ) ) ? $offer_package['taxes'] : 0,
						'CUSTOM'           => json_encode( array(
							'_wfocu_o_id'       => $this->get_wc_gateway()->get_option( 'invoice_prefix' ) . $this->get_order_number( $order ),
							'_wfocu_session_id' => WFOCU_Core()->data->get_transient_key(),
						) ),
					) );
				} else {
					$this->add_payment_parameters( array(
						'AMT'              => $total_amount,
						'CURRENCYCODE'     => INFCWDS_WC_Compatibility::get_order_currency( $order ),
						'ITEMAMT'          => $this->round( $order_subtotal ),
						'SHIPPINGAMT'      => ( isset( $offer_package['shipping'] ) && isset( $offer_package['shipping']['diff'] ) ) ? $offer_package['shipping']['diff']['cost'] : 0,
						'INVNUM'           => $this->get_wc_gateway()->get_option( 'invoice_prefix' ) . $this->get_order_number( $order ),
						'PAYMENTACTION'    => $type,
						'PAYMENTREQUESTID' => INFCWDS_WC_Compatibility::get_order_id( $order ),
						'TAXAMT'           => ( isset( $offer_package['taxes'] ) ) ? $offer_package['taxes'] : 0,
						'CUSTOM'           => json_encode( array(
							'_wfocu_o_id'       => $this->get_wc_gateway()->get_option( 'invoice_prefix' ) . $this->get_order_number( $order ),
							'_wfocu_session_id' => WFOCU_Core()->data->get_transient_key(),
						) ),
					) );
				}
			}
		}
	}

	/** Helper Methods ******************************************************/

	/**
	 * Add a parameter
	 *
	 * @param string $key
	 * @param string|int $value
	 *
	 * @since 2.0
	 */
	public function add_parameter( $key, $value ) {
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

	public function clean_params() {
		$this->parameters = array();
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
	private function get_item_description( $product_or_str ) {

		if ( is_string( $product_or_str ) ) {
			$str = $product_or_str;
		} else {
			$str = $product_or_str->get_short_description();
		}
		$item_desc = wp_strip_all_tags( wp_specialchars_decode( wp_staticize_emoji( $str ) ) );
		$item_desc = preg_replace( '/[\x00-\x1F\x80-\xFF]/', '', $item_desc );
		$item_desc = str_replace( "\n", ', ', rtrim( $item_desc ) );
		if ( strlen( $item_desc ) > 127 ) {
			$item_desc = substr( $item_desc, 0, 124 ) . '...';
		}

		return html_entity_decode( $item_desc, ENT_NOQUOTES, 'UTF-8' );

	}


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

	public function get_parameters() {
		return $this->parse_parameters($this->parameters);
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

	public function get_value_from_response( $response, $key ) {

		if ( $response && isset( $response[ $key ] ) ) {

			return $response[ $key ];
		}
	}

	public function get_order_from_response( $response ) {

		if ( $response && isset( $response['CUSTOM'] ) ) {
			$getjson = json_decode( $response['CUSTOM'], true );

			return wc_get_order( $getjson['_infcrwds_o_id'] );
		}
	}

	public function get_session_from_response( $response ) {

		if ( $response && isset( $response['CUSTOM'] ) ) {
			$getjson = json_decode( $response['CUSTOM'], true );

			return ( $getjson['_infcrwds_session_id'] );
		}
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

	public function get_transaction_id( $response ) {

		if ( is_array( $response ) && isset( $response['PAYMENTINFO_0_TRANSACTIONID'] ) ) {
			return $response['PAYMENTINFO_0_TRANSACTIONID'];
		}

		return '';
	}


	public function is_reference_trans_enabled() {
        return $this->_store->get('is_pp_ref_trans_enabled', false);
	}









	/************************************** PAYPAL IN_OFFER TRANSACTION STARTS *********************/


	/**
	 * Tell the system to run without a token or not
	 * @return bool
	 */
	public function is_run_without_token() {
		return true;
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
		$environment = ( true === $this->get_wc_gateway()->testmode ) ? 'sandbox' : 'live';
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

	public function add_line_item($name, $desc, $amt, $quantity, $url, $use_deprecated_params=false, $idx=0) {
		$this->add_line_item_parameters(array(
			'NAME' => $name,
			'DESC' => $desc,
			'AMT'  => $this->round($amt),
			'QTY'  => $quantity,
			'ITEMURL' => $url
		), $idx, $use_deprecated_params);
	}

	// public function begin_express_checkout_payment($use_deprecated_params,
	// 	$session_id,
	// 	$order,
	// 	$has_shipping) {
		
	// 	$request = new Paypal_SetExpressCheckout_Request($use_deprecated_params, $this, $session_id);
		
	// 	$request->initialize(INFCWDS_WC_Compatibility::get_order_currency( $order ),
	// 		$this->get_callback_url( 'infcrwds_paypal_return' ),
	// 		$this->get_callback_url( 'cancel_url' ),
	// 		$this->get_callback_url( 'notify_url' ),
	// 		$has_shipping, 'Login');
		
	// 	return $request;
	// }

	public function create_express_checkout_token() {

		/**
		 * @todo code here to validate the request params
		 * @todo code here to instantiate the core class that needs to handle further call probably WFOCU_Core()->public
		 */
		$get_current_offer      = WFOCU_Core()->data->get( 'current_offer' );
		$get_current_offer_meta = WFOCU_Core()->offers->get_offer_meta( $get_current_offer );
		WFOCU_Core()->data->set( '_offer_result', true );
		$posted_data = WFOCU_Core()->process_offer->parse_posted_data();

		$response = false;

		if ( true === WFOCU_AJAX_Controller::validate_charge_request( $posted_data ) ) {

			WFOCU_Core()->process_offer->execute( $get_current_offer_meta );

			$get_order = WFOCU_Core()->data->get_parent_order();
			// First we need to request an express checkout token for setting up a billing agreement, to do that, we need to pull details of the transaction from the PayPal Standard args and massage them into the Express Checkout params
			$response = $this->set_express_checkout( array(
				'currency'    => INFCWDS_WC_Compatibility::get_order_currency( $get_order ),
				'return_url'  => $this->get_callback_url( 'infcrwds_paypal_return' ),
				'cancel_url'  => $this->get_callback_url( 'cancel_url' ),
				'notify_url'  => $this->get_callback_url( 'notify_url' ),
				'order'       => $get_order,
				'no_shipping' => WFOCU_Core()->process_offer->package_needs_shipping() ? 0 : 1,
			), true );
			InfcrwdsPlugin()->logger->log( 'Result For setExpressCheckout' . print_r( $response, true ) );


			if ( isset( $response['TOKEN'] ) && '' !== $response['TOKEN'] ) {
				WFOCU_Core()->data->set( 'token', $response['TOKEN'], 'paypal' );
				WFOCU_Core()->data->set( 'upsell_package', WFOCU_Core()->data->get( '_upsell_package' ), 'paypal' );
				WFOCU_Core()->data->save( 'paypal' );
				WFOCU_Core()->data->save();
				wp_send_json( array(
					'result' => 'success',
					'token'  => $response['TOKEN'],
				) );
			} else {
				$get_error_str = $this->get_api_error( $response );
				$get_order->add_order_note( sprintf( __( 'Offer payment failed. Reason: %s', 'woofunnels-upstroke-one-click-upsell' ), $get_error_str ) );


				$data     = WFOCU_Core()->process_offer->_handle_upsell_charge( false );
				$response = $data;
			}


		}
		wp_send_json( array(
			'result'   => 'error',
			'response' => $response,
		) );


	}


	public function allow_paypal_express_check_action( $actions ) {
		array_push( $actions, 'infcrwds_front_create_express_checkout_token' );

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
	 * Get payer ID from API.
	 */
	public function get_payer_id() {

		$environment = $this->get_environment();

		$api_creds_prefix = '';
		if ( 'sandbox' === $environment ) {
			$api_creds_prefix = 'sandbox_';
		}

		$option_key = 'woocommerce_ppec_payer_id_' . $environment . '_' . md5( $this->get_wc_gateway()->get_option( $api_creds_prefix . 'api_username' ) . ':' . $this->get_wc_gateway()->get_option( $api_creds_prefix . 'api_password' ) );

		if ( $payer_id = get_option( $option_key ) ) {
			return $payer_id;
		} else {
			$result = $this->get_pal_details();

			if ( ! empty( $result['PAL'] ) ) {
				update_option( $option_key, wc_clean( $result['PAL'] ) );

				return $payer_id;
			}
		}

		return false;
	}

	public function get_pal_details() {

		$environment      = $this->get_environment();
		$api_creds_prefix = '';
		if ( 'sandbox' === $environment ) {
			$api_creds_prefix = 'sandbox_';
		}
		$this->set_api_credentials( $this->get_key(), $environment, $this->get_wc_gateway()->get_option( $api_creds_prefix . 'api_username' ), $this->get_wc_gateway()->get_option( $api_creds_prefix . 'api_password' ), $this->get_wc_gateway()->get_option( $api_creds_prefix . 'api_signature' ) );

		$this->add_parameter( 'METHOD', 'GetPalDetails' );
		$this->populate_credentials( $this->api_username, $this->api_password, $this->api_signature, 124 );
		$request         = new \stdClass();
		$request->path   = '';
		$request->method = 'POST';
		$request->body   = $this->to_string();

		return $this->perform_request( $request );

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

	/********************** PAYPAL IN-OFFER PURCHASE ********************************/


	/**
	 * We have to handle PDT as the return url when funnel runs is not checkout/order-received but offer url
	 * Hence we need to trigger paypal PDT Handler class so that it could process further.
	 */
	public function maybe_handle_pdt() {

		if ( $this->_store->is_in_session() ) {
			if ( empty( $_REQUEST['cm'] ) || empty( $_REQUEST['tx'] ) || empty( $_REQUEST['st'] ) ) { // WPCS: Input var ok, CSRF ok, sanitization ok.
				return;
			}

			// add_action( 'woocommerce_valid_order_statuses_for_payment', array( $this, 'maybe_mark_order_status' ) );

			/**
			 * check if identity token is there and class does not exists then add the class
			 */
			if ( $this->get_wc_gateway()->identity_token && false === class_exists( 'WC_Gateway_Paypal_PDT_Handler' ) ) {
				include_once plugin_dir_path( WC_PLUGIN_FILE ) . 'includes/gateways/paypal/includes/class-wc-gateway-paypal-pdt-handler.php';
			}

			if ( class_exists( 'WC_Gateway_Paypal_PDT_Handler' ) ) {

				//InfcrwdsPlugin()->logger->log( 'PDT Payment initialized' );
				$pdt = new \WC_Gateway_Paypal_PDT_Handler( $this->get_wc_gateway()->testmode, $this->get_wc_gateway()->identity_token );
				$pdt->check_response();

				/**
				 * Save Paypal IPN status so that it will be used when we move to correct status once funnel finishes.
				 */
				$status    = wc_clean( strtolower( wp_unslash( $_REQUEST['st'] ) ) ); // WPCS: input var ok, CSRF ok, sanitization ok.
				$get_order = $this->store->get_base_order();
				if ( $get_order ) {
					$get_order->update_meta_data( '_infcrwds_paypal_ipn_status', $status );
					$get_order->save_meta_data();
				}
			}
		}
	}

	/**
	 * Conditionally triggers to handle PayPal PDT handler callback conditions
	 *
	 * @param $status
	 *
	 * @see WC_Gateway_Paypal_PDT_Handler::check_response()
	 * @see WC_Order::needs_payment()
	 * @return array
	 */
	public function maybe_mark_order_status( $status ) {
		remove_action( 'woocommerce_valid_order_statuses_for_payment', array( $this, 'maybe_mark_order_status' ) );

		$status[] = 'infcrwds-pri-order';

		return $status;
	}
}


