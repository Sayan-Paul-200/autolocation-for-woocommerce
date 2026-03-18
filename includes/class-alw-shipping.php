<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ALW_Shipping_Calculator {

    public function __construct() {
        // Override package rates
        add_filter( 'woocommerce_package_rates', array( $this, 'calculate_distance_rate' ), 20, 2 );

        // Block checkout if too far
        add_action( 'woocommerce_checkout_process', array( $this, 'validate_distance_limit' ) );
    }

    public function calculate_distance_rate( $rates, $package ) {
        $cust_lat = isset( $_POST['billing_lat'] ) ? floatval( wp_unslash( $_POST['billing_lat'] ) ) : 0;
        $cust_lng = isset( $_POST['billing_lng'] ) ? floatval( wp_unslash( $_POST['billing_lng'] ) ) : 0;
        // Grab the distance calculated by the Frontend
        $cust_dist = isset( $_POST['billing_distance'] ) ? floatval( wp_unslash( $_POST['billing_distance'] ) ) : 0;

        // Fallback: Geocode if no coords provided
        if ( empty( $cust_lat ) || empty( $cust_lng ) ) {
            $billing_address = $this->get_posted_address( $package );
            if ( ! empty( $billing_address ) ) {
                $coords = $this->geocode_address( $billing_address );
                if ( $coords ) {
                    $cust_lat = $coords['lat'];
                    $cust_lng = $coords['lng'];
                }
            }
        }

        // If still no coords, return existing rates (fallback)
        if ( empty( $cust_lat ) || empty( $cust_lng ) ) {
            return $rates;
        }

        // --- DISTANCE LOGIC START ---
        // Priority 1: Use the Frontend Distance (Driving) if available
        if ( $cust_dist > 0 ) {
            $distance_km = $cust_dist;
        } 
        // Priority 2: Backend Calculation (Fail-safe)
        else {
            $dir = $this->get_directions_distance( ALW_STORE_LAT, ALW_STORE_LNG, $cust_lat, $cust_lng );
            
            if ( $dir && isset( $dir['distance_km'] ) ) {
                $distance_km = (float) $dir['distance_km'];
            } else {
                $distance_km = $this->get_haversine_km( (float) ALW_STORE_LAT, (float) ALW_STORE_LNG, (float) $cust_lat, (float) $cust_lng );
            }
        }
        // --- DISTANCE LOGIC END ---

        // Calculate Cost
        $bill_km = $distance_km;
        if ( ALW_ROUND_METHOD === 'floor' ) $bill_km = floor( $distance_km );
        elseif ( ALW_ROUND_METHOD === 'ceil' ) $bill_km = ceil( $distance_km );
        else $bill_km = round( $distance_km );

        if ( $distance_km <= ALW_FREE_KM ) {
            $cost = 0;
            $label = sprintf( 'Distance Shipping (Free — %.2f km)', $distance_km );
        } elseif ( $distance_km > ALW_MAX_KM ) {
            // Beyond radius -> Return empty rates to block
            return array();
        } else {
            $cost = $bill_km * ALW_RATE_PER_KM;
            $label = sprintf( 'Distance Shipping (%.2f km — %s)', $distance_km, strip_tags( wc_price( $cost ) ) );
        }

        // Return single custom rate
        $rate_id = 'distance_shipping';
        $rate = new WC_Shipping_Rate( $rate_id, $label, $cost, array(), 'distance_shipping_method' );

        return array( $rate_id => $rate );
    }

    public function validate_distance_limit() {
        $cust_lat = isset( $_POST['billing_lat'] ) ? floatval( wp_unslash( $_POST['billing_lat'] ) ) : 0;
        $cust_lng = isset( $_POST['billing_lng'] ) ? floatval( wp_unslash( $_POST['billing_lng'] ) ) : 0;
        $cust_dist = isset( $_POST['billing_distance'] ) ? floatval( wp_unslash( $_POST['billing_distance'] ) ) : 0;

        if ( empty( $cust_lat ) || empty( $cust_lng ) ) return;

        // Use the same logic priority
        if ( $cust_dist > 0 ) {
            $distance_km = $cust_dist;
        } else {
            $dir = $this->get_directions_distance( ALW_STORE_LAT, ALW_STORE_LNG, $cust_lat, $cust_lng );
            $distance_km = ($dir && isset( $dir['distance_km'] )) ? (float) $dir['distance_km'] : $this->get_haversine_km( (float) ALW_STORE_LAT, (float) ALW_STORE_LNG, (float) $cust_lat, (float) $cust_lng );
        }

        if ( $distance_km > ALW_MAX_KM ) {
            wc_add_notice( sprintf( 'We do not deliver to this address — it is %.2f km away which exceeds our delivery radius of %s km.', $distance_km, ALW_MAX_KM ), 'error' );
        }
    }

    // --- Helpers ---

    private function get_posted_address( $package = null ) {
        if ( isset( $package['destination'] ) ) {
            $dest = $package['destination'];
            $parts = array(
                $dest['address'] ?? '', $dest['city'] ?? '', $dest['state'] ?? '', $dest['postcode'] ?? '', $dest['country'] ?? ''
            );
            return trim( implode( ', ', array_filter( $parts ) ) );
        }
        return trim( implode( ', ', array_filter( array(
            $_POST['billing_address_1'] ?? '', $_POST['billing_city'] ?? '', $_POST['billing_state'] ?? '', $_POST['billing_postcode'] ?? '', $_POST['billing_country'] ?? ''
        ) ) ) );
    }

    private function get_haversine_km( $lat1, $lon1, $lat2, $lon2 ) {
        $R = 6371.0;
        $dLat = deg2rad( $lat2 - $lat1 );
        $dLon = deg2rad( $lon2 - $lon1 );
        $a = sin( $dLat / 2 ) * sin( $dLat / 2 ) + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $dLon / 2 ) * sin( $dLon / 2 );
        $c = 2 * asin( min(1, sqrt( $a ) ) );
        return $R * $c;
    }

    private function get_directions_distance( $origin_lat, $origin_lng, $dest_lat, $dest_lng ) {
        $api_key = ALW_GOOGLE_API_KEY;
        if ( empty( $api_key ) ) return false;

        $transient_key = 'ds_dir_' . md5( "{$origin_lat},{$origin_lng}_{$dest_lat},{$dest_lng}" );
        $cached = get_transient( $transient_key );
        if ( $cached ) return $cached;

        $origin = rawurlencode( $origin_lat . ',' . $origin_lng );
        $destination = rawurlencode( $dest_lat . ',' . $dest_lng );
        $url = "https://maps.googleapis.com/maps/api/directions/json?origin={$origin}&destination={$destination}&mode=driving&key=" . rawurlencode( $api_key );

        $resp = wp_remote_get( $url, array( 'timeout' => 15 ) );
        if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) return false;

        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( isset( $data['status'] ) && $data['status'] === 'OK' && ! empty( $data['routes'][0]['legs'][0]['distance']['value'] ) ) {
            $meters = intval( $data['routes'][0]['legs'][0]['distance']['value'] );
            $res = array( 'distance_km' => $meters / 1000.0, 'meters' => $meters );
            set_transient( $transient_key, $res, ALW_CACHE_SECONDS );
            return $res;
        }
        return false;
    }

    private function geocode_address( $address ) {
        $api_key = ALW_GOOGLE_API_KEY;
        if ( empty( $address ) || empty( $api_key ) ) return false;
        
        $transient_key = 'ds_geo_' . md5( $address );
        $cached = get_transient( $transient_key );
        if ( $cached ) return $cached;

        $url = add_query_arg( array( 'address' => rawurlencode( $address ), 'key' => $api_key ), 'https://maps.googleapis.com/maps/api/geocode/json' );
        $resp = wp_remote_get( $url, array( 'timeout' => 10 ) );
        if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) return false;

        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( isset( $data['status'] ) && $data['status'] === 'OK' && ! empty( $data['results'][0]['geometry']['location'] ) ) {
            $loc = $data['results'][0]['geometry']['location'];
            $res = array( 'lat' => (float) $loc['lat'], 'lng' => (float) $loc['lng'] );
            set_transient( $transient_key, $res, ALW_CACHE_SECONDS );
            return $res;
        }
        return false;
    }
}