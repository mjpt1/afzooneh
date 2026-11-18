<?php
/**
 * Front-end presentation for store profiles.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Appends store details and a product grid to the single `store_profile` view,
 * and exposes a `[shp_store_products]` shortcode for use anywhere.
 */
class Storefront {

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register() {
        add_filter( 'the_content', array( $this, 'append_store_block' ), 20 );
        add_filter( 'the_content', array( $this, 'prepend_product_block' ), 9 );
        add_filter( 'the_content', array( $this, 'append_shop_listing_products' ), 21 );
        add_filter( 'the_content', array( $this, 'append_buy_button' ), 8 );
        add_shortcode( 'shp_shop_products', array( $this, 'shop_products_shortcode' ) );
        add_shortcode( 'shp_buy_button', array( $this, 'buy_button_shortcode' ) );

        // Keep harvested product listings out of the Classified Listing
        // directory / archives — the directory lists shops, not products.
        if ( Settings::get( 'classima_hide_products_from_directory', true ) ) {
            add_filter( 'rtcl_listing_ads_args', array( $this, 'exclude_product_listings_args' ), 20 );
            add_filter( 'rtcl_widget_listings_query_params', array( $this, 'exclude_product_listings_args' ), 20 );
            add_action( 'pre_get_posts', array( $this, 'exclude_product_listings_query' ) );
        }
        add_shortcode( 'shp_store_products', array( $this, 'shortcode' ) );
        add_shortcode( 'shp_stores', array( $this, 'stores_shortcode' ) );
        add_shortcode( 'shp_offers', array( $this, 'offers_shortcode' ) );
        add_shortcode( 'shp_specs', array( $this, 'specs_shortcode' ) );
        add_shortcode( 'shp_compare', array( $this, 'compare_shortcode' ) );
        add_shortcode( 'shp_compare_button', array( $this, 'compare_button_shortcode' ) );
        add_action( 'wp_head', array( $this, 'inline_styles' ) );
        add_action( 'wp_head', array( $this, 'aggregate_offer_schema' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'compare_script' ) );

        // Classified Listing single-listing: the buy button, injected at several
        // points so it survives both the classic template and the Classima
        // single-listing layout builder. First hook to fire wins (deduped).
        add_filter( 'rtcl_get_price_html', array( $this, 'append_buy_to_price' ), 20, 2 );
        add_action( 'rtcl_single_listing_before_content', array( $this, 'echo_buy_button' ), 5 );
        add_action( 'rtcl_single_listing_content_end', array( $this, 'echo_buy_button' ), 5 );
        add_action( 'rtcl_after_single_listing', array( $this, 'echo_buy_button' ), 5 );

        // WooCommerce single-product: inject specs + offers after the summary.
        add_action( 'woocommerce_after_single_product_summary', array( $this, 'wc_after_summary' ), 6 );
        add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'wc_compare_button' ), 20 );

        // WooCommerce single-product buy button (source-shop link). Only used
        // as a fallback when the "لینک خرید در سایت فروشنده" plugin's own
        // [seller_buy_link] shortcode is not available — otherwise we just feed
        // that plugin's meta and let its button render in the template.
        if ( ! shortcode_exists( 'seller_buy_link' ) ) {
            add_filter( 'woocommerce_get_price_html', array( $this, 'wc_append_buy_to_price' ), 20, 2 );
            add_action( 'woocommerce_after_single_product', array( $this, 'wc_echo_buy_button' ), 5 );
            add_action( 'woocommerce_single_product_summary', array( $this, 'wc_echo_buy_button' ), 25 );
        }
    }

    /**
     * Compare-tool client script (localStorage list of product IDs).
     *
     * @return void
     */
    public function compare_script() {
        $handle = 'shp-compare';
        wp_register_script( $handle, '', array(), SHP_VERSION, true );
        wp_enqueue_script( $handle );
        wp_add_inline_script(
            $handle,
            'window.shpCompare=(function(){var K="shp_compare";function g(){try{return JSON.parse(localStorage.getItem(K))||[]}catch(e){return[]}}'
            . 'function s(a){try{localStorage.setItem(K,JSON.stringify(a.slice(0,6)))}catch(e){}}'
            . 'function paint(){document.querySelectorAll("[data-shp-compare]").forEach(function(b){var id=+b.getAttribute("data-shp-compare");'
            . 'b.classList.toggle("is-in",g().indexOf(id)>-1)});var c=document.getElementById("shp-compare-count");if(c)c.textContent=g().length}'
            . 'document.addEventListener("click",function(e){var b=e.target.closest("[data-shp-compare]");if(!b)return;e.preventDefault();'
            . 'var id=+b.getAttribute("data-shp-compare"),a=g(),i=a.indexOf(id);if(i>-1)a.splice(i,1);else a.push(id);s(a);paint()});'
            . 'document.addEventListener("DOMContentLoaded",paint);return{get:g,set:s,paint:paint}})();'
        );
    }

    /**
     * Render the harvested facts (gallery, price, attributes, categories) at
     * the top of a single harvested-product page, regardless of the theme.
     *
     * @param string $content Post content.
     * @return string
     */
    public function prepend_product_block( $content ) {
        if ( ! is_singular( Entity_Manager::PRODUCT_CPT ) || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        return $this->render_product_details( get_the_ID() ) . $content;
    }

    /**
     * Build the product detail block.
     *
     * @param int $product_id Product post ID.
     * @return string
     */
    private function render_product_details( $product_id ) {
        $price      = get_post_meta( $product_id, '_shp_price', true );
        $stock      = get_post_meta( $product_id, '_shp_stock', true );
        $sku        = get_post_meta( $product_id, '_shp_sku', true );
        $brand      = get_post_meta( $product_id, '_shp_brand', true );
        $source     = get_post_meta( $product_id, '_shp_source_url', true );
        $attributes = get_post_meta( $product_id, '_shp_attributes', true );
        $gallery    = get_post_meta( $product_id, '_shp_gallery_ids', true );

        $gallery_ids = is_array( $gallery ) ? array_map( 'intval', $gallery ) : array();
        if ( empty( $gallery_ids ) && has_post_thumbnail( $product_id ) ) {
            $gallery_ids = array( (int) get_post_thumbnail_id( $product_id ) );
        }

        ob_start();
        ?>
        <div class="shp-product-detail">
            <?php if ( $gallery_ids ) : ?>
                <div class="shp-pd-gallery">
                    <?php foreach ( $gallery_ids as $img_id ) : ?>
                        <?php echo wp_get_attachment_image( $img_id, 'large' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <ul class="shp-pd-facts">
                <?php if ( '' !== $price ) : ?>
                    <li><strong><?php esc_html_e( 'قیمت', 'shopharvester-pro' ); ?>:</strong> <?php echo esc_html( $price ); ?></li>
                <?php endif; ?>
                <?php if ( '' !== $stock ) : ?>
                    <li><strong><?php esc_html_e( 'موجودی', 'shopharvester-pro' ); ?>:</strong> <?php echo esc_html( 'instock' === $stock ? __( 'موجود', 'shopharvester-pro' ) : $stock ); ?></li>
                <?php endif; ?>
                <?php if ( '' !== $sku ) : ?>
                    <li><strong><?php esc_html_e( 'کد محصول', 'shopharvester-pro' ); ?>:</strong> <?php echo esc_html( $sku ); ?></li>
                <?php endif; ?>
                <?php if ( '' !== $brand ) : ?>
                    <li><strong><?php esc_html_e( 'برند', 'shopharvester-pro' ); ?>:</strong> <?php echo esc_html( $brand ); ?></li>
                <?php endif; ?>
            </ul>

            <?php
            $cats = get_the_term_list( $product_id, Entity_Manager::TAX_CAT, '<div class="shp-pd-terms"><strong>' . esc_html__( 'دسته‌بندی', 'shopharvester-pro' ) . ':</strong> ', '، ', '</div>' );
            if ( $cats && ! is_wp_error( $cats ) ) {
                echo $cats; // phpcs:ignore WordPress.Security.EscapeOutput
            }
            $tags = get_the_term_list( $product_id, Entity_Manager::TAX_TAG, '<div class="shp-pd-terms"><strong>' . esc_html__( 'برچسب‌ها', 'shopharvester-pro' ) . ':</strong> ', '، ', '</div>' );
            if ( $tags && ! is_wp_error( $tags ) ) {
                echo $tags; // phpcs:ignore WordPress.Security.EscapeOutput
            }
            ?>

            <?php if ( is_array( $attributes ) && $attributes ) : ?>
                <table class="shp-pd-attributes">
                    <?php foreach ( $attributes as $label => $value ) : ?>
                        <tr>
                            <th><?php echo esc_html( $label ); ?></th>
                            <td><?php echo esc_html( is_array( $value ) ? implode( '، ', $value ) : $value ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>

            <?php if ( $source && current_user_can( 'edit_posts' ) ) : ?>
                <p class="shp-pd-source"><a href="<?php echo esc_url( $source ); ?>" target="_blank" rel="noreferrer"><?php esc_html_e( 'منبع اصلی محصول', 'shopharvester-pro' ); ?></a></p>
            <?php endif; ?>

            <?php
            echo $this->compare_button_markup( $product_id ); // phpcs:ignore
            echo $this->render_offers( $product_id ); // phpcs:ignore
            echo $this->render_specs( $product_id );  // phpcs:ignore
            ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /* --------------------------------------------------------------------- *
     *  Marketplace: offers, specs, comparison.
     * --------------------------------------------------------------------- */

    /**
     * The full list of shop offers for a product (price comparison).
     *
     * @param int $product_id Product ID.
     * @return string
     */
    public function render_offers( $product_id ) {
        $offers = Offer_Repo::for_product( $product_id );
        if ( count( $offers ) < 1 ) {
            return '';
        }

        $min = get_post_meta( $product_id, '_shp_price_min', true );
        $max = get_post_meta( $product_id, '_shp_price_max', true );

        ob_start();
        ?>
        <div class="shp-offers">
            <h3><?php echo esc_html( sprintf( __( 'فروش این محصول در %s فروشگاه', 'shopharvester-pro' ), number_format_i18n( count( $offers ) ) ) ); ?></h3>
            <?php if ( '' !== $min ) : ?>
                <p class="shp-offers-range">
                    <?php echo esc_html( sprintf( __( 'از %1$s تا %2$s تومان', 'shopharvester-pro' ), number_format_i18n( (float) $min ), number_format_i18n( (float) $max ) ) ); ?>
                </p>
            <?php endif; ?>
            <ul class="shp-offer-list">
                <?php foreach ( $offers as $o ) :
                    $store_title = $o->shop_name ?: get_the_title( $o->store_id );
                    ?>
                    <li class="shp-offer <?php echo 'outofstock' === $o->availability ? 'is-out' : ''; ?>">
                        <span class="shp-offer-shop">
                            <?php echo esc_html( $store_title ); ?>
                            <?php if ( $o->shop_city ) : ?><small><?php echo esc_html( $o->shop_city ); ?></small><?php endif; ?>
                            <?php if ( null !== $o->shop_rating && $o->shop_rating > 0 ) : ?>
                                <span class="shp-offer-rating">★ <?php echo esc_html( number_format_i18n( (float) $o->shop_rating, 1 ) ); ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="shp-offer-price">
                            <?php if ( null !== $o->price ) : ?>
                                <?php echo esc_html( number_format_i18n( (float) $o->price ) ); ?> <small><?php esc_html_e( 'تومان', 'shopharvester-pro' ); ?></small>
                            <?php else : ?>
                                <?php esc_html_e( 'تماس بگیرید', 'shopharvester-pro' ); ?>
                            <?php endif; ?>
                        </span>
                        <?php if ( 'outofstock' === $o->availability ) : ?>
                            <span class="shp-offer-buy is-disabled"><?php esc_html_e( 'ناموجود', 'shopharvester-pro' ); ?></span>
                        <?php else : ?>
                            <a class="shp-offer-buy" href="<?php echo esc_url( $o->buy_url ); ?>" target="_blank" rel="nofollow noopener sponsored">
                                <?php echo esc_html( Settings::get( 'buy_button_text', 'خرید از فروشگاه' ) ); ?>
                            </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Grouped technical specifications table.
     *
     * @param int $product_id Product ID.
     * @return string
     */
    public function render_specs( $product_id ) {
        $specs = get_post_meta( $product_id, '_shp_specs', true );
        if ( ! is_array( $specs ) || empty( $specs ) ) {
            return '';
        }

        ob_start();
        echo '<div class="shp-specs"><h3>' . esc_html__( 'مشخصات فنی', 'shopharvester-pro' ) . '</h3>';
        foreach ( $specs as $group => $rows ) {
            if ( empty( $rows ) ) {
                continue;
            }
            echo '<table class="shp-spec-table"><caption>' . esc_html( $group ) . '</caption><tbody>';
            foreach ( (array) $rows as $k => $v ) {
                echo '<tr><th>' . esc_html( $k ) . '</th><td>' . esc_html( is_array( $v ) ? implode( '، ', $v ) : $v ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * "Add to comparison" toggle button.
     *
     * @param int $product_id Product ID.
     * @return string
     */
    public function compare_button_markup( $product_id ) {
        return sprintf(
            '<button type="button" class="shp-compare-btn" data-shp-compare="%d"><span class="shp-compare-add">%s</span><span class="shp-compare-rm">%s</span></button>',
            (int) $product_id,
            esc_html__( 'افزودن به مقایسه', 'shopharvester-pro' ),
            esc_html__( 'حذف از مقایسه', 'shopharvester-pro' )
        );
    }

    /* Shortcodes ---------------------------------------------------------- */

    /**
     * @param array $atts Attributes.
     * @return string
     */
    public function offers_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'product' => 0 ), $atts, 'shp_offers' );
        $id   = (int) $atts['product'] ?: get_the_ID();
        return $id ? $this->render_offers( $id ) : '';
    }

    /**
     * @param array $atts Attributes.
     * @return string
     */
    public function specs_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'product' => 0 ), $atts, 'shp_specs' );
        $id   = (int) $atts['product'] ?: get_the_ID();
        return $id ? $this->render_specs( $id ) : '';
    }

    /**
     * @param array $atts Attributes.
     * @return string
     */
    public function compare_button_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'product' => 0 ), $atts, 'shp_compare_button' );
        $id   = (int) $atts['product'] ?: get_the_ID();
        return $id ? $this->compare_button_markup( $id ) : '';
    }

    /**
     * The comparison page: side-by-side spec + price table.
     *
     * @param array $atts Attributes.
     * @return string
     */
    public function compare_shortcode( $atts ) {
        $ids = array();
        if ( isset( $_GET['ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            $ids = array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['ids'] ) ) ) ); // phpcs:ignore
        }
        $ids = array_slice( array_values( array_filter( array_unique( $ids ) ) ), 0, 6 );

        ob_start();
        echo '<div class="shp-compare" id="shp-compare-root" data-shp-compare-page="1">';
        echo '<p class="shp-compare-hint">' . esc_html__( 'محصولاتی که با دکمه «افزودن به مقایسه» انتخاب کرده‌اید اینجا نمایش داده می‌شوند.', 'shopharvester-pro' ) . '</p>';

        if ( $ids ) {
            echo $this->render_compare_table( $ids ); // phpcs:ignore
        } else {
            echo '<div class="shp-compare-slot"></div>';
        }
        echo '</div>';

        // Hydrate from localStorage on load.
        echo '<script>document.addEventListener("DOMContentLoaded",function(){var a=(window.shpCompare&&window.shpCompare.get())||[];'
            . 'var u=new URL(location.href);if(a.length && !u.searchParams.get("ids")){u.searchParams.set("ids",a.join(","));location.replace(u.toString());}});</script>';

        return (string) ob_get_clean();
    }

    /**
     * Build the comparison matrix for a set of products.
     *
     * @param int[] $ids Product IDs.
     * @return string
     */
    private function render_compare_table( array $ids ) {
        $ptype    = Entity_Manager::product_post_type();
        $products = array();
        foreach ( $ids as $id ) {
            if ( get_post_type( $id ) === $ptype ) {
                $products[ $id ] = $id;
            }
        }
        if ( empty( $products ) ) {
            return '<p>' . esc_html__( 'محصولی برای مقایسه نیست.', 'shopharvester-pro' ) . '</p>';
        }

        // Union of all spec keys.
        $rows = array();
        $data = array();
        foreach ( $products as $id ) {
            $specs = get_post_meta( $id, '_shp_specs', true );
            $flat  = array();
            if ( is_array( $specs ) ) {
                foreach ( $specs as $group ) {
                    foreach ( (array) $group as $k => $v ) {
                        $flat[ $k ] = is_array( $v ) ? implode( '، ', $v ) : $v;
                        $rows[ $k ] = true;
                    }
                }
            }
            $data[ $id ] = $flat;
        }

        ob_start();
        echo '<div class="shp-compare-wrap"><table class="shp-compare-table"><thead><tr><th></th>';
        foreach ( $products as $id ) {
            echo '<th><a href="' . esc_url( get_permalink( $id ) ) . '">' . get_the_post_thumbnail( $id, 'thumbnail' ) // phpcs:ignore
                . '<span>' . esc_html( get_the_title( $id ) ) . '</span></a>'
                . '<button type="button" class="shp-compare-btn" data-shp-compare="' . (int) $id . '">&times;</button></th>';
        }
        echo '</tr></thead><tbody>';

        echo '<tr><th>' . esc_html__( 'کمترین قیمت', 'shopharvester-pro' ) . '</th>';
        foreach ( $products as $id ) {
            $min = get_post_meta( $id, '_shp_price_min', true );
            echo '<td>' . ( '' !== $min ? esc_html( number_format_i18n( (float) $min ) . ' ' . __( 'تومان', 'shopharvester-pro' ) ) : '—' ) . '</td>';
        }
        echo '</tr>';

        echo '<tr><th>' . esc_html__( 'تعداد فروشگاه', 'shopharvester-pro' ) . '</th>';
        foreach ( $products as $id ) {
            echo '<td>' . esc_html( number_format_i18n( (int) get_post_meta( $id, '_shp_offer_count', true ) ) ) . '</td>';
        }
        echo '</tr>';

        foreach ( array_keys( $rows ) as $key ) {
            echo '<tr><th>' . esc_html( $key ) . '</th>';
            foreach ( $products as $id ) {
                echo '<td>' . esc_html( $data[ $id ][ $key ] ?? '—' ) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        return (string) ob_get_clean();
    }

    /* WooCommerce hooks + schema --------------------------------------- */

    /**
     * Inject offers + specs on WooCommerce single-product pages.
     *
     * @return void
     */
    public function wc_after_summary() {
        if ( ! is_product() ) {
            return;
        }
        $id = get_the_ID();
        echo $this->render_offers( $id );  // phpcs:ignore
        echo $this->render_specs( $id );   // phpcs:ignore
    }

    /**
     * Compare toggle on the WooCommerce add-to-cart area.
     *
     * @return void
     */
    public function wc_compare_button() {
        global $product;
        if ( $product ) {
            echo $this->compare_button_markup( $product->get_id() ); // phpcs:ignore
        }
    }

    /**
     * Emit Product + AggregateOffer JSON-LD on single product pages so the
     * comparison listing is eligible for rich results.
     *
     * @return void
     */
    public function aggregate_offer_schema() {
        if ( ! Settings::get( 'marketplace_mode' ) || ! is_singular( Entity_Manager::product_post_type() ) ) {
            return;
        }

        $id     = get_queried_object_id();
        $offers = Offer_Repo::for_product( $id );
        if ( count( $offers ) < 2 ) {
            return;
        }

        $prices = array();
        $shop_offers = array();
        foreach ( $offers as $o ) {
            if ( null !== $o->price ) {
                $prices[] = (float) $o->price;
            }
            $shop_offers[] = array(
                '@type'         => 'Offer',
                'price'         => null !== $o->price ? (string) $o->price : '',
                'priceCurrency' => 'IRR',
                'availability'  => 'outofstock' === $o->availability ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock',
                'url'           => $o->buy_url,
                'seller'        => array( '@type' => 'Organization', 'name' => $o->shop_name ),
            );
        }

        $schema = array(
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            'name'     => get_the_title( $id ),
            'image'    => get_the_post_thumbnail_url( $id, 'large' ) ?: '',
            'brand'    => array( '@type' => 'Brand', 'name' => (string) get_post_meta( $id, '_shp_brand', true ) ),
            'offers'   => array(
                '@type'         => 'AggregateOffer',
                'priceCurrency' => 'IRR',
                'lowPrice'      => $prices ? (string) min( $prices ) : '',
                'highPrice'     => $prices ? (string) max( $prices ) : '',
                'offerCount'    => count( $offers ),
                'offers'        => $shop_offers,
            ),
        );

        echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
    }

    /**
     * Minimal, theme-agnostic styling for the store block.
     *
     * @return void
     */
    public function inline_styles() {
        echo '<style id="shp-storefront-css">'
            . '.shp-store-meta ul{list-style:none;padding:0;display:flex;flex-wrap:wrap;gap:10px 24px}'
            . '.shp-store-socials a{margin-inline-end:8px}'
            . '.shp-product-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:20px;margin:24px 0}'
            . '.shp-product-card{border:1px solid #e2e5ee;border-radius:12px;padding:12px;text-align:center}'
            . '.shp-product-card img{max-width:100%;height:auto;border-radius:8px}'
            . '.shp-product-card h3{font-size:15px;margin:10px 0 6px}'
            . '.shp-product-price{color:#4f46e5;font-weight:700}'
            . '.shp-draft-badge{background:#fef3c7;color:#d97706;font-size:11px;padding:2px 6px;border-radius:6px}'
            . '.shp-product-detail{margin-bottom:28px}'
            . '.shp-pd-gallery{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px}'
            . '.shp-pd-gallery img{max-width:220px;height:auto;border:1px solid #e2e5ee;border-radius:10px}'
            . '.shp-pd-facts{list-style:none;padding:0;display:flex;flex-wrap:wrap;gap:8px 24px}'
            . '.shp-pd-terms{margin:6px 0}'
            . '.shp-pd-attributes{border-collapse:collapse;margin:14px 0;width:100%;max-width:520px}'
            . '.shp-pd-attributes th,.shp-pd-attributes td{border:1px solid #e2e5ee;padding:6px 10px;text-align:start;font-size:14px}'
            . '.shp-pd-source{font-size:12px;opacity:.7}'
            . '.shp-stores-search{display:flex;gap:8px;margin-bottom:18px;max-width:420px}'
            . '.shp-stores-search input{flex:1;padding:8px 12px;border:1px solid #d7dae5;border-radius:8px}'
            . '.shp-stores-grid{display:grid;grid-template-columns:repeat(var(--shp-cols,3),1fr);gap:18px}'
            . '@media(max-width:782px){.shp-stores-grid{grid-template-columns:repeat(2,1fr)}}'
            . '@media(max-width:480px){.shp-stores-grid{grid-template-columns:1fr}}'
            . '.shp-store-card{display:flex;flex-direction:column;align-items:center;gap:6px;text-align:center;padding:16px;border:1px solid #e2e5ee;border-radius:12px;text-decoration:none;color:inherit;transition:box-shadow .15s}'
            . '.shp-store-card:hover{box-shadow:0 6px 20px rgba(0,0,0,.08)}'
            . '.shp-store-card__logo img{max-width:120px;height:auto}'
            . '.shp-store-card__name{font-weight:700}'
            . '.shp-store-card__domain{font-size:12px;opacity:.6}'
            . '.shp-store-card__count{font-size:13px;color:#4f46e5}'
            . '.shp-offers{margin:20px 0;border:1px solid #e2e5ee;border-radius:12px;padding:14px 16px}'
            . '.shp-offers h3{margin:0 0 4px}'
            . '.shp-offers-range{color:#4f46e5;font-weight:700;margin:0 0 10px}'
            . '.shp-offer-list{list-style:none;margin:0;padding:0}'
            . '.shp-offer{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:10px 0;border-top:1px solid #eef0f5}'
            . '.shp-offer.is-out{opacity:.55}'
            . '.shp-offer-shop small{opacity:.6;margin-inline-start:6px}'
            . '.shp-offer-rating{color:#f59e0b;margin-inline-start:6px;font-size:13px}'
            . '.shp-offer-price{font-weight:700}'
            . '.shp-offer-buy{background:#16a34a;color:#fff;padding:8px 18px;border-radius:8px;text-decoration:none;font-weight:600;white-space:nowrap}'
            . '.shp-offer-buy.is-disabled{background:#d1d5db;color:#6b7280}'
            . '.shp-specs{margin:20px 0}'
            . '.shp-spec-table{width:100%;border-collapse:collapse;margin:8px 0 16px}'
            . '.shp-spec-table caption{text-align:start;font-weight:700;padding:6px 0;color:#4f46e5}'
            . '.shp-spec-table th,.shp-spec-table td{border:1px solid #e2e5ee;padding:7px 10px;text-align:start;font-size:14px;vertical-align:top}'
            . '.shp-spec-table th{background:#fafbff;width:40%}'
            . '.shp-compare-btn{background:#fff;border:1px solid #4f46e5;color:#4f46e5;border-radius:8px;padding:7px 14px;cursor:pointer;font-weight:600;margin:8px 0}'
            . '.shp-compare-btn.is-in{background:#4f46e5;color:#fff}'
            . '.shp-compare-btn .shp-compare-rm{display:none}.shp-compare-btn.is-in .shp-compare-add{display:none}.shp-compare-btn.is-in .shp-compare-rm{display:inline}'
            . '.shp-compare-wrap{overflow-x:auto}'
            . '.shp-compare-table{border-collapse:collapse;min-width:100%}'
            . '.shp-compare-table th,.shp-compare-table td{border:1px solid #e2e5ee;padding:8px 10px;font-size:13px;text-align:start;min-width:150px}'
            . '.shp-compare-table thead th a{display:block;text-align:center;text-decoration:none;color:inherit}'
            . '.shp-compare-table thead img{display:block;margin:0 auto 6px}'
            . '.shp-buy-box{margin:20px 0;padding:16px;border:1px solid #e2e5ee;border-radius:12px;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}'
            . '.shp-buy-box .shp-buy-price{font-size:20px;font-weight:800;color:#1f2430}'
            . '.shp-buy-btn{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#ff6a00,#ee0979);color:#fff!important;font-size:16px;font-weight:700;padding:13px 28px;border-radius:50px;text-decoration:none!important;box-shadow:0 6px 16px rgba(238,9,121,.3)}'
            . '.shp-buy-btn:hover{opacity:.94;transform:translateY(-1px)}'
            . '.shp-shop-products{margin:28px 0}'
            . '.shp-shop-products h2{margin:0 0 14px}'
            . '</style>';
    }

    /**
     * Add a "buy on the source shop" button to a harvested product listing.
     *
     * @param string $content Post content.
     * @return string
     */
    /**
     * Tracks listings whose buy button has already been output this request.
     *
     * @var array<int,bool>
     */
    private $buy_button_done = array();

    /**
     * Build the buy-button HTML for a harvested product listing.
     *
     * @param int $lid Listing ID.
     * @return string Empty when not applicable or already rendered.
     */
    private function render_buy_button( $lid ) {
        $lid = (int) $lid;
        if ( ! $lid || isset( $this->buy_button_done[ $lid ] ) ) {
            return '';
        }
        // Only the listing the page is actually about — never on related /
        // card sub-queries that reuse the price filter.
        if ( function_exists( 'get_queried_object_id' ) && get_queried_object_id() && (int) get_queried_object_id() !== $lid ) {
            return '';
        }
        if ( ! Settings::get( 'classima_buy_button', true ) ) {
            return '';
        }

        $ptype = get_post_type( $lid );
        if ( 'rtcl_listing' === $ptype ) {
            $ours = get_post_meta( $lid, '_shp_is_listing', true ) || 'shopharvester' === get_post_meta( $lid, '_rtcl_import_source', true );
        } elseif ( 'product' === $ptype ) {
            $ours = (bool) get_post_meta( $lid, '_shp_source_url', true );
        } else {
            $ours = false;
        }
        if ( ! $ours ) {
            return '';
        }

        $url = (string) get_post_meta( $lid, '_shp_source_url', true );
        if ( '' === $url ) {
            $url = (string) get_post_meta( $lid, '_rtcl_import_source_url', true );
        }
        if ( '' === $url ) {
            return '';
        }

        $this->buy_button_done[ $lid ] = true;

        $price = (string) get_post_meta( $lid, 'price', true );
        if ( '' === $price && 'product' === $ptype && function_exists( 'wc_get_product' ) ) {
            $wc_product = wc_get_product( $lid );
            $price      = $wc_product ? (string) $wc_product->get_price() : '';
        }
        $label = (string) Settings::get( 'buy_button_text', 'خرید از فروشگاه' );

        $html = '<div class="shp-buy-box">';
        if ( '' !== $price ) {
            $html .= '<span class="shp-buy-price">' . esc_html( number_format_i18n( (float) $price ) ) . ' ' . esc_html__( 'تومان', 'shopharvester-pro' ) . '</span>';
        }
        $html .= '<a class="shp-buy-btn" href="' . esc_url( $url ) . '" target="_blank" rel="nofollow noopener sponsored">'
            . '<span aria-hidden="true">&#128722;</span> ' . esc_html( $label )
            . '</a></div>';

        return $html;
    }

    /**
     * the_content: prepend the buy button on a harvested product listing.
     *
     * @param string $content Post content.
     * @return string
     */
    public function append_buy_button( $content ) {
        if ( ! is_singular( 'rtcl_listing' ) || is_admin() ) {
            return $content;
        }

        // render_buy_button() self-guards to the queried listing only, so this
        // stays safe even when the theme filters the_content off-loop.
        return $this->render_buy_button( (int) get_the_ID() ) . $content;
    }

    /**
     * Action hooks (rtcl_single_listing_*): echo the buy button.
     *
     * @param mixed $arg Hook arg — a listing object on some hooks, unused here.
     * @return void
     */
    public function echo_buy_button( $arg = null ) {
        if ( ! is_singular( 'rtcl_listing' ) ) {
            return;
        }
        echo $this->render_buy_button( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * rtcl_get_price_html filter: append the buy button right after the price.
     *
     * @param string $html    Price HTML.
     * @param object $listing  Rtcl listing model.
     * @return string
     */
    public function append_buy_to_price( $html, $listing = null ) {
        if ( ! is_singular( 'rtcl_listing' ) ) {
            return $html;
        }
        $lid = 0;
        if ( is_object( $listing ) && method_exists( $listing, 'get_id' ) ) {
            $lid = (int) $listing->get_id();
        }
        if ( ! $lid ) {
            $lid = (int) get_the_ID();
        }

        return $html . $this->render_buy_button( $lid );
    }

    /**
     * Shortcode: [shp_buy_button id="123"].
     *
     * @param array $atts Attributes.
     * @return string
     */
    public function buy_button_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'id' => 0 ), $atts, 'shp_buy_button' );
        $lid  = (int) $atts['id'] ?: (int) get_the_ID();

        return $this->render_buy_button( $lid );
    }

    /**
     * woocommerce_get_price_html: append the buy button after a harvested
     * product's price (fallback path when [seller_buy_link] is unavailable).
     *
     * @param string      $html    Price HTML.
     * @param \WC_Product $product Product.
     * @return string
     */
    public function wc_append_buy_to_price( $html, $product = null ) {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) {
            return $html;
        }
        $pid = is_object( $product ) && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : (int) get_the_ID();

        return $html . $this->render_buy_button( $pid );
    }

    /**
     * WooCommerce single-product action hooks: echo the buy button.
     *
     * @return void
     */
    public function wc_echo_buy_button() {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) {
            return;
        }
        echo $this->render_buy_button( (int) get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Append the store block on the single store profile page.
     *
     * @param string $content Post content.
     * @return string
     */
    public function append_store_block( $content ) {
        if ( ! is_singular( Entity_Manager::STORE_CPT ) || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        $store_id = get_the_ID();

        return $content . $this->render_store_meta( $store_id ) . $this->render_products( $store_id );
    }

    /**
     * Add a "not one of our product listings" clause to a Classified Listing
     * query-args array (rtcl_listing_ads_args / widget params).
     *
     * @param array $args Query args.
     * @return array
     */
    public function exclude_product_listings_args( $args ) {
        if ( ! is_array( $args ) ) {
            return $args;
        }
        $meta_query   = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
        $meta_query[] = array( 'key' => '_shp_is_listing', 'compare' => 'NOT EXISTS' );
        $args['meta_query'] = $meta_query;

        return $args;
    }

    /**
     * Same exclusion for native main queries: the rtcl_listing archive, its
     * taxonomy archives and site search.
     *
     * @param \WP_Query $query Query.
     * @return void
     */
    public function exclude_product_listings_query( $query ) {
        if ( is_admin() || ! $query->is_main_query() || $query->is_singular() ) {
            return;
        }

        $is_listing_context = ( 'rtcl_listing' === $query->get( 'post_type' ) )
            || $query->is_post_type_archive( 'rtcl_listing' )
            || $query->is_tax( array( 'rtcl_category', 'rtcl_location', 'rtcl_tag' ) )
            || ( $query->is_search() && 'rtcl_listing' === $query->get( 'post_type' ) );

        if ( ! $is_listing_context ) {
            return;
        }

        $meta_query   = (array) $query->get( 'meta_query' );
        $meta_query[] = array( 'key' => '_shp_is_listing', 'compare' => 'NOT EXISTS' );
        $query->set( 'meta_query', $meta_query );
    }

    /**
     * On a Classima "shop" listing page, append a grid of that shop's harvested
     * product listings (nested via post_parent).
     *
     * @param string $content Post content.
     * @return string
     */
    public function append_shop_listing_products( $content ) {
        if ( ! is_singular( 'rtcl_listing' ) || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }
        $shop_id = get_the_ID();
        if ( ! get_post_meta( $shop_id, '_shp_is_shop', true ) ) {
            return $content;
        }

        return $content . $this->render_shop_products( $shop_id );
    }

    /**
     * Shortcode: [shp_shop_products shop="123" limit="48"].
     *
     * @param array $atts Attributes.
     * @return string
     */
    public function shop_products_shortcode( $atts ) {
        $atts    = shortcode_atts( array( 'shop' => 0, 'limit' => 48 ), $atts, 'shp_shop_products' );
        $shop_id = (int) $atts['shop'];
        if ( ! $shop_id && is_singular( 'rtcl_listing' ) ) {
            $shop_id = get_the_ID();
        }

        return $shop_id ? $this->render_shop_products( $shop_id, (int) $atts['limit'] ) : '';
    }

    /**
     * Render the product-listing grid for a shop listing.
     *
     * @param int $shop_id Shop listing ID.
     * @param int $limit    Max items.
     * @return string
     */
    private function render_shop_products( $shop_id, $limit = 48 ) {
        $q = new \WP_Query(
            array(
                'post_type'      => 'rtcl_listing',
                'post_status'    => 'publish',
                'post_parent'    => (int) $shop_id,
                'posts_per_page' => max( 1, (int) $limit ),
                'orderby'        => 'date',
                'order'          => 'DESC',
                'no_found_rows'  => true,
            )
        );

        if ( ! $q->have_posts() ) {
            return '';
        }

        $out  = '<div class="shp-shop-products"><h2>' . esc_html__( 'محصولات این فروشگاه', 'shopharvester-pro' ) . '</h2>';
        $out .= '<div class="shp-stores-grid">';
        while ( $q->have_posts() ) {
            $q->the_post();
            $pid   = get_the_ID();
            $price = get_post_meta( $pid, 'price', true );
            $thumb = has_post_thumbnail( $pid ) ? get_the_post_thumbnail( $pid, 'medium' ) : '';
            $out  .= '<a class="shp-store-card" href="' . esc_url( get_permalink( $pid ) ) . '">'
                . ( $thumb ? '<span class="shp-store-card__logo">' . $thumb . '</span>' : '' )
                . '<span class="shp-store-card__name">' . esc_html( get_the_title( $pid ) ) . '</span>'
                . ( '' !== $price ? '<span class="shp-store-card__meta">' . esc_html( number_format_i18n( (float) $price ) ) . ' ' . esc_html__( 'تومان', 'shopharvester-pro' ) . '</span>' : '' )
                . '</a>';
        }
        wp_reset_postdata();
        $out .= '</div></div>';

        return $out;
    }

    /**
     * Shortcode handler: [shp_store_products store="123" limit="24"].
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function shortcode( $atts ) {
        $atts = shortcode_atts(
            array(
                'store' => 0,
                'limit' => 24,
            ),
            $atts,
            'shp_store_products'
        );

        $store_id = (int) $atts['store'];
        if ( ! $store_id && is_singular( Entity_Manager::STORE_CPT ) ) {
            $store_id = get_the_ID();
        }

        return $store_id ? $this->render_products( $store_id, (int) $atts['limit'] ) : '';
    }

    /**
     * Directory of every store: [shp_stores columns="3" limit="60"
     * orderby="title|products|date" order="ASC" min_products="0" search="1"].
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function stores_shortcode( $atts ) {
        $atts = shortcode_atts(
            array(
                'columns'      => 3,
                'limit'        => 60,
                'orderby'      => 'title',
                'order'        => 'ASC',
                'min_products' => 0,
                'search'       => 0,
            ),
            $atts,
            'shp_stores'
        );

        $search_term = '';
        if ( ! empty( $atts['search'] ) && isset( $_GET['store_q'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            $search_term = sanitize_text_field( wp_unslash( $_GET['store_q'] ) ); // phpcs:ignore
        }

        $query_args = array(
            'post_type'      => Entity_Manager::STORE_CPT,
            'post_status'    => 'publish',
            'posts_per_page' => max( 1, (int) $atts['limit'] ),
            'orderby'        => ( 'date' === $atts['orderby'] ) ? 'date' : 'title',
            'order'          => in_array( strtoupper( $atts['order'] ), array( 'ASC', 'DESC' ), true ) ? strtoupper( $atts['order'] ) : 'ASC',
            'no_found_rows'  => true,
        );
        if ( '' !== $search_term ) {
            $query_args['s'] = $search_term;
        }

        $query = new \WP_Query( $query_args );

        $items = array();
        foreach ( $query->posts as $post ) {
            $term_id = (int) get_post_meta( $post->ID, '_shp_store_term_id', true );
            $count   = 0;
            if ( $term_id ) {
                $term  = get_term( $term_id, Entity_Manager::TAX_STORE );
                $count = ( $term && ! is_wp_error( $term ) ) ? (int) $term->count : 0;
            }
            if ( $count < (int) $atts['min_products'] ) {
                continue;
            }
            $items[] = array( 'post' => $post, 'count' => $count );
        }
        wp_reset_postdata();

        if ( 'products' === $atts['orderby'] ) {
            usort(
                $items,
                static function ( $a, $b ) {
                    return $b['count'] <=> $a['count'];
                }
            );
        }

        $cols = max( 1, min( 6, (int) $atts['columns'] ) );

        ob_start();

        if ( ! empty( $atts['search'] ) ) {
            ?>
            <form class="shp-stores-search" method="get">
                <input type="search" name="store_q" value="<?php echo esc_attr( $search_term ); ?>" placeholder="<?php esc_attr_e( 'جستجوی فروشگاه…', 'shopharvester-pro' ); ?>" />
                <button type="submit"><?php esc_html_e( 'جستجو', 'shopharvester-pro' ); ?></button>
            </form>
            <?php
        }

        if ( empty( $items ) ) {
            echo '<p class="shp-store-empty">' . esc_html__( 'فروشگاهی یافت نشد.', 'shopharvester-pro' ) . '</p>';
            return (string) ob_get_clean();
        }

        echo '<div class="shp-stores-grid" style="--shp-cols:' . (int) $cols . '">';
        foreach ( $items as $item ) {
            $post   = $item['post'];
            $link   = get_permalink( $post );
            $domain = get_post_meta( $post->ID, '_shp_store_domain', true );
            ?>
            <a class="shp-store-card" href="<?php echo esc_url( $link ); ?>">
                <?php if ( has_post_thumbnail( $post ) ) : ?>
                    <span class="shp-store-card__logo"><?php echo get_the_post_thumbnail( $post->ID, 'medium' ); // phpcs:ignore ?></span>
                <?php endif; ?>
                <span class="shp-store-card__name"><?php echo esc_html( get_the_title( $post ) ); ?></span>
                <?php if ( $domain ) : ?>
                    <span class="shp-store-card__domain" dir="ltr"><?php echo esc_html( $domain ); ?></span>
                <?php endif; ?>
                <span class="shp-store-card__count"><?php echo esc_html( sprintf( __( '%s محصول', 'shopharvester-pro' ), number_format_i18n( $item['count'] ) ) ); ?></span>
            </a>
            <?php
        }
        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * Store contact / brand details.
     *
     * @param int $store_id Store post ID.
     * @return string
     */
    private function render_store_meta( $store_id ) {
        $rows = array(
            __( 'برند', 'shopharvester-pro' )   => get_post_meta( $store_id, '_shp_store_brand', true ),
            __( 'دامنه', 'shopharvester-pro' )  => get_post_meta( $store_id, '_shp_store_domain', true ),
            __( 'ایمیل', 'shopharvester-pro' )  => get_post_meta( $store_id, '_shp_store_email', true ),
            __( 'تلفن', 'shopharvester-pro' )   => get_post_meta( $store_id, '_shp_store_phone', true ),
            __( 'نشانی', 'shopharvester-pro' )  => get_post_meta( $store_id, '_shp_store_address', true ),
        );

        $out = '<div class="shp-store-meta"><ul>';
        foreach ( $rows as $label => $value ) {
            if ( '' !== $value ) {
                $out .= '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . '</li>';
            }
        }

        $socials = get_post_meta( $store_id, '_shp_store_socials', true );
        if ( is_array( $socials ) && $socials ) {
            $out .= '<li class="shp-store-socials">';
            foreach ( $socials as $network => $url ) {
                $out .= '<a href="' . esc_url( $url ) . '" target="_blank" rel="nofollow noopener">' . esc_html( $network ) . '</a> ';
            }
            $out .= '</li>';
        }

        $out .= '</ul></div>';

        return $out;
    }

    /**
     * Product grid for a store.
     *
     * @param int $store_id Store post ID.
     * @param int $limit    Max products.
     * @return string
     */
    private function render_products( $store_id, $limit = 24 ) {
        $term_id = (int) get_post_meta( $store_id, '_shp_store_term_id', true );
        if ( ! $term_id ) {
            return '';
        }

        // Editors also see products still in review (draft).
        $statuses = current_user_can( 'edit_posts' ) ? array( 'publish', 'draft', 'pending' ) : array( 'publish' );

        $query = new \WP_Query(
            array(
                'post_type'      => Entity_Manager::product_post_type(),
                'post_status'    => $statuses,
                'posts_per_page' => max( 1, (int) $limit ),
                'no_found_rows'  => true,
                'tax_query'      => array(
                    array(
                        'taxonomy' => Entity_Manager::TAX_STORE,
                        'field'    => 'term_id',
                        'terms'    => array( $term_id ),
                    ),
                ),
            )
        );

        if ( ! $query->have_posts() ) {
            return '<p class="shp-store-empty">' . esc_html__( 'محصولی برای نمایش وجود ندارد.', 'shopharvester-pro' ) . '</p>';
        }

        $out = '<div class="shp-product-grid">';
        while ( $query->have_posts() ) {
            $query->the_post();

            $price = get_post_meta( get_the_ID(), '_shp_price', true );
            if ( '' === $price && function_exists( 'wc_get_product' ) ) {
                $wc = wc_get_product( get_the_ID() );
                if ( $wc ) {
                    $price = wp_strip_all_tags( $wc->get_price_html() );
                }
            }
            $status = get_post_status();
            $badge  = ( 'publish' !== $status ) ? ' <span class="shp-draft-badge">' . esc_html__( 'پیش‌نویس', 'shopharvester-pro' ) . '</span>' : '';

            $out .= '<article class="shp-product-card">';
            if ( has_post_thumbnail() ) {
                $out .= '<a href="' . esc_url( get_permalink() ) . '">' . get_the_post_thumbnail( get_the_ID(), 'medium' ) . '</a>';
            }
            $out .= '<h3><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a>' . $badge . '</h3>';
            if ( '' !== $price ) {
                $out .= '<div class="shp-product-price">' . esc_html( $price ) . '</div>';
            }
            $out .= '</article>';
        }
        $out .= '</div>';

        wp_reset_postdata();

        return $out;
    }
}
