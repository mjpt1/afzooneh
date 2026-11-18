<?php
/**
 * Read/write access to shop offers (the price-comparison rows).
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Each row in `shp_offers` links one canonical product to one shop, with that
 * shop's price and the outbound "buy" URL. A product with several offers is a
 * price-comparison listing.
 */
class Offer_Repo {

    /**
     * The offers table name.
     *
     * @return string
     */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'shp_offers';
    }

    /**
     * Insert or update an offer (keyed by store + source URL).
     *
     * @param array $data { product_id, store_id, shop_name, shop_city,
     *                       shop_rating, price, regular_price, currency,
     *                       availability, buy_url }.
     * @return int Offer ID.
     */
    public static function upsert( array $data ) {
        global $wpdb;

        $buy_url = esc_url_raw( $data['buy_url'] ?? '' );
        $hash    = sha1( strtolower( trim( $buy_url ) ) );
        $now     = current_time( 'mysql' );

        $row = array(
            'product_id'    => (int) ( $data['product_id'] ?? 0 ),
            'store_id'      => (int) ( $data['store_id'] ?? 0 ),
            'shop_name'     => sanitize_text_field( $data['shop_name'] ?? '' ),
            'shop_city'     => sanitize_text_field( $data['shop_city'] ?? '' ),
            'shop_rating'   => isset( $data['shop_rating'] ) && '' !== $data['shop_rating'] ? (float) $data['shop_rating'] : null,
            'price'         => self::to_decimal( $data['price'] ?? '' ),
            'regular_price' => self::to_decimal( $data['regular_price'] ?? '' ),
            'currency'      => sanitize_text_field( $data['currency'] ?? 'IRT' ),
            'availability'  => in_array( $data['availability'] ?? '', array( 'instock', 'outofstock' ), true ) ? $data['availability'] : 'instock',
            'buy_url'       => $buy_url,
            'source_url_hash' => $hash,
            'last_seen'     => $now,
            'is_active'     => 1,
        );

        $existing = $wpdb->get_var( // phpcs:ignore
            $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE store_id = %d AND source_url_hash = %s', $row['store_id'], $hash )
        );

        if ( $existing ) {
            $wpdb->update( self::table(), $row, array( 'id' => (int) $existing ) ); // phpcs:ignore
            $id = (int) $existing;
        } else {
            $row['first_seen'] = $now;
            $wpdb->insert( self::table(), $row ); // phpcs:ignore
            $id = (int) $wpdb->insert_id;
        }

        self::refresh_product_aggregates( $row['product_id'] );

        return $id;
    }

    /**
     * All active offers for a product, cheapest first.
     *
     * @param int $product_id Product ID.
     * @return array<int, object>
     */
    public static function for_product( $product_id ) {
        global $wpdb;

        return (array) $wpdb->get_results( // phpcs:ignore
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE product_id = %d AND is_active = 1
                 ORDER BY (price IS NULL) ASC, price ASC',
                (int) $product_id
            )
        );
    }

    /**
     * Count of active offers for a product.
     *
     * @param int $product_id Product ID.
     * @return int
     */
    public static function count_for_product( $product_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( // phpcs:ignore
            $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE product_id = %d AND is_active = 1', (int) $product_id )
        );
    }

    /**
     * Recompute the cached min/max price + offer count on the product meta, and
     * (for WooCommerce external products) point the buy button at the cheapest
     * offer.
     *
     * @param int $product_id Product ID.
     * @return void
     */
    public static function refresh_product_aggregates( $product_id ) {
        $product_id = (int) $product_id;
        if ( ! $product_id ) {
            return;
        }

        global $wpdb;
        $row = $wpdb->get_row( // phpcs:ignore
            $wpdb->prepare(
                'SELECT MIN(price) min_price, MAX(price) max_price, COUNT(*) n
                 FROM ' . self::table() . ' WHERE product_id = %d AND is_active = 1 AND price IS NOT NULL',
                $product_id
            )
        );

        $count = self::count_for_product( $product_id );

        update_post_meta( $product_id, '_shp_offer_count', $count );
        update_post_meta( $product_id, '_shp_price_min', $row && null !== $row->min_price ? $row->min_price : '' );
        update_post_meta( $product_id, '_shp_price_max', $row && null !== $row->max_price ? $row->max_price : '' );

        $cheapest = $wpdb->get_row( // phpcs:ignore
            $wpdb->prepare(
                'SELECT buy_url, price FROM ' . self::table() . '
                 WHERE product_id = %d AND is_active = 1 AND buy_url <> ""
                 ORDER BY (price IS NULL) ASC, price ASC LIMIT 1',
                $product_id
            )
        );

        if ( $cheapest ) {
            update_post_meta( $product_id, '_shp_buy_url', $cheapest->buy_url );

            if ( 'external' === (string) Settings::get( 'buy_button_mode', 'external' ) && function_exists( 'wc_get_product' ) ) {
                $wc = wc_get_product( $product_id );
                if ( $wc && $wc->is_type( 'external' ) ) {
                    $wc->set_product_url( $cheapest->buy_url );
                    if ( '' !== $cheapest->price ) {
                        $wc->set_regular_price( (string) $cheapest->price );
                    }
                    $wc->set_button_text( (string) Settings::get( 'buy_button_text', 'خرید از فروشگاه' ) );
                    $wc->save();
                }
            }
        }
    }

    /**
     * Delete one offer and refresh the product aggregates.
     *
     * @param int $offer_id Offer ID.
     * @return bool
     */
    public static function delete( $offer_id ) {
        global $wpdb;
        $product_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT product_id FROM ' . self::table() . ' WHERE id = %d', (int) $offer_id ) ); // phpcs:ignore
        $ok         = (bool) $wpdb->delete( self::table(), array( 'id' => (int) $offer_id ) ); // phpcs:ignore
        if ( $product_id ) {
            self::refresh_product_aggregates( $product_id );
        }
        return $ok;
    }

    /**
     * Add or update a manual offer from the admin metabox.
     *
     * @param int   $product_id Product ID.
     * @param array $fields     { shop_name, price, availability, buy_url }.
     * @return int
     */
    public static function manual_upsert( $product_id, array $fields ) {
        return self::upsert(
            array(
                'product_id'   => (int) $product_id,
                'store_id'     => 0,
                'shop_name'    => $fields['shop_name'] ?? '',
                'price'        => $fields['price'] ?? '',
                'availability' => $fields['availability'] ?? 'instock',
                'buy_url'      => $fields['buy_url'] ?? '',
            )
        );
    }

    /**
     * Deactivate offers of a store/product not seen in the latest crawl.
     *
     * @param int    $store_id  Store ID.
     * @param string $before    MySQL datetime.
     * @return int Rows affected.
     */
    public static function deactivate_stale( $store_id, $before ) {
        global $wpdb;
        return (int) $wpdb->query( // phpcs:ignore
            $wpdb->prepare(
                'UPDATE ' . self::table() . ' SET is_active = 0 WHERE store_id = %d AND last_seen < %s AND is_active = 1',
                (int) $store_id,
                $before
            )
        );
    }

    /**
     * Normalise a price string to a decimal or null.
     *
     * @param mixed $value Raw.
     * @return float|null
     */
    private static function to_decimal( $value ) {
        $value = strtr( (string) $value, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٬' => '', ',' => '', '،' => '' ) );
        if ( ! preg_match( '/\d/', $value ) ) {
            return null;
        }
        $value = preg_replace( '/[^\d.]/', '', $value );
        $parts = explode( '.', $value );
        if ( count( $parts ) > 2 ) {
            $value = array_shift( $parts ) . '.' . implode( '', $parts );
        }

        return '' === trim( $value, '.' ) ? null : (float) $value;
    }
}
