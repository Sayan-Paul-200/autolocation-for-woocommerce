<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ALW_Admin_Settings {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        // Enqueue Admin CSS
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    public function enqueue_admin_assets( $hook ) {
        // Only load on our specific settings page to avoid conflicts
        if ( $hook !== 'toplevel_page_alw_settings' ) {
            return;
        }
        wp_enqueue_style( 
            'alw-admin-style', 
            plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/alw-admin.css', 
            array(), 
            filemtime( plugin_dir_path( dirname( __FILE__ ) ) . 'assets/css/alw-admin.css' ) 
        );
    }

    public function add_admin_menu() {
        add_menu_page(
            'Auto Location Settings', 'Auto Location', 'manage_options', 'alw_settings', 
            array( $this, 'settings_page_html' ), 'dashicons-location', 56
        );
    }

    public function register_settings() {
        register_setting( 'alw_settings_group', 'alw_google_api_key', array(
            'sanitize_callback' => 'sanitize_text_field',
        ));
        register_setting( 'alw_settings_group', 'alw_store_lat', array(
            'sanitize_callback' => array( $this, 'sanitize_latitude' ),
        ));
        register_setting( 'alw_settings_group', 'alw_store_lng', array(
            'sanitize_callback' => array( $this, 'sanitize_longitude' ),
        ));
        register_setting( 'alw_settings_group', 'alw_free_km', array(
            'sanitize_callback' => array( $this, 'sanitize_non_negative_float' ),
        ));
        register_setting( 'alw_settings_group', 'alw_max_km', array(
            'sanitize_callback' => array( $this, 'sanitize_max_km' ),
        ));
        register_setting( 'alw_settings_group', 'alw_rate_per_km', array(
            'sanitize_callback' => array( $this, 'sanitize_non_negative_float' ),
        ));
        register_setting( 'alw_settings_group', 'alw_round_method', array(
            'sanitize_callback' => array( $this, 'sanitize_round_method' ),
        ));

        add_settings_section( 'alw_general_section', 'General Configuration', null, 'alw_settings' );
        add_settings_section( 'alw_rules_section', 'Shipping Rules', null, 'alw_settings' );

        add_settings_field( 'alw_google_api_key', 'Google Maps API Key', array( $this, 'render_api_key_field' ), 'alw_settings', 'alw_general_section', array( 'id' => 'alw_google_api_key' ) );
        add_settings_field( 'alw_store_lat', 'Store Latitude', array( $this, 'render_text_field' ), 'alw_settings', 'alw_general_section', array( 'id' => 'alw_store_lat' ) );
        add_settings_field( 'alw_store_lng', 'Store Longitude', array( $this, 'render_text_field' ), 'alw_settings', 'alw_general_section', array( 'id' => 'alw_store_lng' ) );

        add_settings_field( 'alw_free_km', 'Free Shipping Distance (km)', array( $this, 'render_number_field' ), 'alw_settings', 'alw_rules_section', array( 'id' => 'alw_free_km' ) );
        add_settings_field( 'alw_max_km', 'Max Delivery Distance (km)', array( $this, 'render_number_field' ), 'alw_settings', 'alw_rules_section', array( 'id' => 'alw_max_km' ) );
        add_settings_field( 'alw_rate_per_km', 'Rate Per KM', array( $this, 'render_number_field' ), 'alw_settings', 'alw_rules_section', array( 'id' => 'alw_rate_per_km' ) );
        add_settings_field( 'alw_round_method', 'Rounding Method', array( $this, 'render_select_field' ), 'alw_settings', 'alw_rules_section', array( 'id' => 'alw_round_method' ) );
    }

    public function render_api_key_field( $args ) {
        $option = get_option( $args['id'] );
        ?>
        <input type="password" name="<?php echo esc_attr( $args['id'] ); ?>" value="<?php echo esc_attr( $option ); ?>" class="regular-text" autocomplete="off" />
        <p class="description">Required: Geocoding API, Maps JavaScript API, Places API, and Directions API.</p>

        <div class="notice notice-warning inline" style="margin: 10px 0; padding: 8px 12px;">
            <p><strong>&#9888;&#65039; Security:</strong> Restrict this API key in the
            <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a>:
            set <em>Application restrictions &rarr; HTTP referrers</em> to your domain,
            and <em>API restrictions</em> to only Geocoding, Maps JS, Places, and Directions APIs.</p>
        </div>
        
        <div class="alw-tutorial-box">
            <h3>How to get your Google Maps API Key</h3>
            <p>1. Go to the Google Maps Platform Console: <br>
                <a href="https://developers.google.com/maps/documentation/embed/get-api-key?setupProd=prerequisites" target="_blank" class="alw-tutorial-link">
                    https://developers.google.com/maps/documentation/embed/get-api-key?setupProd=prerequisites <span class="dashicons dashicons-external"></span>
                </a>
            </p>
            <p>2. Watch this quick tutorial:</p>
            <div class="alw-video-wrapper">
                <iframe class="alw-video-iframe" 
                    src="https://www.youtube.com/embed/9_7s-UuQ2nw" 
                    title="How to generate an API key for Google Maps Platform" 
                    frameborder="0" 
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                    allowfullscreen>
                </iframe>
            </div>
            <p class="description" style="margin-top: 10px;">Ensure you enable billing on the Google Cloud Project, otherwise the API will not work.</p>
        </div>
        <?php
    }

    public function render_text_field( $args ) {
        $option = get_option( $args['id'] );
        echo '<input type="text" name="' . esc_attr( $args['id'] ) . '" value="' . esc_attr( $option ) . '" class="regular-text" />';
        if ( ! empty( $args['desc'] ) ) echo '<p class="description">' . esc_html( $args['desc'] ) . '</p>';
    }

    public function render_number_field( $args ) {
        $option = get_option( $args['id'] );
        echo '<input type="number" step="0.01" name="' . esc_attr( $args['id'] ) . '" value="' . esc_attr( $option ) . '" class="small-text" />';
    }

    public function render_select_field( $args ) {
        $option = get_option( $args['id'] );
        $items = array( 'ceil' => 'Ceil (Round Up)', 'floor' => 'Floor (Round Down)', 'round' => 'Standard Round' );
        echo '<select name="' . esc_attr( $args['id'] ) . '">';
        foreach ( $items as $key => $val ) {
            $selected = ( $option == $key ) ? 'selected="selected"' : '';
            echo '<option value="' . esc_attr( $key ) . '" ' . $selected . '>' . esc_html( $val ) . '</option>';
        }
        echo '</select>';
    }

    public function settings_page_html() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'alw_settings_group' );
                do_settings_sections( 'alw_settings' );
                submit_button( 'Save Settings' );
                ?>
            </form>
        </div>
        <?php
    }

    // --- Sanitization Callbacks ---

    public function sanitize_latitude( $value ) {
        $value = floatval( $value );
        if ( $value < -90 || $value > 90 ) {
            add_settings_error( 'alw_store_lat', 'invalid_lat', 'Latitude must be between -90 and 90.' );
            return get_option( 'alw_store_lat' );
        }
        return $value;
    }

    public function sanitize_longitude( $value ) {
        $value = floatval( $value );
        if ( $value < -180 || $value > 180 ) {
            add_settings_error( 'alw_store_lng', 'invalid_lng', 'Longitude must be between -180 and 180.' );
            return get_option( 'alw_store_lng' );
        }
        return $value;
    }

    public function sanitize_non_negative_float( $value ) {
        $value = floatval( $value );
        return max( 0, $value );
    }

    public function sanitize_max_km( $value ) {
        $value = floatval( $value );
        $free_km = floatval( get_option( 'alw_free_km', 0 ) );
        if ( $value <= 0 ) {
            add_settings_error( 'alw_max_km', 'invalid_max', 'Max delivery distance must be greater than 0.' );
            return get_option( 'alw_max_km' );
        }
        if ( $value < $free_km ) {
            add_settings_error( 'alw_max_km', 'max_lt_free', 'Max distance cannot be less than the free shipping distance.' );
            return get_option( 'alw_max_km' );
        }
        return $value;
    }

    public function sanitize_round_method( $value ) {
        $allowed = array( 'ceil', 'floor', 'round' );
        return in_array( $value, $allowed, true ) ? $value : 'ceil';
    }
}