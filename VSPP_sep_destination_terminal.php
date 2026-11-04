<?php
/**
 * Plugin Name: VSPP Sep Destination Terminal
 * Description: VSPP Sep Destination Terminal to catch request from source terminal and handle some payment processes with built-in debugging.
 * Version:     2.3.5
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

/**
 * Main Plugin Class
 */
class Destination_Sep_Gateway {
// redirect from https://payment.vira-system-paydar-pars.ir/%d9%be%d8%b1%d8%af%d8%a7%d8%ae%d8%aa/order-pay/253/?pay_for_order=true&key=wc_order_PwwH1T6trLyOt
// to bank 
// https://payment.vira-system-paydar-pars.ir/%d9%be%d8%b1%d8%af%d8%a7%d8%ae%d8%aa/order-received/253/?key=wc_order_PwwH1T6trLyOt&wc_status=success
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
        add_filter( 'template_redirect', array( $this, 'handle_gateway_return_redirect' ) );
        add_action( 'admin_notices', array( $this, 'plugin_admin_notice' ) );
    }

    public function load_textdomain() {
        load_plugin_textdomain(
            'simple-custom-plugin',
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages'
        );
    }

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
//TODO handle https://payment.vira-system-paydar-pars.ir/%d9%be%d8%b1%d8%af%d8%a7%d8%ae%d8%aa/order-pay/257/?pay_for_order=true&key=wc_order_zy4xAA8CtS0X5
// we should update the checkout based on this so when the user cancle the payment when
//  it return to checkout the checkout page filled with this order

/**
     * Intercepts only when the user returns from the bank to the Order Received (Thank You) page,
     * triggers the synchronization endpoint on the source site, and redirects the user back.
     */
    public function handle_gateway_return_redirect() {
        // Ensure WooCommerce is active and we are on the order-received endpoint.
        if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-received' ) ) {
            return;
        }

        global $wp;
        $order_id = 0;

        $this->log_debug( 'Hit order-received endpoint.', array( 'request_uri' => $_SERVER['REQUEST_URI'] ?? '' ) );

        // Extract order ID from WooCommerce query vars or URL parameters.
        if ( isset( $wp->query_vars['order-received'] ) ) {
            $order_id = intval( $wp->query_vars['order-received'] );
        } elseif ( isset( $_GET['order_id'] ) ) {
            $order_id = intval( $_GET['order_id'] );
        }

        $order = null;
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            $this->log_debug( 'Attempted order lookup by ID.', array( 'order_id' => $order_id, 'found' => (bool) $order ) );
        } elseif ( isset( $_GET['key'] ) ) {
            $order_key   = sanitize_text_field( $_GET['key'] );
            $resolved_id = wc_get_order_id_by_order_key( $order_key );
            $this->log_debug( 'Attempted order lookup by key.', array( 'order_key' => $order_key, 'resolved_id' => $resolved_id ) );
            if ( $resolved_id ) {
                $order = wc_get_order( $resolved_id );
            }
        }

        if ( ! $order ) {
            $this->log_debug( 'ERROR: Could not resolve WooCommerce order instance from request.' );
            return;
        }

        $this->log_debug( 'Order successfully resolved.', array( 'order_id' => $order->get_id(), 'status' => $order->get_status() ) );

        // Retrieve stored redirect and sync metadata from destination order
        $redirect_url_to_source = $order->get_meta( '_redirect_url_to_source_checkout' );
        $sync_endpoint_url      = $order->get_meta( '_sync_orders_endpoint_on_source' );

//      Build an associative array of all order metadata for debugging inspection
        $all_meta_data = array();
        foreach ( $order->get_meta_data() as $meta ) {
            // $meta->key and $meta->value contain the meta info
            $all_meta_data[ $meta->key ] = $meta->value;
        }

        $this->log_debug( 'Inspecting order metadata dump.', array(
            'order_id'               => $order->get_id(),
            'target_redirect_meta'   => $redirect_url_to_source,
            'target_sync_meta'       => $sync_endpoint_url,
            'all_available_meta'     => $all_meta_data,
        ) );

        if ( empty( $redirect_url_to_source ) ) {
            $this->log_debug( 'Skipping redirection: _redirect_url_to_source_checkout meta is empty.' );
            return;
        }
        $source_order_key = $order->get_meta( '_source_order_key' );
        $this->log_debug( 'Trigger the remote GET synchronization request.', array( 'sync_endpoint_url' => $sync_endpoint_url, 'source_order_key' => $source_order_key ) );
        // 1. Trigger the remote GET synchronization request back to the source site
        if ( ! empty( $sync_endpoint_url ) && ! empty( $source_order_key ) ) {
            $sync_request_url = add_query_arg(
                array(
                    'destination_order_id' => $order->get_id(),
                ),
                $sync_endpoint_url
            );

                    

            $this->log_debug( 'Initiating remote order synchronization GET request.', array( 'sync_url' => $sync_request_url ) );

            $response = wp_remote_get( esc_url_raw( $sync_request_url ), array(
                'timeout'   => 15,
                'sslverify' => true,
            ) );

            if ( is_wp_error( $response ) ) {
                $this->log_debug( 'ERROR: Remote order synchronization failed.', array( 'error_message' => $response->get_error_message() ) );
            } else {
                $response_code = wp_remote_retrieve_response_code( $response );
                $response_body = wp_remote_retrieve_body( $response );
                $this->log_debug( 'Remote order synchronization response received.', array(
                    'response_code' => $response_code,
                    'response_body' => $response_body,
                ) );
            }
        }

        // 2. Append destination order key and status to the final redirect URL back to source
        $final_redirect_url = add_query_arg(
            array(
                // 'destination_order_key' => $order->get_order_key(),
                // 'destination_order_id' => $order->get_id(),
                'status'                => $order->get_status(),
            ),
            $redirect_url_to_source
        );

        $this->log_debug( 'Executing final cross-domain redirect.', array( 
            'final_redirect_url' => $final_redirect_url,
            'destination_order_key' => $order->get_order_key(),
            'destination_order_id' => $order->get_id(),
            'status'                => $order->get_status(),
             ) );

        // Perform cross-domain redirect back to source site.
        wp_redirect( esc_url_raw( $final_redirect_url ) );
        exit;
    }

    /**
     * Constructs target REST API URL back to the source site.
     */
    private function get_source_rest_api_url( $source_redirect_url, $order_key ) {
        $parsed_url      = parse_url( $source_redirect_url );
        $scheme          = $parsed_url['scheme'] ?? 'http';
        $host            = $parsed_url['host'] ?? '';
        $redirect_domain = $scheme . '://' . $host;
        
        $source_restapi_url = $redirect_domain . '/wp-json/verify_order/source-gateway/v1';
        
        return add_query_arg(
            array(
                'destination_order_key' => $order_key,
            ),
            $source_restapi_url
        );
    }

    /**
     * Sends the remote GET request to verify the order and handles errors.
     */
    private function send_remote_verification( $verify_rest_url ) {
        $response = wp_remote_get( $verify_rest_url, array( 'timeout' => 15 ) );

        if ( is_wp_error( $response ) ) {
            $this->log_debug( 'wp_remote_get returned WP_Error.', array( 'message' => $response->get_error_message() ) );
            return $response;
        }

        $status        = wp_remote_retrieve_response_code( $response );
        $response_body_raw = wp_remote_retrieve_body( $response );
        $response_body = json_decode( $response_body_raw, true );

        $this->log_debug( 'Remote verification response received.', array(
            'response_code' => $status,
            'body'          => $response_body_raw
        ) );

        if ( $status < 200 || $status >= 300 ) {
            return new WP_Error(
                'api_request_failed',
                $response_body['message'] ?? 'The remote API request failed.',
                array( 'status' => $status )
            );
        }

        return true;
    }

    public function plugin_admin_notice() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'VSPP Sep Destination Terminal is active and running smoothly.', 'simple-custom-plugin' ); ?></p>
        </div>
        <?php
    }

    /**
     * Runs once when the plugin is activated.
     */
    public static function activate() {
        if ( ! get_option( 'destination_sep_gateway_version' ) ) {
            add_option( 'destination_sep_gateway_version', '2.1.0' );
        }
        flush_rewrite_rules();
    }

    /**
     * Runs once when the plugin is deactivated.
     */
    public static function deactivate() {
        flush_rewrite_rules();
    }

    public static function uninstall() {
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            return;
        }

        // Clean up options or custom database entries
        delete_option( 'destination_sep_gateway_version' );
    }
}

register_activation_hook( __FILE__, array( 'Destination_Sep_Gateway', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Destination_Sep_Gateway', 'deactivate' ) );
register_uninstall_hook( __FILE__, array( 'Destination_Sep_Gateway', 'uninstall' ) );

/**
 * Initialize the plugin for normal requests.
 */
function run_destination_sep_gateway() {
    return Destination_Sep_Gateway::get_instance();
}

run_destination_sep_gateway();