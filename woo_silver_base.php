<?php
/**
 * Plugin Name: WooCommerce Silver Currency
 * Plugin URI: https://github.com/
 * Description: Based on Qoran Shora 51 or god told kilogram silver for industry.Adds Silver (1 KiloGram) as a custom currency option to WooCommerce. XAG is the official ISO 4217 code for 1 Troy Ounce of Silver
 * Version: 1.0.1
 * Author: Custom Developer
 * Requires at least: 5.8
 * WC requires at least: 6.0
 * Text Domain: woocommerce-silver-currency
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * 1. Register Custom Currency: Silver (KiloGram)
 */
add_filter( 'woocommerce_currencies', 'add_silver_currency' );
function add_silver_currency( $currencies ) {
    $currencies['XAG_KILOGRAM'] = __( 'KiloGram Silver (XAG to Kg AG)', 'woocommerce-silver-currency' );
    return $currencies;
}

/**
 * 2. Assign Symbol to the Custom Currency
 */
add_filter( 'woocommerce_currency_symbol', 'add_silver_currency_symbol', 10, 2 );
function add_silver_currency_symbol( $currency_symbol, $currency ) {
    if ( 'XAG_KILOGRAM' === $currency ) {
        $currency_symbol = 'کیلوگرم نقره';
    }
    return $currency_symbol;
}