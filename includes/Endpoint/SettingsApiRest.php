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
use InfCrowds\WPR;


class SettingsApiRest extends \WC_REST_Controller
{
     /**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
    protected $namespace = 'wc/v2';
    
    /**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'infcrwds-settings';

    	/**
	 * Post type.
	 *
	 * @var string
	 */
	// protected $post_type = 'infcrwds-settings';
    /**
	 * If object is hierarchical.
	 *
	 * @var bool
	 */
	protected $hierarchical = true;

    function __construct($store, $gw_manager)
    {
        $this->_store = $store;
        $this->gateway_mgr = $gw_manager;
    }


    // public function register_routes()
    // {
    //     $this->add_route('get_setting', array());
    //     $this->add_create_route('set_setting', array());
    //     $this->add_route('get_supported_gateways', array(), $suffix='gateways');
    // }

    public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_setting' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_setting' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(),
				),
				// 'schema' => array( $this, 'get_public_item_schema' ),
			)
        );
        register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/gateways',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_supported_gateways' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(),
				),
				// 'schema' => array( $this, 'get_public_item_schema' ),
			)
        );
    }

    public function get_setting() {
        $keys = $_GET['key'];
        if(!is_array($keys)) {
            $keys = array($keys);
        }
        if (empty($keys)) {
            return new \WP_REST_Response( array(
                'success' => false,
                'value' => $keys
            ), 400 );
        }
        $res = array();

        foreach ($keys as $key) {
            $res[$key] = $this->_store->get(sanitize_key($key));
        }

        return new \WP_REST_Response( array(
            'success' => true,
            'value' => $res
        ), 200 );
    }
    public function get_supported_gateways($request) {
        
        return new \WP_REST_Response( array(
            'success' => 200,
            'value' => array_keys($this->gateway_mgr->get_supported_gateways())
        ), 200 );
    }

    public function set_setting( $request ) {
        $params = $request->get_json_params();
        if (empty($params)) {
            return new \WP_REST_Response( array(
                'success' => false,
                'value' => null
            ), 400 );
        }
        foreach ($params as $key => $value) {
            $this->_store->set( $key, $value );
        }
        return new \WP_REST_Response( array(
            'success'   => true,
            'value'     => $params
        ), 200 );
    }

    /**
	 * Makes sure the current user has access to READ the settings APIs.
	 *
	 * @since  3.0.0
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|boolean
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! wc_rest_check_manager_permissions( 'settings', 'read' ) ) {
			return new WP_Error( 'woocommerce_rest_cannot_view', __( 'Sorry, you cannot list resources.', 'woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
    }

    /**
	 * Makes sure the current user has access to READ the settings APIs.
	 *
	 * @since  3.0.0
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|boolean
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! wc_rest_check_manager_permissions( 'settings', 'write' ) ) {
			return new WP_Error( 'woocommerce_rest_cannot_view', __( 'Sorry, you cannot list resources.', 'woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
    }
}
