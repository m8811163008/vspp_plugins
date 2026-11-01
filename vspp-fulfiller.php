////Add this key to the x-access-token header in your requests.




<?php



/**
 * Plugin Name: VSPP Fulfiller & Automation
 * Description: Receives webhook payloads from the marketplace, validates headers, and automatically provisions Debian tunnel accounts.
 * Version: 1.0.2
 * Author: Custom
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VSPP_Fulfiller_Automator {

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_webhook_endpoint' ) );
    }

    /**
     * Register custom REST API endpoint: POST /wp-json/vspp/v1/fulfill
     */
    public function register_webhook_endpoint() {
        register_rest_route( 'vspp/v1', '/fulfill', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_webhook_request' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * Handle incoming webhook request
     */
    public function handle_webhook_request( WP_REST_Request $request ) {
        $auth_header = $request->get_header( 'x_vendor_secret' );
        if ( empty( $auth_header ) ) {
            $auth_header = $request->get_header( 'authorization' );
        }

        $expected_secret = get_option( 'vspp_webhook_secret', 'my_default_secret_token' );

        if ( empty( $auth_header ) || ! hash_equals( $expected_secret, trim( str_replace( 'Bearer ', '', $auth_header ) ) ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Unauthorized: Invalid or missing secret token.' ), 401 );
        }

        $params = $request->get_json_params();
        if ( empty( $params ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid JSON payload.' ), 400 );
        }

        $order_id = isset( $params['order_id'] ) ? sanitize_text_field( $params['order_id'] ) : '';
        $customer = isset( $params['customer'] ) ? $params['customer'] : array();
        $items    = isset( $params['items'] ) ? $params['items'] : array();

        if ( empty( $order_id ) || empty( $customer ) || empty( $items ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Missing required order data.' ), 400 );
        }

        $customer_email = isset( $customer['email'] ) ? sanitize_email( $customer['email'] ) : '';
        $customer_phone = isset( $customer['phone'] ) ? sanitize_text_field( $customer['phone'] ) : '';
        
        $raw_username = ! empty( $customer_email ) ? current( explode( '@', $customer_email ) ) : $customer_phone;
        $username     = sanitize_user( preg_replace( '/[^a-zA-Z0-9_]/', '', $raw_username ), true );
        if ( empty( $username ) || strlen( $username ) < 3 ) {
            $username = 'user_' . wp_rand( 1000, 9999 );
        }

        $password = wp_generate_password( 10, false );

        $total_days = 30; 
        foreach ( $items as $item ) {
            $qty = isset( $item['quantity'] ) ? intval( $item['quantity'] ) : 1;
            $total_days = 30 * $qty;
            break;
        }

        $script_path = '/usr/local/bin/vspp_bw_auto.sh';
        if ( ! file_exists( $script_path ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Provisioning script not found on server.' ), 500 );
        }

        $cmd = sprintf( 'sudo %s %s %d %s 2>&1', 
            escapeshellcmd( $script_path ),
            escapeshellarg( $username ),
            intval( $total_days ),
            escapeshellarg( $password )
        );

        $output = array();
        $return_var = 0;
        exec( $cmd, $output, $return_var );

        if ( $return_var !== 0 ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Failed to execute provisioning script.',
                'output'  => implode( "\n", $output ),
            ), 500 );
        }

        return new WP_REST_Response( array(
            'success'      => true,
            'message'      => 'Tunnel account successfully created/updated.',
            'username'     => $username,
            'password'     => $password,
            'expires_days' => $total_days,
            'script_output'=> implode( "\n", $output ),
        ), 200 );
    }
}

if ( class_exists( 'VSPP_Fulfiller_Automator' ) ) {
    new VSPP_Fulfiller_Automator();
}

/**
 * Add Settings Menu Page
 */
add_action( 'admin_menu', 'vspp_fulfill_add_admin_menu' );
function vspp_fulfill_add_admin_menu() {
    add_menu_page(
        'VSPP Fulfiller',
        'VSPP Fulfiller',
        'manage_options',             // Change to 'manage_woocommerce' or 'edit_posts' if vendor is not full admin
        'vspp-fulfill',
        'vspp_fulfill_settings_page',
        'dashicons-admin-network',
        56
    );
}

function vspp_fulfill_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have sufficient permissions to access this page.' );
    }

    if ( isset( $_POST['vspp_save_secret'] ) && check_admin_referer( 'vspp_secret_nonce' ) ) {
        update_option( 'vspp_webhook_secret', sanitize_text_field( $_POST['vspp_webhook_secret'] ) );
        echo '<div class="updated notice"><p><strong>Settings saved successfully.</strong></p></div>';
    }

    $secret = get_option( 'vspp_webhook_secret', 'my_default_secret_token' );
    ?>
    <div class="wrap">
        <h1>VSPP Fulfiller & Automation Settings</h1>
        <form method="post" action="">
            <?php wp_nonce_field( 'vspp_secret_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="vspp_webhook_secret">Webhook Secret Token</label></th>
                    <td>
                        <input name="vspp_webhook_secret" type="text" id="vspp_webhook_secret" value="<?php echo esc_attr( $secret ); ?>" class="regular-text" />
                        <p class="description">Enter the secret token that matches the vendor custom header configured in the marketplace.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Save Changes', 'primary', 'vspp_save_secret' ); ?>
        </form>
        <h2>Your Webhook Endpoint URL</h2>
        <p>Provide this REST API endpoint URL to your Dokan marketplace settings:</p>
        <code><?php echo esc_url( rest_url( 'vspp/v1/fulfill' ) ); ?></code>
    </div>
    <?php
}