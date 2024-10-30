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

class NewOrderMaker implements IOrderMaker {

    public function __construct($store, $offer, $order_to_inherit) {
        $this->_offer = $offer;
        $this->_store = $store;
        $this->_order_to_inherit = $order_to_inherit;

    }

    function get_custom_price( $return_price, $qty, $product ) {
        if($product->get_id() == $this->_offer['product_id']) {
            return $this->_offer['pricing']['sale_price'];
        }
    }
    /**
	 *
	 * @param array $package
	 * @param WC_Order $parent
	 * @param WC_Order $new
	 */
	public function maybe_handle_shipping_new_order( $parent, $new ) {
		$pricing = $this->_offer['pricing'];
		$shipping_price = 0;
		if ( $pricing['has_shipping'] ) {

			$get_shipping_items = $parent->get_items( 'shipping' );

			if ( $get_shipping_items && is_array( $get_shipping_items ) ) {
                $shipping_price = $pricing['shipping_price'];
				if ( $shipping_price > 0 ) {

					$item = new \WC_Order_Item_Shipping();
					$item->set_props( array(
						'method_title' => $pricing['shipping_label'],
						'method_id'    => $pricing['shipping_type'],
						'total'        => $shipping_price,
					) );
					$item->save();
					$new->add_item( $item );
					wc_add_order_item_meta( $item->get_id(), '_infcrwds_offer_id', $this->_offer['offer']['offer_id'] );
					wc_add_order_item_meta( $item->get_id(), '_infcrwds_shipping_price', $shipping_price);
				} else {
					/**
					 * If we are in this case that means user opted for the free shipping option provided by us.
					 * We have to apply free shipping method to the current order and remove the previous one.
					 */

					$item = new \WC_Order_Item_Shipping();
					$item->set_props( array(
						'method_title' => $pricing['shipping_label'],
						'method_id'    => $pricing['shipping_type'],
						'total'        => 0,
					) );
					$item->save();
					$new->add_item( $item );

				}

				/**
				 * @todo handle for local-pickup case for  out of shop base address users.
				 * In case of local pickup shipping, taxes were calculated based on shop base address but not users shipping on front end.
				 * But as soon as we run WC_Order::calculate_totals(), WC does not consider local pickup and apply taxes based on customer.
				 * This ends up messing prices in the order.
				 */
				$new->calculate_totals();

                $new->save();
			} else {

				/**
				 * When there is no shipping exists for the parent order we have to add a new method
				 */
				/**
				 * @todo handle this case as we have to allow customer to chosen between the offered shipping methods
				 */

			}
		}
		return $shipping_price;
	}
    /**
	 * Create a new order in woocommerce
	 *
	 * @param $args
	 * @param WC_Order $order_to_inherit
	 *
	 * @return bool|WC_Order|WP_Error
	 */
	private function create_order( $order_to_inherit, $status, $session_id, $transaction_id, $payment_completed ) {
		/**
		 * @todo make the functions compatible to  WC < 3.0
		 */
		if ( ! empty( $order_to_inherit ) ) {
			$parent_order_billing = $order_to_inherit->get_address( 'billing' );

			if ( ! empty( $parent_order_billing['email'] ) ) {
				$customer_id = $order_to_inherit->get_customer_id();

				$order = wc_create_order( array(
					'customer_id' => $customer_id,
					'status'      => $status,
				) );
                $args  = apply_filters( 'infcrwds_add_products_to_the_order', $order );
                
                add_filter( 'woocommerce_get_price_excluding_tax', array($this, 'get_custom_price'), 10, 3);				
                $product = wc_get_product($this->_offer['selected_variant_id'] === null ? $this->_offer['product_id']: $this->_offer['selected_variant_id']);
                $item_id = $order->add_product( $product, $this->_offer['pricing']['quantity']);
                remove_filter('woocommerce_get_price_excluding_tax', array($this, 'get_custom_price'));
                
                wc_add_order_item_meta( $item_id, '_infcrwds_session_id', $session_id );
				wc_add_order_item_meta( $item_id, '_infcrwds_offer_id', $this->_offer['offer']['offer_id'] );
                
                
				$order->set_address( $order_to_inherit->get_address( 'billing' ), 'billing' );
				$order->set_address( $order_to_inherit->get_address( 'shipping' ), 'shipping' );

				// set shipping

				$order->set_payment_method( $order_to_inherit->get_payment_method() );
				$order->set_payment_method_title( $order_to_inherit->get_payment_method_title() );

				// reports won't track orders if these values are not set
				if ( ! wc_tax_enabled() ) {
					$order->set_shipping_tax( 0 );
					$order->set_cart_tax( 0 );
				}

				/**
				 * Copying the meta provided by the user from primary order to the new one
				 */
				$meta_keys_to_copy = $this->_store->get( 'order_copy_meta_keys' , '');

				$explode_meta_keys = apply_filters( 'infcrwds_order_copy_meta_keys', explode( '|', $meta_keys_to_copy ) );
				if ( is_array( $explode_meta_keys ) ) {
					foreach ( $explode_meta_keys as $key ) {
						$get_meta = get_post_meta( INFCWDS_WC_Compatibility::get_order_id( $order_to_inherit ), $key, true );
						update_post_meta( INFCWDS_WC_Compatibility::get_order_id( $order ), $key, $get_meta );
					}
                }
				$order->calculate_totals();
                $infcrwds_rev = $this->_offer['pricing']['sale_price'];
                $infcrwds_rev = $this->maybe_handle_shipping_new_order($order_to_inherit, $order);

				$order->add_meta_data( '_infcrwds_sibling_order', $order_to_inherit, false );
				$order->add_meta_data( '_infcrwds_revenue', $infcrwds_rev);
				$order->add_meta_data( '_infcrwds_internal_upsell', 'yes' );
				$order->add_meta_data( '_infcrwds', '1' );
                $order->save_meta_data();
                
                $order->save();
                $item = $this->_order_to_inherit->get_item($item_id, false);
                OrderHelpers::reduce_stock($order, $item, $product);
                if($payment_completed) {
                    OrderHelpers::payment_complete($transaction_id, $order);
                }
                return $order;
			}

		}
		return false;
    }
    
    public function makeSuccessOrder($session_id, $transaction_id) {
        $res = $this->create_order($this->_order_to_inherit, 'wp-pending', $session_id, $transaction_id, true);
        $transaction_id_note = '';
        if ( ! empty( $get_transaction_id ) ) {
			$transaction_id_note = sprintf( ' (Transaction ID: %s)', $transaction_id );

        }
		$res->add_order_note( sprintf( 'A New Order Created | Funnel ID %s  | Offer ID %s %s', $session_id, $this->_offer['offer']['offer_id'], $transaction_id_note ) );
		do_action('infcrwds_upsell_success_order_created', $res);
        return $res;
    }

    public function makeCanceledOrder($session_id) {
        return $this->create_order($this->_order_to_inherit, 'wc-canceled', $session_id, null, false);
    }

    public function makePaymentFailedOrder($session_id, $transaction_id, $error_reason) {
        $result_order = $this->create_order($this->_order_to_inherit, 'wc-failed', $session_id, null, false);
        $result_order->add_order_note( sprintf( __( 'Offer payment failed. Reason: %s', 'infcrwds-one-click-upsell' ), $error_reason ) );
        return $result_order;
    }
}