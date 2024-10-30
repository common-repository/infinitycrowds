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

class InvalidProductException extends \Exception {
}

class OfferConverter {

    public function __construct($offer, $store, $order, $selected_variant_id) {
        $this->offer = $offer;
        $this->order = $order;
        $this->_store = $store;
        $this->selected_currency = INFCWDS_WC_Compatibility::get_order_currency( $order );
        $this->from_currency = sanitize_text_field($offer['currency_code']);
        $this->_should_convert_currency = $this->selected_currency !== $this->from_currency;
        $product_id = intval($offer['product_id']);
        $this->allow_more_variations = true;
        if(isset($offer['variation_id']) && $offer['variation_id'] !== null) {
            $variation_id = $offer['variation_id'];
            $this->allow_more_variations = false;
        } else {
            $variation_id = $selected_variant_id;
        }
        $this->selected_variant_id = $variation_id;
        $this->_model = $offer['model'];
        $this->_is_external = $offer['is_external'] === '1';
        $this->_prices_with_tax = $offer['prices_with_tax'] == '1';
        $this->_discount_details = isset($offer['discount_details']) ? $offer['discount_details'] : null;
        $this->_dynamic_shipping = isset($offer['dynamic_shipping']) ? $offer['dynamic_shipping'] : false;
        $this->_merge_with_existing = $offer['merge_with_order'] == '1';
        # TBD: how much the user should have payed for shipping
        $this->original_shipping_price = null;
        $this->original_shipping_tax = null;
        $this->item_price = null;
        $this->product_attributes = null;

        $product = wc_get_product($product_id);
        $this->shipping_price = 0.0;
        $this->shipping_tax = 0.0;
        $this->shipping_method = null;
        $this->has_shipping = true;
        $this->shipping_type = false;
        $this->variations = null;

        $this->flat_shipping_price = floatval($offer['flat_shipping']);
        switch($this->_model) {
            case 'ONE_PLUS_ONE':
                if($this->_discount_details === null) {
                    $this->quantity = 2;
                    $this->_discount_details = array(
                        "discount_percentage" => 0.5,
                        "discount_amount" => null,
                        "quantity" => $this->quantity //todo: change?..
                    );    
                }
                else {
                    $prereq = intval($this->_discount_details['prereq_quantity']);
                    $discount_quantity = intval($this->_discount_details['quantity']);
                    $this->quantity = $prereq + $discount_quantity;
                    
                    $this->_discount_details['discount_percentage'] = 1 - (($prereq + 
                        ($discount_quantity * (1 - $this->_discount_details['discount_percentage']))) / $this->quantity);
                }
                break;
            case 'DISCOUNT': 
            case 'ADD_MORE':
                $this->quantity = $this->_discount_details === null ? 1 : intval($this->_discount_details['quantity']);
                break;

            default:
                $this->quantity = 1;
        }
        if(!$product 
            || 'publish' !== $product->get_status()) {
            throw new InvalidProductException('product_not_found');
        }
        $selected_product = $this->_do_product_variable_handling($product, $variation_id);
        
        if(!$product->has_enough_stock($this->quantity)) {
            throw new InvalidProductException('product_out_of_stock');
        }
        $this->product = $product;
        $this->selected_product = $selected_product;
        $this->regular_price = null;
        $this->regular_price_tax = null;
        $this->sale_price = null;
        $this->tax_cost = null;
        $this->sale_price_includes_shipping = null;
        $this->shipping_label = null;

        $this->base_currency_sale_price = null;
        $this->base_currency_tax_cost = null;
        $this->base_currency_shipping_price = null;
        $this->base_currency_shipping_tax = null;

    }

    protected function get_attribute_options( $product_id, $attribute ) {
		if ( isset( $attribute['is_taxonomy'] ) && $attribute['is_taxonomy'] ) {
			return wc_get_product_terms(
				$product_id,
				$attribute['name'],
				array(
					'fields' => 'names',
				)
			);
		} elseif ( isset( $attribute['value'] ) ) {
			return array_map( 'trim', explode( '|', $attribute['value'] ) );
		}
		return array();
	}
	/**
	 * Get the attributes for a product or product variation.
	 *
	 * @param WC_Product|WC_Product_Variation $product Product instance.
	 *
	 * @return array
	 */
	protected function get_attributes( $product ) {
		$attributes = array();

		if ( $product->is_type( 'variation' ) ) {
			$_product = wc_get_product( $product->get_parent_id() );
			foreach ( $product->get_variation_attributes() as $attribute_name => $attribute ) {
				$name = str_replace( 'attribute_', '', $attribute_name );

				if ( empty( $attribute ) && '0' !== $attribute ) {
					continue;
				}

				// Taxonomy-based attributes are prefixed with `pa_`, otherwise simply `attribute_`.
				if ( 0 === strpos( $attribute_name, 'attribute_pa_' ) ) {
					$option_term  = get_term_by( 'slug', $attribute, $name );
					$attributes[] = array(
						'id'     => wc_attribute_taxonomy_id_by_name( $name ),
						'name'   => $this->get_attribute_taxonomy_name( $name, $_product ),
						'option' => $option_term && ! is_wp_error( $option_term ) ? $option_term->name : $attribute,
					);
				} else {
					$attributes[] = array(
						'id'     => 0,
						'name'   => $this->get_attribute_taxonomy_name( $name, $_product ),
						'option' => $attribute,
					);
				}
			}
		} else {
			foreach ( $product->get_attributes() as $attribute ) {
				$attributes[] = array(
					'id'        => $attribute['is_taxonomy'] ? wc_attribute_taxonomy_id_by_name( $attribute['name'] ) : 0,
					'name'      => $this->get_attribute_taxonomy_name( $attribute['name'], $product ),
					'position'  => (int) $attribute['position'],
					'visible'   => (bool) $attribute['is_visible'],
					'variation' => (bool) $attribute['is_variation'],
					'options'   => $this->get_attribute_options( $product->get_id(), $attribute ),
				);
			}
		}

		return $attributes;
    }
    
	/**
	 * Get product attribute taxonomy name.
	 *
	 * @param string     $slug    Taxonomy name.
	 * @param WC_Product $product Product data.
	 *
	 * @since  3.0.0
	 * @return string
	 */
	protected function get_attribute_taxonomy_name( $slug, $product ) {
        if(function_exists('wc_attribute_taxonomy_slug') === false) {
            return $slug;
        }
        // Format slug so it matches attributes of the product.
		$slug       = wc_attribute_taxonomy_slug( $slug );
		$attributes = $product->get_attributes();
		$attribute  = false;

		// pa_ attributes.
		if ( isset( $attributes[ wc_attribute_taxonomy_name( $slug ) ] ) ) {
			$attribute = $attributes[ wc_attribute_taxonomy_name( $slug ) ];
		} elseif ( isset( $attributes[ $slug ] ) ) {
			$attribute = $attributes[ $slug ];
		}

		if ( ! $attribute ) {
			return $slug;
		}

		// Taxonomy attribute name.
		if ( $attribute->is_taxonomy() ) {
			$taxonomy = $attribute->get_taxonomy_object();
			return $taxonomy->attribute_label;
		}

		// Custom product attribute name.
		return $attribute->get_name();
	}

    private function _do_product_variable_handling($product, $variation_id) {
        if(!$this->allow_more_variations) {
            return $product;
        }
        if (!$product->is_type( 'variable' )) {
            return $product;
        }

        $filtered_variations = array();
        $available_variations = $product->get_available_variations();
        
        $default_product = null;
        foreach($available_variations as $variation) {
            if($variation['is_in_stock'] && ($variation['max_qty'] === '' || $variation['max_qty'] >= $this->quantity)) {
                $variation['attributes'] = $this->get_attributes(wc_get_product($variation['variation_id']));
                $can_set_default = $variation_id === null || $variation_id == $variation['variation_id'];
                $filtered_variations[] = $variation;
                if($can_set_default && $default_product === null) {
                    $default_product = wc_get_product($variation['variation_id']);
                }
            }
        }
        if($default_product === null) {
            throw new InvalidProductException('product_not_found');
        }
        $this->variations = $filtered_variations;
        $this->selected_variant_id = $default_product->get_id();
        // $this->product_attributes = $this->get_attributes($product);
        return $default_product;
    }

    public function is_free_shipping( $method ) {
        $re  = '/(free_shipping)/';
        $str = $method;
        preg_match_all( $re, $str, $matches, PREG_SET_ORDER, 0 );

        if ( is_array( $matches ) && count( $matches ) > 0 && isset( $matches[0][0] ) && 'free_shipping' === $matches[0][0] ) {

            return true;
        }

        return false;
    }
    
    private function calc_shipping_price() {
        $this->has_shipping = !$this->_is_external &&
            wc_shipping_enabled() &&
            $this->selected_product->needs_shipping();

        if(!$this->has_shipping) {
            return;
        }
        
        $current_method  = null;
        if($this->order !== null) {
            $methods =  $this->order->get_shipping_methods();
            // $chosen_methods = WC()->session->get( 'chosen_shipping_methods', array() );
            // $existing_methods = array();
            if ( $methods && is_array( $methods ) && count( $methods ) ) {
                if($this->_merge_with_existing) {
                    $this->shipping_price = 0;
                    $this->shipping_tax = 0;
                    $this->shipping_type = 'free shipping';
                    $this->shipping_label = 'Free Shipping';
                    $this->base_currency_shipping_tax = 0;
                    $this->base_currency_shipping_price = 0;
                }
            }
            else {
                $this->shipping_price = 0;
                $this->shipping_tax = 0;
                $this->base_currency_shipping_tax = 0;
                $this->base_currency_shipping_price = 0;
                $this->has_shipping = false;
                return;
            }
            $this->original_shipping_price = floatval($this->order->get_shipping_total());
            $this->original_shipping_tax = floatval($this->order->get_shipping_tax());
        }
        if($this->_merge_with_existing && $this->flat_shipping_price > 0) {
            $this->shipping_price = $this->flat_shipping_price;
            $this->base_currency_shipping_price = $this->flat_shipping_price; 
            if($this->_should_convert_currency) {
                $this->shipping_price = INFCWDS_Plugin_Compatibilities::get_fixed_currency_price( $this->shipping_price, $this->selected_currency );
            }
            $this->shipping_tax = Commons::get_tax_cost($this->shipping_price, false);
            $this->base_currency_shipping_tax = Commons::get_tax_cost($this->base_currency_shipping_price, false);
            if($this->_prices_with_tax) {
                $this->shipping_price = $this->shipping_price - $this->shipping_tax;
                $this->base_currency_shipping_price = $this->base_currency_shipping_price - $this->base_currency_shipping_tax;
            }
            $this->shipping_type = 'fixed';
            $this->shipping_label = $current_method == null ? $this->_store->get('flat_shipping_label', 'Flat Shipping') : $current_method->get_name();
        }
    }
    
    public function calc_discounts($sale_price, $conv_disc_amnt) {
        $tax_cost = 0;
        if($this->_prices_with_tax) {
            if($this->_discount_details !== null) {
                $tax_for_sale_price = Commons::get_tax_cost($sale_price, false);
                if($this->_discount_details['discount_percentage'] != null && floatval($this->_discount_details['discount_percentage']) > 0) {
                    $sale_price = ($sale_price + $tax_for_sale_price) * (1 - floatval($this->_discount_details['discount_percentage']));
                } else if($this->_discount_details['discount_amount'] != null && floatval($this->_discount_details['discount_amount']) > 0) {
                    $disc_amnt = $this->_discount_details['discount_amount'];
                    if($conv_disc_amnt) {
                        $disc_amnt = INFCWDS_Plugin_Compatibilities::get_fixed_currency_price( $disc_amnt);
                    }
                    $sale_price = ($sale_price + $tax_for_sale_price) - floatval($disc_amnt);
                }
                $tax_cost = Commons::get_tax_cost($sale_price, true);
                $sale_price = $sale_price - $tax_cost;
            }
        }
        else {
            if($this->_discount_details !== null) {
                if($this->_discount_details['discount_percentage'] != null && floatval($this->_discount_details['discount_percentage']) > 0) {
                    $sale_price = $sale_price * (1 - floatval($this->_discount_details['discount_percentage']));
                } else if($this->_discount_details['discount_amount'] != null && floatval($this->_discount_details['discount_amount']) > 0) {
                    $disc_amnt = $this->_discount_details['discount_amount'];
                    if($conv_disc_amnt) {
                        $disc_amnt = INFCWDS_Plugin_Compatibilities::get_fixed_currency_price( $disc_amnt);
                    }
                    $sale_price = $sale_price - floatval($disc_amnt);
                }
            }
            $tax_cost = Commons::get_tax_cost($sale_price, false);
        }
        return array($sale_price, $tax_cost);
    }

    public function calc_offer_pricing() {
        $this->item_price = floatval(($this->selected_product->get_regular_price() != null ? $this->selected_product->get_regular_price() : $this->selected_product->get_price()));
        $start_price =  $this->item_price * $this->quantity;
        $this->calc_shipping_price();
        
        $sale_price = $this->selected_product->get_price() * $this->quantity;

        $base_currency_sale_price = INFCWDS_Plugin_Compatibilities::get_fixed_currency_price_reverse( $sale_price );

        $start_price = INFCWDS_Plugin_Compatibilities::get_fixed_currency_price_reverse( $start_price, null, $this->selected_currency );
        $sale_price = INFCWDS_Plugin_Compatibilities::get_fixed_currency_price_reverse( $sale_price, null, $this->selected_currency );
        
        $sale_price_arr = $this->calc_discounts($sale_price, $this->_should_convert_currency);
        $base_currency_sale_price_arr = $this->calc_discounts($base_currency_sale_price, false);

        $this->sale_price = $sale_price_arr[0];
        $this->tax_cost = $sale_price_arr[1];

        $this->base_currency_sale_price = $base_currency_sale_price_arr[0];
        $this->base_currency_tax_cost = $base_currency_sale_price_arr[1];

        $this->regular_price = $start_price;
        $this->regular_price_tax = Commons::get_tax_cost($this->regular_price, false);
        $this->sale_price_includes_shipping = $sale_price + $this->shipping_price;
    }
}
