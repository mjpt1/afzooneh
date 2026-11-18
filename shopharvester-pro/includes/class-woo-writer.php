<?php
/**
 * Writes harvested products as real WooCommerce products.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Active only when `product_target = woocommerce`. Uses the WooCommerce CRUD
 * API so harvested items behave exactly like hand-made products: add-to-cart,
 * pricing, stock, gallery, attributes, and (when the source exposes them)
 * variations as a variable product.
 */
class Woo_Writer {

    /**
     * @var Media_Handler
     */
    private $media;

    /**
     * @var Yoast_Bridge
     */
    private $yoast;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->media = new Media_Handler();
        $this->yoast = new Yoast_Bridge();
    }

    /**
     * Create or update a WooCommerce product from normalized facts.
     *
     * @param array    $facts         Normalized facts.
     * @param int      $store_id      Store post ID.
     * @param int      $store_term_id Store term ID.
     * @param int|null $existing_id   Existing product ID when updating.
     * @param array|null $enrichment  AI enrichment (or null).
     * @param int      $job_id        Job ID.
     * @return int Product ID.
     * @throws \RuntimeException On failure.
     */
    public function upsert( array $facts, $store_id, $store_term_id, $existing_id, $enrichment, $job_id ) {
        if ( ! function_exists( 'wc_get_product' ) ) {
            throw new \RuntimeException( 'woocommerce_unavailable' );
        }

        $variations     = ( ! empty( $facts['variations'] ) && is_array( $facts['variations'] ) ) ? $facts['variations'] : array();
        $has_variations = count( $variations ) > 0;

        $product = $existing_id ? wc_get_product( $existing_id ) : null;
        if ( $existing_id && ! $product ) {
            $existing_id = 0;
        }

        // Lightweight re-sync.
        if ( $existing_id && 'price_stock' === (string) Settings::get( 'resync_scope', 'full' ) ) {
            $this->apply_price_stock( $product, $facts );
            $product->save();
            update_post_meta( $product->get_id(), '_shp_last_import', current_time( 'mysql' ) );

            Logger::success(
                sprintf( __( 'قیمت/موجودی محصول ووکامرس بروزرسانی شد: %s', 'shopharvester-pro' ), $product->get_name() ),
                array( 'product_id' => $product->get_id() ),
                array( 'job_id' => $job_id, 'channel' => 'import', 'source_url' => $facts['source_url'] )
            );

            return (int) $product->get_id();
        }

        // "External / Affiliate" product: the Buy button links to the source
        // shop instead of adding to cart. This is the marketplace model.
        $external = 'external' === (string) Settings::get( 'buy_button_mode', 'external' );
        if ( $external ) {
            $has_variations = false;
            $variations     = array();
        }

        $want_type = $external ? 'external' : ( $has_variations ? 'variable' : 'simple' );

        if ( ! $product || ! $product->is_type( $want_type ) ) {
            $keep_id = $product ? $product->get_id() : 0;
            if ( 'external' === $want_type ) {
                $product = new \WC_Product_External();
            } elseif ( 'variable' === $want_type ) {
                $product = new \WC_Product_Variable();
            } else {
                $product = new \WC_Product_Simple();
            }
            if ( $keep_id ) {
                $product->set_id( $keep_id );
                wp_set_object_terms( $keep_id, $want_type, 'product_type' );
            }
        }

        if ( $external ) {
            $product->set_product_url( esc_url_raw( $facts['source_url'] ) );
            $product->set_button_text( (string) Settings::get( 'buy_button_text', 'خرید از فروشگاه' ) );
        }

        $title = ( $enrichment['seo_title'] ?? '' ) ?: $facts['title'];
        $product->set_name( $title ?: __( 'محصول بدون عنوان', 'shopharvester-pro' ) );
        $product->set_description( (string) ( $enrichment['long_description'] ?? $facts['description'] ) );
        $product->set_short_description( (string) ( $enrichment['short_description'] ?? '' ) );

        if ( '' !== (string) $facts['sku'] ) {
            $sku_owner = wc_get_product_id_by_sku( $facts['sku'] );
            if ( ! $sku_owner || (int) $sku_owner === (int) $product->get_id() ) {
                $product->set_sku( $facts['sku'] );
            }
        }

        // Publish status: never downgrade an editor's published product.
        $current_status = $product->get_id() ? get_post_status( $product->get_id() ) : false;
        if ( ! in_array( $current_status, array( 'publish', 'private', 'future' ), true ) ) {
            $product->set_status( 'draft' );
        }

        $this->apply_price_stock( $product, $facts );

        $attributes = $this->build_attributes( $facts, $variations );
        $product->set_attributes( $attributes );

        // Categories + tags.
        $mapper   = new Taxonomy_Mapper();
        $resolved = $mapper->resolve( $facts, $store_id );
        $cat_ids  = $resolved['product_cat'] ?? ( $resolved[ Entity_Manager::TAX_CAT ] ?? array() );
        if ( ! empty( $cat_ids ) ) {
            $product->set_category_ids( array_values( array_map( 'intval', $cat_ids ) ) );
        }

        $tags = ! empty( $enrichment['tags'] ) ? $enrichment['tags'] : ( $facts['tags'] ?? array() );
        if ( ! empty( $tags ) ) {
            $product->set_tag_ids( $this->term_ids( (array) $tags, 'product_tag' ) );
        }

        $product_id = $product->save();
        if ( ! $product_id ) {
            throw new \RuntimeException( 'wc_product_save_failed' );
        }

        if ( ! $existing_id ) {
            $slug = ( $enrichment['url_slug'] ?? '' ) ?: sanitize_title( $title );
            if ( $slug ) {
                wp_update_post( array( 'ID' => $product_id, 'post_name' => $slug ) );
            }
        }

        if ( $store_term_id ) {
            wp_set_object_terms( $product_id, array( (int) $store_term_id ), Entity_Manager::TAX_STORE, false );
        }
        update_post_meta( $product_id, '_shp_store_id', $store_id );
        update_post_meta( $product_id, '_shp_source_url', $facts['source_url'] );
        update_post_meta( $product_id, '_shp_source_url_hash', sha1( strtolower( trim( (string) $facts['source_url'] ) ) ) );
        // Feed the "لینک خرید در سایت فروشنده" plugin's [seller_buy_link]
        // shortcode (used in the Elementor product template) so its button
        // renders automatically.
        if ( '' !== (string) $facts['source_url'] ) {
            update_post_meta( $product_id, '_sbl_seller_buy_link', esc_url_raw( (string) $facts['source_url'] ) );
        }
        update_post_meta( $product_id, '_shp_last_import', current_time( 'mysql' ) );
        if ( $enrichment ) {
            update_post_meta( $product_id, '_shp_ai_enriched', 1 );
        }

        $this->apply_images( $product_id, $facts, $enrichment );

        if ( $variations ) {
            $this->sync_variations( wc_get_product( $product_id ), $facts, $variations );
        }

        if ( $enrichment ) {
            $this->yoast->apply( $product_id, $enrichment );
        }

        // Bring the product up to the Yoast content-analysis "green" bar:
        // focus keyphrase, keyphrase-bearing meta description, an intro + H2s
        // that contain it, body length and image alt text. Uses AI output when
        // present, a fact-anchored template otherwise.
        ( new Seo_Optimizer() )->apply( $product_id, is_array( $enrichment ) ? $enrichment : array(), $facts );

        Logger::success(
            sprintf(
                $existing_id
                    ? __( 'محصول ووکامرس بروزرسانی شد (پیش‌نویس): %s', 'shopharvester-pro' )
                    : __( 'محصول ووکامرس ثبت شد (پیش‌نویس): %s', 'shopharvester-pro' ),
                $product->get_name()
            ),
            array( 'product_id' => $product_id, 'store_id' => $store_id, 'type' => $variations ? 'variable' : 'simple' ),
            array( 'job_id' => $job_id, 'channel' => 'import', 'source_url' => $facts['source_url'] )
        );

        return (int) $product_id;
    }

    /* --------------------------------------------------------------------- */

    /**
     * @param \WC_Product $product Product.
     * @param array       $facts   Facts.
     * @return void
     */
    private function apply_price_stock( $product, array $facts ) {
        $price = $this->numeric_price( $facts['price'] );
        if ( '' !== $price ) {
            $product->set_regular_price( $price );
        }

        $sale = $this->numeric_price( $facts['sale_price'] ?? '' );
        if ( '' !== $sale && ( '' === $price || (float) $sale < (float) $price ) ) {
            $product->set_sale_price( $sale );
        }

        // WC_Product_External supports neither stock status nor stock
        // management — WC throws WC_Data_Exception for that type. External
        // products only carry a price + the outbound "buy" URL.
        if ( $product->is_type( 'external' ) ) {
            return;
        }

        if ( 'instock' === $facts['stock'] ) {
            $product->set_stock_status( 'instock' );
        } elseif ( 'outofstock' === $facts['stock'] ) {
            $product->set_stock_status( 'outofstock' );
        }

        if ( isset( $facts['stock_quantity'] ) && is_numeric( $facts['stock_quantity'] ) && '' !== (string) $facts['stock_quantity'] ) {
            $product->set_manage_stock( true );
            $product->set_stock_quantity( (int) $facts['stock_quantity'] );
        }
    }

    /**
     * Normalise a price string to a plain decimal WooCommerce accepts.
     *
     * @param string $raw Raw price.
     * @return string
     */
    private function numeric_price( $raw ) {
        $raw = strtr(
            (string) $raw,
            array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٬' => '', ',' => '', '،' => '', '٫' => '.' )
        );
        if ( ! preg_match( '/\d/', $raw ) ) {
            return '';
        }
        // Drop a 1–2 digit decimal tail (".00") — Iranian prices are integers.
        $raw = preg_replace( '/(\d)\.(\d{1,2})(?!\d)/', '$1', $raw );
        // Collapse thousand separators inside a group ("199,000" -> "199000").
        do {
            $raw = preg_replace( '/(\d)[,٬،\x{066C}](\d{3})(?!\d)/u', '$1$2', $raw, -1, $count );
        } while ( $count );

        // A discounted price arrives as two amounts separated by a space or the
        // currency word ("199000 تومان159000"). Keep only the first group.
        if ( preg_match( '/\d+/', $raw, $m ) ) {
            return $m[0];
        }

        return '';
    }

    /**
     * Build the product attribute set (custom, per-product attributes).
     *
     * @param array $facts      Facts.
     * @param array $variations Variation rows (to flag variation attributes).
     * @return \WC_Product_Attribute[]
     */
    private function build_attributes( array $facts, array $variations ) {
        $vary_keys = array();
        foreach ( $variations as $v ) {
            foreach ( (array) ( $v['attributes'] ?? array() ) as $k => $_ ) {
                $vary_keys[ $k ] = true;
            }
        }

        $raw = is_array( $facts['attributes'] ?? null ) ? $facts['attributes'] : array();

        // Make sure variation attributes exist even if not in the flat list.
        foreach ( array_keys( $vary_keys ) as $k ) {
            if ( ! isset( $raw[ $k ] ) ) {
                $values = array();
                foreach ( $variations as $v ) {
                    if ( isset( $v['attributes'][ $k ] ) ) {
                        $values[] = $v['attributes'][ $k ];
                    }
                }
                $raw[ $k ] = array_values( array_unique( $values ) );
            }
        }

        $attributes = array();
        $position   = 0;
        foreach ( $raw as $name => $value ) {
            $name    = trim( (string) $name );
            $options = is_array( $value ) ? $value : array_map( 'trim', preg_split( '/[،,|\/]+/u', (string) $value ) );
            $options = array_values( array_filter( array_map( 'strval', $options ), 'strlen' ) );
            if ( '' === $name || empty( $options ) ) {
                continue;
            }

            $attr = new \WC_Product_Attribute();
            $attr->set_id( 0 );
            $attr->set_name( $name );
            $attr->set_options( $options );
            $attr->set_position( $position++ );
            $attr->set_visible( true );
            $attr->set_variation( isset( $vary_keys[ $name ] ) );
            $attributes[] = $attr;
        }

        return $attributes;
    }

    /**
     * Recreate a variable product's variations from the source rows.
     *
     * @param \WC_Product_Variable $product    Variable product.
     * @param array                $facts      Facts.
     * @param array                $variations Variation rows.
     * @return void
     */
    private function sync_variations( $product, array $facts, array $variations ) {
        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            return;
        }

        foreach ( $product->get_children() as $child_id ) {
            wp_delete_post( $child_id, true );
        }

        foreach ( $variations as $row ) {
            $variation = new \WC_Product_Variation();
            $variation->set_parent_id( $product->get_id() );

            $attrs = array();
            foreach ( (array) ( $row['attributes'] ?? array() ) as $k => $v ) {
                $attrs[ sanitize_title( $k ) ] = (string) $v;
            }
            $variation->set_attributes( $attrs );

            $price = $this->numeric_price( $row['price'] ?? ( $facts['price'] ?? '' ) );
            if ( '' !== $price ) {
                $variation->set_regular_price( $price );
            }
            if ( ! empty( $row['sku'] ) && ! wc_get_product_id_by_sku( $row['sku'] ) ) {
                $variation->set_sku( $row['sku'] );
            }
            $variation->set_stock_status( ( ( $row['stock'] ?? $facts['stock'] ) === 'outofstock' ) ? 'outofstock' : 'instock' );
            $variation->save();
        }

        \WC_Product_Variable::sync( $product->get_id() );
    }

    /**
     * Download images, set featured + gallery.
     *
     * @param int   $product_id Product ID.
     * @param array $facts      Facts.
     * @param array|null $enrichment Enrichment.
     * @return void
     */
    private function apply_images( $product_id, array $facts, $enrichment ) {
        $alt = ! empty( $enrichment['image_alt_texts'] ) ? $enrichment['image_alt_texts'] : array();
        $ids = $this->media->import_for_product( $product_id, $facts['images'], $alt );

        if ( empty( $ids ) ) {
            return;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return;
        }
        $product->set_image_id( $ids[0] );
        if ( count( $ids ) > 1 ) {
            $product->set_gallery_image_ids( array_slice( $ids, 1 ) );
        }
        $product->save();
    }

    /**
     * Resolve term names to IDs, creating missing terms.
     *
     * @param array  $names    Term names.
     * @param string $taxonomy Taxonomy.
     * @return int[]
     */
    private function term_ids( array $names, $taxonomy ) {
        $ids = array();
        foreach ( $names as $name ) {
            $name = trim( (string) $name );
            if ( '' === $name ) {
                continue;
            }
            $term = term_exists( $name, $taxonomy );
            if ( ! $term ) {
                $term = wp_insert_term( $name, $taxonomy );
            }
            if ( ! is_wp_error( $term ) ) {
                $ids[] = (int) $term['term_id'];
            }
        }

        return $ids;
    }
}
