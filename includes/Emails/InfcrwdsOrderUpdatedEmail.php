<?php
/**
 * Customer Updated Order Email.
 *
 * An email sent to the customer when an order is updated by the upsell offer acceptance.
 *
 * @class       INFCRWDS_WC_Email_Updated_Order
 * @version     1.0.0
 * @author      InfCrwds
 * @extends     WC_Email
 */
namespace InfCrowds\WPR\Emails;

use INFCWDS_WC_Compatibility;

 
class InfcrwdsOrderUpdatedEmail extends \WC_Email {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->id             = 'infcrwds_customer_updated_order';
        $this->customer_email = true;

        $this->title          = __( 'Updated order', 'woocommerce' );
        $this->description    = __( 'This is an order email sent to customer when he/she accepts any upsell offer and the current order is updated.', 'woocommerce' );
        $this->template_html  = 'infcrwds-customer-updated-order.php';
        $this->template_plain = 'infcrwds-customer-updated-order-plain.php';
        $this->template_base  = INFCWDS_PLUGIN_DIR . '/views/emails/templates/';
        $this->placeholders   = array(
            '{site_title}'   => $this->get_blogname(),
            '{order_date}'   => '',
            '{order_number}' => '',
        );

        add_action( 'infcrwds_upsell_success_order_updated_notification', array( $this, 'fire_order_updated_mail' ), 999, 1 );

        // Call parent constructor
        parent::__construct();
    }

    /**
     * Get email subject.
     *
     * @since  3.1.0
     * @return string
     */
    public function get_default_subject() {
        return __( 'Your {site_title} order {order_number} has been updated', 'woocommerce' );
    }

    /**
     * Get email heading.
     *
     * @since  3.1.0
     * @return string
     */
    public function get_default_heading() {
        return __( 'Your order has been updated', 'woocommerce' );
    }

    public function fire_order_updated_mail( $order ) {

        $this->trigger( INFCWDS_WC_Compatibility::get_order_id( $order ), $order );
    }

    /**
     * Trigger the sending of this email.
     *
     * @param int $order_id The order ID.
     * @param WC_Order $order Order object.
     */
    public function trigger( $order_id, $order = false ) {
        $this->setup_locale();

        if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
            $order = wc_get_order( $order_id );
        }

        if ( is_a( $order, 'WC_Order' ) ) {
            $this->object                         = $order;
            $this->recipient                      = $this->object->get_billing_email();
            $this->placeholders['{order_date}']   = wc_format_datetime( $this->object->get_date_created() );
            $this->placeholders['{order_number}'] = $this->object->get_order_number();
        }

        if ( $this->is_enabled() && $this->get_recipient() ) {
            $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
        }

        $this->restore_locale();
    }

    /**
     * Get content html.
     *
     * @access public
     * @return string
     */
    public function get_content_html() {

        ob_start();
        wc_get_template( $this->template_html, array(
            'order'         => $this->object,
            'email_heading' => $this->get_heading(),
            'sent_to_admin' => false,
            'plain_text'    => false,
            'email'         => $this,
        ), '', $this->template_base );

        return ob_get_clean();

    }

    /**
     * Get content plain.
     *
     * @access public
     * @return string
     */
    public function get_content_plain() {
        ob_start();
        wc_get_template( $this->template_html, array(
            'order'         => $this->object,
            'email_heading' => $this->get_heading(),
            'sent_to_admin' => false,
            'plain_text'    => true,
            'email'         => $this,
        ), '', $this->template_base );

        return ob_get_clean();
    }
}

