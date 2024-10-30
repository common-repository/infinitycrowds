<?php
/**
 * Gateways API
 *
 *
 * @package   InfinityCrowds
 * @author    InfinityCrowds
 * @license   GPL-3.0
 * @link      https://infinitycrowds.com
 * @copyright 2019 InfinityCrowds (Pty) Ltd
 */
namespace InfCrowds\WPR\Endpoint\Gateways;
use \InfCrowds\WPR\Endpoint\BaseApi;
use \InfCrowds\WPR\ICRequest;
use \InfCrowds\WPR\Gateways\GatewayIntegrationBase;


class GatewaysApi extends BaseApi {
    
    function __construct($ns, $store, $gateway_mgr, $action)
    {
        parent::__construct($ns, '/gateways/');
        $this->_gateway_mgr = $gateway_mgr;
        $this->_store = $store;
        add_action( 'wc_ajax_nopriv_gateways_' . $action, array( $this, 'handle_upsell' ) );
        add_action( 'wc_ajax_gateways_' . $action, array( $this, 'handle_upsell' ) );
    }

    public function register_routes() {
    }

    protected function handle_successful_charge($integration, $offer, $order, $session_id, $transaction_id) {
        $order = $integration->write_success_order($offer,
            $order,
            $session_id,
            $transaction_id, 
            false);
        return wp_send_json( array(
            'success' => true,
            'value' => $order->get_checkout_order_received_url()
        ), 201 );
    }

    protected function pay_for_upsell($get_integration, $order, $session_id, $offer, $product, $pricing) {

    }

    public function write_error_from_request(
            $request,
            $selected_offer, 
            $current_order,
            $integration, 
            $session_id, 
            $error_type) {

        $get_error_str = $request->error_reason;

        $integration->write_payment_failed_order($selected_offer,
            $current_order,
            $session_id,
            null,
            $get_error_str,
            $redirect=false);

        return wp_send_json( array(
            'success' => false,
            'value' => $error_type
        ), 400 );
    }

    public function handle_upsell() {
        // $this->ensure_session();
        try {
            check_ajax_referer( 'infcrwds_front', 'nonce' );
            $current_order = $this->_store->get_base_order();
            if(!$current_order) {
                return wp_send_json( array(
                    'success' => false,
                    'value' => 'order_not_found'
                ), 400 );
            }
            $session_id = $this->_store->get_current_ic_session_id();
            $get_payment_gateway = \INFCWDS_WC_Compatibility::get_payment_gateway_from_order( $current_order );
            $get_integration = $this->_gateway_mgr->get_integration( $get_payment_gateway );
            if(!($get_integration instanceof GatewayIntegrationBase)) {
                return wp_send_json( array(
                    'success' => false,
                    'value' => 'gateway_not_supported'
                ), 400 );
            }
            $selected_offer = $this->_store->get_suggested_offer($_POST['data']['token']);
            if(empty($selected_offer)) {
                return wp_send_json( array(
                    'success' => false,
                    'value' => 'offer_not_found'
                ), 400 );
            }
            if(empty($session_id)) {
                return wp_send_json( array(
                    'success' => false,
                    'value' => 'session_expired'
                ), 400 );
            }
            // validate the offer against infcrwds server
            $ic_request = new ICRequest($this->_store);
            $response = $ic_request->get('/widget/offers/'. $session_id . '/' . $selected_offer['offer_order_id'] . '/'. $selected_offer['offer']['offer_id']);
            if(!$response) {
                return wp_send_json( array(
                    'success' => false,
                    'value' => 'offer_not_available'
                ), 400 );
            }
            $this->_store->set_selected_offer($selected_offer);
            $offer_pricing = $selected_offer['pricing'];

            $product_id = $selected_offer['product_id'];
            $product = wc_get_product($product_id);

            return $this->pay_for_upsell($get_integration,
                $current_order,
                $session_id,
                $selected_offer,
                $product,
                $offer_pricing);
        } catch(Exception $e) {
			InfcrwdsPlugin()->logger->error('handle upsell failed:'. $e->getMessage());
            return wp_send_json( array(
                'success' => false,
                'value' => $e->getMessage()
            ), 400 );
        }
    }
}