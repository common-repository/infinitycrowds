<?php
/**
 * Infinitycrowd
 *
 *
 * @package   Infinitycrowds
 * @author    Infinitycrowd LTD
 * @license   GPL-3.0
 * @link      https://infinitycrowd.io
 * @copyright 2020 Infinitycrowd (Pty) Ltd
 *
 * @wordpress-plugin
 * Plugin Name:       Infinitycrowds
 * Plugin URI:        https://infinitycrowd.io
 * Description:       Upsell and Retention Platform
 * Version:           1.0.153
 * Author:            Infinitycrowds
 * Author URI:        https://infinitycrowd.io
 * Text Domain:       infcrwds
 * License:           GPL-3.0
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.txt
 * Domain Path:       /
 */


namespace InfCrowds\WPR {

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}


/**
 * Autoloader
 *
 * @param string $class The fully-qualified class name.
 * @return void
 *
 *  * @since 1.0.0
 */
spl_autoload_register(function ($class) {

    // project-specific namespace prefix
    $prefix = __NAMESPACE__;

    // base directory for the namespace prefix
    $base_dir = __DIR__ . '/includes/';

    // does the class use the namespace prefix?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // no, move to the next registered autoloader
        return;
    }

    // get the relative class name
    $relative_class = substr($class, $len);

    // replace the namespace prefix with the base directory, replace namespace
    // separators with directory separators in the relative class name, append
    // with .php
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // if the file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});

function wc_version_check_notice() {
    ?>
    <div class="error">
        <p>
            <?php
            printf( __( '<strong> Attention: </strong>InfinityCrowds requires WooCommerce version %1$s or greater. Kindly update the WooCommerce plugin.', 'infcrwds' ), INFCWDS_MIN_WC_VERSION );
            ?>
        </p>
    </div>
    <?php
}

function infcrwds_is_woocommerce_active() {
    if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
        return true;
    }
    return false;
}
/**
 * Initialize Plugin
 *
 * @since 1.0.0
 */
function init() {
    if ( infcrwds_is_woocommerce_active() && class_exists( 'WooCommerce' ) ) {
        global $woocommerce;
        if ( ! version_compare( $woocommerce->version, INFCWDS_MIN_WC_VERSION, '>=' ) ) {
            add_action( 'admin_notices', 'InfCrowds\\WPR\\wc_version_check_notice' );

            return false;
        }
    }
    $store = new Store();
    $wpr = Plugin::get_instance();
    $wpr->basic_initialize($store);
    $logged_in = $store->is_logged_in();
    if($logged_in) {
        $wpr->initialize($store);
        $rest_router = new Endpoint\PublicRestRouter($store, $wpr->gateway_mgr, $wpr->offer_cart);
        $wc_rest = new Endpoint\WCRestRouter($store, $wpr->gateway_mgr);
    }
    if(is_admin()) {
        $wpr_admin = new Admin($wpr, $store);
        $rest_router = new Endpoint\AdminRestRouter($store, $wpr->gateway_mgr);
    }
}

add_action( 'plugins_loaded', 'InfCrowds\\WPR\\init' );

function define_plugin_properties() {
    define( 'INFCWDS_VERSION', '1.0.153' );
    define( 'INFCWDS_MIN_WC_VERSION', '3.0.0' );
    define( 'INFCWDS_MIN_WP_VERSION', '4.9' );
    define( 'INFCWDS_SLUG', 'infcrowds' );
    define( 'INFCWDS_FULL_NAME', __( 'Infinitycrowd: The First Personalized Post-Purchase Upsell', 'Infinitycrowd' ) );
    define( 'INFCWDS_PLUGIN_FILE', __FILE__ );
    define( 'INFCWDS_PLUGIN_DIR', __DIR__ );
    define( 'INFCWDS_TEMPLATE_DIR', plugin_dir_path( INFCWDS_PLUGIN_FILE ) . 'templates' );
    define( 'INFCWDS_PLUGIN_URL', untrailingslashit( plugin_dir_url( INFCWDS_PLUGIN_FILE ) ) );
    define( 'INFCWDS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
    define( 'INFCWDS_DB_VERSION', '1.0' );

    ( defined( 'INFCWDS_IS_DEV' ) && true === INFCWDS_IS_DEV ) ? define( 'INFCWDS_VERSION_DEV', time() ) : define( 'INFCWDS_VERSION_DEV', INFCWDS_VERSION );

}
define_plugin_properties();

require INFCWDS_PLUGIN_DIR . '/compatibilities/class-infcrwds-plugin-compatibilities.php';
require INFCWDS_PLUGIN_DIR . '/includes/wordpressEnqueueChunksPlugin.php';

/**
 * Register the widget
 *
 * @since 1.0.0
 */

 /**
 * Register activation and deactivation hooks
 */
register_activation_hook( __FILE__, array( 'InfCrowds\\WPR\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'InfCrowds\\WPR\\Plugin', 'deactivate' ) );
}
namespace {
    use InfCrowds\WPR\Plugin;

    if ( ! function_exists( 'InfcrwdsPlugin' ) ) {

        /**
         * Global Common function to load all the classes
         * @return Plugin
         */
        function InfcrwdsPlugin() {  //@codingStandardsIgnoreLine
            return Plugin::get_instance();
        }
    }
    $GLOBALS['InfcrwdsPlugin'] = InfcrwdsPlugin();
}
