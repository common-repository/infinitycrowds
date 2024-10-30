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

class OfferPage {
    public function __construct($store) {
        $this->_store = $store;
        $this->_order_id = null;
        // add_action( 'init', array( $this, 'setup_template' ), 999);
        add_filter('page_template', array( $this, 'catch_plugin_template'));
        add_action( 'wp_enqueue_scripts', array($this, 'enqueue_offers_page'));

    }

    public function enqueue_offers_page() {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }
        if(!is_page('infinitycrowd-secret-offers')) {
            return;
        }
        $this->enqueue_offers_page_scripts();
        $this->write_offers_page_configs();
    }

    public function ensure() {
        $check_page_exist = get_page_by_title('infinitycrowd-secret-offer', 'OBJECT', 'page');
        $offer_page_link = null;
        $offers_page_link = null;
        if(empty($check_page_exist)) {
            $post_id = wp_insert_post(
                array(
                'comment_status' => 'close',
                'ping_status'    => 'close',
                'post_author'    => get_current_user_id(),
                'post_title'     => ucwords('infinitycrowd-secret-offer'),
                'post_name'      => strtolower(str_replace(' ', '-', trim('infinitycrowd-secret-offer'))),
                'post_status'    => 'publish',
                'post_content'   => 'Infinitycrowd secret offer page',
                'post_type'      => 'page',
                )
            );
            
            if( !$post_id )
                wp_die('Error creating template page');
            else {
                update_post_meta( $post_id, '_wp_page_template', 'infcrwd-offer-page.php' );
                $offer_page_link = get_page_link($post_id);
            }
        } else {
            if($check_page_exist->post_status == 'trash') {
                wp_untrash_post($check_page_exist->ID);
            }
            $offer_page_link = get_page_link($check_page_exist->ID);
        }
        $check_page_exist = get_page_by_path('infinitycrowd-secret-offers', 'OBJECT', 'page');
        $page_empty = empty($check_page_exist);
        if(!$page_empty && strpos($check_page_exist->post_content, 'infcrwds-offer-root') === false) {
            wp_delete_post($check_page_exist->ID, true);
            $page_empty = true;
        }
        if($page_empty) {
            
            $post_id = wp_insert_post(
                array(
                'comment_status' => 'close',
                'ping_status'    => 'close',
                'post_author'    => get_current_user_id(),
                'post_title'     => substr( get_bloginfo('language'), 0, 2) == 'he' ? 'דילים ללקוחות החנות בלבד' : 'Offers For Customers Only',
                'post_name'      => strtolower(str_replace(' ', '-', trim('infinitycrowd-secret-offers'))),
                'post_status'    => 'publish',
                'post_type'      => 'page',
                'post_content'   => <<<PAGE
                <div id="infcrwds-offer-root">
  <div style="width:100%;height:400px;display:flex;justify-content:center;align-items:center;flex-direction:column;">
  <div style="width: 100px;height: 75px;">
    <img src="https://cdn.infinitycrowds.com/plugins/woo/global/assets/imgs/loader.svg" />
  </div>
  <div>Loading Offers...</div>
</div>
PAGE
                )
            );

            if( !$post_id )
                wp_die('Error creating template page');
            else {
                $offers_page_link = get_page_link($post_id);
                // update_post_meta( $post_id, '_wp_page_template', 'infcrwd-offers-page.php' );
            }
        } else {
            if($check_page_exist->post_status == 'trash') {
                wp_untrash_post($check_page_exist->ID);
            }
            $offers_page_link = get_page_link($check_page_exist->ID);
        }
        if($offers_page_link !== null && $offer_page_link !== null) {
            $req = new ICRequest($this->_store);
            $req->post('/pages/links', array(
                "offers_page" => $offers_page_link,
                "offer_page" => $offer_page_link
            ));
        }
    }

    public function enqueue_page_scripts() {
        $plugin_slug = Plugin::get_instance()->get_plugin_slug();
        $version = Plugin::get_instance()->get_plugin_version();
        

        // support media files
        if ( 'mediaelement' === apply_filters( 'wp_video_shortcode_library', 'mediaelement' ) ) {
			wp_enqueue_style( 'wp-mediaelement' );
			wp_enqueue_script( 'wp-mediaelement' );
        }
        $scripts_src = $this->_store->get('offerpage_scripts', false);


        if($scripts_src === false) {
            return;
        } else {
            $count = 0;
            foreach($scripts_src as $src) {
                $name = $plugin_slug . '-offerpage' . $count;
                wp_enqueue_script($name , $src, array(), null, true);
                $count++;
            }
        }
    }

    public function enqueue_offers_page_scripts() {
        $plugin_slug = Plugin::get_instance()->get_plugin_slug();
        $scripts_src = $this->_store->get('offerspage_scripts', false);


        if($scripts_src === false) {
            return;
        } else {
            $count = 0;
            foreach($scripts_src as $src) {
                $name = $plugin_slug . '-offerspage' . $count;
                wp_enqueue_script($name , $src, array(), null, true);
                $count++;
            }
        }
    }
    
    public function write_page_configs() {
        $plugin = Plugin::get_instance();
        $object_name = 'infcrwds_object';

		$object = array(
            'api_nonce'   => wp_create_nonce( 'infcrwds_front' ),
            'api_url'	  => \WC_AJAX::get_endpoint( '%%endpoint%%' ),
        );
        wp_localize_script( $plugin->get_plugin_slug() . '-offerpage0', $object_name, $object );
    }

    public function write_offers_page_configs() {
        $plugin = Plugin::get_instance();
        $object_name = 'infcrwds_object';
        $cust_data = WC()->session->get('customer');
        $a = 1;
		$object = array(
            'api_nonce'   => wp_create_nonce( 'infcrwds_front' ),
            'api_url'	  => \WC_AJAX::get_endpoint( '%%endpoint%%' ),
            'email_or_phone' => empty($cust_data) ? null : $cust_data['email'],//$customer->get_billing_email(),//$this->_store->session_get('billing_email'),
            'customer_id'    => empty($cust_data) ? null : $cust_data['id']//$customer->get_id(),
        );
        wp_localize_script( $plugin->get_plugin_slug() . '-offerspage0', $object_name, $object );
    }

    
    public function render_offer_template($page_template) {
        return INFCWDS_PLUGIN_DIR . '/views/offer-page-template.php';
    }
    
    public function enable_wc_session_cookie() {
        if ( ! WC()->session->has_session() ) 
           WC()->session->set_customer_session_cookie( true ); 
    }

    public function catch_plugin_template($template)
    {
            // If tp-file.php is the set template
        if( is_page_template('infcrwd-offer-page.php') ) {
                    
            if( is_admin()) {
                return;
            }
            if(!isset($_GET['infcrwds_sid']) || !isset($_GET['infcrwds_oid']) || !isset($_GET['m_order_id'])) {
                return;
            }
            $sid = sanitize_key($_GET['infcrwds_sid']);
            $offer_id = sanitize_key($_GET['infcrwds_oid']);
            $order_id  = sanitize_key($_GET['m_order_id']);
            $is_internal  = sanitize_key($_GET['internal']) === '1';

            if($sid === '' || $offer_id === '') {
                return;
            }
            $this->_order_id = $order_id;
            $imp_id = null;
            $widget_id = null;
            if(isset($_GET['infcrwds_impid'])) {
                $imp_id = sanitize_key($_GET['infcrwds_impid']);
            }
            if(isset($_GET['infcrwds_wid'])) {
                $widget_id = sanitize_key($_GET['infcrwds_wid']);
            }
            $this->enable_wc_session_cookie();
            
            $this->_store->begin_offers_session($sid, $is_internal ? $this->_order_id: null);

            return INFCWDS_PLUGIN_DIR . '/views/offer-page-template.php';
        }
        return $template;
    }
}