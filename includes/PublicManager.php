<?php
/**
 * InfCrowds
 *
 *
 * @package   InfCrowds
 * @author    InfCrowds
 * @license   GPL-3.0
 * @link      https://goInfCrowds.com
 * @copyright 2017 InfCrowds (Pty) Ltd
 */

namespace InfCrowds\WPR;
use InfCrowds\WPR\Gateways\GatewayIntegrationBase;
use INFCWDS_Plugin_Compatibilities;
use INFCWDS_WC_Compatibility;



/**
 * @subpackage PublicManager
 */
class PublicManager {

    public function __construct($gateway_mgr, $store) {
        $this->_store = $store;
        $this->_gateway_mgr = $gateway_mgr;
        $this->cart_offers = null;
        $this->_async_handles = null;
        $this->_merged_order_id = null;
        $this->_merged_with_order_ids = null;
        $this->_deleted_order_id = null;
        $this->_cart_total = null;
        $this->do_hooks();
    }

    private function do_hooks() {
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'post_merge_orders_bacs_or_checks' ), 1, 2 );
        // add_action( 'woocommerce_checkout_order_processed', array( $this, 'report_conversion_bacs_or_checks' ), 99, 2 );

        add_action( 'woocommerce_pre_payment_complete', array( $this, 'post_merge_orders' ), 100, 1 );
        // add_action( 'woocommerce_pre_payment_complete', array( $this, 'report_conversion' ), 99, 1 );
        add_action( 'woocommerce_pre_payment_complete', array( $this, 'prepare_widget_payment_complete' ), 99, 1 );

        add_action( 'woocommerce_checkout_order_processed', array( $this, 'prepare_widget_checkout_completed' ), 99, 1 );
        add_action( 'infcrwds_upsell_success_order_updated', array( $this, 'prepare_widget_after_upsell'), 99, 1);
        add_action( 'infcrwds_upsell_success_order_created', array( $this, 'prepare_widget_after_upsell'), 99, 1);

        add_action('woocommerce_before_checkout_form', array($this, 'prepare_widget_on_cart_update'), 99, 1);

        add_action( 'woocommerce_updated_product_stock', array( $this, 'update_offer_stock'), 10 , 1 );
        add_action( 'woocommerce_thankyou', array($this, 'on_thank_you'), 99, 1 );
        add_action( 'woocommerce_cart_loaded_from_session', array($this, 'set_cart_offers'), 10, 1 );
        if($this->_store->get('track', true)) {
            add_action( 'wp_enqueue_scripts', array($this, 'tracker'));
        }
        else { 
            add_action( 'wp_enqueue_scripts', array($this, 'light_tracker'));
        }
        add_filter( 'woocommerce_payment_complete_order_status', array($this, 'filter_payment_completed_status'), 100, 2);
        add_filter( 'woocommerce_payment_complete', array($this, 'delete_merged_order'), 100, 1);
        add_filter( 'woocommerce_get_return_url', array($this, 'show_merged_order_url'), 100, 2);

        add_action('woocommerce_update_product', array($this, 'sync_on_product_save'), 10, 1 );
        add_action('wp_trash_post', array($this, 'sync_on_product_delete'), 10, 1 );
        add_action('before_delete_post', array($this, 'sync_on_product_delete'), 10, 1 );
        // add_action( 'added_post_meta', array($this, 'on_product_save_rating'), 10, 4 );
        // add_action( 'updated_post_meta', array($this, 'on_product_save_rating'), 10, 4 );
    }

    public function show_merged_order_url($url, $merged_order) {
        if (INFCWDS_WC_Compatibility::get_order_id($merged_order) === $this->_merged_order_id
            && $this->_merged_with_order_ids != null && sizeof($this->_merged_with_order_ids) > 0) {
                // $this->_merged_with_order_ids
            $order = wc_get_order( $this->_merged_with_order_ids[0] );
            if ( false === is_a( $order, 'WC_Order' ) ) {
                //            INFCWDS_Core()->log->log( 'No valid order' );
                
                return;
            }
            // remove_filter('woocommerce_get_checkout_order_received_url',
                // array($this, 'show_merged_order_url'), 100);
            
            // if($this->_merged_order_id !== null) {
            //         # special case where merged order id is not deleted (bacs cache or cod)
            //         wp_delete_post($this->_merged_order_id, true);
            //         $this->_merged_order_id = null;
            // }

            // workaround for bacs checs or cod
            
            if($this->_deleted_order_id == null) {
                if (apply_filters('infcrwd_can_delete_order', true, $this->_merged_order_id)){
                    wp_delete_post($this->_merged_order_id,true);
                    $this->_deleted_order_id = $this->_merged_order_id;
                }
                
            }
            return $order->get_checkout_order_received_url(); 
        }
        return $url;
    }

    public function set_cart_offers($cart) {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            /* it's an Ajax call */ 
            return;
        }
        $this->cart_offers = null;
        foreach($cart->get_cart() as $item) {
            if(isset($item['ic_offer_id']) && isset($item['ic_pricing'])) {
                if($this->cart_offers === null) {
                    $this->cart_offers = array();
                }
                $this->cart_offers[strval($item['ic_offer_id'])] = $item['ic_pricing'];
            }
        }
        $this->_cart_total = $cart->get_cart_contents_total();
    }

    public function filter_payment_completed_status($status, $order_id) {
        if($this->_merged_order_id === $order_id) {
            return 'completed';
        }
        return $status;
    }

    public function delete_merged_order($order_id) {
        if($this->_merged_order_id === $order_id) {
            if (apply_filters('infcrwd_can_delete_order', true, $order_id)){
                wp_delete_post($order_id,true);
                $this->_deleted_order_id = $this->_merged_order_id;
            }
        }
    }
    public function light_tracker() {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            /* it's an Ajax call */ 
            return;
        }

        if(is_order_received_page() || is_page("infinitycrowd-secret-offer") || is_page("infinitycrowd-secret-offers")) {
            // pages where we're already running, not relevant
            return;
        }
        $context_type = null;
        $context_id = null;
        $co_url = null;
        
        if(is_checkout() || is_page( 'cart' ) || is_cart()) {
                return;
        }
        if($this->cart_offers !== null) {
            $co_url = wc_get_checkout_url();
        }
        $scripts_src = $this->_store->get('onlinestore_scripts', false);
        
        if($scripts_src === false) {
            return;
        }
        $plugin = Plugin::get_instance();
        $count = 0;
        $this->_async_handles = array();
        foreach($scripts_src as $src) {
            $count_scripts = sizeof($this->_async_handles);
            $name = $plugin->get_plugin_slug() . '-tracker' . $count_scripts;
            wp_enqueue_script($name , $src, array(), null, true);
            $this->_async_handles[] = $name;
        }
        
        $this->enqueue_async_scripts();

        $object_name = '__infcrwds_tracker';
        
        $object = array(
            'ic_merchant_id' => $this->_store->get('ic_merchant_id'),
            'billing_name' => $this->_store->session_get('billing_name'),
            'offers_in_cart' => $this->cart_offers,
            'checkout_url' => $co_url,
            'context_id' => $context_id,
            'context_type' => $context_type,
            'cart_total' => $this->_cart_total,
            'track' => $this->_store->get('track', true)
        );

        wp_localize_script( $plugin->get_plugin_slug() . '-tracker0', $object_name, $object );
    }
    public function tracker() {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            /* it's an Ajax call */ 
            return;
        }
        
        if(is_order_received_page() || is_page("infinitycrowd-secret-offer") || is_page("infinitycrowd-secret-offers")) {
            // pages where we're already running, not relevant
            return;
        }
        $is_product = is_product();
        $context_type = null;
        $context_id = null;
        $co_url = null;
        if(is_product_category()) {
            $context_type = 'category';
            $context_id = get_queried_object()->term_id;
        }
        else if($is_product) {
            global $post;
            $context_type = 'product';
            $context_id = $post->ID;
        }
        else if(is_checkout() || is_page( 'cart' ) || is_cart()) {
            // dont track this pages..
            return;
        }
        if($this->cart_offers !== null) {
            $co_url = wc_get_checkout_url();
        }
        $scripts_src = $this->_store->get('onlinestore_scripts', false);
        
        if($scripts_src === false) {
            return;
        }
        $plugin = Plugin::get_instance();
        $count = 0;
        $this->_async_handles = array();
        foreach($scripts_src as $src) {
            $count_scripts = sizeof($this->_async_handles);
            $name = $plugin->get_plugin_slug() . '-tracker' . $count_scripts;
            wp_enqueue_script($name , $src, array(), null, true);
            $this->_async_handles[] = $name;
        }
        
        $this->enqueue_async_scripts();

        $object_name = '__infcrwds_tracker';
        
        $object = array(
            'ic_merchant_id' => $this->_store->get('ic_merchant_id'),
            'billing_name' => $this->_store->session_get('billing_name'),
            'offers_in_cart' => $this->cart_offers,
            'checkout_url' => $co_url,
            'context_id' => $context_id,
            'context_type' => $context_type,
            'cart_total' => $this->_cart_total,
            'track' => $this->_store->get('track', true)
        );

        wp_localize_script( $plugin->get_plugin_slug() . '-tracker0', $object_name, $object );
    }

    public function sync_on_product_save($product_id) {
        remove_action('woocommerce_update_product', array($this, 'sync_on_product_save'));
        $product = wc_get_product( $product_id );
        if($product->get_status() !== 'publish') {
            $this->sync_on_product_delete($product_id);
            return;
        }
        $data = array(
            'sale_price' => $product->get_sale_price() === "" ? null : floatval($product->get_sale_price()),
            'regular_price' => $product->get_sale_price() === "" ? null: floatval($product->get_regular_price()),
            'title' => $product->get_title(),
            'rating' => $product->get_average_rating(),
            'rating_count' => $product->get_rating_count(),
        );
        if($product->get_manage_stock()) {
            $data['is_in_stock'] = $product->is_in_stock();
            $data['quantity'] = $product->get_stock_quantity();
        }
        $req = new ICRequest($this->_store);
        $res = $req->post('/products/'. $product_id, $data);
    }

    public function sync_on_product_delete($product_id) {
        if (get_post_type($product_id) !== 'product') {
            return;
        }
        $req = new ICRequest($this->_store);
        $res = $req->delete('/products/'. $product_id);
    }

    function on_product_save_rating( $meta_id, $post_id, $meta_key, $meta_value ) {
        // '_wc_review_count'
        if ( $meta_key == '_wc_average_rating' ) {
            if ( get_post_type( $post_id ) == 'product' ) {
                $this->sync_on_product_save($post_id);
            }
        }
    }

    public function enqueue_async_scripts() {
        add_filter('script_loader_tag', array($this, 'do_async_scripts'), 10, 3);
    }

    function do_async_scripts( $tag, $handle, $src ) {
        // the handles of the enqueued scripts we want to async
        // $plugin_slug = Plugin::get_instance()->get_plugin_slug();
        if($this->_async_handles !== null && in_array($handle, $this->_async_handles)) {
        // $async_scripts = array( $plugin_slug . '-pub_messages',  );
    
        // if ( in_array( $handle, $async_scripts ) ) {
            return '<script type="text/javascript" src="' . $src . '" async="async"></script>' . "\n";
        }

        return $tag;
    }

    public function on_thank_you($order_id) {
        // $ic_session_id = $this->_store->get_current_ic_session_id();
        
        // if(empty($ic_session_id) && isset($_GET['ic_session_id'])) {
        //     $ic_session_id = sanitize_key($_GET['ic_session_id']);
        //     if($ic_session_id != '' && $ic_session_id !== null) {
        //         $this->_store->begin_offers_session($ic_session_id, $order_id);
        //     }
        // }

        $plugin = Plugin::get_instance();
        $scripts_src = $this->_store->get('widget_scripts', false);
        
        if($scripts_src === false) {
            return;
        } else {
            $order = wc_get_order( $order_id );
            if ( false === is_a( $order, 'WC_Order' ) ) {
                return;
            }
            
            $shop_url = get_bloginfo('url');
            $shop_domain = parse_url($shop_url, PHP_URL_HOST);
            
            $object = array(
                'api_nonce'   => wp_create_nonce( 'infcrwds_front' ),
                'api_url'	  => \WC_AJAX::get_endpoint( '%%endpoint%%' ),
                'shop_url'    => $shop_domain,
                'order_id' => $order_id,
                'billing_name' => INFCWDS_WC_Compatibility::get_billing_first_name($order),
                'billing_email' => INFCWDS_WC_Compatibility::get_order_data($order, 'billing_email'),
            );
            $handleSuffix = '-widget0';
            $this->_async_handles = array();
            foreach($scripts_src as $src) {
                $count_scripts = sizeof($this->_async_handles);
                $name = $plugin->get_plugin_slug() . '-widget' . $count_scripts;
                wp_enqueue_script($name , $src, array(), null, true);
                $this->_async_handles[] = $name;
            }
            $this->enqueue_async_scripts();
        }
        
		$object_name = 'wpr_object_' . uniqid();
		

        wp_localize_script( $plugin->get_plugin_slug() . $handleSuffix, $object_name, $object );
        
        ?><div class="infcrouds" data-object-id="<?php echo $object_name ?>"></div><?php
    }

    public function update_offer_stock($product_id) {
        $product = wc_get_product($product_id);
        if($product->get_manage_stock()) {
            $req = new ICRequest($this->_store);
            $data = array (
                'is_in_stock' => $product->is_in_stock(),
                'quantity' => $product->get_stock_quantity(),
            );
            $req->post('/products/'.$product_id.'/stock', $data);
        }
    }

    private function make_products_list($get_order) {
        $is_in_upsell = false;
        $product_types = array();
        $product_tags = array();
        $products = array();
        if ( $get_order->get_items() && is_array( $get_order->get_items() ) && count( $get_order->get_items() ) ) {
            foreach ($get_order->get_items() as $item_key => $order_item) {
                $product = INFCWDS_WC_Compatibility::get_product_from_item($get_order, $order_item);

                $productID = $product->get_id();
                $productID = ($product->get_parent_id()) ? $product->get_parent_id() : $productID;

                $categories = wp_get_object_terms($productID, 'product_cat', array('fields' => 'ids'));

                $terms = wp_get_post_terms( $productID, 'product_type', array( 'fields' => 'ids' ) );
                $product_types = array_merge( $terms, $product_types );


                $terms = wp_get_object_terms( $productID, 'product_tag', array( 'fields' => 'ids' ) );
                $product_tags = array_merge( $terms, $product_tags );

                $offerid = wc_get_order_item_meta($item_key, '_infcrwds_offer_id');
                if(!empty($offerid)) {
                    $order_to_merge_with = wc_get_order_item_meta($item_key, '_infcrwds_merge_with');
                    if(!empty($order_to_merge_with)) {
                        $is_in_upsell = true;
                    }
                }

                $price = wc_get_order_item_meta($item_key, '_line_total', true);

                array_push($products, array(
                    'product_id' => $order_item->get_product_id(),
                    'variation_id' => $order_item->get_variation_id(),
                    'quantity' => $order_item->get_quantity(),
                    'price' => $price / $order_item->get_quantity(),
                    'tax' => wc_get_order_item_meta($order_item, '_line_tax', true),
                    'categories' => $categories
                ));
            }

        }
        return array(
            'products' => $products,
            'product_tags' => $product_tags,
            'product_types' => $product_types,
            'is_in_upsell' => $is_in_upsell
        );
    }

    private function _prepare_first_order($order, $billing_email)
    {

        $orders = wc_get_orders(array(
            'customer' => $billing_email,
            'limit' => 2,
            'return' => 'ids',
        ));
        if (count($orders) == 1) {
            return true;
        }
        return false;
    }

    private function _prepare_user_roles($order=null, $customer=null) {
        $user_id = null;
        if($order !== null) {
            $user_id  = $order->get_customer_id();
        } else if($customer !== null) {
            $user_id = $customer->get_id();
        }
        if($user_id !== null) {
            $user_meta = get_userdata($user_id);
            if($user_meta) {
                return $user_meta->roles; //array of roles the user is part of.
            }
        }
        return null;
    }

    private function make_product_list_for_cart() {
        $categories = array();
        $product_types = array();
        $product_tags = array();
        $products = array();
        $is_in_upsell = false;
        foreach ( WC()->cart->get_cart() as $cart_item ) {
                if(isset($cart_item['ic_offer_id']) && $cart_item['ic_is_internal_upsell'] === true) {
                    $is_in_upsell = true;
                }
                $product = $cart_item['data'];

                $productID = $product->get_id();
                $productID = ($product->get_parent_id()) ? $product->get_parent_id() : $productID;

                $categories = wp_get_object_terms($productID, 'product_cat', array('fields' => 'ids'));

                $terms = wp_get_post_terms( $productID, 'product_type', array( 'fields' => 'ids' ) );
                $product_types = array_merge( $terms, $product_types );


                $terms = wp_get_object_terms( $productID, 'product_tag', array( 'fields' => 'ids' ) );
                $product_tags = array_merge( $terms, $product_tags );


                $price = $cart_item['line_total'];

                array_push($products, array(
                    'product_id' => $cart_item['product_id'],
                    'variation_id' => $cart_item['variation_id'],
                    'quantity' => $cart_item['quantity'],
                    'price' => $cart_item['data']->get_price(),
                    'tax' => 0,
                    'categories' => $categories
                ));
            }

        return array(
            'products' => $products,
            'product_tags' => $product_tags,
            'product_types' => $product_types,
            'is_in_upsell' => $is_in_upsell,
        );
    }

    private function get_customer_email_hash($customer, $email=null) {
        if ($email === null && $customer !== null) {
            $email = $customer->get_email();
        }
        if(!empty($email)) {
            $email = trim(strtolower($email));
            return hash ('sha256' , $email); 
        }
        return null;
    }

    public function prepare_widget_on_cart_update($wccm_autocreate_account) {
        // setup offers on infcrwds server
        
        // $this->enqueue_async_scripts();

        $current_session_id = $this->_store->get_current_ic_session_id();
        $products_data = $this->make_product_list_for_cart();
        $cart = WC()->cart;
        $customer = $cart->get_customer();
        $data = array(
            'order_id' => null,
            'currency' => get_woocommerce_currency(),
            'event' => 'CHECKOUT_FORM',
            'is_in_upsell' => $products_data['is_in_upsell'],
            'session_id' => $current_session_id,
            'gateway' => null,
            'total_price' => $cart->get_subtotal(),
            'shipping_price' => $cart->get_shipping_total(),
            'customer_email_hash' => $this->get_customer_email_hash($customer, null),
            'customer_id' => $customer === null ? null : $customer->get_id(),
            'products' => $products_data['products'],
            'product_tags' => $products_data['product_tags'],
            'product_types' => $products_data['product_types'],
            'is_first_order' => null,
            'shipping_country' => $customer === null ? null : $customer->get_shipping_country(),
            'billing_country' => $customer === null ? null : $customer->get_billing_country(),
            'ip' => Commons::GetIP(),
            'roles' => $this->_prepare_user_roles(null, $customer),
            'user_agent' => wc_get_user_agent(),
            'locale' => get_locale(),
            'is_logged_in' => is_user_logged_in(),
        );
        $req = new ICRequest($this->_store);
        $res = $req->post('/widget/prepare', $data);
        if($res === false) {
            return;
        }
        $server_response = json_decode($res);
        $this->_store->begin_offers_session($server_response->session_id);
    }

    public function prepare_widget_checkout_completed($order_id) {
        if ( '' == $order_id ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( false === is_a( $order, 'WC_Order' ) ) {
            //            INFCWDS_Core()->log->log( 'No valid order' );
            
            return;
        }
        $this->prepare_widget($order, 'CHECKOUT_PROCESSED');
    }

    public function prepare_widget_payment_complete($order_id) {
        if ( '' == $order_id ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( false === is_a( $order, 'WC_Order' ) ) {
            //            INFCWDS_Core()->log->log( 'No valid order' );
            
            return;
        }
        $this->prepare_widget($order, 'ORDER_PAYED');
    }

    public function prepare_widget_after_upsell($order) {
        $this->_store->clear_upsell_context();
        $this->report_conversion($order->get_id());
        $this->prepare_widget($order, 'AFTER_UPSELL');
    }
    public function post_merge_orders_bacs_or_checks($order_id, $posted_data) {
        if ( WC()->cart->needs_payment() && is_array( $posted_data ) && isset( $posted_data['payment_method'] ) && in_array( $posted_data['payment_method'], array( 'cheque', 'bacs' ) ) ) {
            $this->post_merge_orders($order_id);
        }
    }
    public function report_conversion_bacs_or_checks($order_id, $posted_data) {
        if ( WC()->cart->needs_payment() && is_array( $posted_data ) && isset( $posted_data['payment_method'] ) && in_array( $posted_data['payment_method'], array( 'cheque', 'bacs' ) ) ) {
            $this->report_conversion($order_id);
        }
    }

    public function post_merge_orders($order_id) {
        if ( '' == $order_id ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( false === is_a( $order, 'WC_Order' ) ) {

            InfcrwdsPlugin()->logger->error('No valid order' );
            return;
        }
        $infcrwds_flag = INFCWDS_WC_Compatibility::get_order_meta($order, '_infcrwds');
        if(!empty($infcrwds_flag) && $infcrwds_flag === '1') {
            $order_items = $order->get_items();
            $allow_merge = sizeof($order_items) === 1;
            
            $items_to_delete = array();
            $order_to_merge_with = null;
            $oids = array();
            $order_infcrwds_totals = array();
            $payment_gateway = $order->get_payment_method();
            if($allow_merge) {
                foreach ($order_items as $item_id => $item_data) {
                    $oid = wc_get_order_item_meta($item_id, '_infcrwds_offer_id');
                    if(!empty($oid)) {
                        if(isset($oids[$oid])) {
                            continue;
                        }
                        $order_to_merge_with = wc_get_order_item_meta($item_id, '_infcrwds_merge_with');
                        if(!empty($order_to_merge_with)) {
                            $product = INFCWDS_WC_Compatibility::get_product_from_item($order, $item_data);
                            $order_obj_to_merge_with = wc_get_order($order_to_merge_with);

                            $date_created_dt = INFCWDS_WC_Compatibility::get_order_date($order_obj_to_merge_with);

                            $timezone        = $date_created_dt->getTimezone(); // Get the timezone
                            $date_created_ts = $date_created_dt->getTimestamp(); // Get the timestamp in seconds
                            
                            $now_dt = new \WC_DateTime(); // Get current WC_DateTime object instance
                            $now_dt->setTimezone( $timezone ); // Set the same time zone
                            $now_ts = $now_dt->getTimestamp(); // Get the current timestamp in seconds
                            
                            $one_hour = 60 * 60; // 1 hours in seconds
                            
                            $diff_in_seconds = $now_ts - $date_created_ts; // Get the difference (in seconds)
                            if($diff_in_seconds > $one_hour) {
                                continue;
                            }

                            /* start: handle scenerio where pre_payment_complete called more than once on order */
                            $otmw_items = $order_obj_to_merge_with->get_items();
                            $is_already_added = false;
                            foreach ($otmw_items as $otmw_item_id => $otmw_item_data) {
                                $otmg_oid = wc_get_order_item_meta($otmw_item_id, '_infcrwds_offer_id');
                                $is_already_added = $otmg_oid == $oid;
                                if($is_already_added) {
                                    break;
                                }
                            }
                            if($is_already_added) {
                                continue;;
                            }
                            /* end: handle scenerio where pre_payment_complete called more than once on order */
                            $oids[$oid] = $order_obj_to_merge_with;
                            $price = wc_get_order_item_meta($item_id, '_line_total', true);
                            if(!isset($order_infcrwds_totals[$order_to_merge_with])) {
                                $rev = INFCWDS_WC_Compatibility::get_order_meta($order_obj_to_merge_with, '_infcrwds_revenue');
                                if(empty($rev)) {
                                    $rev = 0;
                                }
                                $order_infcrwds_totals[$order_to_merge_with] = $rev;
                            }
                            $order_infcrwds_totals[$order_to_merge_with] += $price;
                            
                            $new_item_id = $order_obj_to_merge_with->add_product($product,
                                $item_data->get_quantity(), 
                                array(
                                    'subtotal' => $price,
                                    'total' => $price
                                )
                            );

                            wc_add_order_item_meta($new_item_id, '_infcrwds_offer_id', $oid);
                            wc_add_order_item_meta($new_item_id, 'paid via', $payment_gateway);
                            wc_add_order_item_meta($new_item_id, '_infcrwds_session_id',
                                wc_get_order_item_meta($item_id, '_infcrwds_session_id'));
                            
                            $items_to_delete[] = $item_id;
                        }
                    }
                }
                if(sizeof($oids) > 0) {
                    $get_shipping_items = $order->get_items( 'shipping' );

                    foreach($get_shipping_items as $shipping_item) {
                        $oid = wc_get_order_item_meta($shipping_item->get_id(), '_infcrwds_offer_id');
                        if(!empty($oid) && isset($oids[$oid])) {
                            $order_obj_to_merge_with = $oids[$oid];
                            
                            $shipping_rev = wc_get_order_item_meta($shipping_item->get_id(), '_infcrwds_shipping_price');
                            if(!empty($shipping_rev)) {
                                $shipping_price = floatval($shipping_rev);
                                $to_merge_with_id = INFCWDS_WC_Compatibility::get_order_id($order_obj_to_merge_with);
                                $order_infcrwds_totals[$to_merge_with_id] += $shipping_price;
                                
                                $new_item = new \WC_Order_Item_Shipping();
                                $new_item->set_props( array(
                                    'method_title' => $shipping_item->get_method_title(),
                                    'method_id'    => $shipping_item->get_method_id(),
                                    'total'        => $shipping_price,
                                ) );
                                $new_item->save();
                                $order_obj_to_merge_with->add_item( $new_item );
                                $items_to_delete[] = $shipping_item->get_id();
                            }
                        }
                    }
                }
            }
            
            $order_ids = array();
            foreach($oids as $oid=>$merged_order) {

                $merged_order_id = INFCWDS_WC_Compatibility::get_order_id($merged_order);
                $merged_order->add_meta_data( '_infcrwds_sibling_order', INFCWDS_WC_Compatibility::get_order_id($order), false );
                $merged_order->add_meta_data( '_infcrwds_internal_upsell', 'yes' );
                $merged_order->add_meta_data( '_infcrwds', '1' );
                $merged_order->add_meta_data('_infcrwds_revenue', $order_infcrwds_totals[$merged_order_id]);
                $merged_order->save_meta_data();
                $merged_order->calculate_totals();
                $merged_order->add_order_note( sprintf( 'Upsell Offer Accepted  | Offer ID %s', $oid ) );
                $merged_order->save();
                $order_ids[] = $merged_order->get_id();
                do_action('infcrwds_upsell_success_order_updated', $merged_order);
            }

            if(sizeof($order_ids) > 0) {
                foreach($items_to_delete as $item_id) {
                    wc_delete_order_item( $item_id );
                }
                
                clean_post_cache( $order->get_id() );
                
                $order->add_order_note( sprintf( 'Upsell | Content of order was merged with Order IDs: %s', join(',', $order_ids)) );
                $order = wc_get_order($order->get_id());
                $this->_merged_order_id = $order_id;

                $this->_merged_with_order_ids = $order_ids;
                $order->calculate_totals();
                $order->save();
                $order->add_meta_data( '_infcrwds_order_merged', array_keys($order_ids), false );
                $order->save_meta_data();
                $order = wc_get_order($order->get_id());
                do_action('infcrwds_upsell_success_order_merged_with_parent', $order);
            }
            else {
                $order->add_order_note( sprintf( 'Upsell Offer Accepted' ) );
                $order->save();
                do_action('infcrwds_upsell_success_order_created', $order);
            }
        }
    }

    public function report_conversion($order_id) {
        if ( '' == $order_id ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( false === is_a( $order, 'WC_Order' ) ) {

            InfcrwdsPlugin()->logger->error('No valid order' );
            return;
        }
        $currency = INFCWDS_WC_Compatibility::get_order_currency($order);
        
        $infcrwds_flag = INFCWDS_WC_Compatibility::get_order_meta($order, '_infcrwds');
        $offers_arr = array();
        if(!empty($infcrwds_flag) && $infcrwds_flag === '1') {
            // this is an order promoted on infcrwds network
            $ip = null;
            $is_inf_crwds_order = false;
            $order_items = $order->get_items();
            foreach ($order_items as $item_id => $item_data) {
                // $product = INFCWDS_WC_Compatibility::get_product_from_item($order, $item_data);
                $oid = wc_get_order_item_meta($item_id, '_infcrwds_offer_id');
                $session_id = wc_get_order_item_meta($item_id, '_infcrwds_session_id');
                // this is the product in the offer, tag it
                if(!empty($oid)) {
                    
                    if(isset($offers_arr[$oid])) {
                        $arr = $offers_arr[$oid];
                    }
                    else {
                        if($ip === null) {
                            $ip = Commons::GetIP();
                        }
                        $arr = array(
                            'revenue' => 0.0,
                            'ip' => $ip,
                            'session_id' => $session_id,
                            'oid' => $oid,
                            'order_id' => $order_id,
                            'product_name' => $item_data->get_name(),
                            'product_id' => $item_data->get_product_id(),
                            'product_variation_id' => $item_data->get_variation_id()
                        );
                        $offers_arr[$oid] = $arr;
                    }
                    $offers_arr[$oid]['revenue'] += INFCWDS_Plugin_Compatibilities::get_fixed_currency_price_reverse(
                        wc_get_order_item_meta($item_id, '_line_total', true), $currency );
                }
            }
            $get_shipping_items = $order->get_items( 'shipping' );

            foreach($get_shipping_items as $shipping_item) {
                $oid = wc_get_order_item_meta($shipping_item->get_id(), '_infcrwds_offer_id');
                if(!empty($oid) && isset($offers_arr[$oid])) {
                    $shipping_rev = wc_get_order_item_meta($shipping_item->get_id(), '_infcrwds_shipping_price');
                    if(!empty($shipping_rev)) {
                        $shipping_price = floatval($shipping_rev);
                        $offers_arr[$oid]['revenue'] += INFCWDS_Plugin_Compatibilities::get_fixed_currency_price_reverse($shipping_price, $currency);
                    }
                }
            }
            foreach($offers_arr as $oid => $data) {
                $req = new ICRequest($this->_store);
                $res = $req->post('/events/srv/conv', $data);
                InfcrwdsPlugin()->logger->error('Reported conversion for Order #' . $order_id . 'response: ' . $res);
            }
        }
    }

    public function prepare_widget($wc_get_order, $event) {
        $order_id = $wc_get_order->get_id();
        $skip_funnel_init = apply_filters( 'infcrwds_front_skip_widget_prep', false, $wc_get_order );

        if ( true === $skip_funnel_init ) {
            //INFCWDS_Core()->log->log( 'Funnel Running Skipped by filter' );
            return false;
        }

        $enabled_internal_upsell = true;
        $disabled_internal_upsell_reason = null;
		/**create_new_order
		 * Parent Order Has subscription in it and our addon for subscription is not installed and activated, then discard the funnel setup
		 */

		if ( INFCWDS_WC_Compatibility::is_order_parent_contains_subscriptions( $wc_get_order )) {
			// InfcrwdsPlugin()->logger->log( 'Order #' . $order_id . ': Funnel Initiation Skipped: Reason UpStroke Subscription is not Activated.' );

            $enabled_internal_upsell = false;
            $disabled_internal_upsell_reason = 'subscriptions_included';
		}
        if(!$this->_store->get('is_internal_upsell_enabled', true)) {
            $enabled_internal_upsell = false;
            $disabled_internal_upsell_reason = 'settings';
        }

		// InfcrwdsPlugin()->logger->log( 'Order #' . $order_id . ': Entering: ' . __FUNCTION__ );
		// InfcrwdsPlugin()->logger->log( 'Order #' . $order_id . ': Backtrace for maybe_setup_upsell::' . wp_debug_backtrace_summary() );
		do_action( 'infcrwds_front_pre_init_widget_hooks', $wc_get_order );
        $get_payment_gateway = INFCWDS_WC_Compatibility::get_payment_gateway_from_order( $wc_get_order );
        
        if(in_array( $get_payment_gateway, array( 'cheque', 'bacs' )) && $event === 'CHECKOUT_PROCESSED') {
            $event = 'ORDER_PAYED';
        }


        // setup offers on infcrwds server
        $currency = INFCWDS_WC_Compatibility::get_order_currency($wc_get_order);
        $products_data = $this->make_products_list($wc_get_order);
        $billing_email = INFCWDS_WC_Compatibility::get_order_data($wc_get_order, 'billing_email');
        $is_in_upsell = $products_data['is_in_upsell'];
        $data = array(
            'order_id' => $order_id,
            'currency' => $currency,
            'session_id' => $this->_store->get_current_ic_session_id(),
            'event' => $event,
            'is_in_upsell' => $is_in_upsell,
            'enabled_internal_upsell' => $enabled_internal_upsell,
            'disabled_internal_upsell_reason' => $disabled_internal_upsell_reason,
            'gateway' => $get_payment_gateway,
            'total_price' => $wc_get_order->get_total(),
            'shipping_price' => $wc_get_order->get_shipping_total(),
            'customer_id' => $wc_get_order->get_customer_id(),
            'customer_email_hash' => $this->get_customer_email_hash(null, $billing_email),
            'products' => $products_data['products'],
            'product_tags' => $products_data['product_tags'],
            'product_types' => $products_data['product_types'],
            'is_first_order' => $this->_prepare_first_order($wc_get_order, $billing_email),
            'shipping_country' => INFCWDS_WC_Compatibility::get_shipping_country_from_order($wc_get_order),
            'billing_country' => INFCWDS_WC_Compatibility::get_billing_country_from_order($wc_get_order),
            'billing_phone' => $wc_get_order->get_billing_phone(),
            'ip' => Commons::GetIP(),
            'roles' => $this->_prepare_user_roles($wc_get_order),
            'user_agent' => wc_get_user_agent(),
            'locale' => get_locale(),
            'is_logged_in' => is_user_logged_in(),
            'buyer_accepts_marketing' => WC()->session->get( 'accept_marketing', false),
        );
        $req = new ICRequest($this->_store);
        $res = $req->post('/widget/prepare', $data);
        if($res === false) {
            return;
        }
        $server_response = json_decode($res);
        $this->_store->begin_offers_session($server_response->session_id, $order_id, !$is_in_upsell);
        // $this->_store->session_set('billing_email', $billing_email);
    }
}
