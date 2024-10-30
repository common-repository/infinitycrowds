<?php
/**
 * Order Maker Factory
 *
 *
 * @package   InfinityCrowds
 * @author    InfinityCrowds
 * @license   GPL-3.0
 * @link      https://infinitycrowds.com
 * @copyright 2019 InfinityCrowds (Pty) Ltd
 */

namespace InfCrowds\WPR;

interface IOrderMakerFactory {
    public function createOrderMaker($offer, $order, $support_merge);
}