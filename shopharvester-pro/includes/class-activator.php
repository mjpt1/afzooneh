<?php
/**
 * Activation routines.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles everything that must happen when the plugin is activated:
 * database schema creation, default options, roles and rewrite rules.
 */
class Activator {

    /**
     * Entry point for the activation hook.
     *
     * @return void
     */
    public static function activate() {
        self::create_tables();
        self::seed_default_settings();
        self::register_vendor_role();
        self::ensure_stores_page();

        // The CPT/taxonomy are registered on init; make sure rewrite rules
        // are rebuilt on the next request.
        update_option( 'shp_flush_needed', 'yes' );
        update_option( 'shp_db_version', SHP_DB_VERSION );

        if ( ! wp_next_scheduled( 'shp_process_job_queue' ) ) {
            wp_schedule_event( time() + 60, 'shp_every_minute', 'shp_process_job_queue' );
        }
    }

    /**
     * Names of the plugin's custom tables (without prefix).
     *
     * @return string[]
     */
    public static function table_names() {
        return array( 'shp_logs', 'shp_jobs', 'shp_product_hashes', 'shp_offers' );
    }

    /**
     * Do all custom tables physically exist?
     *
     * @return bool
     */
    public static function tables_exist() {
        global $wpdb;

        foreach ( self::table_names() as $name ) {
            $table = $wpdb->prefix . $name;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore
                return false;
            }
        }

        return true;
    }

    /**
     * Create the custom database tables used for logging, job queueing
     * and product de-duplication hashes.
     *
     * @return void
     */
    public static function create_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $logs            = $wpdb->prefix . 'shp_logs';
        $jobs            = $wpdb->prefix . 'shp_jobs';
        $hashes          = $wpdb->prefix . 'shp_product_hashes';

        $sql_logs = "CREATE TABLE {$logs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            level VARCHAR(10) NOT NULL DEFAULT 'INFO',
            channel VARCHAR(60) NOT NULL DEFAULT 'general',
            message TEXT NOT NULL,
            context LONGTEXT NULL,
            job_id BIGINT UNSIGNED NULL,
            source_url TEXT NULL,
            PRIMARY KEY  (id),
            KEY level (level),
            KEY channel (channel),
            KEY job_id (job_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        $sql_jobs = "CREATE TABLE {$jobs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            target_url TEXT NOT NULL,
            subdomain VARCHAR(190) NULL,
            store_id BIGINT UNSIGNED NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            payload LONGTEXT NULL,
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            stats LONGTEXT NULL,
            last_error LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY store_id (store_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        $sql_hashes = "CREATE TABLE {$hashes} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            source_url_hash CHAR(40) NOT NULL,
            content_hash CHAR(40) NOT NULL,
            sku VARCHAR(120) NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_url_hash (source_url_hash),
            KEY content_hash (content_hash),
            KEY sku (sku),
            KEY store_id (store_id),
            KEY product_id (product_id)
        ) {$charset_collate};";

        $offers = $wpdb->prefix . 'shp_offers';
        $sql_offers = "CREATE TABLE {$offers} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            shop_name VARCHAR(190) NULL,
            shop_city VARCHAR(120) NULL,
            shop_rating DECIMAL(3,2) NULL,
            price DECIMAL(18,2) NULL,
            regular_price DECIMAL(18,2) NULL,
            currency VARCHAR(12) NOT NULL DEFAULT 'IRT',
            availability VARCHAR(20) NOT NULL DEFAULT 'instock',
            buy_url TEXT NOT NULL,
            source_url_hash CHAR(40) NOT NULL,
            first_seen DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY store_source (store_id, source_url_hash),
            KEY product_id (product_id),
            KEY price (price),
            KEY is_active (is_active)
        ) {$charset_collate};";

        dbDelta( $sql_logs );
        dbDelta( $sql_jobs );
        dbDelta( $sql_hashes );
        dbDelta( $sql_offers );

        // Some managed / non-standard WordPress installs silently ignore
        // dbDelta. Fall back to a plain CREATE TABLE when that happens.
        foreach ( array( $sql_logs, $sql_jobs, $sql_hashes, $sql_offers ) as $sql ) {
            if ( preg_match( '/CREATE TABLE\s+(\S+)\s*\(/', $sql, $m ) ) {
                $table = $m[1];
                if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore
                    $wpdb->query( str_replace( 'CREATE TABLE ' . $table, 'CREATE TABLE IF NOT EXISTS ' . $table, $sql ) ); // phpcs:ignore
                }
            }
        }

        // Additive column migrations for installs where dbDelta under-performs.
        self::ensure_column( $jobs, 'created_by', 'BIGINT UNSIGNED NOT NULL DEFAULT 0' );
    }

    /**
     * Add a column to a table when it is missing (dbDelta-independent).
     *
     * @param string $table      Fully-qualified table name.
     * @param string $column      Column name.
     * @param string $definition Column definition (type + default).
     * @return void
     */
    private static function ensure_column( $table, $column, $definition ) {
        global $wpdb;

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore
            return;
        }

        $exists = $wpdb->get_var( // phpcs:ignore
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                $table,
                $column
            )
        );

        if ( ! $exists ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" ); // phpcs:ignore
        }
    }

    /**
     * Store the default settings only if none exist yet.
     *
     * @return void
     */
    public static function seed_default_settings() {
        if ( get_option( 'shp_settings' ) ) {
            return;
        }

        add_option( 'shp_settings', Settings::defaults() );
    }

    /**
     * Create the public "Stores" directory page (once) with the [shp_stores]
     * shortcode, unless the operator already made one.
     *
     * @return void
     */
    public static function ensure_stores_page() {
        $existing = (int) get_option( 'shp_stores_page_id' );
        if ( $existing && get_post( $existing ) ) {
            return;
        }

        $page_id = wp_insert_post(
            array(
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => __( 'فروشگاه‌ها', 'shopharvester-pro' ),
                'post_name'    => 'stores',
                'post_content' => '[shp_stores search="1" columns="3"]',
            )
        );

        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( 'shp_stores_page_id', (int) $page_id );
        }

        // Comparison page.
        $cmp = (int) get_option( 'shp_compare_page_id' );
        if ( ! $cmp || ! get_post( $cmp ) ) {
            $cmp_id = wp_insert_post(
                array(
                    'post_type'    => 'page',
                    'post_status'  => 'publish',
                    'post_title'   => __( 'مقایسه محصولات', 'shopharvester-pro' ),
                    'post_name'    => 'compare',
                    'post_content' => '[shp_compare]',
                )
            );
            if ( $cmp_id && ! is_wp_error( $cmp_id ) ) {
                update_option( 'shp_compare_page_id', (int) $cmp_id );
            }
        }
    }

    /**
     * Register the Vendor role used for scraped store owners.
     *
     * @return void
     */
    public static function register_vendor_role() {
        if ( get_role( 'shp_vendor' ) ) {
            return;
        }

        add_role(
            'shp_vendor',
            __( 'فروشنده (ShopHarvester)', 'shopharvester-pro' ),
            array(
                'read'                   => true,
                'edit_posts'             => false,
                'delete_posts'           => false,
                'upload_files'           => false,
                'shp_manage_own_store'   => true,
            )
        );
    }
}
