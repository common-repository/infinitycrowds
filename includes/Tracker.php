<?php
/**
 * InfCrowds
 *
 *
 * @package   InfCrowds
 * @author    InfCrowds
 * @license   GPL-3.0
 * @link      https://goInfCrowds.com
 * @copyright 2017 InfCrowds (Pty) Ltd
 */
namespace InfCrowds\WPR;
use INFCWDS_Plugin_Compatibilities;
use INFCWDS_WC_Compatibility;



/**
 * @subpackage PublicManager
 */
class Tracker {

    public function __construct($gateway_mgr, $store) {
        $this->_store = $store;
        add_action ('woocommerce_add_to_cart', array($this, 'tracker_on_add_to_cart'), 10, 6);
        add_action ( 'woocommerce_remove_cart_item', array($this, 'tracker_on_remove_to_cart'), 10, 2 );
        add_action ( 'woocommerce_after_cart_item_quantity_update', array($this, 'tracker_on_quantity_update'), 10, 2 );
        add_action ( 'woocommerce_before_cart_item_quantity_zero', array($this, 'tracker_on_remove_to_cart'), 10, 2 );
    }

    public function tracker_on_quantity_update($cart_item_key, $quantity) {
        $request = new ICRequest($this->_store);
        $data = array(
            'sid' => $this->_store->get_current_ic_session_id(),
            'ip_addr' => Commons::GetIP(),
            'user_agent' => wc_get_user_agent(),
            'ev' => array(
                'etp' => 2048,
                'ts' => time(),
                'kvps' => array(
                    array(
                        'k' => 'h',
                        'v' => $cart_item_key
                    ),
                    array(
                        'k' => 'qty',
                        'v' => $quantity
                    )

                )
            )
        );
        $res = $request->post('/track/server-event', $data);
        if($res === false) {
            return;
        }
    }

    public function tracker_on_remove_to_cart( $cart_item_key, $cart ) {

        $request = new ICRequest($this->_store);
        $data = array(
            'sid' => $this->_store->get_current_ic_session_id(),
            'ip_addr' => Commons::GetIP(),
            'user_agent' => wc_get_user_agent(),
            'ev' => array(
                'etp' => 128,
                'ts' => time(),
                'kvps' => array(
                    array(
                        'k' => 'h',
                        'v' => $cart_item_key
                    )
                )
            )
        );
        $res = $request->post('/track/server-event', $data);
        if($res === false) {
            return;
        }
    }

    public function tracker_on_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        $request = new ICRequest($this->_store);
        $data = array(
            'sid' => $this->_store->get_current_ic_session_id(),
            'ip_addr' => Commons::GetIP(),
            'user_agent' => wc_get_user_agent(),
            'ev' => array(
                'etp' => 64,
                'ts' => time(),
                'kvps' => array(
                    array(
                        'k' => 'pid',
                        'v' => $product_id
                    ),
                    array(
                        'k' => 'qty',
                        'v' => $quantity
                    ),
                    array(
                        'k' => 'h',
                        'v' => $cart_item_key
                    ),
                    array(
                        'k' => 'vid',
                        'v' => $variation_id
                    ),
                    array(
                        'k' => 'cats',
                        'v' => implode(',', wp_get_object_terms($product_id, 'product_cat', array('fields' => 'ids')))
                    )
                )
            )
        );
        $res = $request->post('/track/server-event', $data);
        if($res === false) {
            return;
        }
    }

}