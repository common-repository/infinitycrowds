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
class RestRouter
{
    /**
     * Initialize the plugin by setting localization and loading public scripts
     * and styles.
     *
     * @since     0.8.1
     */
    public function __construct($store)
    {
        $version = '1';
        $plugin = WPR\Plugin::get_instance();
        $plugin_slug = $plugin->get_plugin_slug();
        $this->namespace = $plugin_slug . '/v' . $version;

        $this->apis = null;
        
        $this->do_hooks();
    }

    /**
     * Set up WordPress hooks and filters
     *
     * @return void
     */
    public function do_hooks()
    {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Register the routes for the objects of the controller.
     */
    public function register_routes()
    {
        foreach ( $this->apis as $api) {
            $api->register_routes();
        }
    }
}
