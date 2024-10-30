<?php


namespace InfCrowds\WPR\Endpoint;


class ItemTypeApi extends BaseApi
{
    function __construct($ns)
    {
        parent::__construct($ns, '/product-types/');
    }


    public function register_routes()
    {
        $this->add_route('get_possible_item_types');
    }

    public function get_possible_item_types() {
        $result = array();
        $terms = get_terms( 'product_type', array( 'hide_empty' => false ) );
        if ( $terms && ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                if ( 'grouped' === $term->name ) {
                    continue;
                }
                array_push($result, array("id" =>$term->term_id, "name"=> $term->name));
            }
        }

        return new \WP_REST_Response( array(
            'success' => true,
            'value' => $result
        ), 200 );
    }
}