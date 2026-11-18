<?php
/**
 * Core plugin orchestrator.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

use ShopHarvesterPro\Admin\Admin_Menu;
use ShopHarvesterPro\Api\Scraper_Webhook;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Singleton that boots every subsystem and registers WordPress hooks.
 */
final class Core {

    /**
     * Single instance.
     *
     * @var Core|null
     */
    private static $instance = null;

    /**
     * @var Entity_Manager
     */
    public $entities;

    /**
     * @var Subdomain_Manager
     */
    public $subdomains;

    /**
     * @var Job_Queue
     */
    public $jobs;

    /**
     * Retrieve the singleton instance.
     *
     * @return Core
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Private constructor — wires the object graph.
     */
    private function __construct() {
        $this->entities   = new Entity_Manager();
        $this->subdomains = new Subdomain_Manager();
        $this->jobs       = new Job_Queue();
    }

    /**
     * Register all runtime hooks.
     *
     * @return void
     */
    public function run() {
        add_action( 'plugins_loaded', array( $this, 'maybe_upgrade_db' ), 20 );
        add_action( 'admin_init', array( $this, 'maybe_upgrade_db' ), 1 );
        add_action( 'admin_init', array( $this, 'maybe_backfill_classima_meta' ), 2 );
        add_action( 'admin_init', array( $this, 'maybe_upgrade_prompt_template' ), 3 );
        add_action( 'admin_init', array( $this, 'maybe_backfill_vendor_authors' ), 3 );
        add_action( 'admin_notices', array( $this, 'db_health_notice' ) );

        add_action( 'init', array( $this, 'load_textdomain' ) );
        add_action( 'init', array( $this->entities, 'register' ), 5 );
        add_action( 'init', array( $this->entities, 'attach_woo_taxonomies' ), 20 );
        add_action( 'init', array( $this->subdomains, 'register_rewrite' ), 6 );
        ( new Storefront() )->register();
        add_action( 'init', array( $this, 'maybe_flush_rewrite' ), 99 );
        add_action( 'wp_loaded', array( $this, 'self_heal_rewrite' ), 99 );

        // Cron plumbing.
        add_filter( 'cron_schedules', array( $this, 'register_cron_schedule' ) );
        add_action( 'init', array( $this, 'self_heal_cron' ), 21 );
        add_action( 'shp_process_job_queue', array( $this->jobs, 'process_due' ) );
        add_action( 'shp_auto_resync', array( $this->jobs, 'auto_resync' ) );
        add_action( 'shp_as_auto_resync', array( $this->jobs, 'auto_resync' ) );
        add_action( 'shp_as_process_job', array( $this->jobs, 'process_job_by_id' ), 10, 1 );
        add_action( 'init', array( $this->jobs, 'maybe_schedule_resync' ), 20 );
        add_action( 'admin_init', array( $this->jobs, 'reconcile_scheduler' ) );
        add_action( 'shp_daily_maintenance', array( 'ShopHarvesterPro\\Includes\\Logger', 'prune' ) );
        if ( ! wp_next_scheduled( 'shp_daily_maintenance' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'shp_daily_maintenance' );
        }

        // Front-end store partitioning.
        add_action( 'parse_request', array( $this->subdomains, 'detect_current_store' ), 1 );
        add_action( 'pre_get_posts', array( $this->subdomains, 'filter_main_query' ) );

        // REST ingest endpoints.
        add_action( 'rest_api_init', array( new Scraper_Webhook( $this->jobs ), 'register_routes' ) );

        // Classima mode: keep a harvested product and its linked rtcl_listing
        // in the same publish state, so publishing the product also publishes
        // the ad (and trashing hides it).
        add_action( 'transition_post_status', array( $this, 'sync_linked_listing_status' ), 10, 3 );

        // Fill SEO metadata (Rank Math / Yoast) when a harvested product is
        // first published, so it is indexable and not flagged "not optimised".
        add_action( 'transition_post_status', array( $this, 'seed_product_seo' ), 12, 3 );

        // Admin.
        if ( is_admin() ) {
            $admin = new Admin_Menu( $this->jobs );
            $admin->hooks();

            ( new \ShopHarvesterPro\Admin\Store_Profile() )->hooks();

            // WP-Cron fallback: drain the queue while on a plugin screen.
            add_action( 'admin_init', array( $this->jobs, 'maybe_process_on_admin' ) );
        }
    }

    /**
     * Load translations.
     *
     * @return void
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'shopharvester-pro', false, dirname( SHP_PLUGIN_BASENAME ) . '/languages' );
    }

    /**
     * Add a one-minute cron interval for near-real-time queue processing.
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public function register_cron_schedule( $schedules ) {
        $schedules['shp_every_minute'] = array(
            'interval' => 60,
            'display'  => __( 'هر یک دقیقه (ShopHarvester)', 'shopharvester-pro' ),
        );

        foreach ( array( 6, 12, 24, 48, 72, 168 ) as $hours ) {
            $schedules[ 'shp_resync_' . $hours . 'h' ] = array(
                'interval' => $hours * HOUR_IN_SECONDS,
                'display'  => sprintf( __( 'هر %d ساعت (همگام‌سازی ShopHarvester)', 'shopharvester-pro' ), $hours ),
            );
        }

        return $schedules;
    }

    /**
     * Self-healing schema: create the custom tables if the activation hook
     * never fired or a managed host ignored dbDelta. Runs at most once per
     * request-burst and becomes a no-op as soon as the tables exist.
     *
     * @return void
     */
    public function maybe_upgrade_db() {
        if ( get_option( 'shp_db_version' ) === SHP_DB_VERSION && Activator::tables_exist() ) {
            return;
        }

        if ( get_transient( 'shp_db_upgrade_running' ) ) {
            return;
        }
        set_transient( 'shp_db_upgrade_running', 1, 2 * MINUTE_IN_SECONDS );

        Activator::create_tables();

        if ( Activator::tables_exist() ) {
            update_option( 'shp_db_version', SHP_DB_VERSION );
            delete_option( 'shp_db_broken' );
        } else {
            update_option( 'shp_db_broken', current_time( 'mysql' ) );
        }

        delete_transient( 'shp_db_upgrade_running' );
    }

    /**
     * Warn the operator when the custom tables could not be created.
     *
     * @return void
     */
    public function db_health_notice() {
        if ( ! current_user_can( 'manage_options' ) || ! get_option( 'shp_db_broken' ) ) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>برداشت‌گر فروشگاه پرو:</strong> '
            . esc_html__( 'جدول‌های دیتابیس افزونه ساخته نشدند. افزونه را یک‌بار غیرفعال و دوباره فعال کنید؛ اگر باز هم رفع نشد، دسترسی کاربر دیتابیس به دستور CREATE TABLE را با میزبان بررسی کنید.', 'shopharvester-pro' )
            . '</p></div>';
    }

    /**
     * Re-register the recurring queue event if it went missing (e.g. after an
     * update without re-activation, or a plugin conflict clearing cron). When
     * Action Scheduler drives the queue this recurring event is not needed.
     *
     * @return void
     */
    public function self_heal_cron() {
        if ( \ShopHarvesterPro\Includes\Scheduler::available() ) {
            return;
        }
        if ( ! wp_next_scheduled( 'shp_process_job_queue' ) ) {
            wp_schedule_event( time() + 60, 'shp_every_minute', 'shp_process_job_queue' );
        }
    }

    /**
     * Flush rewrite rules once after activation or CPT changes.
     *
     * @return void
     */
    public function maybe_flush_rewrite() {
        $needs_flush = 'yes' === get_option( 'shp_flush_needed' )
            || get_option( 'shp_rewrite_version' ) !== SHP_VERSION;

        if ( $needs_flush ) {
            // Hard flush on a version bump (rewrites .htaccess too where
            // possible); soft flush is enough for the recurring import flag.
            $hard = get_option( 'shp_rewrite_version' ) !== SHP_VERSION;
            flush_rewrite_rules( $hard );
            delete_option( 'shp_flush_needed' );
            update_option( 'shp_rewrite_version', SHP_VERSION );

            // Backfill assets introduced by upgrades.
            Activator::ensure_stores_page();
        }
    }

    /**
     * Refresh the AI prompt template to the current default once, but only when
     * the stored one is an unmodified earlier default (never clobber a template
     * the operator has customised).
     *
     * @return void
     */
    public function maybe_upgrade_prompt_template() {
        if ( 'v2' === get_option( 'shp_prompt_tpl_ver' ) ) {
            return;
        }
        update_option( 'shp_prompt_tpl_ver', 'v2' );

        $stored = get_option( 'shp_settings' );
        if ( ! is_array( $stored ) || ! isset( $stored['ai_prompt_template'] ) ) {
            return;
        }
        $current = (string) $stored['ai_prompt_template'];

        $is_old_default = '' === trim( $current )
            || ( false !== strpos( $current, 'متخصص ارشد سئو و کپی‌رایتینگ فروشگاهی' ) && false === strpos( $current, 'قواعد اجباری' ) );

        if ( $is_old_default ) {
            $stored['ai_prompt_template'] = Settings::default_prompt_template();
            Settings::update( $stored );
        }
    }

    /**
     * Front-end safety net: if WooCommerce is active but the stored rewrite
     * rules have lost the `product` / `product_cat` routes (a partial rebuild
     * triggered from a cron context leaves single-product and product-category
     * permalinks 404ing), rebuild them once. Rate-limited so a genuinely
     * rule-less state cannot loop.
     *
     * @return void
     */
    public function self_heal_rewrite() {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }
        if ( ! post_type_exists( 'product' ) || '' === (string) get_option( 'permalink_structure' ) ) {
            return;
        }
        if ( get_transient( 'shp_rewrite_healed' ) ) {
            return;
        }

        $rules = get_option( 'rewrite_rules' );
        if ( is_array( $rules ) ) {
            foreach ( array_keys( $rules ) as $pattern ) {
                if ( 0 === strpos( $pattern, 'product/' ) || 0 === strpos( $pattern, 'product-category/' ) ) {
                    return; // Product routes present — nothing to do.
                }
            }
        }

        set_transient( 'shp_rewrite_healed', 1, HOUR_IN_SECONDS );
        flush_rewrite_rules( false );
    }

    /**
     * One-off: tag Classima product listings imported by older versions so the
     * buy button and the directory-hiding filter recognise them, and copy the
     * source URL from the Classified Listing importer's own provenance meta.
     *
     * @return void
     */
    public function maybe_backfill_classima_meta() {
        if ( get_option( 'shp_classima_backfill' ) === SHP_VERSION ) {
            return;
        }

        global $wpdb;

        if ( post_type_exists( 'rtcl_listing' ) ) {
            // _shp_is_listing = 1 for every shopharvester import that is not a shop.
            $wpdb->query( // phpcs:ignore
                "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
                 SELECT p.ID, '_shp_is_listing', '1'
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} src ON src.post_id = p.ID AND src.meta_key = '_rtcl_import_source' AND src.meta_value = 'shopharvester'
                 LEFT JOIN {$wpdb->postmeta} ex   ON ex.post_id = p.ID AND ex.meta_key = '_shp_is_listing'
                 LEFT JOIN {$wpdb->postmeta} shop ON shop.post_id = p.ID AND shop.meta_key = '_shp_is_shop'
                 WHERE p.post_type = 'rtcl_listing' AND ex.meta_id IS NULL AND shop.meta_id IS NULL"
            );

            // _shp_source_url from the importer's _rtcl_import_source_url.
            $wpdb->query( // phpcs:ignore
                "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
                 SELECT s.post_id, '_shp_source_url', s.meta_value
                 FROM {$wpdb->postmeta} s
                 LEFT JOIN {$wpdb->postmeta} ex ON ex.post_id = s.post_id AND ex.meta_key = '_shp_source_url'
                 WHERE s.meta_key = '_rtcl_import_source_url' AND s.meta_value <> '' AND ex.meta_id IS NULL"
            );
        }

        // Feed the [seller_buy_link] shortcode's meta for every harvested item
        // (WooCommerce product or listing) that has a known source URL.
        $wpdb->query( // phpcs:ignore
            "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
             SELECT s.post_id, '_sbl_seller_buy_link', s.meta_value
             FROM {$wpdb->postmeta} s
             LEFT JOIN {$wpdb->postmeta} ex ON ex.post_id = s.post_id AND ex.meta_key = '_sbl_seller_buy_link'
             WHERE s.meta_key = '_shp_source_url' AND s.meta_value <> '' AND ex.meta_id IS NULL"
        );

        update_option( 'shp_classima_backfill', SHP_VERSION, false );
        update_option( 'shp_flush_needed', 'yes' );
    }

    /**
     * One-off, resumable: make every harvested product owned by its source
     * shop's WCFM vendor so it shows on that vendor's storefront. Products
     * imported by older builds (or while the output target was plain
     * WooCommerce) were authored by the operator and therefore invisible on
     * every store page.
     *
     * Runs in small batches on admin_init; each request promotes a few
     * vendors and re-authors up to 500 products, then marks itself done.
     *
     * @return void
     */
    public function maybe_backfill_vendor_authors() {
        if ( get_option( 'shp_vendor_author_backfill' ) === SHP_VERSION ) {
            return;
        }
        if ( ! Settings::get( 'classima_make_vendor', true ) ) {
            update_option( 'shp_vendor_author_backfill', SHP_VERSION, false );
            return;
        }

        global $wpdb;

        $state  = get_option( 'shp_vendor_backfill_state', array( 'phase' => 'vendors', 'offset' => 0 ) );
        $phase  = $state['phase'] ?? 'vendors';
        $offset = (int) ( $state['offset'] ?? 0 );

        if ( 'vendors' === $phase ) {
            $store_ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore
                "SELECT DISTINCT CAST(meta_value AS UNSIGNED) AS sid
                 FROM {$wpdb->postmeta}
                 WHERE meta_key = '_shp_store_id' AND meta_value <> ''
                 ORDER BY sid LIMIT 15 OFFSET %d",
                $offset
            ) );

            foreach ( $store_ids as $sid ) {
                Classima_Writer::ensure_vendor( (int) $sid );
            }

            if ( count( $store_ids ) < 15 ) {
                update_option( 'shp_vendor_backfill_state', array( 'phase' => 'authors', 'offset' => 0 ), false );
            } else {
                update_option( 'shp_vendor_backfill_state', array( 'phase' => 'vendors', 'offset' => $offset + 15 ), false );
            }
            return;
        }

        // Phase: re-author products in batches.
        $rows = $wpdb->get_results( // phpcs:ignore
            "SELECT p.ID AS id, CAST(v.meta_value AS UNSIGNED) AS vendor
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_shp_store_id'
             INNER JOIN {$wpdb->postmeta} v ON v.post_id = CAST(s.meta_value AS UNSIGNED) AND v.meta_key = '_shp_store_vendor_id'
             WHERE p.post_type IN ('product','shp_product')
               AND v.meta_value REGEXP '^[0-9]+$'
               AND CAST(v.meta_value AS UNSIGNED) > 0
               AND p.post_author <> CAST(v.meta_value AS UNSIGNED)
             LIMIT 500"
        );

        if ( empty( $rows ) ) {
            update_option( 'shp_vendor_author_backfill', SHP_VERSION, false );
            delete_option( 'shp_vendor_backfill_state' );
            delete_transient( 'wcfm_marketplace_active_vendors' );
            delete_option( 'wcfm_marketplace_active_vendors' );
            update_option( 'shp_flush_needed', 'yes' );
            return;
        }

        $by_vendor = array();
        foreach ( $rows as $row ) {
            $by_vendor[ (int) $row->vendor ][] = (int) $row->id;
        }

        foreach ( $by_vendor as $vendor_id => $ids ) {
            $in = implode( ',', array_map( 'intval', $ids ) );
            $wpdb->query( $wpdb->prepare( // phpcs:ignore
                "UPDATE {$wpdb->posts} SET post_author = %d WHERE ID IN ({$in})",
                $vendor_id
            ) );
            foreach ( $ids as $id ) {
                clean_post_cache( $id );
            }
        }
    }

    /**
     * Mirror a harvested product's publish state onto its linked rtcl_listing.
     *
     * @param string   $new_status New post status.
     * @param string   $old_status Previous post status.
     * @param \WP_Post $post       Post being transitioned.
     * @return void
     */
    public function sync_linked_listing_status( $new_status, $old_status, $post ) {
        if ( ! $post instanceof \WP_Post || $new_status === $old_status ) {
            return;
        }
        if ( ! in_array( $post->post_type, array( 'product', Entity_Manager::PRODUCT_CPT ), true ) ) {
            return;
        }

        $listing_id = (int) get_post_meta( $post->ID, '_shp_listing_id', true );
        if ( ! $listing_id || get_post_type( $listing_id ) !== 'rtcl_listing' ) {
            return;
        }

        $map = array( 'publish' => 'publish', 'trash' => 'trash', 'draft' => 'draft', 'pending' => 'pending' );
        if ( ! isset( $map[ $new_status ] ) || get_post_status( $listing_id ) === $map[ $new_status ] ) {
            return;
        }

        // Guard against the reverse transition re-entering this handler.
        remove_action( 'transition_post_status', array( $this, 'sync_linked_listing_status' ), 10 );
        if ( 'trash' === $new_status ) {
            wp_trash_post( $listing_id );
        } else {
            wp_update_post( array( 'ID' => $listing_id, 'post_status' => $map[ $new_status ] ) );
        }
        add_action( 'transition_post_status', array( $this, 'sync_linked_listing_status' ), 10, 3 );
    }

    /**
     * Give a harvested product the SEO metadata that Rank Math / Yoast expect,
     * so it is indexable and not flagged "not optimised". Fills gaps only —
     * anything an editor set by hand is left alone. Runs on first publish.
     *
     * @param string   $new_status New post status.
     * @param string   $old_status Previous post status.
     * @param \WP_Post $post       Post being transitioned.
     * @return void
     */
    public function seed_product_seo( $new_status, $old_status, $post ) {
        if ( ! $post instanceof \WP_Post || 'publish' !== $new_status || 'publish' === $old_status ) {
            return;
        }
        if ( ! in_array( $post->post_type, array( 'product', Entity_Manager::PRODUCT_CPT ), true ) ) {
            return;
        }
        if ( '' === (string) get_post_meta( $post->ID, '_shp_source_url_hash', true ) ) {
            return;
        }

        ( new Seo_Optimizer() )->apply( $post->ID );
    }
}
