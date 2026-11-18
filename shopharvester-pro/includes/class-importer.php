<?php
/**
 * Ingestion pipeline: payload -> store -> products -> enrichment -> draft.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Consumes a normalized scrape payload and materialises WordPress content.
 * Every product is stored strictly as `draft` — never auto-published.
 */
class Importer {

    /**
     * @var Entity_Manager
     */
    private $entities;

    /**
     * @var AI_Engine
     */
    private $ai;

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
        $this->entities = new Entity_Manager();
        $this->ai       = new AI_Engine();
        $this->media    = new Media_Handler();
        $this->yoast    = new Yoast_Bridge();
        $this->woo      = new Woo_Writer();
        $this->classima = new Classima_Writer();
    }

    /**
     * @var Woo_Writer
     */
    private $woo;

    /**
     * @var Classima_Writer
     */
    private $classima;

    /**
     * User ID that queued the current job (falls back to 0).
     *
     * @var int
     */
    private $operator_id = 0;

    /**
     * Stores whose directory listing has already been ensured this run.
     *
     * @var array<int,int>
     */
    private $shop_listing_done = array();

    /**
     * Resolve the operator (job creator) for author attribution.
     *
     * @param int $job_id Job ID.
     * @return int
     */
    private function resolve_operator( $job_id ) {
        global $wpdb;

        $uid = 0;
        if ( $job_id ) {
            $uid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT created_by FROM {$wpdb->prefix}shp_jobs WHERE id = %d", (int) $job_id ) ); // phpcs:ignore
        }
        if ( ! $uid && function_exists( 'get_current_user_id' ) ) {
            $uid = (int) get_current_user_id();
        }
        if ( ! $uid ) {
            // Last resort: the first administrator, so content is never authored by "nobody".
            $admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
            $uid    = $admins ? (int) $admins[0] : 0;
        }

        return $uid;
    }

    /**
     * The user a harvested product should be authored by: the source shop's
     * WCFM vendor when one exists (so it appears on that storefront), otherwise
     * the operator who queued the job. Never 0.
     *
     * @param int $store_id Store post ID.
     * @return int User ID.
     */
    private function store_author( $store_id ) {
        if ( $store_id && Settings::get( 'classima_make_vendor', true ) ) {
            $vendor_id = (int) Classima_Writer::ensure_vendor( (int) $store_id );
            if ( $vendor_id ) {
                return $vendor_id;
            }
        }
        return (int) $this->operator_id;
    }

    /**
     * Run a full import for one job payload.
     *
     * @param array $payload  { store: array, products: array[] }.
     * @param int   $job_id   Owning job ID (for log correlation).
     * @param int   $offset   Index of the first product to process this call.
     * @param int   $limit    Max products to process this call (0 = all).
     * @return array Stats { store_id, found, imported, updated, skipped, failed, processed, done }.
     */
    public function run( array $payload, $job_id = 0, $offset = 0, $limit = 0 ) {
        $stats = array(
            'store_id'  => 0,
            'found'     => 0,
            'imported'  => 0,
            'updated'   => 0,
            'skipped'   => 0,
            'failed'    => 0,
            'processed' => 0,
            'done'      => true,
        );

        $this->operator_id = $this->resolve_operator( $job_id );

        $store_data = $this->normalize_store( $payload['store'] ?? array() );
        $all        = isset( $payload['products'] ) && is_array( $payload['products'] ) ? array_values( $payload['products'] ) : array();

        $stats['found'] = count( $all );

        $offset   = max( 0, (int) $offset );
        $limit    = max( 0, (int) $limit );
        $products = $limit > 0 ? array_slice( $all, $offset, $limit ) : array_slice( $all, $offset );
        $stats['done'] = ( $offset + count( $products ) ) >= count( $all );

        try {
            $store_id          = $this->entities->resolve_store( $store_data );
            $stats['store_id'] = $store_id;
        } catch ( \Throwable $e ) {
            Logger::exception( $e, array( 'job_id' => $job_id, 'channel' => 'import' ) );
            return $stats;
        }

        $store_term_id = $this->entities->store_term_id( $store_id );

        // Stop before the PHP execution limit so a partially-processed batch is
        // saved instead of the whole request being killed mid-import.
        $exec_limit  = (int) ini_get( 'max_execution_time' );
        $deadline    = microtime( true ) + ( $exec_limit > 0 ? max( 8, (int) ( $exec_limit * 0.6 ) ) : 40 );
        $hit_deadline = false;

        foreach ( $products as $raw_product ) {
            if ( microtime( true ) >= $deadline ) {
                $hit_deadline = true;
                break;
            }
            $stats['processed']++;
            try {
                $facts               = $this->normalize_product( $raw_product );
                $facts['store_name'] = get_the_title( $store_id );

                // Reject non-product noise: empty titles, shop/category/pagination
                // and add-to-cart URLs that slipped through the scraper.
                if ( '' === trim( (string) $facts['title'] ) ) {
                    $stats['skipped']++;
                    continue;
                }
                if ( '' !== $facts['source_url'] && preg_match( '#(add-to-cart=|/shop/?($|\?)|/shop/page/|/page/\d+|/product-category/|/product-tag/|/cart/|/checkout/)#i', $facts['source_url'] ) ) {
                    $stats['skipped']++;
                    Logger::info(
                        sprintf( __( 'نشانی غیرمحصول نادیده گرفته شد: %s', 'shopharvester-pro' ), $facts['source_url'] ),
                        array(),
                        array( 'job_id' => $job_id, 'channel' => 'import', 'source_url' => $facts['source_url'] )
                    );
                    continue;
                }

                $duplicate_id = $this->find_duplicate( $facts, $store_id );

                if ( $duplicate_id && 'skip' === Settings::get( 'duplicate_product_mode' ) ) {
                    $stats['skipped']++;
                    Logger::info(
                        sprintf( __( 'محصول تکراری نادیده گرفته شد: %s', 'shopharvester-pro' ), $facts['title'] ),
                        array( 'product_id' => $duplicate_id ),
                        array( 'job_id' => $job_id, 'channel' => 'dedup', 'source_url' => $facts['source_url'] )
                    );
                    continue;
                }

                $product_id = $this->upsert_product( $facts, $store_id, $store_term_id, $duplicate_id, $job_id );

                if ( $duplicate_id ) {
                    $stats['updated']++;
                } else {
                    $stats['imported']++;
                }
            } catch ( \Throwable $e ) {
                $stats['failed']++;
                Logger::exception(
                    $e,
                    array(
                        'job_id'     => $job_id,
                        'channel'    => 'import',
                        'source_url' => $raw_product['source_url'] ?? ( $raw_product['url'] ?? '' ),
                    )
                );
            }
        }

        if ( $hit_deadline ) {
            $stats['done'] = false;
        }

        Logger::success(
            sprintf(
                __( 'دسته‌ی برداشت پردازش شد: %1$d جدید، %2$d بروزرسانی، %3$d تکراری، %4$d ناموفق.', 'shopharvester-pro' ),
                $stats['imported'],
                $stats['updated'],
                $stats['skipped'],
                $stats['failed']
            ),
            $stats,
            array( 'job_id' => $job_id, 'channel' => 'import' )
        );

        return $stats;
    }

    /* --------------------------------------------------------------------- *
     *  Product upsert.
     * --------------------------------------------------------------------- */

    /**
     * Create or update a single product post and all of its satellite data.
     *
     * @param array    $facts         Normalized facts.
     * @param int      $store_id      Store post ID.
     * @param int      $store_term_id Store term ID.
     * @param int|null $existing_id   Existing product ID when updating.
     * @param int      $job_id        Job ID.
     * @return int Product post ID.
     * @throws \RuntimeException On insert failure.
     */
    private function upsert_product( array $facts, $store_id, $store_term_id, $existing_id, $job_id ) {

        // A product an editor has locked is never modified by a re-import.
        if ( $existing_id && get_post_meta( $existing_id, '_shp_locked', true ) ) {
            Logger::info(
                sprintf( __( 'محصول قفل‌شده است و بروزرسانی نشد: %s', 'shopharvester-pro' ), get_the_title( $existing_id ) ),
                array( 'product_id' => $existing_id ),
                array( 'job_id' => $job_id, 'channel' => 'import', 'source_url' => $facts['source_url'] )
            );
            return (int) $existing_id;
        }

        // Marketplace mode: the same product from many shops maps to one
        // canonical product with many offers. Not applicable to the Classified
        // Listing output, where each row is its own listing under its store.
        if ( (bool) Settings::get( 'marketplace_mode' ) && 'classima' !== (string) Settings::get( 'product_target' ) ) {
            $matcher   = new Product_Matcher();
            $canonical = $existing_id ? (int) $existing_id : $matcher->find( $facts );

            if ( ! $canonical ) {
                $canonical = $this->build_product( $facts, $store_id, $store_term_id, null, $job_id );
            } else {
                $this->enrich_canonical( $canonical, $facts, $store_term_id );
            }

            $matcher->tag( $canonical, $facts );
            $this->attach_offer( $canonical, $facts, $store_id );
            $this->store_hash_row( $canonical, $store_id, $facts );

            Logger::success(
                sprintf( __( 'پیشنهاد فروشگاه به محصول متصل شد: %s', 'shopharvester-pro' ), get_the_title( $canonical ) ),
                array( 'product_id' => $canonical, 'store_id' => $store_id, 'price' => $facts['price'] ),
                array( 'job_id' => $job_id, 'channel' => 'import', 'source_url' => $facts['source_url'] )
            );

            return (int) $canonical;
        }

        return $this->build_product( $facts, $store_id, $store_term_id, $existing_id, $job_id );
    }

    /**
     * Create / update the actual product post + satellite data.
     *
     * @param array    $facts         Normalized facts.
     * @param int      $store_id      Store post ID.
     * @param int      $store_term_id Store term ID.
     * @param int|null $existing_id   Existing product ID when updating.
     * @param int      $job_id        Job ID.
     * @return int Product post ID.
     * @throws \RuntimeException On insert failure.
     */
    private function build_product( array $facts, $store_id, $store_term_id, $existing_id, $job_id ) {

        // Classified Listing (Classima) output path: create an rtcl_listing and,
        // when enabled, a matching WCFM vendor for the source shop.
        if ( 'classima' === (string) Settings::get( 'product_target' ) ) {
            $skip_ai    = $existing_id && 'price_stock' === (string) Settings::get( 'resync_scope', 'full' );
            $enrichment = $skip_ai ? null : $this->ai->enrich( $facts );
            $listing_id = $this->classima->upsert( $facts, $store_id, $store_term_id, $existing_id, $job_id, $enrichment, $this->operator_id );
            if ( $listing_id ) {
                $this->store_hash_row( $listing_id, $store_id, $facts );
            }
            return (int) $listing_id;
        }

        // WooCommerce output path.
        if ( 'woocommerce' === (string) Settings::get( 'product_target' ) && function_exists( 'wc_get_product' ) ) {
            $skip_ai    = $existing_id && 'price_stock' === (string) Settings::get( 'resync_scope', 'full' );
            $enrichment = $skip_ai ? null : $this->ai->enrich( $facts );
            $product_id = $this->woo->upsert( $facts, $store_id, $store_term_id, $existing_id, $enrichment, $job_id );

            // The source shop as a browsable entry in the Classified Listing
            // directory (/all-ads) — once per store per run, no per-product آگهی.
            if ( $product_id && empty( $this->shop_listing_done[ (int) $store_id ] ) && Classima_Writer::available() ) {
                $this->shop_listing_done[ (int) $store_id ] = $this->classima->ensure_shop_directory_listing( (int) $store_id, $this->operator_id );
            }

            if ( $product_id && ! $existing_id ) {
                // Author the product to the source shop's vendor so it shows on
                // that vendor's storefront; fall back to the operator, never 0.
                $author = $this->store_author( $store_id );
                if ( $author && (int) get_post_field( 'post_author', $product_id ) !== $author ) {
                    wp_update_post( array( 'ID' => (int) $product_id, 'post_author' => $author ) );
                }
                if ( $this->operator_id ) {
                    update_post_meta( (int) $product_id, '_shp_imported_by', $this->operator_id );
                }
            }
            $this->store_hash_row( $product_id, $store_id, $facts );
            return (int) $product_id;
        }

        // Lightweight re-sync: refresh only price / stock / SKU / attributes and
        // leave the human-edited title, content, images and SEO untouched.
        if ( $existing_id && 'price_stock' === (string) Settings::get( 'resync_scope', 'full' ) ) {
            update_post_meta( $existing_id, '_shp_price', $facts['price'] );
            update_post_meta( $existing_id, '_shp_stock', $facts['stock'] );
            if ( '' !== $facts['sku'] ) {
                update_post_meta( $existing_id, '_shp_sku', $facts['sku'] );
            }
            if ( ! empty( $facts['attributes'] ) ) {
                update_post_meta( $existing_id, '_shp_attributes', $facts['attributes'] );
            }
            update_post_meta( $existing_id, '_shp_last_import', current_time( 'mysql' ) );
            update_post_meta( $existing_id, '_shp_content_hash', $this->content_hash( $facts ) );
            $this->store_hash_row( $existing_id, $store_id, $facts );

            Logger::success(
                sprintf( __( 'قیمت/موجودی بروزرسانی شد: %1$s (قیمت: %2$s، موجودی: %3$s)', 'shopharvester-pro' ), get_the_title( $existing_id ), $facts['price'] ?: '—', $facts['stock'] ?: '—' ),
                array( 'product_id' => $existing_id ),
                array( 'job_id' => $job_id, 'channel' => 'import', 'source_url' => $facts['source_url'] )
            );

            return (int) $existing_id;
        }

        $enrichment = $this->ai->enrich( $facts );

        $title   = $enrichment['seo_title'] ?? $facts['title'];
        $title   = $title ?: __( 'محصول بدون عنوان', 'shopharvester-pro' );
        $content = $enrichment['long_description'] ?? $facts['description'];
        $excerpt = $enrichment['short_description'] ?? wp_trim_words( wp_strip_all_tags( $facts['description'] ), 40 );
        $slug    = $enrichment['url_slug'] ?? sanitize_title( $title );

        $postarr = array(
            'post_type'    => Entity_Manager::PRODUCT_CPT,
            'post_status'  => 'draft', // Hard rule: never publish automatically.
            'post_title'   => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_name'    => $slug,
        );

        $author = $this->store_author( $store_id );
        if ( $author ) {
            $postarr['post_author'] = $author;
        }

        if ( $existing_id ) {
            $postarr['ID'] = $existing_id;
            // Never reassign a product a human may have re-authored.
            unset( $postarr['post_author'] );

            // Preserve the editor's publish decision — a re-import must not
            // silently push a published product back to draft.
            if ( ! in_array( get_post_status( $existing_id ), array( 'draft', 'auto-draft', 'pending' ), true ) ) {
                unset( $postarr['post_status'] );
            }
            // Keep any slug the editor customised.
            unset( $postarr['post_name'] );

            $result = wp_update_post( $postarr, true );
        } else {
            $result = wp_insert_post( $postarr, true );
        }

        if ( is_wp_error( $result ) ) {
            throw new \RuntimeException( 'product_upsert_failed: ' . $result->get_error_message() );
        }

        $product_id = (int) $result;

        // Core meta.
        update_post_meta( $product_id, '_shp_store_id', $store_id );
        update_post_meta( $product_id, '_shp_source_url', $facts['source_url'] );
        update_post_meta( $product_id, '_shp_price', $facts['price'] );
        update_post_meta( $product_id, '_shp_stock', $facts['stock'] );
        update_post_meta( $product_id, '_shp_sku', $facts['sku'] );
        update_post_meta( $product_id, '_shp_brand', $facts['brand'] );
        update_post_meta( $product_id, '_shp_attributes', $facts['attributes'] );
        if ( ! empty( $facts['specs'] ) ) {
            update_post_meta( $product_id, '_shp_specs', $facts['specs'] );
        }
        if ( ! empty( $facts['model'] ) ) {
            update_post_meta( $product_id, '_shp_model', $facts['model'] );
        }
        if ( ! empty( $facts['ean'] ) ) {
            update_post_meta( $product_id, '_shp_ean', $facts['ean'] );
        }
        update_post_meta( $product_id, '_shp_source_url_hash', $this->hash( $facts['source_url'] ) );
        update_post_meta( $product_id, '_shp_content_hash', $this->content_hash( $facts ) );
        update_post_meta( $product_id, '_shp_last_import', current_time( 'mysql' ) );

        if ( $enrichment ) {
            update_post_meta( $product_id, '_shp_ai_enriched', 1 );
        }

        // Taxonomies.
        if ( $store_term_id ) {
            wp_set_object_terms( $product_id, array( (int) $store_term_id ), Entity_Manager::TAX_STORE, false );
        }
        $mapper   = new Taxonomy_Mapper();
        $resolved = $mapper->resolve( $facts, $store_id );
        foreach ( $resolved as $taxonomy => $term_ids ) {
            if ( ! empty( $term_ids ) ) {
                wp_set_object_terms( $product_id, array_map( 'intval', $term_ids ), $taxonomy, false );
            }
        }
        $tags = ! empty( $enrichment['tags'] ) ? $enrichment['tags'] : ( $facts['tags'] ?? array() );
        if ( ! empty( $tags ) ) {
            wp_set_object_terms( $product_id, $tags, Entity_Manager::TAX_TAG, false );
        }
        if ( ! empty( $facts['attributes'] ) ) {
            $attr_terms = array();
            foreach ( $facts['attributes'] as $k => $v ) {
                foreach ( (array) $v as $single ) {
                    $attr_terms[] = $k . ': ' . $single;
                }
            }
            wp_set_object_terms( $product_id, $attr_terms, Entity_Manager::TAX_ATTR, false );
        }

        // Images + featured image.
        $alt_texts = ! empty( $enrichment['image_alt_texts'] ) ? $enrichment['image_alt_texts'] : array();
        $this->media->import_for_product( $product_id, $facts['images'], $alt_texts );

        // Yoast SEO.
        if ( $enrichment ) {
            $this->yoast->apply( $product_id, $enrichment );
        }
        ( new Seo_Optimizer() )->apply( $product_id, is_array( $enrichment ) ? $enrichment : array(), $facts );

        // De-dup index row.
        $this->store_hash_row( $product_id, $store_id, $facts );

        Logger::success(
            sprintf(
                $existing_id
                    ? __( 'محصول بروزرسانی شد (پیش‌نویس): %s', 'shopharvester-pro' )
                    : __( 'محصول جدید ثبت شد (پیش‌نویس): %s', 'shopharvester-pro' ),
                $title
            ),
            array( 'product_id' => $product_id, 'store_id' => $store_id ),
            array( 'job_id' => $job_id, 'channel' => 'import', 'source_url' => $facts['source_url'] )
        );

        return $product_id;
    }

    /**
     * Attach (or refresh) this shop's offer on a canonical product and link the
     * store taxonomy without replacing other stores' links.
     *
     * @param int   $product_id Canonical product ID.
     * @param array $facts      Facts.
     * @param int   $store_id   Store post ID.
     * @return void
     */
    private function attach_offer( $product_id, array $facts, $store_id ) {
        $has_sale = '' !== (string) ( $facts['sale_price'] ?? '' );

        Offer_Repo::upsert(
            array(
                'product_id'    => $product_id,
                'store_id'      => $store_id,
                'shop_name'     => $facts['shop_name'] ?? ( $facts['store_name'] ?? get_the_title( $store_id ) ),
                'shop_city'     => $facts['shop_city'] ?? '',
                'shop_rating'   => $facts['shop_rating'] ?? '',
                'price'         => $has_sale ? $facts['sale_price'] : ( $facts['price'] ?? '' ),
                'regular_price' => $has_sale ? ( $facts['price'] ?? '' ) : '',
                'availability'  => ( 'outofstock' === (string) ( $facts['stock'] ?? '' ) ) ? 'outofstock' : 'instock',
                'buy_url'       => $facts['source_url'],
            )
        );

        $term_id = $this->entities->store_term_id( $store_id );
        if ( $term_id ) {
            wp_set_object_terms( $product_id, array( (int) $term_id ), Entity_Manager::TAX_STORE, true );
        }
    }

    /**
     * Fill only the missing fields of an existing canonical product when a new
     * shop's data arrives (never overwrite curated content).
     *
     * @param int   $product_id    Canonical product ID.
     * @param array $facts         Facts.
     * @param int   $store_term_id Unused (kept for signature symmetry).
     * @return void
     */
    private function enrich_canonical( $product_id, array $facts, $store_term_id ) {
        if ( '' === (string) get_post_meta( $product_id, '_shp_brand', true ) && ! empty( $facts['brand'] ) ) {
            update_post_meta( $product_id, '_shp_brand', sanitize_text_field( $facts['brand'] ) );
        }
        if ( empty( get_post_meta( $product_id, '_shp_specs', true ) ) && ! empty( $facts['specs'] ) ) {
            update_post_meta( $product_id, '_shp_specs', $facts['specs'] );
        }
        foreach ( array( 'model' => '_shp_model', 'ean' => '_shp_ean' ) as $k => $meta ) {
            if ( ! empty( $facts[ $k ] ) && '' === (string) get_post_meta( $product_id, $meta, true ) ) {
                update_post_meta( $product_id, $meta, sanitize_text_field( $facts[ $k ] ) );
            }
        }
        if ( ! has_post_thumbnail( $product_id ) && ! empty( $facts['images'] ) ) {
            $this->media->import_for_product( $product_id, array_slice( $facts['images'], 0, 4 ), array() );
        }

        $mapper = new Taxonomy_Mapper();
        foreach ( $mapper->resolve( $facts, 0 ) as $taxonomy => $term_ids ) {
            if ( ! empty( $term_ids ) ) {
                wp_set_object_terms( $product_id, array_map( 'intval', $term_ids ), $taxonomy, true );
            }
        }

        update_post_meta( $product_id, '_shp_last_import', current_time( 'mysql' ) );
    }

    /* --------------------------------------------------------------------- *
     *  De-duplication.
     * --------------------------------------------------------------------- */

    /**
     * Locate an existing product by source URL, content hash or SKU.
     *
     * @param array $facts    Normalized facts.
     * @param int   $store_id Store post ID.
     * @return int|null Existing product ID or null.
     */
    private function find_duplicate( array $facts, $store_id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'shp_product_hashes';

        if ( '' !== $facts['source_url'] ) {
            $id = $wpdb->get_var( // phpcs:ignore
                $wpdb->prepare( "SELECT product_id FROM {$table} WHERE source_url_hash = %s", $this->hash( $facts['source_url'] ) )
            );
            if ( $id && 'trash' !== get_post_status( $id ) ) {
                return (int) $id;
            }
        }

        if ( '' !== $facts['sku'] ) {
            $id = $wpdb->get_var( // phpcs:ignore
                $wpdb->prepare( "SELECT product_id FROM {$table} WHERE sku = %s AND store_id = %d", $facts['sku'], $store_id )
            );
            if ( $id && 'trash' !== get_post_status( $id ) ) {
                return (int) $id;
            }
        }

        $id = $wpdb->get_var( // phpcs:ignore
            $wpdb->prepare( "SELECT product_id FROM {$table} WHERE content_hash = %s AND store_id = %d", $this->content_hash( $facts ), $store_id )
        );
        if ( $id && 'trash' !== get_post_status( $id ) ) {
            return (int) $id;
        }

        return null;
    }

    /**
     * Insert / update the de-dup index row for a product.
     *
     * @param int   $product_id Product ID.
     * @param int   $store_id   Store ID.
     * @param array $facts      Facts.
     * @return void
     */
    private function store_hash_row( $product_id, $store_id, array $facts ) {
        global $wpdb;

        $table = $wpdb->prefix . 'shp_product_hashes';
        $row   = array(
            'product_id'      => $product_id,
            'store_id'        => $store_id,
            'source_url_hash' => $this->hash( $facts['source_url'] ?: ( 'pid:' . $product_id ) ),
            'content_hash'    => $this->content_hash( $facts ),
            'sku'             => $facts['sku'],
            'updated_at'      => current_time( 'mysql' ),
        );

        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE product_id = %d", $product_id ) ); // phpcs:ignore

        if ( $existing ) {
            $wpdb->update( $table, $row, array( 'id' => $existing ) ); // phpcs:ignore
        } else {
            $wpdb->insert( $table, $row ); // phpcs:ignore
        }
    }

    /**
     * @param string $value Value to hash.
     * @return string SHA1 hex.
     */
    private function hash( $value ) {
        return sha1( strtolower( trim( (string) $value ) ) );
    }

    /**
     * A stable fingerprint of the meaningful product facts.
     *
     * @param array $facts Facts.
     * @return string
     */
    private function content_hash( array $facts ) {
        $seed = wp_json_encode(
            array(
                'title'      => mb_strtolower( trim( $facts['title'] ) ),
                'sku'        => $facts['sku'],
                'brand'      => mb_strtolower( $facts['brand'] ),
                'attributes' => $facts['attributes'],
            ),
            JSON_UNESCAPED_UNICODE
        );

        return sha1( $seed );
    }

    /* --------------------------------------------------------------------- *
     *  Normalization.
     * --------------------------------------------------------------------- */

    /**
     * @param array $store Raw store payload.
     * @return array
     */
    public function normalize_store( array $store ) {
        return array(
            'name'        => sanitize_text_field( $store['name'] ?? '' ),
            'brand'       => sanitize_text_field( $store['brand'] ?? '' ),
            'domain'      => sanitize_text_field( $store['domain'] ?? ( $store['url'] ?? '' ) ),
            'email'       => sanitize_email( $store['email'] ?? '' ),
            'phone'       => sanitize_text_field( $store['phone'] ?? '' ),
            'address'     => sanitize_textarea_field( $store['address'] ?? '' ),
            'socials'     => is_array( $store['socials'] ?? null ) ? $store['socials'] : array(),
            'logo'        => esc_url_raw( $store['logo'] ?? '' ),
            'description' => wp_kses_post( $store['description'] ?? '' ),
            'source_url'  => esc_url_raw( $store['source_url'] ?? ( $store['url'] ?? '' ) ),
            'slug'        => sanitize_title( $store['slug'] ?? '' ),
        );
    }

    /**
     * @param array $product Raw product payload.
     * @return array
     */
    public function normalize_product( array $product ) {
        $images = $product['images'] ?? array();
        if ( is_string( $images ) ) {
            $images = array_filter( array_map( 'trim', explode( ',', $images ) ) );
        }

        $categories = $product['categories'] ?? array();
        if ( is_string( $categories ) ) {
            $categories = array_filter( array_map( 'trim', explode( ',', $categories ) ) );
        }

        // Ordered breadcrumb path(s): accepts a string, an array of strings,
        // or an array of segment-arrays.
        $raw_paths = $product['categories_path'] ?? ( $product['category_path'] ?? ( $product['breadcrumbs'] ?? array() ) );
        if ( is_string( $raw_paths ) ) {
            $raw_paths = array( $raw_paths );
        }
        $categories_path = array();
        foreach ( (array) $raw_paths as $entry ) {
            if ( is_array( $entry ) ) {
                $categories_path[] = array_values( array_map( 'sanitize_text_field', $entry ) );
            } else {
                $categories_path[] = sanitize_text_field( (string) $entry );
            }
        }

        $collections = $product['collections'] ?? array();
        if ( is_string( $collections ) ) {
            $collections = array_filter( array_map( 'trim', explode( ',', $collections ) ) );
        }

        $attributes = array();
        foreach ( (array) ( $product['attributes'] ?? array() ) as $key => $value ) {
            $key = sanitize_text_field( is_int( $key ) ? ( $value['name'] ?? $key ) : $key );
            $val = is_array( $value ) ? ( $value['value'] ?? $value ) : $value;
            if ( is_array( $val ) ) {
                $val = array_map( 'sanitize_text_field', $val );
            } else {
                $val = sanitize_text_field( $val );
            }
            if ( '' !== $key ) {
                $attributes[ $key ] = $val;
            }
        }

        // Grouped technical specs: { "گروه": { "کلید": "مقدار" } }. Falls back
        // to wrapping the flat attributes when the source has no groups.
        $specs = array();
        foreach ( (array) ( $product['specs'] ?? array() ) as $group => $rows ) {
            $group = sanitize_text_field( (string) $group );
            $clean = array();
            foreach ( (array) $rows as $k => $v ) {
                $k = sanitize_text_field( (string) $k );
                $v = is_array( $v ) ? implode( '، ', array_map( 'sanitize_text_field', $v ) ) : sanitize_text_field( (string) $v );
                if ( '' !== $k && '' !== $v ) {
                    $clean[ $k ] = $v;
                }
            }
            if ( $clean ) {
                $specs[ $group ?: __( 'مشخصات', 'shopharvester-pro' ) ] = $clean;
            }
        }
        if ( empty( $specs ) && ! empty( $attributes ) ) {
            $flat = array();
            foreach ( $attributes as $k => $v ) {
                $flat[ $k ] = is_array( $v ) ? implode( '، ', $v ) : $v;
            }
            $specs[ __( 'مشخصات', 'shopharvester-pro' ) ] = $flat;
        }

        // Variations (source rows: { attributes: {name:value}, price, sku, stock }).
        $variations = array();
        foreach ( (array) ( $product['variations'] ?? array() ) as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $vattrs = array();
            foreach ( (array) ( $row['attributes'] ?? array() ) as $k => $v ) {
                $k = sanitize_text_field( (string) $k );
                if ( '' !== $k ) {
                    $vattrs[ $k ] = sanitize_text_field( (string) $v );
                }
            }
            if ( $vattrs ) {
                $variations[] = array(
                    'attributes' => $vattrs,
                    'price'      => sanitize_text_field( (string) ( $row['price'] ?? '' ) ),
                    'sku'        => sanitize_text_field( (string) ( $row['sku'] ?? '' ) ),
                    'stock'      => sanitize_text_field( (string) ( $row['stock'] ?? '' ) ),
                );
            }
        }

        return array(
            'title'       => sanitize_text_field( $product['title'] ?? ( $product['name'] ?? '' ) ),
            'source_url'  => esc_url_raw( $product['source_url'] ?? ( $product['url'] ?? '' ) ),
            'description' => wp_kses_post( $product['description'] ?? '' ),
            'categories'      => array_values( array_map( 'sanitize_text_field', (array) $categories ) ),
            'categories_path' => $categories_path,
            'collections'     => array_values( array_map( 'sanitize_text_field', (array) $collections ) ),
            'tags'        => array_values( array_map( 'sanitize_text_field', (array) ( $product['tags'] ?? array() ) ) ),
            'attributes'  => $attributes,
            'specs'       => $specs,
            'variations'  => $variations,
            'brand'       => sanitize_text_field( $product['brand'] ?? ( $attributes['برند'] ?? ( $attributes['Brand'] ?? '' ) ) ),
            'model'       => sanitize_text_field( (string) ( $product['model'] ?? ( $product['mpn'] ?? '' ) ) ),
            'ean'         => sanitize_text_field( (string) ( $product['ean'] ?? ( $product['gtin'] ?? ( $product['barcode'] ?? '' ) ) ) ),
            'price'       => sanitize_text_field( (string) ( $product['price'] ?? '' ) ),
            'sale_price'  => sanitize_text_field( (string) ( $product['sale_price'] ?? '' ) ),
            'stock'       => sanitize_text_field( (string) ( $product['stock'] ?? '' ) ),
            'stock_quantity' => isset( $product['stock_quantity'] ) && is_numeric( $product['stock_quantity'] ) ? (int) $product['stock_quantity'] : '',
            'sku'         => sanitize_text_field( (string) ( $product['sku'] ?? '' ) ),
            'images'      => array_values( array_map( 'esc_url_raw', (array) $images ) ),
        );
    }
}
