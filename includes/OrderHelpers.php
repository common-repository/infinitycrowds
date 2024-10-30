<?php
/**
 * Order Helpers
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

class OrderHelpers {

    public static function get_order_number( $order, $session_id=null) {
		if ( ! empty( $session_id ) ) {
			return INFCWDS_WC_Compatibility::get_order_id( $order ) . '_' . $session_id;
		} else {
			return INFCWDS_WC_Compatibility::get_order_id( $order );
		}

    }
    
	/**
	 * Controller of WC_Order::payment_complete() & reduction of stock for non completed gateways
	 * We need to restrict payment_complete function to run for the not supported gateways
	 *
	 * @param $transaction_id
	 * @param WC_Order $order
	 */
	public static function payment_complete( $transaction_id, $order ) {
        $gatways_do_not_support_payment_complete = array( 'bacs', 'cheque', 'cod' );

		if ( false == in_array( $order->get_payment_method(), $gatways_do_not_support_payment_complete ) ) {
			$order->payment_complete( $transaction_id );
		} elseif ( 'cod' === $order->get_payment_method() ) {
			$order->set_status( 'processing' );
			wc_reduce_stock_levels( $order );

		} elseif ( 'bacs' === $order->get_payment_method() || 'cheque' === $order->get_payment_method() ) {
			$order->set_status( 'on-hold' );
			wc_reduce_stock_levels( $order );
		}
	}

    /**
     * Controller of stock reduction after an order
     *
     * @param WC_Order $order
     */
    public static function reduce_stock($order, $item_added, $product) {

		$stock_reduced = $order->get_data_store()->get_stock_reduced( $order->get_id() );
		if ( true === $stock_reduced && 'yes' === get_option( 'woocommerce_manage_stock' ) ) {

            if ( $product->managing_stock() ) {
                $qty       = apply_filters( 'woocommerce_order_item_quantity', $item_added->get_quantity(), $order, $item_added );
                $item_name = $product->get_formatted_name();
                $new_stock = wc_update_product_stock( $product, $qty, 'decrease' );

                if ( ! is_wp_error( $new_stock ) ) {
                    /* translators: 1: item name 2: old stock quantity 3: new stock quantity */
                    $order->add_order_note( sprintf( __( '%1$s stock reduced from %2$s to %3$s.', 'woocommerce' ), $item_name, $new_stock + $qty, $new_stock ) );

                    // Get the latest product data.
                    $product = wc_get_product( $product->get_id() );

                    if ( '' !== get_option( 'woocommerce_notify_no_stock_amount' ) && $new_stock <= get_option( 'woocommerce_notify_no_stock_amount' ) ) {
                        do_action( 'woocommerce_no_stock', $product );
                    } elseif ( '' !== get_option( 'woocommerce_notify_low_stock_amount' ) && $new_stock <= get_option( 'woocommerce_notify_low_stock_amount' ) ) {
                        do_action( 'woocommerce_low_stock', $product );
                    }

                    if ( $new_stock < 0 ) {
                        do_action( 'woocommerce_product_on_backorder', array(
                            'product'  => $product,
                            'order_id' => INFCWDS_WC_Compatibility::get_order_id( $order ),
                            'quantity' => $qty,
                        ) );
                    }
                }
            }
        }
    }

    public static function get_order_invoice_num($prefix, $order) {
        return $prefix . OrderHelpers::str_to_ascii( ltrim( $order->get_order_number(), 
        _x( '#', 'hash before the order number. Used as a character to remove from the actual order number', 'woocommerce-subscriptions' ) ) );
    }
    /**
	 * Returns a string with all non-ASCII characters removed. This is useful for any string functions that expect only
	 * ASCII chars and can't safely handle UTF-8
	 *
	 * Based on the SV_WC_Helper::str_to_ascii() method developed by the masterful SkyVerge team
	 *
	 * Note: We must do a strict false check on the iconv() output due to a bug in PHP/glibc {@link https://bugs.php.net/bug.php?id=63450}
	 *
	 * @param string $string string to make ASCII
	 *
	 * @return string|null ASCII string or null if error occurred
	 */
	public static function str_to_ascii( $string ) {

		$ascii = false;

		if ( function_exists( 'iconv' ) ) {
			$ascii = iconv( 'UTF-8', 'ASCII//IGNORE', $string );
		}

		return false === $ascii ? preg_replace( '/[^a-zA-Z0-9_\-]/', '', $string ) : $ascii;
	}

}