<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ALW_WhatsApp_Notifier {

    public function __construct() {
        // Schedule event when order is successfully processed
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'schedule_whatsapp_notifications' ), 10, 3 );
        
        // Background worker hook (prevents blocking checkout UX)
        add_action( 'alw_send_async_whatsapp', array( $this, 'send_whatsapp_notifications' ), 10, 1 );
    }

    public function schedule_whatsapp_notifications( $order_id, $posted_data, $order ) {
        // Schedule an event to happen immediately in a separate WP-Cron/Worker process
        wp_schedule_single_event( time(), 'alw_send_async_whatsapp', array( $order_id ) );
    }

    public function send_whatsapp_notifications( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $customer_phone = $order->get_billing_phone();
        $admin_phone    = ALW_ADMIN_PHONE;
        $order_total    = wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) );
        
        // Build items string
        $items_str = "";
        foreach ( $order->get_items() as $item_id => $item ) {
            $items_str .= "- " . $item->get_name() . " (x" . $item->get_quantity() . ")\n";
        }

        // Get shipping cost
        $shipping_total = wp_strip_all_tags( wc_price( $order->get_shipping_total(), array( 'currency' => $order->get_currency() ) ) );
        
        // Get Google Maps link
        $lat = $order->get_meta( '_shipping_lat' );
        $lng = $order->get_meta( '_shipping_lng' );
        $map_link = ( ! empty( $lat ) && ! empty( $lng ) ) ? "https://maps.google.com/?q={$lat},{$lng}" : "Location Not Provided";

        // Build Markdown Payload
        $message = "🛒 *New Auto-Location Order #{$order_id}*\n\n";
        $message .= "*Order Items:*\n{$items_str}\n";
        $message .= "*Distance Shipping Total:* " . $shipping_total . "\n";
        $message .= "*Grand Total:* {$order_total}\n\n";
        $message .= "*📍 Exact Delivery Pin:*\n{$map_link}";

        // Send to Admin
        if ( ! empty( $admin_phone ) ) {
            $this->send_meta_cloud_message( $admin_phone, $message );
        }

        // Send to Customer
        if ( ! empty( $customer_phone ) ) {
            $this->send_meta_cloud_message( $customer_phone, $message );
        }
    }

    private function send_meta_cloud_message( $phone, $text ) {
        $phone_id = ALW_WA_PHONE_ID;
        $token = ALW_WA_TOKEN;

        if ( empty( $phone_id ) || empty( $token ) ) return false;

        // Clean phone number strictly for WhatsApp API standard (only digits)
        $phone = preg_replace( '/[^0-9]/', '', $phone );

        $url = "https://graph.facebook.com/v18.0/{$phone_id}/messages";

        $body = array(
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $phone,
            'type'              => 'text',
            'text'              => array(
                'preview_url' => true,
                'body'        => $text
            )
        );

        $args = array(
            'body'        => wp_json_encode( $body ),
            'timeout'     => 15,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking'    => true, // Worker is detached, so blocking here is safe/fine
            'headers'     => array(
                'Authorization' => "Bearer {$token}",
                'Content-Type'  => 'application/json',
            ),
        );

        $response = wp_remote_post( $url, $args );
        
        if ( is_wp_error( $response ) ) {
            error_log( 'WhatsApp API Connection Error: ' . $response->get_error_message() );
        } else {
            $response_code = wp_remote_retrieve_response_code( $response );
            if ( $response_code >= 400 ) {
                error_log( 'WhatsApp API Rejected Payload: ' . wp_remote_retrieve_body( $response ) );
            }
        }
    }
}
