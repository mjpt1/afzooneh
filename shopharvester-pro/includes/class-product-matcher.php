<?php
/**
 * Cross-shop product matching for marketplace mode.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * In marketplace mode the same physical product sold by several shops must map
 * to ONE canonical product (with many offers). This finds that canonical
 * product — by barcode/model first, then by a normalized brand + title key,
 * then by fuzzy title within the same brand.
 */
class Product_Matcher {

    /**
     * Find the canonical product a scraped item belongs to.
     *
     * @param array $facts Normalized product facts.
     * @return int|null Existing product ID or null.
     */
    public function find( array $facts ) {
        $ptype = Entity_Manager::product_post_type();

        // 1) Exact barcode / model number.
        foreach ( array( '_shp_ean', '_shp_model' ) as $meta_key ) {
            $value = $this->identifier_value( $facts, $meta_key );
            if ( '' === $value ) {
                continue;
            }
            $found = get_posts(
                array(
                    'post_type'   => $ptype,
                    'post_status' => 'any',
                    'numberposts' => 1,
                    'fields'      => 'ids',
                    'meta_key'    => $meta_key,
                    'meta_value'  => $value,
                )
            );
            if ( ! empty( $found ) ) {
                return (int) $found[0];
            }
        }

        // 2) Normalized "brand|title" match key.
        $key = $this->match_key( $facts );
        if ( '' !== $key ) {
            $found = get_posts(
                array(
                    'post_type'   => $ptype,
                    'post_status' => 'any',
                    'numberposts' => 1,
                    'fields'      => 'ids',
                    'meta_key'    => '_shp_match_key',
                    'meta_value'  => $key,
                )
            );
            if ( ! empty( $found ) ) {
                return (int) $found[0];
            }
        }

        // 3) Fuzzy title within the same brand.
        $brand = $this->normalize( $facts['brand'] ?? '' );
        $title = $this->normalize( $facts['title'] ?? '' );
        if ( '' === $title ) {
            return null;
        }

        $threshold = (float) Settings::get( 'match_fuzzy_threshold', 90 );
        if ( $threshold <= 0 ) {
            return null;
        }

        $candidates = get_posts(
            array(
                'post_type'   => $ptype,
                'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
                'numberposts' => 40,
                'fields'      => 'ids',
                's'           => mb_substr( $facts['title'] ?? '', 0, 30 ),
                'suppress_filters' => false,
            )
        );

        foreach ( $candidates as $cand_id ) {
            if ( $brand && $this->normalize( (string) get_post_meta( $cand_id, '_shp_brand', true ) ) !== $brand ) {
                continue;
            }
            $pct = 0.0;
            similar_text( $title, $this->normalize( get_the_title( $cand_id ) ), $pct );
            if ( $pct >= $threshold ) {
                return (int) $cand_id;
            }
        }

        return null;
    }

    /**
     * Store the identifiers + match key on a (new or updated) product so future
     * matches are cheap.
     *
     * @param int   $product_id Product ID.
     * @param array $facts      Facts.
     * @return void
     */
    public function tag( $product_id, array $facts ) {
        $ean   = $this->identifier_value( $facts, '_shp_ean' );
        $model = $this->identifier_value( $facts, '_shp_model' );

        if ( '' !== $ean ) {
            update_post_meta( $product_id, '_shp_ean', $ean );
        }
        if ( '' !== $model ) {
            update_post_meta( $product_id, '_shp_model', $model );
        }

        $key = $this->match_key( $facts );
        if ( '' !== $key ) {
            update_post_meta( $product_id, '_shp_match_key', $key );
        }
    }

    /* --------------------------------------------------------------------- */

    /**
     * Pull a barcode / model value from facts (explicit field or a spec row).
     *
     * @param array  $facts    Facts.
     * @param string $meta_key Which identifier.
     * @return string
     */
    private function identifier_value( array $facts, $meta_key ) {
        if ( '_shp_ean' === $meta_key ) {
            $v = $facts['ean'] ?? ( $facts['gtin'] ?? ( $facts['barcode'] ?? '' ) );
        } else {
            $v = $facts['model'] ?? ( $facts['mpn'] ?? '' );
        }

        if ( '' === (string) $v ) {
            $v = $this->from_specs( $facts, '_shp_ean' === $meta_key ? array( 'بارکد', 'کد کالا', 'ean', 'gtin', 'شناسه کالا' ) : array( 'مدل', 'کد مدل', 'model', 'شماره فنی' ) );
        }

        $v = preg_replace( '/\s+/', '', (string) $v );
        return ( strlen( $v ) >= 4 && strlen( $v ) <= 60 ) ? $v : '';
    }

    /**
     * Look a value up inside the grouped specs structure.
     *
     * @param array $facts Facts.
     * @param array $names Candidate spec names.
     * @return string
     */
    private function from_specs( array $facts, array $names ) {
        $specs = $facts['specs'] ?? array();
        if ( ! is_array( $specs ) ) {
            return '';
        }
        $names = array_map( array( $this, 'normalize' ), $names );

        foreach ( $specs as $group ) {
            foreach ( (array) $group as $k => $val ) {
                if ( in_array( $this->normalize( (string) $k ), $names, true ) ) {
                    return (string) ( is_array( $val ) ? reset( $val ) : $val );
                }
            }
        }
        // Flat attributes fallback.
        foreach ( (array) ( $facts['attributes'] ?? array() ) as $k => $val ) {
            if ( in_array( $this->normalize( (string) $k ), $names, true ) ) {
                return (string) ( is_array( $val ) ? reset( $val ) : $val );
            }
        }

        return '';
    }

    /**
     * Deterministic "brand|title" key.
     *
     * @param array $facts Facts.
     * @return string
     */
    private function match_key( array $facts ) {
        $brand = $this->normalize( $facts['brand'] ?? '' );
        $title = $this->normalize( $facts['title'] ?? '' );
        if ( '' === $title ) {
            return '';
        }
        // Drop shop-specific noise words.
        $title = preg_replace( '/\b(اورجینال|اصل|گارانتی|ارسال رایگان|جدید|new|original)\b/u', '', $title );
        $title = trim( preg_replace( '/\s+/u', ' ', $title ) );

        return sha1( $brand . '|' . $title );
    }

    /**
     * @param string $value Raw.
     * @return string
     */
    private function normalize( $value ) {
        $value = strtr(
            (string) $value,
            array( 'ي' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا',
                '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                "\xE2\x80\x8C" => ' ', 'ـ' => '' )
        );
        $value = preg_replace( '/[\p{P}\p{S}]+/u', ' ', $value );
        $value = trim( preg_replace( '/\s+/u', ' ', $value ) );

        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
    }
}
