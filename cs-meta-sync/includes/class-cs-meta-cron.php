<?php
/**
 * CS Meta Sync — WP-Cron Scheduled Sync.
 *
 * Registers and manages the scheduled catalog sync cron event.
 * Supports two daily sync hooks for the "Twice Daily" interval.
 *
 * @package CS_Meta_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CS_Meta_Cron {

    public function __construct() {
        // Primary sync event (used by all intervals).
        add_action( 'cs_meta_catalog_sync', array( $this, 'run_scheduled_sync' ) );
        // Secondary sync event (used only with "Twice Daily" at sync_time_2).
        add_action( 'cs_meta_catalog_sync_2', array( $this, 'run_scheduled_sync' ) );
    }

    /**
     * Callback for the WP-Cron event.
     * Runs a full product and product set sync.
     */
    public function run_scheduled_sync() {
        if ( '1' !== CS_Meta_Sync::get_option( 'enable_catalog' ) ) {
            return;
        }

        $sync = new CS_Meta_Catalog_Sync();
        $log  = $sync->sync_all_products();

        // Log result for debugging.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[CS Meta Sync] Scheduled sync completed: ' . wp_json_encode( array(
                'time'    => $log['time'] ?? '',
                'total'   => $log['total'] ?? 0,
                'success' => $log['success'] ?? 0,
                'errors'  => $log['errors'] ?? 0,
                'sets'    => count( $log['sets'] ?? array() ),
            ) ) );
        }
    }
}
