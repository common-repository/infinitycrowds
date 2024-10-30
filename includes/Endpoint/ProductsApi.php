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

namespace InfCrowds\WPR\Endpoint;
use InfCrowds\WPR;

/**
 * @subpackage REST_Controller
 */
class ProductsApi extends BaseApi {
	/**
	 * Initialize the plugin by setting localization and loading public scripts
	 * and styles.
	 *
	 * @since     0.8.1
	 */
	function __construct($ns) {
        parent::__construct($ns, '/products/');
        $this->_search_trace = array();
	}


    /**
     * Register the routes for the objects of the controller.
     */
    public function register_routes() {
        $this->add_route('search_products', array(), $suffix='search/');
        $this->add_route('get_product_by_id', array(), $suffix='(?P<id>\d+)');
        $this->add_route('get_product_by_ids', array());
        $this->add_route('get_product_categories', array(), $suffix='(?P<id>\d+)/categories/');
    }

    public function get_product_by_ids($data) {
        $ids = isset( $_GET["ids"] ) ? (array) $_GET["ids"] : array();

        $ids  = array_map( 'sanitize_key', $ids);
        // $ids = $data->get_query_params()['ids'];
        return new \WP_REST_Response( array(
            'success' => true,
            'value' => $this->_get_products_by_id($ids)
        ), 200);
    }

    public function get_product_categories($data) {
        $product_id = $data['id'];
        $terms = wp_get_object_terms($product_id, 'product_cat', array('fields' => 'ids'));
        return new \WP_REST_Response(array(
            'success' => true,
            'value' => $terms
        ), 200);
    }

    public function get_product_by_id($data) {
        $product_id = $data['id'];
        if (empty($product_id)) {
            return new \WP_REST_Response( array(
                'success' => false,
                'value' => null
            ), 400 );
        }
        $products = $this->_get_products_by_id(array($product_id));

        if(empty($products)) {
            return new \WP_REST_Response( array(
                'success' => false,
                'value' => null
            ), 404 );
        }

        return new \WP_REST_Response( array(
            'success' => true,
            'value' => $products[0]
        ), 200 );
    }

    private function _get_products_by_id($ids) {
        /**
         * Products types that are allowed in the offers
         */
        $allowed_types = apply_filters('infcrwds_offer_product_types', array(
            'simple',
            'variable',
            'variation',
        ));
        $this->_search_trace['allowed_types'] = $allowed_types;
        $product_objects = array_map('wc_get_product', $ids);
        $product_objects = array_filter($product_objects, 'wc_products_array_filter_editable');
        $this->_search_trace['len_products_found'] = sizeof($product_objects);
        $this->_search_trace['_pfilter_unmatch'] = 0;
        $product_objects = array_filter($product_objects, function ($arr) use ($allowed_types) {
            $ret_val =  $arr && is_a($arr, 'WC_Product') && in_array($arr->get_type(), $allowed_types);
            if(!$ret_val) {
                $this->_search_trace['_pfilter_unmatch'] += 1;
            }
            return $ret_val;
        });
        $this->_search_trace['len_products_found_that_is_product'] = sizeof($product_objects); 
        $products = array();
        $this->_search_trace['statuses'] = array();
        foreach ($product_objects as $product_object) {
            $product_id = $product_object->get_id();
            $variation_id = null;
            $this->_search_trace['statuses'][] = $product_object->get_status();
            if ('publish' === $product_object->get_status()) {
                $image_url = null;
                $image_id = $product_object->get_image_id();
                $price = $product_object->get_sale_price();
                $min_price = $price;
                $regular_price = $product_object->get_regular_price();
                if(empty($price)) {
                    $price = $product_object->get_price();
                }
                if(!empty($image_id)) {
                    $image_url = wp_get_attachment_image_src($image_id, 'full');
                }
                $isVariationParent = false;
                if ($product_object instanceof \WC_Product_Variable) {
                    $isVariationParent = true;
                    $possible_variations = $product_object->get_available_variations();
                    if(!empty($possible_variations)) {
                        $prices = $product_object->get_variation_prices();
                        
                        if(isset($prices['sale_price']) && sizeof($prices['sale_price']) > 0) {
                            $key = null;
                            foreach($prices['sale_price'] as $k => $p) {
                                $key = $k;
                                break;
                            }
                            $p = $prices['sale_price'][$key];
                            $min_price = min($price, $p);
                            $price = $p;
                            $regular_price = $prices['regular_price'][$key];
                        }
                        else {
                            $key = null;
                            foreach($prices['price'] as $k => $p) {
                                $key = $k;
                                break;
                            }
                            $p = $prices['price'][$key];
                            $min_price = min($price, $p);
                            $price = $p;
                            $regular_price = $prices['price'][0];
                        }

                        if(empty($image_url)) {
                            foreach ($possible_variations as $variation ) {
                                if($variation['image'] !== null) {
                                    $image_url = $variation['image'];
                                    if(!empty($image_url)) {
                                        $image_url = wp_get_attachment_image_src($variation['image_id'], 'full');
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
                else if ($product_object instanceof \WC_Product_Variation){
                    $product_id = get_parent_id();
                    $variation_id = $product_object->get_id();
                }
                $products[] = array(
                    'id' => $product_id,
                    'variation_id' => $variation_id, 
                    'product' => rawurldecode(ProductsApi::get_formatted_product_name($product_object)),
                    'image' => $image_url,
                    'title' => $product_object->get_title(),
                    'price' => $price,
                    'regular_price' => $regular_price,
                    'rating' => $product_object->get_average_rating(),
                    'rating_count' => $product_object->get_rating_count(),
                    'stock_quantity' => $product_object->get_stock_quantity(),
                    'stock_status' => $product_object->get_stock_status(),
                    'link' => get_permalink( $product_object->get_id() ),
                    'is_variation_parent' => $isVariationParent,
                    'min_price' => $min_price,
                );
            }
        }
        return $products;
    }

    public function search_products()
    {
        $term = wc_clean(empty($term) ? stripslashes($_GET['term']) : $term);

        if (empty($term)) {
            return new \WP_REST_Response( array(
                'success' => false,
                'value' => null
            ), 400 );
        }

        $variations = true;
        if ('1' !== $_GET['variations']) {
            $variations = false;
        }
        $ids = $this->actualy_search_products($term, $variations);

        return new \WP_REST_Response( array(
            'success' => true,
            'value' => $this->_get_products_by_id($ids),
            'trace' => $this->_search_trace,
        ), 200 );
    }


    public static function get_variation_attribute( $variation ) {
        if ( is_a( $variation, 'WC_Product_Variation' ) ) {
            $variation_attributes = $variation_attributes_basic = $variation->get_attributes();
            //          $variation_attributes       = array();
            //          foreach ( $variation_attributes_basic as $key => $value ) {
            //              $variation_attributes[ wc_attribute_label( $key, $variation ) ] = $value;
            //          }

        } else {

            $variation_attributes = array();
            if ( is_array( $variation ) ) {
                foreach ( $variation as $key => $value ) {
                    $variation_attributes[ str_replace( 'attribute_', '', $key ) ] = $value;
                }
            }
        }

        return ( $variation_attributes );

    }

    public static function get_formatted_product_name( $product ) {
        $formatted_variation_list = ProductsApi::get_variation_attribute( $product );

        $arguments = array();
        if ( ! empty( $formatted_variation_list ) && count( $formatted_variation_list ) > 0 ) {
            foreach ( $formatted_variation_list as $att => $att_val ) {
                if ( $att_val == '' ) {
                    $att_val = __( 'any', 'rocketsell-one-click-upsell' );
                }
                $att         = strtolower( $att );
                $att_val     = strtolower( $att_val );
                $arguments[] = "$att: $att_val";
            }
        }

        return sprintf( '%s (#%d)%s', $product->get_title(), $product->get_id(), ( count( $arguments ) > 0 ) ? ' (' . implode( ',', $arguments ) . ')' : '' );
    }

    public function actualy_search_products( $term, $include_variations = false ) {
        global $wpdb;
        $like_term     = '%' . $wpdb->esc_like( $term ) . '%';
        $this->_search_trace['like_term'] = $like_term;
        $post_types    = apply_filters( 'infcrwds_allow_post_types_to_search', $include_variations ? array(
            'product',
            'product_variation',
        ) : array( 'product' ) );
        $this->_search_trace['post_types'] = $post_types;
        $post_statuses = current_user_can( 'edit_private_products' ) ? array(
            'private',
            'publish',
        ) : array( 'publish' );
        $this->_search_trace['post_statuses'] = $post_statuses;
        $type_join     = '';
        $type_where    = '';
        $possible_id_q = '';
        if (ctype_digit($term)) {
            $possible_id_q =" OR posts.ID=$term";
        }
        $product_ids = $wpdb->get_col(

            $wpdb->prepare( "SELECT DISTINCT posts.ID FROM {$wpdb->posts} posts
				LEFT JOIN {$wpdb->postmeta} postmeta ON posts.ID = postmeta.post_id
				{$type_join}
				WHERE (
					posts.post_title LIKE %s
					OR (
						postmeta.meta_key = '_sku' AND postmeta.meta_value LIKE %s
					)
                    {$possible_id_q}
				)
				AND posts.post_type IN ('" . implode( "','", $post_types ) . "')
				AND posts.post_status IN ('" . implode( "','", $post_statuses ) . "')
				{$type_where}
				ORDER BY posts.post_parent ASC, posts.post_title ASC", $like_term, $like_term ) );

        if ( is_numeric( $term ) ) {
            $post_id   = absint( $term );
            $post_type = get_post_type( $post_id );

            if ( 'product_variation' === $post_type && $include_variations ) {
                $product_ids[] = $post_id;
            } elseif ( 'product' === $post_type ) {
                $product_ids[] = $post_id;
            }

            $product_ids[] = wp_get_post_parent_id( $post_id );
            $this->_search_trace['numeric'] = 1;
        }
        $ids = wp_parse_id_list( $product_ids );
        $this->_search_trace['ids'] = $ids;
        return $ids;
    }
}
