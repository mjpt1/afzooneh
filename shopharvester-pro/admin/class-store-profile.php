<?php
/**
 * Editable admin screen for the `store_profile` post type: a classic-editor
 * edit page with a "Store details" metabox for every `_shp_store_*` field.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Admin;

use ShopHarvesterPro\Includes\Entity_Manager;
use ShopHarvesterPro\Includes\Subdomain_Manager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Store profile metabox + classic-editor enforcement.
 */
class Store_Profile {

    const NONCE = 'shp_store_profile_save';

    /**
     * Register hooks.
     *
     * @return void
     */
    public function hooks() {
        add_filter( 'use_block_editor_for_post_type', array( $this, 'force_classic_editor' ), 20, 2 );
        add_filter( 'gutenberg_can_edit_post_type', array( $this, 'force_classic_editor' ), 20, 2 );
        add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
        add_action( 'save_post_' . Entity_Manager::STORE_CPT, array( $this, 'save' ), 10, 2 );
        add_filter( 'manage_' . Entity_Manager::STORE_CPT . '_posts_columns', array( $this, 'columns' ) );
        add_action( 'manage_' . Entity_Manager::STORE_CPT . '_posts_custom_column', array( $this, 'column' ), 10, 2 );

        // A page-caching plugin that caches /wp-admin/ output can serve a stale
        // edit form whose nonce fails on Update/Publish. Ask for the strictest
        // no-cache headers on this screen — best-effort, only helps a cache that
        // honours HTTP headers.
        add_action( 'load-post.php', array( $this, 'no_cache_edit_screen' ) );
        add_action( 'load-post-new.php', array( $this, 'no_cache_edit_screen' ) );
    }

    /**
     * Send strict no-cache headers when editing one of our post types.
     *
     * @return void
     */
    public function no_cache_edit_screen() {
        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '' ; // phpcs:ignore WordPress.Security.NonceVerification
        if ( '' === $post_type && isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            $post_type = (string) get_post_type( (int) $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification
        }
        if ( in_array( $post_type, array( Entity_Manager::STORE_CPT, Entity_Manager::PRODUCT_CPT ), true ) ) {
            nocache_headers();
        }
    }

    /**
     * Keep our internal post types on the classic editor.
     *
     * @param bool   $use       Whether to use the block editor.
     * @param string $post_type Post type.
     * @return bool
     */
    public function force_classic_editor( $use, $post_type ) {
        if ( in_array( $post_type, array( Entity_Manager::STORE_CPT, Entity_Manager::PRODUCT_CPT ), true ) ) {
            return false;
        }

        return $use;
    }

    /**
     * Register the details metabox.
     *
     * @return void
     */
    public function add_metabox() {
        add_meta_box(
            'shp_store_details',
            __( 'اطلاعات فروشگاه', 'shopharvester-pro' ),
            array( $this, 'render' ),
            Entity_Manager::STORE_CPT,
            'normal',
            'high'
        );
    }

    /**
     * The fields we manage, in display order.
     *
     * @return array<string,array{label:string,type:string}>
     */
    private function fields() {
        return array(
            '_shp_store_brand'   => array( 'label' => __( 'برند / نام نمایشی', 'shopharvester-pro' ), 'type' => 'text' ),
            '_shp_store_domain'  => array( 'label' => __( 'دامنه', 'shopharvester-pro' ), 'type' => 'text' ),
            '_shp_store_email'   => array( 'label' => __( 'ایمیل', 'shopharvester-pro' ), 'type' => 'email' ),
            '_shp_store_phone'   => array( 'label' => __( 'تلفن', 'shopharvester-pro' ), 'type' => 'text' ),
            '_shp_store_address' => array( 'label' => __( 'نشانی', 'shopharvester-pro' ), 'type' => 'textarea' ),
            '_shp_store_logo'    => array( 'label' => __( 'نشانی تصویر لوگو', 'shopharvester-pro' ), 'type' => 'url' ),
        );
    }

    /**
     * Render the metabox.
     *
     * @param \WP_Post $post Store post.
     * @return void
     */
    public function render( $post ) {
        wp_nonce_field( self::NONCE, self::NONCE );

        $vendor_id  = (int) get_post_meta( $post->ID, '_shp_store_vendor_id', true );
        $store_url  = Subdomain_Manager::store_url( $post->ID );
        $socials    = get_post_meta( $post->ID, '_shp_store_socials', true );
        $socials    = is_array( $socials ) ? $socials : array();
        $social_txt = '';
        foreach ( $socials as $k => $v ) {
            $social_txt .= $k . ' | ' . $v . "\n";
        }

        echo '<style>.shp-spf label{display:block;font-weight:600;margin:12px 0 4px}.shp-spf input,.shp-spf textarea{width:100%;max-width:520px}.shp-spf textarea{min-height:70px}</style>';
        echo '<div class="shp-spf">';

        foreach ( $this->fields() as $key => $f ) {
            $val = (string) get_post_meta( $post->ID, $key, true );
            printf( '<label for="%1$s">%2$s</label>', esc_attr( $key ), esc_html( $f['label'] ) );
            if ( 'textarea' === $f['type'] ) {
                printf( '<textarea id="%1$s" name="%1$s">%2$s</textarea>', esc_attr( $key ), esc_textarea( $val ) );
            } else {
                printf( '<input type="%3$s" id="%1$s" name="%1$s" value="%2$s" />', esc_attr( $key ), esc_attr( $val ), esc_attr( $f['type'] ) );
            }
        }

        echo '<label for="shp_store_socials">' . esc_html__( 'شبکه‌های اجتماعی (هر خط: نام | نشانی)', 'shopharvester-pro' ) . '</label>';
        printf( '<textarea id="shp_store_socials" name="shp_store_socials">%s</textarea>', esc_textarea( trim( $social_txt ) ) );

        echo '<hr />';
        if ( $vendor_id ) {
            printf(
                '<p><strong>%1$s</strong> <a href="%2$s">%3$s</a></p>',
                esc_html__( 'کاربر فروشنده:', 'shopharvester-pro' ),
                esc_url( get_edit_user_link( $vendor_id ) ),
                esc_html( get_the_author_meta( 'display_name', $vendor_id ) ?: ( '#' . $vendor_id ) )
            );
        }
        if ( $store_url ) {
            printf(
                '<p><strong>%1$s</strong> <a href="%2$s" target="_blank" rel="noreferrer">%2$s</a></p>',
                esc_html__( 'صفحه‌ی فروشگاه (WCFM):', 'shopharvester-pro' ),
                esc_url( $store_url )
            );
        }

        $listing_id = (int) get_post_meta( $post->ID, '_shp_shop_listing_id', true );
        if ( $listing_id && 'rtcl_listing' === get_post_type( $listing_id ) ) {
            printf(
                '<p><strong>%1$s</strong> <a href="%2$s" target="_blank" rel="noreferrer">%2$s</a></p>',
                esc_html__( 'آگهیِ فروشگاه:', 'shopharvester-pro' ),
                esc_url( get_permalink( $listing_id ) )
            );
        }

        echo '<p class="description">' . esc_html__( 'عنوانِ این نوشته = نام فروشگاه. متنِ ویرایشگر = توضیحاتِ فروشگاه. با ذخیره، همه‌ی این اطلاعات به «آگهیِ فروشگاه» و «فروشگاهِ WCFM» بالا هم منتقل می‌شود.', 'shopharvester-pro' ) . '</p>';
        echo '</div>';
    }

    /**
     * Persist the metabox fields.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post.
     * @return void
     */
    public function save( $post_id, $post ) {
        if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        foreach ( $this->fields() as $key => $f ) {
            if ( ! isset( $_POST[ $key ] ) ) {
                continue;
            }
            $raw = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            switch ( $f['type'] ) {
                case 'email':
                    $val = sanitize_email( $raw );
                    break;
                case 'url':
                    $val = esc_url_raw( $raw );
                    break;
                case 'textarea':
                    $val = sanitize_textarea_field( $raw );
                    break;
                default:
                    $val = sanitize_text_field( $raw );
            }
            update_post_meta( $post_id, $key, $val );
        }

        if ( isset( $_POST['shp_store_socials'] ) ) {
            $lines   = preg_split( '/\r\n|\r|\n/', sanitize_textarea_field( wp_unslash( $_POST['shp_store_socials'] ) ) );
            $socials = array();
            foreach ( (array) $lines as $line ) {
                if ( false === strpos( $line, '|' ) ) {
                    continue;
                }
                list( $name, $url ) = array_map( 'trim', explode( '|', $line, 2 ) );
                $url = esc_url_raw( $url );
                if ( '' !== $name && '' !== $url ) {
                    $socials[ sanitize_key( $name ) ] = $url;
                }
            }
            update_post_meta( $post_id, '_shp_store_socials', $socials );
        }

        // Push the same data outward to the two places a shopper actually sees
        // the shop: its Classified Listing entry (/listing/…) and its WCFM
        // vendor storefront (/store/…).
        $fresh = get_post( $post_id );
        $data  = array(
            'title'    => $fresh ? $fresh->post_title : get_the_title( $post_id ),
            'content'  => $fresh ? $fresh->post_content : '',
            'phone'    => (string) get_post_meta( $post_id, '_shp_store_phone', true ),
            'email'    => (string) get_post_meta( $post_id, '_shp_store_email', true ),
            'address'  => (string) get_post_meta( $post_id, '_shp_store_address', true ),
            'socials'  => (array) get_post_meta( $post_id, '_shp_store_socials', true ),
            'logo_id'  => (int) get_post_thumbnail_id( $post_id ),
        );

        $this->sync_shop_listing( $post_id, $data );
        $this->sync_wcfm_vendor( $post_id, $data );
    }

    /**
     * Mirror the profile onto the linked Classified Listing shop entry.
     *
     * @param int   $store_id Store profile ID.
     * @param array $data     Field bundle.
     * @return void
     */
    private function sync_shop_listing( $store_id, array $data ) {
        $listing_id = (int) get_post_meta( $store_id, '_shp_shop_listing_id', true );
        if ( ! $listing_id || 'rtcl_listing' !== get_post_type( $listing_id ) || 'trash' === get_post_status( $listing_id ) ) {
            return;
        }

        $update = array( 'ID' => $listing_id );
        if ( '' !== trim( (string) $data['title'] ) && get_post_field( 'post_title', $listing_id ) !== $data['title'] ) {
            $update['post_title'] = $data['title'];
        }
        if ( get_post_field( 'post_content', $listing_id ) !== $data['content'] ) {
            $update['post_content'] = $data['content'];
        }
        if ( count( $update ) > 1 ) {
            wp_update_post( $update );
        }

        if ( '' !== $data['phone'] ) {
            update_post_meta( $listing_id, 'phone', $data['phone'] );
            update_post_meta( $listing_id, '_rtcl_phone', $data['phone'] );
        }
        if ( '' !== $data['email'] ) {
            update_post_meta( $listing_id, 'email', $data['email'] );
            update_post_meta( $listing_id, '_rtcl_email', $data['email'] );
        }
        if ( '' !== $data['address'] ) {
            update_post_meta( $listing_id, 'address', $data['address'] );
            update_post_meta( $listing_id, '_rtcl_address', $data['address'] );
        }
        if ( $data['socials'] ) {
            update_post_meta( $listing_id, '_rtcl_social_profiles', $data['socials'] );
        }
        if ( $data['logo_id'] && ! has_post_thumbnail( $listing_id ) ) {
            set_post_thumbnail( $listing_id, $data['logo_id'] );
        }
    }

    /**
     * Mirror the profile onto the WCFM vendor storefront settings.
     *
     * @param int   $store_id Store profile ID.
     * @param array $data     Field bundle.
     * @return void
     */
    private function sync_wcfm_vendor( $store_id, array $data ) {
        $vendor_id = (int) get_post_meta( $store_id, '_shp_store_vendor_id', true );
        if ( ! $vendor_id || ! function_exists( 'wcfm_is_vendor' ) || ! wcfm_is_vendor( $vendor_id ) ) {
            return;
        }

        $title = (string) $data['title'];
        if ( '' !== $title ) {
            update_user_meta( $vendor_id, 'wcfmmp_store_name', $title );
        }

        $settings = get_user_meta( $vendor_id, 'wcfmmp_profile_settings', true );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }
        if ( '' !== $title ) {
            $settings['store_name'] = $title;
        }
        if ( '' !== $data['phone'] ) {
            $settings['phone'] = $data['phone'];
        }
        if ( '' !== $data['email'] ) {
            $settings['store_email'] = $data['email'];
        }
        if ( '' !== trim( (string) $data['content'] ) ) {
            $settings['shop_description'] = wp_kses_post( $data['content'] );
        }
        if ( '' !== $data['address'] ) {
            $settings['address'] = isset( $settings['address'] ) && is_array( $settings['address'] )
                ? array_merge( $settings['address'], array( 'street_1' => $data['address'] ) )
                : array( 'street_1' => $data['address'] );
        }
        if ( $data['socials'] ) {
            $settings['social'] = isset( $settings['social'] ) && is_array( $settings['social'] )
                ? array_merge( $settings['social'], $data['socials'] )
                : $data['socials'];
        }

        update_user_meta( $vendor_id, 'wcfmmp_profile_settings', $settings );
        if ( $data['logo_id'] ) {
            update_user_meta( $vendor_id, '_wcfm_gallery', array() );
            $settings['gravatar'] = $data['logo_id'];
            update_user_meta( $vendor_id, 'wcfmmp_profile_settings', $settings );
        }
    }

    /**
     * Admin list columns.
     *
     * @param array $cols Columns.
     * @return array
     */
    public function columns( $cols ) {
        $out = array();
        foreach ( $cols as $k => $v ) {
            $out[ $k ] = $v;
            if ( 'title' === $k ) {
                $out['shp_domain'] = __( 'دامنه', 'shopharvester-pro' );
                $out['shp_vendor'] = __( 'فروشنده', 'shopharvester-pro' );
            }
        }

        return $out;
    }

    /**
     * Admin list column content.
     *
     * @param string $col     Column key.
     * @param int    $post_id Post ID.
     * @return void
     */
    public function column( $col, $post_id ) {
        if ( 'shp_domain' === $col ) {
            echo esc_html( (string) get_post_meta( $post_id, '_shp_store_domain', true ) );
        } elseif ( 'shp_vendor' === $col ) {
            $vid = (int) get_post_meta( $post_id, '_shp_store_vendor_id', true );
            if ( $vid ) {
                printf( '<a href="%s">%s</a>', esc_url( get_edit_user_link( $vid ) ), esc_html( get_the_author_meta( 'display_name', $vid ) ?: ( '#' . $vid ) ) );
            } else {
                echo '—';
            }
        }
    }
}
