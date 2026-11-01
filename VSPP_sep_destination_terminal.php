<?php
/**
 * Plugin Name: VSPP Sep Destination Terminal
 * Description: VSPP Sep Destination Terminal to catch request from source terminal and handle some payment processes.
 * Version:     1.5.0
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
     * Intercepts only when the user returns from the bank to the Order Received (Thank You) page.
     */
    public function handle_gateway_return_redirect() {
        // Ensure WooCommerce is active and we are on the order-received endpoint.
        if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-received' ) ) {
            return;
        }

        global $wp;
        $order_id = 0;

        // Extract order ID from WooCommerce query vars or URL parameters.
        if ( isset( $wp->query_vars['order-received'] ) ) {
            $order_id = intval( $wp->query_vars['order-received'] );
        } elseif ( isset( $_GET['order_id'] ) ) {
            $order_id = intval( $_GET['order_id'] );
        }

        $order = null;
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
        } elseif ( isset( $_GET['key'] ) ) {
            $order_key   = sanitize_text_field( $_GET['key'] );
            $resolved_id = wc_get_order_id_by_order_key( $order_key );
            if ( $resolved_id ) {
                $order = wc_get_order( $resolved_id );
            }
        }

        if ( ! $order ) {
            return;
        }

        // Retrieve source redirect metadata.
        $source_redirect_url = $order->get_meta( '_source_redirect_url' );

        // If no source redirect URL is stored, let the normal WooCommerce thank you page render.
        if ( empty( $source_redirect_url ) ) {
            return;
        }

        $order_key       = $order->get_order_key();
        $verify_rest_url = $this->get_source_rest_api_url( $source_redirect_url, $order_key );

        // Trigger the remote API verification request.
        $verification_result = $this->send_remote_verification( $verify_rest_url );

        if ( is_wp_error( $verification_result ) ) {
            return $verification_result;
        }

        $redirect_url = add_query_arg(
            array(
                'status'                => $order->get_status(),
                'destination_order_key' => $order_key,
            ),
            $source_redirect_url
        );

        // Perform cross-domain redirect back to source site.
        wp_redirect( esc_url_raw( $redirect_url ) );
        exit;
    }

    /**
     * Constructs target REST API URL back to the source site.
     *
     * @param string $source_redirect_url
     * @param string $order_key
     * @return string
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
     *
     * @param string $verify_rest_url
     * @return true|WP_Error
     */
    private function send_remote_verification( $verify_rest_url ) {
        $response = wp_remote_get( $verify_rest_url );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status        = wp_remote_retrieve_response_code( $response );
        $response_body = json_decode( wp_remote_retrieve_body( $response ), true );

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
            <p><?php esc_html_e( 'Simple Custom Plugin is active and running smoothly.', 'simple-custom-plugin' ); ?></p>
        </div>
        <?php
    }

    /**
     * Runs once when the plugin is activated.
     */
    public static function activate() {
        if ( ! get_option( 'destination_sep_gateway_version' ) ) {
            add_option( 'destination_sep_gateway_version', '1.2.0' );
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
        // Security check: Ensure WordPress is actually executing the uninstall process
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            return;
        }

        // Clean up options or custom database entries
        delete_option( 'destination_sep_gateway_version' );
    }
}

// Register lifecycle hooks
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