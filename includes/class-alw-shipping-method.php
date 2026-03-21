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
        $this->method_title       = __( 'Distance-Based Shipping', 'auto-location-woocommerce' );
        $this->method_description = __( 'Calculates shipping cost based on driving distance from store using Google Maps.', 'auto-location-woocommerce' );
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
                'title'   => __( 'Method Title', 'auto-location-woocommerce' ),
                'type'    => 'text',
                'default' => __( 'Distance Shipping', 'auto-location-woocommerce' ),
                'desc_tip' => true,
                'description' => __( 'The title shown to customers at checkout.', 'auto-location-woocommerce' ),
            ),
            'free_km' => array(
                'title'       => __( 'Free Shipping Distance (km)', 'auto-location-woocommerce' ),
                'type'        => 'number',
                'default'     => get_option( 'alw_free_km', 0 ),
                'desc_tip'    => true,
                'description' => __( 'Orders within this distance get free shipping.', 'auto-location-woocommerce' ),
                'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
            ),
            'max_km' => array(
                'title'       => __( 'Max Delivery Distance (km)', 'auto-location-woocommerce' ),
                'type'        => 'number',
                'default'     => get_option( 'alw_max_km', 50 ),
                'desc_tip'    => true,
                'description' => __( 'Orders beyond this distance will not see this shipping option.', 'auto-location-woocommerce' ),
                'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
            ),
            'rate_per_km' => array(
                'title'       => __( 'Rate Per KM', 'auto-location-woocommerce' ),
                'type'        => 'number',
                'default'     => get_option( 'alw_rate_per_km', 10 ),
                'desc_tip'    => true,
                'description' => __( 'Cost per kilometer beyond the free shipping distance.', 'auto-location-woocommerce' ),
                'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
            ),
            'round_method' => array(
                'title'   => __( 'Rounding Method', 'auto-location-woocommerce' ),
                'type'    => 'select',
                'default' => get_option( 'alw_round_method', 'ceil' ),
                'options' => array(
                    'ceil'  => __( 'Ceil (Round Up)', 'auto-location-woocommerce' ),
                    'floor' => __( 'Floor (Round Down)', 'auto-location-woocommerce' ),
                    'round' => __( 'Standard Round', 'auto-location-woocommerce' ),
                ),
                'desc_tip'    => true,
                'description' => __( 'How to round the distance before calculating cost.', 'auto-location-woocommerce' ),
            ),
            'pricing_mode' => array(
                'title'   => __( 'Pricing Mode', 'auto-location-woocommerce' ),
                'type'    => 'select',
                'default' => 'flat_rate',
                'options' => array(
                    'flat_rate' => __( 'Flat Rate per KM', 'auto-location-woocommerce' ),
                    'tiered'    => __( 'Distance Tiers', 'auto-location-woocommerce' ),
                ),
                'desc_tip'    => true,
                'description' => __( 'Flat Rate uses a single rate per km. Distance Tiers lets you define different rates for different distance brackets.', 'auto-location-woocommerce' ),
            ),
            'distance_tiers' => array(
                'title'   => __( 'Distance Tiers', 'auto-location-woocommerce' ),
                'type'    => 'alw_tiers',
                'default' => '',
            ),
        );
    }

    /**
     * Generate HTML for the custom distance tiers repeater field.
     */
    public function generate_alw_tiers_html( $key, $data ) {
        $field_key = $this->get_field_key( $key );
        $tiers     = $this->get_option( $key );
        if ( ! is_array( $tiers ) ) {
            $tiers = array();
        }
        $tiers_json = wp_json_encode( $tiers );

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php echo esc_html( $data['title'] ); ?></label>
            </th>
            <td class="forminp">
                <div id="alw-tiers-wrap" style="max-width:600px;">
                    <table class="alw-tiers-table widefat" style="margin-bottom:10px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'From (km)', 'auto-location-woocommerce' ); ?></th>
                                <th><?php esc_html_e( 'To (km)', 'auto-location-woocommerce' ); ?></th>
                                <th><?php esc_html_e( 'Rate / km', 'auto-location-woocommerce' ); ?></th>
                                <th><?php esc_html_e( 'Flat Fee', 'auto-location-woocommerce' ); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="alw-tiers-body">
                        </tbody>
                    </table>
                    <button type="button" class="button" id="alw-add-tier"><?php esc_html_e( '+ Add Tier', 'auto-location-woocommerce' ); ?></button>
                </div>
                <textarea name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" style="display:none;"><?php echo esc_textarea( $tiers_json ); ?></textarea>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Validate the distance tiers field.
     */
    public function validate_alw_tiers_field( $key, $value ) {
        $tiers = json_decode( wp_unslash( $value ), true );
        if ( ! is_array( $tiers ) ) {
            return array();
        }

        $sanitized = array();
        foreach ( $tiers as $tier ) {
            $from = isset( $tier['from'] ) ? max( 0, floatval( $tier['from'] ) ) : 0;
            $to   = isset( $tier['to'] )   ? max( 0, floatval( $tier['to'] ) )   : 0;
            $rate = isset( $tier['rate'] ) ? max( 0, floatval( $tier['rate'] ) ) : 0;
            $flat = isset( $tier['flat'] ) ? max( 0, floatval( $tier['flat'] ) ) : 0;

            if ( $to > $from ) {
                $sanitized[] = array(
                    'from' => $from,
                    'to'   => $to,
                    'rate' => $rate,
                    'flat' => $flat,
                );
            }
        }

        // Sort by 'from'
        usort( $sanitized, function( $a, $b ) {
            return $a['from'] <=> $b['from'];
        });

        return $sanitized;
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
        $free_km       = floatval( $this->get_option( 'free_km', ALW_FREE_KM ) );
        $max_km        = floatval( $this->get_option( 'max_km', ALW_MAX_KM ) );
        $rate_per_km   = floatval( $this->get_option( 'rate_per_km', ALW_RATE_PER_KM ) );
        $round_method  = $this->get_option( 'round_method', ALW_ROUND_METHOD );
        $pricing_mode  = $this->get_option( 'pricing_mode', 'flat_rate' );

        // Beyond max distance → don't add rate (method won't appear at checkout)
        if ( $distance_km > $max_km ) {
            return;
        }

        // Calculate cost based on pricing mode
        if ( $pricing_mode === 'tiered' ) {
            $tiers = $this->get_option( 'distance_tiers', array() );
            if ( ! is_array( $tiers ) || empty( $tiers ) ) {
                // Fallback to flat rate if no tiers configured
                $pricing_mode = 'flat_rate';
            }
        }

        if ( $pricing_mode === 'tiered' ) {
            $cost = $this->calculate_tiered_cost( $distance_km, $tiers );
            if ( $cost <= 0 ) {
                $label = sprintf(
                    /* translators: 1: shipping method title, 2: distance in km */
                    __( '%1$s (Free — %.2f km)', 'auto-location-woocommerce' ),
                    $this->title, $distance_km
                );
            } else {
                $label = sprintf(
                    /* translators: 1: shipping method title, 2: distance in km, 3: formatted price */
                    __( '%1$s (%.2f km — %3$s)', 'auto-location-woocommerce' ),
                    $this->title, $distance_km, strip_tags( wc_price( $cost ) )
                );
            }
        } else {
            // Legacy flat-rate calculation
            $bill_km = $distance_km;
            if ( $round_method === 'floor' ) {
                $bill_km = floor( $distance_km );
            } elseif ( $round_method === 'ceil' ) {
                $bill_km = ceil( $distance_km );
            } else {
                $bill_km = round( $distance_km );
            }

            if ( $distance_km <= $free_km ) {
                $cost  = 0;
                $label = sprintf(
                    /* translators: 1: shipping method title, 2: distance in km */
                    __( '%1$s (Free — %.2f km)', 'auto-location-woocommerce' ),
                    $this->title, $distance_km
                );
            } else {
                $cost  = $bill_km * $rate_per_km;
                $label = sprintf(
                    /* translators: 1: shipping method title, 2: distance in km, 3: formatted price */
                    __( '%1$s (%.2f km — %3$s)', 'auto-location-woocommerce' ),
                    $this->title, $distance_km, strip_tags( wc_price( $cost ) )
                );
            }
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

    /**
     * Calculate cost using cumulative distance tiers.
     *
     * Each tier applies its rate only to the distance within that tier's bracket.
     * Example: tiers [{0-5, rate:0}, {5-15, rate:10}, {15-30, rate:15, flat:50}]
     * For 20km: 0 + (10×10) + (5×15 + 50) = 225
     *
     * @param float $distance_km Total distance.
     * @param array $tiers       Array of tier definitions.
     * @return float Total shipping cost.
     */
    private function calculate_tiered_cost( $distance_km, $tiers ) {
        $total     = 0;
        $remaining = $distance_km;

        foreach ( $tiers as $tier ) {
            $from = floatval( $tier['from'] );
            $to   = floatval( $tier['to'] );
            $rate = floatval( $tier['rate'] );
            $flat = floatval( $tier['flat'] );

            if ( $remaining <= 0 ) break;
            if ( $distance_km <= $from ) continue;

            $tier_km = min( $remaining, $to - $from );
            $tier_cost = $tier_km * $rate;
            if ( $tier_km > 0 ) {
                $tier_cost += $flat;
            }
            $total += $tier_cost;
            $remaining -= $tier_km;
        }

        return round( $total, 2 );
    }
}
