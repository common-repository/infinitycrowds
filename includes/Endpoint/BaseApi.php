<?php


namespace InfCrowds\WPR\Endpoint;
use mysql_xdevapi\Exception;
use InfCrowds\WPR;

abstract class BaseApi
{

    public function __construct($namespace, $endpoint) {
        $this->endpoint = $endpoint;
        $this->namespace = $namespace;
    }
    /**
     * Check if a given request has access to update a setting
     *
     * @param WP_REST_Request $request Full data about the request.
     * @return WP_Error|bool
     */
    public function woocommerce_permission_check($request)
    {
        return current_user_can('manage_woocommerce');
    }

    public abstract function register_routes();

    protected function add_route($func, $args=array(), $suffix='') {
        register_rest_route($this->namespace, $this->endpoint . $suffix, array(
            array(
                'methods' => \WP_REST_Server::READABLE,
                'callback' => array($this, $func),
                'permission_callback' => array($this, 'woocommerce_permission_check'),
                'args' => $args,
            ),
        ));
    }

    protected function ensure_session() {
        if ( null === WC()->session ) {
            if ( defined( 'WC_ABSPATH' ) ) {
                // WC 3.6+ - Cart and notice functions are not included during a REST request.
                include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
                include_once WC_ABSPATH . 'includes/wc-notice-functions.php';
            }
            $session_class = apply_filters( 'woocommerce_session_handler', 'WC_Session_Handler' );
            WC()->session = new $session_class();
            WC()->session->init();
            
            if ( null === WC()->customer ) {
                WC()->customer = new \WC_Customer( get_current_user_id(), true );
            }
            if ( null === WC()->cart ) {

                WC()->cart = new \WC_Cart();
            }
        }
    }

    protected function add_create_route($func, $args=array(), $suffix='') {
        register_rest_route($this->namespace, $this->endpoint . $suffix, array(
            array(
                'methods'  => \WP_REST_Server::CREATABLE,
                'callback' => array($this, $func),
                'permission_callback' => array($this, 'woocommerce_permission_check'),
                'args' => $args,
            ),
        ));
    }
}