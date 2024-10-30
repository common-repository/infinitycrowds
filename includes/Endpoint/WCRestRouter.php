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


use InfCrowds\WPR;

/**
 * @subpackage REST_Controller
 */
class WCRestRouter extends RestRouter
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
            new SettingsApiRest($store, $gateway_mgr),
        );
    }
}
