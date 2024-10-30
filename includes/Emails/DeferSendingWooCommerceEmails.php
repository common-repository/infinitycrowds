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

class DeferSendingWooCommerceEmails {
	private static $instance;
	private $default_defer_time;
	// An associative array to match $email_id with email class, to allow for the deferring of different emails.
	private $email_id_to_class;


	// Returns an instance of this class. 
	public static function get_instance() {
		if ( null == self::$instance ) {
			self::$instance = new DeferSendingWooCommerceEmails();
		} 
		return self::$instance;
	}


	// Initialize the plugin variables.
	public function __construct() {
		$this->default_defer_time = 3600; // Defer for 3600 seconds (1 hour).
		// Can add other email IDs and their associated email class names.
		// This is also the list of emails that will be deferred.
		$this->email_id_to_class = array( 
								// 'woocommerce_order_status_completed' => array( 'WC_Email_Customer_Completed_Order', $this->default_defer_time ),
								// Probably don't want to defer these emails but they are shown
								// here as a demo of a different defer time.			
								//'woocommerce_order_status_pending_to_on-hold' => array( 'WC_Email_New_Order', $this->default_defer_time ),  // New order
                                'woocommerce_order_status_pending_to_processing' => array( 'WC_Email_New_Order', $this->default_defer_time ),
								'woocommerce_order_status_failed_to_processing' => array( 'WC_Email_New_Order', $this->default_defer_time ),  
								'woocommerce_order_status_pending_to_completed' => array( 'WC_Email_New_Order', $this->default_defer_time ),
								// Additional transitition-to-email class info from @djm56
								//'woocommerce_order_status_on-hold' => array('WC_Email_Customer_On_Hold_Order', $this->default_defer_time),  // Order on hold.
								//'woocommerce_order_status_pending_to_on-hold' => array('WC_Email_Customer_On_Hold_Order', $this->default_defer_time),  // Order on hold.
								//'woocommerce_order_status_cancelled_to_on-hold' => array('WC_Email_Customer_On_Hold_Order', $this->default_defer_time),  // Order on hold.
								//'woocommerce_order_status_pending_to_on-hold' => array('WC_Email_Customer_On_Hold_Order', $this->default_defer_time),  // Order on hold.
								);

		$this->non_delayed_emails_id_to_class = array( 
			'woocommerce_order_status_pending_to_processing' => 'WC_Email_Customer_Processing_Order',
			'woocommerce_order_status_failed_to_processing' => 'WC_Email_Customer_Processing_Order',
			'woocommerce_order_status_pending_to_completed' => 'WC_Email_Customer_Completed_Order',
		);

		$this->init();
	}


	// Set up WordPress specfic actions.
	public function init() {
		// Set all WooCommerce emails to be deferred.
		add_filter( 'woocommerce_defer_transactional_emails', '__return_true' );

		// Allow most emails to be sent as normal but prevent emails listed in $this->email_id_to_class. Schedule them for a time in the future.
		add_filter( 'woocommerce_allow_send_queued_transactional_email', array( $this, 'whether_send_queued_wc_email' ), 10, 3 );

		// This is the scheduled function that will send the email.
		add_action( 'send_deferred_woocommerce_email', array( $this, 'send_deferred_woocommerce_email' ), 10, 2 );
		add_action( 'send_deferred_not_delayed_woocommerce_email', array( $this, 'send_deferred_not_delayed__woocommerce_email' ), 10, 2 );

		// DEBUG: Add the order modification time and current time to prove that the email was intentionally delayed.
		//add_action( 'woocommerce_email_order_details', array( $this, 'add_defer_length_info_to_order_email' ), 5, 4 ); 
	}
	
	private function get_email_class( $email_id ) {
		if ( array_key_exists( $email_id, $this->email_id_to_class ) ) {
			return $this->email_id_to_class[ $email_id ][ 0 ];
		}
		else {
			return null;
		}
	}

	private function get_not_delayed_email_class( $email_id ) {
		if ( array_key_exists( $email_id, $this->non_delayed_emails_id_to_class ) ) {
			return $this->non_delayed_emails_id_to_class[ $email_id ];
		}
		else {
			return null;
		}
	}

	private function get_email_defer_time( $filter ) {
		if ( array_key_exists( $filter, $this->email_id_to_class ) ) {
			return $this->email_id_to_class[ $filter ][ 1 ];
		}
		else {
			return $this->default_defer_time;
		}
	}
	
	
	public function whether_send_queued_wc_email( $true, $filter, $args ) {
		//error_log( 'woocommerce_allow_send_queued_transactional_email $filter: ' . var_export( $filter, true ) );
		//error_log( 'woocommerce_allow_send_queued_transactional_email order_number: ' . var_export( $args[ 0 ], true ) );
		InfcrwdsPlugin()->logger->log('enqueue send wc email '. $filter . ' ' . $args[0]);
		if ( array_key_exists( $filter, $this->email_id_to_class ) ) {
			// TODO: Consider verifying that $args[0] is a valid order number.
			$order = wc_get_order( $args[ 0 ] );
			if (! is_a( $order, 'WC_Order' ) ) {
				return false;
			}
			$order_num = $args[ 0 ];
			$schedule_res = wp_schedule_single_event( time() + $this->get_email_defer_time( $filter ), 'send_deferred_woocommerce_email', array( $order_num, $filter ) );
			if($schedule_res === false){
				InfcrwdsPlugin()->logger->log('delayed events error scheduling: '. $order_num);
				return $true;
			}

			if( array_key_exists($filter, $this->non_delayed_emails_id_to_class)) {
				try {
					$schedule_res = wp_schedule_single_event( time() + 5, 'send_deferred_not_delayed_woocommerce_email', array( $order_num, $filter ) );
					if($schedule_res === false) {
						InfcrwdsPlugin()->logger->log('not delayed events error scheduling: '. $order_num);
						WC()->mailer()->get_emails()[ $this->get_email_class( $filter ) ]->trigger( $order_num );
					} 
					else {
						InfcrwdsPlugin()->logger->log('not delayed emails scheduling for 5 sec: '. $filter);
					}
				} catch (\Exception $e) {
					InfcrwdsPlugin()->logger->error('error scheduling not delayed emails 5 sec: ' . $e->getMessage());
				}
			} 
			// else {
				// InfcrwdsPlugin()->logger->log('no not delayed emails found: '. $filter);
			// }
			//error_log( sprintf( 'woocommerce_allow_send_queued_transactional_email: Defer a %s email for order: %s for %d seconds.', $filter, $order_num, $this->get_email_defer_time( $filter ) ) );

			return false;
		}

		//error_log( 'woocommerce_allow_send_queued_transactional_email: Ok to send email.' );

		return $true;
	}


	// Send the deferred email for order $order_id.
	function send_deferred_woocommerce_email( $order_id, $email_id ) {
		//error_log( 'send_deferred_woocommerce_email for order: ' . $order_id );
		$order = wc_get_order( $order_id );
		if ( is_a( $order, 'WC_Order' ) ) {
			InfcrwdsPlugin()->logger->log('got to send delayed emails '. $email_id . 'order' . $order_id);
			WC()->mailer()->get_emails()[ $this->get_email_class( $email_id ) ]->trigger( $order_id );
		} else {
			InfcrwdsPlugin()->logger->log('none order'. $order_id);
		}
	}

	function send_deferred_not_delayed__woocommerce_email( $order_id, $email_id ) {
		$order = wc_get_order( $order_id );
		if ( is_a( $order, 'WC_Order' ) ) {
			InfcrwdsPlugin()->logger->log('got to send not delayed emails '. $email_id . 'order' . $order_id);
			try {
				WC()->mailer()->get_emails()[ $this->get_not_delayed_email_class( $email_id ) ]->trigger( $order_id );
			} catch (\Exception $e) {
				InfcrwdsPlugin()->logger->error('error sending not delayed emails: ' . $e->getMessage());
			}
		}
		else {
			InfcrwdsPlugin()->logger->log('none order'. $order_id);
		}
	}


	// This is an experimental function to add the date/time the order was modified and
	// the date/time the email was sent into email - to demonstrate that the deferring code worked.
	public function add_defer_length_info_to_order_email( $order, $sent_to_admin, $plain_text, $email ) {
		if ( 'customer_completed_order' == $email->id ) {
			if ( $plain_text ) {
				printf( '%sThe order was modified at %s and email sent at %s.', "\n", $order->get_date_modified(), current_time( 'mysql' ) );
			}
			else {
				printf( '<p>The order was modified at <strong>%s</strong> and email sent at <strong>%s</strong>.</p>', $order->get_date_modified(), current_time( 'mysql' ) );
			}
		}
	}
}
