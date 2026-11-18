<?php
/**
 * WooCommerce Store API ingestion.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The most reliable path for a WooCommerce target: the public, read-only
 * Store API (`/wp-json/wc/store/v1/products`). It returns fully structured
 * product data — names, prices, images, categories, attributes, stock — with
 * no scraping guesswork. Falls back to HTML scraping when the REST API is
 * unavailable or locked down.
 */
class Woo_Store_Api {

    const UA = 'Mozilla/5.0 (compatible; ShopHarvesterBot/1.0; +https://mahsen81.ir)';

    /**
     * Attempt a full Store API harvest for a target URL.
     *
     * @param string $url Any URL on the target site.
     * @return array|null { store, products } or null when the API is not usable.
     */
    public function harvest( $url ) {
        $root = $this->discover_rest_root( $url );
        if ( ! $root ) {
            return null;
        }

        $base  = trailingslashit( $root ) . 'wc/store/v1/';
        $probe = $this->get_json( $base . 'products?per_page=1' );
        if ( ! is_array( $probe ) ) {
            return null;
        }

        $category_map = $this->fetch_category_map( $base );
        $max          = max( 1, (int) Settings::get( 'builtin_max_products', 200 ) );
        $per_page     = 100;
        $products     = array();

        for ( $page = 1; count( $products ) < $max; $page++ ) {
            $batch = $this->get_json( $base . 'products?per_page=' . $per_page . '&page=' . $page );
            if ( ! is_array( $batch ) || empty( $batch ) ) {
                break;
            }

            foreach ( $batch as $raw ) {
                $products[] = $this->map_product( $raw, $category_map );
                if ( count( $products ) >= $max ) {
                    break;
                }
            }

            if ( count( $batch ) < $per_page ) {
                break;
            }
        }

        if ( empty( $products ) ) {
            return null;
        }

        return array(
            'store'    => $this->fetch_store( $root, $url ),
            'products' => $products,
        );
    }

    /**
     * Locate the site's REST root (handles sub-directory installs and custom
     * `rest_url` filters) via the `api.w.org` link relation, then fall back to
     * the origin's `/wp-json`.
     *
     * @param string $url Target URL.
     * @return string|null
     */
    private function discover_rest_root( $url ) {
        $response = Http_Client::get(
            $url,
            array( 'timeout' => (int) Settings::get( 'builtin_timeout', 25 ) )
        );

        if ( ! is_wp_error( $response ) ) {
            $link = wp_remote_retrieve_header( $response, 'link' );
            $link = is_array( $link ) ? implode( ',', $link ) : (string) $link;
            if ( $link && preg_match( '#<([^>]+)>;\s*rel="https://api\.w\.org/"#i', $link, $m ) ) {
                return untrailingslashit( trim( $m[1] ) );
            }

            $body = wp_remote_retrieve_body( $response );
            if (
                preg_match( '#<link[^>]+rel=["\']https://api\.w\.org/["\'][^>]+href=["\']([^"\']+)["\']#i', $body, $m ) ||
                preg_match( '#<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']https://api\.w\.org/["\']#i', $body, $m )
            ) {
                return untrailingslashit( html_entity_decode( trim( $m[1] ) ) );
            }
        }

        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) ) {
            return null;
        }

        return ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . '/wp-json';
    }

    /**
     * GET + decode a JSON endpoint.
     *
     * @param string $endpoint Absolute URL.
     * @return array|null
     */
    private function get_json( $endpoint ) {
        $response = Http_Client::get(
            $endpoint,
            array(
                'timeout' => (int) Settings::get( 'builtin_timeout', 25 ),
                'headers' => array( 'Accept' => 'application/json' ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        return is_array( $data ) ? $data : null;
    }

    /**
     * Build an id => { name, parent } map of every product category.
     *
     * @param string $base Store API base URL.
     * @return array
     */
    private function fetch_category_map( $base ) {
        $map = array();

        for ( $page = 1; $page <= 30; $page++ ) {
            $batch = $this->get_json( $base . 'products/categories?per_page=100&page=' . $page );
            if ( ! is_array( $batch ) || empty( $batch ) ) {
                break;
            }

            foreach ( $batch as $category ) {
                $map[ (int) ( $category['id'] ?? 0 ) ] = array(
                    'name'   => (string) ( $category['name'] ?? '' ),
                    'parent' => (int) ( $category['parent'] ?? 0 ),
                );
            }

            if ( count( $batch ) < 100 ) {
                break;
            }
        }

        return $map;
    }

    /**
     * Resolve a category id to an ordered root -> leaf name path.
     *
     * @param int   $id  Category id.
     * @param array $map Category map.
     * @return string[]
     */
    private function category_path( $id, array $map ) {
        $path  = array();
        $guard = 0;

        while ( $id && isset( $map[ $id ] ) && $guard++ < 10 ) {
            array_unshift( $path, $map[ $id ]['name'] );
            $id = $map[ $id ]['parent'];
        }

        return array_values( array_filter( $path, 'strlen' ) );
    }

    /**
     * Map a Store API product object to the plugin's normalized shape.
     *
     * @param array $product Store API product.
     * @param array $map     Category map.
     * @return array
     */
    private function map_product( array $product, array $map ) {
        $prices = isset( $product['prices'] ) && is_array( $product['prices'] ) ? $product['prices'] : array();
        $minor  = (int) ( $prices['currency_minor_unit'] ?? 0 );
        $price  = '';

        if ( isset( $prices['price'] ) && '' !== $prices['price'] && is_numeric( $prices['price'] ) ) {
            $amount = (float) $prices['price'] / pow( 10, max( 0, $minor ) );
            $price  = trim(
                ( $prices['currency_prefix'] ?? '' )
                . number_format( $amount, 0, '.', ',' )
                . ( $prices['currency_suffix'] ?? '' )
            );
        }

        if ( '' === $price && ! empty( $product['price_html'] ) ) {
            $price = wp_strip_all_tags( $product['price_html'] );
        }

        // Regular vs sale price from the Store API prices object.
        $regular_price = ( isset( $prices['regular_price'] ) && is_numeric( $prices['regular_price'] ) )
            ? number_format( (float) $prices['regular_price'] / pow( 10, max( 0, $minor ) ), 0, '.', ',' )
            : '';
        $sale_price = ( isset( $prices['sale_price'] ) && is_numeric( $prices['sale_price'] ) && $prices['sale_price'] !== ( $prices['regular_price'] ?? null ) )
            ? number_format( (float) $prices['sale_price'] / pow( 10, max( 0, $minor ) ), 0, '.', ',' )
            : '';
        if ( '' !== $regular_price ) {
            $price = trim( ( $prices['currency_prefix'] ?? '' ) . $regular_price . ( $prices['currency_suffix'] ?? '' ) );
        }

        $paths = array();
        foreach ( (array) ( $product['categories'] ?? array() ) as $category ) {
            $cid  = (int) ( $category['id'] ?? 0 );
            $path = $cid ? $this->category_path( $cid, $map ) : array_filter( array( (string) ( $category['name'] ?? '' ) ) );
            if ( $path ) {
                $paths[] = array_values( $path );
            }
        }

        $attributes = array();
        foreach ( (array) ( $product['attributes'] ?? array() ) as $attribute ) {
            $terms = array();
            foreach ( (array) ( $attribute['terms'] ?? array() ) as $term ) {
                $terms[] = (string) ( $term['name'] ?? '' );
            }
            $name = trim( (string) ( $attribute['name'] ?? '' ) );
            if ( '' !== $name && $terms ) {
                $attributes[ $name ] = implode( '، ', array_filter( $terms ) );
            }
        }

        $images = array();
        foreach ( (array) ( $product['images'] ?? array() ) as $image ) {
            if ( ! empty( $image['src'] ) ) {
                $images[] = $image['src'];
            }
        }

        return array(
            'title'            => wp_strip_all_tags( (string) ( $product['name'] ?? '' ) ),
            'source_url'       => esc_url_raw( (string) ( $product['permalink'] ?? '' ) ),
            'description'      => wp_strip_all_tags( (string) ( $product['description'] ?? '' ) ),
            'short_description' => wp_strip_all_tags( (string) ( $product['short_description'] ?? '' ) ),
            'sku'             => (string) ( $product['sku'] ?? '' ),
            'brand'           => '',
            'price'           => $price,
            'sale_price'      => $sale_price,
            'stock'           => ! empty( $product['is_in_stock'] ) ? 'instock' : 'outofstock',
            'categories'      => array(),
            'categories_path'  => $paths,
            'collections'     => array(),
            'attributes'      => $attributes,
            'variations'      => array(),
            'images'          => array_values( array_unique( $images ) ),
        );
    }

    /**
     * Pull the store identity from the REST index.
     *
     * @param string $root REST root.
     * @param string $url  Target URL.
     * @return array
     */
    private function fetch_store( $root, $url ) {
        $info = $this->get_json( $root ) ?: array();
        $host = wp_parse_url( $url, PHP_URL_HOST );

        return array(
            'name'        => (string) ( $info['name'] ?? $host ),
            'brand'       => '',
            'domain'      => $host,
            'email'       => '',
            'phone'       => '',
            'address'     => '',
            'socials'     => array(),
            'logo'        => (string) ( $info['site_logo_url'] ?? '' ),
            'description' => (string) ( $info['description'] ?? '' ),
            'source_url'  => $url,
        );
    }
}
