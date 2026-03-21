<?php
/**
 * WooCommerce Shipping Method: Distance-Based Shipping.
 *
 * A proper WC_Shipping_Method implementation that integrates with
 * WooCommerce Shipping Zones and supports per-zone instance settings.
 *
 * @package Auto_Location_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ALW_Shipping_Method extends WC_Shipping_Method {

    /**
     * Constructor. Sets up the shipping method ID, title, and supported features.
     *
     * @param int $instance_id Shipping method instance ID.
     */
    public function __construct( $instance_id = 0 ) {
        $this->id                 = 'alw_distance_shipping';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = 'Distance-Based Shipping';
        $this->method_description = 'Calculates shipping cost based on driving distance from store using Google Maps.';
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal',
        );

        $this->init();
    }

    /**
     * Initialize settings, form fields, and save hook.
     */
    private function init() {
        $this->init_form_fields();
        $this->init_settings();

        $this->title   = $this->get_option( 'title', 'Distance Shipping' );
        $this->enabled = $this->get_option( 'enabled', 'yes' );

        // Save settings in admin
        add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
    }

    /**
     * Define instance-level form fields.
     * Defaults are pulled from the global ALW admin settings for backward compatibility.
     */
    public function init_form_fields() {
        $this->instance_form_fields = array(
            'title' => array(
                'title'   => 'Method Title',
                'type'    => 'text',
                'default' => 'Distance Shipping',
                'desc_tip' => true,
                'description' => 'The title shown to customers at checkout.',
            ),
            'free_km' => array(
                'title'       => 'Free Shipping Distance (km)',
                'type'        => 'number',
                'default'     => get_option( 'alw_free_km', 0 ),
                'desc_tip'    => true,
                'description' => 'Orders within this distance get free shipping.',
                'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
            ),
            'max_km' => array(
                'title'       => 'Max Delivery Distance (km)',
                'type'        => 'number',
                'default'     => get_option( 'alw_max_km', 50 ),
                'desc_tip'    => true,
                'description' => 'Orders beyond this distance will not see this shipping option.',
                'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
            ),
            'rate_per_km' => array(
                'title'       => 'Rate Per KM',
                'type'        => 'number',
                'default'     => get_option( 'alw_rate_per_km', 10 ),
                'desc_tip'    => true,
                'description' => 'Cost per kilometer beyond the free shipping distance.',
                'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
            ),
            'round_method' => array(
                'title'   => 'Rounding Method',
                'type'    => 'select',
                'default' => get_option( 'alw_round_method', 'ceil' ),
                'options' => array(
                    'ceil'  => 'Ceil (Round Up)',
                    'floor' => 'Floor (Round Down)',
                    'round' => 'Standard Round',
                ),
                'desc_tip'    => true,
                'description' => 'How to round the distance before calculating cost.',
            ),
        );
    }

    /**
     * Calculate shipping rate for the given package.
     *
     * @param array $package WooCommerce shipping package.
     */
    public function calculate_shipping( $package = array() ) {
        // Get customer coordinates from the hidden checkout fields
        $cust_lat = isset( $_POST['billing_lat'] ) ? floatval( wp_unslash( $_POST['billing_lat'] ) ) : 0;
        $cust_lng = isset( $_POST['billing_lng'] ) ? floatval( wp_unslash( $_POST['billing_lng'] ) ) : 0;

        // Fallback: geocode from package destination address
        if ( empty( $cust_lat ) || empty( $cust_lng ) ) {
            $service = new ALW_Distance_Service();
            $address = $service->build_address_from_package( $package );
            if ( ! empty( $address ) ) {
                $coords = $service->geocode_address( $address );
                if ( $coords ) {
                    $cust_lat = $coords['lat'];
                    $cust_lng = $coords['lng'];
                }
            }
        }

        // No coordinates available — can't calculate, don't add a rate
        if ( empty( $cust_lat ) || empty( $cust_lng ) ) {
            return;
        }

        // Server-authoritative distance calculation
        $service     = new ALW_Distance_Service();
        $distance_km = $service->compute_distance( ALW_STORE_LAT, ALW_STORE_LNG, $cust_lat, $cust_lng );

        // Instance settings (per-zone overrides, fall back to global defaults)
        $free_km      = floatval( $this->get_option( 'free_km', ALW_FREE_KM ) );
        $max_km       = floatval( $this->get_option( 'max_km', ALW_MAX_KM ) );
        $rate_per_km  = floatval( $this->get_option( 'rate_per_km', ALW_RATE_PER_KM ) );
        $round_method = $this->get_option( 'round_method', ALW_ROUND_METHOD );

        // Beyond max distance → don't add rate (method won't appear at checkout)
        if ( $distance_km > $max_km ) {
            return;
        }

        // Round distance
        $bill_km = $distance_km;
        if ( $round_method === 'floor' ) {
            $bill_km = floor( $distance_km );
        } elseif ( $round_method === 'ceil' ) {
            $bill_km = ceil( $distance_km );
        } else {
            $bill_km = round( $distance_km );
        }

        // Calculate cost
        if ( $distance_km <= $free_km ) {
            $cost  = 0;
            $label = sprintf( '%s (Free — %.2f km)', $this->title, $distance_km );
        } else {
            $cost  = $bill_km * $rate_per_km;
            $label = sprintf( '%s (%.2f km — %s)', $this->title, $distance_km, strip_tags( wc_price( $cost ) ) );
        }

        // Add rate to WooCommerce (coexists with other shipping methods)
        $this->add_rate( array(
            'id'      => $this->get_rate_id(),
            'label'   => $label,
            'cost'    => $cost,
            'package' => $package,
        ));
    }

    /**
     * Check if this shipping method is available.
     *
     * @param array $package WooCommerce shipping package.
     * @return bool
     */
    public function is_available( $package ) {
        return parent::is_available( $package )
            && defined( 'ALW_GOOGLE_API_KEY' )
            && ! empty( ALW_GOOGLE_API_KEY );
    }
}
