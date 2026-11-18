<?php
/**
 * Shopify storefront JSON ingestion.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Most Shopify storefronts expose a public, unauthenticated `/products.json`
 * (and `/collections/<handle>/products.json`) feed. When present it is the
 * cleanest source of structured product data for that platform.
 */
class Shopify_Api {

    const UA = 'Mozilla/5.0 (compatible; ShopHarvesterBot/1.0; +https://mahsen81.ir)';

    /**
     * Attempt a Shopify harvest for a target URL.
     *
     * @param string $url Any URL on the target store.
     * @return array|null { store, products } or null when not a Shopify feed.
     */
    public function harvest( $url ) {
        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) ) {
            return null;
        }

        $origin = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'];

        // A collection URL narrows the feed; otherwise use the whole catalogue.
        $feed_base = $origin . '/products.json';
        if ( ! empty( $parts['path'] ) && preg_match( '#/collections/([^/]+)#', $parts['path'], $m ) ) {
            $feed_base = $origin . '/collections/' . $m[1] . '/products.json';
        }

        $probe = $this->get_json( $feed_base . '?limit=1' );
        if ( ! is_array( $probe ) || ! array_key_exists( 'products', $probe ) ) {
            return null;
        }

        $max      = max( 1, (int) Settings::get( 'builtin_max_products', 200 ) );
        $per_page = 250;
        $products = array();

        for ( $page = 1; count( $products ) < $max; $page++ ) {
            $data = $this->get_json( $feed_base . '?limit=' . $per_page . '&page=' . $page );
            if ( ! is_array( $data ) || empty( $data['products'] ) ) {
                break;
            }

            foreach ( $data['products'] as $raw ) {
                $products[] = $this->map_product( $raw, $origin );
                if ( count( $products ) >= $max ) {
                    break;
                }
            }

            if ( count( $data['products'] ) < $per_page ) {
                break;
            }
        }

        if ( empty( $products ) ) {
            return null;
        }

        return array(
            'store'    => $this->fetch_store( $origin, $url ),
            'products' => $products,
        );
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

        $type = wp_remote_retrieve_header( $response, 'content-type' );
        $type = is_array( $type ) ? implode( ' ', $type ) : (string) $type;
        if ( '' !== $type && false === strpos( $type, 'json' ) ) {
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        return is_array( $data ) ? $data : null;
    }

    /**
     * Map a Shopify product object to the plugin's normalized shape.
     *
     * @param array  $product Shopify product.
     * @param string $origin  Store origin.
     * @return array
     */
    private function map_product( array $product, $origin ) {
        $variants = isset( $product['variants'] ) && is_array( $product['variants'] ) ? $product['variants'] : array();
        $first    = $variants[0] ?? array();

        // compare_at_price is the "regular" price; price is the current/sale one.
        $price      = isset( $first['price'] ) && '' !== $first['price'] ? (string) $first['price'] : '';
        $sale_price = '';
        $compare    = isset( $first['compare_at_price'] ) ? (string) $first['compare_at_price'] : '';
        if ( '' !== $compare && is_numeric( $compare ) && is_numeric( $price ) && (float) $compare > (float) $price ) {
            $sale_price = $price;
            $price      = $compare;
        }

        $in_stock = false;
        foreach ( $variants as $variant ) {
            if ( ! empty( $variant['available'] ) ) {
                $in_stock = true;
                break;
            }
        }

        // Real variations when the product has more than one meaningful variant.
        $option_names = array();
        foreach ( (array) ( $product['options'] ?? array() ) as $option ) {
            $option_names[] = (string) ( $option['name'] ?? '' );
        }

        $variations = array();
        if ( count( $variants ) > 1 && $option_names && 'Title' !== ( $option_names[0] ?? '' ) ) {
            foreach ( $variants as $variant ) {
                $attrs = array();
                foreach ( $option_names as $i => $oname ) {
                    $val = $variant[ 'option' . ( $i + 1 ) ] ?? '';
                    if ( '' !== $val && 'Default Title' !== $val && '' !== $oname ) {
                        $attrs[ $oname ] = (string) $val;
                    }
                }
                if ( $attrs ) {
                    $variations[] = array(
                        'attributes' => $attrs,
                        'price'      => (string) ( $variant['price'] ?? '' ),
                        'sku'        => (string) ( $variant['sku'] ?? '' ),
                        'stock'      => ! empty( $variant['available'] ) ? 'instock' : 'outofstock',
                    );
                }
            }
        }

        // Option name => distinct values across variants.
        $attributes = array();
        foreach ( (array) ( $product['options'] ?? array() ) as $option ) {
            $name   = trim( (string) ( $option['name'] ?? '' ) );
            $values = array_values( array_unique( array_filter( (array) ( $option['values'] ?? array() ) ) ) );
            if ( '' !== $name && $values && 'Title' !== $name ) {
                $attributes[ $name ] = implode( '، ', $values );
            }
        }

        $images = array();
        foreach ( (array) ( $product['images'] ?? array() ) as $image ) {
            if ( ! empty( $image['src'] ) ) {
                $images[] = $image['src'];
            }
        }

        $categories = array_filter(
            array_merge(
                array( (string) ( $product['product_type'] ?? '' ) ),
                array_map( 'strval', (array) ( $product['tags'] ?? array() ) )
            )
        );

        return array(
            'title'            => wp_strip_all_tags( (string) ( $product['title'] ?? '' ) ),
            'source_url'       => $origin . '/products/' . ( $product['handle'] ?? '' ),
            'description'      => wp_strip_all_tags( (string) ( $product['body_html'] ?? '' ) ),
            'short_description' => '',
            'sku'             => (string) ( $first['sku'] ?? '' ),
            'brand'           => (string) ( $product['vendor'] ?? '' ),
            'price'           => $price,
            'sale_price'      => $sale_price,
            'stock'           => $in_stock ? 'instock' : 'outofstock',
            'categories'      => array_values( $categories ),
            'categories_path'  => array(),
            'collections'     => array(),
            'attributes'      => $attributes,
            'variations'      => $variations,
            'images'          => array_values( array_unique( $images ) ),
        );
    }

    /**
     * Minimal store identity for a Shopify origin.
     *
     * @param string $origin Store origin.
     * @param string $url    Target URL.
     * @return array
     */
    private function fetch_store( $origin, $url ) {
        $meta = $this->get_json( $origin . '/meta.json' ) ?: array();
        $host = wp_parse_url( $url, PHP_URL_HOST );

        return array(
            'name'        => (string) ( $meta['name'] ?? $host ),
            'brand'       => '',
            'domain'      => $host,
            'email'       => '',
            'phone'       => '',
            'address'     => '',
            'socials'     => array(),
            'logo'        => '',
            'description' => '',
            'source_url'  => $url,
        );
    }
}
