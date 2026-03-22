<?php
/**
 * Uninstall handler for Auto-Location for WooCommerce.
 *
 * Runs when the plugin is deleted (not just deactivated).
 * Removes all plugin options and transient caches from the database.
 *
 * @package Auto_Location_WooCommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$alw_options = array(
    'alw_google_api_key',
    'alw_store_lat',
    'alw_store_lng',
);

if ( is_multisite() ) {
    $sites = get_sites( array( 'fields' => 'ids' ) );
    foreach ( $sites as $site_id ) {
        switch_to_blog( $site_id );
        alw_cleanup_data( $alw_options );
        restore_current_blog();
    }
} else {
    alw_cleanup_data( $alw_options );
}

/**
 * Delete all plugin options and transient caches.
 *
 * @param array $options List of option names to delete.
 */
function alw_cleanup_data( $options ) {
    foreach ( $options as $opt ) {
        delete_option( $opt );
    }

    // Clean up distance and geocoding transient caches
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_ds_dir_%'
            OR option_name LIKE '_transient_timeout_ds_dir_%'
            OR option_name LIKE '_transient_ds_geo_%'
            OR option_name LIKE '_transient_timeout_ds_geo_%'"
    );
}
