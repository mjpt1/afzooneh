<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes plugin options and custom tables when the operator explicitly
 * requests full cleanup via the plugin settings.
 *
 * @package ShopHarvesterPro
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$settings = get_option( 'shp_settings', array() );
$purge    = isset( $settings['purge_on_uninstall'] ) ? (bool) $settings['purge_on_uninstall'] : false;

delete_option( 'shp_settings' );
delete_option( 'shp_db_version' );
delete_option( 'shp_flush_needed' );
delete_option( 'shp_rewrite_version' );
delete_option( 'shp_stores_page_id' );
delete_option( 'shp_resync_scheduled_hours' );
delete_option( 'shp_last_runner_poll' );

if ( $purge ) {
    $tables = array(
        $wpdb->prefix . 'shp_logs',
        $wpdb->prefix . 'shp_jobs',
        $wpdb->prefix . 'shp_product_hashes',
    );

    foreach ( $tables as $table ) {
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    // Remove custom post types content created by the plugin.
    $cpt_ids = get_posts(
        array(
            'post_type'      => array( 'store_profile' ),
            'post_status'    => 'any',
            'numberposts'    => -1,
            'fields'         => 'ids',
        )
    );

    foreach ( $cpt_ids as $cpt_id ) {
        wp_delete_post( $cpt_id, true );
    }
}
