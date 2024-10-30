<?php
namespace InfCrowds\WPR\Gateways;

use INFCWDS_WC_Compatibility;
use \InfCrowds\WPR\ICRequest;

class GatewayCardCom_Integration extends GatewayIntegrationBase {

	protected $key = 'cardcom';

	/**
	 * Constructor
	 */
	public function __construct($store, $order_maker_factory) {

        parent::__construct($store, $order_maker_factory);
        add_action('valid-cardcom-successful-request', array($this, 'cardcom_successfull'));
        add_filter('woocommerce_payment_gateway_save_new_payment_method_option_html', array($this, 'override_cardcom_save_token_cb'), 10, 2);
        // add_filter('infcrwd_can_delete_order', array($this, 'can_delete_order'), 10, 2);
    }
    
    public function override_cardcom_save_token_cb($html, $gw) {
        if($this->is_enabled() && $gw->id === 'cardcom') {
            return str_replace('<input ', '<input checked ', $html);
        }
        return $html;
    }
  
    // public function can_delete_order($current_val, $order_id) {
    //     return get_post_meta( $order_id, '_payment_method', true ) != $this->key;
    // }
    
    // only delete the order and redirect when we get cardcom successfull
    public function cardcom_successfull($posted){
        $orderid = htmlentities($posted["order_id"]);
        $order = new \WC_Order( $orderid);
        if(empty($order)) {
            $order = $this->_store->get_base_order();
        }
        $merge_oids = INFCWDS_WC_Compatibility::get_order_meta($order, '_infcrwds_order_merged');
        if(!empty($merge_oids)) {
            $order = new \WC_Order( $merge_oids[0]);
        }
        WC()->cart->empty_cart();
        $cardcom_gw = $this->get_wc_gateway();
        if($cardcom_gw->successUrl!=''){
            $redirectTo = $cardcom_gw->successUrl;
        }
        else{
            $redirectTo = $cardcom_gw->get_return_url( $order );
        }

        if($cardcom_gw->UseIframe){
            echo "<script>window.top.location.href =\"$redirectTo\";</script>";
            exit();
        }else{
            wp_redirect($redirectTo);
            exit();
        }
        return true;
    }
      
    public function add_payment_options($order, $payment_options) {
        if(!$this->has_token($order)) {
            return;
        }
        // $payment_gateway = wc_get_payment_gateway_by_order($order);
        // if(!$payment_gateway) {
        //     return $payment_options;
        // }
        // $req = new ICRequest($this->_store);
        // $req->post('/payment-gateways/cardcom/prep-merchant-confs', array(
        //     'terminal_num' => $payment_gateway->terminalnumber,
        //     'username' => $payment_gateway->username,
        // ));
        return parent::add_payment_options($order, $payment_options);
    }
    public function get_token($order) {
        $token_id = get_post_meta( $order->get_id(), 'CardcomTokenId', true );
        if(empty($token_id)) {

            InfcrwdsPlugin()->logger->log('CARDCOM token empty' );
            // Get orders by customer with email 'woocommerce@woocommerce.com'.
            $billing_email = INFCWDS_WC_Compatibility::get_order_data($order, 'billing_email');
            if(!empty($billing_email)) {
                $args = array(
                    'customer' => $billing_email,
                    'status' => 'completed', 
                );
            
              $orders = wc_get_orders( $args );
              foreach($orders as $past_order) {
                //    InfcrwdsPlugin()->logger->log('iterating order....' . json_encode($past_order->get_data()));
                  $token_id = get_post_meta( $past_order->get_id(), 'CardcomTokenId', true );
                  if (!empty($token_id)) {
                      break;
                  }
               }
               if(empty($token_id)) {
                $args = array(
                    'customer' => $billing_email,
                    'status' => 'processing', 
                );
                $orders = wc_get_orders( $args );
                foreach($orders as $past_order) {
                    //  InfcrwdsPlugin()->logger->log('iterating order....' . json_encode($past_order->get_data()));
                    $token_id = get_post_meta( $past_order->get_id(), 'CardcomTokenId', true );
                    if (!empty($token_id)) {
                        break;
                    }
                 }
               }
            }
        }
        return $token_id;
    }
    
	public function has_token( $order ) {
        return !empty($this->get_token($order));
    }

    public function initInvoice($order_id, $sum_to_bill, $pricing, $product_id){
        $cardcom_gw = $this->get_wc_gateway();
        
        $order = new \WC_Order( $order_id );
        $params = array();
        
        $SumToBill = number_format($order->get_total(), 2, '.', '') ;
        
        if(!empty(\WC_Gateway_Cardcom::$cvv_free_trm)){
            $params["terminalnumber"] = \WC_Gateway_Cardcom::$cvv_free_trm;
        }else{
            $params["terminalnumber"] = \WC_Gateway_Cardcom::$trm;
        }

        $params["username"] = \WC_Gateway_Cardcom::$user;
        $params["CodePage"] = "65001";

        $params["SumToBill"] =number_format($sum_to_bill , 2, '.', '');

        $params["Languge"] = \WC_Gateway_Cardcom::$language;

        $coin = \WC_Gateway_Cardcom::GetCurrency($order, \WC_Gateway_Cardcom::$CoinID);
        $params["CoinID"] = $coin;
        $params["CoinISOName"] = $order->get_currency();
        $compName = substr(strip_tags( preg_replace("/&#\d*;/", " ", $order->get_billing_company()) ), 0, 200);
        $lastName = substr(strip_tags( preg_replace("/&#\d*;/", " ", $order->get_billing_last_name()) ), 0, 200);
        $firstName = substr(strip_tags( preg_replace("/&#\d*;/", " ", $order->get_billing_first_name()) ), 0, 200);
        //$customerName = $order->get_billing_first_name()." ".$order->get_billing_last_name();
        $customerName = $firstName." ".$lastName;
        if($compName != ''){
            $customerName  =  $compName;
        }

        $params['InvoiceHead.CustName']			= $customerName ;
        $params['InvoiceHead.CustAddresLine1']	= substr(strip_tags( preg_replace("/&#\d*;/", " ", $order->get_billing_address_1()) ), 0, 200);
        $params['InvoiceHead.CustCity']	= substr(strip_tags( preg_replace("/&#\d*;/", " ", $order->get_billing_city()) ), 0, 200);

        $params['InvoiceHead.CustAddresLine2']	= substr(strip_tags( preg_replace("/&#\d*;/", " ", $order->get_billing_address_2()) ), 0, 200);
        $zip  = wc_format_postcode( $order->get_shipping_postcode(), $order->get_shipping_country());
        if(!empty($zip)){
            $params['InvoiceHead.CustAddresLine2'].=__( 'Postcode / ZIP', 'woocommerce' ).': '.$zip;
        }
        $params['InvoiceHead.CustLinePH']= substr(strip_tags( preg_replace("/&#\d*;/", " ", $order->get_billing_phone()) ), 0, 200);
        if(strtolower(\WC_Gateway_Cardcom::$language) =='he' || strtolower(\WC_Gateway_Cardcom::$language) =='en'){
            $params['InvoiceHead.Language']	= \WC_Gateway_Cardcom::$language;
        }else{
            $params['InvoiceHead.Language']	= 'en';
        }
        $params['InvoiceHead.Email'] = $order->get_billing_email();
        $params['InvoiceHead.SendByEmail']= 'true';

        $params['InvoiceHead.CoinID']= $coin;
        $params['InvoiceHead.CoinISOName']= $order->get_currency();
        // error_log('country : '.$order->get_billing_country());
        if($order->get_billing_country() != 'IL' && \WC_Gateway_Cardcom::$InvVATFREE ==4){
            $params['InvoiceHead.ExtIsVatFree'] ='true';
        }else {
            $params['InvoiceHead.ExtIsVatFree'] = \WC_Gateway_Cardcom::$InvVATFREE == '1' ? 'true' : 'false';
        }
        if(strtolower(\WC_Gateway_Cardcom::$language) =='he'){
            $params['InvoiceHead.Comments'] = 'מספר הזמנה: '.$order->get_id();
        }else{
            $params['InvoiceHead.Comments'] = 'Order ID: '. $order->get_id();
        }
        $taxes = $pricing['tax_cost'] + $pricing['shipping_tax'];
        $shipping_price = $pricing['shipping_price'];
        $item_price = $pricing['sale_price'];

        $params['InvoiceLines1.IsVatFree'] = $pricing['tax_cost'] == 0 ? 'true': 'false';
        $params['InvoiceLines1.Quantity'] = 1;
        $params['InvoiceLines1.ProductId'] =  $product_id;
        $offer_product = wc_get_product($product_id);
        
        $params['InvoiceLines1.Description'] = woocommerce_get_formatted_product_name($offer_product);
        $params['InvoiceLines1.Price'] = number_format($item_price + $pricing['tax_cost'], 2, '.', '');

        InfcrwdsPlugin()->logger->log('CARDCOM invoice lines price ' .  $item_price);
        // InfcrwdsPlugin()->logger->log('CARDCOM invoice pricing ' .  $pricing);
        InfcrwdsPlugin()->logger->log('CARDCOM invoice lines tax ' . $pricing['tax_cost']);
        InfcrwdsPlugin()->logger->log('CARDCOM invoice lines actual '. $params['InvoiceLines1.Price']);
        if($shipping_price > 0) {
                $params['InvoiceLines2.Description']= 'Shipping extra';
                $params['InvoiceLines2.Price']= $shipping_price;
                $params['InvoiceLines2.Quantity']=  1;
                $params['InvoiceLines2.ProductID']= "Shipping";
        }

        return $params;
    }

    /*
    *TOKENIZATION
    */
    function charge_token($token_id, $order_id , $sum_to_bill, $pricing, $product_id, $cvv =''){
        $token    = \WC_Payment_Tokens::get( $token_id );
        if ( $token->get_user_id() !== get_current_user_id() ) {
            // Optionally display a notice with `wc_add_notice`
            return false;
        }
        $order = new \WC_Order( $order_id );
        $params = array();
        $params = $this->initInvoice($order_id, $sum_to_bill, $pricing, $product_id);
        $coin =  \WC_Gateway_Cardcom::GetCurrency($order,\WC_Gateway_Cardcom::$CoinID);
        $params['TokenToCharge.APILevel']='9';
        $params['TokenToCharge.Token']=$token->get_token();
        $params['TokenToCharge.Salt']=''; #User ID or a Cost var.
        $params['TokenToCharge.CardValidityMonth']=$token->get_expiry_month();
        $params['TokenToCharge.CardValidityYear']=$token->get_expiry_year();
        $params['TokenToCharge.SumToBill']=number_format($sum_to_bill, 2, '.', '');

        $coin = \WC_Gateway_Cardcom::GetCurrency($order,\WC_Gateway_Cardcom::$CoinID);
        // $params['TokenToCharge.CoinID']=$coin;
        $params["TokenToCharge.CoinISOName"] = $order->get_currency();

        $params['TokenToCharge.UniqAsmachta']=$order_id;
        $params['TokenToCharge.CVV2']=$cvv;
        $params['TokenToCharge.NumOfPayments']='1';

        $params['CustomeFields.Field1'] = 'Cardcom Woo Token charge (Infinitycrowd)';
        $params['CustomeFields.Field2'] = "order_id:".$order_id;
        //$params['CustomeFields.Field2']='Custom e Comments 2';
        $cardcom_gw = $this->get_wc_gateway();
        $urlencoded = http_build_query($cardcom_gw->senitize($params));
        $args = array('body'=>$urlencoded,
            'timeout'=>'10',
            'redirection' => '5',
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => array(),
            'cookies' => array());
        $cardcom_gw = $this->get_wc_gateway();
        InfcrwdsPlugin()->logger->log('CARDCOM PAYMENT REQ', $urlencoded);
        $response =$cardcom_gw ->cardcom_post('https://secure.cardcom.solutions/interface/ChargeToken.aspx',$args);
        $body = wp_remote_retrieve_body( $response );
        InfcrwdsPlugin()->logger->log( 'CARDCOM UPSELL RESP :', print_r( $response, true ) );
        $responseArray =  array();
        $returnvalue = '1';
        parse_str($body,$responseArray);
        //error_log(implode(";",$responseArray));
        $this->InternalDealNumberPro =  0;
        if(isset($responseArray['ResponseCode']) && ( $responseArray['ResponseCode'] == '0' || $responseArray['ResponseCode'] == '608')){
            if(isset($responseArray['InternalDealNumber'])){
                $this->InternalDealNumberPro = $responseArray['InternalDealNumber'];
            } else {
                $this->InternalDealNumberPro = "9";
            }
            // wc_add_order_item_meta((int)$order_id, 'CardcomInternalDealNumber', 0 );
            update_post_meta( (int)$order_id , 'CardcomInternalDealNumber', $this->InternalDealNumberPro );

            $order->add_order_note( __('Token charge successfully completed! Deal Number:'.$this->InternalDealNumberPro, 'woocommerce') );
            return array(true,  $this->InternalDealNumberPro);
        }
        return false;
    }

	public function process_charge( $order, $offer ) {
        $is_successful = true;
        $trans_id = null;
        $pricing = $offer['pricing'];
        $taxes = $pricing['tax_cost'] + $pricing['shipping_tax'];
        $shipping_price = $pricing['shipping_price'];
        $item_price = $pricing['sale_price'];
        $product_id = $offer['product_id'];
        
        // $req = new ICRequest($this->_store);
        // $data = array(
        //         "amount" => $item_price + $shipping_price + $taxes,
        //         "invoice_num" => ltrim( $this->get_order_number( $order, $offer ), '#'),
        //         'order_id' => INFCWDS_WC_Compatibility::get_order_id( $order ),
        //         'currency_code' => INFCWDS_WC_Compatibility::get_order_data( $order, 'currency' )
        //     );
        // $response = $req->post('/payment-gateways/cardcom/pay-with-token', $data, null, array(
		// 	'timeout' => 90
        // ));
        $token_id = $this->get_token($order);
        if(empty($token_id)) {
            // $token_id = 1;  
            return array(false, $trans_id);
        }
        $response = $this->charge_token($token_id, $order->id, $item_price + $shipping_price + $taxes, $pricing, $product_id);
        if($response === false) {
            InfcrwdsPlugin()->logger->error( 'CARDCOM UPSELL ERROR :', print_r( $response, true ) );
            return array(false, $trans_id);
        }

		return $response;
    }
    
}