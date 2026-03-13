<?php
/**
 * Plugin Name:       CS Meta Sync
 * Plugin URI:        https://github.com/your-repo/cs-meta-sync
 * Description:       Sync WooCommerce products to Meta Commerce catalog, integrate Meta Pixel & Conversions API for marketing and conversion tracking.
 * Version:           1.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            CS Development
 * Author URI:        https://example.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cs-meta-sync
 * Domain Path:       /languages
 * WC requires at least: 5.0
 * WC tested up to:   9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Plugin constants.
 */
define( 'CS_META_SYNC_VERSION', '1.2.0' );
define( 'CS_META_SYNC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CS_META_SYNC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CS_META_SYNC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'CS_META_SYNC_GRAPH_API_VERSION', 'v22.0' );

/**
 * Check for WooCommerce dependency.
 */
function cs_meta_sync_check_woocommerce() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'CS Meta Sync requires WooCommerce to be installed and active.', 'cs-meta-sync' );
            echo '</p></div>';
        } );
        return false;
    }
    return true;
}

/**
 * Main plugin class.
 */
final class CS_Meta_Sync {

    /** @var CS_Meta_Sync|null Singleton instance */
    private static $instance = null;

    /** @var CS_Meta_Settings */
    public $settings;

    /** @var CS_Meta_Catalog_Sync */
    public $catalog_sync;

    /** @var CS_Meta_Cron */
    public $cron;

    /** @var CS_Meta_Pixel */
    public $pixel;

    /** @var CS_Meta_CAPI */
    public $capi;

    /**
     * Get singleton instance.
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor — hook into WordPress.
     */
    private function __construct() {
        $this->includes();
        $this->init_modules();
        $this->init_hooks();
    }

    /**
     * Include required files.
     */
    private function includes() {
        require_once CS_META_SYNC_PLUGIN_DIR . 'includes/class-cs-meta-settings.php';
        require_once CS_META_SYNC_PLUGIN_DIR . 'includes/class-cs-meta-catalog-sync.php';
        require_once CS_META_SYNC_PLUGIN_DIR . 'includes/class-cs-meta-cron.php';
        require_once CS_META_SYNC_PLUGIN_DIR . 'includes/class-cs-meta-pixel.php';
        require_once CS_META_SYNC_PLUGIN_DIR . 'includes/class-cs-meta-capi.php';
        require_once CS_META_SYNC_PLUGIN_DIR . 'includes/class-cs-meta-notifications.php';
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        add_action( 'init', array( $this, 'load_textdomain' ) );
        add_filter( 'plugin_action_links_' . CS_META_SYNC_PLUGIN_BASENAME, array( $this, 'add_settings_link' ) );
    }

    /**
     * Load plugin textdomain.
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'cs-meta-sync', false, dirname( CS_META_SYNC_PLUGIN_BASENAME ) . '/languages/' );
    }

    /**
     * Instantiate all modules.
     */
    public function init_modules() {
        $this->settings     = new CS_Meta_Settings();
        $this->catalog_sync = new CS_Meta_Catalog_Sync();
        $this->cron         = new CS_Meta_Cron();
        $this->pixel        = new CS_Meta_Pixel();
        $this->capi         = new CS_Meta_CAPI();
    }

    /**
     * Add settings link to the plugins page.
     */
    public function add_settings_link( $links ) {
        $settings_link = '<a href="' . admin_url( 'admin.php?page=cs-meta-sync' ) . '">'
                       . esc_html__( 'Settings', 'cs-meta-sync' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Get a plugin option (helper).
     *
     * @param string $key     Option key.
     * @param mixed  $default Default value.
     * @return mixed
     */
    public static function get_option( $key, $default = '' ) {
        $options = get_option( 'cs_meta_sync_settings', array() );
        return isset( $options[ $key ] ) ? $options[ $key ] : $default;
    }
}

/**
 * Boot the plugin after all plugins are loaded.
 */
add_action( 'plugins_loaded', function () {
    if ( cs_meta_sync_check_woocommerce() ) {
        CS_Meta_Sync::instance();
    }
}, 20 );

/**
 * Activation hook — schedule cron, set defaults.
 */
register_activation_hook( __FILE__, function () {
    // Set default options if not already present.
    if ( false === get_option( 'cs_meta_sync_settings' ) ) {
        update_option( 'cs_meta_sync_settings', array(
            'pixel_id'          => '',
            'capi_access_token' => '',
            'catalog_id'        => '',
            'graph_api_token'   => '',
            'enable_pixel'      => '0',
            'enable_capi'       => '0',
            'enable_catalog'    => '0',
            'sync_interval'     => 'twicedaily',
            'test_mode'         => '0',
        ) );
    }

    // Schedule cron.
    if ( ! wp_next_scheduled( 'cs_meta_catalog_sync' ) ) {
        $interval = CS_Meta_Sync::get_option( 'sync_interval', 'twicedaily' );
        wp_schedule_event( time(), $interval, 'cs_meta_catalog_sync' );
    }
} );

/**
 * Deactivation hook — clear cron.
 */
register_deactivation_hook( __FILE__, function () {
    $timestamp = wp_next_scheduled( 'cs_meta_catalog_sync' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'cs_meta_catalog_sync' );
    }
} );
