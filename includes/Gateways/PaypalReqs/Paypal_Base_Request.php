<?php
namespace InfCrowds\WPR\Gateways\PaypalReqs;
use \InfCrowds\WPR\Gateways\SV_API_Base;
use INFCWDS_WC_Compatibility;
use \InfCrowds\WPR\Gateways\GatewayPayPalStandard_Integration;
use \InfCrowds\WPR\OrderHelpers;


class Paypal_Base_Request extends SV_API_Base {
    /** @var array the request parameters */
    private $parameters = array();
    public $response = null;

    public function __construct(
            $deprecated_params, 
            $paypal_integration,
            $session_id) {
        $this->_paypal_integration = $paypal_integration;
        $this->error_reason = null;
        $this->_args = null; 
        $this->_session_id = $session_id;
        $this->_deprecated_params = $deprecated_params;
        $environment = $paypal_integration->get_environment();
        $api_creds_prefix = '';
        $this->_shipping_prefix = 'PAYMENTREQUEST_0_SHIPTO';
        $this->total_amount = 0;
		if ( 'sandbox' === $environment ) {
			$api_creds_prefix = 'sandbox_';
		}
        $this->set_api_credentials(
            $paypal_integration->get_key(), 
            $environment,
            $paypal_integration->get_wc_gateway()->get_option( $api_creds_prefix . 'api_username' ),
            $paypal_integration->get_wc_gateway()->get_option( $api_creds_prefix . 'api_password' ),
            $paypal_integration->get_wc_gateway()->get_option( $api_creds_prefix . 'api_signature' ) );
        $this->line_item_idx = 0;
        $this->total_amount = 0;
    }
    
    public function add_line_item($name, $desc, $amt, $quantity, $url) {
        $amt = $this->round($amt);
        $single_item_price = $this->round($amt / $quantity);
        if(($single_item_price * $quantity) !== $amt) {
            // handle paypal rounding issues
            $single_item_price = $amt;
            $quantity = 1;
        }
        $this->total_amount += $amt;
        $item = array(
			'NAME' => $name,
			'AMT'  => $single_item_price,
			'QTY'  => $quantity,
        );
        if($desc !== null) {
            $item['DESC'] = $desc;
        }
        if($url !== null) {
            $item['ITEMURL'] = $url;
        }
        $this->add_line_item_parameters($item, $this->line_item_idx++);
        return $item;
    }

    /**
	 * Helper method to return the item description, which is composed of item
	 * meta flattened into a comma-separated string, if available. Otherwise the
	 * product SKU is included.
	 *
	 * The description is automatically truncated to the 127 char limit.
	 *
	 * @param array $item cart or order item
	 * @param \WC_Product $product product data
	 *
	 * @return string
	 */
	protected function get_item_description( $product_or_str ) {

		if ( is_string( $product_or_str ) ) {
			$str = $product_or_str;
		} else {
			$str = $product_or_str->get_short_description();
		}
		$item_desc = wp_strip_all_tags( wp_specialchars_decode( wp_staticize_emoji( $str ) ) );
		$item_desc = preg_replace( '/[\x00-\x1F\x80-\xFF]/', '', $item_desc );
		$item_desc = str_replace( "\n", ', ', rtrim( $item_desc ) );
		if ( strlen( $item_desc ) > 127 ) {
			$item_desc = substr( $item_desc, 0, 124 ) . '...';
		}

		return html_entity_decode( $item_desc, ENT_NOQUOTES, 'UTF-8' );
	}

    public function set_overall_params(
        $shipping_price,
        $shipping_diff,
        $order,
        $taxes,
        $oid_as_inv_num=true,
        $total_amount=null) {

        $order_num = OrderHelpers::get_order_number($order, $this->_session_id);
        $inv_num = $this->_paypal_integration->get_wc_gateway()->get_option( 'invoice_prefix' ) . $order_num;
        if($total_amount === null) {
            $total_amount = $this->round($this->total_amount + $shipping_price + $shipping_diff + ($taxes === null ? 0 : $taxes));
        }
        $order_subtotal = $this->round($this->total_amount);

        $arr = array(
            'AMT'              => $total_amount,
            'CURRENCYCODE'     => INFCWDS_WC_Compatibility::get_order_currency( $order ),
            'ITEMAMT'          => $order_subtotal,
            'INVNUM'           => $inv_num,
            'PAYMENTACTION'    => $this->_args['payment_action'],
            'PAYMENTREQUESTID' => INFCWDS_WC_Compatibility::get_order_id( $order ),
            'CUSTOM'           => json_encode( array(
                '_infcrwds_o_id'       => $oid_as_inv_num ? $inv_num : INFCWDS_WC_Compatibility::get_order_id( $order ),
                '_infcrwds_session_id' => $this->_session_id,
                ) ),
            );
        if($taxes !== null) {
            $arr['TAXAMT'] = $this->round($taxes);
        }
        $has_shipping = $order->needs_shipping_address();
        /**
         * When shipping amount is a negative number, means user opted for free shipping offer
         * In this case we setup shippingamt as 0 and shipping discount amount is that negative amount that is coming.
         */
        if ( $has_shipping && 0 > $shipping_diff ) {
            $arr['SHIPPINGAMT'] = 0;
            $arr['SHIPDISCAMT'] = $shipping_diff;
        }
        else {
            $arr['SHIPPINGAMT'] = $this->round($shipping_price);
        }


        if($this->_deprecated_params) {
            $this->add_parameters($arr);
        }
        else {
            $this->add_payment_parameters($arr);
        }

        if($has_shipping) {
            $shipping_params = $this->_paypal_integration->get_shipping_address_params($order, $this->_shipping_prefix);
            foreach ( $shipping_params as $key => $val ) {
                $this->add_parameter( $key, $val );
            }
        }
    }

    public function set_payment_params_for_order($order) {
        $calculated_total = 0;
        $order_subtotal   = 0;
        $item_count       = 0;
        $order_items      = array();

        $should_skip_line_items = 
            $this->_paypal_integration->skip_line_items( $order );
        if($should_skip_line_items) {
            $item_names = array();

            foreach ( $order->get_items() as $item ) {
                $order_subtotal += $item['line_total'];
                $qty = ( ! empty( $item['qty'] ) ) ? absint( $item['qty'] ) : 1;
                $item_names[] = sprintf( '%1$s x %2$s', $item->get_title(), $qty );
            }
            foreach ( $order->get_fees() as $fee ) {
                $item_names[] = sprintf( '%1$s x %2$s', $fee['name'], 1 );
                $order_subtotal += $fee['line_total'];
            }

            if ( $order->get_total_discount() > 0 ) {
                $item_names[] = sprintf( '%1$s x %2$s',
                    __( 'Total Discount', 'woocommerce-subscriptions' ), 1 );
            }

            $total_amount = $this->round( $order->get_total() );
            $calculated_total += $this->round( $order_subtotal + $order->get_cart_tax() ) + $this->round( $order->get_total_shipping() + $order->get_shipping_tax() );

            // offset the discrepancy between the WooCommerce cart total and PayPal's calculated total by adjusting the order subtotal
            if ( $this->price_format( $total_amount ) !== $this->price_format( $calculated_total ) ) {
                $order_subtotal = $order_subtotal - ( $calculated_total - $total_amount );
            }

            $item_names = array();

            foreach ( $order_items as $item ) {
                $item_names[] = sprintf( '%1$s x %2$s', $item['NAME'], $item['QTY'] );
            }

            // add a single item for the entire order
            $this->add_line_item(
                // translators: placeholder is blogname
                sprintf( __( '%s - Order', 'woocommerce-subscriptions' ), get_option( 'blogname' ) ),
                $this->get_item_description( implode( ', ', $item_names ) ),
                $this->round( $order_subtotal + $order->get_cart_tax() ),
                1, null );
            

            $this->set_overall_params(
                $this->round( $order->get_total_shipping() + $order->get_shipping_tax() ),
                0, $order, null, false, $total_amount);

            // if ( $this->_deprecated_params ) {
            //     $this->add_parameters( array(
            //         'AMT'              => $total_amount,
            //         'CURRENCYCODE'     => INFCWDS_WC_Compatibility::get_order_currency( $order ),
            //         'ITEMAMT'          => $this->round( $order_subtotal + $order->get_cart_tax() ),
            //         'SHIPPINGAMT'      => $this->round( $order->get_total_shipping() + $order->get_shipping_tax() ),
            //         'INVNUM'           => OrderHelpers::get_order_invoice_num($this->get_wc_gateway()->get_option( 'invoice_prefix' ), $order),
            //         'PAYMENTACTION'    => $type,
            //         'PAYMENTREQUESTID' => INFCWDS_WC_Compatibility::get_order_id( $order ),
            //         'CUSTOM'           => json_encode( array(
            //             '_infcrwds_o_id'       => INFCWDS_WC_Compatibility::get_order_id( $order ),
            //             '_infcrwds_session_id' => $this->_session_id,
            //         ) ),
            //     ) );
            // } else {
            //     $this->add_payment_parameters( array(
            //         'AMT'              => $total_amount,
            //         'CURRENCYCODE'     => INFCWDS_WC_Compatibility::get_order_currency( $order ),
            //         'ITEMAMT'          => $this->round( $order_subtotal + $order->get_cart_tax() ),
            //         'SHIPPINGAMT'      => $this->round( $order->get_total_shipping() + $order->get_shipping_tax() ),
            //         'INVNUM'           => $this->get_wc_gateway()->get_option( 'invoice_prefix' ) . WFOCU_Common::str_to_ascii( ltrim( $order->get_order_number(), _x( '#', 'hash before the order number. Used as a character to remove from the actual order number', 'woocommerce-subscriptions' ) ) ),
            //         'PAYMENTACTION'    => $this->_args['payment_action'],
            //         'PAYMENTREQUESTID' => INFCWDS_WC_Compatibility::get_order_id( $order ),
            //         'CUSTOM'           => json_encode( array(
            //             '_infcrwds_o_id'       => INFCWDS_WC_Compatibility::get_order_id( $order ),
            //             '_infcrwds_session_id' => $this->_session_id,
            //         ) ),
            //     ) );
            // }
        }
        else {
            foreach ( $order->get_items() as $item ) {
                $product = new \WC_Product( $item['product_id'] );
    
                $order_items[] = $this->add_line_item(
                    $product->get_title(),
                    $this->get_item_description( $product ),
                    $this->round( $order->get_item_subtotal( $item ) ),
                    ( ! empty( $item['qty'] ) ) ? absint( $item['qty'] ) : 1,
                    $product->get_permalink()
                );
            }
            
            // add fees
            foreach ( $order->get_fees() as $fee ) {
    
                $order_items[] = $this->add_line_item(
                    $fee['name'], null,
                    $this->round( $fee['line_total'] ),
                    1, null
                );
            }
    
            if ( $order->get_total_discount() > 0 ) {
    
                $order_items[] = $this->add_line_item(
                    __( 'Total Discount', 'woocommerce-subscriptions' ), null,
                    - $this->round( $order->get_total_discount() ), 1, null
                );
            }
            $this->set_overall_params(
                $this->round( $order->get_total_shipping()), 0,
                $order, $this->round( $order->get_total_tax() ), false, $order->get_total());
        }
    }


    public function set_payment_parameters_for_offer($order, $offer, $pricing, $product) {
        $this->add_line_item(
            $product->get_title(),
            $offer['offer']['title'],
            $pricing['sale_price'],
            $pricing['quantity'],
            $product->get_permalink());
        
        $this->set_overall_params(
            $pricing['shipping_price'],
            0,
            $order,
            $pricing['tax_cost'] + $pricing['shipping_tax']);
        
    }

    public function finalize() {
        
        $this->add_parameters(
            $this->_paypal_integration->create_credentials(
                $this->api_username, $this->api_password, $this->api_signature, 124
            )
        );

        $request         = new \stdClass();
        $request->path   = '';
        $request->method = 'POST';
        $request->body   = $this->to_string();
        WC()->session->set( 'paypal_request_data', $this->_paypal_integration->parse_parameters($this->parameters));
            
        $response = $this->perform_request( $request );
        
        return $this->on_response($response);
    }
    
    protected function on_response($response) {
        if($this->has_api_error($response)) {
            $this->error_reason = $this->get_api_error( $response );
            return false;
        }
        return $response;
    }

    public function get_api_error( $response ) {


		if ( 'Failure' == $this->get_value_from_response( $response, 'ACK' ) ) {
			return $this->get_value_from_response( $response, 'L_LONGMESSAGE0' );
		}

		return '';
	}

    public function has_api_error( $response ) {
		// assume something went wrong if ACK is missing
		if ( ! isset( $response['ACK'] ) ) {
			return true;
		}

		// any non-success ACK is considered an error, see
		// https://developer.paypal.com/docs/classic/api/NVPAPIOverview/#id09C2F0K30L7
		return ( 'Success' !== $this->get_value_from_response( $response, 'ACK' ) && 'SuccessWithWarning' !== $this->get_value_from_response( $response, 'ACK' ) );
    }

    public function get_value_from_response( $response, $key ) {

		if ( $response && isset( $response[ $key ] ) ) {

			return $response[ $key ];
		}
	}

    
    /**
	 * Add payment parameters, auto-prefixes the parameter key with `PAYMENTREQUEST_0_`
	 * for convenience and readability
	 *
	 * @param array $params
	 *
	 * @since 2.0
	 */
	protected function add_payment_parameters( array $params ) {
		foreach ( $params as $key => $value ) {
			$this->add_parameter( "PAYMENTREQUEST_0_{$key}", $value );
		}
    }
    

	/**
	 * Format prices.
	 *
	 * @since 2.2.12
	 *
	 * @param float|int $price
	 * @param int $decimals Optional. The number of decimal points.
	 *
	 * @return string
	 */
	public function price_format( $price, $decimals = 2 ) {
		return number_format( $price, $decimals, '.', '' );
    }
    
    /**
	 * Returns the string representation of this request
	 *
	 * @see SV_WC_Payment_Gateway_API_Request::to_string()
	 * @return string the request query string
	 * @since 2.0
	 */
	public function to_string() {
		//InfcrwdsPlugin()->logger->log( print_r( $this->get_parameters(), true ) );

		return http_build_query( $this->_paypal_integration->parse_parameters($this->parameters), '', '&' );
    }
    
    protected function set_method( $method ) {
		$this->add_parameter( 'METHOD', $method );
    }    
    /**
	 * Adds a line item parameters to the request, auto-prefixes the parameter key
	 * with `L_PAYMENTREQUEST_0_` for convenience and readability
	 *
	 * @param array $params
	 * @param int $item_count current item count
	 *
	 * @since 2.0
	 */
	protected function add_line_item_parameters( array $params, $item_count) {
		foreach ( $params as $key => $value ) {
			if ( $this->_deprecated_params ) {
				$this->add_parameter( "L_{$key}{$item_count}", $value );
			} else {
				$this->add_parameter( "L_PAYMENTREQUEST_0_{$key}{$item_count}", $value );
			}
		}
    }


	/** Helper Methods ******************************************************/

	/**
	 * Add a parameter
	 *
	 * @param string $key
	 * @param string|int $value
	 *
	 * @since 2.0
	 */
	public function add_parameter( $key, $value ) {
		$this->parameters[ $key ] = $value;
	}

	/**
	 * Add multiple parameters
	 *
	 * @param array $params
	 *
	 * @since 2.0
	 */
	public function add_parameters( array $params ) {
		foreach ( $params as $key => $value ) {
			$this->add_parameter( $key, $value );
		}
	}

	public function clean_params() {
		$this->parameters = array();
	}

	/**
	 * Sets up API credentials to the class that we need later during the API call
	 *
	 * @param $gateway_id
	 * @param $api_environment
	 * @param $api_username
	 * @param $api_password
	 * @param $api_signature
	 */
	public function set_api_credentials( $gateway_id, $api_environment, $api_username, $api_password, $api_signature ) {
		// tie API to gateway
		$this->gateway_id = $gateway_id;

		// request URI does not vary per-request
		$this->request_uri = ( 'production' === $api_environment ) ? GatewayPayPalStandard_Integration::PRODUCTION_ENDPOINT : GatewayPayPalStandard_Integration::SANDBOX_ENDPOINT;

		// PayPal requires HTTP 1.1
		$this->request_http_version = '1.1';

		$this->api_username  = $api_username;
		$this->api_password  = $api_password;
		$this->api_signature = $api_signature;
    }
	/**
	 * Round a float
	 *
	 * @since 2.0.9
	 *
	 * @param float $number
	 * @param int $precision Optional. The number of decimal digits to round to.
	 */
	protected function round( $number, $precision = 2 ) {
		return round( (float) $number, $precision );
    }
    
    	/**
	 * Return the parsed response object for the request
	 *
	 * @since 2.2.0
	 *
	 * @param string $raw_response_body
	 *
	 * @return object
	 */
	protected function get_parsed_response( $raw_response_body ) {

		wp_parse_str( urldecode( $raw_response_body ), $this->response_params );

		return $this->response_params;
	}
}