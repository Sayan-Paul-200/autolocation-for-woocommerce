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
        register_setting( 'alw_settings_group', 'alw_google_api_key' );
        register_setting( 'alw_settings_group', 'alw_store_lat' );
        register_setting( 'alw_settings_group', 'alw_store_lng' );
        register_setting( 'alw_settings_group', 'alw_free_km' );
        register_setting( 'alw_settings_group', 'alw_max_km' );
        register_setting( 'alw_settings_group', 'alw_rate_per_km' );
        register_setting( 'alw_settings_group', 'alw_round_method' );
        register_setting( 'alw_settings_group', 'alw_wa_enabled' );
        register_setting( 'alw_settings_group', 'alw_wa_phone_id' );
        register_setting( 'alw_settings_group', 'alw_wa_token' );
        register_setting( 'alw_settings_group', 'alw_admin_phone' );

        add_settings_section( 'alw_general_section', 'General Configuration', null, 'alw_settings' );
        add_settings_section( 'alw_rules_section', 'Shipping Rules', null, 'alw_settings' );
        add_settings_section( 'alw_whatsapp_section', 'WhatsApp Cloud API Integrations', null, 'alw_settings' );

        add_settings_field( 'alw_google_api_key', 'Google Maps API Key', array( $this, 'render_api_key_field' ), 'alw_settings', 'alw_general_section', array( 'id' => 'alw_google_api_key' ) );
        add_settings_field( 'alw_store_lat', 'Store Latitude', array( $this, 'render_text_field' ), 'alw_settings', 'alw_general_section', array( 'id' => 'alw_store_lat' ) );
        add_settings_field( 'alw_store_lng', 'Store Longitude', array( $this, 'render_text_field' ), 'alw_settings', 'alw_general_section', array( 'id' => 'alw_store_lng' ) );

        add_settings_field( 'alw_free_km', 'Free Shipping Distance (km)', array( $this, 'render_number_field' ), 'alw_settings', 'alw_rules_section', array( 'id' => 'alw_free_km' ) );
        add_settings_field( 'alw_max_km', 'Max Delivery Distance (km)', array( $this, 'render_number_field' ), 'alw_settings', 'alw_rules_section', array( 'id' => 'alw_max_km' ) );
        add_settings_field( 'alw_rate_per_km', 'Rate Per KM', array( $this, 'render_number_field' ), 'alw_settings', 'alw_rules_section', array( 'id' => 'alw_rate_per_km' ) );
        add_settings_field( 'alw_round_method', 'Rounding Method', array( $this, 'render_select_field' ), 'alw_settings', 'alw_rules_section', array( 'id' => 'alw_round_method' ) );

        add_settings_field( 'alw_wa_enabled', 'Enable WhatsApp Notifications', array( $this, 'render_checkbox_field' ), 'alw_settings', 'alw_whatsapp_section', array( 'id' => 'alw_wa_enabled' ) );
        add_settings_field( 'alw_wa_phone_id', 'Phone Number ID', array( $this, 'render_text_field' ), 'alw_settings', 'alw_whatsapp_section', array( 'id' => 'alw_wa_phone_id', 'desc' => 'From your Meta Developer App Dashboard.' ) );
        add_settings_field( 'alw_wa_token', 'Permanent Access Token', array( $this, 'render_text_field' ), 'alw_settings', 'alw_whatsapp_section', array( 'id' => 'alw_wa_token', 'desc' => 'Your system-user access token.' ) );
        add_settings_field( 'alw_admin_phone', 'Admin WhatsApp Number', array( $this, 'render_text_field' ), 'alw_settings', 'alw_whatsapp_section', array( 'id' => 'alw_admin_phone', 'desc' => 'Include country code without +, e.g., 919876543210' ) );
    }

    public function render_api_key_field( $args ) {
        $option = get_option( $args['id'] );
        ?>
        <input type="text" name="<?php echo esc_attr( $args['id'] ); ?>" value="<?php echo esc_attr( $option ); ?>" class="regular-text" />
        <p class="description">Required: Geocoding API, Maps JavaScript API, Places API, and Directions API.</p>
        
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

    public function render_checkbox_field( $args ) {
        $option = get_option( $args['id'] );
        echo '<input type="checkbox" name="' . esc_attr( $args['id'] ) . '" value="1" ' . checked( 1, $option, false ) . ' />';
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
}