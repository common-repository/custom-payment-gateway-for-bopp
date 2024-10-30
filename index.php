<?php
/*
Plugin Name: Custom Payment Gateway for BOPP
Description: Extends WooCommerce to Process Payments with BOPP gateway
Version: 2.2
License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/
add_action("plugins_loaded", "woocommerce_bopp_payment_init", 0);
function woocommerce_bopp_payment_init()
{
    if (!class_exists("WC_Payment_Gateway")) {
        return;
    }
    /**
     * Localisation
     */
    load_plugin_textdomain(
        "wc-bopppayment",
        false,
        dirname(plugin_basename(__FILE__)) . "/languages"
    );

    /**
     * BOPP Payment Gateway class
     */
    class WC_BOPPPayment extends WC_Payment_Gateway
    {
      
        protected $msg = [];
        static $logger;
	const WC_LOG_FILENAME = 'bopp-payment-gateway';

        public function __construct()
        {
          

            $sandbox_skd = "https://bopp.io/public/scripts/sdkdev.js";
            $live_sdk = "https://bopp.io/public/scripts/sdk.js";
            $allowed_html_tags = array(
                'table' => array(
                    'class' => array(),
                    'id' => array(),
                    'style' => array(),
                    'border' => array(),
                    'cellpadding' => array(),
                    'cellspacing' => array(),
                    'width' => array(),
                ),
                'input' => array(
                    'type'  => array(),
                    'id'    => array(),
                    'name'  => array(),
                    'value' => array()
                 ),
                 "script" => array(
                    "src" => array()
                 ),
                    "div" => array(
                        "id" => array(),
                        "class" => array()
                    ),
                    "span" => array(
                        "id" => array(),
                        "class" => array()
                    ),
                    "img" => array(
                        "src" => array(),
                        "alt" => array()
                    ),
                    "a" => array(
                        "href" => array(),
                        "class" => array()
                    ),
                    "button" => array(
                        "class" => array(),
                        "id" => array(),
                        "type" => array(),
                        "name" => array(),
                        "value" => array()
                    ),
                    "label" => array(
                        "for" => array()
                    ),
                    "p" => array(
                        "class" => array()
                    ),
                    "h1" => array(
                        "class" => array()
                    ),
                    "h2" => array(
                        "class" => array()
                    ),
                    "h3" => array(
                        "class" => array()
                    ),
                    "h4" => array(
                        "class" => array()
                    ),
                    "h5" => array(
                        "class" => array()
                    ),
            );
            
             $this->allowed_html_tags = $allowed_html_tags;
             $this->id = "bopppayment";
             $this->title = __("BOPP", "wc-bopppayment");
             $this->method_title = __("BOPP", "wc-bopppayment");
             $this->method_description = sprintf(esc_html__('Bank transfers made easy.%s Get paid instantly with no card fees and no chargebacks!',  'wc-bopppayment' ),"<br/>");
             $this->description		   = sprintf(esc_html__('Bank transfers made easy.%s Get paid instantly with no card fees and no chargebacks.%s Powered by BOPP &#174;.', 'wc-bopppayment'), "<br/>", "<br/>");
             $this->icon =
                WP_PLUGIN_URL .
                "/" .
                plugin_basename(dirname(__FILE__)) .
                "/logo.png";
            $this->has_fields = true;
            $this->init_form_fields();
            $this->init_settings();
            $this->merchantkey = $this->settings["merchantkey"];
            $this->sandbox = $this->settings["sandbox"];
            $this->sdk_url = $live_sdk;
            if ($this->sandbox === "yes"){
//              $this->sdk_url = $sandbox_skd;
            }
            //$this->sdkEndpoint = 
            $this->msg["message"] = "";
            $this->msg["class"] = "";

            if ( empty( self::$logger ) ) {
                self::$logger = wc_get_logger();
            }

           

            if (version_compare(WOOCOMMERCE_VERSION, "2.0.0", ">=")) {
                add_action(
                    "woocommerce_update_options_payment_gateways_" . $this->id,
                    [&$this, "process_admin_options"]
                );
            } else {
                add_action("woocommerce_update_options_payment_gateways", [
                    &$this,
                    "process_admin_options",
                ]);
            }
            add_action("woocommerce_receipt_" . $this->id, [
                &$this,
                "receipt_page",
            ]);

            add_action("woocommerce_api_process_bopp_payment", [
                &$this,
                "process_bopp_payment",
            ]);
        }

        function init_form_fields()
        {
            $this->form_fields = [
                "enabled" => [
                    "title" => __("Enable/Disable", "wc-bopppayment"),
                    "type" => "checkbox",
                    "label" => __(
                        "Enable BOPP Payments Payment Module.",
                        "wc-bopppayment"
                    ),
                    "default" => "no",
                ],
                "sandbox" => [
                  "title" => __("Enable/Disable", "wc-bopppayment"),
                  "type" => "checkbox",
                  "label" => __(
                      "Enable BOPP Sandbox Mode",
                      "wc-bopppayment"
                  ),
                  "default" => "yes",
              ],
                "merchantkey" => [
                    "title" => __("Merchant Key:", "wc-bopppayment"),
                    "type" => "text",
                    "description" => __(
                        "This is the Key provided by BOPP after registration",
                        "wc-bopppayment"
                    ),
                ]
            ];
        }

        /**
         * Admin Panel Options
         *
         **/
        public function admin_options()
        {
            echo wp_kses("<h3>" .
                __("BOPP Payment Gateway", "wc-bopppayment") .
                "</h3>", $this->allowed_html_tags);

            echo wp_kses("<p>BOPP is the most popular payment gateway for online payment processing trough Open Banking Standard.</p>", $this->allowed_html_tags);

            echo wp_kses('<table class="form-table">', $this->allowed_html_tags);

            $this->generate_settings_html();

            echo wp_kses("</table>", $this->allowed_html_tags);
        }

        /*
         *  Fields for Bopp
         *
         */
        function payment_fields()
        {
            if ($this->description) {
                echo wp_kses(wpautop(wptexturize($this->description)), $this->allowed_html_tags);
            }
        }

        /*
         *
         * Receipt Page
         */
        function receipt_page($order)
        {
            echo wp_kses("<p class='bopp_msg'>" .
                __(
                    "Thank you for your order, please click the button below to pay with BOPP.",
                    "wc-bopppayment"
                ) .
                "</p>", $this->allowed_html_tags);
            echo wp_kses($this->bopp_payment_form($order),$this->allowed_html_tags);
        }

        /*
         * BOPP redirect URL
         *
         */
        function bopp_redirect_url($order)
        {
            $redirect_url = $this->get_return_url($order);
            return $redirect_url;
        }

        /*
         * BOPP Payment form
         *
         */
        public function bopp_payment_form($order_id)
        {
            global $woocommerce;
            $order = new WC_Order($order_id);
            $txnid = $order_id . "_" . date("ymd");
            $redirect_url = $this->bopp_redirect_url($order);
            $cancel_url = $order->get_cancel_order_url();
            $site_url = get_site_url();
            $on_success_post_url = $site_url."/?wc-api=process_bopp_payment";
            $orderReference="";
            if (isset($_GET['order'])) {
                $orderReference = $_GET['order'];
            }else {
                $orderReference = $order->get_id();
            }
            return '<div id="bopp_buttons"> 
                <div id="bopp" style="margin-bottom: 5px;"></div>
                <div>
                <a class="button cancel" href="' .
                $cancel_url .
                '">' .
                __("Cancel order &amp; restore cart", "wc-bopppayment") .
                '</a></div>
                </div>

            <script type="text/javascript">


				jQuery(function($){ 

          const orderRefId =  "'.$orderReference.'";
          const merchantKey = "'.$this->merchantkey.'"
          const sdk_url = "'.$this->sdk_url.'"
          const orderTotal = '. $order->get_total().'
          const orderReference = "bopp"+orderRefId
          const site_url = "'.$site_url.'"
          const redirect_url = "'.$redirect_url.'"
          const cancel_url = "'.$cancel_url.'"
          const on_success_post_url = "'.$on_success_post_url.'"
          const bopp_msg_alert_default_style={"color":"white","padding":"5px","border-radius":"5px"}
          

          function t(){

            const boppButton = BoppButton({
                key: merchantKey,
                container: document.getElementById("bopp")
             });
             var paymentElementLocator = ".woocommerce-Price-amount"
             var paymentAmount = /(\d+\,\d+)/.exec($(paymentElementLocator).html());
             if (paymentAmount == null) {
               paymentAmount = /(\d+\.\d+)/.exec($(paymentElementLocator).html());
             }
        
             boppButton.setCallback(
                function(){
              setTimeout(function() {
                window.location.replace(redirect_url);
              }, 2000);
              
             }, 
             function(){
              $(".bopp_msg").text("The payment attempt was cancelled by the bank/user.")
              $(".bopp_msg").css(bopp_msg_alert_default_style).css({"background-color":"orange"})
             }, 
             function(){
              $(".bopp_msg").text("The payment attempt failed.")
              $(".bopp_msg").css(bopp_msg_alert_default_style).css({"background-color":"red"})
             
             })

             boppButton.setAmount(parseFloat(orderTotal), "GBP");
             boppButton.setPaymentReference(orderReference)
             boppButton.setOnSuccessPost(on_success_post_url)
             boppButton.setEnabled(true);
    

            }

          const script = document.createElement("script");
          script.src = sdk_url;
          script.type = "text/javascript";
          script.addEventListener("load", t);



         setTimeout(function(){
            console.log($(".abb-overlay"))
            $(".abb-overlay").css("z-index","999 !important");
        }, 1000);

      document.head.appendChild(script);
});</script>';
        }

        function process_payment($order_id)
        {
          global $woocommerce;
          $order = new WC_Order($order_id);

          //error_log("Process payment");
          //error_log(print_r($order, true));

          return [
              "result" => "success",
              "redirect" => add_query_arg(
                  "order",
                  $order->get_id(),
                  add_query_arg(
                      "key",
                      $order->get_order_key(),
                      $order->get_checkout_payment_url(true)
                  )
              ),
          ];
        }

        function process_bopp_payment()
        {
          $this->add_cors_http_header($this->sandbox);
          $request_data='';
            try{
            $request_data = $this->get_request_data();

            } catch(Exception $e) {
   

                self::$logger->warning($e->getMessage(), [ 'source' => self::WC_LOG_FILENAME ]);
                if ($request_data){
                    self::$logger->info('Issue while reading the update coming from bopp: ' . print_r($request_body, TRUE) . '.', [ 'source' => self::WC_LOG_FILENAME ]);
                } 
                status_header(400);
                exit();
            }
   

            try{
            $jwtOK = $this->checkJWT($request_data);
            if ($jwtOK){
                $this->process_order("success", $request_data, "");
            } else {
                $this->process_order("fail", $request_data, "Payment failed...");
            }
 
            } catch(Exception $e) {
                self::$logger->warning($e->getMessage(), [ 'source' => self::WC_LOG_FILENAME ]);
                if ($request_data){
                    self::$logger->info('Issue while validating the payment signature: ' . print_r($request_body, TRUE) . '.', [ 'source' => self::WC_LOG_FILENAME ]);
                } 
                status_header(400);
                exit();
            }
        }

        function checkJWT(){
            return true;       
        }

        function get_request_data()
{
    $body = file_get_contents("php://input");
    if (!$body) {
        throw new Exception("Incorrect request data.");
    }
    $request_data = json_decode($body, true);
    return $request_data;
}


function add_cors_http_header($sandbox)
{
    if ($sandbox === "yes"){
        header("Access-Control-Allow-Origin: *");
    } else {
        header("Access-Control-Allow-Origin: https://bopp.io");
    }
    
    header(
        "Access-Control-Allow-Methods: POST,PUT,OPTIONS"
    );
    header(
        "Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token, XMLHttpRequest, user-agent, accept, x-requested-with"
    );
    if ("OPTIONS" == $_SERVER["REQUEST_METHOD"]) {
        status_header(200);
        exit();
    }
}
        


        function process_order($paymentStatus, $request_data, $message)
        {
            global $woocommerce;



           $txnReferenceNo = $request_data["paymentInstructionId"];
           $reference = $request_data["reference"];
            $status = "";
            $message = $message;

            /* Parse authStatus end*/
            $referenceComponents = explode(
                "bopp",
                $reference
            );
            $order_id = end($referenceComponents);

            if ($order_id != "") {
                try {
                    $order = new WC_Order($order_id);

                    
                    
                    if ($order->status !== "completed") {
                        $status = strtolower($paymentStatus);
                        if ($status == "success") {
                            $this->msg["class"] = "success";
                            $this->msg["message"] =
                                "The order is paid. Nothing else to update.";
                            if ($order->status == "processing") {
                            } else {
                                $order->payment_complete();
                                $order->add_order_note(
                                    "BOPP payment successful.\n Unique BOPP payment ID: " .
                                        $txnReferenceNo
                                );
                                $order->add_order_note($this->msg["message"]);
                                $woocommerce->cart->empty_cart();
                            }
                        } elseif ($status == "cancel") {
                            $this->msg["message"] = "The payment was canceled!";
                            $this->msg["class"] = "error";
                            $order->add_order_note(
                                "The order payment was canceled"
                            );
                            $order->update_status("cancelled");
                            $woocommerce->cart->empty_cart();
                        } elseif ($status == "pending") {
                            $this->msg["message"] =
                                "Thank you for shopping with us. Right now your payment staus is pending, We will keep you posted regarding the status of your order through e-mail";
                            $this->msg["class"] = "error";
                            $order->add_order_note(
                                "BOPP payment status is pending (" .
                                    $message .
                                    ")<br/>Unnique Id from BOPP: " .
                                    $txnReferenceNo
                            );
                            $order->add_order_note($this->msg["message"]);
                            $order->update_status("on-hold");
                            $woocommerce->cart->empty_cart();
                        } else {
                            $this->msg["class"] = "error";
                            $this->msg["message"] =
                                "The transaction has been declined due to " .
                                $message;
                            $order->add_order_note("Error: " . $status);
                            $order->update_status("failed");
                            $woocommerce->cart->empty_cart();
                        }
                    }
                } catch (Exception $e) {
                    $this->msg["class"] = "error";
                    $this->msg["message"] =
                        "An unexpected error occurred! Transaction failed.";
                }
            }

            /*Register message to the woocommerce*/
            wc_add_notice(
                __($this->msg["message"], "woocommerce"),
                $this->msg["class"]
            );

/*         
            $redirect_url = $this->get_return_url($order);
            if (wp_redirect($redirect_url)) {
                exit();
            } */
        }


    }
}







/*  function add_cors_http_header()
{
    header("Access-Control-Allow-Origin: *");
    header(
        "Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS, READ"
    );
    header(
        "Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token,authorization,XMLHttpRequest, user-agent, accept, x-requested-with"
    );
    header("Access-Control-Allow-Credentials: true");

    if ("OPTIONS" == $_SERVER["REQUEST_METHOD"]) {
        status_header(200);
        exit();
    }
} */ 


/**
 * Add BOPP Payment Gateway to WooCommerce
 **/
function woocommerce_add_bopp_gateway($methods)
{
    $methods[] = "WC_BOPPPayment";
    return $methods;
}
add_filter(
    "woocommerce_payment_gateways",
    "woocommerce_add_bopp_gateway"
);
