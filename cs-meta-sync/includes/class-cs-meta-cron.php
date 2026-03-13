<?php
/**
 * CS Meta Sync — WP-Cron Scheduled Sync.
 *
 * Registers and manages the scheduled catalog sync cron event.
 *
 * @package CS_Meta_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CS_Meta_Cron {

    public function __construct() {
        add_action( 'cs_meta_catalog_sync', array( $this, 'run_scheduled_sync' ) );
    }

    /**
     * Callback for the WP-Cron event.
     */
    public function run_scheduled_sync() {
        if ( '1' !== CS_Meta_Sync::get_option( 'enable_catalog' ) ) {
            return;
        }

        $sync = new CS_Meta_Catalog_Sync();
        $log  = $sync->sync_all_products();

        // Log result for debugging.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[CS Meta Sync] Scheduled sync: ' . wp_json_encode( $log ) );
        }
    }
}
