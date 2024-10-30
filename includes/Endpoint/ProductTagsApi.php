<?php

/**
 * InfCrowds
 *
 *
 * @package   InfCrowds
 * @author    InfCrowds
 * @license   GPL-3.0
 * @link      https://InfCrowds.com
 * @copyright 2017 InfCrowds (Pty) Ltd
 */

namespace InfCrowds\WPR\Endpoint;

use InfCrowds\WPR;

/**
 * @subpackage REST_Controller
 */
class ProductTagsApi extends BaseApi
{
    function __construct($ns)
    {
        parent::__construct($ns, '/product-tags/');
    }

    /**
     * Register the routes for the objects of the controller.
     */
    public function register_routes()
    {
        $this->add_route('get_product_tags');
    }


    public function get_product_tags() {
        $result = array();

        $terms = get_terms( 'product_tag', array( 'hide_empty' => false ) );
        if ( $terms && ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                array_push($result, array("id" => $term->term_id, "name"=> $term->name));

            }
        }

        return new \WP_REST_Response( array(
            'success' => true,
            'value' => $terms
        ), 200 );
    }
}
