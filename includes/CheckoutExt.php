<?php
/**
 * Checkout Ext
 *
 *
 * @package   InfinityCrowds
 * @author    InfinityCrowds
 * @license   GPL-3.0
 * @link      https://infinitycrowds.com
 * @copyright 2019 InfinityCrowds (Pty) Ltd
 */

 namespace InfCrowds\WPR;


class CheckoutExt {
    public function __construct($store) {
        $this->_store = $store;
        if($store->get('require_marketing_cb')) {
            add_action('woocommerce_review_order_before_submit', array($this, 'add_concent_marketing_checkbox'), 9);
            add_action('woocommerce_checkout_process', array($this, 'check_marketing_concent'));

        }
    }
    function add_concent_marketing_checkbox() {
        if(!$this->_store->get('existing_marketing_cb', false)) {
            $txt = substr( get_bloginfo('language'), 0, 2) == 'he' ? 'מאשר קבלת דיוור, דילים והנחות במייל ובמסרונים' : 'Sign up for exclusive offers and news via text messages & email.';
            echo '<p class="form-row terms"> <input type="checkbox" class="input-checkbox" name="accept_marketing" value="1" id="accept_marketing" checked /> <label for="accept_marketing" class="checkbox" style="display: inline;">'. $txt .'</label> </p>';
        }
    }

    function check_marketing_concent(){
        $name = $this->_store->get('existing_marketing_cb', false);
        if(!$name) {
            $name = 'accept_marketing';
        }
        if (isset($_POST[$name]) && $_POST[$name] == '1')  {
            WC()->session->set( 'accept_marketing', true );
        } else {
            WC()->session->set( 'accept_marketing', false );
        }
    }
    
}