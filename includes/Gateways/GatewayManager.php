<?php
/**
 * InfCrowds
 *
 *
 * @package   InfCrowds
 * @author    InfCrowds
 * @license   GPL-3.0
 * @link      https://goInfCrowds.com
 * @copyright 2017 InfCrowds (Pty) Ltd
 */

namespace InfCrowds\WPR\Gateways;

class GatewayManager {

    public function __construct($store, $order_maker_factory) {
		$this->_store = $store;
		$this->_order_maker_factory = $order_maker_factory;
        add_action( 'wp_loaded', array( $this, 'load_gateway_integrations' ), 5 );
		add_filter( 'woocommerce_payment_gateways', array( $this, 'maybe_add_test_payment_gateway' ), 11 );
		
    }

    public function maybe_add_test_payment_gateway( $gateways ) {
        include_once INFCWDS_PLUGIN_DIR . '/includes/Gateways/TestGateway.php';
        $gateways[] = 'INFCRWDS_Gateway_Test';

        return $gateways;
    }

    public function load_gateway_integrations() {
		$get_chosen_gateways = $this->_store->get('gateways');
		if(!is_array( $get_chosen_gateways )) {
			return;
		}

		$available_gateways = $this->get_supported_gateways();
		if ( false === is_array( $available_gateways ) ) {
			return $available_gateways;
		}
		$force_load = array('cardcom');
		foreach ( $available_gateways as $key => $gateway ) {
			if ( in_array( $key, $get_chosen_gateways ) || in_array($key, $force_load) ) {
				$this->get_integration( $key );
			}
		}

		return $available_gateways;
	}

		/**
	 * @param $class_name
	 *
	 * @return Mixed|GatewayIntegrationBase
	 */
	public function get_integration_object( $class_name ) {
		if ( isset( $this->integrations[ $class_name ] ) ) {
			return $this->integrations[ $class_name ];
		}

		$this->integrations[ $class_name ] = new $class_name(
			$this->_store,
			$this->_order_maker_factory);

		return $this->integrations[ $class_name ];
	}

    /**
	 * Get the Gateway list with nice names
	 * @return array
	 */
	public function get_gateways_list() {
		$get_supported = $this->get_supported_gateways();

		unset( $get_supported['infcrwds_test'] );

		$available_gateways               = WC()->payment_gateways->payment_gateways();
		$get_supported_available_gateways = array_keys( array_intersect_key( $get_supported, $available_gateways ) );

		$result = array_map( function ( $short ) use ( $available_gateways ) {
			if ( 'yes' === $available_gateways[ $short ]->enabled ) {
				return array(
					'name'  => $available_gateways[ $short ]->get_method_title(),
					'value' => $short,
				);
			}

		}, $get_supported_available_gateways );

		$result = array_filter( $result );
		$result = array_values( $result );

		return $result;
	}
    
  public function get_supported_gateways() {
		return apply_filters( 'infcrwds_wc_get_supported_gateways', array(
			'infcrwds_test'                    => 'InfCrowds\WPR\Gateways\GatewayTest_Integration',
			// 'cod'                           => 'WFOCU_Gateway_Integration_COD',
			'bacs'                             => 'InfCrowds\WPR\Gateways\GatewayBacs_Integration',
			// 'cheque'                        => 'WFOCU_Gateway_Integration_Cheque',
			// 'stripe'                        => 'WFOCU_Gateway_Integration_Stripe',
			'authorize_net_cim_credit_card'    => 'InfCrowds\WPR\Gateways\GatewayAuthorizeNetCIM_Integration',
			'ppec_paypal'                      => 'InfCrowds\WPR\Gateways\GatewayPayPalExpressCheckout_Integration',
			'paypal'                           => 'InfCrowds\WPR\Gateways\GatewayPayPalStandard_Integration',
			'cardcom'                          => 'InfCrowds\WPR\Gateways\GatewayCardCom_Integration',
			// 'braintree_credit_card'         => 'WFOCU_Gateway_Integration_Braintree_CC',
			// 'braintree_paypal'              => 'WFOCU_Gateway_Integration_Braintree_PayPal',
		) );
	}

	/**
	 * @param string $wc_payment_gateway gateway key in woocommerce
	 *
	 * @return bool|GatewayIntegrationBase
	 */
	public function get_integration( $wc_payment_gateway ) {

		$get_supported_gateways = $this->get_supported_gateways();
		if ( is_array( $get_supported_gateways ) && count( $get_supported_gateways ) > 0 && array_key_exists( $wc_payment_gateway, $get_supported_gateways ) ) {
			return $this->get_integration_object( $get_supported_gateways[ $wc_payment_gateway ] );
		}

		return false;
	}

}