<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ALW_Checkout_Manager {

    public function __construct() {
        // 1. Hide shipping address form
        add_filter( 'woocommerce_cart_needs_shipping_address', '__return_false' );

        // 2. Default "ship to different address" to unchecked
        add_filter( 'woocommerce_ship_to_different_address_checked', '__return_false' );

        // 3. Copy billing -> shipping in $_POST
        add_action( 'woocommerce_checkout_process', array( $this, 'copy_billing_to_shipping_post' ) );

        // 4. Save data to order object
        add_action( 'woocommerce_checkout_create_order', array( $this, 'copy_billing_to_shipping_order' ), 20, 2 );

        // 5. CSS to hide checkbox
        add_action( 'wp_enqueue_scripts', array( $this, 'hide_ship_to_different_css' ) );

        // 6. Embed Google Maps link in Order Emails
        add_action( 'woocommerce_email_order_meta', array( $this, 'add_map_link_to_email' ), 10, 4 );

        // 7. Show Google Maps link in WooCommerce Admin Order Screen
        add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'show_map_link_in_admin_order' ) );

        // 8. Show Google Maps link in Frontend "View Order" screen (My Account)
        add_action( 'woocommerce_order_details_after_customer_address', array( $this, 'show_map_link_in_frontend_order' ), 10, 2 );
    }

    
    public function copy_billing_to_shipping_post() {
        $shipping_keys = array(
            'shipping_first_name', 'shipping_last_name', 'shipping_company',
            'shipping_country', 'shipping_address_1', 'shipping_address_2',
            'shipping_city', 'shipping_state', 'shipping_postcode',
        );

        foreach ( $shipping_keys as $ship_key ) {
            $bill_key = str_replace( 'shipping_', 'billing_', $ship_key );
            if ( empty( $_POST[ $ship_key ] ) && ! empty( $_POST[ $bill_key ] ) ) {
                $_POST[ $ship_key ] = sanitize_text_field( wp_unslash( $_POST[ $bill_key ] ) );
            }
        }
    }

    public function copy_billing_to_shipping_order( $order, $data ) {
        if ( ! $order->get_shipping_first_name() ) $order->set_shipping_first_name( $order->get_billing_first_name() );
        if ( ! $order->get_shipping_last_name() )  $order->set_shipping_last_name( $order->get_billing_last_name() );
        if ( ! $order->get_shipping_company() )    $order->set_shipping_company( $order->get_billing_company() );
        if ( ! $order->get_shipping_country() )    $order->set_shipping_country( $order->get_billing_country() );
        if ( ! $order->get_shipping_address_1() )  $order->set_shipping_address_1( $order->get_billing_address_1() );
        if ( ! $order->get_shipping_address_2() )  $order->set_shipping_address_2( $order->get_billing_address_2() );
        if ( ! $order->get_shipping_city() )       $order->set_shipping_city( $order->get_billing_city() );
        if ( ! $order->get_shipping_state() )      $order->set_shipping_state( $order->get_billing_state() );
        if ( ! $order->get_shipping_postcode() )   $order->set_shipping_postcode( $order->get_billing_postcode() );
        
        // Explicitly store the coordinates so we have a permanent record
        if ( isset( $_POST['billing_lat'] ) && ! empty( $_POST['billing_lat'] ) ) {
            $order->update_meta_data( '_shipping_lat', sanitize_text_field( wp_unslash( $_POST['billing_lat'] ) ) );
        }
        if ( isset( $_POST['billing_lng'] ) && ! empty( $_POST['billing_lng'] ) ) {
            $order->update_meta_data( '_shipping_lng', sanitize_text_field( wp_unslash( $_POST['billing_lng'] ) ) );
        }
    }

    public function hide_ship_to_different_css() {
        if ( is_checkout() && ! is_wc_endpoint_url() ) {
            wp_add_inline_style( 'woocommerce-inline', '
                .woocommerce-shipping-fields, .woocommerce-form__label-for-checkbox { display: none !important; }
            ' );
        }
    }

    public function add_map_link_to_email( $order, $sent_to_admin, $plain_text, $email ) {
        // Only trigger for expected emails like New Order or Processing Order
        $lat = $order->get_meta( '_shipping_lat' );
        $lng = $order->get_meta( '_shipping_lng' );

        if ( empty( $lat ) || empty( $lng ) ) {
            return;
        }

        $map_url = esc_url( "https://maps.google.com/?q={$lat},{$lng}" );

        if ( $plain_text ) {
            echo "Delivery Map Pin: {$map_url}\n\n";
        } else {
            echo '<h2>Delivery Location</h2>';
            echo '<p><strong>Map Pin:</strong> <a href="' . $map_url . '" target="_blank">View exact delivery location on Google Maps &rarr;</a></p>';
        }
    }

    public function show_map_link_in_admin_order( $order ) {
        $lat = $order->get_meta( '_shipping_lat' );
        $lng = $order->get_meta( '_shipping_lng' );

        if ( ! empty( $lat ) && ! empty( $lng ) ) {
            $map_url = esc_url( "https://maps.google.com/?q={$lat},{$lng}" );
            echo '<p><strong>Delivery Map Pin:</strong><br>';
            echo '<a href="' . $map_url . '" target="_blank" style="display:inline-block; margin-top:5px; text-decoration:none;"><span class="dashicons dashicons-location-alt" style="margin-top:2px;"></span> View on Google Maps</a></p>';
        }
    }

    public function show_map_link_in_frontend_order( $type, $order ) {
        // Output ONLY under the primary delivery/shipping address block if it exists
        // Or if there's no shipping block, fallback to display under the billing block.
        if ( ! is_a( $order, 'WC_Order' ) ) {
            return;
        }

        // If 'shipping' is shown, don't show it under 'billing' to avoid duplicates
        if ( $type === 'billing' && $order->has_shipping_address() ) {
            return;
        }

        $lat = $order->get_meta( '_shipping_lat' );
        $lng = $order->get_meta( '_shipping_lng' );

        if ( ! empty( $lat ) && ! empty( $lng ) ) {
            $map_url = esc_url( "https://maps.google.com/?q={$lat},{$lng}" );
            echo '<div style="margin-top:20px; padding: 10px; background: rgba(0,0,0,0.03); border-radius: 4px; display: inline-block; width: 100%;">';
            echo '<strong style="display:block; margin-bottom: 5px;">📍 Exact Delivery Pin:</strong>';
            echo '<a href="' . $map_url . '" target="_blank" class="button" style="text-decoration:none; display: inline-flex; align-items: center; gap: 5px;">';
            echo '<span class="dashicons dashicons-location-alt" style="margin-top:2px;"></span> View on Google Maps &rarr;</a>';
            echo '</div>';
        }
    }
}