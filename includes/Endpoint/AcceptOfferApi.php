<?php
/**
 * Upsell API
 *
 *
 * @package   InfinityCrowds
 * @author    InfinityCrowds
 * @license   GPL-3.0
 * @link      https://infinitycrowds.com
 * @copyright 2019 InfinityCrowds (Pty) Ltd
 */
namespace InfCrowds\WPR\Endpoint;

use \InfCrowds\WPR\OfferConverter;
use \InfCrowds\WPR\OfferNotFoundException;
use \InfCrowds\WPR\OfferAlreadyAcceptedException;
use \InfCrowds\WPR\ICRequest;


class AcceptOfferApi extends BaseApi
{
    function __construct($ns, $store, $offer_cart_mgr)
    {
        parent::__construct($ns, '/offer-accept/');
        $this->_store = $store;
        $this->_offer_cart_mgr = $offer_cart_mgr;
        add_action( 'wc_ajax_nopriv_offer_accept', array( $this, 'offer_accept' ) );
        add_action( 'wc_ajax_offer_accept', array( $this, 'offer_accept' ) );
    }

    public function woocommerce_permission_check($req) {
        return true;
    }
    
    public function register_routes()
    {
    }

    public function offer_accept() {
        try {
            // $this->ensure_session();
            check_ajax_referer( 'infcrwds_front', 'nonce' );
            $selected_offer = $this->_store->get_suggested_offer(sanitize_text_field( $_POST['data']['token'] ));
            if(empty($selected_offer)) {
                return wp_send_json( array(
                    'success' => false,
                    'value' => 'offer_not_found'
                ), 400 );
            }
            $session_id = $this->_store->get_current_ic_session_id();
            // validate the offer against infcrwds server
            $ic_request = new ICRequest($this->_store);
            $response = $ic_request->get('/widget/offers/'. $session_id . '/'. $selected_offer['offer_order_id'] . '/' . $selected_offer['offer']['offer_id']);
            if(!$response) {
                throw new OfferNotFoundException('offer_not_found');
            }
            $this->_store->set_selected_offer($selected_offer);

            $this->_offer_cart_mgr->add_selected_offer_to_cart($selected_offer, $session_id);
            
            return wp_send_json(array(
                'success' => true,
                'value' => array('checkout_url' => wc_get_checkout_url()),
            ), 200);
            
        } catch(OfferNotFoundException $e) {
            InfcrwdsPlugin()->logger->error("accept offer: not found" . $e->getMessage());
            return wp_send_json( array(
                'success' => false,
                'value' => $e->getMessage()
            ), 400 );
        } catch(OfferAlreadyAcceptedException $e) {
            InfcrwdsPlugin()->logger->error("accept offer: already accepted" . $e->getMessage());
            return wp_send_json( array(
                'success' => false,
                'value' => $e->getMessage()
            ), 400 );
        } catch(Exception $e) {
            InfcrwdsPlugin()->logger->error("accept offer: error" . $e->getMessage());
            return wp_send_json( array(
                'success' => false,
                'value' => $e->getMessage()
            ), 500 );
        }
    }
}
