<?php
/**
 * Output adapter for Classified Listing (Classima) sites.
 *
 * Turns a normalized product into an `rtcl_listing` (so it appears in the
 * site's Listings / Stores sections) and, optionally, promotes the store's
 * vendor user to a WCFM multivendor vendor so the same shop also owns a
 * marketplace storefront.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Classified Listing + WCFM vendor writer.
 */
class Classima_Writer {

    /**
     * Provenance source key stamped on every listing we create.
     */
    const SOURCE = 'shopharvester';

    /**
     * @var Woo_Writer
     */
    private $woo;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->woo = new Woo_Writer();
    }

    /**
     * Whether the target site exposes the Classified Listing ingester API.
     *
     * @return bool
     */
    public static function available() {
        return class_exists( '\Rtcl\Services\Importers\ListingIngester' )
            && class_exists( '\Rtcl\Services\Importers\DedupeResolver' )
            && function_exists( 'rtcl' );
    }

    /**
     * Create or update a listing for one normalized product.
     *
     * @param array    $facts         Normalized product facts.
     * @param int      $store_id      ShopHarvester store post ID.
     * @param int      $store_term_id Store taxonomy term ID (passed through to the product writer).
     * @param int|null $existing_id   Existing listing ID when known.
     * @param int      $job_id        Owning job ID.
     * @param array    $enrichment    Optional AI enrichment payload.
     * @param int      $operator_id   User that queued the job (fallback author).
     * @return int Primary post ID — the WooCommerce product when available,
     *             otherwise the listing (0 on failure).
     */
    public function upsert( array $facts, $store_id, $store_term_id, $existing_id, $job_id, $enrichment = null, $operator_id = 0 ) {
        $enrichment  = is_array( $enrichment ) ? $enrichment : array();
        $operator_id = (int) $operator_id;

        $has_listing = self::available();
        $has_woo     = function_exists( 'wc_get_product' );

        if ( ! $has_listing && ! $has_woo ) {
            Logger::error(
                __( 'حالت «آگهی + فروشنده» انتخاب شده اما نه Classified Listing (نسخه‌ی دارای درون‌ریز) و نه ووکامرس روی سایت فعال نیستند.', 'shopharvester-pro' ),
                array(),
                array( 'job_id' => $job_id, 'channel' => 'import' )
            );
            return 0;
        }

        $vendor_id = self::ensure_vendor( $store_id );
        $vendor    = $vendor_id ? get_userdata( $vendor_id ) : null;

        // Author for the created content: the per-shop vendor when available
        // (so the site attributes it correctly), otherwise the operator who
        // queued the job — never user 0.
        $author_id = $vendor_id ?: $operator_id;

        // Two independent switches:
        //  - classima_create_listing: an rtcl_listing (آگهی) per product. Off by
        //    default — harvested products stay WooCommerce-only.
        //  - classima_shop_directory: ONE rtcl_listing per source shop so the
        //    shop itself is browsable in /all-ads. On by default.
        $make_product_listing = $has_listing && Settings::get( 'classima_create_listing', false );
        $make_shop_listing    = $has_listing && Settings::get( 'classima_shop_directory', true );

        // The source shop as its own rtcl_listing. When per-product listings are
        // on too, those nest under it via post_parent.
        $shop_listing_id = $make_shop_listing ? $this->ensure_shop_listing( $store_id, $author_id, $job_id ) : 0;

        // --- 1) WooCommerce product: the WooCommerce-style editable object. ---
        $product_id = 0;
        if ( $has_woo ) {
            $product_id = (int) $this->woo->upsert( $facts, $store_id, $store_term_id, $existing_id, $enrichment, $job_id );
            if ( $product_id ) {
                if ( ! $existing_id && $author_id && (int) get_post_field( 'post_author', $product_id ) !== $author_id ) {
                    wp_update_post( array( 'ID' => $product_id, 'post_author' => $author_id ) );
                }
                if ( $operator_id ) {
                    update_post_meta( $product_id, '_shp_imported_by', $operator_id );
                }
            }
        }

        // --- 2) Classified Listing rtcl_listing: the site's "آگهی" object. ---
        $listing_id = $make_product_listing ? $this->write_listing( $facts, $store_id, $vendor, $author_id, $job_id, $enrichment, $shop_listing_id ) : 0;
        if ( $listing_id && $operator_id ) {
            update_post_meta( $listing_id, '_shp_imported_by', $operator_id );
        }

        // --- 3) Cross-link the two so edits and re-syncs stay in step. ---
        if ( $product_id && $listing_id ) {
            update_post_meta( $product_id, '_shp_listing_id', $listing_id );
            update_post_meta( $listing_id, '_shp_product_id', $product_id );
        }

        return $product_id ? $product_id : $listing_id;
    }

    /**
     * Public entry point for the WooCommerce output path: make sure the source
     * shop has its own directory listing (and a WCFM vendor), without creating
     * any per-product listing.
     *
     * @param int $store_id    ShopHarvester store post ID.
     * @param int $operator_id Job operator (fallback author).
     * @return int Shop listing ID (0 when disabled / unavailable).
     */
    public function ensure_shop_directory_listing( $store_id, $operator_id = 0 ) {
        if ( ! self::available() || ! Settings::get( 'classima_shop_directory', true ) ) {
            return 0;
        }
        $vendor_id = self::ensure_vendor( (int) $store_id );
        $author_id = $vendor_id ?: (int) $operator_id;

        return $this->ensure_shop_listing( (int) $store_id, $author_id, 0 );
    }

    /**
     * Create or update the rtcl_listing mirror of a product.
     *
     * @param array         $facts      Normalized facts.
     * @param int           $store_id   Store post ID.
     * @param \WP_User|null $vendor     Vendor user (for the contact e-mail).
     * @param int           $author_id  User ID to own the listing.
     * @param int           $job_id     Job ID.
     * @param array         $enrichment      Enrichment payload.
     * @param int           $shop_listing_id Parent shop listing (0 when not grouping).
     * @return int Listing post ID (0 on failure).
     */
    private function write_listing( array $facts, $store_id, $vendor, $author_id, $job_id, array $enrichment, $shop_listing_id = 0 ) {

        $title       = ! empty( $enrichment['seo_title'] ) ? $enrichment['seo_title'] : $facts['title'];
        $description = ! empty( $enrichment['long_description'] ) ? $enrichment['long_description'] : $facts['description'];
        $status      = $this->status();

        $meta = array(
            'price_type'           => 'regular',
            '_rtcl_listing_pricing' => 'price',
        );

        $price = $this->to_number( $facts['sale_price'] ?: $facts['price'] );
        if ( '' !== $price ) {
            $meta['price'] = $price;
        }

        $ad_type = (string) Settings::get( 'classima_ad_type', '' );
        if ( '' !== $ad_type ) {
            $meta['ad_type'] = $ad_type;
        }
        // (When empty, any stale shop-type ad_type is stripped after the insert
        // so the product never lingers in the stores directory — see below.)

        $form_id = (int) Settings::get( 'classima_form_id', 0 );
        if ( $form_id > 0 ) {
            $meta['_rtcl_form_id'] = $form_id;
        }

        $phone = $store_id ? (string) get_post_meta( $store_id, '_shp_store_phone', true ) : '';
        if ( '' !== $phone ) {
            $meta['phone'] = $phone;
        }

        $socials = $store_id ? (array) get_post_meta( $store_id, '_shp_store_socials', true ) : array();
        $socials = array_filter( $socials );
        if ( $socials ) {
            $meta['_rtcl_social_profiles'] = $socials;
        }

        // SKU / brand / model preserved as generic listing meta so they survive
        // and can be surfaced by custom fields if the site maps them.
        foreach ( array( 'sku' => '_shp_sku', 'brand' => '_shp_brand', 'model' => '_shp_model', 'ean' => '_shp_ean' ) as $fact_key => $meta_key ) {
            if ( ! empty( $facts[ $fact_key ] ) ) {
                $meta[ $meta_key ] = $facts[ $fact_key ];
            }
        }

        $categories = $this->hierarchy_strings( $facts );
        $tags       = array_slice( array_values( array_filter( (array) ( $enrichment['tags'] ?? $facts['tags'] ) ) ), 0, 15 );

        $row = array(
            'source'       => self::SOURCE,
            'source_id'    => sha1( (string) $facts['source_url'] ),
            'source_url'   => (string) $facts['source_url'],
            'title'        => $title,
            'content'      => $description,
            'excerpt'      => (string) ( $enrichment['short_description'] ?? '' ),
            'status'       => $status,
            'author_email' => $vendor ? $vendor->user_email : '',
            'categories'   => $categories,
            'tags'         => $tags,
            'meta'         => $meta,
            'gallery_urls' => array_slice( array_values( array_filter( (array) $facts['images'] ) ), 0, (int) Settings::get( 'max_images_per_product', 8 ) ),
        );

        $opts = array(
            'update_existing' => true,
            'target_category' => (int) Settings::get( 'classima_category_id', 0 ),
            'target_location' => (int) Settings::get( 'classima_location_id', 0 ),
            'default_status'  => $status,
            'form_id'         => $form_id,
        );

        $ingester = new \Rtcl\Services\Importers\ListingIngester();
        $result   = $ingester->ingest_normalized( $row, $opts );

        if ( ! empty( $result['errors'] ) ) {
            // A failed side-image download is not worth a log line — it does not
            // affect the listing itself. Only report other problems.
            $significant = array_values( array_filter(
                (array) $result['errors'],
                static function ( $err ) {
                    return ! preg_match( '/(تصویر|image|عکس|photo)/iu', (string) $err );
                }
            ) );
            if ( $significant ) {
                Logger::warning(
                    __( 'ساخت آگهی با هشدار همراه بود.', 'shopharvester-pro' ),
                    array( 'errors' => array_slice( $significant, 0, 5 ) ),
                    array( 'job_id' => $job_id, 'channel' => 'import', 'source_url' => $facts['source_url'] )
                );
            }
        }

        $post_id = (int) ( $result['post_id'] ?? 0 );
        if ( ! $post_id ) {
            Logger::error(
                sprintf( __( 'ساخت آگهی ناموفق بود: %s', 'shopharvester-pro' ), $title ),
                array( 'errors' => array_slice( (array) ( $result['errors'] ?? array() ), 0, 5 ) ),
                array( 'job_id' => $job_id, 'channel' => 'import', 'source_url' => $facts['source_url'] )
            );
            return 0;
        }

        // Make sure the listing is owned by the intended author even if the
        // ingester could not resolve it from the e-mail.
        if ( $author_id && (int) get_post_field( 'post_author', $post_id ) !== (int) $author_id ) {
            wp_update_post( array( 'ID' => $post_id, 'post_author' => (int) $author_id ) );
        }

        // A harvested product is not a shop: strip any shop-type ad_type so it
        // does not appear in the site's stores / boutiques directory.
        if ( '' === $ad_type ) {
            delete_post_meta( $post_id, 'ad_type' );
        }
        update_post_meta( $post_id, '_shp_is_listing', 1 );

        // Nest the product listing under its shop listing.
        if ( $shop_listing_id && (int) get_post_field( 'post_parent', $post_id ) !== (int) $shop_listing_id ) {
            wp_update_post( array( 'ID' => $post_id, 'post_parent' => (int) $shop_listing_id ) );
        }
        if ( $shop_listing_id ) {
            update_post_meta( $post_id, '_shp_shop_listing_id', (int) $shop_listing_id );
        }

        update_post_meta( $post_id, '_shp_source_url_hash', sha1( (string) $facts['source_url'] ) );
        update_post_meta( $post_id, '_shp_source_url', esc_url_raw( (string) $facts['source_url'] ) );
        update_post_meta( $post_id, '_shp_store_id', (int) $store_id );
        update_post_meta( $post_id, '_shp_last_import', current_time( 'mysql' ) );

        Logger::success(
            sprintf(
                /* translators: 1: listing title, 2: action */
                __( 'آگهی %1$s شد: %2$s', 'shopharvester-pro' ),
                'updated' === ( $result['action'] ?? '' ) ? __( 'بروزرسانی', 'shopharvester-pro' ) : __( 'ساخته', 'shopharvester-pro' ),
                $title
            ),
            array( 'listing_id' => $post_id, 'store_id' => (int) $store_id, 'author_id' => (int) $author_id ),
            array( 'job_id' => $job_id, 'channel' => 'import', 'source_url' => $facts['source_url'] )
        );

        return $post_id;
    }

    /**
     * Resolve — and, when enabled, promote to a WCFM vendor — the user that
     * owns the given ShopHarvester store.
     *
     * Safe to call repeatedly and from any output path (WooCommerce or
     * Classified Listing) — it only ever adds the WCFM role / profile meta
     * once.
     *
     * @param int $store_id Store post ID.
     * @return int Vendor user ID (0 when unavailable).
     */
    public static function ensure_vendor( $store_id ) {
        if ( ! $store_id ) {
            return 0;
        }

        $vendor_id = (int) get_post_meta( $store_id, '_shp_store_vendor_id', true );
        if ( ! $vendor_id || ! get_userdata( $vendor_id ) ) {
            return 0;
        }

        if ( ! Settings::get( 'classima_make_vendor', true ) ) {
            return $vendor_id;
        }

        // Already a WCFM vendor? Nothing to do.
        if ( function_exists( 'wcfm_is_vendor' ) && wcfm_is_vendor( $vendor_id ) ) {
            return $vendor_id;
        }

        $user = new \WP_User( $vendor_id );
        if ( ! in_array( 'wcfm_vendor', (array) $user->roles, true ) ) {
            $user->add_role( 'wcfm_vendor' );
        }

        // Give the vendor a human-readable display name so content authored by
        // it never shows a bare login slug.
        $store_title = get_the_title( $store_id );
        if ( $store_title && $user->display_name === $user->user_login ) {
            wp_update_user( array( 'ID' => $vendor_id, 'display_name' => $store_title ) );
        }

        if ( ! get_user_meta( $vendor_id, 'wcfmmp_profile_settings', true ) ) {
            $store_name = get_the_title( $store_id );
            $settings   = array(
                'store_name'       => wp_strip_all_tags( $store_name ),
                'social'           => array(),
                'payment'          => array(),
                'phone'            => (string) get_post_meta( $store_id, '_shp_store_phone', true ),
                'show_email'       => 'no',
                'location'         => '',
                'find_address'     => '',
                'banner'           => 0,
                'customer_support' => array( 'email' => $user->user_email ),
            );
            $address = (string) get_post_meta( $store_id, '_shp_store_address', true );
            if ( '' !== $address ) {
                $settings['address'] = array( 'street_1' => $address );
            }

            update_user_meta( $vendor_id, 'wcfmmp_profile_settings', $settings );
            update_user_meta( $vendor_id, 'wcfmmp_store_name', $store_name );
            update_user_meta(
                $vendor_id,
                '_wcfmmp_shipping',
                array(
                    '_wcfmmp_user_shipping_enable' => 'yes',
                    '_wcfmmp_user_shipping_type'   => 'by_zone',
                )
            );

            delete_user_meta( $vendor_id, '_disable_vendor' );
            update_user_meta( $vendor_id, '_wcfm_vendor', 'yes' );

            do_action( 'wcfm_new_vendor', $vendor_id, false );
            do_action( 'wcfmmp_new_vendor_registered', $vendor_id );
            do_action( 'wcfm_vendor_enable_after', $vendor_id );

            // The /store/<slug> endpoint 404s until rewrite rules are rebuilt
            // and WCFM's active-vendor cache is cleared.
            delete_transient( 'wcfm_marketplace_active_vendors' );
            delete_option( 'wcfm_marketplace_active_vendors' );
            self::request_rewrite_flush();

            Logger::success(
                sprintf( __( 'فروشنده‌ی WCFM ساخته شد: %s', 'shopharvester-pro' ), $store_name ),
                array( 'vendor_id' => $vendor_id, 'store_id' => (int) $store_id ),
                array( 'channel' => 'import' )
            );
        }

        return $vendor_id;
    }

    /**
     * Ensure the source shop exists as its own `rtcl_listing` (a "shop"
     * listing). Product listings then nest under it via post_parent, so the
     * shop's own page can show its products.
     *
     * Resolution order: previously-linked shop listing → a shop listing we
     * tagged for this store → an existing directory listing whose title matches
     * the shop name (so we attach to a shop the operator already created) →
     * create a new one.
     *
     * @param int $store_id  ShopHarvester store post ID.
     * @param int $author_id Author for a freshly created shop listing.
     * @param int $job_id    Job ID.
     * @return int Shop listing ID (0 when disabled / unavailable).
     */
    private function ensure_shop_listing( $store_id, $author_id, $job_id ) {
        if ( ! $store_id || ! self::available() ) {
            return 0;
        }
        if ( ! Settings::get( 'classima_group_by_shop', true ) && ! Settings::get( 'classima_shop_directory', true ) ) {
            return 0;
        }

        $want_status = 'pending' === Settings::get( 'classima_shop_status', 'publish' ) ? 'pending' : 'publish';
        $name        = trim( (string) get_the_title( $store_id ) );
        if ( '' === $name ) {
            return 0;
        }

        // 1) Linked from a previous run.
        $linked = (int) get_post_meta( $store_id, '_shp_shop_listing_id', true );
        if ( $linked && 'rtcl_listing' === get_post_type( $linked ) ) {
            $this->promote_listing( $linked, $want_status );
            return $linked;
        }

        // 2) A shop listing we already tagged for this store.
        $tagged = get_posts(
            array(
                'post_type'      => 'rtcl_listing',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array( 'key' => '_shp_is_shop', 'value' => '1' ),
                    array( 'key' => '_shp_store_id', 'value' => (string) $store_id ),
                ),
                'no_found_rows'  => true,
            )
        );
        if ( ! empty( $tagged ) ) {
            $sid = (int) $tagged[0];
            update_post_meta( $store_id, '_shp_shop_listing_id', $sid );
            $this->promote_listing( $sid, $want_status );
            return $sid;
        }

        // 3) A directory listing the operator already created for this shop.
        $match = $this->match_existing_shop( $name );
        if ( $match ) {
            update_post_meta( $match, '_shp_is_shop', 1 );
            update_post_meta( $match, '_shp_store_id', (int) $store_id );
            update_post_meta( $store_id, '_shp_shop_listing_id', $match );
            Logger::success(
                sprintf( __( 'محصولات به فروشگاه موجود متصل شدند: %s', 'shopharvester-pro' ), $name ),
                array( 'shop_listing_id' => $match, 'store_id' => (int) $store_id ),
                array( 'job_id' => $job_id, 'channel' => 'import' )
            );
            return $match;
        }

        // 4) Create a new shop listing.
        $meta = array(
            '_shp_is_shop'          => 1,
            '_shp_store_id'         => (int) $store_id,
            '_rtcl_listing_pricing' => 'price',
        );
        $ad_type = (string) Settings::get( 'classima_shop_ad_type', '' );
        if ( '' !== $ad_type ) {
            $meta['ad_type'] = $ad_type;
        }
        $form_id = (int) Settings::get( 'classima_form_id', 0 );
        if ( $form_id > 0 ) {
            $meta['_rtcl_form_id'] = $form_id;
        }
        $phone = (string) get_post_meta( $store_id, '_shp_store_phone', true );
        if ( '' !== $phone ) {
            $meta['phone'] = $phone;
        }
        $socials = array_filter( (array) get_post_meta( $store_id, '_shp_store_socials', true ) );
        if ( $socials ) {
            $meta['_rtcl_social_profiles'] = $socials;
        }

        $post_id = wp_insert_post(
            array(
                'post_type'    => 'rtcl_listing',
                'post_status'  => $want_status,
                'post_title'   => $name,
                'post_author'  => $author_id ?: 0,
                'post_content' => (string) get_post_field( 'post_content', $store_id ),
                'meta_input'   => $meta,
            ),
            true
        );

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            Logger::warning(
                sprintf( __( 'ساخت آگهیِ فروشگاه ناموفق بود: %s', 'shopharvester-pro' ), $name ),
                array( 'store_id' => (int) $store_id ),
                array( 'job_id' => $job_id, 'channel' => 'import' )
            );
            return 0;
        }

        $post_id = (int) $post_id;

        $cat = (int) Settings::get( 'classima_category_id', 0 );
        if ( $cat ) {
            wp_set_object_terms( $post_id, array( $cat ), 'rtcl_category' );
        }
        $loc = (int) Settings::get( 'classima_location_id', 0 );
        if ( $loc ) {
            wp_set_object_terms( $post_id, array( $loc ), 'rtcl_location' );
        }

        $logo_id = (int) get_post_meta( $store_id, '_thumbnail_id', true );
        if ( $logo_id ) {
            set_post_thumbnail( $post_id, $logo_id );
        }

        update_post_meta( $store_id, '_shp_shop_listing_id', $post_id );
        self::request_rewrite_flush();

        Logger::success(
            sprintf( __( 'آگهیِ فروشگاه ساخته شد: %s', 'shopharvester-pro' ), $name ),
            array( 'shop_listing_id' => $post_id, 'store_id' => (int) $store_id, 'status' => $want_status ),
            array( 'job_id' => $job_id, 'channel' => 'import' )
        );

        return $post_id;
    }

    /**
     * Find an existing directory listing whose title matches a shop name.
     *
     * @param string $name Shop name.
     * @return int Listing ID or 0.
     */
    private function match_existing_shop( $name ) {
        $candidates = get_posts(
            array(
                'post_type'      => 'rtcl_listing',
                'post_status'    => array( 'publish', 'pending', 'draft' ),
                'posts_per_page' => 8,
                'fields'         => 'ids',
                's'              => $name,
                'no_found_rows'  => true,
                'suppress_filters' => false,
            )
        );

        $target = $this->normalize_name( $name );
        foreach ( (array) $candidates as $cid ) {
            if ( get_post_meta( $cid, '_shp_is_listing', true ) ) {
                continue; // Never attach a shop to one of our own product listings.
            }
            $cand = $this->normalize_name( get_the_title( $cid ) );
            if ( '' === $cand ) {
                continue;
            }
            if ( $cand === $target ) {
                return (int) $cid;
            }
            $pct = 0.0;
            similar_text( $cand, $target, $pct );
            if ( $pct >= 92 ) {
                return (int) $cid;
            }
        }

        return 0;
    }

    /**
     * Normalize a name for comparison (Persian ی/ک, digits, ZWNJ, spacing).
     *
     * @param string $s Raw name.
     * @return string
     */
    private function normalize_name( $s ) {
        $s = (string) $s;
        $s = strtr( $s, array( 'ي' => 'ی', 'ك' => 'ک', 'ۀ' => 'ه', "\xE2\x80\x8C" => ' ' ) );
        $s = preg_replace( '/\s+/u', ' ', $s );

        return trim( function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s ) );
    }

    /**
     * Promote a pending listing to publish when the setting asks for it.
     *
     * @param int    $listing_id Listing post ID.
     * @param string $status     Desired status.
     * @return void
     */
    private function promote_listing( $listing_id, $status ) {
        if ( 'publish' !== $status || 'publish' === get_post_status( $listing_id ) ) {
            return;
        }
        if ( 'trash' === get_post_status( $listing_id ) ) {
            return;
        }
        wp_update_post( array( 'ID' => (int) $listing_id, 'post_status' => 'publish' ) );
    }

    /**
     * Force a rewrite-rule rebuild that does not depend on hook timing or on
     * admin access. Deleting the `rewrite_rules` option makes WordPress
     * regenerate every registered rule (Store add-on included) on the next
     * front-end request — the reliable fix for a fresh /store/<slug> 404 on a
     * site where the operator cannot open Settings → Permalinks.
     *
     * @return void
     */
    private static function request_rewrite_flush() {
        // Only raise the flag. The actual rebuild happens on the next admin or
        // front-end request (Core::maybe_flush_rewrite / self_heal_rewrite),
        // where every post type and add-on rule is registered — flushing here,
        // inside a cron / Action Scheduler run, produced a partial rule set that
        // 404'd product and product-category permalinks.
        update_option( 'shp_flush_needed', 'yes' );
        update_option( 'rtcl_queue_flush_rewrite_rules', 'yes' );
    }

    /**
     * Build "Parent > Child" hierarchy strings for the listing taxonomies.
     *
     * @param array $facts Normalized facts.
     * @return string[]
     */
    private function hierarchy_strings( array $facts ) {
        $out = array();

        foreach ( (array) ( $facts['categories_path'] ?? array() ) as $path ) {
            if ( is_array( $path ) ) {
                $path = array_filter( array_map( 'trim', $path ) );
                if ( $path ) {
                    $out[] = implode( ' > ', $path );
                }
            } elseif ( is_string( $path ) && '' !== trim( $path ) ) {
                $out[] = trim( $path );
            }
        }

        if ( empty( $out ) ) {
            foreach ( (array) ( $facts['categories'] ?? array() ) as $name ) {
                $name = trim( (string) $name );
                if ( '' !== $name ) {
                    $out[] = $name;
                }
            }
        }

        return array_slice( array_values( array_unique( $out ) ), 0, 5 );
    }

    /**
     * Normalize a price string (Persian digits, separators) to a plain number.
     *
     * @param string $value Raw price.
     * @return string
     */
    private function to_number( $value ) {
        $value = (string) $value;
        if ( '' === $value ) {
            return '';
        }

        $map   = array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٫' => '.' );
        $value = strtr( $value, $map );
        $value = preg_replace( '/(\d)\.(\d{1,2})(?!\d)/', '$1', $value );
        do {
            $value = preg_replace( '/(\d)[,٬،\x{066C}](\d{3})(?!\d)/u', '$1$2', $value, -1, $count );
        } while ( $count );

        // Keep only the first number group so a glued "regular+sale" pair
        // ("۱۹۹٬۰۰۰ تومان۱۵۹٬۰۰۰") does not become a nonsense amount.
        return preg_match( '/\d+/', $value, $m ) ? $m[0] : '';
    }

    /**
     * Resolved listing status for freshly created listings.
     *
     * @return string
     */
    private function status() {
        $status = (string) Settings::get( 'classima_listing_status', 'draft' );

        return in_array( $status, array( 'draft', 'pending', 'publish' ), true ) ? $status : 'draft';
    }
}
