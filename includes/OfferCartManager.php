<?php
/**
 * WP-Reactivate
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
use INFCWDS_Plugin_Compatibilities;

class OfferNotFoundException extends \Exception {
}

class OfferAlreadyAcceptedException extends \Exception {
}


class OfferCartManager {
    public function __construct($store) {
        $this->_store = $store;
        add_action( 'woocommerce_before_calculate_totals', array($this, 'set_custom_cart_item_price'), 1000, 1);
        add_filter( 'woocommerce_package_rates', array($this, 'filter_woocommerce_package_rates'), 1000, 2 ); 
        add_filter( 'woocommerce_after_cart_item_quantity_update', array($this, 'ensure_product_quantity'), 1000, 4);
        add_action( 'woocommerce_checkout_update_order_meta', array($this, 'maybe_tag_order_for_infcrwds'), 1000, 2);
        // add_filter( 'woocommerce_coupons_enabled', array($this, 'hide_coupon_field_if_infcrwds_cart'), 1000, 1 );
        add_filter ( 'woocommerce_shipping_chosen_method', array($this, 'override_chosen_shipping_method'), 999, 3);
        add_filter( 'woocommerce_cart_needs_shipping', array($this, 'override_needs_shipping'), 999, 1);
        add_action('woocommerce_before_checkout_form', array($this, 'validate_ic_offers'));
        add_action( 'woocommerce_after_checkout_validation', array($this, 'validate_ic_offers_on_checkout_submit'), 10, 2);

        // add_filter( 'woocommerce_shipping_chosen_method' array()
    }

    public function validate_ic_offers_on_checkout_submit($fields, $errors) {
        $cart = WC()->cart;
        foreach($cart->get_cart() as $cart_item) {
            if(isset($cart_item['ic_offer_id'])) {
                $expiry = $cart_item['ic_expiry'];
                if(time() > $expiry) {
                    $_product =  wc_get_product( $cart_item['data']->get_id()); 
                    $errors->add( 'validation', 'Offer for product' . $_product->get_title() . ' has expired' );
                    return;
                }
            }
        }
    }

    public function validate_ic_offers($wccm_autocreate_account) {
        $cart = WC()->cart;
        $keys_to_remove = null;
        foreach($cart->get_cart() as $cart_item) {
            if(isset($cart_item['ic_offer_id'])) {
                $expiry = $cart_item['ic_expiry'];
                if(time() > $expiry) {
                    if($keys_to_remove === null) {
                        $keys_to_remove = array();
                    }
                    $keys_to_remove[] = $cart_item['key'];
                }
            }
        }
        if($keys_to_remove !== null) {
            foreach($keys_to_remove as $k) {
                WC()->cart->remove_cart_item($k);
            }
        }
    }

    public function override_needs_shipping($needs_shipping) {
        $cart = WC()->cart;
        if($cart === null) {
            return $needs_shipping;
        }
        if(!$needs_shipping) {
            return false;
        }
        foreach($cart->get_cart() as $cart_item) {
            if(isset($cart_item['ic_offer_id'])) {
                $pricing = $cart_item['ic_pricing'];
                $has_shipping = $pricing['has_shipping'];
                if(!$has_shipping) {
                    continue;
                }
                $shipping_price = $pricing['bc_shipping_price'];
                $has_shipping = $shipping_price > 0;
                if($has_shipping) {
                    return true;
                }
             } else {
                return true;
            }
        }
        return false;
    }

    public function override_chosen_shipping_method($default, $rates, $chosen_method) {
		$cart = WC()->cart;
		if($cart !== null) {
           foreach($cart->get_cart() as $cart_item) {
               if(isset($cart_item['ic_offer_id'])) {
					$rate_keys = array_keys( $rates );
					$default   = current( $rate_keys );
                    break;
                }
            }
        }
		return $default;
    }
    
    // hide coupon field on cart page
    public function hide_coupon_field_if_infcrwds_cart( $enabled ) {
        $cart = WC()->cart;
        if($cart !== null) {
            foreach($cart->get_cart() as $cart_item) {
                if(isset($cart_item['ic_offer_id'])) {
                    return false;
                }
            }
        }
        return $enabled;
    }
    
    public function filter_woocommerce_package_rates( $package_rates, $package ) { 
        if(sizeof($package['contents']) !== 1) {
            return $package_rates;
        }

        foreach($package['contents'] as $key=>$item) {
            if(isset($item['ic_offer_id']) && isset($item['ic_pricing'])) {
                
                $is_intenal_upsell = $item['ic_is_internal_upsell'];
                if(!$is_intenal_upsell) {
                    return $package_rates;
                }
                $pricing = $item['ic_pricing'];
                $has_shipping = $pricing['has_shipping'];
                if(!$has_shipping) {
                    return $package_rates;
                }
                $shipping_price = $pricing['bc_shipping_price'];
                $shipping_tax = $pricing['bc_shipping_tax'];
                $added_shipping = false;
                
                $set_shipping = false;
                $new_package_rates = array();
                foreach($package_rates as $key=>$method) {
                    if($method->method_id == 'free_shipping' && $shipping_price == 0) {
                        $new_package_rates[$key] = $method;
                    }
                    else if( $shipping_price > 0 && strpos($key, 'flat_rate') !== FALSE ) {
                        $total = $shipping_price;
                        if(wc_prices_include_tax()) {
                            $total += $shipping_tax;
                        }

                        $total = INFCWDS_Plugin_Compatibilities::get_fixed_currency_price($total);
                        $method->set_cost($total);
                        
                        $method->add_meta_data('_infcrwds_offer_id', $item['ic_offer_id']);
                        $method->add_meta_data('_infcrwds_shipping_price', $total);
                        $shipping_price_taxes = Commons::get_price_taxes($total, wc_prices_include_tax());
                        $method->set_taxes($shipping_price_taxes);                    
                        $new_package_rates[$key] = $method;
                    }
                }
                if(sizeof($new_package_rates) == 0) {
                    foreach ( WC()->shipping()->get_shipping_methods() as $method_id => $method ) {
                        // get_method_title() added in WC 2.6
                        if($method_id === 'free_shipping' && $shipping_price == 0) {
                            $rate = new \WC_Shipping_Rate( $method_id,
                                is_callable( array( $method, 'get_method_title' ) ) ? $method->get_method_title() : $method->get_title(),
                                0,
                                array(),
                                $method_id);
                            $new_package_rates['free'] = $rate;
                        }
                        else if($method_id == 'flat_rate' && $shipping_price > 0) {
                            $rate = new \WC_Shipping_Rate( $method_id,
                                is_callable( array( $method, 'get_method_title' ) ) ? $method->get_method_title() : $method->get_title(),
                                0,
                                array(),
                                $method_id);
                            
                            $total = $shipping_price;
                            if(wc_prices_include_tax()) {
                                $total += $shipping_tax;
                            }
                            $total = INFCWDS_Plugin_Compatibilities::get_fixed_currency_price($total);
                            $rate->set_cost($total);
                        
                            $rate->add_meta_data('_infcrwds_offer_id', $item['ic_offer_id']);
                            $rate->add_meta_data('_infcrwds_shipping_price', $total);
                            $shipping_price_taxes = Commons::get_price_taxes($total, wc_prices_include_tax());
                            $rate->set_taxes($shipping_price_taxes);
                            $new_package_rates['flat_rate:1'] = $rate;
                        }
                    }
                    if(sizeof($new_package_rates) == 0) {
                        if($shipping_price == 0) {
                            $rate = new \WC_Shipping_Rate( '',
                                'Free Shipping',
                                0,
                                array(),
                                '');
                            $new_package_rates['free'] = $rate;
                        } else {
                            $rate = new \WC_Shipping_Rate( '',
                            'Flat Shipping',
                            0,
                            array(),
                            '');

                            $total = $shipping_price;
                            if(wc_prices_include_tax()) {
                                $total += $shipping_tax;
                            }
                            $total = INFCWDS_Plugin_Compatibilities::get_fixed_currency_price($total);
                            $rate->set_cost($total);
                        
                            $rate->add_meta_data('_infcrwds_offer_id', $item['ic_offer_id']);
                            $rate->add_meta_data('_infcrwds_shipping_price', $total);
                            $shipping_price_taxes = Commons::get_price_taxes($total, wc_prices_include_tax());
                            $rate->set_taxes($shipping_price_taxes);
                            $new_package_rates['flat_rate:1'] = $rate;
                        }
                    }
                }
                return $new_package_rates;
            }
                    // else {
                //     $total = $shipping_price;
                //     if(wc_prices_include_tax()) {
                //         $total += $shipping_tax;
                //     }
                //     // else {
                //     //     $total_tax_rate = 1 - ($total / ($total + $shipping_tax));
                //     //     $total += $shipping_tax;
                //     //     $total = $total / (1 + $total_tax_rate);
                //     // }
                //     $total = INFCWDS_Plugin_Compatibilities::get_fixed_currency_price($total);
                //     $method->set_cost($total);
                    
                //     $method->add_meta_data('_infcrwds_offer_id', $item['ic_offer_id']);
                //     $method->add_meta_data('_infcrwds_shipping_price', $total);
                //     $shipping_price_taxes = Commons::get_price_taxes($total, wc_prices_include_tax());
                //     $method->set_taxes($shipping_price_taxes);
                // }
            }
        return $package_rates;
    }

    public function ensure_product_quantity( $cart_item_key, $quantity, $old_quantity, $cart ) {
        $cart_itm = $cart->cart_contents[ $cart_item_key ];
        if(isset($cart_itm['ic_pricing'])) {
            $cart->cart_contents[$cart_item_key]['quantity'] = $cart_itm['ic_pricing']['quantity'];
        }
    }
    
    function set_custom_cart_item_price( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) )
            return;
    
        if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 )
            return;
    
            
            foreach ( $cart->get_cart() as $cart_item) {
                if(isset($cart_item['ic_pricing'])) {
                    $pricing = $cart_item['ic_pricing'];
                    $total = $pricing['bc_sale_price']; 
                    if(wc_prices_include_tax()) {
                        $total += $pricing['bc_sale_tax'];
                    }
                    // else {
                        // $total_tax_rate = 1 - ($total / ($total + $pricing['tax_cost']));
                        // $total += $pricing['tax_cost'];
                        // $total = $total / (1 + $total_tax_rate);
                    // }
                    
                    $cart_item['data']->set_price($total / $pricing['quantity']);
            }
        }
    }

    public function maybe_tag_order_for_infcrwds($order_id, $data) {
        $attached_to_offer = false;
        $order = null;
        // '_infcrwds_revenue'
        $revenue = 0;
        foreach(WC()->cart->get_cart() as $cart_item) {
            if(isset($cart_item['ic_offer_id'])) {
                $attached_to_offer = true;
                // this is our lead, make sure to tag the order
               // Getting the order object "$order"
               if($order === null) {
                $order = wc_get_order( $order_id );
               }
                // Getting the items in the order
                $order_items = $order->get_items();
                $product_id = $cart_item['data']->get_id();
                
                // Iterating through each item in the order
                foreach ($order_items as $item_id => $item_data) {
                    $product = INFCWDS_WC_Compatibility::get_product_from_item($order, $item_data);
                    if($product->get_id() === $product_id) {
                        // this is the product in the offer, tag it
                        wc_add_order_item_meta( $item_id, '_infcrwds_offer_id', $cart_item['ic_offer_id']);
                        wc_add_order_item_meta( $item_id, '_infcrwds_session_id', $cart_item['ic_session_id']);
                        $revenue += wc_get_order_item_meta($item_id, '_line_total', true);

                        if($cart_item['ic_is_internal_upsell'] && isset($cart_item['ic_merge_with'])) {
                            wc_add_order_item_meta($item_id, '_infcrwds_merge_with', $cart_item['ic_merge_with']);
                        }
                    }
                }
            }
        }
        if($attached_to_offer && $order !== null) {
            $order->add_meta_data( '_infcrwds', '1' );
            $revenue += $order->get_shipping_total();
            $order->add_meta_data('_infcrwds_revenue', $revenue);
            $order->save_meta_data();
        }
    }

    public function add_selected_offer_to_cart($offer, $session_id) {
        
        $this->add_offer_to_cart($offer['offer'], $offer['order_id'], $offer['pricing'], $offer['product_id'], $session_id, $offer['selected_variant_id']);
    }

    public function add_offer_to_cart($offer, $order_id_to_merge_with, $pricing, $product_id, $session_id, $selected_variant_id) {
        foreach(WC()->cart->get_cart() as $cart_item) {
            if(isset($cart_item['ic_offer_id'])) {
                WC()->cart->remove_cart_item($cart_item['key']);
                // throw new OfferAlreadyAcceptedException('offer_already_accepted');
            }
        }
        $is_intenal_upsell = $order_id_to_merge_with > 0;
        $custom_data = array(
            'ic_pricing' => $pricing,
            'ic_offer_id' => $offer['offer_id'],
            'ic_is_internal_upsell' => $is_intenal_upsell,
            'ic_session_id' => $session_id,
            'ic_expiry' => time() + 3600
        );
        if($offer['merge_with_order'] && $is_intenal_upsell) {
            $to_merge_order = wc_get_order($order_id_to_merge_with);
            $merged_flags = INFCWDS_WC_Compatibility::get_order_meta($to_merge_order, '_infcrwds_order_merged');
            if(empty($merged_flags)) {
                // allow merge only if order to merged with is not a merged order by itself
                $custom_data['ic_merge_with'] = $order_id_to_merge_with;
            }
        }
        $attributes = array();
        if($selected_variant_id !== null) {
            $product = wc_get_product($offer['product_id']);
            if($product->is_type('variable')) {
                foreach($product->get_available_variations() as $variation ) {
                    if($variation['variation_id'] === $selected_variant_id) {
                        foreach( $variation['attributes'] as $key => $value ){
                            $taxonomy = str_replace('attribute_', '', $key );
                            $taxonomy_obj = get_taxonomy( $taxonomy );
                            if($taxonomy_obj) {
                                $taxonomy_label = $taxonomy_obj->labels->singular_name;
                                $term_name = get_term_by( 'slug', $value, $taxonomy_obj )->name;
                                $attributes[$taxonomy_label] = $term_name;
                            }
                            else {
                                $attributes[$taxonomy] = $value;
                            }
                        }
                    }
                }
            }
        }
        WC()->cart->remove_cart_item(WC()->cart->generate_cart_id($offer['product_id'], $pricing['quantity'], $selected_variant_id, $attributes));
        WC()->cart->add_to_cart($offer['product_id'], $pricing['quantity'], $selected_variant_id, $attributes, $custom_data);
        WC()->cart->calculate_shipping();
    }
}