<?php
/**
 * Author INFCWDS.
 */


if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * INFCRWDS_Gateway_Test Test Gateway.
 *
 * Provides a Test gateway to test infcrwds funnels.
 *
 * @class        INFCRWDS_Gateway_Test
 * @extends        WC_Payment_Gateway
 */
class INFCRWDS_Gateway_Test extends WC_Payment_Gateway {

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        // Setup general properties
        $this->setup_properties();

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();
        $this->enabled = 'yes';
        // Get settings
        $this->title              = __( 'Test Gateway By Infinitycrowds', 'infinity-crowds-one-click-upsell' );
        $this->description        = __( 'This gateway is registered by Infinitycrowds for testing Funnels. This is only visible to Admins and not end users,', 'infinity-crowds-one-click-upsell' );
        $this->instructions       = '';
        $this->enable_for_methods = array();
        $this->enable_for_virtual = true;
        $this->supports           = array(
            'products',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change',
            'subscription_payment_method_change_customer',
            'subscription_payment_method_change_admin',
            'multiple_subscriptions',
            'refunds',
        );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );

        // Customer Emails
        add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
    }

    /**
     * Setup general properties for the gateway.
     */
    protected function setup_properties() {
        $this->id                 = 'infcrwds_test';
        $this->icon               = '';
        $this->method_title       = __( 'Test Gateway', 'woocommerce' );
        $this->method_description = '';
        $this->has_fields         = false;
    }

    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function init_form_fields() {

        $this->form_fields = array();
    }

    /**
     * Init settings for gateways.
     */
    public function init_settings() {

        $this->enabled = 'yes';
    }

    /**
     * Check If The Gateway Is Available For Use.
     *
     * @return bool
     */
    public function is_available() {

        $order          = null;
        $needs_shipping = false;
        $is_gateway_on  = array('yes');//WFOCU_Core()->data->get_option( 'gateway_test' );

        if ( is_array( $is_gateway_on ) && count( $is_gateway_on ) > 0 && 'yes' === $is_gateway_on[0] ) {

            if ( false === current_user_can( 'manage_woocommerce' ) ) {

                return false;
            }

            return parent::is_available();
        } else {
            return false;
        }
    }

    /**
     * Process the payment and return the result.
     *
     * @param int $order_id
     *
     * @return array
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        $order->payment_complete();

        // Remove cart
        WC()->cart->empty_cart();

        // Return thankyou redirect
        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }

    /**
     * Output for the order received page.
     */
    public function thankyou_page() {
        if ( $this->instructions ) {
            echo wpautop( wptexturize( $this->instructions ) );
        }
    }

    /**
     * Add content to the WC emails.
     *
     * @access public
     *
     * @param WC_Order $order
     * @param bool $sent_to_admin
     * @param bool $plain_text
     */
    public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
        if ( $this->instructions && ! $sent_to_admin && $this->id === $order->get_payment_method() ) {
            echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
        }
    }

    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        return true;
    }
}
