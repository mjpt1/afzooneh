<?php
/**
 * Yoast SEO meta integration.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Writes enrichment output straight into the Yoast SEO post-meta keys.
 * Falls back gracefully (still stores the meta) when Yoast is inactive.
 */
class Yoast_Bridge {

    /**
     * Persist SEO metadata for a product.
     *
     * @param int   $product_id  Product post ID.
     * @param array $enrichment  Normalized AI enrichment array.
     * @return void
     */
    public function apply( $product_id, array $enrichment ) {
        $title    = $enrichment['yoast_title'] ?? '';
        $metadesc = $enrichment['yoast_metadesc'] ?? '';
        $focus    = $enrichment['yoast_focus_keyphrase'] ?? '';

        if ( '' !== $title ) {
            update_post_meta( $product_id, '_yoast_wpseo_title', $title );
        }
        if ( '' !== $metadesc ) {
            update_post_meta( $product_id, '_yoast_wpseo_metadesc', $metadesc );
        }
        if ( '' !== $focus ) {
            update_post_meta( $product_id, '_yoast_wpseo_focuskw', $focus );
        }

        if ( ! empty( $enrichment['image_alt_texts'] ) ) {
            update_post_meta( $product_id, '_shp_ai_image_alts', $enrichment['image_alt_texts'] );
        }

        update_post_meta( $product_id, '_shp_seo_applied_at', current_time( 'mysql' ) );

        if ( ! $this->yoast_active() ) {
            Logger::info(
                __( 'افزونه Yoast SEO فعال نیست؛ متادیتا در همان کلیدها ذخیره شد تا پس از فعال‌سازی استفاده شود.', 'shopharvester-pro' ),
                array( 'product_id' => $product_id ),
                array( 'channel' => 'yoast' )
            );
        }
    }

    /**
     * @return bool True when Yoast SEO is loaded.
     */
    public function yoast_active() {
        return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
    }
}
