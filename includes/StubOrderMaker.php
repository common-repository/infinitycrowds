<?php
/**
 * Stub Order Maker
 *
 *
 * @package   InfinityCrowds
 * @author    InfinityCrowds
 * @license   GPL-3.0
 * @link      https://infinitycrowds.com
 * @copyright 2019 InfinityCrowds (Pty) Ltd
 */

namespace InfCrowds\WPR;

class StubOrderMaker implements IOrderMaker {
    public function __construct($order) {
        $this->_order = $order;
    }
    public function makeSuccessOrder($session_id, $transaction_id) {
        return $this->_order;
    }

    public function makeCanceledOrder($session_id) {
        return $this->_order;
    }

    public function makePaymentFailedOrder($session_id, $transaction_id, $error_reason) {
        return $this->_order;
    }
}