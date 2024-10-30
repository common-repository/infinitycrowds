<?php


namespace InfCrowds\WPR\Endpoint;


class CountriesApi extends BaseApi
{
    function __construct($ns)
    {
        parent::__construct($ns, '/countries/');
    }


    public function register_routes()
    {
        $this->add_route('get_possible_rule_values');
    }


    public function get_possible_rule_values() {

        $result = WC()->countries->get_allowed_countries();

        return new \WP_REST_Response( array(
            'success' => true,
            'value' => $result
        ), 200 );
    }
}