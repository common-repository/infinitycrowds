<?php

/**
 * InfCrowds
 *
 *
 * @package   Infcrowds
 * @author    Infcrowds
 * @license   GPL-3.0
 * @link      https://infinitycrowds.com
 * @copyright 2017 Infcrowds (Pty) Ltd
 */

namespace InfCrowds\WPR\Endpoint;
use \InfCrowds\WPR\Endpoint\Gateways\PaypalRefTransactionCheckoutApi;
use \InfCrowds\WPR\Endpoint\Gateways\PaypalInContextCheckoutApi;
use \InfCrowds\WPR\Endpoint\Gateways\PaypalExpressCheckoutRefTransactionApi;
use \InfCrowds\WPR\Endpoint\Gateways\AuthorizeNetCIMApi;
use \InfCrowds\WPR\Endpoint\Gateways\CardComApi;
use \InfCrowds\WPR\Endpoint\Gateways\TestGatewayApi;
use \InfCrowds\WPR\Endpoint\Gateways\BacsApi;

use InfCrowds\WPR;

/**
 * @subpackage REST_Controller
 */
class PublicRestRouter extends RestRouter
{
    /**
     * Initialize the plugin by setting localization and loading public scripts
     * and styles.
     *
     * @since     0.8.1
     */
    public function __construct($store, $gateway_mgr, $offer_cart_mgr)
    {
        parent::__construct($store);
        $namespace = $this->namespace;
        $this->apis = array(
            new CalculateOfferApi($namespace, $store, $gateway_mgr),
            new AcceptOfferApi($namespace, $store, $offer_cart_mgr),
            new PaypalRefTransactionCheckoutApi($namespace, $store, $gateway_mgr),
            new PaypalInContextCheckoutApi($namespace, $store, 'ppec_paypal', $gateway_mgr),
            new PaypalInContextCheckoutApi($namespace, $store, 'paypal', $gateway_mgr),
            new TestGatewayApi($namespace, $store, $gateway_mgr),
            new PaypalExpressCheckoutRefTransactionApi($namespace, $store, $gateway_mgr),
            new AuthorizeNetCIMApi($namespace, $store, $gateway_mgr),
            new CardComApi($namespace, $store, $gateway_mgr),
            new BacsApi($namespace, $store, $gateway_mgr));
    }
}
