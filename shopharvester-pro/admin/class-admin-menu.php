<?php
/**
 * Admin dashboard, menu registration and AJAX endpoints.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Admin;

use ShopHarvesterPro\Includes\Entity_Manager;
use ShopHarvesterPro\Includes\Job_Queue;
use ShopHarvesterPro\Includes\Logger;
use ShopHarvesterPro\Includes\Settings;
use ShopHarvesterPro\Includes\Subdomain_Manager;
use ShopHarvesterPro\Includes\Taxonomy_Mapper;
use ShopHarvesterPro\Includes\System_Report;
use ShopHarvesterPro\Includes\AI_Engine;
use ShopHarvesterPro\Includes\Offer_Repo;
use ShopHarvesterPro\Includes\Seo_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the ShopHarvester Pro admin experience and wires its AJAX actions.
 */
class Admin_Menu {

    const CAPABILITY = 'manage_options';
    const SLUG       = 'shopharvester';
    const NONCE      = 'shp_admin_nonce';

    /**
     * @var Job_Queue
     */
    private $jobs;

    /**
     * Constructor.
     *
     * @param Job_Queue $jobs Job queue.
     */
    public function __construct( Job_Queue $jobs ) {
        $this->jobs = $jobs;
    }

    /**
     * Register admin hooks.
     *
     * @return void
     */
    public function hooks() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

        // Keep the ShopHarvester menu highlighted on the native CPT screens.
        add_filter( 'parent_file', array( $this, 'fix_parent_file' ) );
        add_filter( 'submenu_file', array( $this, 'fix_submenu_file' ) );

        // Bulk "publish" / lock actions on the harvested-products list table
        // (both the native CPT and — when used — WooCommerce products).
        foreach ( array_unique( array( Entity_Manager::PRODUCT_CPT, Entity_Manager::product_post_type() ) ) as $ptype ) {
            add_filter( 'bulk_actions-edit-' . $ptype, array( $this, 'register_bulk_publish' ) );
            add_filter( 'handle_bulk_actions-edit-' . $ptype, array( $this, 'handle_bulk_publish' ), 10, 3 );
        }
        add_action( 'admin_notices', array( $this, 'bulk_publish_notice' ) );
        add_action( 'admin_notices', array( $this, 'ai_key_notice' ) );

        // "Harvested only" filtered view of the WooCommerce products list.
        add_action( 'pre_get_posts', array( $this, 'filter_harvested_products' ) );
        add_filter( 'views_edit-product', array( $this, 'harvested_products_view_link' ) );
        add_action( 'wp_ajax_shp_publish_store_drafts', array( $this, 'ajax_publish_store_drafts' ) );
        add_action( 'wp_ajax_shp_publish_all_drafts', array( $this, 'ajax_publish_all_drafts' ) );
        add_action( 'wp_ajax_shp_purge_product_listings', array( $this, 'ajax_purge_product_listings' ) );
        add_action( 'wp_ajax_shp_seo_optimize_all', array( $this, 'ajax_seo_optimize_all' ) );
        add_action( 'wp_ajax_shp_purge_store_categories', array( $this, 'ajax_purge_store_categories' ) );
        add_action( 'wp_ajax_shp_merge_duplicate_categories', array( $this, 'ajax_merge_duplicate_categories' ) );
        add_action( 'wp_ajax_shp_repair_glued_prices', array( $this, 'ajax_repair_glued_prices' ) );
        add_action( 'wp_ajax_shp_delete_store_products', array( $this, 'ajax_delete_store_products' ) );

        add_action( 'wp_ajax_shp_submit_run', array( $this, 'ajax_submit_run' ) );
        add_action( 'wp_ajax_shp_retry_job', array( $this, 'ajax_retry_job' ) );
        add_action( 'wp_ajax_shp_run_job', array( $this, 'ajax_run_job' ) );
        add_action( 'wp_ajax_shp_fetch_logs', array( $this, 'ajax_fetch_logs' ) );
        add_action( 'wp_ajax_shp_export_logs', array( $this, 'ajax_export_logs' ) );
        add_action( 'wp_ajax_shp_download_report', array( $this, 'ajax_download_report' ) );
        add_action( 'wp_ajax_shp_download_logs_json', array( $this, 'ajax_download_logs_json' ) );
        add_action( 'wp_ajax_shp_process_queue', array( $this, 'ajax_process_queue' ) );
        add_action( 'wp_ajax_shp_network_test', array( $this, 'ajax_network_test' ) );
        add_action( 'wp_ajax_shp_ai_test', array( $this, 'ajax_ai_test' ) );
        add_action( 'wp_ajax_shp_cancel_job', array( $this, 'ajax_cancel_job' ) );
        add_action( 'admin_post_shp_submit_run', array( $this, 'handle_post_submit_run' ) );
        add_action( 'add_meta_boxes', array( $this, 'register_offer_metabox' ) );
        add_action( 'wp_ajax_shp_delete_offer', array( $this, 'ajax_delete_offer' ) );
        add_action( 'wp_ajax_shp_add_offer', array( $this, 'ajax_add_offer' ) );
        add_action( 'wp_ajax_shp_save_settings', array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_shp_save_mappings', array( $this, 'ajax_save_mappings' ) );
        add_action( 'wp_ajax_shp_dashboard_stats', array( $this, 'ajax_dashboard_stats' ) );
    }

    /* --------------------------------------------------------------------- *
     *  Menu + assets.
     * --------------------------------------------------------------------- */

    /**
     * Register the top-level menu and sub-pages.
     *
     * @return void
     */
    public function register_menu() {
        $menu_icon = 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#a7aaad">'
            . '<path d="M5.6 8h12.8l-1.05 10a2.4 2.4 0 0 1-2.4 2.15H9.05A2.4 2.4 0 0 1 6.65 18z"/>'
            . '<path fill="none" stroke="#a7aaad" stroke-width="1.6" stroke-linecap="round" d="M8.6 8.4V6a3.4 3.4 0 0 1 6.8 0v2.4"/>'
            . '<path fill="none" stroke="#1e1b4b" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" d="M12 10.4v4.4M9.9 12.7l2.1 2.1 2.1-2.1"/>'
            . '</svg>'
        );

        add_menu_page(
            __( 'برداشت‌گر فروشگاه پرو', 'shopharvester-pro' ),
            __( 'برداشت‌گر فروشگاه', 'shopharvester-pro' ),
            self::CAPABILITY,
            self::SLUG,
            array( $this, 'render_page' ),
            $menu_icon,
            26
        );

        // Plugin screens (rendered by render_page).
        $own = array(
            ''       => __( 'داشبورد', 'shopharvester-pro' ),
            'runner' => __( 'اجرای برداشت', 'shopharvester-pro' ),
        );

        foreach ( $own as $tab => $label ) {
            add_submenu_page(
                self::SLUG,
                $label,
                $label,
                self::CAPABILITY,
                $tab ? self::SLUG . '-' . $tab : self::SLUG,
                array( $this, 'render_page' )
            );
        }

        // Native list tables for the harvested content, kept in explicit order.
        $ptype  = Entity_Manager::product_post_type();
        $cat_tax = ( 'product' === $ptype && taxonomy_exists( 'product_cat' ) ) ? 'product_cat' : Entity_Manager::TAX_CAT;

        $products_link = 'edit.php?post_type=' . $ptype;
        if ( 'product' === $ptype ) {
            // Filter the WooCommerce list down to items this plugin harvested.
            $products_link = add_query_arg( 'shp_harvested', '1', $products_link );
        }
        add_submenu_page(
            self::SLUG,
            __( 'محصولات برداشت‌شده', 'shopharvester-pro' ),
            __( 'محصولات برداشت‌شده', 'shopharvester-pro' ),
            'edit_posts',
            $products_link
        );
        add_submenu_page(
            self::SLUG,
            __( 'دسته‌بندی محصولات', 'shopharvester-pro' ),
            __( 'دسته‌بندی محصولات', 'shopharvester-pro' ),
            'manage_categories',
            'edit-tags.php?taxonomy=' . $cat_tax . '&post_type=' . $ptype
        );
        add_submenu_page(
            self::SLUG,
            __( 'پروفایل فروشگاه‌ها', 'shopharvester-pro' ),
            __( 'پروفایل فروشگاه‌ها', 'shopharvester-pro' ),
            'edit_posts',
            'edit.php?post_type=' . Entity_Manager::STORE_CPT
        );

        // Remaining plugin screens — راهنما intentionally last, after تنظیمات.
        $tail = array(
            'stores'   => __( 'خلاصه فروشگاه‌ها', 'shopharvester-pro' ),
            'mappings' => __( 'نگاشت دسته‌ها', 'shopharvester-pro' ),
            'logs'     => __( 'گزارش‌ها', 'shopharvester-pro' ),
            'report'   => __( 'گزارش سیستم', 'shopharvester-pro' ),
            'settings' => __( 'تنظیمات', 'shopharvester-pro' ),
            'help'     => __( 'راهنما', 'shopharvester-pro' ),
        );

        foreach ( $tail as $tab => $label ) {
            add_submenu_page(
                self::SLUG,
                $label,
                $label,
                self::CAPABILITY,
                self::SLUG . '-' . $tab,
                array( $this, 'render_page' )
            );
        }
    }

    /**
     * Highlight the ShopHarvester top-level menu on the native CPT screens.
     *
     * @param string $parent_file Current parent file.
     * @return string
     */
    public function fix_parent_file( $parent_file ) {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && in_array( $screen->post_type, array( Entity_Manager::PRODUCT_CPT, Entity_Manager::STORE_CPT ), true ) ) {
            return self::SLUG;
        }
        if ( $screen && Entity_Manager::TAX_CAT === $screen->taxonomy ) {
            return self::SLUG;
        }
        if ( $screen && 'edit-product' === $screen->id && ! empty( $_GET['shp_harvested'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            return self::SLUG;
        }

        return $parent_file;
    }

    /**
     * Highlight the correct sub-item on the native CPT screens.
     *
     * @param string $submenu_file Current submenu file.
     * @return string
     */
    public function fix_submenu_file( $submenu_file ) {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen ) {
            return $submenu_file;
        }

        if ( Entity_Manager::TAX_CAT === $screen->taxonomy ) {
            return 'edit-tags.php?taxonomy=' . Entity_Manager::TAX_CAT . '&post_type=' . Entity_Manager::PRODUCT_CPT;
        }
        if ( Entity_Manager::PRODUCT_CPT === $screen->post_type ) {
            return 'edit.php?post_type=' . Entity_Manager::PRODUCT_CPT;
        }
        if ( Entity_Manager::STORE_CPT === $screen->post_type ) {
            return 'edit.php?post_type=' . Entity_Manager::STORE_CPT;
        }
        if ( 'edit-product' === $screen->id && ! empty( $_GET['shp_harvested'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            return 'edit.php?post_type=product&shp_harvested=1';
        }

        return $submenu_file;
    }

    /**
     * Add a "publish" bulk action to the harvested-products list table.
     *
     * @param array $actions Existing bulk actions.
     * @return array
     */
    public function register_bulk_publish( $actions ) {
        $actions['shp_publish'] = __( 'انتشار (وضعیت → منتشرشده)', 'shopharvester-pro' );
        $actions['shp_lock']    = __( 'قفل کردن (در برداشت مجدد تغییر نکند)', 'shopharvester-pro' );
        $actions['shp_unlock']  = __( 'باز کردن قفل', 'shopharvester-pro' );
        return $actions;
    }

    /**
     * Handle the bulk publish action.
     *
     * @param string $redirect  Redirect URL.
     * @param string $doaction  Selected action.
     * @param int[]  $post_ids  Selected post IDs.
     * @return string
     */
    public function handle_bulk_publish( $redirect, $doaction, $post_ids ) {
        if ( ! in_array( $doaction, array( 'shp_publish', 'shp_lock', 'shp_unlock' ), true ) ) {
            return $redirect;
        }

        $count = 0;
        foreach ( (array) $post_ids as $post_id ) {
            $post_id = (int) $post_id;
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                continue;
            }

            if ( 'shp_publish' === $doaction && 'publish' !== get_post_status( $post_id ) ) {
                if ( ! is_wp_error( wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ), true ) ) ) {
                    $count++;
                }
            } elseif ( 'shp_lock' === $doaction ) {
                update_post_meta( $post_id, '_shp_locked', 1 );
                $count++;
            } elseif ( 'shp_unlock' === $doaction ) {
                delete_post_meta( $post_id, '_shp_locked' );
                $count++;
            }
        }

        $arg = 'shp_publish' === $doaction ? 'shp_published' : ( 'shp_lock' === $doaction ? 'shp_locked' : 'shp_unlocked' );

        return add_query_arg( $arg, $count, $redirect );
    }

    /**
     * Notice after a bulk publish.
     *
     * @return void
     */
    public function bulk_publish_notice() {
        $map = array(
            'shp_published' => __( '%d محصول منتشر شد.', 'shopharvester-pro' ),
            'shp_locked'    => __( '%d محصول قفل شد.', 'shopharvester-pro' ),
            'shp_unlocked'  => __( 'قفل %d محصول باز شد.', 'shopharvester-pro' ),
        );

        foreach ( $map as $key => $text ) {
            if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                printf(
                    '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                    esc_html( sprintf( $text, (int) $_GET[ $key ] ) ) // phpcs:ignore
                );
            }
        }
    }

    /**
     * Warn when AI enrichment is enabled but the selected provider has no key.
     *
     * @return void
     */
    public function ai_key_notice() {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if ( 0 !== strpos( $page, self::SLUG ) ) {
            return;
        }
        if ( ! Settings::get( 'ai_enabled' ) ) {
            return;
        }

        $provider = (string) Settings::get( 'ai_provider' );
        $engine   = new AI_Engine();
        if ( $engine->has_credentials( $provider ) ) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__( 'غنی‌سازی هوش مصنوعی روشن است اما کلید API ارائه‌دهنده تنظیم نشده — محصولات با داده‌ی خام ذخیره می‌شوند.', 'shopharvester-pro' ),
            esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) ),
            esc_html__( 'رفتن به تنظیمات', 'shopharvester-pro' )
        );
    }

    /**
     * Restrict the WooCommerce products list to harvested items when the
     * ShopHarvester "محصولات برداشت‌شده" menu link is used (?shp_harvested=1).
     *
     * @param \WP_Query $query Current query.
     * @return void
     */
    public function filter_harvested_products( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }
        if ( empty( $_GET['shp_harvested'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'edit-product' !== $screen->id ) {
            return;
        }

        $meta_query   = (array) $query->get( 'meta_query' );
        $meta_query[] = array( 'key' => '_shp_source_url_hash', 'compare' => 'EXISTS' );
        $query->set( 'meta_query', $meta_query );
    }

    /**
     * Add a "برداشت‌شده" filter link to the WooCommerce products list views.
     *
     * @param array $views Existing view links.
     * @return array
     */
    public function harvested_products_view_link( $views ) {
        global $wpdb;

        $count = (int) $wpdb->get_var( // phpcs:ignore
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_shp_source_url_hash'
             WHERE p.post_type = 'product' AND p.post_status <> 'trash'"
        );

        $current = ! empty( $_GET['shp_harvested'] ) ? ' class="current" aria-current="page"' : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $url     = admin_url( 'edit.php?post_type=product&shp_harvested=1' );

        $views['shp_harvested'] = sprintf(
            '<a href="%s"%s>%s <span class="count">(%s)</span></a>',
            esc_url( $url ),
            $current,
            esc_html__( 'برداشت‌شده', 'shopharvester-pro' ),
            esc_html( number_format_i18n( $count ) )
        );

        return $views;
    }

    /**
     * AJAX: publish every draft/pending product of one store.
     *
     * @return void
     */
    public function ajax_publish_store_drafts() {
        $this->guard();

        $store_id = isset( $_POST['store_id'] ) ? (int) $_POST['store_id'] : 0;
        $term_id  = (int) get_post_meta( $store_id, '_shp_store_term_id', true );

        if ( ! $term_id ) {
            wp_send_json_error( array( 'message' => __( 'فروشگاه یافت نشد.', 'shopharvester-pro' ) ) );
        }

        $ids = get_posts(
            array(
                'post_type'   => Entity_Manager::product_post_type(),
                'post_status' => array( 'draft', 'pending' ),
                'numberposts' => -1,
                'fields'      => 'ids',
                'tax_query'   => array(
                    array(
                        'taxonomy' => Entity_Manager::TAX_STORE,
                        'field'    => 'term_id',
                        'terms'    => array( $term_id ),
                    ),
                ),
            )
        );

        $count = 0;
        foreach ( $ids as $post_id ) {
            $result = wp_update_post( array( 'ID' => (int) $post_id, 'post_status' => 'publish' ), true );
            if ( ! is_wp_error( $result ) ) {
                $count++;
            }
        }

        Logger::info(
            sprintf( __( '%1$d محصول فروشگاه #%2$d منتشر شد.', 'shopharvester-pro' ), $count, $store_id ),
            array(),
            array( 'channel' => 'entities' )
        );

        wp_send_json_success( array( 'message' => sprintf( __( '%d محصول منتشر شد.', 'shopharvester-pro' ), $count ) ) );
    }

    /**
     * AJAX: publish a batch of draft/pending harvested products across every
     * store. The browser calls this repeatedly until `remaining` is 0.
     *
     * @return void
     */
    public function ajax_publish_all_drafts() {
        $this->guard();

        $batch = 100;

        $ids = get_posts(
            array(
                'post_type'      => Entity_Manager::product_post_type(),
                'post_status'    => array( 'draft', 'pending' ),
                'posts_per_page' => $batch,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => array(
                    array( 'key' => '_shp_source_url_hash', 'compare' => 'EXISTS' ),
                ),
            )
        );

        $count = 0;
        foreach ( $ids as $post_id ) {
            $result = wp_update_post( array( 'ID' => (int) $post_id, 'post_status' => 'publish' ), true );
            if ( ! is_wp_error( $result ) ) {
                $count++;
            }
        }

        $remaining = (int) ( new \WP_Query(
            array(
                'post_type'      => Entity_Manager::product_post_type(),
                'post_status'    => array( 'draft', 'pending' ),
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array( 'key' => '_shp_source_url_hash', 'compare' => 'EXISTS' ),
                ),
            )
        ) )->found_posts;

        if ( $count ) {
            Logger::info(
                sprintf( __( '%d محصول برداشت‌شده منتشر شد (انتشار گروهی همه فروشگاه‌ها).', 'shopharvester-pro' ), $count ),
                array(),
                array( 'channel' => 'entities' )
            );
        }

        wp_send_json_success(
            array(
                'published' => $count,
                'remaining' => $remaining,
            )
        );
    }

    /**
     * AJAX: remove any product category that is really a store name (created by
     * the old "nest categories under the store" option). Children are moved up
     * to the store category's parent; products keep their real categories.
     *
     * @return void
     */
    public function ajax_purge_store_categories() {
        $this->guard();

        $store_names = array();
        foreach ( get_posts( array( 'post_type' => Entity_Manager::STORE_CPT, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $sid ) {
            $title = trim( wp_strip_all_tags( (string) get_the_title( $sid ) ) );
            if ( '' !== $title ) {
                $store_names[ $this->norm_name( $title ) ] = $title;
            }
        }

        $removed   = 0;
        $unhooked  = 0;
        $taxes     = array_filter( array( 'product_cat', Entity_Manager::TAX_CAT ), 'taxonomy_exists' );

        foreach ( $taxes as $tax ) {
            $terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
            if ( is_wp_error( $terms ) ) {
                continue;
            }
            foreach ( $terms as $term ) {
                if ( ! isset( $store_names[ $this->norm_name( $term->name ) ] ) ) {
                    continue;
                }

                // Move direct children up to this term's parent.
                foreach ( get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'parent' => $term->term_id ) ) as $child ) {
                    wp_update_term( $child->term_id, $tax, array( 'parent' => (int) $term->parent ) );
                    $unhooked++;
                }

                // Detach the store term from every product that carries it.
                $obj_ids = get_objects_in_term( $term->term_id, $tax );
                if ( ! is_wp_error( $obj_ids ) ) {
                    foreach ( $obj_ids as $pid ) {
                        wp_remove_object_terms( (int) $pid, (int) $term->term_id, $tax );
                    }
                }

                wp_delete_term( $term->term_id, $tax );
                $removed++;
            }
        }

        Logger::info(
            sprintf( __( '%1$d دسته‌ی هم‌نام با فروشگاه حذف شد؛ %2$d زیردسته یک سطح بالا آمد.', 'shopharvester-pro' ), $removed, $unhooked ),
            array(),
            array( 'channel' => 'entities' )
        );

        wp_send_json_success(
            array(
                'message' => sprintf( __( '%1$d دسته‌ی فروشگاهی حذف شد، %2$d زیردسته جابه‌جا شد.', 'shopharvester-pro' ), $removed, $unhooked ),
            )
        );
    }

    /**
     * AJAX: merge product categories that share the exact same name but were
     * built as separate branches (the operator's own category plus one or
     * more the harvester created at a different spot in the tree, from before
     * v3.4.7's cross-branch reuse). Same-taxonomy only, exact normalized name.
     * Keeps the oldest (lowest term ID) term per name group; moves every other
     * group member's children and posts onto it, then deletes it.
     *
     * @return void
     */
    public function ajax_merge_duplicate_categories() {
        $this->guard();

        $taxes   = array_filter( array( 'product_cat', Entity_Manager::TAX_CAT ), 'taxonomy_exists' );
        $merged  = 0;
        $moved   = 0;

        foreach ( $taxes as $tax ) {
            $terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'orderby' => 'term_id', 'order' => 'ASC' ) );
            if ( is_wp_error( $terms ) ) {
                continue;
            }

            $groups = array();
            foreach ( $terms as $term ) {
                $key = $this->norm_name( $term->name );
                if ( '' === $key ) {
                    continue;
                }
                $groups[ $key ][] = $term;
            }

            foreach ( $groups as $group ) {
                if ( count( $group ) < 2 ) {
                    continue;
                }

                $primary = $group[0]; // lowest term_id = oldest.
                foreach ( array_slice( $group, 1 ) as $dupe ) {
                    // Never merge a term into its own descendant/ancestor —
                    // that would need a real hierarchy edit, not a merge.
                    if ( $this->is_term_ancestor( (int) $dupe->term_id, (int) $primary->term_id, $tax )
                        || $this->is_term_ancestor( (int) $primary->term_id, (int) $dupe->term_id, $tax ) ) {
                        continue;
                    }

                    foreach ( get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'parent' => $dupe->term_id ) ) as $child ) {
                        wp_update_term( $child->term_id, $tax, array( 'parent' => (int) $primary->term_id ) );
                        $moved++;
                    }

                    $obj_ids = get_objects_in_term( $dupe->term_id, $tax );
                    if ( ! is_wp_error( $obj_ids ) ) {
                        foreach ( $obj_ids as $pid ) {
                            wp_set_object_terms( (int) $pid, array( (int) $primary->term_id ), $tax, true );
                            wp_remove_object_terms( (int) $pid, (int) $dupe->term_id, $tax );
                        }
                    }

                    wp_delete_term( $dupe->term_id, $tax );
                    $merged++;
                }
            }
        }

        Logger::info(
            sprintf( __( '%1$d دسته‌ی تکراری ادغام شد؛ %2$d زیردسته جابه‌جا شد.', 'shopharvester-pro' ), $merged, $moved ),
            array(),
            array( 'channel' => 'entities' )
        );

        wp_send_json_success(
            array( 'message' => sprintf( __( '%1$d دسته‌ی تکراری ادغام شد، %2$d زیردسته جابه‌جا شد.', 'shopharvester-pro' ), $merged, $moved ) )
        );
    }

    /**
     * Whether $maybe_ancestor is an ancestor of $term_id in the given taxonomy.
     *
     * @param int    $maybe_ancestor Candidate ancestor term ID.
     * @param int    $term_id        Term to walk up from.
     * @param string $taxonomy       Taxonomy.
     * @return bool
     */
    private function is_term_ancestor( $maybe_ancestor, $term_id, $taxonomy ) {
        $guard = 0;
        while ( $term_id && $guard++ < 50 ) {
            $term = get_term( $term_id, $taxonomy );
            if ( ! $term || is_wp_error( $term ) || ! $term->parent ) {
                return false;
            }
            if ( (int) $term->parent === (int) $maybe_ancestor ) {
                return true;
            }
            $term_id = (int) $term->parent;
        }

        return false;
    }

    /**
     * AJAX: repair harvested WooCommerce products whose price is two amounts
     * glued together with no separator (a since-fixed scraper bug: an
     * old/new discount pair rendered as sibling elements produced e.g.
     * "3798000" + "3420000" -> "37980003420000"). Only touches values that
     * cleanly split into two plausible Toman amounts of equal digit length —
     * anything else is left alone for a re-scrape. Batched; the browser
     * repeats the call until `remaining` is 0.
     *
     * @return void
     */
    public function ajax_repair_glued_prices() {
        $this->guard();

        if ( ! function_exists( 'wc_get_product' ) ) {
            wp_send_json_error( array( 'message' => __( 'ووکامرس فعال نیست.', 'shopharvester-pro' ) ) );
        }

        $q = new \WP_Query(
            array(
                'post_type'      => 'product',
                'post_status'    => array( 'publish', 'draft', 'pending' ),
                'posts_per_page' => 300,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'meta_query'     => array(
                    array( 'key' => '_shp_source_url_hash', 'compare' => 'EXISTS' ),
                    array( 'key' => '_shp_price_repair_checked', 'compare' => 'NOT EXISTS' ),
                ),
            )
        );

        $checked = 0;
        $fixed   = 0;

        foreach ( $q->posts as $pid ) {
            $checked++;
            $product = wc_get_product( $pid );
            if ( $product ) {
                $before_regular = (string) $product->get_regular_price( 'edit' );
                $before_sale    = (string) $product->get_sale_price( 'edit' );
                $split          = $this->split_glued_price( $before_regular );
                $touched        = false;

                if ( $split ) {
                    $product->set_regular_price( (string) $split['regular'] );
                    $product->set_sale_price( $split['sale'] ? (string) $split['sale'] : '' );
                    $touched = true;
                } else {
                    $split_sale = $this->split_glued_price( $before_sale );
                    if ( $split_sale ) {
                        $product->set_sale_price( (string) $split_sale['sale'] );
                        if ( '' === $before_regular ) {
                            $product->set_regular_price( (string) $split_sale['regular'] );
                        }
                        $touched = true;
                    }
                }

                if ( $touched ) {
                    $product->save();
                    update_post_meta( $pid, '_shp_price_repair_log', array( 'before_regular' => $before_regular, 'before_sale' => $before_sale ) );
                    $fixed++;
                }
            }
            update_post_meta( $pid, '_shp_price_repair_checked', 1 );
        }

        $remaining = (int) ( new \WP_Query(
            array(
                'post_type'      => 'product',
                'post_status'    => array( 'publish', 'draft', 'pending' ),
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array( 'key' => '_shp_source_url_hash', 'compare' => 'EXISTS' ),
                    array( 'key' => '_shp_price_repair_checked', 'compare' => 'NOT EXISTS' ),
                ),
            )
        ) )->found_posts;

        if ( $fixed ) {
            Logger::info(
                sprintf( __( '%d محصولِ برداشت‌شده با قیمتِ به‌هم‌چسبیده اصلاح شد.', 'shopharvester-pro' ), $fixed ),
                array(),
                array( 'channel' => 'entities' )
            );
        }

        wp_send_json_success( array( 'checked' => $checked, 'fixed' => $fixed, 'remaining' => $remaining ) );
    }

    /**
     * Try to split a price string that is really two Toman amounts glued
     * together with no separator into a plausible (regular, sale) pair.
     * Deliberately conservative: only fires on an even-length digit string
     * whose two equal-length halves are both plausible retail prices.
     *
     * @param string $raw Stored price string.
     * @return array{regular:int,sale:int}|null
     */
    private function split_glued_price( $raw ) {
        $digits = preg_replace( '/[^0-9]/', '', (string) $raw );
        $len    = strlen( $digits );

        // A real single Toman price on this kind of site never reaches 10
        // digits; anything that long and evenly splittable is the tell.
        if ( $len < 12 || $len > 18 || 0 !== $len % 2 ) {
            return null;
        }

        $half = $len / 2;
        $a    = (int) substr( $digits, 0, $half );
        $b    = (int) substr( $digits, $half );

        $plausible = static function ( $n ) {
            return $n >= 10000 && $n <= 50000000;
        };
        if ( $a === $b || ! $plausible( $a ) || ! $plausible( $b ) ) {
            return null;
        }

        $regular = max( $a, $b );
        $sale    = min( $a, $b );

        return array(
            'regular' => $regular,
            'sale'    => ( $sale < $regular * 0.98 ) ? $sale : 0,
        );
    }

    /**
     * Loose name key for matching a category to a store title.
     *
     * @param string $name Name.
     * @return string
     */
    private function norm_name( $name ) {
        $name = strtr( (string) $name, array( 'ي' => 'ی', 'ك' => 'ک', 'ٔ' => '', '‌' => ' ', '‏' => '', '‎' => '' ) );
        $name = preg_replace( '/\s+/u', ' ', $name );

        return trim( mb_strtolower( $name ) );
    }

    /**
     * AJAX: trash a batch of per-product آگهی (rtcl_listing) entries the plugin
     * created, keeping the one-per-shop directory listings (`_shp_is_shop`).
     * The browser repeats the call until `remaining` is 0.
     *
     * @return void
     */
    public function ajax_purge_product_listings() {
        $this->guard();

        if ( ! post_type_exists( 'rtcl_listing' ) ) {
            wp_send_json_error( array( 'message' => __( 'افزونه‌ی آگهی روی سایت فعال نیست.', 'shopharvester-pro' ) ) );
        }

        $query = new \WP_Query(
            array(
                'post_type'      => 'rtcl_listing',
                'post_status'    => 'any',
                'posts_per_page' => 100,
                'fields'         => 'ids',
                'meta_query'     => array(
                    'relation' => 'AND',
                    array( 'key' => '_shp_is_listing', 'compare' => 'EXISTS' ),
                    array( 'key' => '_shp_is_shop', 'compare' => 'NOT EXISTS' ),
                ),
            )
        );

        $count = 0;
        foreach ( $query->posts as $post_id ) {
            if ( wp_trash_post( (int) $post_id ) ) {
                $count++;
            }
        }

        $remaining = max( 0, (int) $query->found_posts - $count );

        if ( $count ) {
            Logger::info(
                sprintf( __( '%d آگهیِ تکیِ محصول به زباله‌دان منتقل شد.', 'shopharvester-pro' ), $count ),
                array(),
                array( 'channel' => 'entities' )
            );
        }

        wp_send_json_success( array( 'trashed' => $count, 'remaining' => $remaining ) );
    }

    /**
     * AJAX: run the SEO optimiser over harvested products in batches. The
     * browser repeats the call until `remaining` is 0. `force` re-runs on
     * products already processed.
     *
     * @return void
     */
    public function ajax_seo_optimize_all() {
        $this->guard();

        // "force" is a one-shot reset: clear every processed-marker, then the
        // normal NOT-EXISTS cursor walks the whole set again.
        if ( ! empty( $_POST['force'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            global $wpdb;
            $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_shp_seo_applied_at'" ); // phpcs:ignore
            wp_send_json_success( array( 'processed' => 0, 'remaining' => -1, 'reset' => true ) );
        }

        $ai_engine = new AI_Engine();
        $ai_on     = (bool) Settings::get( 'ai_enabled' ) && $ai_engine->has_credentials( Settings::get( 'ai_provider' ) );
        // AI calls are slow; keep the batch small when enrichment is on.
        $batch = $ai_on ? 8 : 40;

        $q = new \WP_Query(
            array(
                'post_type'      => Entity_Manager::product_post_type(),
                'post_status'    => array( 'publish', 'draft', 'pending' ),
                'posts_per_page' => $batch,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'meta_query'     => array(
                    array( 'key' => '_shp_source_url_hash', 'compare' => 'EXISTS' ),
                    array( 'key' => '_shp_seo_applied_at', 'compare' => 'NOT EXISTS' ),
                ),
            )
        );

        $optimizer = new Seo_Optimizer();
        $done      = 0;
        $facts     = array();

        foreach ( $q->posts as $product_id ) {
            $product_id = (int) $product_id;

            $enrichment = array();
            if ( $ai_on ) {
                $facts = $this->facts_from_product( $product_id );
                $out   = $ai_engine->enrich( $facts );
                if ( is_array( $out ) ) {
                    $enrichment = $out;
                }
            }

            $optimizer->apply( $product_id, $enrichment, $facts );
            // Guarantee the marker so the batch cursor advances even if apply()
            // bailed (locked product, etc.).
            if ( '' === (string) get_post_meta( $product_id, '_shp_seo_applied_at', true ) ) {
                update_post_meta( $product_id, '_shp_seo_applied_at', current_time( 'mysql' ) );
            }
            $done++;
        }

        $remaining = (int) ( new \WP_Query(
            array(
                'post_type'      => Entity_Manager::product_post_type(),
                'post_status'    => array( 'publish', 'draft', 'pending' ),
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array( 'key' => '_shp_source_url_hash', 'compare' => 'EXISTS' ),
                    array( 'key' => '_shp_seo_applied_at', 'compare' => 'NOT EXISTS' ),
                ),
            )
        ) )->found_posts;

        if ( $done ) {
            Logger::info(
                sprintf( __( 'سئوی %d محصول برداشت‌شده بهینه شد%s.', 'shopharvester-pro' ), $done, $ai_on ? __( ' (با هوش مصنوعی)', 'shopharvester-pro' ) : '' ),
                array(),
                array( 'channel' => 'yoast' )
            );
        }

        wp_send_json_success(
            array(
                'processed' => $done,
                'remaining' => $remaining,
                'ai'        => $ai_on,
            )
        );
    }

    /**
     * Rebuild a minimal facts array for a stored product (for AI enrichment
     * during the SEO backfill).
     *
     * @param int $product_id Product ID.
     * @return array
     */
    private function facts_from_product( $product_id ) {
        $store_id   = (int) get_post_meta( $product_id, '_shp_store_id', true );
        $attributes = get_post_meta( $product_id, '_shp_attributes', true );
        $cats       = array();
        foreach ( array( 'product_cat', Entity_Manager::TAX_CAT ) as $tax ) {
            if ( taxonomy_exists( $tax ) ) {
                $terms = get_the_terms( $product_id, $tax );
                if ( is_array( $terms ) ) {
                    foreach ( $terms as $t ) {
                        $cats[] = $t->name;
                    }
                }
            }
        }

        return array(
            'title'       => get_the_title( $product_id ),
            'brand'       => (string) get_post_meta( $product_id, '_shp_brand', true ),
            'price'       => (string) get_post_meta( $product_id, '_shp_price', true ),
            'categories'  => array_values( array_unique( $cats ) ),
            'attributes'  => is_array( $attributes ) ? $attributes : array(),
            'description' => (string) get_post_field( 'post_content', $product_id ),
            'store_name'  => $store_id ? wp_strip_all_tags( (string) get_the_title( $store_id ) ) : '',
            'source_url'  => (string) get_post_meta( $product_id, '_shp_source_url', true ),
            'images'      => array(),
        );
    }

    /**
     * AJAX: move every product of one store to trash (for re-testing).
     *
     * @return void
     */
    public function ajax_delete_store_products() {
        $this->guard();

        $store_id = isset( $_POST['store_id'] ) ? (int) $_POST['store_id'] : 0;
        $term_id  = (int) get_post_meta( $store_id, '_shp_store_term_id', true );

        if ( ! $term_id ) {
            wp_send_json_error( array( 'message' => __( 'فروشگاه یافت نشد.', 'shopharvester-pro' ) ) );
        }

        $ids = get_posts(
            array(
                'post_type'   => Entity_Manager::product_post_type(),
                'post_status' => 'any',
                'numberposts' => -1,
                'fields'      => 'ids',
                'tax_query'   => array(
                    array(
                        'taxonomy' => Entity_Manager::TAX_STORE,
                        'field'    => 'term_id',
                        'terms'    => array( $term_id ),
                    ),
                ),
            )
        );

        $count = 0;
        foreach ( $ids as $post_id ) {
            if ( wp_trash_post( (int) $post_id ) ) {
                $count++;
            }
        }

        // Also drop de-dup hashes so a fresh run re-imports cleanly.
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'shp_product_hashes', array( 'store_id' => $store_id ) ); // phpcs:ignore

        Logger::info(
            sprintf( __( '%1$d محصول فروشگاه #%2$d به زباله‌دان منتقل شد.', 'shopharvester-pro' ), $count, $store_id ),
            array(),
            array( 'channel' => 'entities' )
        );

        wp_send_json_success( array( 'message' => sprintf( __( '%d محصول به زباله‌دان منتقل شد.', 'shopharvester-pro' ), $count ) ) );
    }

    /**
     * Enqueue CSS/JS on our screens only.
     *
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function assets( $hook ) {
        $on_plugin_screen = false !== strpos( (string) $hook, self::SLUG );
        $on_cpt_screen    = in_array( get_current_screen() ? get_current_screen()->post_type : '', array( Entity_Manager::PRODUCT_CPT, Entity_Manager::STORE_CPT ), true );

        if ( ! $on_plugin_screen && ! $on_cpt_screen ) {
            return;
        }

        wp_enqueue_style(
            'shp-admin',
            SHP_PLUGIN_URL . 'admin/css/admin.css',
            array(),
            SHP_VERSION
        );

        wp_enqueue_script(
            'shp-admin',
            SHP_PLUGIN_URL . 'admin/js/admin.js',
            array( 'jquery' ),
            SHP_VERSION,
            true
        );

        wp_localize_script(
            'shp-admin',
            'SHP',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( self::NONCE ),
                'i18n'    => array(
                    'submitting' => __( 'در حال ارسال…', 'shopharvester-pro' ),
                    'done'       => __( 'انجام شد', 'shopharvester-pro' ),
                    'error'      => __( 'خطا رخ داد', 'shopharvester-pro' ),
                    'confirm'    => __( 'مطمئن هستید؟', 'shopharvester-pro' ),
                ),
            )
        );
    }

    /* --------------------------------------------------------------------- *
     *  Page routing / rendering.
     * --------------------------------------------------------------------- */

    /**
     * Resolve the active tab from the current page slug.
     *
     * @return string
     */
    private function current_tab() {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::SLUG; // phpcs:ignore WordPress.Security.NonceVerification
        $tab  = str_replace( self::SLUG . '-', '', $page );
        return in_array( $tab, array( 'help', 'runner', 'stores', 'mappings', 'logs', 'report', 'settings' ), true ) ? $tab : 'dashboard';
    }

    /**
     * Render the requested admin view.
     *
     * @return void
     */
    public function render_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'دسترسی کافی ندارید.', 'shopharvester-pro' ) );
        }

        $tab   = $this->current_tab();
        $views = SHP_PLUGIN_DIR . 'admin/views/';

        $context = array(
            'tab'      => $tab,
            'jobs'     => $this->jobs,
            'stats'    => $this->jobs->dashboard_stats(),
            'stores'   => Subdomain_Manager::all_stores(),
            'settings' => Settings::all(),
            'base_url' => admin_url( 'admin.php?page=' . self::SLUG ),
            'nonce'    => wp_create_nonce( self::NONCE ),
        );

        echo '<div class="wrap shp-wrap" dir="rtl">';
        $this->render_header( $tab );

        $file = $views . $tab . '.php';
        if ( is_readable( $file ) ) {
            ( static function ( $__file, $__ctx ) {
                extract( $__ctx, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
                include $__file;
            } )( $file, $context );
        } else {
            echo '<p>' . esc_html__( 'نمای درخواستی یافت نشد.', 'shopharvester-pro' ) . '</p>';
        }

        echo '</div>';
    }

    /**
     * Shared tabbed header.
     *
     * @param string $active Active tab key.
     * @return void
     */
    private function render_header( $active ) {
        $tabs = array(
            'dashboard' => array( __( 'داشبورد', 'shopharvester-pro' ), self::SLUG ),
            'runner'    => array( __( 'اجرای برداشت', 'shopharvester-pro' ), self::SLUG . '-runner' ),
            'stores'    => array( __( 'خلاصه فروشگاه‌ها', 'shopharvester-pro' ), self::SLUG . '-stores' ),
            'mappings'  => array( __( 'نگاشت دسته‌ها', 'shopharvester-pro' ), self::SLUG . '-mappings' ),
            'logs'      => array( __( 'گزارش‌ها', 'shopharvester-pro' ), self::SLUG . '-logs' ),
            'report'    => array( __( 'گزارش سیستم', 'shopharvester-pro' ), self::SLUG . '-report' ),
            'settings'  => array( __( 'تنظیمات', 'shopharvester-pro' ), self::SLUG . '-settings' ),
            'help'      => array( __( 'راهنما', 'shopharvester-pro' ), self::SLUG . '-help' ),
        );

        echo '<div class="shp-topbar">';
        echo '<div class="shp-brand"><span class="shp-logo">'
            . '<img src="' . esc_url( SHP_PLUGIN_URL . 'assets/icon.svg' ) . '" width="30" height="30" alt="" />'
            . '</span>'
            . '<div><strong>برداشت‌گر فروشگاه پرو</strong><small>نسخه ' . esc_html( SHP_VERSION ) . '</small></div></div>';
        echo '<nav class="shp-tabs">';
        foreach ( $tabs as $key => $tab ) {
            printf(
                '<a href="%s" class="shp-tab%s">%s</a>',
                esc_url( admin_url( 'admin.php?page=' . $tab[1] ) ),
                $key === $active ? ' is-active' : '',
                esc_html( $tab[0] )
            );
        }
        echo '</nav>';
        echo '</div>';
    }

    /* --------------------------------------------------------------------- *
     *  AJAX handlers.
     * --------------------------------------------------------------------- */

    /**
     * Verify nonce + capability for every AJAX call.
     *
     * @return void
     */
    private function guard() {
        if ( ! current_user_can( self::CAPABILITY ) || ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'درخواست نامعتبر است.', 'shopharvester-pro' ) ), 403 );
        }
    }

    /**
     * Handle the runner form submission.
     *
     * @return void
     */
    public function ajax_submit_run() {
        $this->guard();

        $result = $this->build_jobs(
            isset( $_POST['urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['urls'] ) ) : '',
            isset( $_POST['subdomain'] ) ? sanitize_text_field( wp_unslash( $_POST['subdomain'] ) ) : '',
            isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '' // phpcs:ignore
        );

        if ( 'ok' !== $result['status'] ) {
            wp_send_json_error( array( 'message' => $result['message'] ) );
        }

        wp_send_json_success(
            array(
                'message' => sprintf(
                    __( '%d کار در صف قرار گرفت و در پس‌زمینه پردازش می‌شود. برای دیدن نتیجه چند لحظه صبر کنید.', 'shopharvester-pro' ),
                    count( $result['created'] )
                ),
                'jobs'    => $result['created'],
            )
        );
    }

    /**
     * Non-AJAX fallback: some hardened hosts (security firewalls) block
     * admin-ajax POSTs. This handles a plain form submit to admin-post.php.
     *
     * @return void
     */
    public function handle_post_submit_run() {
        if ( ! current_user_can( self::CAPABILITY ) || ! check_admin_referer( 'shp_submit_run_form', 'shp_form_nonce' ) ) {
            wp_die( esc_html__( 'درخواست نامعتبر است.', 'shopharvester-pro' ) );
        }

        $result = $this->build_jobs(
            isset( $_POST['urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['urls'] ) ) : '',
            isset( $_POST['subdomain'] ) ? sanitize_text_field( wp_unslash( $_POST['subdomain'] ) ) : '',
            isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '' // phpcs:ignore
        );

        $flag = in_array( $result['status'], array( 'ok', 'db', 'empty', 'json' ), true ) ? $result['status'] : 'empty';
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '-runner&shp_submitted=' . $flag ) );
        exit;
    }

    /**
     * Shared job-creation logic for both submit paths.
     *
     * @param string $urls_raw  Newline-separated URLs.
     * @param string $subdomain Store slug.
     * @param string $payload   Raw JSON payload (optional).
     * @return array { status, message, created[] }
     */
    private function build_jobs( $urls_raw, $subdomain, $payload ) {
        $urls = array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', (string) $urls_raw ) ) );

        if ( empty( $urls ) && '' === trim( (string) $payload ) ) {
            return array( 'status' => 'empty', 'message' => __( 'حداقل یک URL یا یک محموله JSON وارد کنید.', 'shopharvester-pro' ), 'created' => array() );
        }

        if ( ! \ShopHarvesterPro\Includes\Activator::tables_exist() ) {
            \ShopHarvesterPro\Includes\Core::instance()->maybe_upgrade_db();
            if ( ! \ShopHarvesterPro\Includes\Activator::tables_exist() ) {
                return array( 'status' => 'db', 'message' => __( 'جدول‌های دیتابیس افزونه وجود ندارند. افزونه را یک‌بار غیرفعال و دوباره فعال کنید.', 'shopharvester-pro' ), 'created' => array() );
            }
        }

        $created = array();

        if ( '' !== trim( (string) $payload ) ) {
            $decoded = json_decode( $payload, true );
            if ( ! is_array( $decoded ) ) {
                return array( 'status' => 'json', 'message' => __( 'محموله JSON معتبر نیست.', 'shopharvester-pro' ), 'created' => array() );
            }
            $created[] = $this->jobs->create(
                array(
                    'target_url' => $decoded['store']['source_url'] ?? '',
                    'subdomain'  => $subdomain,
                    'payload'    => array(
                        'store'    => $decoded['store'] ?? array(),
                        'products' => $decoded['products'] ?? array(),
                    ),
                )
            );
        }

        foreach ( $urls as $url ) {
            $url = esc_url_raw( $url );
            if ( ! $url ) {
                continue;
            }
            $created[] = $this->jobs->create( array( 'target_url' => $url, 'subdomain' => $subdomain, 'status' => 'queued' ) );
        }

        $created = array_values( array_filter( $created ) );

        if ( empty( $created ) ) {
            return array( 'status' => 'db', 'message' => __( 'ثبت کار ناموفق بود. به «گزارش سیستم» بخش «Database tables» بروید.', 'shopharvester-pro' ), 'created' => array() );
        }

        $this->jobs->schedule_soon();

        return array( 'status' => 'ok', 'message' => '', 'created' => $created );
    }

    /**
     * Retry a failed job.
     *
     * @return void
     */
    public function ajax_retry_job() {
        $this->guard();
        $job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;

        if ( $this->jobs->retry( $job_id ) ) {
            wp_send_json_success( array( 'message' => __( 'کار برای اجرای مجدد در صف قرار گرفت.', 'shopharvester-pro' ) ) );
        }

        wp_send_json_error( array( 'message' => __( 'کار یافت نشد.', 'shopharvester-pro' ) ) );
    }

    /**
     * Force-run a single job now.
     *
     * @return void
     */
    public function ajax_run_job() {
        $this->guard();
        $job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
        $job    = $this->jobs->get( $job_id );

        if ( ! $job ) {
            wp_send_json_error( array( 'message' => __( 'کار یافت نشد.', 'shopharvester-pro' ) ) );
        }

        $stats = $this->jobs->process_one( $job );
        wp_send_json_success(
            array(
                'message' => __( 'پردازش کار انجام شد.', 'shopharvester-pro' ),
                'stats'   => $stats,
            )
        );
    }

    /**
     * Register the "shop offers" metabox on product edit screens.
     *
     * @return void
     */
    public function register_offer_metabox() {
        if ( ! \ShopHarvesterPro\Includes\Settings::get( 'marketplace_mode' ) ) {
            return;
        }
        foreach ( array_unique( array( Entity_Manager::PRODUCT_CPT, Entity_Manager::product_post_type() ) ) as $pt ) {
            if ( post_type_exists( $pt ) ) {
                add_meta_box( 'shp_offers_box', __( 'پیشنهادهای فروشگاه‌ها (مقایسه قیمت)', 'shopharvester-pro' ), array( $this, 'render_offer_metabox' ), $pt, 'normal', 'default' );
            }
        }
    }

    /**
     * Render the offers metabox.
     *
     * @param \WP_Post $post Post.
     * @return void
     */
    public function render_offer_metabox( $post ) {
        $offers = Offer_Repo::for_product( $post->ID );
        $nonce  = esc_attr( wp_create_nonce( self::NONCE ) );

        echo '<table class="widefat striped shp-offers-box" data-pid="' . (int) $post->ID . '" data-nonce="' . $nonce . '"><thead><tr>'
            . '<th>' . esc_html__( 'فروشگاه', 'shopharvester-pro' ) . '</th><th>' . esc_html__( 'قیمت', 'shopharvester-pro' ) . '</th>'
            . '<th>' . esc_html__( 'موجودی', 'shopharvester-pro' ) . '</th><th>' . esc_html__( 'لینک خرید', 'shopharvester-pro' ) . '</th>'
            . '<th>' . esc_html__( 'آخرین بروزرسانی', 'shopharvester-pro' ) . '</th><th></th></tr></thead><tbody>';

        if ( empty( $offers ) ) {
            echo '<tr><td colspan="6">' . esc_html__( 'هنوز پیشنهادی ثبت نشده است.', 'shopharvester-pro' ) . '</td></tr>';
        }
        foreach ( $offers as $o ) {
            printf(
                '<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td dir="ltr"><a href="%4$s" target="_blank" rel="noreferrer">%5$s</a></td><td>%6$s</td><td><button type="button" class="button-link-delete shp-del-offer" data-offer="%7$d">%8$s</button></td></tr>',
                esc_html( $o->shop_name ?: get_the_title( $o->store_id ) ),
                esc_html( null !== $o->price ? number_format_i18n( (float) $o->price ) : '—' ),
                esc_html( 'outofstock' === $o->availability ? __( 'ناموجود', 'shopharvester-pro' ) : __( 'موجود', 'shopharvester-pro' ) ),
                esc_url( $o->buy_url ),
                esc_html__( 'باز کردن', 'shopharvester-pro' ),
                esc_html( $o->last_seen ),
                (int) $o->id,
                esc_html__( 'حذف', 'shopharvester-pro' )
            );
        }
        echo '</tbody></table>';

        echo '<p class="shp-add-offer">'
            . '<input type="text" class="shp-off-shop" placeholder="' . esc_attr__( 'نام فروشگاه', 'shopharvester-pro' ) . '" /> '
            . '<input type="text" class="shp-off-price" placeholder="' . esc_attr__( 'قیمت (تومان)', 'shopharvester-pro' ) . '" style="width:120px" /> '
            . '<input type="url" class="shp-off-url" placeholder="https://…" dir="ltr" style="width:260px" /> '
            . '<button type="button" class="button shp-add-offer-btn">' . esc_html__( 'افزودن پیشنهاد', 'shopharvester-pro' ) . '</button></p>';

        echo '<script>(function($){$(document).on("click",".shp-del-offer",function(){var $t=$(this),$b=$t.closest(".shp-offers-box");'
            . '$.post(ajaxurl,{action:"shp_delete_offer",nonce:$b.data("nonce"),offer_id:$t.data("offer")},function(){$t.closest("tr").remove();});});'
            . '$(document).on("click",".shp-add-offer-btn",function(){var $b=$(".shp-offers-box");$.post(ajaxurl,{action:"shp_add_offer",nonce:$b.data("nonce"),'
            . 'product_id:$b.data("pid"),shop_name:$(".shp-off-shop").val(),price:$(".shp-off-price").val(),buy_url:$(".shp-off-url").val()},function(){location.reload();});});})(jQuery);</script>';
    }

    /**
     * AJAX: delete one offer.
     *
     * @return void
     */
    public function ajax_delete_offer() {
        $this->guard();
        $id = isset( $_POST['offer_id'] ) ? (int) $_POST['offer_id'] : 0;
        wp_send_json_success( array( 'deleted' => Offer_Repo::delete( $id ) ) );
    }

    /**
     * AJAX: add a manual offer.
     *
     * @return void
     */
    public function ajax_add_offer() {
        $this->guard();
        $pid = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
        if ( ! $pid || ! current_user_can( 'edit_post', $pid ) ) {
            wp_send_json_error( array( 'message' => __( 'دسترسی کافی ندارید.', 'shopharvester-pro' ) ) );
        }
        $id = Offer_Repo::manual_upsert(
            $pid,
            array(
                'shop_name' => sanitize_text_field( wp_unslash( $_POST['shop_name'] ?? '' ) ),
                'price'     => sanitize_text_field( wp_unslash( $_POST['price'] ?? '' ) ),
                'buy_url'   => esc_url_raw( wp_unslash( $_POST['buy_url'] ?? '' ) ),
            )
        );
        wp_send_json_success( array( 'offer_id' => $id ) );
    }

    /**
     * Cancel a stuck / unwanted job.
     *
     * @return void
     */
    public function ajax_cancel_job() {
        $this->guard();
        $job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;

        if ( $this->jobs->cancel( $job_id ) ) {
            wp_send_json_success( array( 'message' => sprintf( __( 'کار #%d لغو شد.', 'shopharvester-pro' ), $job_id ) ) );
        }

        wp_send_json_error( array( 'message' => __( 'کار یافت نشد.', 'shopharvester-pro' ) ) );
    }

    /**
     * Return a page of logs as JSON for the live table.
     *
     * @return void
     */
    public function ajax_fetch_logs() {
        $this->guard();

        $result = Logger::query(
            array(
                'level'    => isset( $_POST['level'] ) ? sanitize_text_field( wp_unslash( $_POST['level'] ) ) : '',
                'channel'  => isset( $_POST['channel'] ) ? sanitize_text_field( wp_unslash( $_POST['channel'] ) ) : '',
                'job_id'   => isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0,
                'search'   => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '',
                'paged'    => isset( $_POST['paged'] ) ? max( 1, (int) $_POST['paged'] ) : 1,
                'per_page' => 25,
            )
        );

        wp_send_json_success( $result );
    }

    /**
     * Stream a CSV export of the current log filter.
     *
     * @return void
     */
    public function ajax_export_logs() {
        if ( ! current_user_can( self::CAPABILITY ) || ! check_admin_referer( self::NONCE, 'nonce' ) ) {
            wp_die( esc_html__( 'درخواست نامعتبر است.', 'shopharvester-pro' ) );
        }

        $csv = Logger::export_csv(
            array(
                'level'   => isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( $_GET['level'] ) ) : '',
                'channel' => isset( $_GET['channel'] ) ? sanitize_text_field( wp_unslash( $_GET['channel'] ) ) : '',
                'search'  => isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '',
            )
        );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=shopharvester-logs-' . gmdate( 'Ymd-His' ) . '.csv' );
        echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel.
        echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /**
     * Stream the plain-text system report as a download.
     *
     * @return void
     */
    public function ajax_download_report() {
        if ( ! current_user_can( self::CAPABILITY ) || ! check_admin_referer( self::NONCE, 'nonce' ) ) {
            wp_die( esc_html__( 'درخواست نامعتبر است.', 'shopharvester-pro' ) );
        }

        nocache_headers();
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=shopharvester-system-report-' . gmdate( 'Ymd-His' ) . '.txt' );
        echo System_Report::generate(); // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /**
     * Stream the full log table as a JSON download.
     *
     * @return void
     */
    public function ajax_download_logs_json() {
        if ( ! current_user_can( self::CAPABILITY ) || ! check_admin_referer( self::NONCE, 'nonce' ) ) {
            wp_die( esc_html__( 'درخواست نامعتبر است.', 'shopharvester-pro' ) );
        }

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=shopharvester-logs-' . gmdate( 'Ymd-His' ) . '.json' );
        echo System_Report::full_log_json(); // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /**
     * Force a queue processing pass now (WP-Cron independent).
     *
     * @return void
     */
    public function ajax_process_queue() {
        $this->guard();

        @set_time_limit( 120 ); // phpcs:ignore
        $result = $this->jobs->run_now();

        wp_send_json_success(
            array(
                'message' => sprintf( __( 'پردازش انجام شد. %d کار در صف باقی مانده.', 'shopharvester-pro' ), (int) $result['pending'] ),
                'pending' => (int) $result['pending'],
            )
        );
    }

    /**
     * Test the AI provider connection, optionally with unsaved form values.
     *
     * @return void
     */
    public function ajax_ai_test() {
        $this->guard();

        @set_time_limit( 60 ); // phpcs:ignore

        $overrides = array( 'ai_enabled' => true );
        foreach ( array( 'ai_provider', 'ai_model', 'openai_api_key', 'gapgpt_api_key', 'gapgpt_base_url', 'gemini_api_key', 'perplexity_api_key' ) as $key ) {
            if ( isset( $_POST[ $key ] ) && '' !== $_POST[ $key ] ) {
                $overrides[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore
            }
        }

        Settings::override( $overrides );

        wp_send_json_success( ( new AI_Engine() )->test() );
    }

    /**
     * Outbound connectivity self-test.
     *
     * @return void
     */
    public function ajax_network_test() {
        $this->guard();

        @set_time_limit( 90 ); // phpcs:ignore
        $custom = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

        wp_send_json_success(
            array( 'results' => System_Report::network_test( $custom ? array( $custom ) : array() ) )
        );
    }

    /**
     * Persist settings from the settings form.
     *
     * @return void
     */
    public function ajax_save_settings() {
        $this->guard();

        $raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array(); // phpcs:ignore
        Settings::update( Settings::sanitize( $raw ) );
        $this->jobs->maybe_schedule_resync();

        Logger::info( __( 'تنظیمات افزونه بروزرسانی شد.', 'shopharvester-pro' ), array(), array( 'channel' => 'settings' ) );

        wp_send_json_success( array( 'message' => __( 'تنظیمات ذخیره شد.', 'shopharvester-pro' ) ) );
    }

    /**
     * Persist the category name map from the mapping screen.
     *
     * @return void
     */
    public function ajax_save_mappings() {
        $this->guard();

        $raw   = isset( $_POST['map'] ) ? wp_unslash( $_POST['map'] ) : '[]'; // phpcs:ignore
        $pairs = json_decode( (string) $raw, true );

        if ( ! is_array( $pairs ) ) {
            wp_send_json_error( array( 'message' => __( 'داده نگاشت معتبر نیست.', 'shopharvester-pro' ) ) );
        }

        $flat = array();
        foreach ( $pairs as $pair ) {
            if ( isset( $pair['source'] ) ) {
                $flat[ (string) $pair['source'] ] = (string) ( $pair['target'] ?? '' );
            }
        }

        $count = Taxonomy_Mapper::save_map( $flat );

        Logger::info(
            sprintf( __( 'نگاشت دسته‌ها ذخیره شد (%d قانون).', 'shopharvester-pro' ), $count ),
            array(),
            array( 'channel' => 'settings' )
        );

        wp_send_json_success( array( 'message' => sprintf( __( '%d قانون ذخیره شد.', 'shopharvester-pro' ), $count ) ) );
    }

    /**
     * Return refreshed dashboard counters.
     *
     * @return void
     */
    public function ajax_dashboard_stats() {
        $this->guard();
        wp_send_json_success( $this->jobs->dashboard_stats() );
    }
}
