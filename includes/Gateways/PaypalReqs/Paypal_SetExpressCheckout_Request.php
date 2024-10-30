<?php
namespace InfCrowds\WPR\Gateways\PaypalReqs;
use INFCWDS_WC_Compatibility;
use \InfCrowds\WPR\OrderHelpers;

class Paypal_SetExpressCheckout_Request extends Paypal_Base_Request {

    public function __construct(
        $deprecated_params, 
        $paypal_integration, $session_id=null) {
        parent::__construct($deprecated_params,
            $paypal_integration, 
            $session_id);
        $this->_args = null;
    }

    public function set_reference_transaction() {
        $this->add_parameter( 'L_BILLINGTYPE0', $this->_args['billing_type'] );
        $this->add_parameter( 'L_BILLINGAGREEMENTDESCRIPTION0', get_bloginfo( 'name' ) );
        $this->add_parameter( 'L_BILLINGAGREEMENTCUSTOM0', '' );
    }

    public function initialize($currency,
        $return_url,
        $cancel_url,
        $notify_url, 
        $has_shipping,
        $landing='Login',
        $payment_action='Sale',
        $method='SetExpressCheckout') {

        $this->_currency = $currency;
        $this->_has_shipping = $has_shipping;
        
        $args = array(
            'currency'    => $currency,
            'return_url'  => $return_url,
            'cancel_url'  => $cancel_url,
            'notify_url'  => $notify_url,
            'no_shipping' => $has_shipping ? 0 : 1,
        );

        $default_description = sprintf( _x( 'Orders with %s', 'data sent to paypal', 'woocommerce-subscriptions' ), get_bloginfo( 'name' ) );

        $defaults = array(
            'currency'            => get_woocommerce_currency(),
            'billing_type'        => 'MerchantInitiatedBillingSingleAgreement',
            'billing_description' => html_entity_decode( apply_filters( 'woocommerce_subscriptions_paypal_billing_agreement_description', $default_description, $args ), ENT_NOQUOTES, 'UTF-8' ),
            'maximum_amount'      => null,
            'no_shipping'         => 1,
            'page_style'          => null,
            'brand_name'          => html_entity_decode( get_bloginfo( 'name' ), ENT_NOQUOTES, 'UTF-8' ),
            'landing_page'        => $landing,
            'payment_action'      => 'Sale',
            'custom'              => '',
            'addressoverride'     => '1',
        );
        
        $this->_args = wp_parse_args( $args, $defaults );

        $this->set_method($method);

        $this->add_parameters( array(
            'RETURNURL'   => $this->_args['return_url'],
            'CANCELURL'   => $this->_args['cancel_url'],
            'PAGESTYLE'   => $this->_args['page_style'],
            'BRANDNAME'   => $this->_args['brand_name'],
            'LANDINGPAGE' => $this->_args['landing_page'], // 'Billing',
            'NOSHIPPING'  => $this->_args['no_shipping'],

            'ADDROVERRIDE' => $this->_args['addressoverride'],
            'MAXAMT'       => $this->_args['maximum_amount'],
        ) );
    }


    // public function set_overall_params(
    //     $shipping_price,
    //     $shipping_diff,
    //     $order,
    //     $taxes,
    //     $oid_as_inv_num=true,
    //     $total_amount=null) {

    //     $order_num = OrderHelpers::get_order_number($order, $this->session_id);
    //     $inv_num = $this->_paypal_integration->get_wc_gateway()->get_option( 'invoice_prefix' ) . $order_num;
    //     if($total_amount === null) {
    //         $total_amount = $this->round($this->total_amount + $shipping_price + $shipping_diff + ($taxes === null ? 0 : $taxes));
    //     }
    //     $order_subtotal = $this->round($this->total_amount);

    //     $arr = array(
    //         'AMT'              => $total_amount,
    //         'CURRENCYCODE'     => $this->_currency,
    //         'ITEMAMT'          => $order_subtotal,
    //         'INVNUM'           => $inv_num,
    //         'PAYMENTACTION'    => $this->_args['payment_action'],
    //         'PAYMENTREQUESTID' => INFCWDS_WC_Compatibility::get_order_id( $order ),
    //         'CUSTOM'           => json_encode( array(
    //             '_infcrwds_o_id'       => $oid_as_inv_num ? $inv_num : INFCWDS_WC_Compatibility::get_order_id( $order ),
    //             '_infcrwds_session_id' => $this->session_id,
    //             ) ),
    //         );
    //     if($taxes !== null) {
    //         $arr['TAXAMT'] = $taxes;
    //     }

        /**
         * When shipping amount is a negative number, means user opted for free shipping offer
         * In this case we setup shippingamt as 0 and shipping discount amount is that negative amount that is coming.
         */
    //     if ( $this->_has_shipping && 0 > $shipping_diff ) {
    //         $arr['SHIPPINGAMT'] = 0;
    //         $arr['SHIPDISCAMT'] = $shipping_diff;
    //     }
    //     else {
    //         $arr['SHIPPINGAMT'] = $shipping_price;
    //     }


    //     if($this->_deprecated_params) {
    //         $this->add_parameters($arr);
    //     }
    //     else {
    //         $this->add_payment_parameters($arr);
    //     }

    //     if($this->_has_shipping) {
    //         $shipping_params = $this->_paypal_integration->get_shipping_address_params($order, $this->_shipping_prefix);
    //         foreach ( $shipping_params as $key => $val ) {
    //             $this->add_parameter( $key, $val );
    //         }
    //     }
    // }

    protected function on_response($response) {
        if ( isset( $response['TOKEN'] ) && '' !== $response['TOKEN'] ) {
            return $response['TOKEN'];
        }
        else {
            $this->error_reason = $this->get_api_error( $response );
        }
        return false;
    }
}
