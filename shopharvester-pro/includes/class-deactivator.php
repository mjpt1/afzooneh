<?php
/**
 * Deactivation routines.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles cleanup that must happen when the plugin is deactivated.
 * No data is destroyed here — only scheduled events and rewrite rules.
 */
class Deactivator {

    /**
     * Entry point for the deactivation hook.
     *
     * @return void
     */
    public static function deactivate() {
        $timestamp = wp_next_scheduled( 'shp_process_job_queue' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'shp_process_job_queue' );
        }
        wp_clear_scheduled_hook( 'shp_process_job_queue' );
        wp_clear_scheduled_hook( 'shp_auto_resync' );
        delete_option( 'shp_resync_scheduled_hours' );

        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( '', array(), 'shopharvester' );
        }

        flush_rewrite_rules();
    }
}
