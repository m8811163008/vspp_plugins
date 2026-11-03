<?php
/**
 * Plugin Name: VSPP WooCommerce BACS Addon
 * Plugin URI: https://vira-system-paydar-pars.ir
 * Description: Show product price value based on BACS account multipliers
 * Version: 1.4.1
 * Author: Vira System Paydar Pars
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VSPP_WooCommerce_BACS_Addon {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // 1. Filter bank details on checkout/thank-you pages
        add_filter( 'woocommerce_bacs_account_fields', array( $this, 'add_custom_text_to_bank_details' ), 10, 2 );

        // // 2. Add global settings field to BACS settings page
        // add_filter( 'woocommerce_bacs_account_fields', array( $this, 'add_global_bacs_settings_fields' ) );

        // // 3. Save custom account multipliers when BACS settings are saved
        // add_action( 'woocommerce_update_options_payment_gateways_bacs', array( $this, 'save_bacs_account_multipliers' ) );

        // // 4. Inject admin script to render multiplier inputs inside each BACS account row
        add_filter( 'woocommerce_thankyou_bacs', array( $this, 'render_bacs_price_on_thankyou_page' ) );
        
    }
    private int $multiplier = 2700000;

    /**
     * Add global settings fields under BACS
     */
    public function add_global_bacs_settings_fields( $form_fields ) {
        $form_fields['custom_global_multiplier'] = array(
            'title'             => __( 'Global BACS Multiplier', 'text-domain' ),
            'type'              => 'number',
            'description'       => __( 'Default multiplier for all bank accounts if not specified.', 'text-domain' ),
            'default'           => '1.0',
            'desc_tip'          => true,
            'custom_attributes' => array(
                'step' => 'any',
            ),
        );
        return $form_fields;
    }

    /**
     * Save custom account multipliers when BACS settings are saved
     */
    public function save_bacs_account_multipliers() {
        if ( isset( $_POST['bacs_multiplier'] ) && is_array( $_POST['bacs_multiplier'] ) ) {
            $multipliers = array_map( 'sanitize_text_field', $_POST['bacs_multiplier'] );
            update_option( 'vspp_bacs_account_multipliers', $multipliers );
        } else {
            delete_option( 'vspp_bacs_account_multipliers' );
        }
    }

    /**
     * Add js to thankyou page
     */
    public function render_bacs_price_on_thankyou_page() {
        // 1. Get the order key from the URL (e.g., ?key=wc_order_Dbw3Z3dfAt3Yb)
        $order_key = isset( $_GET['key'] ) ? sanitize_text_field( $_GET['key'] ) : '';

        if ( ! empty( $order_key ) ) {
            // Find the order ID using the unique order key from the URL
            $order_id = wc_get_order_id_by_order_key( $order_key );
        }

        // Fallback: Check WordPress query vars if the key wasn't in $_GET directly
        if ( ! $order_id && function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
            global $wp;
            $order_id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
        }

        // If we still don't have a valid order ID, exit
        if ( ! $order_id ) {
            return;
        }

        // Get the WooCommerce order object
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $resolved_order_total = $order->get_total();
        $silver_price = $this -> get_current_silver_price();
        $multiplier = $this -> multiplier;

        // Output the JavaScript snippet with PHP variables securely injected
        ?>
            <script type="text/javascript">
                document.addEventListener('DOMContentLoaded', function() {
                    const orderKey = <?php echo json_encode( (string) $order_key, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP ); ?>;
                    const orderTotal = <?php echo json_encode( (float) $resolved_order_total ); ?>;
                    const silverPrice = <?php echo json_encode( (int) $silver_price ); ?>;
                    const multiplier = <?php echo json_encode( (int) $multiplier ); ?>;
                    var total = orderTotal * silverPrice * multiplier;
                    total = Math.round(total / 1000) * 1000;

                    // Select ALL account name headings instead of just the first one
                    const accountNameHeadings = document.querySelectorAll('.wc-bacs-bank-details-account-name');

                    if (accountNameHeadings.length > 0) {
                        accountNameHeadings.forEach(function(heading) {
                            heading.textContent += ' ریال ' + total;
                        });
                    }
                });
            </script>
        <?php
    }


    /**
     * Customize BACS bank details to show calculated values using multipliers
     */
    public function add_custom_text_to_bank_details( $bacs_account, $order_id ) {

        // 'iban'           => array(
		// 					'label' => __( 'IBAN', 'woocommerce' ),
		// 					'value' => $bacs_account->iban,
		// 				),
		$bacs_account['change_miltiplier'] = array(
							'label' => __( 'change miltiplier 1 USD/IRR', 'woocommerce' ),
							// 'value' => $bacs_account->iban,
							'value' => $this -> multiplier,
						);
        return $bacs_account;
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
}

VSPP_WooCommerce_BACS_Addon::get_instance();