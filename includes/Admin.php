<?php
/**
 * WP-Reactivate
 *
 *
 * @package   WP-Reactivate
 * @author    InfCrowds
 * @license   GPL-3.0
 * @link      https://goInfCrowds.com
 * @copyright 2017 InfCrowds (Pty) Ltd
 */

namespace InfCrowds\WPR;
use INFCWDS_WC_Compatibility;
 
/**
 * @subpackage Admin
 */
class Admin
{

    /**
     * Plugin basename.
     *
     * @since    1.0.0
     *
     * @var      string
     */
    protected $plugin_basename = null;

    /**
     * Slug of the plugin screen.
     *
     * @since    1.0.0
     *
     * @var      string
     */
    protected $plugin_screen_hook_suffix = null;

    /**
     * Initialize the plugin by loading admin scripts & styles and adding a
     * settings page and menu.
     *
     * @since     1.0.0
     */
    public function __construct($plugin, $store)
    {
        $this->_store = $store;
        $this->_plugin = $plugin;
        $this->plugin_slug = $plugin->get_plugin_slug();
        $this->version = $plugin->get_plugin_version();
        $this->errors = null;
        $this->plugin_basename = plugin_basename(plugin_dir_path(realpath(dirname(__FILE__))) . $this->plugin_slug . '.php');
        
        $this->do_hooks();
    }


    /**
     * Handle WP actions and filters.
     *
     * @since    1.0.0
     */
    private function do_hooks()
    {
        // Load admin style sheet and JavaScript.
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Add the options page and menu item.
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'admin_order_list_show_upsell_info'), 100);
        // Add plugin action link point to settings page
        // add_filter( 'plugin_action_links_' . $this->plugin_basename, array( $this, 'add_action_links' ) );
    }

    
    public function admin_order_list_show_upsell_info($column) {
        global $post;

        if ( 'order_total' === $column ) {
    
            $order    = wc_get_order( $post->ID );
            if(!$order->is_paid()) {
                return ;
            }
            $currency = is_callable( array( $order, 'get_currency' ) ) ? $order->get_currency() : $order->order_currency;
            $rev     = INFCWDS_WC_Compatibility::get_order_meta( $order, '_infcrwds_revenue' );
            if(!empty($rev)) {
                echo '<b style="display:block;font-style:italic;">(' . wc_price( $rev, array( 'currency' => $currency ) ) . ' Infinitycrowd Upsell)</b>';
            }
        }
    }

    /**
     * Register and enqueue admin-specific style sheet.
     *
     * @return    null    Return early if no settings page is registered.
     * @since     1.0.0
     *
     */
    public function enqueue_admin_styles()
    {
        if (!isset($this->plugin_screen_hook_suffix)) {
            return;
        }
        $screen = get_current_screen();
        if ($this->plugin_screen_hook_suffix == $screen->id) {
            wp_enqueue_style($this->plugin_slug . '-style', plugins_url('assets/css/admin.css', dirname(__FILE__)), array(), $this->version);
        }
    }

    /**
     * Register and enqueue admin-specific javascript
     *
     * @return    null    Return early if no settings page is registered.
     * @since     1.0.0
     *
     */
    public function enqueue_admin_scripts()
    {
        if (!isset($this->plugin_screen_hook_suffix)) {
            return;
        }

        $screen = get_current_screen();
        if ($this->plugin_screen_hook_suffix == $screen->id) {

            // Commons::enqueue_entrypoint('admin', $this->version);

            wp_localize_script($this->plugin_slug . '-admin', 'wpr_object', array(
                    'api_nonce' => wp_create_nonce('wp_rest'),
                    'api_url' => rest_url($this->plugin_slug . '/v1/'),
                    'assets_url'  => plugins_url( 'assets', dirname( __FILE__ ) ),
                )
            );
        }
    }

    public function register_admin_menu()
    {
        $this->plugin_screen_hook_suffix = add_menu_page(
            __('Infinitycrowds', 'infcrwds-offer-network'),
            'Infinitycrowd',
            'manage_woocommerce',
            'infcrwds',
            array(
                $this,
                'display_plugin_admin_page'
            ),
            plugins_url('assets/imgs/logo_small.png', dirname( __FILE__ ))
        );
    }

    /**
     * Render the settings page for this plugin.
     *
     * @since    1.0.0
     */
    public function display_plugin_admin_page()
    {
        $this->process_form_actions();
    }

    private function ensure_api_keys() {
        global $wpdb;
        $errors = array();
        try {
            $key_id = $this->_store->get('wc_key_id', -1);
            $user_id = get_current_user_id();
            $permissions = 'read_write';
            $description = 'Infinitycrowd API Key';
            $consumer_key = null;
            $consumer_secret = null;
            if ( -1 !== $key_id ) {
                
                $data = array(
                    'user_id'     => $user_id,
                    'description' => $description,
                    'permissions' => $permissions,
                );

                $wpdb->update(
                    $wpdb->prefix . 'woocommerce_api_keys',
                    $data,
                    array( 'key_id' => $key_id ),
                    array(
                        '%d',
                        '%s',
                        '%s',
                    ),
                    array( '%d' )
                );
                $theapikey = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM ". $wpdb->prefix . "woocommerce_api_keys WHERE key_id = %d", array($key_id)));
                if($theapikey !== null) {
                    return $errors;
                }

            }
            
            $consumer_key    = 'ck_' . wc_rand_hash();
            $consumer_secret = 'cs_' . wc_rand_hash();
            
            $data = array(
                'user_id'         => $user_id,
                'description'     => $description,
                'permissions'     => $permissions,
                'consumer_key'    => wc_api_hash( $consumer_key ),
                'consumer_secret' => $consumer_secret,
                'truncated_key'   => substr( $consumer_key, -7 ),
            );

            $wpdb->insert(
                $wpdb->prefix . 'woocommerce_api_keys',
                $data,
                array(
                    '%d',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                )
            );

            $key_id = $wpdb->insert_id;
            $this->_store->set('wc_key_id', $key_id);
            
            $request = new ICRequest($this->_store);
            $resp = $request->post('/woocommerce/keys', array(
                'consumer_key' => $consumer_key,
                'consumer_secret' => $consumer_secret,
            ), null, array(
                'timeout' => 20
            ));
            if($resp === false) {
                $delete = $wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' => $key_id ), array( '%d' ) );
                $errors = $this->make_errors_from_response($errors, $request);

            }
        } catch(Exception $x) {
            $errors[] = "Woocommerce - Infinintycrowds authentication error. please contact support";
            InfcrwdsPlugin()->logger->error(
                "error while ensuring auth keys:" . $x->getMessage());
        }
        return $errors;
    }
    
    
    public function process_form_actions() {
        // if (isset($_POST['infcrwds_register'])) {
        //     check_admin_referer('infcrwds_registration_form');
        //     $this->errors = $this->proccess_registeration();
        //     if(count($this->errors) > 0) {
        //         include( INFCWDS_PLUGIN_DIR . '/views/register.php');
        //         $this->display_form_message($this->errors, true);
        //         return;
        //     }
        // } else 
        if(isset($_POST['infcrwds_login'])) {
            check_admin_referer('infcrwds_login_form');
            $this->errors = $this->process_login();
            if(count($this->errors) > 0) {
                include( INFCWDS_PLUGIN_DIR . '/views/login.php');
                $this->display_form_message($this->errors, true);
                return;
            }
        }
        else if(isset($_POST['infcrwds_settings'])) {
            check_admin_referer('infcrwds_settings_form');
            $this->_store->set('logs_enabled', isset($_POST['logs_enabled']) && $_POST['logs_enabled'] == 'on');;
            $this->_store->set('defer_admin_email', isset($_POST['defer_admin_email']) && $_POST['defer_admin_email'] == 'on');;
            if(isset($_POST['gateways'])) {
                $this->_store->set('gateways', $_POST['gateways']);
            } else {
                $this->_store->set('gateways', null);
            }
        }
        else if(isset($_POST['log_in_button'])) {
            include( INFCWDS_PLUGIN_DIR . '/views/login.php');
            return;
        }
        else if(isset($_POST['infcrwds_logout'])) {
            $req = new ICRequest($this->_store);
            $req->post('/merchants/logout/plugin', array(
                "reason" => null,
                "is_deactivate" => true
            ), array(
                'HTTP-CLIENT-IP' => Commons::GetIP(),
            ), array('user-agent' => wc_get_user_agent()));
            $key_id = $this->_store->get('wc_key_id', -1);
            if($key_id > 0) {
                global $wpdb;
                $delete = $wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' => $key_id ), array( '%d' ) );
            }
            $updated = update_option('infcrwds_global_settings', array(
                'ic_server_baseurl' => 'https://api.infinitycrowds.com/api/v1',
                'gateways' => array(),
                'is_internal_upsell_enabled' => true,
                "is_pp_ref_trans_enabled" => false,
                "add_widget_to_order_recieved_email" => true,
                "logs_enabled" => false,
                "track" => true
            ));

            include( INFCWDS_PLUGIN_DIR . '/views/login.php');
            return;
        }
        $req = new ICRequest($this->_store);
        if($req->invalid) {
            include( INFCWDS_PLUGIN_DIR . '/views/login.php');
            return;
        }
        $response = $req->post('/auth/sig', array("date" => gmdate('D, d M Y H:i:s T')));
        if($response === false) {
            include( INFCWDS_PLUGIN_DIR . '/views/login.php');
        } else {
            $errors = $this->ensure_api_keys();
            $ic_status = 'Connected';
            $settings_enabled = true;
            if(count($errors)) {
                $settings_enabled = false;
                $this->display_form_message($errors, true);
                $ic_status = 'Connectivity Issues';
            }
            $_base_url = $this->_store->get('ic_server_baseurl');
            $scheme = parse_url($_base_url, PHP_URL_SCHEME);
            $host =  parse_url($_base_url, PHP_URL_HOST);
            $port = parse_url($_base_url, PHP_URL_PORT);
            $resp = json_decode($response);
            
            $dashboard_link = $scheme . '://' . $host . ':' . $port . '/dashboard/go?token=' . $resp->access_token;
            $logs_enabled = $this->_store->get('logs_enabled');
            $gateways = $this->_store->get('gateways');
            $supported_gateways = array();
            $defer_admin_email = $this->_store->get('defer_admin_email', false);

            foreach($this->_plugin->gateway_mgr->get_supported_gateways() as $k=>$v) {
                $supported_gateways[$k] = $k;
            }
            if($this->_plugin->offer_page !== null) {
                $this->_plugin->offer_page->ensure();
            }
            include( INFCWDS_PLUGIN_DIR . '/views/settings.php');
        }
            
    }
    function display_form_message($messages = array(), $is_error = false) {
        $class = $is_error ? 'error' : 'updated fade';
        if (is_array($messages)) {
            foreach ($messages as $message) {
                ?>
                <div id='message' class='<?php echo $class ?>'><p><strong><?php echo $message ?></strong></p></div>
                <?php
                // echo "<div id='message' class='$class'><p><strong>$message</strong></p></div>";
            }
        } elseif (is_string($messages)) {
            ?>
            <div id='message' class='<?php echo $class ?>'><p><strong><?php echo $messages ?></strong></p></div>
            <?php
        }
    }
    
    function make_errors_from_response($errors, $req) {
        $status_code = $req->err_n;
        if($status_code === 400 || $status_code === 401) {
            $server_response = json_decode($req->server_output);
            foreach(get_object_vars($server_response) as $key => $validation_errors) {
                if(is_array($validation_errors)) {
                    foreach ($validation_errors as $err) {
                        array_push($errors, $key . ': ' . $err);
                    }
                } else {
                    array_push($errors, $key . ': ' . $validation_errors);
                }

            }
        } else {
            array_push($errors, 'unknown error occured, please try again later');
        }
        return $errors;
    }

    function process_login() {
        $errors = array();
        if ($_POST['infcrwds_email'] === '') {
            array_push($errors, 'Provide valid email address');
        }
        if (!isset($_POST['infcrwds_password'])){
            array_push($errors, 'Password no provided');
        }
        if(count($errors) == 0) {
            $shop_url = get_bloginfo('url');
            $shop_domain = parse_url($shop_url, PHP_URL_HOST);
            return $this->_make_login(
                $_POST['infcrwds_email'],
                $_POST['infcrwds_password'], 
                $shop_domain);
        }
        return $errors;
    }

    function _make_login($email, $password, $shop_url) {
        $errors = array();
        $req = new BasicICRequest($this->_store);
        $resp = $req->post('/auth/login', array(
            "email" => $email,
            "password" => $password,
            "shop_url" => $shop_url
        ), null, array(
            'timeout' => 20
        ));
        if(!$resp) {
            $errors = $this->make_errors_from_response($errors, $req);
        } else {
            $login_resp = json_decode($resp);
            if($login_resp->billing_agreement_id === null) {
                array_push($errors, 'No active plan. please register at <a href="https://infinitycrowd.io/register" target="_blank">infinitycrowd.io</a>');
                return $errors;
            }
            if(!property_exists($login_resp, "merchant_id") || $login_resp->merchant_id === null) {
                $req = new BasicICRequest($this->_store);
                $language = get_bloginfo('language');
                $resp = $req->post('/register/merchant', array(
                    "shop_url" => get_bloginfo('url'),
                    "currency_code" => get_woocommerce_currency(),
                    "language" => $language,
                ), array(
                    'Authorization' => "JWT " . $login_resp->access_token
                ), array(
                    'timeout' => 20
                ));
                if(!$resp) {
                    $errors = $this->make_errors_from_response($errors, $req);
                    return $errors;
                }
                $resp = $req->post('/auth/login', array(
                    "email" => $email,
                    "password" => $password,
                    "shop_url" => $shop_url
                ), null, array(
                    'timeout' => 20
                ));
                if(!$resp) {
                    array_push($errors, 'Shop login failed, please contact support@infinitycrowds.com');
                    return $errors;
                }
                $login_resp = json_decode($resp);
                if(!property_exists($login_resp, "merchant_id") || $login_resp->merchant_id === null) {
                    array_push($errors, 'Shop registration failed, please contact support@infinitycrowds.com');
                    return $errors;
                }
            }
            $this->_store->set('ic_merchant_id', $login_resp->merchant_id);
            $resp = $req->post('/auth/api-key', array(
            ), array(
                'Authorization' => "JWT " . $login_resp->access_token
            ), array(
                'timeout' => 20
            ));
            if(!$resp) {
                if($req->err_n === 422) {
                    $resp = $req->get('/auth/api-key', array(
                        'Authorization' => "JWT " . $login_resp->access_token
                    ), array(
                        'timeout' => 20
                    ));
                    if(!$resp) {
                        array_push($errors, 'Unable to get api keys');
                    } else {
                        $keys = json_decode($resp);
                        $this->_store->set('ic_api_key', $keys->api_key);
                        $this->_store->set('ic_secret', $keys->secret);

                        $this->_plugin->initialize($this->_store);
                    }
                } else {
                    array_push($errors, 'API keys creation error');
                }
            } else {
                $keys = json_decode($resp);
                $this->_store->set('ic_api_key', $keys->api_key);
                $this->_store->set('ic_secret', $keys->secret);
                $this->_plugin->initialize($this->_store);
            }
        }
        return $errors;
    }

    /**
     * Add settings action link to the plugins page.
     *
     * @since    1.0.0
     */
    public function add_action_links($links)
    {
        return array_merge(
            array(
                'settings' => '<a href="' . admin_url('options-general.php?page=' . $this->plugin_slug) . '">' . __('Settings', $this->plugin_slug) . '</a>',
            ),
            $links
        );
    }
}
