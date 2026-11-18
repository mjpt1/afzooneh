<?php
/**
 * Subdomain routing and per-store query partitioning.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Turns `store-slug.mainsite.com` into a filtered view of a single store,
 * while the bare main domain keeps a unified cross-store catalogue and search.
 */
class Subdomain_Manager {

    /**
     * Resolved store post ID for the current request, or 0 for the main site.
     *
     * @var int
     */
    private $current_store_id = 0;

    /**
     * Resolved store slug for the current request.
     *
     * @var string
     */
    private $current_store_slug = '';

    /**
     * Register the rewrite tag/rule for virtual subdomain mode.
     *
     * @return void
     */
    public function register_rewrite() {
        add_rewrite_tag( '%shp_store%', '([^&]+)' );
    }

    /**
     * Inspect the requested host and, if it is a store subdomain, remember
     * which store we are on for the rest of the request.
     *
     * @return void
     */
    public function detect_current_store() {
        $host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
        $host = preg_replace( '/:\d+$/', '', $host );
        if ( '' === $host ) {
            return;
        }

        $base = strtolower( Settings::get( 'base_domain' ) );
        if ( '' === $base || $host === $base || $host === 'www.' . $base ) {
            return;
        }

        $suffix = '.' . $base;
        if ( substr( $host, -strlen( $suffix ) ) !== $suffix ) {
            return;
        }

        $slug = substr( $host, 0, -strlen( $suffix ) );
        $slug = sanitize_title( $slug );
        if ( '' === $slug || 'www' === $slug ) {
            return;
        }

        $store = get_posts(
            array(
                'post_type'   => Entity_Manager::STORE_CPT,
                'post_status' => 'publish',
                'numberposts' => 1,
                'fields'      => 'ids',
                'meta_key'    => '_shp_store_slug',
                'meta_value'  => $slug,
            )
        );

        if ( empty( $store ) ) {
            return;
        }

        $this->current_store_id   = (int) $store[0];
        $this->current_store_slug = $slug;

        set_query_var( 'shp_store', $slug );
    }

    /**
     * Restrict the main query on a store subdomain to that store's products.
     * On the main domain nothing is restricted, so search stays global.
     *
     * @param \WP_Query $query Query being prepared.
     * @return void
     */
    public function filter_main_query( $query ) {
        if ( is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $ptype = Entity_Manager::product_post_type();

        // Main domain: make sure site-wide search also spans harvested products
        // (WordPress core search is limited to the `post` type by default).
        if ( ! $this->current_store_id ) {
            if ( $query->is_search() ) {
                $existing = (array) $query->get( 'post_type' );
                if ( empty( $existing ) ) {
                    $existing = array( 'post', 'page' );
                }
                if ( ! in_array( $ptype, $existing, true ) ) {
                    $existing[] = $ptype;
                    $query->set( 'post_type', $existing );
                }
            }
            return;
        }

        $term_id = (int) get_post_meta( $this->current_store_id, '_shp_store_term_id', true );
        if ( ! $term_id ) {
            return;
        }

        // Store subdomain: constrain product-bearing queries to this store.
        $post_types = (array) $query->get( 'post_type' );
        if ( $query->is_search() || $query->is_home() || in_array( $ptype, $post_types, true ) || empty( $post_types ) ) {
            $query->set( 'post_type', $ptype );

            $tax_query   = (array) $query->get( 'tax_query' );
            $tax_query[] = array(
                'taxonomy' => Entity_Manager::TAX_STORE,
                'field'    => 'term_id',
                'terms'    => array( $term_id ),
            );
            $query->set( 'tax_query', $tax_query );
        }
    }

    /**
     * Public accessor for the active store ID (0 on the main site).
     *
     * @return int
     */
    public function current_store_id() {
        return $this->current_store_id;
    }

    /**
     * Build the public front-end URL for a store.
     *
     * @param int $store_id Store post ID.
     * @return string
     */
    public static function store_url( $store_id ) {
        // When this store has a WCFM vendor/storefront, that is the real,
        // browsable "shop page" — link straight to it instead of guessing a
        // /store/<our-own-slug>/ path that only coincidentally looks similar
        // and 404s (WCFM keys stores by the vendor's user_nicename, not by
        // our _shp_store_slug).
        $vendor_id = (int) get_post_meta( $store_id, '_shp_store_vendor_id', true );
        if ( $vendor_id && function_exists( 'wcfm_is_vendor' ) && wcfm_is_vendor( $vendor_id ) ) {
            if ( function_exists( 'wcfmmp_get_store_url' ) ) {
                $wcfm_url = wcfmmp_get_store_url( $vendor_id );
                if ( $wcfm_url ) {
                    return $wcfm_url;
                }
            }
            $vendor = get_userdata( $vendor_id );
            if ( $vendor && $vendor->user_nicename ) {
                return home_url( user_trailingslashit( 'store/' . $vendor->user_nicename ) );
            }
        }

        // No WCFM vendor: fall back to a taxonomy-term archive of the store's
        // products (the store_profile CPT itself is not publicly queryable, and
        // calling get_permalink() here would recurse through the post_type_link
        // filter that calls this method).
        $term_id = (int) get_post_meta( $store_id, '_shp_store_term_id', true );
        if ( $term_id ) {
            $term_link = get_term_link( $term_id, Entity_Manager::TAX_STORE );
            if ( ! is_wp_error( $term_link ) && $term_link ) {
                return $term_link;
            }
        }

        $slug = get_post_meta( $store_id, '_shp_store_slug', true );
        $base = Settings::get( 'base_domain' );

        if ( $slug && 'real' === Settings::get( 'subdomain_mode' ) && $base ) {
            return sprintf( '%s://%s.%s/', is_ssl() ? 'https' : 'http', $slug, $base );
        }

        return $slug ? home_url( user_trailingslashit( 'store/' . $slug ) ) : home_url( '/' );
    }

    /**
     * List every store slug — used by the admin runner dropdown.
     *
     * @return array Array of { id, slug, name }.
     */
    public static function all_stores() {
        $posts = get_posts(
            array(
                'post_type'   => Entity_Manager::STORE_CPT,
                'post_status' => 'publish',
                'numberposts' => -1,
                'orderby'     => 'title',
                'order'       => 'ASC',
            )
        );

        $out = array();
        foreach ( $posts as $post ) {
            $out[] = array(
                'id'   => $post->ID,
                'slug' => get_post_meta( $post->ID, '_shp_store_slug', true ),
                'name' => $post->post_title,
            );
        }

        return $out;
    }
}
