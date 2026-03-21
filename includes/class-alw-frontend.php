<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ALW_Frontend_Scripts {

    public function __construct() {
        add_filter( 'woocommerce_checkout_fields', array( $this, 'add_hidden_lat_lng_fields' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ), 999 );
    }

    public function add_hidden_lat_lng_fields( $fields ) {
        $fields['billing']['billing_lat'] = array( 'type' => 'hidden', 'class' => array( 'billing-lat-field' ) );
        $fields['billing']['billing_lng'] = array( 'type' => 'hidden', 'class' => array( 'billing-lng-field' ) );
        // Field to sync frontend distance to backend
        $fields['billing']['billing_distance'] = array( 'type' => 'hidden', 'class' => array( 'billing-distance-field' ) );
        return $fields;
    }

    public function enqueue_frontend_assets() {
        if ( ! is_checkout() ) return;

        $plugin_dir_url  = plugin_dir_url( dirname( __FILE__ ) );
        $plugin_dir_path = plugin_dir_path( dirname( __FILE__ ) );

        // --- CSS ---
        wp_enqueue_style( 
            'alw-frontend-style', 
            $plugin_dir_url . 'assets/css/alw-frontend.css', 
            array(), 
            filemtime( $plugin_dir_path . 'assets/css/alw-frontend.css' ) 
        );

        // --- Main Map Script ---
        wp_enqueue_script(
            'alw-checkout-map',
            $plugin_dir_url . 'assets/js/alw-checkout-map.js',
            array( 'jquery' ),
            filemtime( $plugin_dir_path . 'assets/js/alw-checkout-map.js' ),
            true
        );

        wp_localize_script( 'alw-checkout-map', 'alw_checkout_config', array(
            'api_key'         => ALW_GOOGLE_API_KEY,
            'store_lat'       => ALW_STORE_LAT,
            'store_lng'       => ALW_STORE_LNG,
            'free_km'         => ALW_FREE_KM,
            'max_km'          => ALW_MAX_KM,
            'rate_per_km'     => ALW_RATE_PER_KM,
            'round_method'    => ALW_ROUND_METHOD,
            'currency_symbol' => html_entity_decode( get_woocommerce_currency_symbol() ),
            'i18n'            => array(
                'shipping_free'           => __( 'Shipping: FREE', 'auto-location-woocommerce' ),
                'shipping_label'          => __( 'Shipping', 'auto-location-woocommerce' ),
                'delivery_not_available'  => sprintf(
                    /* translators: %s: maximum delivery distance in km */
                    __( 'DELIVERY NOT AVAILABLE (beyond %s km)', 'auto-location-woocommerce' ),
                    ALW_MAX_KM
                ),
                'enter_address'           => __( 'Enter billing address or pick location on the map to calculate shipping.', 'auto-location-woocommerce' ),
                'place_pin'               => __( 'Place the pin at exact delivery location', 'auto-location-woocommerce' ),
                'current_location'        => __( 'Current location', 'auto-location-woocommerce' ),
                'getting_location'        => __( 'Getting location…', 'auto-location-woocommerce' ),
                'geolocation_unsupported' => __( 'Geolocation not supported.', 'auto-location-woocommerce' ),
                'location_failed'         => __( 'Could not get location.', 'auto-location-woocommerce' ),
                'location_set'            => __( 'Location set', 'auto-location-woocommerce' ),
                'use_my_location'         => __( 'Use my location for billing address', 'auto-location-woocommerce' ),
                'maps_not_ready'          => __( 'Maps not ready. Please try again.', 'auto-location-woocommerce' ),
                'cannot_compute'          => __( 'Could not compute distance. Please use the map.', 'auto-location-woocommerce' ),
                'approx_location'         => __( 'Approx location', 'auto-location-woocommerce' ),
                'selected_location'       => __( 'Selected location', 'auto-location-woocommerce' ),
                'store_label'             => _x( 'Store', 'map marker title', 'auto-location-woocommerce' ),
                'delivery_location'       => __( 'Delivery location', 'auto-location-woocommerce' ),
            ),
        ));

        // --- Checkout Blocker Script ---
        wp_enqueue_script(
            'alw-checkout-blocker',
            $plugin_dir_url . 'assets/js/alw-checkout-blocker.js',
            array(),
            filemtime( $plugin_dir_path . 'assets/js/alw-checkout-blocker.js' ),
            true
        );

        wp_localize_script( 'alw-checkout-blocker', 'alw_blocker_config', array(
            'i18n' => array(
                'delivery_block_message' => __( 'Delivery not available to the selected location. Please choose a different address or contact support.', 'auto-location-woocommerce' ),
                'delivery_not_available' => __( 'Delivery not available.', 'auto-location-woocommerce' ),
            ),
        ));
    }
}