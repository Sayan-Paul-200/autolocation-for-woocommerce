<?php
/**
 * Distance Service: Google Maps distance computation helpers.
 *
 * A pure service class with no WooCommerce hooks. Provides distance
 * calculation via Google Directions API with Haversine fallback,
 * address geocoding, and address building from WooCommerce packages.
 *
 * @package Auto_Location_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ALW_Distance_Service {

    /**
     * Compute distance between two points (server-authoritative).
     * Tries Google Directions API first, falls back to Haversine.
     *
     * @param float|string $origin_lat  Origin latitude.
     * @param float|string $origin_lng  Origin longitude.
     * @param float        $dest_lat    Destination latitude.
     * @param float        $dest_lng    Destination longitude.
     * @return float Distance in kilometers.
     */
    public function compute_distance( $origin_lat, $origin_lng, $dest_lat, $dest_lng ) {
        $dir = $this->get_directions_distance( $origin_lat, $origin_lng, $dest_lat, $dest_lng );
        if ( $dir && isset( $dir['distance_km'] ) ) {
            return (float) $dir['distance_km'];
        }
        return $this->get_haversine_km(
            (float) $origin_lat, (float) $origin_lng,
            (float) $dest_lat,   (float) $dest_lng
        );
    }

    /**
     * Build an address string from a WooCommerce shipping package or from $_POST data.
     *
     * @param array|null $package WooCommerce shipping package (optional).
     * @return string Concatenated address string.
     */
    public function build_address_from_package( $package = null ) {
        if ( $package && isset( $package['destination'] ) ) {
            $dest  = $package['destination'];
            $parts = array(
                $dest['address'] ?? '',
                $dest['city'] ?? '',
                $dest['state'] ?? '',
                $dest['postcode'] ?? '',
                $dest['country'] ?? '',
            );
            return trim( implode( ', ', array_filter( $parts ) ) );
        }

        // Fallback: build from $_POST billing fields
        return trim( implode( ', ', array_filter( array(
            $_POST['billing_address_1'] ?? '',
            $_POST['billing_city'] ?? '',
            $_POST['billing_state'] ?? '',
            $_POST['billing_postcode'] ?? '',
            $_POST['billing_country'] ?? '',
        ) ) ) );
    }

    /**
     * Geocode an address string to lat/lng using Google Geocoding API.
     * Results are cached via WordPress transients.
     *
     * @param string $address Address to geocode.
     * @return array|false Array with 'lat' and 'lng' keys, or false on failure.
     */
    public function geocode_address( $address ) {
        $api_key = defined( 'ALW_GOOGLE_API_KEY' ) ? ALW_GOOGLE_API_KEY : '';
        if ( empty( $address ) || empty( $api_key ) ) return false;

        $transient_key = 'ds_geo_' . md5( $address );
        $cached = get_transient( $transient_key );
        if ( $cached ) return $cached;

        $cache_ttl = defined( 'ALW_CACHE_SECONDS' ) ? ALW_CACHE_SECONDS : DAY_IN_SECONDS * 7;

        $url  = add_query_arg(
            array( 'address' => rawurlencode( $address ), 'key' => $api_key ),
            'https://maps.googleapis.com/maps/api/geocode/json'
        );
        $resp = wp_remote_get( $url, array( 'timeout' => 10 ) );

        if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) return false;

        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( isset( $data['status'] ) && $data['status'] === 'OK' && ! empty( $data['results'][0]['geometry']['location'] ) ) {
            $loc = $data['results'][0]['geometry']['location'];
            $res = array( 'lat' => (float) $loc['lat'], 'lng' => (float) $loc['lng'] );
            set_transient( $transient_key, $res, $cache_ttl );
            return $res;
        }

        return false;
    }

    /**
     * Calculate Haversine (straight-line) distance between two points.
     *
     * @param float $lat1 Origin latitude.
     * @param float $lon1 Origin longitude.
     * @param float $lat2 Destination latitude.
     * @param float $lon2 Destination longitude.
     * @return float Distance in kilometers.
     */
    public function get_haversine_km( $lat1, $lon1, $lat2, $lon2 ) {
        $R    = 6371.0;
        $dLat = deg2rad( $lat2 - $lat1 );
        $dLon = deg2rad( $lon2 - $lon1 );
        $a    = sin( $dLat / 2 ) * sin( $dLat / 2 )
              + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) )
              * sin( $dLon / 2 ) * sin( $dLon / 2 );
        $c    = 2 * asin( min( 1, sqrt( $a ) ) );
        return $R * $c;
    }

    /**
     * Get driving distance via Google Directions API.
     * Results are cached via WordPress transients.
     *
     * @param float|string $origin_lat  Origin latitude.
     * @param float|string $origin_lng  Origin longitude.
     * @param float        $dest_lat    Destination latitude.
     * @param float        $dest_lng    Destination longitude.
     * @return array|false Array with 'distance_km' and 'meters' keys, or false on failure.
     */
    private function get_directions_distance( $origin_lat, $origin_lng, $dest_lat, $dest_lng ) {
        $api_key = defined( 'ALW_GOOGLE_API_KEY' ) ? ALW_GOOGLE_API_KEY : '';
        if ( empty( $api_key ) ) return false;

        $transient_key = 'ds_dir_' . md5( "{$origin_lat},{$origin_lng}_{$dest_lat},{$dest_lng}" );
        $cached = get_transient( $transient_key );
        if ( $cached ) return $cached;

        $cache_ttl = defined( 'ALW_CACHE_SECONDS' ) ? ALW_CACHE_SECONDS : DAY_IN_SECONDS * 7;

        $origin      = rawurlencode( $origin_lat . ',' . $origin_lng );
        $destination  = rawurlencode( $dest_lat . ',' . $dest_lng );
        $url = "https://maps.googleapis.com/maps/api/directions/json?origin={$origin}&destination={$destination}&mode=driving&key=" . rawurlencode( $api_key );

        $resp = wp_remote_get( $url, array( 'timeout' => 15 ) );
        if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) return false;

        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( isset( $data['status'] ) && $data['status'] === 'OK' && ! empty( $data['routes'][0]['legs'][0]['distance']['value'] ) ) {
            $meters = intval( $data['routes'][0]['legs'][0]['distance']['value'] );
            $res    = array( 'distance_km' => $meters / 1000.0, 'meters' => $meters );
            set_transient( $transient_key, $res, $cache_ttl );
            return $res;
        }

        return false;
    }
}