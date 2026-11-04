<?php
/**
 * Plugin Name: VSPP Sep Source Terminal
 * Description: A lightweight, secure boilerplate plugin following WordPress best practices.
 * Version:     1.4.9
 * Author:      Vira System Paydar Pars and Gemini
 * Author URI:  http://vira-system-paydar-pars.ir/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: source-sep-gateway
 * 
 * WC requires at least: 8.0
 */

// Exit if accessed directly for security.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


add_action( 'rest_api_init', 'register_verification_rest_route' );
/**
 * Register the REST API endpoint that the source site will call.
 */
function register_verification_rest_route() {
    //'myplugin/v1', '/submit-data/'
    register_rest_route(
        'source-gateway/v1',
        '/sync_order/',
        array(
            'methods'             => 'GET',
            'callback'            => array( 'Source_Sep_Gateway', 'handle_verification_rest_endpoint' ),
            'permission_callback' => '__return_true', // Adjust permission/authentication if needed
        )
    );
}




add_action( 'plugins_loaded', 'register_source_sep_gateway_class' );
function register_source_sep_gateway_class() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }
    class Source_Sep_Gateway extends WC_Payment_Gateway{


        public function __construct() {
            // Within your constructor, you should define the following variables:

            // $this->id - Unique ID for your gateway, e.g., 'your_gateway'
            // $this->icon - If you want to show an image next to the gateway's name on the frontend, enter a URL to an image.
            // $this->has_fields - Bool. Can be set to true if you want payment fields to show on the checkout (if doing a direct integration).
            // $this->method_title - Title of the payment method shown on the admin page.
            // $this->method_description - Description for the payment method shown on the admin page.
            $this->id = 'source_gateway';
            $this->method_title = __('Source Sep Gateway','source-sep-gateway');
            $this->method_description = __('Redirects checkout securely to Vira System payment node.','source-sep-gateway');
            $this->has_fields = false;
            // a WooCommerce payment gateway. It should usually initialize gateway properties
            // Loading your settings
            // In the constructor you can load the settings you previously defined:
            // After that you can load your settings from the settings API.
            // The init_settings method above populates the settings variable for you:
            // Define user set variables
            // $this->title       = $this->settings['title'];
            // $this->description = $this->settings['description'];
            // Your constructor should also define and load settings fields:

            $this->init_form_fields();
            $this->init_settings();
            $this->register_save_options_action_hook();
            $this->read_options();
            
            add_action('woocommerce_before_checkout_form',array( $this, 'before_checkout_form_scaffold' ));
        }

        private int $multiplier = 2700000;
        // Setting definitions use the following format:
        // 'setting_name' => array(
        //     'title'       => 'Title for your setting shown on the settings page',
        //     'description' => 'Description for your setting shown on the settings page',
        //     'type'        => 'text|password|textarea|checkbox|select|multiselect',
        //     'default'     => 'Default value for the setting',
        //     'class'       => 'Class for the input element',
        //     'css'         => 'CSS rules added inline on the input element',
        //     'label'       => 'Label', // For checkbox inputs only.
        //     'options'     => array( // Array of options for select/multiselect inputs only.
        //         'key' => 'value'
        //     ),
        // )

        /**
         * Helper function to write clean debug messages to wp-content/debug.log
         */
        private function log_debug( $message, $data = null ) {
            if ( WP_DEBUG === true ) {
                $log = '[VSPP Debug] ' . $message;
                if ( null !== $data ) {
                    $log .= ' | Data: ' . print_r( $data, true );
                }
                error_log( $log );
            }
        }

/**
         * Handles the incoming REST API request from the source site to verify and sync order status.
         * 
         * @param WP_REST_Request $request
         * @return WP_REST_Response
         */
        public static function handle_verification_rest_endpoint( WP_REST_Request $request ) {
            $gateway = new self();
            $source_order_key = $request->get_param( 'source_order_key' );

            $gateway->log_debug( 'Verification request received', array( 'source_order_key' => $source_order_key ) );

            // 1. Validate required parameter
            if ( empty( $source_order_key ) ) {
                $gateway->log_debug( 'Validation failed: Missing source_order_key parameter.' );
                return new WP_REST_Response( array(
                    'success' => false,
                    'message' => 'Missing source_order_key parameter.',
                ), 400 );
            }

            // 2. Fetch source order locally
            $source_order = wc_get_order( $source_order_key );

            if ( ! $source_order ) {
                $gateway->log_debug( 'Source order not found locally', array( 'source_order_key' => $source_order_key ) );
                return new WP_REST_Response( array(
                    'success' => false,
                    'message' => 'Order not found on source site.',
                ), 404 );
            }

            // 3. Search destination orders via API
            $result = $gateway->send( 'GET', '/wc/v3/orders', array(
                    'search'   => '_source_order_key',
                ) );

            $gateway->log_debug( 'Destination order search API response received', $result );

            // 4. Verify API response structure
            if ( empty( $result['data'] ) || ! is_array( $result['data'] ) ) {
                $gateway->log_debug( 'Destination order not found or invalid response structure received', $result );
                return new WP_REST_Response( array(
                    'success' => false,
                    'message' => 'Destination order not found or invalid response received.',
                ), 404 );
            }

            // Grab the first matching destination order
            $destination_order = $result['data'][0] ?? null;

            if ( ! $destination_order || empty( $destination_order['status'] ) ) {
                $gateway->log_debug( 'Destination order status could not be retrieved', array( 'destination_order' => $destination_order ) );
                return new WP_REST_Response( array(
                    'success' => false,
                    'message' => 'Destination order status could not be retrieved.',
                ), 400 );
            }

            $dest_status = $destination_order['status'];
            $gateway->log_debug( 'Destination order status successfully retrieved', array( 'dest_status' => $dest_status ) );

            // 5. Update local source order status based on destination order status
            if ( in_array( $dest_status, array( 'processing', 'completed' ), true ) ) {
                if ( ! $source_order->is_paid() ) {
                    // Complete payment and add transaction reference if available
                    $transaction_id = $destination_order['transaction_id'] ?? '';
                    $gateway->log_debug( 'Completing source order payment', array( 'transaction_id' => $transaction_id ) );
                    $source_order->payment_complete( $transaction_id );
                }
                $gateway->log_debug( 'Updating source order status to paid/complete state', array( 'dest_status' => $dest_status ) );
                $source_order->update_status( $dest_status, __( 'Status synced from destination order.', 'text-domain' ) );
            } else {
                $gateway->log_debug( 'Updating source order status to alternate state', array( 'dest_status' => $dest_status ) );
                $source_order->update_status( $dest_status, __( 'Status updated from destination order.', 'text-domain' ) );
            }

            $source_order->save();
            $gateway->log_debug( 'Order status sync completed successfully', array( 'final_status' => $source_order->get_status() ) );

            return new WP_REST_Response( array(
                'success' => true,
                'message' => 'Order status synced successfully.',
                'status'  => $source_order->get_status(),
            ), 200 );
        }


        public function init_form_fields() {
            $this->form_fields = array(
                'enabled'     => array( 'title' => 'Enable', 'type' => 'checkbox', 'label' => 'Enable Gateway', 'default' => 'yes' ),
                'title'       => array( 'title' => 'Title', 'type' => 'text', 'default' => 'Remote Online Payment' ),
                'description' => array( 'title' => 'Description', 'type' => 'textarea', 'default' => 'Pay securely via remote gateway.' ),
                'dest_rest_api_url'    => array( 'title' => 'Destination Rest API Endpoint', 'type' => 'url', 'description' => 'https://payment.vira-system-paydar-pars.ir/wp-json/' ),
                'api_user'    => array(
                    'title'       => 'WooCommerce Consumer Key',
                    'type'        => 'text',
                    'description' => 'Use a read/write REST API key created in WooCommerce > Settings > Advanced > REST API.',
                ),
                'api_pass'    => array(
                    'title'       => 'WooCommerce Consumer Secret',
                    'type'        => 'password',
                    'description' => 'The consumer secret paired with the consumer key.',
                ),
            );
        }

        // Displaying your settings
        public function admin_options() {
            ?>
            <h2><?php esc_html_e( 'Source Sep Gateway', 'source-sep-gateway' ); ?></h2>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table>
            <?php
        }

        // Saving your settings
        // WC_Settings_API does not create a settings screen or save action by itself. 
        // The integration that renders the settings must call process_admin_options() when
        // its form is submitted.
        // WooCommerce provides save actions for its registered settings integrations.
        // A WC_Payment_Gateway subclass registered with WooCommerce should use the dynamic 
        // payment gateway action, which includes its gateway ID:
        private function register_save_options_action_hook(){
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        // Declare the property here to prevent the deprecation warning
        public $dest_rest_api_url;
        public $api_user;
        public $api_pass;
        
        private function read_options(){
            $this->enabled = $this->get_option( 'enabled' );
            $this->title = $this->get_option( 'title' );
            $this->description = $this->get_option( 'description' );
            $this->dest_rest_api_url = $this->get_option( 'dest_rest_api_url' );
            $this->api_user = $this->get_option( 'api_user' );
            $this->api_pass = $this->get_option( 'api_pass' );
        }

        function process_payment( $order_id ) {
            global $woocommerce;
            $order = new WC_Order( $order_id );

            if ( ! $order ) {
                wc_add_notice(
                    __( 'Unable to find the order. Please try again.', 'source-sep-gateway' ),
                    'error'
                );

                return array(
                    'result' => 'failure',
                );
            }

            // Start the remote payment request here. Do not mark the order paid yet.
            $order->update_status(
                'pending',
                __( 'Redirecting customer to payment provider.', 'source-sep-gateway' )
            );

            $this->ensure_registration( $order );
            
            // create order on destination website via rest api
            $destination_order_response = $this -> create_destination_order($order);


            // $destination_chekout = $this -> create_destination_checkout( $destination_order_response);

            // create the checkout on destination

            // redirect to destination checkout for payment
            // $url = $this->get_destination_checkout_redirect_url($order, $destination_order['id']);

//TODO FLOW THE SILVERS ON EARTH FOR SOME FELLOW SAID FOR MORE SAFTY AND AUTHENTICATION AND 
//  ALLAH SAID BEKHANDID 

            return array(
                'result' => 'success',
                'redirect' => $destination_order_response["payment_url"]
            );
            

            // Awaiting payment: pending
            // Awaiting manual/delayed confirmation: on-hold
            // Provider confirms payment: $order->payment_complete();
            // Provider confirms failure: $order->update_status( 'failed', '...' );



            // change status 
            // Mark as on-hold (we're awaiting the cheque)
            // $order->update_status(\Automattic\WooCommerce\Enums\OrderStatus::ON_HOLD, __( 'Awaiting cheque payment', 'woocommerce' ));
            
            // payment compeleted
            // if ( $order->is_paid() ) {
            //     return; // Already handled; make the callback idempotent.
            // }
            // $order->payment_complete();

            // payment error
            // wc_add_notice( __('Payment error:', 'woothemes') . $error_message, 'error' );
            // return array(
            //     'result'   => 'failure'
            // );

            // updating payment status 
            // $order = new WC_Order( $order_id );
            // $order->update_status(\Automattic\WooCommerce\Enums\OrderStatus::ON_HOLD, __('Awaiting cheque payment', 'woothemes'));
            // or add note $order->add_order_note( __('IPN payment completed', 'woothemes') );



            // if ( WC()->cart ) {
            //     WC()->cart->empty_cart();
            // }


            // Return thankyou redirect
            // return array(
            //     'result' => 'success',
            //     'redirect' => $this->get_return_url( $order )
            // );

        }
        
        function create_destination_order($order){
            
            // get base curency product 
            // create order on desti
            $base_product_id = $this -> get_base_product_id();
            $customer_id = $this -> get_destination_customer_id();
            $payment = $this -> get_destination_payment();
            $price = $order -> get_total() * $this -> get_current_silver_price() * $this -> multiplier;
            $line_items = [
                ['product_id' => $base_product_id,
                'quantity'   => (int)$price],
                // You can add price adjustments or subtotal if necessary here
            ];


            // to prevent duplication
            // foreach ($order->get_shipping_methods() as $shipping_item) {
            //     $shipping_lines[] = [
            //         'method_id'   => $shipping_item->get_method_id(),
            //         'method_title'=> $shipping_item->get_name(),
            //         'total'       => (string) $shipping_item->get_total(),
            //     ];
            // }

            // 4. Build the payload for the destination API
            $payload = [
                
                "currency"             => 'IRR', // Or set custom like "XAG_KG"
                "customer_id"          => $customer_id,
                "billing"              => $order->get_address('billing'),         
                "shipping"             => $order->get_address('shipping'),
                "payment_method"       => $payment['id'],
                "payment_method_title" => $payment['method_title'],
                "transaction_id"       => "",
                "meta_data" => [
                    [
                        // Get the corresponding Order ID
                        // $order_id = wc_get_order_id_by_order_key( $order_key );
                        'key'   => '_source_order_key',
                        'value' => $order->get_order_key(),
                    ],
                    [
                        'key'   => '_redirect_url_to_source_checkout',
                        'value' => $order->get_checkout_order_received_url(),
                    ],
                    [
                        'key'   => '_sync_orders_endpoint_on_source',
                        'value' => rest_url('source-gateway/v1/sync_order/'),
                    ]
                ],
                "line_items"           => $line_items,
                "shipping_lines"       => [],
                "fee_lines"            => [],
                "coupon_lines"         => [],
                "set_paid"             => $order->is_paid(),
                // manual update by the customer on th source website
                "manual_update"        => true
            ];

            // 5. Send POST request to create the order on the destination site
            $result = $this->send('POST', '/wc/v3/orders', $payload);

            return $result;
        }

        private function get_destination_payment($sep_id = 'WC_Sep_Payment_Gateway'){
             return $this -> send( 'GET', '/wc/v3/payment_gateways/'.$sep_id);
        }




        private function get_destination_customer_id(){
            $user = wp_get_current_user();

            //if id is 0 then handle it we already registered user by ret api
            $result = $this -> send( 'GET', '/wp/v2/users',[ 'search' => $user->user_login ] );
            // search destination to fetch the user to return customer id
            return $result[0]['id'];
        }

        private function get_base_product_id(){
            $product_id = 0;
            $result = $this -> send( 'GET', '/wc/v3/products',['sku' => 'base_currency'] );
            if(empty($result)){
                return new WP_Error( 'invalid_customer_response', __( 'product with base_currency sdk did not created', 'source-sep-gateway' ) );
                // $product_id = create_product();
            }else{
                $product_id = $result[0]['id'] ;
            }
            return  $product_id;
        }

        private function create_product($sku){
            //todo add later
        }

        /**
         * Placeholder method for fetching silver price
         */
        private function get_current_silver_price() {
            $request = new WP_REST_Request('GET', '/vspp/v1/silver-price');
            $response = rest_do_request($request);
            // 1. Check if the response returned a WP_Error
            if ( is_wp_error( $response ) ) {
                return 0;
            }
            // 2. Get the HTTP status code (200 means success)
            $status = $response->get_status();
            if ( $status >= 200 && $status < 300 ) {
                $data = $response->get_data();
                // {
                //     "success": true,
                //     "data": {
                //         "price_per_ozt": 49.975,
                //         "price_per_kg": 1606.7335613349999,
                //         "currency": "USD",
                //         "updated_at": "2026-11-02 03:56:02"
                //     }
                // }
                if ( isset( $data['success'] ) && $data['success'] === true && isset( $data['data'] ) ) {
                    return $data['data']['price_per_kg']; 
                }
            }
            // fallback
            return 1650; 
        }



        function ensure_registration( $order ) {
            // we need to fetch unique username
            $user_email = $order->get_billing_email();
            // how to send internal rest api request to /wp/v2/users?search=m8811163008@gmail.com
            // To make an internal REST API call in WordPress without firing a slow HTTP request
            // Internal Requests

            // check if user exist in source site or it is a visitor and
            // we need to create a new user in source site
            $result = $this -> internal_http_request( 'GET', '/wp/v2/users',[ 'search' => $user_email ] );
            // if the result is empty then create the user 
            if (empty($result)) {
                $password = wp_generate_password();
                $user_data = [
                    'username'    => $user_email,
                    'name'        => $user_email,
                    'first_name'  => $order->get_billing_first_name(),
                    'last_name'   => $order->get_billing_last_name(),
                    'email'       => $user_email,
                    'url'         => '',
                    'description' => 'visited dear customer',
                    'locale'      => '',
                    'nickname'    => '',
                    'slug'        => '',
                    'roles'       => ['customer'],
                    'password'    => $password,
                ];
                $this -> internal_http_request( 'GET', '/wp/v2/users',$user_data );
                $this -> send( 'GET', '/wp/v2/users',$user_data );
                //todo later log in user when redirect from the source
            }

        }






        public function before_checkout_form_scaffold() {
            $statuses = wc_get_order_statuses();

            ?>
            <section class="source-gateway-checkout-scaffold">
                <h2><?php esc_html_e( 'Checkout information', 'source-sep-gateway' ); ?></h2>
                <p><?php esc_html_e( 'Add your checkout content here.', 'source-sep-gateway' ); ?></p>
                <ul>
                    <?php foreach ( $statuses as $status_key => $status_label ) : ?>
                        <li>
                            <?php
                            printf(
                                '%1$s (%2$s)',
                                esc_html( $status_label ),
                                esc_html( $status_key )
                            );
                            ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                
                <?php
                echo wp_kses_post(
                    do_shortcode( '[vspp_silver_price_kg label="Live Silver Price (KG)"]' )
                );
                ?>

            </section>
            <?php
        }

        /**
         * Send an internal WordPress REST API request.
         *
         * @param string $method   The HTTP method (e.g., GET, POST, PUT, DELETE).
         * @param string $route    The REST API route (e.g., /wp/v2/posts).
         * @param array  $body     The parameters/body data for the request.
         * @param int    $timeout  Timeout (retained for signature consistency, though less relevant internally).
         * @return mixed|\WP_Error
         */
        public function internal_http_request(string $method, string $route, array $body = [], int $timeout = 15) {
            $request = new WP_REST_Request(strtoupper($method), $route);

            // Add specified request parameters into the request.
            if (!empty($body)) {
                foreach ($body as $param_name => $param_value) {
                    $request->set_param($param_name, $param_value);
                }
            }

            $response = rest_do_request($request);

            if (is_wp_error($response)) {
                return $response;
            }

            $status       = $response->get_status();
            $responseBody = $response->get_data();

            if ($status < 200 || $status >= 300) {
                $message = 'The internal API request failed.';
                
                if (is_array($responseBody) && isset($responseBody['message'])) {
                    $message = $responseBody['message'];
                }

                return new \WP_Error(
                    'internal_api_request_failed',
                    $message,
                    ['status' => $status]
                );
            }

            return $responseBody;
        }


        public function send(string $method, string $endpoint, array $params = [], int $timeout = 15) {
            
            $url = $this->dest_rest_api_url . ltrim($endpoint, '/');

            $args = [
                'method'  => strtoupper($method),
                'headers' => $this->getHeaders(),
                'timeout' => $timeout,
            ];

            if (!empty($params)) {
                if ('GET' === $method) {
                    // Append parameters to the URL query string for GET requests
                    $url = add_query_arg($params, $url);
                } else {
                    // Send as a JSON body for POST, PUT, DELETE, etc.
                    $args['body'] = wp_json_encode($params);
                }
            }


            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                return $response;
            }

            $status   = wp_remote_retrieve_response_code($response);
            // todo turn this off and use object instead of array
            $responseBody = json_decode(wp_remote_retrieve_body($response), true);
            

            if ($status < 200 || $status >= 300) {
                return new WP_Error(
                    'api_request_failed',
                    $responseBody['message'] ?? 'The remote API request failed.',
                    ['status' => $status]
                );
            }

            return $responseBody;
        }

        /**
         * Generate standard Basic Auth and JSON headers.
         */
        private function getHeaders(): array 
        {
            return [
                'Authorization' => 'Basic ' . base64_encode($this->api_user . ':' . $this->api_pass),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ];
        }


        
    }
}






class Source_Sep_Gateway_Application {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Runs on every page request
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        add_action( 'admin_notices', array( $this, 'check_woocommerce_activation_admin_notices' ) );
        add_action( 'woocommerce_init', [$this, '_woocommerce_init'] );
    }

    public function load_textdomain() {
        load_plugin_textdomain(
            'source-sep-gateway',
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages'
        );
    }

    public function check_woocommerce_activation_admin_notices() {
        if ( ! class_exists( 'WooCommerce' ) && current_user_can( 'activate_plugins' ) ) {
                ?>
                <div class="notice notice-warning">
                    <p>
                        <?php
                        echo esc_html__(
                            'Source Sep Gateway requires WooCommerce to be installed and active.',
                            'source-sep-gateway'
                        );
                        ?>
                    </p>
                </div>
                <?php
        }
    }
    
    public function _woocommerce_init() {
        add_filter( 'woocommerce_payment_gateways', function( $methods ) {
            $methods[] = 'Source_Sep_Gateway';
            return $methods;
        } );
    }




    /**
     * Runs once when the plugin is activated.
     */
    public static function activate() {
        flush_rewrite_rules();
    }

    /**
     * Runs once when the plugin is deactivated.
     */
    public static function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Runs once when the plugin is deleted (uninstalled) via the admin dashboard.
     */
    public static function uninstall() {
        // Security check: Ensure WordPress is actually executing the uninstall process
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            return;
        }
        // Clean up options or custom database entries
        delete_option( 'source_sep_gateway_version' );
    }
}


/**
 * Initialize the plugin for normal requests.
 */
function run_source_sep_gateway() {
    // Register lifecycle hooks
    register_activation_hook( __FILE__, array( 'Source_Sep_Gateway_Application', 'activate' ) );
    register_deactivation_hook( __FILE__, array( 'Source_Sep_Gateway_Application', 'deactivate' ) );
    register_uninstall_hook( __FILE__, array( 'Source_Sep_Gateway_Application', 'uninstall' ) );

    return Source_Sep_Gateway_Application::get_instance();
}

run_source_sep_gateway();