<?php
/**
 * Plugin Name: Auto-Location for WooCommerce
 * Description: Calculates shipping based on distance with a dedicated settings panel.
 * Version: 1.4.0
 * Author: Auto Computation
 * Author URI: https://autocomputation.com/
 * Text Domain: auto-location-woocommerce
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =================================================================
// 1. HPOS COMPATIBILITY DECLARATION
// =================================================================

add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
});

// =================================================================
// 2. LOAD ADMIN (ALWAYS — so settings are accessible)
// =================================================================

require_once plugin_dir_path( __FILE__ ) . 'includes/class-alw-admin.php';

// =================================================================
// 3. MAIN INITIALIZATION (after plugins_loaded)
// =================================================================

add_action( 'plugins_loaded', function() {

    // --- WooCommerce Dependency Check ---
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>Auto-Location for WooCommerce</strong> requires WooCommerce to be installed and active.</p></div>';
        });
        return;
    }

    // --- Load Admin Settings UI ---
    if ( is_admin() ) {
        new ALW_Admin_Settings();
    }

    // --- Load Settings ---
    $alw_api_key      = get_option( 'alw_google_api_key' );
    $alw_lat          = get_option( 'alw_store_lat' );
    $alw_lng          = get_option( 'alw_store_lng' );
    $alw_free_km      = get_option( 'alw_free_km' );
    $alw_max_km       = get_option( 'alw_max_km' );
    $alw_rate_per_km  = get_option( 'alw_rate_per_km' );
    $alw_round_method = get_option( 'alw_round_method', 'ceil' );

    // --- Check Configuration Completeness ---
    $alw_is_configured = (
        ! empty( $alw_api_key ) &&
        ! empty( $alw_lat ) &&
        ! empty( $alw_lng ) &&
        $alw_free_km !== '' && $alw_free_km !== false &&
        $alw_max_km !== '' && $alw_max_km !== false &&
        $alw_rate_per_km !== '' && $alw_rate_per_km !== false
    );

    if ( ! $alw_is_configured ) {
        if ( is_admin() ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error"><p><strong>Auto-Location Plugin Paused:</strong> Please fill in all fields in the <a href="' . admin_url( 'admin.php?page=alw_settings' ) . '">Auto Location Settings</a> to enable checkout functionality.</p></div>';
            });
        }
        return;
    }

    // --- Define Constants ---
    define( 'ALW_GOOGLE_API_KEY', $alw_api_key );
    define( 'ALW_STORE_LAT',      $alw_lat );
    define( 'ALW_STORE_LNG',      $alw_lng );
    define( 'ALW_FREE_KM',        floatval( $alw_free_km ) );
    define( 'ALW_MAX_KM',         floatval( $alw_max_km ) );
    define( 'ALW_RATE_PER_KM',    floatval( $alw_rate_per_km ) );
    define( 'ALW_ROUND_METHOD',   $alw_round_method );
    define( 'ALW_CACHE_SECONDS',  DAY_IN_SECONDS * 7 );

    // --- Include Logic Files ---
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-alw-checkout.php';
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-alw-frontend.php';
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-alw-shipping.php';
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-alw-shipping-method.php';

    // --- Register WooCommerce Shipping Method ---
    add_filter( 'woocommerce_shipping_methods', function( $methods ) {
        $methods['alw_distance_shipping'] = 'ALW_Shipping_Method';
        return $methods;
    });

    // --- Instantiate Logic Classes ---
    new ALW_Checkout_Manager();
    new ALW_Frontend_Scripts();

    // --- Checkout Validation: Block orders beyond max distance ---
    add_action( 'woocommerce_checkout_process', function() {
        $cust_lat = isset( $_POST['billing_lat'] ) ? floatval( wp_unslash( $_POST['billing_lat'] ) ) : 0;
        $cust_lng = isset( $_POST['billing_lng'] ) ? floatval( wp_unslash( $_POST['billing_lng'] ) ) : 0;

        // Fallback: geocode from billing address
        if ( empty( $cust_lat ) || empty( $cust_lng ) ) {
            $service = new ALW_Distance_Service();
            $address = $service->build_address_from_package();
            if ( ! empty( $address ) ) {
                $coords = $service->geocode_address( $address );
                if ( $coords ) {
                    $cust_lat = $coords['lat'];
                    $cust_lng = $coords['lng'];
                }
            }
        }

        if ( empty( $cust_lat ) || empty( $cust_lng ) ) {
            wc_add_notice( 'We could not pinpoint your delivery location. Please try placing the pin on the map or providing a more detailed address.', 'error' );
            return;
        }

        $service     = new ALW_Distance_Service();
        $distance_km = $service->compute_distance( ALW_STORE_LAT, ALW_STORE_LNG, $cust_lat, $cust_lng );

        if ( $distance_km > ALW_MAX_KM ) {
            wc_add_notice(
                sprintf( 'We do not deliver to this address — it is %.2f km away which exceeds our delivery radius of %s km.', $distance_km, ALW_MAX_KM ),
                'error'
            );
        }
    });

}, 5 );