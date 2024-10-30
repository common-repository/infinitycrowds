<?php
/**
 * InfinityCrowds Store
 *
 *
 * @package   InfinityCrowds
 * @author    InfinityCrowds
 * @license   GPL-3.0
 * @link      https://infinitycrowds.com
 * @copyright 2019 InfinityCrowds (Pty) Ltd
 */

namespace InfCrowds\WPR;

/**
 * @subpackage Admin
 */
class Store {
    
    public function __construct() {
        $this->options = null;
        // add_action( 'init', array( $this, 'setup_options' ), 26 );
    }

    public function setup_options() {
		if ( ! $this->options ) {
			$this->options   = get_option( 'infcrwds_global_settings' );
			// $this->options = wp_parse_args( $options, $this->get_options_defaults( $options ) );

			$this->options = apply_filters( 'infcrwds_global_settings', $this->options );
		}
    }
    private function _get(&$var, $default) {
        return isset($var) ? $var : $default;
    }

    public function get($key, $default=null) {
        $this->setup_options();
        return $this->_get($this->options[$key], $default);
    }

    public function is_logged_in() {
        $api_key = $this->get('ic_api_key', false);
        $secret_key = $this->get('ic_secret', false);
        $ic_merchant_id = $this->get('ic_merchant_id', false);
        if(!$api_key || !$secret_key || !$ic_merchant_id) {
            return false;
        }
        return true;
    }

    public function is_internal_upsell_enabled() {
        return $this->get('is_internal_upsell_enabled', true);
    }

    public function refresh() {
        $this->options = null;
    }
    
    public function set($key, $value) {
        $this->setup_options();
        $this->options[$key] = $value;
        $new_opts = array();
        foreach($this->options as $key=>$value) {
            if (strpos($key, '__cache__') !== 0) {
                $new_opts[$key] = $value;
             }
        }
        return update_option( 'infcrwds_global_settings', $new_opts);
        $this->options = $new_opts;
    }

    public function set_impression_id($offer_id, $imp_id) {
        WC()->session->set("imps_" . $offer_id, $imp_id);
    }

    public function get_impression_id($offer_id) {
        return WC()->session->get("imps_" . $offer_id);
    }

    public function is_in_session() {
        return WC()->session->get('ic_session_id', 0) !== 0;
    }

    public function begin_offers_session($ic_session_id, $order_id=null, $force_override_base_order=false) {
        WC()->session->set( 'ic_session_id', $ic_session_id);;
        if($order_id !== null) {
            $base_order_id = $this->get_base_order_id();
            if($force_override_base_order || $base_order_id == 0) {
                WC()->session->set('ic_base_order_id', $order_id);
            }
        }
    }

    public function set_selected_offer($offer) {
        WC()->session->set( 'ic_selected_offer', $offer);;
    }

    public function set_suggested_offer($offer_token, $offer){
        WC()->session->set( 'ic_suggested_offer' . $offer_token, $offer);;
    }

    public function get_suggested_offer($offer_token) {
        return WC()->session->get( 'ic_suggested_offer' . $offer_token);
    }

    public function get_selected_offer() {
        return WC()->session->get( 'ic_selected_offer');
    }
    
    public function get_current_ic_session_id() {
        $sid = WC()->session->get('ic_session_id', null);
        if($sid !== null && !is_string($sid)) {
            return null;
        }
        return $sid;
    }

    public function session_get($key) {
        return WC()->session->get($key);
    }
    public function session_set($key, $value) {
        return WC()->session->set($key, $value);
    }
    
    public function clear_upsell_context() {
        WC()->session->__unset( 'ic_base_order_id' );
        WC()->session->__unset( 'ic_selected_offer');
    }

    public function end_offers_session() {
        WC()->session->__unset( 'ic_session_id' );
        WC()->session->__unset( 'ic_base_order_id' );

    }

    public function get_base_order_id() {
        return WC()->session->get('ic_base_order_id', 0);
    }

    public function get_base_order() {
        $order_id = WC()->session->get('ic_base_order_id', 0);
        if($order_id === 0) {
            return null;
        }
        return wc_get_order( $order_id );
    }
}