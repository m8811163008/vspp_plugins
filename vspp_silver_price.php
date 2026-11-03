<?php
/**
 * Plugin Name: VSPP Silver Price Calculator
 * Plugin URI: https://vira-system-paydar-pars.ir
 * Description: Fetches real-time silver prices from GoldAPI.io and calculates the price per kilogram in USD for Vira System Paydar Pars.
 * Version: 1.1.0
 * Author: Vira System Paydar Pars
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VSPP_Silver_Price_Plugin {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_endpoint' ) );
        add_shortcode( 'vspp_silver_price_kg', array( $this, 'render_shortcode' ) );
    }

    /**
     * Add options submenu under Settings
     */
    public function add_settings_page() {
        add_options_page(
            'VSPP Silver Price Settings',
            'VSPP Silver Price',
            'manage_options',
            'vspp-silver-price',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Register settings and sanitization
     */
    public function register_settings() {
        register_setting( 'vspp_silver_options_group', 'vspp_goldapi_key', array(
            'sanitize_callback' => 'sanitize_text_field',
        ) );
    }

    /**
     * Register REST API GET endpoint
     */
    public function register_rest_endpoint() {
        //'myplugin/v1', '/submit-data/'
        register_rest_route( 'vspp/v1', '/silver-price/', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_rest_silver_price' ),
            'permission_callback' => '__return_true', // Public endpoint (cached internally to prevent API abuse)
        ) );
    }

    /**
     * REST API Callback handler
     */
    public function get_rest_silver_price( $request ) {
        $data = $this->fetch_silver_price();

        if ( is_wp_error( $data ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => $data->get_error_message(),
            ), 500 );
        }

        return new WP_REST_Response( array(
            'success' => true,
            'data'    => $data,
        ), 200 );
    }

    /**
     * Render admin settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>VSPP Silver Price Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'vspp_silver_options_group' );
                do_settings_sections( 'vspp_silver_options_group' );
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="vspp_goldapi_key">GoldAPI.io API Key</label></th>
                        <td>
                            <input type="text" id="vspp_goldapi_key" name="vspp_goldapi_key" value="<?php echo esc_attr( get_option( 'vspp_goldapi_key' ) ); ?>" class="regular-text" />
                            <p class="description">Enter your GoldAPI.io API key to fetch real-time silver spot prices.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <hr>
            <h2>Integration & Usage</h2>
            <h3>1. Shortcode Usage</h3>
            <p>Paste the following shortcode into any page, post, or widget area:</p>
            <code>[vspp_silver_price_kg label="Live Silver Price (KG)"]</code>

            <h3>2. REST API Endpoint</h3>
            <p>Other plugins or themes can fetch the price programmatically using WordPress REST API:</p>
            <code><?php echo esc_url( rest_url( 'vspp/v1/silver-price' ) ); ?></code>
        </div>
        <?php
    }

    /**
     * Fetch silver price from GoldAPI.io with transient caching
     */
    private function fetch_silver_price() {
        $cache_key = 'vspp_silver_price_kg_cache';
        $cached_data = get_transient( $cache_key );

        if ( false !== $cached_data ) {
            return $cached_data;
        }

        $api_key = get_option( 'vspp_goldapi_key' );
        if ( empty( $api_key ) ) {
            return new WP_Error( 'missing_api_key', 'GoldAPI key is not configured in plugin settings.' );
        }

        // Fetching XAG (Silver) in USD
        $response = wp_remote_get(
            'https://www.goldapi.io/api/price/XAG/USD',
            array(
                'timeout' => 15,
                'headers' => array(
                    'x-access-token' => $api_key,
                    'Content-Type'   => 'application/json',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code !== 200 || ! is_array( $data ) || ! isset( $data['price'] ) ) {
            return new WP_Error( 'api_error', 'Failed to retrieve valid price data from GoldAPI.' );
        }

        // GoldAPI returns price per troy ounce (ozt).
        // 1 troy ounce = 31.1034768 grams.
        // 1 kilogram = 1000 grams -> 1 kg = 1000 / 31.1034768 troy ounces (~32.1507466 ozt/kg).
        $price_per_ozt = floatval( $data['price'] );
        $troy_ounces_per_kg = 32.1507466;
        $price_per_kg = $price_per_ozt * $troy_ounces_per_kg;

        $result = array(
            'price_per_ozt' => $price_per_ozt,
            'price_per_kg'  => $price_per_kg,
            'currency'      => 'USD',
            'updated_at'    => current_time( 'mysql', true ), // UTC MySQL timestamp (YYYY-MM-DD HH:MM:SS)
        );

        // Cache for 10 minutes (600 seconds) to reduce external API requests
        set_transient( $cache_key, $result, 8 * HOUR_IN_SECONDS );

        return $result;
    }

    /**
     * Shortcode handler
     */
    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'label' => 'Silver Price per KG',
        ), $atts, 'vspp_silver_price_kg' );

        $data = $this->fetch_silver_price();

        if ( is_wp_error( $data ) ) {
            return '<span class="vspp-price-error">Price temporarily unavailable.</span>';
        }

        $label           = esc_html( $atts['label'] );
        $price_formatted = number_format( $data['price_per_kg'], 2 );
        $currency        = esc_html( $data['currency'] );

        return sprintf(
            '<div class="vspp-silver-price-box"><span class="vspp-label">%s:</span> <span class="vspp-price">%s %s</span></div>',
            $label,
            $price_formatted,
            $currency
        );
    }
}

VSPP_Silver_Price_Plugin::get_instance();