<?php
/**
 * Plugin Name: Auto-Location for WooCommerce
 * Description: Calculates shipping based on distance with a dedicated settings panel.
 * Version: 1.2.0
 * Author: Auto Computation
 * Author URI: https://autocomputation.com/
 * Text Domain: auto-location-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =================================================================
// 1. LOAD SETTINGS (NO DEFAULTS)
// =================================================================

// We retrieve options without default values. If empty, they return false/empty string.
$alw_api_key      = get_option( 'alw_google_api_key' );
$alw_lat          = get_option( 'alw_store_lat' );
$alw_lng          = get_option( 'alw_store_lng' );
$alw_free_km      = get_option( 'alw_free_km' );
$alw_max_km       = get_option( 'alw_max_km' );
$alw_rate_per_km  = get_option( 'alw_rate_per_km' );
$alw_round_method = get_option( 'alw_round_method', 'ceil' ); // Rounding can have a default as it's a dropdown

// =================================================================
// 2. CHECK CONFIGURATION COMPLETENESS
// =================================================================

// We explicitly check if any required field is empty.
// We use strict checks for numbers so '0' is allowed if you want 0 cost, but empty string is not.
$alw_is_configured = (
    ! empty( $alw_api_key ) &&
    ! empty( $alw_lat ) &&
    ! empty( $alw_lng ) &&
    $alw_free_km !== '' && $alw_free_km !== false &&
    $alw_max_km !== '' && $alw_max_km !== false &&
    $alw_rate_per_km !== '' && $alw_rate_per_km !== false
);

// =================================================================
// 3. LOAD CORE & ADMIN (ALWAYS LOADED)
// =================================================================

require_once plugin_dir_path( __FILE__ ) . 'includes/class-alw-admin.php';

add_action( 'plugins_loaded', function() use ( $alw_is_configured ) {
    
    // Always load the Admin Settings so the user can enter data
    if ( is_admin() ) {
        new ALW_Admin_Settings();

        // Optional: Show a notice if fields are missing so Admin knows why it's not working
        if ( ! $alw_is_configured ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error"><p><strong>Auto-Location Plugin Paused:</strong> Please fill in all fields in the <a href="' . admin_url('admin.php?page=alw_settings') . '">Auto Location Settings</a> to enable checkout functionality.</p></div>';
            });
        }
    }
});

// =================================================================
// 4. LOAD FUNCTIONALITY (ONLY IF CONFIGURED)
// =================================================================

if ( $alw_is_configured ) {

    // Define Constants only if configured (to avoid undefined constant errors if we tried to use them elsewhere)
    define( 'ALW_GOOGLE_API_KEY', $alw_api_key );
    define( 'ALW_STORE_LAT',      $alw_lat );
    define( 'ALW_STORE_LNG',      $alw_lng );
    define( 'ALW_FREE_KM',        floatval( $alw_free_km ) );
    define( 'ALW_MAX_KM',         floatval( $alw_max_km ) );
    define( 'ALW_RATE_PER_KM',    floatval( $alw_rate_per_km ) );
    define( 'ALW_ROUND_METHOD',   $alw_round_method );
    define( 'ALW_CACHE_SECONDS',  DAY_IN_SECONDS * 7 );

    // Include logic files
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-alw-checkout.php';
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-alw-frontend.php';
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-alw-shipping.php';

    // Instantiate Logic Classes
    add_action( 'plugins_loaded', function() {
        new ALW_Checkout_Manager();
        new ALW_Frontend_Scripts();
        new ALW_Shipping_Calculator();
    });
}