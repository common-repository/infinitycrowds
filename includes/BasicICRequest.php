<?php
/**
 * WP-Reactivate
 *
 *
 * @package   InfinityCrowds
 * @author    InfinityCrowds
 * @license   GPL-3.0
 * @link      https://infinitycrowds.com
 * @copyright 2019 InfinityCrowds (Pty) Ltd
 */

namespace InfCrowds\WPR;

class BasicICRequest {

    public function __construct($store) {
        $this->_base_url = $store->get('ic_server_baseurl');
        $this->_host = $store->get('__cache__ic_server_host', false);

        if(!$this->_host) {
            $this->_host = parse_url($this->_base_url)['host'];
            $store->set('__cache__ic_server_host', $this->_host);
        }
        
        $this->err = null;
        $this->err_n = null;
        $this->server_output = null;
        $this->invalid = empty($this->_base_url);
        if($this->invalid) {
            $this->err = 'Invalid';
            $this->err_n = -1;
        }
    }
    public function allow_local($is_external, $host, $url) {
        return true;
    }
    protected function _prep_headers($headers, $path, $data) {
        return $headers;
    }

    private function _prep($path, $data, $extra_headers = null, $method='POST', $default_args=null) {
        // curl_setopt($this->_ch, CURLOPT_URL, $this->_base_url . $path);
        // curl_setopt($this->_ch, CURLOPT_RETURNTRANSFER, true);

        $headers = array(
            'Accept' => 'application/json',
            'Accept-Encoding' => 'gzip, deflate',
            'Cache-Control' => 'no-cache',
            'Content-Type' => 'application/json; charset=utf-8',
            'Host' => $this->_host,
            'Date' => '"'.gmdate('D, d M Y H:i:s T').'"',
        );
        
        if($extra_headers != null) {
            $headers = array_merge($headers, $extra_headers);
        }

        $headers = $this->_prep_headers($headers, $path, $data);

        $args = array(
			'method'      => $method,
			'timeout'     => 2,
			'redirection' => 0,
			'httpversion' => '1.1',
			'sslverify'   => true,
			'blocking'    => true,
			'user-agent'  => 'INFCRWDS/'. INFCWDS_VERSION,
			'headers'     => $headers,
			'cookies'     => array(),
        );
        if($default_args != null) {
            $args = wp_parse_args($default_args, $args);
        }

        return $args;
    }

    private function _do($path, $args) {
        try {
            $response = wp_remote_request ($this->_base_url . $path, $args);
            if ( is_wp_error( $response ) ) {
                $this->err = $response->get_error_message();
                $this->err_n = $response->get_error_code();
                InfcrwdsPlugin()->logger->log(
                    'Infcrwds req failed '. $path. ' err: ' . $this->err . "err_no." . $this->err_n);
                return false;
                }
        }
        catch(Exception $x) {
            $this->err = $x->getMessage();
            $this->err_n = -1;
            return false;
        }
            
        $httpcode = wp_remote_retrieve_response_code( $response );
        $this->server_output = wp_remote_retrieve_body( $response );
        
        if($httpcode >= 300) {
            $this->err = wp_remote_retrieve_response_message( $response );
            $this->err_n = $httpcode;
            InfcrwdsPlugin()->logger->error(
                "Infcrwds error code" . $httpcode . " (" . $path . ') resp: ' . $this->server_output);
            return false;
        }

        return $this->server_output;
    }

    public function post($path, $data, $extra_headers = null, $req_args = null) {
        if($this->invalid) {
            return false;
        }
        $payload = json_encode($data);

        $args = $this->_prep($path, $payload, $extra_headers, 'POST', $req_args);
        $args['body'] = $payload;

        return $this->_do($path, $args);
    }

    public function get($path, $extra_headers = null, $req_args = null) {
        if($this->invalid) {
            return false;
        }
        $args = $this->_prep($path, null, $extra_headers, 'GET', $req_args);

        return $this->_do($path, $args);
    }


    public function delete($path, $extra_headers = null, $req_args = null) {
        if($this->invalid) {
            return false;
        }
        $args = $this->_prep($path, null, $extra_headers, 'DELETE', $req_args);

        return $this->_do($path, $args);
    }
}