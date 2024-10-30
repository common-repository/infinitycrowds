<?php

namespace InfCrowds\WPR\Endpoint;


class CouponApi extends BaseApi
{
    function __construct()
    {
        parent::__construct('/coupons/');
    }

    public function register_routes()
    {
        $this->add_route('get_coupon', array());
        $this->add_create_route('create_coupon', array());
    }

    private function create_coupon($request) {
        /**
         * Create a coupon programatically
         */
        $coupon_code = $request->get_param('code'); // Code
        $coupon_data = new WC_Coupon($coupon_code);
        if(!empty($coupon_data->id))
        {
            return new \WP_REST_Response( array(
                'success' => false,
                'value' => array(
                    'msg' => 'Already Exists'
                )
            ), 422 );
        }
        $amount = $request->get_param('amount'); // Amount
        $discount_type = $request->get_param('discount_type'); // Type: fixed_cart, percent, fixed_product, percent_product
        $quantity = $request->get_param('quantity'); // quantity
        $product_id = $request->get_param('product_id');
                            
        $coupon = array(
            'post_title' => $coupon_code,
            'post_content' => '',
            'post_status' => 'publish',
            'post_author' => 1,
            'post_type'		=> 'shop_coupon'
        );
                            
        $new_coupon_id = wp_insert_post( $coupon );
                            
        // Add meta
        update_post_meta( $new_coupon_id, 'discount_type', $discount_type );
        update_post_meta( $new_coupon_id, 'coupon_amount', $amount );
        update_post_meta( $new_coupon_id, 'individual_use', 'no' );
        update_post_meta( $new_coupon_id, 'product_ids', array($product_id) );
        update_post_meta( $new_coupon_id, 'exclude_product_ids', '' );
        update_post_meta( $new_coupon_id, 'usage_limit', $quantity );
        update_post_meta( $new_coupon_id, 'expiry_date', '' );
        update_post_meta( $new_coupon_id, 'apply_before_tax', 'yes' );
        update_post_meta( $new_coupon_id, 'free_shipping', 'no' );
        return $this->_get_coupon_internal($coupon_code);
    }

    private function _get_coupon_internal($code) {
        $c = new WC_Coupon($code);

        if ( $c ) {
            return new \WP_REST_Response( array(
                'success' => true,
                'value' => array(
                    'discount' => $c->amount,
                    'discount_type' => $c->discount_type,
                    'individual_use' => $c->individual_use,
                    'usage_count' => $c->usage_count,
                    'quantity' => $c->usage_limit,
                    'description' => $c->description
                )
            ), 200 );
        }

        return new \WP_REST_Response( array(
            'success' => false,
            'value' => null
        ), 404 );
    }

    public function get_coupon() {
        $result = array();
        $code = wc_clean(empty($code) ? stripslashes(sanitize_key($_GET['code'])) : $code);
        return $this->_get_coupon_internal($code);
    }
}