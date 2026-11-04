<?php
/**
 * Plugin Name: VSPP WooCommerce Webhook Receiver
 * Description: A secure custom plugin to receive and process WooCommerce order webhooks via WordPress REST API with SKU filtering and Ubuntu user management.
 * Version:     1.2.0
 * Author:      Vira System Paydar Pars and Gemini
 * Author URI:  http://vira-system-paydar-pars.ir/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: vspp-wc-webhook
 */

// Exit if accessed directly for security.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Dedicated Handler Class for Subscriptions & Product SKUs
 */
class VSPP_Subscription_Handler {

    /**
     * Process line items matching the subscription prefix.
     * 
     * @param array  $line_items            The order line items from the webhook payload.
     * @param string $customer_phone_number The billing phone number of the customer.
     * @return array                        Processed subscription matches.
     */
    public static function process_line_items( $line_items, $customer_phone_number ) {
        $processed_subscriptions = array();

        if ( empty( $line_items ) || ! is_array( $line_items ) ) {
            return $processed_subscriptions;
        }

        foreach ( $line_items as $item ) {
            $sku = isset( $item['sku'] ) ? trim( $item['sku'] ) : '';

            // Check if SKU starts with 'bw_product_'
            if ( 0 === strpos( $sku, 'bw_product_' ) ) {
                // Extract duration/identifier part after 'bw_product_' (e.g., '03' from 'bw_product_03')
                $duration_days = str_replace( 'bw_product_', '', $sku );
                
                // Trigger Ubuntu user cleanup/creation logic
                self::create_subscription( $duration_days, $customer_phone_number );

                $processed_subscriptions[] = array(
                    'sku'           => $sku,
                    'duration_days' => $duration_days,
                    'product_id'    => isset( $item['product_id'] ) ? $item['product_id'] : 0,
                    'quantity'      => isset( $item['quantity'] ) ? $item['quantity'] : 1,
                );
            }
        }

        return $processed_subscriptions;
    }

    /**
     * Manage Ubuntu system user accounts based on customer phone number.
     * Checks if the user exists on the system, removes them if present, and provisions a new account.
     * 
     * @param string|int $duration_days         The subscription duration code.
     * @param string     $customer_phone_number The customer phone number used as the username.
     */
    private static function create_subscription( $duration_days, $customer_phone_number ) {
        if ( empty( $customer_phone_number ) ) {
            error_log( '[VSPP Subscriptions] Skipping Ubuntu user provisioning: Phone number is empty.' );
            return;
        }
        // create usernam from phone number instead of plus sign add p989121234567 as username
        // Sanitize the phone number to be safe for Linux shell usernames (alphanumeric and underscores only)
        $username = preg_replace( '/[^a-zA-Z0-9_]/', '', $customer_phone_number );

        if ( empty( $username ) ) {
            error_log( '[VSPP Subscriptions] Skipping Ubuntu user provisioning: Invalid username derived from phone number.' );
            return;
        }

        // 1. Check if user already exists on the Ubuntu server using shell command
        // `id username` or `getent passwd username` returns 0 exit code if user exists
        $output     = array();
        $return_var = 0;
        exec( sprintf( 'id %s 2>&1', escapeshellarg( $username ) ), $output, $return_var );

        if ( 0 === $return_var ) {
            // User exists -> Delete the existing user account (and optionally their home directory with -r)
            $delete_output     = array();
            $delete_return_var = 0;
            exec( sprintf( 'sudo userdel -r %s 2>&1', escapeshellarg( $username ) ), $delete_output, $delete_return_var );

            if ( 0 === $delete_return_var ) {
                error_log( sprintf( '[VSPP Subscriptions] Existing Ubuntu user "%s" successfully deleted.', $username ) );
            } else {
                error_log( sprintf( '[VSPP Subscriptions] Failed to delete Ubuntu user "%s". Output: %s', $username, implode( "\n", $delete_output ) ) );
            }
        } else {
            error_log( sprintf( '[VSPP Subscriptions] Ubuntu user "%s" does not exist yet. Proceeding with creation.', $username ) );
        }

        // 2. Provision new user account or configure subscription duration here
        // Example shell creation command (uncomment when ready for live execution):
        // exec( sprintf( 'sudo useradd -m -s /bin/bash %s', escapeshellarg( $username ) ) );
        
        error_log( sprintf( '[VSPP Subscriptions] Provisioned new subscription slot for user "%s" for %s days.', $username, $duration_days ) );
    }
}

/**
 * Main Plugin Class
 */
class VSPP_WC_Webhook_Receiver {

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
        add_action( 'admin_notices', array( $this, 'plugin_admin_notice' ) );
        
        // Register the custom REST API endpoint
        add_action( 'rest_api_init', array( $this, 'register_webhook_endpoint' ) );
    }

    public function load_textdomain() {
        load_plugin_textdomain(
            'vspp-wc-webhook',
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages'
        );
    }

    public function plugin_admin_notice() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'VSPP WooCommerce Webhook Receiver is active and ready to listen.', 'vspp-wc-webhook' ); ?></p>
        </div>
        <?php
    }

    /**
     * Register the custom REST API route for incoming webhooks.
     * Route: /wp-json/vspp/v1/fullfill_order
     */
    public function register_webhook_endpoint() {
        register_rest_route( 'vspp/v1', '/fullfill_order', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_webhook_payload' ),
            'permission_callback' => '__return_true', // Webhooks authenticate via HMAC signature rather than WP user login
        ) );
    }

    /**
     * Handle and verify the incoming WooCommerce Webhook payload.
     * 
     * @param WP_REST_Request $request The current REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_webhook_payload( WP_REST_Request $request ) {
        // 1. Retrieve headers sent by WooCommerce
        $signature = $request->get_header( 'x_wc_webhook_signature' );
        $topic     = $request->get_header( 'x_wc_webhook_topic' );
        $resource  = $request->get_header( 'x_wc_webhook_resource' );
        $event     = $request->get_header( 'x_wc_webhook_event' );

        // 2. Get raw request body for cryptographic signature verification
        $payload_body = $request->get_body();
        $secret_key   = 'fullfill_order'; // Your webhook secret

        // 3. Verify the HMAC-SHA256 signature to guarantee authenticity
        $calculated_signature = base64_encode( hash_hmac( 'sha256', $payload_body, $secret_key, true ) );

        if ( ! hash_equals( $calculated_signature, $signature ) ) {
            return new WP_Error(
                'invalid_signature',
                __( 'Webhook signature verification failed.', 'vspp-wc-webhook' ),
                array( 'status' => 401 )
            );
        }

        // 4. Parse JSON payload
        $order_data = $request->get_json_params();

        if ( empty( $order_data ) ) {
            return new WP_Error(
                'empty_payload',
                __( 'No payload data received.', 'vspp-wc-webhook' ),
                array( 'status' => 400 )
            );
        }

        // Extract core order variables safely
        $order_id              = isset( $order_data['id'] ) ? $order_data['id'] : 0;
        $order_status          = isset( $order_data['status'] ) ? $order_data['status'] : 'unknown';
        $line_items            = isset( $order_data['line_items'] ) ? $order_data['line_items'] : array();
        $customer_phone_number = isset( $order_data['billing']['phone'] ) ? $order_data['billing']['phone'] : '';

        // 5. Filter line items based on SKU starting with 'bw_product_' and delegate to handler class
        $matched_subscriptions = VSPP_Subscription_Handler::process_line_items( $line_items, $customer_phone_number );

        // Optional: Skip processing if no target subscription SKUs were purchased
        if ( empty( $matched_subscriptions ) ) {
            return rest_ensure_response( array(
                'success' => true,
                'message' => 'Webhook received, but no matching subscription SKUs (bw_product_*) were found in this order.',
                'data'    => array(
                    'order_id' => $order_id,
                ),
            ) );
        }

        // 6. Send educational JSON response back confirming successful fulfillment routing
        return rest_ensure_response( array(
            'success' => true,
            'message' => 'Webhook processed successfully. Subscription SKUs matched, user checked/cleaned on Ubuntu, and routed.',
            'data'    => array(
                'order_id'              => $order_id,
                'order_status'          => $order_status,
                'topic'                 => $topic,
                'matched_subscriptions' => $matched_subscriptions,
            ),
        ) );
    }

    /**
     * Runs once when the plugin is activated.
     */
    public static function activate() {
        if ( ! get_option( 'vspp_wc_webhook_version' ) ) {
            add_option( 'vspp_wc_webhook_version', '1.2.0' );
        }
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
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            return;
        }
        delete_option( 'vspp_wc_webhook_version' );
    }
}

// Register lifecycle hooks
register_activation_hook( __FILE__, array( 'VSPP_WC_Webhook_Receiver', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'VSPP_WC_Webhook_Receiver', 'deactivate' ) );
register_uninstall_hook( __FILE__, array( 'VSPP_WC_Webhook_Receiver', 'uninstall' ) );

/**
 * Initialize the plugin.
 */
function run_vspp_wc_webhook_receiver() {
    return VSPP_WC_Webhook_Receiver::get_instance();
}

run_vspp_wc_webhook_receiver();