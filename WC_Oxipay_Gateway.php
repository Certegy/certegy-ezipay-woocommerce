<?php
class WC_Oxipay_Gateway extends WC_Payment_Gateway {
        //current version of the plugin- used to run upgrade tasks on update
        public $plugin_current_version;

        //todo: localise these string constants
        const PLUGIN_NO_GATEWAY_LOG_MSG = 'Transaction attempted with no gateway URL set. Please check oxipay plugin configuration, and provide a gateway URL.';
        const PLUGIN_MISCONFIGURATION_CLIENT_MSG = 'There is an issue with the site configuration, which has been logged. We apologize for any inconvenience. Please try again later. ';
        const PLUGIN_NO_API_KEY_LOG_MSG = 'Transaction attempted with no API key set. Please check oxipay plugin configuration, and provide an API Key';
        const PLUGIN_NO_MERCHANT_ID_SET_LOG_MSG = 'Transaction attempted with no Merchant ID key. Please check oxipay plugin configuration, and provide an Merchant ID.';
        const PLUGIN_NO_REGION_LOG_MSG = 'Transaction attempted with no Oxipay region set. Please check oxipay plugin configuration, and provide an Oxipay region.';

        public $logger = null;

        function __construct() {
            $this->id                     = 'oxipay';
            $this->has_fields             = false;
            $this->order_button_text      = __( 'Proceed to ' . Oxipay_Config::DISPLAY_NAME, 'woocommerce' );
            $this->method_title           = __( Oxipay_Config::DISPLAY_NAME, 'woocommerce' );
            $this->method_description     = __( 'Easy to setup installment payment plans from ' . Oxipay_Config::DISPLAY_NAME );
	        $this->plugin_current_version = get_plugin_data( plugin_dir_path(__FILE__) . 'oxipay.php', false, false)['Version'];

            $this->init_form_fields();
            $this->init_settings();
            if( is_admin() ){
                $this->init_upgrade_process();
            }

            $this->title         = __( Oxipay_Config::DISPLAY_NAME , 'woocommerce' );
            $this->description   = __( '<strong>'.Oxipay_Config::DISPLAY_NAME . ' the smarter way to pay.</strong><br/> Shop today, pay over time. 4 easy fortnightly payments.', 'woocommerce' );
            $this->icon          = plugin_dir_url( __FILE__ ) .  'images/oxipay.png';
            $this->shop_details  = __( Oxipay_Config::DISPLAY_NAME . ' Payment', 'woocommerce' );

            // where available we can use logging to assist with debugging			
            if (function_exists('wc_get_logger')) {
                $this->logger = wc_get_logger();
                $this->logContext = array( 'source' => 'Oxipay' );
            }
            
            add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
            
            // when we are on the checkout page we want to provide
            // the checkout process through a modal dialog box
            if (!is_admin()) {
                add_action( 'wp_enqueue_scripts', array($this, 'oxipay_enqueue_script'));
            }
            
            add_action( 'woocommerce_api_wc_oxipay_gateway', array( $this, 'oxipay_callback') );
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_single_product_summary', array( $this, 'add_price_widget') );
            add_filter( 'woocommerce_thankyou_order_id', array( $this,'payment_finalisation' ) );
            add_filter( 'the_title', array( $this,'order_received_title' ), 11 );

            add_action( 'woocommerce_before_checkout_form', array($this, 'oxipay_min_max_notice'));
            add_action( 'woocommerce_before_cart', array($this, 'oxipay_min_max_notice'));
            add_filter( 'woocommerce_available_payment_gateways', array($this,'oxipay_min_max_filter'));
        }

        function add_price_widget(){
            global $product;
            if(isset($this->settings['price_widget']) && $this->settings['price_widget']=='yes'){
                $country_domain = 'com.au';
                if(isset($this->settings['country']) && $this->settings['country']=='NZ'){
                    $country_domain = 'co.nz';
                }
                echo '<script id="oxipay-price-info" src="https://widgets.oxipay.'.$country_domain.'/content/scripts/price-info.js?productPrice='.wc_get_price_to_display($product).'"></script>';
            }
        }

        function oxipay_min_max_notice(){
	        $minimum = $this->getMinPrice();
	        $maximum = $this->getMaxPrice();
            if ( $minimum != 0 && WC()->cart->total < $minimum ){
                if(is_checkout()){
	                wc_print_notice(
		                sprintf("You must have an order with a minimum of %s to use Oxipay. Your current order total is %s.",
			                wc_price($minimum),
                            wc_price(WC()->cart->total)
		                ), 'notice'
	                );
                }
            } elseif ( $maximum !=0 && WC()->cart->total > $maximum ){
	            if(is_checkout()){
		            wc_print_notice(
			            sprintf("You must have an order with a maximum of %s to use Oxipay. Your current order total is %s.",
				            wc_price($maximum),
				            wc_price(WC()->cart->total)
			            ), 'notice'
		            );
	            }
            }
        }

        protected function getMinPrice()
        {
            return isset($this->settings['oxipay_minimum'])? $this->settings['oxipay_minimum']:0;
        }

        protected function getMaxPrice()
        {
            return isset($this->settings['oxipay_maximum'])? $this->settings['oxipay_maximum']:0;
        }

        function oxipay_min_max_filter($available_gateways){
	        $minimum = $this->getMinPrice();
	        $maximum = $this->getMaxPrice();
	        if ( ( $minimum != 0 && WC()->cart->total < $minimum) || ($maximum != 0 && WC()->cart->total > $maximum) ){
		        if(isset($available_gateways['oxipay'])){
			        unset($available_gateways['oxipay']);
		        }
            }
	        return $available_gateways;
        }

        /**
         * Log a message using the 2.7 logging infrastructure
         *
         * @param string $message Message log
         * @param string $level  WC_Log_Levels
         */
        public function log( $message, $level=WC_Log_Levels::DEBUG) {	
            if ($this->logger != null) {
                $this->logger->log($level, $message, $this->logContext);
            }	
        }

        /**
         * Load javascript for Wordpress admin
         */
        function admin_scripts(){
            wp_register_script( 'oxipay_admin', plugins_url( '/js/admin.js', __FILE__ ), array( 'jquery' ), '0.4.5' );
            wp_enqueue_script( 'oxipay_admin' );
        }

        /**
         * Load JavaScript for the checkout page
         */
        function oxipay_enqueue_script() {

            

            wp_register_script('oxipay_gateway', plugins_url( '/js/oxipay.js', __FILE__ ), array( 'jquery' ), '0.4.5' );
            wp_register_script('oxipay_modal', plugins_url( '/js/oxipay_modal.js', __FILE__ ), array( 'jquery' ), '0.4.5' );
            $pass_to_oxipay_modal_js = array( 'plugin_url' => plugins_url("", __FILE__) );
            wp_localize_script( 'oxipay_modal', 'php_vars', $pass_to_oxipay_modal_js);

            wp_register_script('iframeResizer', plugins_url( '/js/resizer/iframeResizer.js', __FILE__ ), array( 'jquery' ), '0.4.5' );
            // wp_register_script('iframeResizer', plugins_url( '/js/resizer/iframeResizer.contentWindow.js', __FILE__ ), array( 'jquery' ), '0.4.5' );
            
            wp_enqueue_script('oxipay_gateway');
            wp_enqueue_script('oxipay_modal');
            wp_enqueue_script('iframeResizer');
        }

        /**
         * WC override to display the administration property page
         */
        function init_form_fields() {
            //Build options for the country select field from the config
            $countryOptions = array('' => __( 'Please select...', 'woocommerce' ));
            foreach( Oxipay_Config::$countries as $countryCode => $country ){
                $countryOptions[$countryCode] = __( $country['name'], 'woocommerce' );
            }

            $this->form_fields = array(
                'enabled' 			=> array(
                    'title' 		=> __( 'Enabled', 'woocommerce' ),
                    'type' 			=> 'checkbox',
                    'label' 		=> __( 'Enable the ' . Oxipay_Config::DISPLAY_NAME . ' Payment Gateway', 'woocommerce' ),
                    'default' 		=> 'yes',
                    'description'	=> 'Disable oxipay services, your customers will not be able to use our easy installment plans.',
                    'desc_tip'		=> true
                ),
                'price_widget' 		=> array(
	                'title' 		=> __( 'Price Widget', 'woocommerce' ),
	                'type' 			=> 'checkbox',
	                'label' 		=> __( 'Enable the ' . Oxipay_Config::DISPLAY_NAME . ' Price Widget', 'woocommerce' ),
	                'default' 		=> 'yes',
	                'description'	=> 'Display a price widget in each product page.',
	                'desc_tip'		=> true
                ),
                'shop_name' 		=> array(
                    'title' 		=> __( 'Shop Name', 'woocommerce' ),
                    'type' 			=> 'text',
                    'description' 	=> __( 'The name of the shop that will be displayed in ' . Oxipay_Config::DISPLAY_NAME, 'woocommerce' ),
                    'default' 		=> __( '', 'woocommerce' ),
                    'desc_tip'      => true,
                ),
                'country'			=> array(
                    'title'			=> __( 'Oxipay Region', 'woocommerce' ),
                    'type'			=> 'select',
                    'description'	=> 'Select the option that matches your retailer agreement.',
                    'options'		=> $countryOptions,
                    'desc_tip'		=> true,
                    'custom_attributes' => array('required' => 'required'),
                ),
                'use_test' 	=> array(
	                'title' 		=> __( 'Test Mode', 'woocommerce' ),
	                'type' 			=> 'checkbox',
	                'label' 		=> __( 'Use Test Mode', 'woocommerce' ),
	                'default' 		=> 'yes',
	                'description'	=> __('While test mode is enabled, transactions will be simulated and cards will not be charged', 'woocommerce' ),
	                'desc_tip'		=> true
                ),
                'use_modal' 	=> array(
                    'title' 		=> __( 'Modal Checkout', 'woocommerce' ),
                    'type' 			=> 'checkbox',
                    'label' 		=> __( 'Modal Checkout', 'woocommerce' ),
                    'default' 		=> 'no',
                    'description'	=> __('The customer will be forwarded to '.Oxipay_Config::DISPLAY_NAME . ' in a modal dialog', 'woocommerce' ),
                    'desc_tip'		=> true
                ),
                'oxipay_merchant_id'=> array(
                    'id'		    => 'oxipay_merchant_id',
                    'title'     	=> __( 'Merchant ID', 'woocommerce' ),
                    'type' 	    	=> 'text',
                    'default'   	=> '',
                    'description'	=> 'Oxipay will have supplied you with your Oxipay Merchant ID. Contact us if you cannot find it.',
                    'desc_tip'		=> true,
                    'custom_attributes' => array('required' => 'required'),
                ),
                'oxipay_api_key'    => array(
                    'id'        	=> 'oxipay_api_key',
                    'title'     	=> __( 'API Key', 'woocommerce' ),
                    'type' 	    	=> 'text',
                    'default'   	=> '',
                    'description'	=> 'Oxipay will have supplied you with your Oxipay API key. Contact us if you cannot find it.',
                    'desc_tip'		=> true,
                    'custom_attributes' => array('required' => 'required'),
                ),
                'oxipay_minimum'=> array(
	                'id'		    => 'oxipay_minimum',
	                'title'     	=> __( 'Minimum Order Total', 'woocommerce' ),
	                'type' 	    	=> 'text',
	                'default'   	=> '0',
	                'description'	=> 'Minimum order total to use Oxipay. Empty for unlimited',
	                'desc_tip'		=> true,
                ),
                'oxipay_maximum'=> array(
	                'id'		    => 'oxipay_maximum',
	                'title'     	=> __( 'Maximum Order Total', 'woocommerce' ),
	                'type' 	    	=> 'text',
	                'default'   	=> '0',
	                'description'	=> 'Maximum order total to use Oxipay. Empty for unlimited',
	                'desc_tip'		=> true,
                )
            );
        }

        /**
         * Check to see if we need to run upgrades.
         */
        function init_upgrade_process() {
            //get the current upgrade version. This will default to 0 before version 0.4.5 of the plugin
            $currentDbVersion = isset( $this->settings['db_plugin_version'] ) ? $this->settings['db_plugin_version'] : 0;
            //see if the current upgrade version is lower than the latest version
            if ( version_compare( $currentDbVersion, $this->plugin_current_version ) < 0 ) {
                //run the upgrade process
                if($this->upgrade( $currentDbVersion )){
                    //update the stored upgrade version if the upgrade process was successful
                    $this->updateSetting( 'db_plugin_version', $this->plugin_current_version );
                }
            }
        }

        /**
         * Run one off upgrade routines. A DB stored version number is compared to the class constant to
         * tell if processes need to run.
         * Update the class constant each time the version number changes. Add tasks here to handle
         * upgrade tasks when needed.
         * Users coming from especially old versions may have multiple version upgrade tasks to process.
         *
         * @param int $currentDbVersion
         * @return bool
         */
        private function upgrade( $currentDbVersion ) {
            if (version_compare( $currentDbVersion, '1.2.0') < 0) {
                if (!isset($this->settings['use_modal'])) {
                    // default to the redirect for existing merchants
                    // so we don't break the existing behaviour                
                    $this->settings['use_modal'] = false;
                    $this->updateSetting('use_modal', $this->settings['use_modal']);
                }

                if (!isset($this->settings['oxipay_minimum'])) {
                    $this->updateSetting('use_modal', $this->settings['oxipay_minimum']);
                }

                if (!isset($this->settings['oxipay_maximum'])) {
                    $this->updateSetting('use_modal', $this->settings['oxipay_maximum']);
                }
            }

            return true;
        }

        /**
         * Update a plugin setting stored in the database
         */
        private function updateSetting($key, $value) {
            $this->settings[$key] = $value;

            update_option( $this->get_option_key(), $this->settings );
        }

        public function get_oxipay_settings() {
            // these are safe values to export via javascript
            $whitelist = [
                'enabled'          => null,
                'display_details'  => null,
                'title'            => null,
                'description'      => null,
                'shop_details'     => null,
                'shop_name'        => null,
                'country'          => null,
                'use_modal'        => null
            ];
            foreach ($whitelist as $k=>$v) {
                if (isset($this->settings[$k])) {
                    $whitelist[$k] = $this->settings[$k];
                }
            }
            return $whitelist;
        }

        /**
         * Generates the payment gateway request parameters and signature and redirects to the
         * payment gateway through the invisible processing.php form
         * @param int $order_id
         * @return next view array
         */
        function process_payment( $order_id ) {
            global $woocommerce;
            $order = new WC_Order( $order_id );
            $gatewayUrl = $this->getGatewayUrl();

            $isValid = true;
            $isValid = $isValid && $this->verifyConfiguration($order);
            $isValid = $isValid && $this->checkCustomerLocation($order);
            $isValid = $isValid && $this->checkOrderAmount($order);
            $isValid = $isValid && !is_null($gatewayUrl) && $gatewayUrl != '';

            if(!$isValid) return;

            $callbackURL  = $this->get_return_url($order);

            $transaction_details = array (
                'x_reference'                   => $order_id,
                'x_account_id'                  => $this->settings['oxipay_merchant_id'],
                'x_amount'                      => $order->get_total(),
                'x_currency'                    => $this->getCurrencyCode(),
                'x_url_callback'                => $callbackURL,
                'x_url_complete'                => $this->get_return_url( $order ),
                'x_url_cancel'                  => $order->get_cancel_order_url_raw(),
                'x_test'                        => 'false',
                'x_shop_country'                => $this->getCountryCode(),
                'x_shop_name'                   => $this->settings['shop_name'],
                //customer detail
                'x_customer_first_name'         => $order->get_billing_first_name(),
                'x_customer_last_name'          => $order->get_billing_last_name(),
                'x_customer_email'              => $order->get_billing_email(),
                'x_customer_phone'              => $order->get_billing_phone(),
                //billing detail
                'x_customer_billing_country'	=> $order->get_billing_country(),
                'x_customer_billing_city' 	    => $order->get_billing_city(),
                'x_customer_billing_address1' 	=> $order->get_billing_address_1(),
                'x_customer_billing_address2' 	=> $order->get_billing_address_2(),
                'x_customer_billing_state' 	    => $order->get_billing_state(),
                'x_customer_billing_zip' 		=> $order->get_billing_postcode(),
                //shipping detail
                'x_customer_shipping_country'	=> $order->get_billing_country(),
                 'x_customer_shipping_city' 	=> $order->get_shipping_city(),
                'x_customer_shipping_address1'  => $order->get_shipping_address_1(),
                'x_customer_shipping_address2'  => $order->get_shipping_address_2(),
                'x_customer_shipping_state' 	=> $order->get_shipping_state(),
                'x_customer_shipping_zip' 		=> $order->get_shipping_postcode(),
                'gateway_url' 					=> $gatewayUrl
            );  

            $signature = oxipay_sign($transaction_details, $this->settings['oxipay_api_key']);
            $transaction_details['x_signature'] = $signature;
        
            $encodedFields = array(
                'x_url_callback',
                'x_url_complete',
                'gateway_url',
                'x_url_cancel',
                'x_customer_email'
            );
        
            // before we do the redirect we base64encode the urls to hopefully get around some of the 
            // limitations with platforms using mod_security 
            // foreach ($encodedFields as $key ) {
            //     $transaction_details[$key] = base64_encode($transaction_details[$key]);
            // }
            // use RFC 3986 so that we can decode it correctly in js
            $qs = http_build_query($transaction_details, null, '&', PHP_QUERY_RFC3986);
            
            return array(
                'result' 	=>  'success',
                'redirect'	=>  $gatewayUrl.'&'.$qs
            );
        }

        /**
         * @param $order
         * @return bool
         */
        private function verifyConfiguration($order)
        {
            $apiKey = $this->settings[ 'oxipay_api_key' ];
            $merchantId = $this->settings[ 'oxipay_merchant_id' ];
            $region = $this->settings['country'];

            $isValid = true;
            $clientMsg = self::PLUGIN_MISCONFIGURATION_CLIENT_MSG;
            $logMsg = '';

            if($this->is_null_or_empty($region)) {
                $logMsg = self::PLUGIN_NO_REGION_LOG_MSG;
                $isValid = false;
            }

            if($this->is_null_or_empty($apiKey)) {
                $logMsg = self::PLUGIN_NO_API_KEY_LOG_MSG;
                $isValid = false;
            }

            if($this->is_null_or_empty($merchantId)) {
                $logMsg = self::PLUGIN_NO_MERCHANT_ID_SET_LOG_MSG;
                $isValid = false;
            }

            if(!$isValid) {
                $order->cancel_order($logMsg);
                $this->logValidationError($clientMsg);
            }

            return $isValid;
        }

        /**
         * returns the gateway URL
         */
        private function getGatewayUrl($countryCode='') {
            //if no countryCode passed in
            if($this->is_null_or_empty($countryCode)) {
	            if ( isset( $this->settings['country'] ) ) {
		            $countryCode = $this->settings['country'];
	            } else {
		            $countryCode = 'AU';
	            }
            }
            
            $secure = 'secure';
            $x = $this->isTesting();

            if($this->isTesting() == 'yes'){
                $secure='securesandbox';
            }

            $tld = null;
            if (isset(Oxipay_Config::$countries[$countryCode]['tld'])){
                $tld = Oxipay_Config::$countries[$countryCode]['tld'];
            }
            //make sure we have a TLD for the country from the config
            if( $this->is_null_or_empty( $tld ) ) {
                //fall back on the Australian TLD
                $tld = ".com.au";
            }
            $displayName = strtolower(Oxipay_Config::DISPLAY_NAME);

            return "https://{$secure}.{$displayName}{$tld}/Checkout?platform=WooCommerce";
        }

        /**
         * Renders plugin configuration markup
         */
        function admin_options() { ?>
            <h2><?php _e(Oxipay_Config::DISPLAY_NAME,'woocommerce'); ?></h2>
            
            <p><?php _e($this->method_description, 'woocommerce' ); ?></p>
            <p>For help setting this plugin up please contact our integration team.</p>
            
            <table class="form-table">
            <?php $this->generate_settings_html(); ?>
            </table>
            <p>Plugin Version: <?php echo $this->plugin_current_version ; ?></p>
            <?php

            $countryUrls = array();
            foreach(Oxipay_Config::$countries as $countryCode => $country){
                $countryUrls[$countryCode] = array('gateway' => $this->getGatewayUrl($countryCode));
            }
            if( count( $countryUrls ) > 0 ) {
                ?>
                <script>
                    var countryUrls = <?php echo json_encode( $countryUrls ); ?>;
                </script>
                <?php
            }
            
        }

        /**
         * This is a filter setup to receive the results from the Oxipay services to show the required
         * outcome for the order based on the 'x_result' property
         * @param $order_id
         * @return mixed
         */
        function payment_finalisation($order_id)
        {
            $order = wc_get_order($order_id);
            $cart  = WC()->session->get('cart', null);

            $isJSON = ($_SERVER['REQUEST_METHOD'] === "POST" && isset($_SERVER['CONTENT_TYPE']) &&
                       (strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) );

            // This addresses the callback. 
            if ($isJSON) {
                $params = json_decode(file_get_contents('php://input'), true);
            } else {
                $full_url = "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
                $parts = parse_url($full_url, PHP_URL_QUERY);
                parse_str($parts, $params);
            }

            // we need order information in order to complete the order
            if (empty($order)) {
                $this->log(sprintf('unable to get order information for orderId: %s ', $order_id));
                return $order_id;
            }

            // make sure we have an oxipay order
            // OIR-3
            if ($order->get_data()['payment_method'] !== 'oxipay') {
                // we don't care about it because it's not an oxipay order
                // only log in debug mode
                $this->log(sprintf('No action required orderId: %s is not an oxipay order ', $order_id));
                return $order_id;
            }

            if (oxipay_checksign($params, $this->settings['oxipay_api_key'])) {
                $this->log(sprintf('Processing orderId: %s ', $order_id));
                // Get the status of the order from XPay and handle accordingly
                switch ($params['x_result']) {
                    case "completed":
                        $order->add_order_note(__( 'Payment approved using ' . Oxipay_Config::DISPLAY_NAME . '. Reference #' . $params['x_gateway_reference'], 'woocommerce'));
                        $order->payment_complete($params['x_reference']);
                        if (!is_null($cart)) {
                            $cart->empty_cart();
                        }
                        $msg = 'complete';
                        break;

                    case "failed":
                        $order->add_order_note(__( 'Payment declined using ' . Oxipay_Config::DISPLAY_NAME . '. Reference #' . $params['x_gateway_reference'], 'woocommerce'));
                        $order->update_status('failed');
                        $msg = 'failed';
                        break;

                    case "pending":
                        $order->add_order_note(__( 'Payment pending using ' . Oxipay_Config::DISPLAY_NAME . '. Reference #' . $params['x_gateway_reference'], 'woocommerce'));
                        $order->update_status('on-hold', 'Error may have occurred with ' . Oxipay_Config::DISPLAY_NAME . '. Reference #' . $params['x_gateway_reference']);
                        $msg = 'failed';
                        break;
                }

                return $order_id;
            }
            else
            {
                $order->add_order_note(__( Oxipay_Config::DISPLAY_NAME . ' payment response failed signature validation. Please check your Merchant Number and API key or contact Oxipay for assistance.', 0, 'woocommerce'));
                $order->add_order_note(__( 'Payment declined using ' . Oxipay_Config::DISPLAY_NAME . '. Your Order ID is ' . $order_id, 'woocommerce'));
                $order->update_status('failed');
                $msg = 'failed';
            }


            if ($isJSON) {
                $return = array(
                    'message'	=> $msg,
                    'id'		=> $order_id
                );
                wp_send_json($return);
            }
        }

        /**
         * This is a filter setup to override the title on the order received page
         * in the case where the payment has failed
         * @param $title
         * @return string
         */
        function order_received_title( $title ) {
            global $wp_query;

            //copying woocommerce logic from wc_page_endpoint_title() in wc-page-functions.php
            if ( ! is_null( $wp_query ) && ! is_admin() && is_main_query() && in_the_loop() && is_page() && is_wc_endpoint_url() ) {
                //make sure we are on the Order Received page and have the payment result available
                $endpoint = WC()->query->get_current_endpoint();
                if( $endpoint == 'order-received' && ! empty( $_GET['x_result'] ) ){
                    //look at the x_result query var. Ideally we'd load the order and look at the status, but this has not been updated when this filter runs
                    if( $_GET['x_result'] == 'failed' ){
                        $title = 'Payment Failed';
                    }
                }
                //copying woocommerce code- the filter only needs to run once
                remove_filter( 'the_title', array( $this, 'order_received_title' ), 11 );
            }

            return $title;
        }

        /**
         * Ensure the customer is being billed from and is shipping to, Australia.
         * @param $order
         * @return bool
         */
        private function checkCustomerLocation($order)
        {
            // The following get shipping and billing countries, and filters null or empty values
            // Then we check to see if there is just a single unique value that is equal to AU, otherwise we 
            // display an error message.

            $countries = array($order->get_billing_country(), $order->get_shipping_country());
            $set_addresses = array_filter($countries);
            $countryCode = $this->getCountryCode();
            $countryName = $this->getCountryName();
            $valid_addresses = (count(array_unique($set_addresses)) === 1 && end($set_addresses) === $countryCode);

            if (!$valid_addresses) {
                $errorMessage = "&nbsp;Orders from outside " . $countryName . " are not supported by " . Oxipay_Config::DISPLAY_NAME . ". Please select a different payment option.";
                $order->cancel_order($errorMessage);
                $this->logValidationError($errorMessage);
                return false;
            }
            return true;
        }

        /**
         * Ensure the order amount is >= $20
         * @param $order
         * @return true
         */
        private function checkOrderAmount($order)
        {
            if($order->get_total() < 20) {
                $errorMessage = "&nbsp;Orders under " . $this->getCurrencyCode() . $this->getCurrencySymbol() . "20 are not supported by " . Oxipay_Config::DISPLAY_NAME . ". Please select a different payment option.";
                $order->cancel_order($errorMessage);
                $this->logValidationError($errorMessage);
                return false;
            }
            return true;
        }

        private function logValidationError($message) {
            wc_add_notice(__('Payment error: ', 'woothemes') . $message, 'error');
        }

        /**
         * @return string
         */
        public function isTesting()
        {
            return isset($this->settings['use_test'])? $this->settings['use_test']: 'no';
        }

        
        /**
         * @return string
         */
        private function getCountryCode()
        {
            return $this->settings['country'];
        }

        /**
         * @return string
         */
        private function getCountryName() {
            return Oxipay_Config::$countries[$this->getCountryCode()]['name'];
        }

        /**
         * @return string
         */
        private function getCurrencyCode() {
            return Oxipay_Config::$countries[$this->getCountryCode()]['currency_code'];
        }

        /**
         * @return string
         */
        private function getCurrencySymbol() {
            return Oxipay_Config::$countries[$this->getCountryCode()]['currency_symbol'];
        }

        /**
         * @return string
         */
        private function getBaseUrl() {
            $tld = Oxipay_Config::$countries[$this->getCountryCode()]['tld'];
            $displayName = strtolower(Oxipay_Config::DISPLAY_NAME);
            if($this->is_null_or_empty($tld)) {
                $tld = ".com.au";
            }

            return "https://{$displayName}{$tld}";
        }

        /**
         * @return string
         */
        private function getSupportUrl() {
            $baseUrl = $this->getBaseUrl();

            return "$baseUrl/contact";
        }

        /**
         * Return the default gateway URL for the given country code.
         * If no country code is provided, use the currently set country.
         * Default to Australia if no country or an invalid country is set.
         * @param $str
         * @return string
         */
        private function getDefaultGatewayUrl($countryCode = false){
            //fetch the country code from settings if not passed in
            if( !$countryCode ){
                if ( isset($this->settings['country'])){
                    $countryCode = $this->settings['country'];
                }else {
                    $countryCode = 'AU';
                }
            }

            $tld = null;
            if (isset(Oxipay_Config::$countries[$countryCode]['tld'])){
                $tld = Oxipay_Config::$countries[$countryCode]['tld'];
            }

            //make sure we have a TLD for the country from the config
            if( $this->is_null_or_empty( $tld ) ) {
                //fall back on the Australian TLD
                $tld = ".com.au";
            }
            $displayName = strtolower(Oxipay_Config::DISPLAY_NAME);

            return "https://secure.{$displayName}{$tld}/Checkout?platform=WooCommerce";
        }

        /**
         * @param $str
         * @return bool
         */
        private function is_null_or_empty($str) {
            return is_null($str) || $str == '';
        }
    }