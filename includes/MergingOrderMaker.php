<?php
/**
 * Merging Order Maker
 *
 *
 * @package   InfinityCrowds
 * @author    InfinityCrowds
 * @license   GPL-3.0
 * @link      https://infinitycrowds.com
 * @copyright 2019 InfinityCrowds (Pty) Ltd
 */

namespace InfCrowds\WPR;
use INFCWDS_WC_Compatibility;

class MergingOrderMaker implements IOrderMaker {

    public function __construct($parent_order, $offer) {
        $this->_parent_order = $parent_order;
        $this->_offer = $offer;

    }
    // function custom_shipping_costs( $rates, $package ) {
    //     // New shipping cost (can be calculated)
    //     foreach( $rates as $rate_key => $rate ){
    //         // Excluding free shipping methods
    //         if( $rate->method_id != 'free_shipping'){
    
    //             // Set rate cost
    //             $rates[$rate_key]->cost = $this->_offer['pricing']['shipping_price'];
    
    //             // Set taxes rate cost (if enabled)
    //             $taxes = array();
    //             foreach ($rates[$rate_key]->taxes as $key => $tax){
    //                 if( $rates[$rate_key]->taxes[$key] > 0 )
    //                     $taxes[$key] = $new_cost * $tax_rate;
    //             }
    //             $rates[$rate_key]->taxes = $taxes;
    
    //         }
    //     }
    //     return $rates;
    // }

    public function maybe_handle_shipping(  ) {
        $order = $this->_parent_order;
        $get_shipping_items = $order->get_items( 'shipping' );
        $shipping_price = 0;
        if ( $get_shipping_items && is_array( $get_shipping_items ) ) {
            $shipping_price = $this->_offer['pricing']['shipping_price'];
            $shipping_type = $this->_offer['pricing']['shipping_type'];
            $shipping_label = $this->_offer['pricing']['shipping_label'];
            $override_shipping = isset( $this->_offer['pricing']['shipping_override'] ) && true === $this->_offer['pricing']['shipping_override'];
            
            
            $item_shipping     = current( $get_shipping_items );
            $item_shipping_key = key( $get_shipping_items );
            if ( false === strpos( $shipping_type, 'free_shipping' ) || 'fixed' == $shipping_type ) {

                if ( $override_shipping ) {
                    $item = new \WC_Order_Item_Shipping();
                    $item->set_props( array(
                        'method_title' => $shipping_label,
                        'method_id'    => $shipping_type,
                        'total'        => $shipping_price,
                    ) );
                    $item->save();
                    $order->add_item( $item );

                    $offer_shipping_items = array();
                    
                    $offer_shipping_items[] = get_the_title( $this->_offer['product_id'] );

                    $item_id     = $item->get_id();
                    $offer_itmes = implode( ',', $offer_shipping_items );

                    wc_add_order_item_meta( $item_id, 'Items', $offer_itmes );
                    wc_add_order_item_meta( $item_id, '_infcrwds_offer_id', $this->_offer['offer']['offer_id'] );
                    wc_add_order_item_meta( $item_id, '_infcrwds_shipping_price', $shipping_price);


                } else {
                    $item_id = $item_shipping->get_id();
                    wc_add_order_item_meta( $item_id, '_infcrwds_offer_id', $this->_offer['offer']['offer_id'] );
                    wc_add_order_item_meta( $item_id, '_infcrwds_shipping_price', $shipping_price);
                    $item_shipping->set_total( $item_shipping->get_total() + $shipping_price );
                 }
            } else {

                /**
                 * If we are in this case that means user opted for the free shipping option provided by us.
                 * We have to apply free shipping method to the current order and remove the previous one.
                 */
                $order->remove_item( $item_shipping_key );
                $item = new \WC_Order_Item_Shipping();
                $item->set_props( array(
                    'method_title' => $shipping_label,
                    'method_id'    => $shipping_type,
                    'total'        => 0,
                ) );
                $item->save();
                $order->add_item( $item );

            }

            /**
             * @todo handle for local-pickup case for out of shop base address users.
             * In case of local pickup shipping, taxes were calculated based on shop base address but not users shipping on front end.
             * But as soon as we run WC_Order::calculate_totals(), WC does not consider local pickup and apply taxes based on customer.
             * This ends up messing prices in the order.
             */
            $order->calculate_totals();

        } else {

            /**
             * When there is no shipping exists for the parent order we have to add a new method
             */
            /**
             * @todo handle this case as we have to allow customer to chosen between the offered shipping methods
             */

        }
        return $shipping_price;
	}

    function get_custom_price( $return_price, $qty, $product ) {
        if($product->get_id() == $this->_offer['product_id']) {
            return $this->_offer['pricing']['sale_price'];
        }
    }

    public function makeOrder($session_id, $transaction_id) {
        add_filter( 'woocommerce_get_price_excluding_tax', array($this, 'get_custom_price'), 10, 3);
        $product = wc_get_product($this->_offer['selected_variant_id'] === null ? $this->_offer['product_id']: $this->_offer['selected_variant_id']);
        $item_id = $this->_parent_order->add_product($product, $this->_offer['pricing']['quantity']);
        remove_filter('woocommerce_get_price_excluding_tax', array($this, 'get_custom_price'));
        $item = $this->_parent_order->get_item($item_id, false);
        
        wc_add_order_item_meta( $item_id, '_infcrwds_session_id', $session_id );
        wc_add_order_item_meta( $item_id, '_infcrwds_offer_id', $this->_offer['offer']['offer_id'] );

        $this->_parent_order->calculate_totals();
        $infcrwds_total = INFCWDS_WC_Compatibility::get_order_meta($this->_parent_order, '_infcrwds_revenue');
        if(empty($infcrwds_total)) {
            $infcrwds_total = 0;
        }
        $infcrwds_total = $this->_offer['pricing']['sale_price'];
        $infcrwds_total += $this->maybe_handle_shipping();

        $this->_parent_order->add_meta_data( '_infcrwds_internal_upsell', 'yes' );
        $this->_parent_order->add_meta_data( '_infcrwds_revenue', $infcrwds_total);
        $this->_parent_order->add_meta_data( '_infcrwds', '1' );
        $this->_parent_order->save_meta_data();
        $this->_parent_order->save();
        
        OrderHelpers::reduce_stock($this->_parent_order, $item, $product);

        $transaction_id_note = '';
        if ( ! empty( $get_transaction_id ) ) {
			$transaction_id_note = sprintf( ' (Transaction ID: %s)', $transaction_id );

        }
        $this->_parent_order->add_order_note( sprintf( 'Upsell Offer Accepted | Funnel ID %s  | Offer ID %s %s', $session_id, $this->_offer['offer']['offer_id'], $transaction_id_note ) );
        do_action('infcrwds_upsell_success_order_updated', $this->_parent_order);
        return $this->_parent_order;
    }

    public function makeSuccessOrder($session_id, $transaction_id) {
        return $this->makeOrder($session_id, $transaction_id);
    }

    public function makeCanceledOrder($session_id) {
        throw new Exception('not supported');
    }

    public function makePaymentFailedOrder($session_id, $transaction_id, $error) {
        throw new Exception('not supported');
    }
}
