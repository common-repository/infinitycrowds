<?php
namespace InfCrowds\WPR;

use INFCWDS_WC_Compatibility;
/**
 * Class INFCrwds_Logger
 * @package InfCrwds
 * @author XlPlugins
 */
class INFCrwds_Logger {

	private static $ins = null;
	public $wc_logger = null;

	public function __construct($store) {
        $this->_enabled = $store->get('logs_enabled');
        if($this->_enabled) {
            add_action( 'init', array( $this, 'load_wc_logger' ) );
		}
		$this->_store = $store;
	}

	public function load_wc_logger() {
		$this->wc_logger = INFCWDS_WC_Compatibility::new_wc_logger();
	}


	public function log( $message, $level = 'info' ) {
		if ( $this->_enabled && is_a( $this->wc_logger, 'WC_Logger' ) && did_action( 'plugins_loaded' ) ) {
			$get_user_ip     = \WC_Geolocation::get_ip_address();
			$message_with_ip = $get_user_ip . ' ' . $message;
			$this->wc_logger->log( $level, $message_with_ip, array( 'source' => 'infcrwds' ) );
		}
		if($level === 'error') {
			try {
				$req = new ICRequest($this->_store);
				$req->post('/monitor', array(
					"message" => $message,
				));
			} catch(Exception $e) {
			}
		}
	}

	public function error($message ) {
		$this->log($message, 'error');
	}
}