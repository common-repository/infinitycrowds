<?php


namespace InfCrowds\WPR\Endpoint;


class CustomerApi extends BaseApi
{
    function __construct($ns)
    {
        parent::__construct($ns, '/customers/');
    }


    public function register_routes()
    {
        $this->add_route('get_possible_rule_values', array() ,$suffix='search/');
    }

    public function get_possible_rule_values() {
        $result = array();
        $term = wc_clean(empty($term) ? stripslashes($_GET['term']) : $term);

        $users = get_users(array('search' => $term));

        if ( $users ) {
            foreach ( $users as $user ) {
                array_push($result, array("id" => $user->ID, "name"=> $user->display_name));
            }
        }

        return new \WP_REST_Response( array(
            'success' => true,
            'value' => $result
        ), 200 );
    }
}
