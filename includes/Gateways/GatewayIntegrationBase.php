<?php
namespace InfCrowds\WPR\Gateways;
use INFCWDS_WC_Compatibility;
/**
 * Abstract Class for all the Gateway Support Class
 * Class GatewayIntegrationBase
 */
abstract class GatewayIntegrationBase extends SV_API_Base {


	public $amount = 0;
	public $token = null;
	public $refund_supported = false;
	protected $_store = null;
	protected $key = '';

	public function __construct($store, $order_maker_factory) {
		$this->_store = $store;
		$this->_order_maker_factory = $order_maker_factory;
	}

	public function write_success_order($offer, $order, $session_id, $transaction_id, $redirect=true) {
		
		$maker = $this->_order_maker_factory->createOrderMaker($offer, $order, true);
		$final_order = $maker->makeSuccessOrder($session_id, $transaction_id);
		do_action( 'infcrwds_offer_accepted_and_processed', $offer, $final_order, $order, $transaction_id );

		if($redirect) {
			$redirect_url = add_query_arg( 'utm_nooverride', '1', $order->get_checkout_order_received_url() );

			// redirect customer to order received page
			wp_safe_redirect( esc_url_raw( $redirect_url ) );
			exit;
		}
		return $final_order;
	}

	public function write_payment_failed_order($offer, $order, $session_id, $transaction_id, $reason, $redirect=true) {
		$maker = $this->_order_maker_factory->createOrderMaker($offer, $order, false);
		$final_order = $maker->makePaymentFailedOrder($session_id, $transaction_id, $reason);
		
		if($redirect) {
			$redirect_url = add_query_arg( 'utm_nooverride', '1', $final_order->get_checkout_order_received_url() );

			// redirect customer to order received page
			wp_safe_redirect( esc_url_raw( $redirect_url ) );
			exit;
		}
	}

	public function handle_upsell_cancelation($offer, $order, $session_id, $make_order=true) {
		if($make_order) {
			$maker = $this->_order_maker_factory->createOrderMaker($offer, $order, false);
			$maker->makeCanceledOrder($session_id);
		}
		$redirect_url = add_query_arg( 'utm_nooverride', '1', $order->get_checkout_order_received_url() );

		// redirect customer to order received page
		wp_safe_redirect( esc_url_raw( $redirect_url ) );
		exit;
	}

	/**
	 * @return WC_Payment_Gateway
	 */
	public function get_wc_gateway() {
		global $woocommerce;
		$gateways = $woocommerce->payment_gateways->payment_gateways();

		return $gateways[ $this->key ];
	}

	public function get_amount() {
		return $this->amount;
	}

	public function set_amount( $amount ) {
		$this->amount = $amount;
	}

	public function get_key() {
		return $this->key;
	}

	/**
	 * Try and get the payment token saved by the gateway
	 *
	 * @param WC_Order $order
	 *
	 * @return true on success false otherwise
	 */
	public function has_token( $order ) {
		return false;

	}

	public function should_tokenize() {
		return $this->_store->is_internal_upsell_enabled();
	}

	/**
	 * Try and get the payment token saved by the gateway
	 *
	 * @param WC_Order $order
	 *
	 * @return true on success false otherwise
	 */
	public function get_token( $order ) {
		return false;

	}

	/**
	 * Charge the upsell and capture payments
	 *
	 * @param WC_Order $order
	 *
	 * @return true on success false otherwise
	 */
	public function process_charge( $order, $offer ) {
		return false;

	}

	/**
	 * @param WC_Order $order
	 *
	 * @return bool
	 */
	public function is_enabled( $order = false ) {
		$get_chosen_gateways = $this->_store->get('gateways');
		if ( is_array( $get_chosen_gateways ) && in_array( $this->key, $get_chosen_gateways ) ) {

			return apply_filters( 'infcrowds_front_payment_gateway_integration_enabled', true, $order );
		}

		return false;
	}

	public function add_payment_options($order, $payment_options) {
		$payment_options[] = array(
			'gateway' => $this->key,
			'gateway_info' => null,
		);
		return $payment_options;
	}

	public function get_order_number( $order, $offer = null ) {

		if ( ! empty( $offer ) ) {
			return INFCWDS_WC_Compatibility::get_order_id( $order ) . '_' . $offer['offer']['offer_id'];
		} else {
			return INFCWDS_WC_Compatibility::get_order_id( $order );
		}

	}

	/**
	 * Tell the system to run without a token or not
	 * @return bool
	 */
	public function is_run_without_token() {
		return false;
	}

	/**
	 * @param WC_Order $order
	 *
	 * @return bool
	 */
	public function is_refund_supported( $order = false ) {

		if ( $this->refund_supported ) {

			return apply_filters( 'wfocu_payment_gateway_refund_supported', true, $order );
		}

		return false;
	}

	public function process_refund_offer( $order ) {
		return false;
	}


}
