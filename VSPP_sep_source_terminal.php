<?php
/**
 * Plugin Name: VSPP Sep Source Terminal
 * Description: A lightweight, secure boilerplate plugin following WordPress best practices.
 * Version:     1.4.7
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

        private function read_options(){
            $this->enabled = $this->get_option( 'enabled' );
            $this->title = $this->get_option( 'title' );
            $this->description = $this->get_option( 'description' );
            $this->dest_rest_api_url = $this->get_option( 'dest_rest_api_url' );
            $this->api_user = $this->get_option( 'api_user' );
            $this->api_pass = $this->get_option( 'api_pass' );
        }

        // add a method to return payload base on if aleady exist or not and if exist update else create new
        // $payload = [
        //                 'email'      => $user->user_email,
        //                 'first_name' => get_user_meta($user_id, 'first_name', true),
        //                 'last_name'  => get_user_meta($user_id, 'last_name', true),
        //                 'username'   => $user->user_login,
        //                 'billing'    => [
        //                     'first_name' => get_user_meta($user_id, 'billing_first_name', true),
        //                     'last_name'  => get_user_meta($user_id, 'billing_last_name', true),
        //                     'company'    => get_user_meta($user_id, 'billing_company', true),
        //                     'address_1'  => get_user_meta($user_id, 'billing_address_1', true),
        //                     'address_2'  => get_user_meta($user_id, 'billing_address_2', true),
        //                     'city'       => get_user_meta($user_id, 'billing_city', true),
        //                     'state'      => get_user_meta($user_id, 'billing_state', true),
        //                     'postcode'   => get_user_meta($user_id, 'billing_postcode', true),
        //                     'country'    => get_user_meta($user_id, 'billing_country', true),
        //                     'email'      => $user->user_email,
        //                     'phone'      => get_user_meta($user_id, 'billing_phone', true),
        //                 ],
        //                 'shipping'   => [
        //                     'first_name' => get_user_meta($user_id, 'shipping_first_name', true),
        //                     'last_name'  => get_user_meta($user_id, 'shipping_last_name', true),
        //                     'company'    => get_user_meta($user_id, 'shipping_company', true),
        //                     'address_1'  => get_user_meta($user_id, 'shipping_address_1', true),
        //                     'address_2'  => get_user_meta($user_id, 'shipping_address_2', true),
        //                     'city'       => get_user_meta($user_id, 'shipping_city', true),
        //                     'state'      => get_user_meta($user_id, 'shipping_state', true),
        //                     'postcode'   => get_user_meta($user_id, 'shipping_postcode', true),
        //                     'country'    => get_user_meta($user_id, 'shipping_country', true),
        //                     'phone'      => get_user_meta($user_id, 'shipping_phone', true),
        //                 ],
        //             ];

        
        function create_destination_order($order){
            
            // get base curency product 
            // create order on desti
            $base_product_id = $this -> get_base_product_id();
            $customer_id = $this -> get_destination_customer_id();
            $payment = $this -> get_destination_payment();
            $line_items = [
                ['product_id' => $base_product_id,
                'quantity'   => $order -> get_total() * 1000],
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
                        'key'   => '_source_order_id',
                        'value' => $order->get_id(),
                        'key'   => '_source_redirect_url',
                        'value' => 'https://payment.vira-system-paydar-pars.ir/%d9%be%d8%b1%d8%af%d8%a7%d8%ae%d8%aa/order-received/216/?key=wc_order_j5VFgUfT8qrad&wc_status=success'
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
//             result dump:
//             array (
//   'id' => 13,
//   'parent_id' => 0,
//   'status' => 'pending',
//   'currency' => 'IRR',
//   'version' => '11.2.1',
//   'prices_include_tax' => false,
//   'date_created' => '2026-10-27T18:05:33',
//   'date_modified' => '2026-10-27T18:05:33',
//   'discount_total' => '0',
//   'discount_tax' => '0',
//   'shipping_total' => '0',
//   'shipping_tax' => '0',
//   'cart_tax' => '0',
//   'total' => '1000',
//   'total_tax' => '0',
//   'customer_id' => 1,
//   'order_key' => 'wc_order_ywFxmK1kVfPo9',
//   'billing' => 
//   array (
//     'first_name' => 'test',
//     'last_name' => 'test',
//     'company' => '',
//     'address_1' => 'te',
//     'address_2' => 'test',
//     'city' => 'test',
//     'state' => 'THR',
//     'postcode' => '1234567898',
//     'country' => 'IR',
//     'email' => 'm8811163008@gmail.com',
//     'phone' => '',
//   ),
//   'shipping' => 
//   array (
//     'first_name' => '',
//     'last_name' => '',
//     'company' => '',
//     'address_1' => '',
//     'address_2' => '',
//     'city' => '',
//     'state' => '',
//     'postcode' => '',
//     'country' => '',
//     'phone' => '',
//   ),
//   'payment_method' => '',
//   'payment_method_title' => '',
//   'transaction_id' => '',
//   'customer_ip_address' => '',
//   'customer_user_agent' => '',
//   'created_via' => 'rest-api',
//   'customer_note' => '',
//   'date_completed' => null,
//   'date_paid' => null,
//   'cart_hash' => '',
//   'number' => '13',
//   'meta_data' => 
//   array (
//     0 => 
//     array (
//       'id' => 1,
//       'key' => '_source_order_id',
//       'value' => '30',
//     ),
//   ),
//   'line_items' => 
//   array (
//     0 => 
//     array (
//       'id' => 1,
//       'name' => 'ریال ایران',
//       'product_id' => 12,
//       'variation_id' => 0,
//       'quantity' => 1000,
//       'tax_class' => '',
//       'subtotal' => '1000',
//       'subtotal_tax' => '0',
//       'total' => '1000',
//       'total_tax' => '0',
//       'taxes' => array (

//       ),
//       'meta_data' => array (

//       ),
//       'sku' => 'base_currency',
//       'global_unique_id' => '',
//       'price' => 1,
//       'image' => 
//       array (
//         'id' => '',
//         'src' => '',
//       ),
//       'parent_name' => null,
//     ),
//   ),
//   'tax_lines' => array (

//   ),
//   'shipping_lines' => array (

//   ),
//   'fee_lines' => array (

//   ),
//   'coupon_lines' => array (

//   ),
//   'refunds' => array (

//   ),
//   'payment_url' => 'https://localhost/destination/checkout/order-pay/13/?pay_for_order=true&key=wc_order_ywFxmK1kVfPo9',
//   'is_editable' => true,
//   'needs_payment' => true,
//   'needs_processing' => true,
//   'date_created_gmt' => '2026-10-27T14:35:33',
//   'date_modified_gmt' => '2026-10-27T14:35:33',
//   'date_completed_gmt' => null,
//   'date_paid_gmt' => null,
//   'currency_symbol' => '﷼',
//   '_links' => 
//   array (
//     'self' => 
//     array (
//       0 => 
//       array (
//         'href' => 'https://localhost/destination/wp-json/wc/v3/orders/13',
//         'targetHints' => 
//         array (
//           'allow' => 
//           array (
//             0 => 'GET',
//             1 => 'POST',
//             2 => 'PUT',
//             3 => 'PATCH',
//             4 => 'DELETE',
//           ),
//         ),
//       ),
//     ),
//     'collection' => 
//     array (
//       0 => 
//       array (
//         'href' => 'https://localhost/destination/wp-json/wc/v3/orders',
//       ),
//     ),
//     'email_templates' => 
//     array (
//       0 => 
//       array (
//         'embeddable' => true,
//         'href' => 'https://localhost/destination/wp-json/wc/v3/orders/13/actions/email_templates',
//       ),
//     ),
//     'customer' => 
//     array (
//       0 => 
//       array (
//         'href' => 'https://localhost/destination/wp-json/wc/v3/customers/1',
//       ),
//     ),
//   ),
// )
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


// function create_destination_checkout($destination_order_response){




//             // get base curency product 
//             // create order on desti
//             $base_product_id = $this -> get_base_product_id();
//             $customer_id = $this -> get_destination_customer_id();
//             $payment = $this -> get_destination_payment();
            


//             // to prevent duplication
//             // foreach ($order->get_shipping_methods() as $shipping_item) {
//             //     $shipping_lines[] = [
//             //         'method_id'   => $shipping_item->get_method_id(),
//             //         'method_title'=> $shipping_item->get_name(),
//             //         'total'       => (string) $shipping_item->get_total(),
//             //     ];
//             // }

//             // 4. Build the payload for the destination API

// //             {
// //   "payment_data": "string",
// //   "customer_note": "string",
// //   "billing_address": "string",
// //   "shipping_address": "string",
// //   "payment_method": "string",
// //   "additional_fields": "string",
// //   "extensions": {
// //     "woocommerce/order-attribution": []
// //   }
// // }

// // {
// //   "additional_fields": "string",
// //   "payment_method": "string",
// //   "order_notes": "string",
// //   "customer_note": "string",
// //   "billing_address": "string",
// //   "shipping_address": "string",
// //   "create_account": true,
// //   "extensions": "string"
// // }
// $source_order_id = null;
// if (!empty($destination_order_response['meta_data'])) {
//     foreach ($destination_order_response['meta_data'] as $meta) {
//         if (isset($meta['key']) && $meta['key'] === '_source_order_id') {
//             $source_order_id = $meta['value'];
//             break;
//         }
//     }
// }

// $payload = [
//     'additional_fields'   => [
//                     [
//                         'key'   => '_source_order_id',
//                         'value' => $source_order_id
//                     ]
//                 ],
//     'payment_method'    => $destination_order_response["payment_method"],
//     'order_notes'      => "", // Pass any gateway-specific custom fields if required
//     'customer_note'     => $destination_order_response["customer_note"],
//     'billing_address' =>  $destination_order_response["billing"],
//     'shipping_address' => $destination_order_response["shipping"],
//     'create_account'     => false,
//     'extensions'        => []
// ];


//             // 5. Send POST request to create the order on the destination site
//             $result = $this->send('POST', '/wc/store/v1/checkout', $payload);
// //             result dump:
// //             array (
// //   'id' => 13,
// //   'parent_id' => 0,
// //   'status' => 'pending',
// //   'currency' => 'IRR',
// //   'version' => '11.2.1',
// //   'prices_include_tax' => false,
// //   'date_created' => '2026-10-27T18:05:33',
// //   'date_modified' => '2026-10-27T18:05:33',
// //   'discount_total' => '0',
// //   'discount_tax' => '0',
// //   'shipping_total' => '0',
// //   'shipping_tax' => '0',
// //   'cart_tax' => '0',
// //   'total' => '1000',
// //   'total_tax' => '0',
// //   'customer_id' => 1,
// //   'order_key' => 'wc_order_ywFxmK1kVfPo9',
// //   'billing' => 
// //   array (
// //     'first_name' => 'test',
// //     'last_name' => 'test',
// //     'company' => '',
// //     'address_1' => 'te',
// //     'address_2' => 'test',
// //     'city' => 'test',
// //     'state' => 'THR',
// //     'postcode' => '1234567898',
// //     'country' => 'IR',
// //     'email' => 'm8811163008@gmail.com',
// //     'phone' => '',
// //   ),
// //   'shipping' => 
// //   array (
// //     'first_name' => '',
// //     'last_name' => '',
// //     'company' => '',
// //     'address_1' => '',
// //     'address_2' => '',
// //     'city' => '',
// //     'state' => '',
// //     'postcode' => '',
// //     'country' => '',
// //     'phone' => '',
// //   ),
// //   'payment_method' => '',
// //   'payment_method_title' => '',
// //   'transaction_id' => '',
// //   'customer_ip_address' => '',
// //   'customer_user_agent' => '',
// //   'created_via' => 'rest-api',
// //   'customer_note' => '',
// //   'date_completed' => null,
// //   'date_paid' => null,
// //   'cart_hash' => '',
// //   'number' => '13',
// //   'meta_data' => 
// //   array (
// //     0 => 
// //     array (
// //       'id' => 1,
// //       'key' => '_source_order_id',
// //       'value' => '30',
// //     ),
// //   ),
// //   'line_items' => 
// //   array (
// //     0 => 
// //     array (
// //       'id' => 1,
// //       'name' => 'ریال ایران',
// //       'product_id' => 12,
// //       'variation_id' => 0,
// //       'quantity' => 1000,
// //       'tax_class' => '',
// //       'subtotal' => '1000',
// //       'subtotal_tax' => '0',
// //       'total' => '1000',
// //       'total_tax' => '0',
// //       'taxes' => array (

// //       ),
// //       'meta_data' => array (

// //       ),
// //       'sku' => 'base_currency',
// //       'global_unique_id' => '',
// //       'price' => 1,
// //       'image' => 
// //       array (
// //         'id' => '',
// //         'src' => '',
// //       ),
// //       'parent_name' => null,
// //     ),
// //   ),
// //   'tax_lines' => array (

// //   ),
// //   'shipping_lines' => array (

// //   ),
// //   'fee_lines' => array (

// //   ),
// //   'coupon_lines' => array (

// //   ),
// //   'refunds' => array (

// //   ),
// //   'payment_url' => 'https://localhost/destination/checkout/order-pay/13/?pay_for_order=true&key=wc_order_ywFxmK1kVfPo9',
// //   'is_editable' => true,
// //   'needs_payment' => true,
// //   'needs_processing' => true,
// //   'date_created_gmt' => '2026-10-27T14:35:33',
// //   'date_modified_gmt' => '2026-10-27T14:35:33',
// //   'date_completed_gmt' => null,
// //   'date_paid_gmt' => null,
// //   'currency_symbol' => '﷼',
// //   '_links' => 
// //   array (
// //     'self' => 
// //     array (
// //       0 => 
// //       array (
// //         'href' => 'https://localhost/destination/wp-json/wc/v3/orders/13',
// //         'targetHints' => 
// //         array (
// //           'allow' => 
// //           array (
// //             0 => 'GET',
// //             1 => 'POST',
// //             2 => 'PUT',
// //             3 => 'PATCH',
// //             4 => 'DELETE',
// //           ),
// //         ),
// //       ),
// //     ),
// //     'collection' => 
// //     array (
// //       0 => 
// //       array (
// //         'href' => 'https://localhost/destination/wp-json/wc/v3/orders',
// //       ),
// //     ),
// //     'email_templates' => 
// //     array (
// //       0 => 
// //       array (
// //         'embeddable' => true,
// //         'href' => 'https://localhost/destination/wp-json/wc/v3/orders/13/actions/email_templates',
// //       ),
// //     ),
// //     'customer' => 
// //     array (
// //       0 => 
// //       array (
// //         'href' => 'https://localhost/destination/wp-json/wc/v3/customers/1',
// //       ),
// //     ),
// //   ),
// // )
//             return $result;
//         }


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
            // at this point we are sure that the user exist so
            // create checkout for this source order 


            // $payload = [
            //     'email'      => $user_email,
            //     'first_name' => $order->get_billing_first_name(),
            //     'last_name'  => $order->get_billing_last_name(),
            //     'billing'    => $order->get_address('billing'),
            //     'shipping'   => $order->get_address('shipping'),
            // ];
            // // update destination customers 
            // return $this->send( 'PUT', '/wc/v3/customers/' . absint( $existing_customer['id'] ), $payload );

            // // $existing_customers = $this->send( 'GET', '/wc/v3/customers?email=' . urlencode( $user_email ) );
            // $existing_customers = $this->send( 'GET', '/wp/v2/users?email=' . urlencode( 'test@test.test' ) );
            // if ( is_wp_error( $existing_customers ) ) {
            //     return $existing_customers;
            // }
            // if ( ! is_array( $existing_customers ) ) {
            //     return new WP_Error( 'invalid_customer_response', __( 'The destination returned an invalid customer lookup response.', 'source-sep-gateway' ) );
            // }

            // foreach ( $existing_customers as $existing_customer ) {
            //     if (
            //         isset( $existing_customer['id'], $existing_customer['email'] )
            //         && strtolower( $existing_customer['email'] ) === strtolower( $user_email )
            //     ) {
            //         return $this->send( 'PUT', '/wc/v3/customers/' . absint( $existing_customer['id'] ), $payload );
            //     }
            // }

            // return $this->send( 'POST', '/wc/v3/customers', $payload );
        }

        // function get_destination_checkout_redirect_url($order, $destination_order_id) {
        //     $result = $this -> send('GET', '/wc/v3/settings/advanced');
        //     $woocommerce_checkout_page_setting ;
        //     // copoilet : for each for result id
        //     foreach ($result as $item) {
        //         if (isset($item['id']) && $item['id'] === 'woocommerce_checkout_page_id') {
        //             $woocommerce_checkout_page_setting = $item; // Returns the full array for that setting item
        //         }
        //     }
        //     $woocommerce_checkout_page_id = $woocommerce_checkout_page_setting['value'];
        //     // send Get GET https://shop.example/wp-json/wp/v2/pages/{page_id}
        //     $checkout_page = $this -> send('GET', '/wp/v2/pages/' . $woocommerce_checkout_page_id);
        //     $checkout_page['link'];
        //     $base_link = rtrim( $checkout_page['link'], '/' ); // Removes trailing slash if present
        //     $order_id  = $order->get_id(); // Use WC_Abstract_Order::get_id() for compatibility

        //     // Result: https://destination-site.com/wc/store/v1/checkout/123
        //     $link = $base_link . '/' . $destination_order_id;
        //     // Append order reference if $order_id is provided
        //     $link = add_query_arg('order_ref', $order -> id, $link);
                
        //     return $link;
        // }

        // function destination_url():string|WP_Error {
        //     $parts = wp_parse_url( $this->dest_rest_api_url );

        //     if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
        //         return new WP_Error( 'invalid_destination_url', 'The destination REST API URL is invalid.' );
        //     }

        //     return $destination_url = $parts['scheme'] . '://' . $parts['host'];
        // }

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

            // final class OrderStatus {
            // 	/**
            // 	 * The order has been received, but no payment has been made.
            // 	 *
            // 	 * @var string
            // 	 */
            // 	public const PENDING = 'pending';

            // 	/**
            // 	 * The customer’s payment failed or was declined, and no payment has been successfully made.
            // 	 *
            // 	 * @var string
            // 	 */
            // 	public const FAILED = 'failed';

            // 	/**
            // 	 * The order is awaiting payment confirmation.
            // 	 *
            // 	 * @var string
            // 	 */
            // 	public const ON_HOLD = 'on-hold';

            // 	/**
            // 	 * Order fulfilled and complete.
            // 	 *
            // 	 * @var string
            // 	 */
            // 	public const COMPLETED = 'completed';

            // 	/**
            // 	 * Payment has been received (paid), and the stock has been reduced.
            // 	 *
            // 	 * @var string
            // 	 */
            // 	public const PROCESSING = 'processing';

            // 	/**
            // 	 * Orders are automatically put in the Refunded status when an admin or shop manager has fully refunded the order’s value after payment.
            // 	 *
            // 	 * @var string
            // 	 */
            // 	public const REFUNDED = 'refunded';

            // 	/**
            // 	 * The order was canceled by an admin or the customer.
            // 	 *
            // 	 * @var string
            // 	 */
            // 	public const CANCELLED = 'cancelled';


            // 	/**
            // 	 * Checkout Draft orders are created when customers start the checkout process while the block version of the checkout is in place.
            // 	 *
            // 	 * @var string
            // 	 */
            // 	public const CHECKOUT_DRAFT = 'checkout-draft';

            // 	/**
            // 	 * Array of all the valid order statuses for a complete payment.
            // 	 *
            // 	 * @var string[]
            // 	 */
            // 	public const PAYMENT_COMPLETE_STATUSES = array(
            // 		self::ON_HOLD,
            // 		self::PENDING,
            // 		self::FAILED,
            // 		self::CANCELLED,
            // 	);

            // 	/**
            // 	 * Returns every order status value defined by this enum, as a flat list of unprefixed slugs.
            // 	 *
            // 	 * It differs from the statuses WooCommerce registers in both directions. It carries
            // 	 * self::TRASH, self::NEW, self::AUTO_DRAFT and self::DRAFT, which wc_get_order_statuses() does
            // 	 * not return by default, and it cannot carry statuses an extension registers.
            // 	 * WC_Abstract_Order::set_status() accepts self::TRASH and self::AUTO_DRAFT even so, exempting
            // 	 * them from its own validation.
            // 	 *
            // 	 * self::CHECKOUT_DRAFT sits on the helper's side of that line: WooCommerce adds it through the
            // 	 * `wc_order_statuses` filter, and the Store API assigns it to live orders during checkout.
            // 	 *
            // 	 * For the registered statuses, use wc_get_order_statuses(). It returns a value => label map
            // 	 * keyed on the `wc-` prefixed slug, so comparing its keys with this enum's values needs
            // 	 * OrderUtil::remove_status_prefix(). OrderInternalStatus lists the seven core statuses in
            // 	 * prefixed form.
            // 	 *
            // 	 * @since 10.9.0
            // 	 *
            // 	 * @return string[]
            // 	 */
            // 	public static function get_all(): array {
            // 		return array(
            // 			self::PENDING,
            // 			self::FAILED,
            // 			self::ON_HOLD,
            // 			self::COMPLETED,
            // 			self::PROCESSING,
            // 			self::REFUNDED,
            // 			self::CANCELLED,
            // 			self::TRASH,
            // 			self::NEW,
            // 			self::AUTO_DRAFT,
            // 			self::DRAFT,
            // 			self::CHECKOUT_DRAFT,
            // 		);
            // 	}
            // }
            
        
        
        
            // use Automattic\WooCommerce\Enums\OrderStatus;
            // Start the remote payment request here. Do not mark the order paid yet.
            $order->update_status(
                'pending',
                __( 'Redirecting customer to payment provider.', 'source-sep-gateway' )
            );
            // add some rest api to destination website by app password header 
            // create customer on destination website by woocomerce rest api

            // create the user
            
            // populate checkout fields billing shipping checkout form fields
            $this->ensure_registration( $order );
            // create order on destination website 

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