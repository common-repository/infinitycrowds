<?php
/**
 *
 * Manages emails
 *
 * @class       EmailMgr
 * @version     1.0.0
 * @author      InfCrwds
 */

namespace InfCrowds\WPR\Emails;
use INFCWDS_WC_Compatibility;
use InfCrowds\WPR\ICRequest;


class EmailsMgr {

    /**
     * Constructor.
     */
    public function __construct($store) {
        add_action( 'woocommerce_email_actions', array( $this, 'add_email_actions' ), 999 );
		add_filter( 'woocommerce_email_classes', array( $this, 'add_email_class' ) );
		if($store->get('add_widget_to_order_recieved_email', false))
			add_action( 'woocommerce_email_after_order_table', array($this, 'add_widget_to_order_email'), 10, 2 );
		add_action('infcrwds_upsell_success_order_merged_with_parent', array($this, 'cancel_emails_for_empty_order'));
		$this->_store = $store;
    }
    
	function add_widget_to_order_email( $order, $is_admin_email ) {
		if($is_admin_email)
		{
			return;
		}
		$infcrwds_flag = INFCWDS_WC_Compatibility::get_order_meta($order, '_infcrwds');
        if(!empty($infcrwds_flag) && $infcrwds_flag === '1') {
			return;
		}
		$oid = INFCWDS_WC_Compatibility::get_order_id($order);
		$req = new ICRequest($this->_store);
		$url = add_query_arg( 'tu_url', $order->get_checkout_order_received_url(),
		'/widget/orders/' . $oid . '/offers/email');
		# allow some timeout here to ensure filled response
		$response = $req->get($url, null, array(
			'timeout' => 20
		));

		if($response !== false) {
			echo $response; 
		}
	}

	function cancel_emails_for_empty_order($merged_order) {
		try {
			if(sizeof($merged_order->get_items()) === 0) { 
				$wc_mails = WC()->mailer();
				$needs_processing = $merged_order->needs_processing();
				if ( true === $needs_processing ) {
					remove_action( 'woocommerce_order_status_failed_to_processing_notification', array( $wc_mails->emails['WC_Email_Customer_Processing_Order'], 'trigger' ), 10, 2 );
					remove_action( 'woocommerce_order_status_on-hold_to_processing_notification', array( $wc_mails->emails['WC_Email_Customer_Processing_Order'], 'trigger' ), 10, 2 );
					remove_action( 'woocommerce_order_status_pending_to_processing_notification', array( $wc_mails->emails['WC_Email_Customer_Processing_Order'], 'trigger' ), 10, 2 );
					
					remove_action( 'woocommerce_order_status_pending_to_processing_notification', array( $wc_mails->emails['WC_Email_New_Order'], 'trigger' ), 10, 2 );
					remove_action( 'woocommerce_order_status_cancelled_to_processing_notification', array( $wc_mails->emails['WC_Email_New_Order'], 'trigger' ), 10, 2 );
					remove_action( 'woocommerce_order_status_failed_to_processing_notification', array( $wc_mails->emails['WC_Email_New_Order'], 'trigger' ), 10, 2 );
					
					
					
					/**
					 * For cased when status is on hold but not processing , bank and cheque
					 */
					remove_action( 'woocommerce_order_status_pending_to_on-hold_notification', array( $wc_mails->emails['WC_Email_Customer_On_Hold_Order'], 'trigger' ), 10, 2 );
					remove_action( 'woocommerce_order_status_failed_to_on-hold_notification', array( $wc_mails->emails['WC_Email_Customer_On_Hold_Order'], 'trigger' ), 10, 2 );
				} else {
					remove_action( 'woocommerce_order_status_pending_to_completed_notification', array( $wc_mails->emails['WC_Email_New_Order'], 'trigger' ), 10, 2 );
					remove_action( 'woocommerce_order_status_failed_to_completed_notification', array( $wc_mails->emails['WC_Email_New_Order'], 'trigger' ), 10, 2 );

					remove_action( 'woocommerce_order_status_completed_notification', array( $wc_mails->emails['WC_Email_Customer_Completed_Order'], 'trigger' ), 10, 2 );

				}
			}
		 } catch ( Exception $e ) {
			InfcrwdsPlugin()->logger->error("cancel_email " . $e->getMessage());	
		}
	}
	
    /**
	 * Setting up dynamic events to fire emails at our event in WooCommerce way
	 * @hooked into `woocommerce_email_actions`
	 *
	 * @param array $email_actions
	 *
	 * @return mixed
	 */
	public function add_email_actions( $email_actions ) {
		
		array_push( $email_actions, 'infcrwds_upsell_success_order_updated' );

		return $email_actions;
	}
    /**
	 * Adding our custom Email to the WooCommerce Email Framework
	 * @hooked into `woocommerce_email_classes`
	 *
	 * @param WC_Email[] $email_classes
	 *
	 * @return mixed
	 */
	public function add_email_class( $email_classes ) {

		$email_classes['InfcrwdsOrderUpdatedEmail'] = new InfcrwdsOrderUpdatedEmail();
		// if(!$this->_store->get('defer_admin_email', false)) {
		$email_classes['InfcrwdsOrderUpdatedEmailAdmin'] = new InfcrwdsOrderUpdatedEmailAdmin();
		// }

		return $email_classes;
	}
}