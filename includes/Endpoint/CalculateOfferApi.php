<?php
/**
 * Upsell API
 *
 *
 * @package   InfinityCrowds
 * @author    InfinityCrowds
 * @license   GPL-3.0
 * @link      https://infinitycrowds.com
 * @copyright 2019 InfinityCrowds (Pty) Ltd
 */
namespace InfCrowds\WPR\Endpoint;

use \InfCrowds\WPR\OfferConverter;
use \InfCrowds\WPR\ICRequest;
use \InfCrowds\WPR\Commons;
use \InfCrowds\WPR\InvalidProductException;
use \InfCrowds\WPR\Gateways\GatewayIntegrationBase;


class CalculateOfferApi extends BaseApi
{
    function __construct($ns, $store, $gateway_mgr)
    {
        parent::__construct($ns, '/offer-calc/');
        $this->_store = $store;
        $this->_gateway_mgr = $gateway_mgr;
        add_action( 'wc_ajax_nopriv_calced_offer', array( $this, 'offer_calc' ) );
        add_action( 'wc_ajax_calced_offer', array( $this, 'offer_calc' ) );
    }

    public function woocommerce_permission_check($req) {
        return true;
    }
    
    public function register_routes()
    {
        // $this->add_route('get_setting', array());
        // $this->add_create_route('offer_calc', array());
    }

	/**
	 * Get the images for a product or product variation
	 *
	 * @since 2.1
	 * @param WC_Product|WC_Product_Variation $product
	 * @return array
	 */
	private function get_images( $product ) {
		$images        = $attachment_ids = array();
		$product_image = $product->get_image_id();
		// Add featured image.
		if ( ! empty( $product_image ) ) {
			$attachment_ids[] = $product_image;
		}
		// Add gallery images.
		$attachment_ids = array_merge( $attachment_ids, $product->get_gallery_image_ids() );
		// Build image data.
		foreach ( $attachment_ids as $position => $attachment_id ) {
			$attachment_post = get_post( $attachment_id );
			if ( is_null( $attachment_post ) ) {
				continue;
			}
            $attachment = wp_get_attachment_image_src( $attachment_id, 'full' );
            $thumbnail = wp_get_attachment_image_src( $attachment_id, 'thumbnail' );
			if ( ! is_array( $attachment ) ) {
				continue;
			}
			$images[] = array(
				'id'         => (int) $attachment_id,
                'src'        => current( $attachment ),
                'thumb'      => current($thumbnail),
				'title'      => get_the_title( $attachment_id ),
				'alt'        => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
				'position'   => (int) $position,
			);
		}
		// Set a placeholder image if the product has no images set.
		if ( empty( $images ) ) {
			$images[] = array(
				'id'         => 0,
                'src'        => wc_placeholder_img_src(),
                'thumb'        => wc_placeholder_img_src(),
				'title'      => __( 'Placeholder', 'woocommerce' ),
				'alt'        => __( 'Placeholder', 'woocommerce' ),
				'position'   => 0,
			);
		}
		return $images;
	}

    public function offer_calc( ) {
        try {
            check_ajax_referer( 'infcrwds_front', 'nonce' );
            $json_params = $_POST['data'];//$request->get_json_params();
            $offer_info = $json_params['offer'];
            $offer_order_id = $json_params['m_order_id']; // for verification
            $current_order = $this->_store->get_base_order();
            if(!$current_order) {
                return wp_send_json(array(
                    'success' => false,
                    'value' => 'order_not_found'
                ), 400);
            }
            $button_props = null;
            $order_id = null;
            $get_integration = null;
            $has_integration = false;

            $order_id = $current_order->get_id();
            $payment_options = array();

            $get_payment_gateway = \INFCWDS_WC_Compatibility::get_payment_gateway_from_order( $current_order );
            $get_integration = $this->_gateway_mgr->get_integration( $get_payment_gateway );
            $has_integration = $get_integration instanceof GatewayIntegrationBase && $get_integration->is_enabled();
            if($has_integration) {
                $payment_options = $get_integration->add_payment_options($current_order, $payment_options);
            }

            $conv = new OfferConverter(
                $offer_info,
                $this->_store,
                $current_order,
                isset($json_params['selected_variation_id']) ? $json_params['selected_variation_id']: null );
            $conv->calc_offer_pricing();
            
            $pricing = array(
                'quantity' => $conv->quantity,
                'item_price' => $conv->item_price,
                'sale_price' => $conv->sale_price,
                'regular_price' => $conv->regular_price,
                'regular_price_tax' => $conv->regular_price_tax,
                'shipping_price' => $conv->shipping_price,
                'shipping_type' => $conv->shipping_type,
                'shipping_label' => $conv->shipping_label,
                'original_shipping_price' => $conv->original_shipping_price,
                'original_shipping_tax' => $conv->original_shipping_tax,
                'tax_cost' => $conv->tax_cost,
                'has_shipping' => $conv->has_shipping,
                'shipping_tax' => $conv->shipping_tax,
                'currency_code' => $conv->selected_currency,
                'bc_sale_price' => $conv->base_currency_sale_price,
                'bc_sale_tax' => $conv->base_currency_tax_cost,
                'bc_shipping_price' => $conv->base_currency_shipping_price,
                'bc_shipping_tax' => $conv->base_currency_shipping_tax,
            );
            
            $token = bin2hex(openssl_random_pseudo_bytes(16));
            $this->_store->set_suggested_offer($token, array(
                'offer' => $conv->offer,
                'product_id' => $conv->selected_product->get_id(),
                'order_id' => $order_id,
                'offer_order_id' => $offer_order_id,
                'pricing' => $pricing,
                'selected_variant_id' => $conv->selected_variant_id
            ));
			if ( class_exists( 'WPBMap' ) && method_exists( 'WPBMap', 'addAllMappedShortcodes' ) ) {
				\WPBMap::addAllMappedShortcodes();
			}

            $variations_to_return = null;
            if($conv->variations) {
                $variations_to_return = array();
                foreach($conv->variations as $v) {
                    $attrs = [];
                    foreach($v['attributes'] as $at) {
                        $attrs[] = $at['name'] . ' - ' . $at['option'];
                    }
                    $variations_to_return[] = array(
                        'id' => $v['variation_id'],
                        'options' => join(' | ', $attrs),
                    );
                }
            }
            return wp_send_json(array(
                'success' => true,
                'value' => array(
                    'token'=> $token,
                    'order_id' => $order_id,
                    'offer_id' => $offer_info['offer_id'],
                    'gateway' => $has_integration ? $get_integration->get_key() : null,
                    'pricing' => $pricing,
                    'payment_options' =>  $payment_options,
                    'images' => $this->get_images($conv->selected_product),
                    'description_html' => $this->_store->get('deny_description_in_lander', false) ? null : apply_filters('the_content', do_shortcode($conv->selected_product->get_description())), 
                    'variations' => $variations_to_return,
                    'selected_variation_id' => $conv->selected_variant_id
                )
            ), 200 );
            
        } catch(InvalidProductException $e) {
            return wp_send_json( array(
                'success' => false,
                'value' => $e->getMessage()
            ), 400 );
        } catch(Exception $e) {
            InfcrwdsPlugin()->logger->error("calculate pricing error" . $x->getMessage());
            return wp_send_json( array(
                'success' => false,
                'value' => $e->getMessage()
            ), 500 );
        }
    }
}
