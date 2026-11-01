<?php
/**
 * Plugin Name: VSPP WooCommerce BACS Addon
 * Plugin URI: https://vira-system-paydar-pars.ir
 * Description: Show product price value based on BACS account multipliers
 * Version: 1.3.1
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
        add_filter( 'woocommerce_bacs_accounts', array( $this, 'add_custom_text_to_bank_details' ), 10, 2 );

        // 2. Add global settings field to BACS settings page
        add_filter( 'woocommerce_settings_api_form_fields_bacs', array( $this, 'add_global_bacs_settings_fields' ) );

        // 3. Save custom account multipliers when BACS settings are saved
        add_action( 'woocommerce_update_options_payment_gateways_bacs', array( $this, 'save_bacs_account_multipliers' ) );

        // 4. Inject admin script to render multiplier inputs inside each BACS account row
        add_action( 'admin_footer', array( $this, 'render_bacs_multiplier_admin_script' ) );
    }

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
     * Inject Admin JavaScript to add the Multiplier column input into the BACS accounts table
     */
    public function render_bacs_multiplier_admin_script() {
        // Only run on WooCommerce payment settings page for BACS
        if ( ! isset( $_GET['page'] ) || 'wc-settings' !== $_GET['page'] || ! isset( $_GET['tab'] ) || 'checkout' !== $_GET['tab'] || ! isset( $_GET['section'] ) || 'bacs' !== $_GET['section'] ) {
            return;
        }

        $saved_multipliers = get_option( 'vspp_bacs_account_multipliers', array() );
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                var savedMultipliers = <?php echo json_encode( $saved_multipliers ); ?>;

                function appendMultiplierField() {
                    // Target the BACS accounts table rows
                    $('#bacs_accounts tr.wc-metabox').each(function(index) {
                        var $row = $(this);
                        if ($row.find('.vspp-multiplier-row').length === 0) {
                            var val = savedMultipliers[index] !== undefined ? savedMultipliers[index] : '1.0';
                            var fieldHtml = '<p class="form-row form-row-first vspp-multiplier-row">' +
                                '<label><?php esc_html_e( 'Account Multiplier', 'text-domain' ); ?></label>' +
                                '<input type="number" step="any" name="bacs_multiplier[' + index + ']" value="' + val + '" />' +
                                '</p>';
                            $row.find('.wc-metabox-content').append(fieldHtml);
                        }
                    });
                }

                // Run on load
                appendMultiplierField();

                // Run when WooCommerce adds a new account row dynamically via button click
                $('#bacs_accounts').on('click', '.add.button', function() {
                    setTimeout(appendMultiplierField, 100);
                });
            });
        </script>
        <?php
    }

    /**
     * Customize BACS bank details to show calculated values using multipliers
     */
    public function add_custom_text_to_bank_details( $accounts, $order_id = null ) {
        if ( ! is_array( $accounts ) ) {
            return $accounts;
        }

        $base_price = $this->get_current_silver_price();
        $saved_multipliers = get_option( 'vspp_bacs_account_multipliers', array() );
        $bacs_settings = get_option( 'woocommerce_bacs_settings', array() );
        $global_multiplier = isset( $bacs_settings['custom_global_multiplier'] ) ? $bacs_settings['custom_global_multiplier'] : 1.0;

        foreach ( $accounts as $key => $account ) {
            $multiplier = isset( $saved_multipliers[ $key ] ) && '' !== $saved_multipliers[ $key ] 
                ? $saved_multipliers[ $key ] 
                : $global_multiplier;

            $calculated_value = $base_price * floatval( $multiplier );
            
            $bank_label = isset( $account['bank_name'] ) ? $account['bank_name'] : '';
            $custom_text = ' (حساب ' . $bank_label . ' - نرخ نهایی: ' . number_format( $calculated_value ) . ')';

            if ( isset( $account['account_name'] ) ) {
                $accounts[ $key ]['account_name'] .= $custom_text;
            }
        }

        return $accounts;
    }

    /**
     * Placeholder method for fetching silver price
     */
    private function get_current_silver_price() {
        return 100000; 
    }
}

VSPP_WooCommerce_BACS_Addon::get_instance();