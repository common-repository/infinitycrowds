<?php
namespace InfCrowds\WPR\Endpoint;
use \InfCrowds\WPR\ICRequest;


class ICAuthApi extends BaseApi {

    function __construct($ns, $store)
    {
        parent::__construct($ns, '/auth');
        $this->_store = $store;
    }

    public function register_routes()
    {
        $this->add_create_route('sign', array(), '/sign');
        $this->add_create_route('authenticate', array(), '/sig');
    }

    public function authenticate($req) {
        $request = new ICRequest($this->_store);
        $response = $request->post('/auth/sig', array("date" => gmdate('D, d M Y H:i:s T')));
        if($response === false) {
            return new \WP_REST_Response( array(
                'success' => false,
                'value' => null
            ), 400 );
        }
        else {
            $resp = json_decode($response);
            $this->_store->set('ic_merchant_id', $resp->merchant_id);
            return new \WP_REST_Response( array(
                'success' => true,
                'value' => $resp,
            ), 200 );
        }
    }
    public function sign($request) {
        $sig_str = $request->get_param('sig_str');
        $secret = get_option('ic_secret');
        if(empty($secret)) {
            return new \WP_REST_Response( array(
                'success' => false,
                'value' => null
            ), 400 );
        }
        $Sig = hash_hmac('sha256', $sig_str, $secret);

        return new \WP_REST_Response( array(
            'success' => false,
            'value' => $Sig
        ), 200 );
    }
}