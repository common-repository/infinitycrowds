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
use WordpressEnqueueChunksPlugin;

class Commons {
    const CDN_URL = 'https://cdn.infinitycrowds.com/plugins/woo/';
    public static function GetIP()
    {
        $fallback_ip = null;
        foreach (array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR') as $key)
        {
            if (array_key_exists($key, $_SERVER) === true)
            {
                foreach (array_map('trim', explode(',', $_SERVER[$key])) as $ip)
                {
                    $fallback_ip = $ip;
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false)
                    {
                        return $ip;
                    }
                }
            }
        }
        return $fallback_ip;
    }

    public static function LOCAL__GetIP() {
        return '127.0.0.1';
    }


    public static function get_price_taxes( $price , $inclusive = false) {

		if ( false === wc_tax_enabled() ) {
			return $price;
		}
		$calculate_tax_for = \WC_Tax::get_tax_location();
		if ( empty( $calculate_tax_for ) ) {
			return 0;
		}
		$calculate_tax_for = array(
			'country'  => $calculate_tax_for[0],
			'state'    => $calculate_tax_for[1],
			'postcode' => $calculate_tax_for[2],
			'city'     => $calculate_tax_for[3],
		);
		$tax_rates = \WC_Tax::find_shipping_rates( $calculate_tax_for );

        $taxes = \WC_Tax::calc_tax( $price, $tax_rates, $inclusive );
        
        return $taxes;
    }

    public static function get_tax_cost($price, $inclusive) {
        $taxes = self::get_price_taxes($price, $inclusive);
        return is_array( $taxes ) ? array_sum( $taxes ) : 0;
    }

    public static function enqueue_entrypoint($name, $plugin_version) {
        $manifest = WordpressEnqueueChunksPlugin\get('manifest');
        $dir = trailingslashit(WordpressEnqueueChunksPlugin\get('assetsDir'));
        $handles = array();
        foreach (WordpressEnqueueChunksPlugin\getChunks(array($name), $manifest) as $chunk => $data) {
            if (WordpressEnqueueChunksPlugin\isRegistered($chunk)) {
                continue;
            }
            // $args = makeScriptArgs($chunk, $data);
            $handle = WordpressEnqueueChunksPlugin\makeHandle($chunk);
            $deps = WordpressEnqueueChunksPlugin\mapDependencies($chunk);
            Commons::enqueue_script($handle, $plugin_version, $dir . $data['file'],  $deps);
            $handles[] = $handle;
        }
        return $handles;
    }

    public static function get_static_url($script_rel_path, $plugin_version) {
        return Commons::CDN_URL . $plugin_version . '/' . $script_rel_path;
    }

    public static function enqueue_script($name,
                                          $plugin_version,
                                          $relative_path, $deps=array()) {                                                       
        wp_enqueue_script($name, Commons::get_static_url($relative_path, 'global'), $deps, null, true);
    }

    public static function LOCAL__enqueue_script($name,
                                          $plugin_version,
                                          $relative_path, $deps=array()) {
        wp_enqueue_script( $name, plugins_url( $relative_path, dirname( __FILE__ ) ), $deps, $plugin_version , true);
    }
    public static function enqueue_style($name,
                                          $plugin_version,
                                          $relative_path, $deps=array()) {
        wp_enqueue_style($name, Commons::get_static_url($relative_path, $plugin_version), $deps, null);
    }

    public static function LOCAL__enqueue_style(
        $name,
        $plugin_version,
        $relative_path, $deps=array()) {

        wp_enqueue_style($name, plugins_url( $relative_path, dirname( __FILE__ )), $deps, $plugin_version);
    }
}