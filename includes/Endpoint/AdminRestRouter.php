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
class AdminRestRouter extends RestRouter
{
    /**
     * Initialize the plugin by setting localization and loading public scripts
     * and styles.
     *
     * @since     0.8.1
     */
    public function __construct($store, $gateway_mgr)
    {
        parent::__construct($store);
        $namespace = $this->namespace;
        $this->apis = array(
            new ProductCategoriesApi($namespace),
            new ProductsApi($namespace),
            new ProductTagsApi($namespace),
            new ItemTypeApi($namespace),
            new CustomerApi($namespace),
            new CustomerRolesApi($namespace),
            new CountriesApi($namespace),
            new SettingsApi($namespace, $store, $gateway_mgr),
            new ICAuthApi($namespace, $store),
        );
    }
}
