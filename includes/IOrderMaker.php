<?php
/**
 * Merging Order Maker
 *
 *
 * @package   InfinityCrowds
 * @author    InfinityCrowds
 * @license   GPL-3.0
 * @link      https://infinitycrowds.com
 * @copyright 2019 InfinityCrowds (Pty) Ltd
 */

namespace InfCrowds\WPR;

interface IOrderMaker {
    public function makeSuccessOrder($session_id, $transaction_id);

    public function makeCanceledOrder($session_id);

    public function makePaymentFailedOrder($session_id, $transaction_id, $error_reason);
}