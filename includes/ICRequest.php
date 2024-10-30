<?php
/**
 * Infinitycrowds request
 *
 *
 * @package   Infinitycrowds
 * @author    Infinitycrowds
 * @license   GPL-3.0
 * @link      https://infinitycrowds.com
 * @copyright 2019 InfinityCrowds (Pty) Ltd
 */

namespace InfCrowds\WPR;

class ICRequest extends BasicICRequest {

    public function __construct($store) {
        parent::__construct($store);

        $this->_api_key = $store->get('ic_api_key');
        $this->_api_secret = $store->get('ic_secret');

        $this->invalid = empty($this->_base_url) || empty($this->_api_key) || empty($this->_api_secret);
        if($this->invalid) {
            $this->err = 'Invalid';
            $this->err_n = -1;
        }
    }
    protected function _prep_headers($headers, $path, $data) {
        $Sig = null;
        if($data === null) {
            $base_path = parse_url($this->_base_url, PHP_URL_PATH);
            $Sig = hash_hmac('sha256', $base_path . $path, $this->_api_secret);
        } else {
            $Sig = hash_hmac('sha256',  $data, $this->_api_secret);
        }
        $headers['X-API-Key'] = $this->_api_key;
        $headers['Authorization'] = 'Signature algorithm="hmac-sha256",signature="'.$Sig.'"';
        return $headers;
    }
}