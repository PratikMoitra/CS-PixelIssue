<?php
/**
 * CS Meta Sync — Settings / Admin Page.
 *
 * Registers a WooCommerce sub-menu page and handles option storage via the WordPress Settings API.
 *
 * @package CS_Meta_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CS_Meta_Settings {

    /** Option key used in wp_options */
    const OPTION_KEY = 'cs_meta_sync_settings';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    /**
     * Add sub-menu under WooCommerce.
     */
    public function add_menu_page() {
        add_submenu_page(
            'woocommerce',
            __( 'CS Meta Sync', 'cs-meta-sync' ),
            __( 'CS Meta Sync', 'cs-meta-sync' ),
            'manage_woocommerce',
            'cs-meta-sync',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Register settings, sections, and fields.
     */
    public function register_settings() {
        register_setting( 'cs_meta_sync_group', self::OPTION_KEY, array(
            'sanitize_callback' => array( $this, 'sanitize_settings' ),
        ) );

        // --- Credentials section ---
        add_settings_section(
            'cs_meta_credentials',
            __( 'Meta API Credentials', 'cs-meta-sync' ),
            function () {
                echo '<p>' . esc_html__( 'Enter your Meta Business credentials below. You can find these in Meta Business Manager → Events Manager and Commerce Manager.', 'cs-meta-sync' ) . '</p>';
            },
            'cs-meta-sync'
        );

        $this->add_text_field( 'pixel_id',          __( 'Meta Pixel ID', 'cs-meta-sync' ),            'cs_meta_credentials' );
        $this->add_text_field( 'capi_access_token',  __( 'Conversions API Access Token', 'cs-meta-sync' ), 'cs_meta_credentials', 'password' );
        $this->add_text_field( 'catalog_id',         __( 'Catalog ID', 'cs-meta-sync' ),                'cs_meta_credentials' );
        $this->add_text_field( 'graph_api_token',    __( 'Graph API Access Token', 'cs-meta-sync' ),    'cs_meta_credentials', 'password' );

        // --- Feature toggles section ---
        add_settings_section(
            'cs_meta_features',
            __( 'Feature Toggles', 'cs-meta-sync' ),
            function () {
                echo '<p>' . esc_html__( 'Enable or disable individual features below.', 'cs-meta-sync' ) . '</p>';
            },
            'cs-meta-sync'
        );

        $this->add_checkbox_field( 'enable_pixel',   __( 'Enable Meta Pixel', 'cs-meta-sync' ),    'cs_meta_features' );
        $this->add_checkbox_field( 'enable_capi',    __( 'Enable Conversions API', 'cs-meta-sync' ), 'cs_meta_features' );
        $this->add_checkbox_field( 'enable_catalog', __( 'Enable Catalog Sync', 'cs-meta-sync' ),  'cs_meta_features' );
        $this->add_checkbox_field( 'test_mode',      __( 'Test Mode (events sent to Test Events in Events Manager)', 'cs-meta-sync' ), 'cs_meta_features' );

        // --- Sync schedule section ---
        add_settings_section(
            'cs_meta_schedule',
            __( 'Catalog Sync Schedule', 'cs-meta-sync' ),
            function () {
                echo '<p>' . esc_html__( 'Products and category sets are synced to Meta at the times specified below.', 'cs-meta-sync' ) . '</p>';
            },
            'cs-meta-sync'
        );

        add_settings_field(
            'sync_interval',
            __( 'Sync Interval', 'cs-meta-sync' ),
            array( $this, 'render_select_field' ),
            'cs-meta-sync',
            'cs_meta_schedule',
            array(
                'key'     => 'sync_interval',
                'options' => array(
                    'hourly'     => __( 'Hourly', 'cs-meta-sync' ),
                    'twicedaily' => __( 'Twice Daily', 'cs-meta-sync' ),
                    'daily'      => __( 'Daily', 'cs-meta-sync' ),
                ),
            )
        );

        add_settings_field(
            'sync_time_1',
            __( 'First Sync Time', 'cs-meta-sync' ),
            array( $this, 'render_time_field' ),
            'cs-meta-sync',
            'cs_meta_schedule',
            array(
                'key'         => 'sync_time_1',
                'description' => __( 'First daily sync time (your server timezone). Applied when interval is Twice Daily or Daily.', 'cs-meta-sync' ),
            )
        );

        add_settings_field(
            'sync_time_2',
            __( 'Second Sync Time', 'cs-meta-sync' ),
            array( $this, 'render_time_field' ),
            'cs-meta-sync',
            'cs_meta_schedule',
            array(
                'key'         => 'sync_time_2',
                'description' => __( 'Second sync time (only used with Twice Daily interval).', 'cs-meta-sync' ),
            )
        );

        // --- Test Event Code field ---
        add_settings_field(
            'test_event_code',
            __( 'Test Event Code', 'cs-meta-sync' ),
            array( $this, 'render_text_field' ),
            'cs-meta-sync',
            'cs_meta_schedule',
            array(
                'key'         => 'test_event_code',
                'type'        => 'text',
                'description' => __( 'Enter test event code from Events Manager → Test Events (only used when Test Mode is enabled).', 'cs-meta-sync' ),
            )
        );

        // --- Notifications section ---
        add_settings_section(
            'cs_meta_notifications',
            __( 'Sync Notifications', 'cs-meta-sync' ),
            function () {
                echo '<p>' . esc_html__( 'Get notified via webhook and/or Telegram when a sync completes. Leave fields empty to disable.', 'cs-meta-sync' ) . '</p>';
            },
            'cs-meta-sync'
        );

        add_settings_field(
            'webhook_url',
            __( 'Webhook URL', 'cs-meta-sync' ),
            array( $this, 'render_text_field' ),
            'cs-meta-sync',
            'cs_meta_notifications',
            array(
                'key'         => 'webhook_url',
                'type'        => 'url',
                'description' => __( 'POST request will be sent to this URL after each sync with full sync results as JSON.', 'cs-meta-sync' ),
            )
        );

        add_settings_field(
            'telegram_bot_token',
            __( 'Telegram Bot Token', 'cs-meta-sync' ),
            array( $this, 'render_text_field' ),
            'cs-meta-sync',
            'cs_meta_notifications',
            array(
                'key'         => 'telegram_bot_token',
                'type'        => 'password',
                'description' => __( 'Bot token from @BotFather (e.g., 123456789:ABCdefGHIjklMNOpqrSTUvwxYZ).', 'cs-meta-sync' ),
            )
        );

        add_settings_field(
            'telegram_chat_id',
            __( 'Telegram Chat ID', 'cs-meta-sync' ),
            array( $this, 'render_text_field' ),
            'cs-meta-sync',
            'cs_meta_notifications',
            array(
                'key'         => 'telegram_chat_id',
                'type'        => 'text',
                'description' => __( 'Channel or group chat ID (e.g., -1001234567890). Use @userinfobot to find yours.', 'cs-meta-sync' ),
            )
        );
    }

    /**
     * Add a text/password field (helper).
     */
    private function add_text_field( $key, $label, $section, $type = 'text' ) {
        add_settings_field(
            $key,
            $label,
            array( $this, 'render_text_field' ),
            'cs-meta-sync',
            $section,
            array( 'key' => $key, 'type' => $type )
        );
    }

    /**
     * Add a checkbox field (helper).
     */
    private function add_checkbox_field( $key, $label, $section ) {
        add_settings_field(
            $key,
            $label,
            array( $this, 'render_checkbox_field' ),
            'cs-meta-sync',
            $section,
            array( 'key' => $key )
        );
    }

    /**
     * Render a text / password field.
     */
    public function render_text_field( $args ) {
        $options = get_option( self::OPTION_KEY, array() );
        $value   = isset( $options[ $args['key'] ] ) ? $options[ $args['key'] ] : '';
        $type    = isset( $args['type'] ) ? $args['type'] : 'text';
        printf(
            '<input type="%s" name="%s[%s]" value="%s" class="regular-text" />',
            esc_attr( $type ),
            esc_attr( self::OPTION_KEY ),
            esc_attr( $args['key'] ),
            esc_attr( $value )
        );
        if ( ! empty( $args['description'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
        }
    }

    /**
     * Render a checkbox field.
     */
    public function render_checkbox_field( $args ) {
        $options = get_option( self::OPTION_KEY, array() );
        $checked = isset( $options[ $args['key'] ] ) && '1' === $options[ $args['key'] ];
        printf(
            '<input type="checkbox" name="%s[%s]" value="1" %s />',
            esc_attr( self::OPTION_KEY ),
            esc_attr( $args['key'] ),
            checked( $checked, true, false )
        );
    }

    /**
     * Render a select (dropdown) field.
     */
    public function render_select_field( $args ) {
        $options   = get_option( self::OPTION_KEY, array() );
        $current   = isset( $options[ $args['key'] ] ) ? $options[ $args['key'] ] : '';
        echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[' . esc_attr( $args['key'] ) . ']">';
        foreach ( $args['options'] as $val => $label ) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $val ),
                selected( $current, $val, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
    }

    /**
     * Render a time input field.
     */
    public function render_time_field( $args ) {
        $options = get_option( self::OPTION_KEY, array() );
        $value   = isset( $options[ $args['key'] ] ) ? $options[ $args['key'] ] : '';
        printf(
            '<input type="time" name="%s[%s]" value="%s" class="small-text" style="width:120px" />',
            esc_attr( self::OPTION_KEY ),
            esc_attr( $args['key'] ),
            esc_attr( $value )
        );
        if ( ! empty( $args['description'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
        }
    }

    /**
     * Sanitize settings on save.
     */
    public function sanitize_settings( $input ) {
        $sanitized = array();
        $sanitized['pixel_id']          = sanitize_text_field( $input['pixel_id'] ?? '' );
        $sanitized['capi_access_token'] = sanitize_text_field( $input['capi_access_token'] ?? '' );
        $sanitized['catalog_id']        = sanitize_text_field( $input['catalog_id'] ?? '' );
        $sanitized['graph_api_token']   = sanitize_text_field( $input['graph_api_token'] ?? '' );
        $sanitized['enable_pixel']      = isset( $input['enable_pixel'] ) ? '1' : '0';
        $sanitized['enable_capi']       = isset( $input['enable_capi'] ) ? '1' : '0';
        $sanitized['enable_catalog']    = isset( $input['enable_catalog'] ) ? '1' : '0';
        $sanitized['test_mode']         = isset( $input['test_mode'] ) ? '1' : '0';
        $sanitized['sync_interval']     = in_array( $input['sync_interval'] ?? '', array( 'hourly', 'twicedaily', 'daily' ), true )
                                          ? $input['sync_interval']
                                          : 'twicedaily';
        $sanitized['sync_time_1']       = $this->sanitize_time( $input['sync_time_1'] ?? '06:00' );
        $sanitized['sync_time_2']       = $this->sanitize_time( $input['sync_time_2'] ?? '18:00' );
        $sanitized['test_event_code']   = sanitize_text_field( $input['test_event_code'] ?? '' );
        $sanitized['webhook_url']       = esc_url_raw( $input['webhook_url'] ?? '' );
        $sanitized['telegram_bot_token'] = sanitize_text_field( $input['telegram_bot_token'] ?? '' );
        $sanitized['telegram_chat_id']  = sanitize_text_field( $input['telegram_chat_id'] ?? '' );

        // Reschedule cron events.
        $old_options = get_option( self::OPTION_KEY, array() );
        $schedule_changed = ( $old_options['sync_interval'] ?? '' ) !== $sanitized['sync_interval']
                         || ( $old_options['sync_time_1'] ?? '' )   !== $sanitized['sync_time_1']
                         || ( $old_options['sync_time_2'] ?? '' )   !== $sanitized['sync_time_2'];

        if ( $schedule_changed ) {
            $this->reschedule_cron( $sanitized );
        }

        return $sanitized;
    }

    /**
     * Sanitize a time string (HH:MM format).
     */
    private function sanitize_time( $time ) {
        if ( preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $time ) ) {
            return $time;
        }
        return '06:00';
    }

    /**
     * Reschedule cron events based on user settings.
     */
    private function reschedule_cron( $settings ) {
        // Clear existing events.
        $timestamp = wp_next_scheduled( 'cs_meta_catalog_sync' );
        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'cs_meta_catalog_sync' );
            $timestamp = wp_next_scheduled( 'cs_meta_catalog_sync' );
        }
        // Also clear the second event hook.
        $timestamp2 = wp_next_scheduled( 'cs_meta_catalog_sync_2' );
        while ( $timestamp2 ) {
            wp_unschedule_event( $timestamp2, 'cs_meta_catalog_sync_2' );
            $timestamp2 = wp_next_scheduled( 'cs_meta_catalog_sync_2' );
        }

        $interval = $settings['sync_interval'];

        if ( 'hourly' === $interval ) {
            // Hourly ignores time settings — schedule from now.
            wp_schedule_event( time(), 'hourly', 'cs_meta_catalog_sync' );
        } elseif ( 'daily' === $interval ) {
            // One sync per day at sync_time_1.
            $next = $this->next_timestamp_for_time( $settings['sync_time_1'] );
            wp_schedule_event( $next, 'daily', 'cs_meta_catalog_sync' );
        } else {
            // Twice daily: schedule two separate daily events.
            $next1 = $this->next_timestamp_for_time( $settings['sync_time_1'] );
            $next2 = $this->next_timestamp_for_time( $settings['sync_time_2'] );
            wp_schedule_event( $next1, 'daily', 'cs_meta_catalog_sync' );
            wp_schedule_event( $next2, 'daily', 'cs_meta_catalog_sync_2' );
        }
    }

    /**
     * Calculate the next Unix timestamp for a given HH:MM time.
     */
    private function next_timestamp_for_time( $time ) {
        $parts = explode( ':', $time );
        $hour  = (int) $parts[0];
        $min   = (int) ( $parts[1] ?? 0 );

        // Get current time in WP timezone.
        $now   = current_time( 'timestamp' );
        $today = strtotime( sprintf( 'today %02d:%02d', $hour, $min ), $now );

        // If the time has already passed today, schedule for tomorrow.
        if ( $today <= $now ) {
            $today += DAY_IN_SECONDS;
        }

        // Convert from local to UTC for WP-Cron.
        $utc_offset = (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
        return $today - $utc_offset;
    }

    /**
     * Enqueue admin CSS and JS on the plugin page only.
     */
    public function enqueue_admin_assets( $hook ) {
        if ( 'woocommerce_page_cs-meta-sync' !== $hook ) {
            return;
        }
        wp_enqueue_style(
            'cs-meta-sync-admin',
            CS_META_SYNC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            CS_META_SYNC_VERSION
        );
        wp_enqueue_script(
            'cs-meta-sync-admin',
            CS_META_SYNC_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            CS_META_SYNC_VERSION,
            true
        );
        wp_localize_script( 'cs-meta-sync-admin', 'csMetaSync', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'cs_meta_sync_nonce' ),
        ) );
    }

    /**
     * Render the settings page HTML.
     */
    public function render_settings_page() {
        $sync_log = get_option( 'cs_meta_sync_last_log', array() );
        ?>
        <div class="wrap cs-meta-sync-wrap">
            <h1><?php esc_html_e( 'CS Meta Sync', 'cs-meta-sync' ); ?></h1>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'cs_meta_sync_group' );
                do_settings_sections( 'cs-meta-sync' );
                submit_button();
                ?>
            </form>

            <hr />

            <!-- Manual Sync Section -->
            <div class="cs-meta-card">
                <h2><?php esc_html_e( 'Manual Catalog Sync', 'cs-meta-sync' ); ?></h2>
                <p><?php esc_html_e( 'Click the button below to push all WooCommerce products to your Meta catalog right now.', 'cs-meta-sync' ); ?></p>
                <button id="cs-meta-sync-now" class="button button-primary">
                    <?php esc_html_e( 'Sync Now', 'cs-meta-sync' ); ?>
                </button>
                <span id="cs-meta-sync-spinner" class="spinner" style="float:none;"></span>
                <div id="cs-meta-sync-result"></div>
                <div id="cs-meta-sync-verbose"></div>
            </div>

            <!-- Last Sync Log -->
            <?php if ( ! empty( $sync_log ) ) : ?>
            <div class="cs-meta-card">
                <h2><?php esc_html_e( 'Last Sync Log', 'cs-meta-sync' ); ?></h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Time', 'cs-meta-sync' ); ?></th>
                            <th><?php esc_html_e( 'Products Sent', 'cs-meta-sync' ); ?></th>
                            <th><?php esc_html_e( 'Successes', 'cs-meta-sync' ); ?></th>
                            <th><?php esc_html_e( 'Errors', 'cs-meta-sync' ); ?></th>
                            <th><?php esc_html_e( 'Details', 'cs-meta-sync' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?php echo esc_html( $sync_log['time'] ?? '—' ); ?></td>
                            <td><?php echo esc_html( $sync_log['total'] ?? 0 ); ?></td>
                            <td><?php echo esc_html( $sync_log['success'] ?? 0 ); ?></td>
                            <td><?php echo esc_html( $sync_log['errors'] ?? 0 ); ?></td>
                            <td><?php echo esc_html( $sync_log['message'] ?? '' ); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
