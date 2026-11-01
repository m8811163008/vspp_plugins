<?php
/**
 * Plugin Name: simple-custom-plugin
 * Description: A lightweight, secure boilerplate plugin following WordPress best practices.
 * Version:     1.0.0
 * Author:      Vira System Paydar Pars and Gemini
 * Author URI:  http://vira-system-paydar-pars.ir/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: simple-custom-plugin
 */

// Exit if accessed directly for security.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main Plugin Class
 */
class Simple_Custom_Plugin {

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
        add_filter( 'the_content', array( $this, 'append_custom_message' ) );
        add_action( 'admin_notices', array( $this, 'plugin_admin_notice' ) );
    }

    public function load_textdomain() {
        load_plugin_textdomain(
            'simple-custom-plugin',
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages'
        );
    }

    public function append_custom_message( $content ) {
        if ( is_single() && in_the_loop() && is_main_query() ) {
            $custom_message = '<p class="scp-notice" style="font-style: italic; color: #666;">' . 
                esc_html__( 'Thanks for reading! Check back soon for more updates.', 'simple-custom-plugin' ) . 
                '</p>';
            $content .= $custom_message;
        }
        return $content;
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
        if ( ! get_option( 'simple_custom_plugin_version' ) ) {
            add_option( 'simple_custom_plugin_version', '1.2.0' );
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
        delete_option( 'simple_custom_plugin_version' );
    }
}

// Register lifecycle hooks
register_activation_hook( __FILE__, array( 'Simple_Custom_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Simple_Custom_Plugin', 'deactivate' ) );
register_uninstall_hook( __FILE__, array( 'Simple_Custom_Plugin', 'uninstall' ) );

/**
 * Initialize the plugin for normal requests.
 */
function run_simple_custom_plugin() {
    return Simple_Custom_Plugin::get_instance();
}

run_simple_custom_plugin();