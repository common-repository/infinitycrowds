<?php
/**
 * InfCrwds
 *
 *
 * @package   InfCrwds
 * @author    InfCrowds
 * @license   GPL-3.0
 * @link      https://goInfCrowds.com
 * @copyright 2017 InfCrowds (Pty) Ltd
 */
namespace InfCrowds\WPR;

use InfCrowds\WPR\Gateways\GatewayManager;
use InfCrowds\WPR\Emails\EmailsMgr;
use InfCrowds\WPR\Emails\DeferSendingWooCommerceEmails;
use INFCWDS_WC_Compatibility;

/**
 * @subpackage Plugin
 */
class Plugin implements IOrderMakerFactory {

	/**
	 * The variable name is used as the text domain when internationalizing strings
	 * of text. Its value should match the Text Domain file header in the main
	 * plugin file.
	 *
	 * @since    1.0.0
	 *
	 * @var      string
	 */
	protected $plugin_slug = 'infcrwds';

	/**
	 * Instance of this class.
	 *
	 * @since    1.0.0
	 *
	 * @var      object
	 */
	protected static $instance = null;
	public $store = null;
	/**
	 * Setup instance attributes
	 *
	 * @since     1.0.0
	 */
	private function __construct() {
		$this->plugin_version = INFCWDS_VERSION;
		$this->tracker = null;
		$this->logger = null;
		$this->gateway_mgr = null;
		$this->public_mgr = null;
		$this->offer_page = null;
		$this->offer_cart = null;
		$this->email_mgr = null;
	}
	
	public function basic_initialize($store) {
		$this->store = $store;
		$this->logger = new INFCrwds_Logger($this->store);
	}

	public function initialize($store) {
		$this->gateway_mgr = new GatewayManager($this->store, $this);
		$this->public_mgr = new PublicManager($this->gateway_mgr, $this->store);
		$this->offer_page = new OfferPage($this->store);
		$this->offer_cart = new OfferCartManager($this->store);
		// if($this->store->get('defer_admin_email', false)) {
		// 	$this->deffered_emails = new DeferSendingWooCommerceEmails();
		// }
		$this->email_mgr = new EmailsMgr($this->store);
		if($this->store->get('track', true)) {
			$this->tracker = new Tracker($this->gateway_mgr, $this->store);
		}
		$this->checkout_ext = new CheckoutExt($this->store);
	}

	/**
	 * Return the plugin slug.
	 *
	 * @since    1.0.0
	 *
	 * @return    Plugin slug variable.
	 */
	public function get_plugin_slug() {
		return $this->plugin_slug;
	}

	/**
	 * Return the plugin version.
	 *
	 * @since    1.0.0
	 *
	 * @return    Plugin slug variable.
	 */
	public function get_plugin_version() {
		return $this->plugin_version;
	}

	public function createOrderMaker($offer, $order, $support_merge=true) {
		// check if already exists, if does - create stub
		
		if(INFCWDS_WC_Compatibility::get_order_meta($order, '_infcrwds_internal_upsell') === 'yes' || 
			!empty(INFCWDS_WC_Compatibility::get_order_meta($order, '_infcrwds_sibling_order'))) {
			return new StubOrderMaker($order);
		}
		if($support_merge && $offer['offer']['merge_with_order']) {
			$date_created_dt = INFCWDS_WC_Compatibility::get_order_date($order);

            $timezone        = $date_created_dt->getTimezone(); // Get the timezone
			$date_created_ts = $date_created_dt->getTimestamp(); // Get the timestamp in seconds
			
			$now_dt = new \WC_DateTime(); // Get current WC_DateTime object instance
            $now_dt->setTimezone( $timezone ); // Set the same time zone
            $now_ts = $now_dt->getTimestamp(); // Get the current timestamp in seconds
            $one_hour = 60 * 60; // 1 hours in seconds
            
            $diff_in_seconds = $now_ts - $date_created_ts; // Get the difference (in seconds)
            $allow_merge = $diff_in_seconds < $one_hour;
			if($allow_merge)
				return new MergingOrderMaker($order, $offer);
		}
		return new NewOrderMaker($this->store, $offer, $order);
	}
	/**
	 * Fired when the plugin is activated.
	 *
	 * @since    1.0.0
	 */
	public static function activate($arg) {
		$p = Plugin::get_instance();
		$store = new Store();
		$p->basic_initialize($store);
		$p->initialize($store);
		$updated = update_option('infcrwds_global_settings', array(
			'ic_server_baseurl' => 'https://api.infinitycrowds.com/api/v1',
			'gateways' => array_keys($p->gateway_mgr->get_supported_gateways()),
			'is_internal_upsell_enabled' => true,
			"is_pp_ref_trans_enabled" => false,
			"add_widget_to_order_recieved_email" => true,
			"logs_enabled" => false,
			"track" => true,
			"defer_admin_email" => false,
		));
		$p->store->refresh();
	}

	/**
	 * Fired when the plugin is deactivated.
	 *
	 * @since    1.0.0
	 */
	public static function deactivate($arg) {
		$p = Plugin::get_instance();
		$req = new ICRequest($p->store);
		$req->post('/merchants/logout/plugin', array(
			"reason" => null,
			"is_deactivate" => true
		), array(
			'HTTP-CLIENT-IP' => Commons::GetIP(),
		), array('user-agent' => wc_get_user_agent()));
		delete_option('infcrwds_global_settings');
	}

	/**
	 * Return an instance of this class.
	 *
	 * @since     1.0.0
	 *
	 * @return    object    A single instance of this class.
	 */
	public static function get_instance() {

		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}
}