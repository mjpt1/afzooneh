<?php
/**
 * Custom post types, taxonomies, users and entity mapping.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Owns the data model: `store_profile` and `shp_product` CPTs, their
 * taxonomies, and the vendor user records tied to each store.
 */
class Entity_Manager {

    const STORE_CPT   = 'store_profile';
    const PRODUCT_CPT = 'shp_product';
    const TAX_STORE   = 'shp_store';
    const TAX_CAT     = 'shp_product_cat';
    const TAX_TAG     = 'shp_product_tag';
    const TAX_ATTR    = 'shp_product_attr';

    /**
     * Register CPTs and taxonomies.
     *
     * @return void
     */
    public function register() {
        register_post_type(
            self::STORE_CPT,
            array(
                'labels'          => array(
                    'name'          => __( 'پروفایل فروشگاه‌ها', 'shopharvester-pro' ),
                    'singular_name' => __( 'پروفایل فروشگاه', 'shopharvester-pro' ),
                    'menu_name'     => __( 'پروفایل فروشگاه‌ها', 'shopharvester-pro' ),
                    'all_items'     => __( 'همه پروفایل‌ها', 'shopharvester-pro' ),
                    'edit_item'     => __( 'ویرایش پروفایل فروشگاه', 'shopharvester-pro' ),
                ),
                // Internal data record only. It must NOT be publicly queryable
                // and must NOT own a /store/ rewrite base — that path belongs to
                // the WCFM vendor storefront, and a collision here 404s the real
                // store page. "View store" links are redirected to the WCFM
                // storefront via the post_type_link filter below.
                'public'             => false,
                'publicly_queryable' => false,
                'exclude_from_search' => true,
                'has_archive'        => false,
                'show_ui'            => true,
                'show_in_menu'       => false,
                'show_in_nav_menus'  => false,
                'show_in_rest'       => true,
                'menu_icon'          => 'dashicons-store',
                'supports'           => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
                'rewrite'            => false,
                'capability_type'    => 'post',
            )
        );

        add_filter( 'post_type_link', array( __CLASS__, 'store_profile_permalink' ), 10, 2 );

        register_post_type(
            self::PRODUCT_CPT,
            array(
                'labels'          => array(
                    'name'          => __( 'محصولات برداشت‌شده', 'shopharvester-pro' ),
                    'singular_name' => __( 'محصول برداشت‌شده', 'shopharvester-pro' ),
                    'menu_name'     => __( 'محصولات برداشت‌شده', 'shopharvester-pro' ),
                    'all_items'     => __( 'همه محصولات برداشت‌شده', 'shopharvester-pro' ),
                    'edit_item'     => __( 'ویرایش محصول', 'shopharvester-pro' ),
                ),
                'public'          => true,
                'has_archive'     => true,
                'show_ui'         => true,
                'show_in_menu'    => false,
                'show_in_rest'    => true,
                'menu_icon'       => 'dashicons-cart',
                'supports'        => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ),
                'rewrite'         => array( 'slug' => 'harvested-product', 'with_front' => false ),
                'capability_type' => 'post',
            )
        );

        register_taxonomy(
            self::TAX_STORE,
            self::PRODUCT_CPT,
            array(
                'labels'            => array( 'name' => __( 'فروشگاه محصول', 'shopharvester-pro' ) ),
                'public'            => true,
                'hierarchical'     => false,
                'show_admin_column' => true,
                'show_in_rest'      => true,
                'rewrite'          => array( 'slug' => 'store-items' ),
            )
        );

        register_taxonomy(
            self::TAX_CAT,
            self::PRODUCT_CPT,
            array(
                'labels'            => array( 'name' => __( 'دسته‌بندی محصولات', 'shopharvester-pro' ) ),
                'public'            => true,
                'hierarchical'     => true,
                'show_admin_column' => true,
                'show_in_rest'      => true,
                'rewrite'          => array( 'slug' => 'harvested-category', 'with_front' => false ),
            )
        );

        register_taxonomy(
            self::TAX_TAG,
            self::PRODUCT_CPT,
            array(
                'labels'       => array( 'name' => __( 'برچسب محصولات', 'shopharvester-pro' ) ),
                'public'       => true,
                'hierarchical' => false,
                'show_in_rest' => true,
                'rewrite'      => array( 'slug' => 'harvested-tag', 'with_front' => false ),
            )
        );

        register_taxonomy(
            self::TAX_ATTR,
            self::PRODUCT_CPT,
            array(
                'labels'       => array( 'name' => __( 'ویژگی‌های محصول', 'shopharvester-pro' ) ),
                'public'       => false,
                'hierarchical' => false,
                'show_in_rest' => false,
            )
        );

        $this->attach_woo_taxonomies();
    }

    /**
     * Cross-wire our taxonomies with WooCommerce's, once both post types exist.
     * Called on `init:5` and again on `init:20` so ordering with WooCommerce's
     * own registration does not matter.
     *
     * @return void
     */
    public function attach_woo_taxonomies() {
        if ( taxonomy_exists( 'product_cat' ) ) {
            register_taxonomy_for_object_type( 'product_cat', self::PRODUCT_CPT );
        }
        if ( taxonomy_exists( 'product_tag' ) ) {
            register_taxonomy_for_object_type( 'product_tag', self::PRODUCT_CPT );
        }
        if ( post_type_exists( 'product' ) ) {
            register_taxonomy_for_object_type( self::TAX_STORE, 'product' );
            register_taxonomy_for_object_type( self::TAX_ATTR, 'product' );
        }
    }

    /**
     * The post type harvested products are written to, based on settings.
     *
     * @return string
     */
    public static function product_post_type() {
        $target = (string) Settings::get( 'product_target' );

        // Both the WooCommerce and the Classima output modes write a real
        // WooCommerce product as the primary, editable object.
        if ( in_array( $target, array( 'woocommerce', 'classima' ), true ) && post_type_exists( 'product' ) ) {
            return 'product';
        }

        return self::PRODUCT_CPT;
    }

    /**
     * Point every "View" / permalink of a store_profile record at the real
     * storefront (WCFM vendor page, or the plugin's virtual store URL).
     *
     * @param string   $link Permalink.
     * @param \WP_Post $post Post.
     * @return string
     */
    public static function store_profile_permalink( $link, $post ) {
        if ( ! $post instanceof \WP_Post || self::STORE_CPT !== $post->post_type ) {
            return $link;
        }

        $url = Subdomain_Manager::store_url( $post->ID );

        return $url ? $url : $link;
    }

    /* --------------------------------------------------------------------- *
     *  Store de-duplication and creation.
     * --------------------------------------------------------------------- */

    /**
     * Find an existing store post by domain, e-mail or phone.
     *
     * @param array $store Normalized store data.
     * @return int|null Store post ID or null.
     */
    public function find_existing_store( array $store ) {
        $checks = array();

        if ( ! empty( $store['domain'] ) ) {
            $checks[] = array( '_shp_store_domain', $this->normalize_domain( $store['domain'] ) );
        }
        if ( ! empty( $store['email'] ) ) {
            $checks[] = array( '_shp_store_email', sanitize_email( $store['email'] ) );
        }
        if ( ! empty( $store['phone'] ) ) {
            $checks[] = array( '_shp_store_phone', $this->normalize_phone( $store['phone'] ) );
        }

        foreach ( $checks as $check ) {
            if ( '' === $check[1] ) {
                continue;
            }

            $found = get_posts(
                array(
                    'post_type'      => self::STORE_CPT,
                    'post_status'    => 'any',
                    'numberposts'    => 1,
                    'fields'         => 'ids',
                    'meta_key'       => $check[0],
                    'meta_value'     => $check[1],
                )
            );

            if ( ! empty( $found ) ) {
                return (int) $found[0];
            }
        }

        return null;
    }

    /**
     * Create a store profile plus its dedicated vendor user.
     *
     * @param array $store Normalized store data.
     * @return int Store post ID.
     * @throws \RuntimeException On failure.
     */
    public function create_store( array $store ) {
        $name   = ! empty( $store['name'] ) ? sanitize_text_field( $store['name'] ) : __( 'فروشگاه بدون نام', 'shopharvester-pro' );
        $domain = $this->normalize_domain( $store['domain'] ?? '' );
        $slug   = $this->unique_store_slug( $store['slug'] ?? ( $domain ?: $name ) );

        $post_id = wp_insert_post(
            array(
                'post_type'    => self::STORE_CPT,
                'post_status'  => 'publish',
                'post_title'   => $name,
                'post_name'    => $slug,
                'post_content' => wp_kses_post( $store['description'] ?? '' ),
            ),
            true
        );

        if ( is_wp_error( $post_id ) ) {
            throw new \RuntimeException( 'store_insert_failed: ' . $post_id->get_error_message() );
        }

        $vendor_id = $this->create_vendor_user( $name, $store['email'] ?? '', $slug, $domain );

        update_post_meta( $post_id, '_shp_store_slug', $slug );
        update_post_meta( $post_id, '_shp_store_domain', $domain );
        update_post_meta( $post_id, '_shp_store_brand', sanitize_text_field( $store['brand'] ?? '' ) );
        update_post_meta( $post_id, '_shp_store_email', sanitize_email( $store['email'] ?? '' ) );
        update_post_meta( $post_id, '_shp_store_phone', $this->normalize_phone( $store['phone'] ?? '' ) );
        update_post_meta( $post_id, '_shp_store_address', sanitize_textarea_field( $store['address'] ?? '' ) );
        update_post_meta( $post_id, '_shp_store_socials', $this->sanitize_socials( $store['socials'] ?? array() ) );
        update_post_meta( $post_id, '_shp_store_source_url', esc_url_raw( $store['source_url'] ?? '' ) );
        update_post_meta( $post_id, '_shp_store_vendor_id', $vendor_id );

        if ( ! empty( $store['logo'] ) ) {
            update_post_meta( $post_id, '_shp_store_logo', esc_url_raw( $store['logo'] ) );
            $logo_id = ( new Media_Handler() )->import_single( $store['logo'], $post_id );
            if ( $logo_id ) {
                set_post_thumbnail( $post_id, $logo_id );
            }
        }

        // Mirror the store as a term so products can be filtered cheaply.
        $term = wp_insert_term( $name, self::TAX_STORE, array( 'slug' => $slug ) );
        if ( ! is_wp_error( $term ) ) {
            update_post_meta( $post_id, '_shp_store_term_id', (int) $term['term_id'] );
        } elseif ( $term->get_error_data( 'term_exists' ) ) {
            update_post_meta( $post_id, '_shp_store_term_id', (int) $term->get_error_data( 'term_exists' ) );
        }

        if ( $vendor_id ) {
            update_user_meta( $vendor_id, '_shp_store_id', $post_id );
        }

        Logger::success(
            sprintf( __( 'فروشگاه جدید «%s» ایجاد شد.', 'shopharvester-pro' ), $name ),
            array( 'store_id' => $post_id, 'vendor_id' => $vendor_id, 'slug' => $slug ),
            array( 'channel' => 'entities' )
        );

        return $post_id;
    }

    /**
     * Resolve a store: return existing ID or create a new one.
     *
     * @param array $store Normalized store data.
     * @return int Store post ID.
     */
    public function resolve_store( array $store ) {
        $existing = $this->find_existing_store( $store );
        if ( $existing ) {
            $this->touch_store( $existing, $store );
            return $existing;
        }

        return $this->create_store( $store );
    }

    /**
     * Update mutable store fields when we re-encounter it.
     *
     * @param int   $store_id Store post ID.
     * @param array $store    Fresh data.
     * @return void
     */
    public function touch_store( $store_id, array $store ) {
        foreach ( array(
            '_shp_store_phone'   => $this->normalize_phone( $store['phone'] ?? '' ),
            '_shp_store_email'   => sanitize_email( $store['email'] ?? '' ),
            '_shp_store_address' => sanitize_textarea_field( $store['address'] ?? '' ),
        ) as $key => $value ) {
            if ( '' !== $value && ! get_post_meta( $store_id, $key, true ) ) {
                update_post_meta( $store_id, $key, $value );
            }
        }
        // Backfill a logo / featured image if we still lack one.
        if ( ! empty( $store['logo'] ) && ! has_post_thumbnail( $store_id ) ) {
            update_post_meta( $store_id, '_shp_store_logo', esc_url_raw( $store['logo'] ) );
            $logo_id = ( new Media_Handler() )->import_single( $store['logo'], $store_id );
            if ( $logo_id ) {
                set_post_thumbnail( $store_id, $logo_id );
            }
        }

        update_post_meta( $store_id, '_shp_store_last_seen', current_time( 'mysql' ) );
    }

    /**
     * Create (or reuse) a vendor user for a store.
     *
     * @param string $name   Store display name.
     * @param string $email  Store e-mail.
     * @param string $slug   Store slug.
     * @param string $domain Store domain.
     * @return int User ID (0 on failure).
     */
    private function create_vendor_user( $name, $email, $slug, $domain ) {
        $email = sanitize_email( $email );
        if ( $email && email_exists( $email ) ) {
            return (int) email_exists( $email );
        }

        $login = 'vendor_' . sanitize_user( $slug, true );
        $login = substr( $login, 0, 60 );
        if ( username_exists( $login ) ) {
            $login .= '_' . wp_rand( 100, 999 );
        }

        if ( ! $email ) {
            $email = $login . '@' . ( $domain ?: 'stores.local' );
        }

        $user_id = wp_insert_user(
            array(
                'user_login'   => $login,
                'user_email'   => $email,
                'user_pass'    => wp_generate_password( 24 ),
                'display_name' => $name,
                'nickname'     => $name,
                'role'         => 'shp_vendor',
            )
        );

        if ( is_wp_error( $user_id ) ) {
            Logger::warning(
                __( 'ایجاد کاربر فروشنده ناموفق بود؛ فروشگاه بدون کاربر ثبت شد.', 'shopharvester-pro' ),
                array( 'error' => $user_id->get_error_message() ),
                array( 'channel' => 'entities' )
            );
            return 0;
        }

        return (int) $user_id;
    }

    /* --------------------------------------------------------------------- *
     *  Helpers.
     * --------------------------------------------------------------------- */

    /**
     * @param string $slug Raw slug candidate.
     * @return string
     */
    private function unique_store_slug( $slug ) {
        $slug = sanitize_title( preg_replace( '/^www\./i', '', (string) $slug ) );
        $slug = $slug ?: 'store-' . wp_rand( 1000, 9999 );
        $base = $slug;
        $i    = 2;

        while ( get_page_by_path( $slug, OBJECT, self::STORE_CPT ) || term_exists( $slug, self::TAX_STORE ) ) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    /**
     * @param string $domain Raw domain / URL.
     * @return string Bare host, lowercased, no www.
     */
    public function normalize_domain( $domain ) {
        $domain = trim( (string) $domain );
        if ( '' === $domain ) {
            return '';
        }
        if ( false !== strpos( $domain, '/' ) || false !== strpos( $domain, ':' ) ) {
            $host = wp_parse_url( $domain, PHP_URL_HOST );
            $domain = $host ?: $domain;
        }

        return strtolower( preg_replace( '/^www\./i', '', $domain ) );
    }

    /**
     * @param string $phone Raw phone number.
     * @return string Digits only (Western), leading zeros preserved.
     */
    public function normalize_phone( $phone ) {
        $phone = (string) $phone;
        $map   = array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' );
        $phone = strtr( $phone, $map );
        return preg_replace( '/\D+/', '', $phone );
    }

    /**
     * @param array $socials Raw socials map.
     * @return array
     */
    private function sanitize_socials( $socials ) {
        $out = array();
        foreach ( (array) $socials as $key => $url ) {
            $url = esc_url_raw( $url );
            if ( $url ) {
                $out[ sanitize_key( $key ) ] = $url;
            }
        }
        return $out;
    }

    /**
     * Store term id for a store post.
     *
     * @param int $store_id Store post id.
     * @return int
     */
    public function store_term_id( $store_id ) {
        return (int) get_post_meta( $store_id, '_shp_store_term_id', true );
    }
}
