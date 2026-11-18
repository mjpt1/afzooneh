<?php
/**
 * Built-in server-side scraper for static / server-rendered stores.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Fetches a target URL with the WordPress HTTP API and extracts store +
 * product data from JSON-LD, OpenGraph and common HTML patterns. This is the
 * zero-dependency path used when no external headless runner is connected.
 * JavaScript-rendered catalogues still require the Playwright runner.
 */
class Http_Scraper {

    /**
     * Wall-clock moment after which the scrape must stop and return whatever
     * it has collected so far.
     *
     * @var float
     */
    private $deadline = 0.0;

    /**
     * When true every fetch goes through the JavaScript rendering API.
     *
     * @var bool
     */
    private $use_render = false;

    /**
     * Scrape a URL (following simple pagination) into a normalized payload.
     *
     * @param string $url        Target URL.
     * @param bool   $use_render  Route every fetch through the JS rendering API.
     * @return array { store: array, products: array }
     * @throws \RuntimeException When the page cannot be fetched.
     */
    public function scrape( $url, $use_render = false ) {
        $this->use_render = (bool) $use_render;

        $max_pages    = max( 1, (int) Settings::get( 'builtin_max_pages', 5 ) );
        $follow       = (bool) Settings::get( 'builtin_follow_products', true );
        $max_products = $this->use_render
            ? max( 1, (int) Settings::get( 'js_render_max_products', 40 ) )
            : max( 1, (int) Settings::get( 'builtin_max_products', 40 ) );

        $exec_limit     = (int) ini_get( 'max_execution_time' );
        $budget         = $exec_limit > 0 ? max( 8, (int) ( $exec_limit * 0.7 ) ) : 45;
        $this->deadline = microtime( true ) + $budget;
        $truncated      = false;

        $first_html = $this->fetch( $url );
        $first_doc  = $this->to_xpath( $first_html );

        $store = $this->extract_store( $first_doc, $url );

        // Products that already carry data (single product page / JSON-LD list
        // / listing cards) keyed by URL, plus any that have no URL at all.
        $by_url  = array();
        $no_url  = array();
        $to_follow = array();

        // Case 1: the submitted URL is itself a product page. A URL whose path
        // clearly names a category / collection / shop is never a single
        // product, even when a JS-rendered page ships an og:type=product shell
        // or a lone price string — treat those as listings so they fall
        // through to the render / runner path instead of importing a fake
        // "product" built from the page's SEO title.
        if ( ! $this->url_is_listing_path( $url )
            && $this->looks_like_product( $first_doc )
            && ! $this->looks_like_listing( $first_doc ) ) {
            $single = $this->extract_single_product( $first_doc, $url, $this->extract_breadcrumb( $first_doc ) );
            if ( $single && '' !== $single['title'] ) {
                return array( 'store' => $store, 'products' => array( $single ) );
            }
        }

        $current_url = $url;
        $current_doc = $first_doc;

        for ( $page = 0; $page < $max_pages; $page++ ) {
            $breadcrumb = $this->extract_breadcrumb( $current_doc );

            foreach ( $this->extract_products( $current_doc, $current_url, $breadcrumb ) as $product ) {
                if ( '' !== $product['source_url'] ) {
                    $by_url[ $product['source_url'] ] = $product;
                } elseif ( '' !== $product['title'] ) {
                    $no_url[ $product['title'] ] = $product;
                }
            }

            $before = count( $to_follow ) + count( $by_url );
            foreach ( $this->discover_product_links( $current_doc, $current_url ) as $link ) {
                $to_follow[ $link ] = true;
            }
            // A paginated page that added nothing new = we have walked off the
            // end (or the pager loops back to page 1). Stop.
            if ( $page > 0 && ( count( $to_follow ) + count( $by_url ) ) === $before ) {
                break;
            }

            $next = $this->find_next_page( $current_doc, $current_url );
            if ( ! $next || $next === $current_url ) {
                break;
            }

            if ( microtime( true ) >= $this->deadline ) {
                $truncated = true;
                break;
            }

            try {
                $current_html = $this->fetch( $next );
            } catch ( \RuntimeException $e ) {
                break;
            }

            $current_url = $next;
            $current_doc = $this->to_xpath( $current_html );
        }

        $li_products = 0;
        foreach ( $first_doc->query( '//li[contains(concat(" ", normalize-space(@class), " "), " product ")]' ) as $_ ) {
            $li_products++;
        }

        Logger::info(
            sprintf(
                __( 'واکشی داخلی: %1$d بایت، %2$d گره JSON-LD، %3$d کارت محصول، %4$d لینک محصول برای دنبال‌کردن.', 'shopharvester-pro' ),
                strlen( $first_html ),
                count( $this->json_ld( $first_doc ) ),
                $li_products,
                count( $to_follow )
            ),
            array( 'url' => $url, 'direct_products' => count( $by_url ) + count( $no_url ) ),
            array( 'channel' => 'import', 'source_url' => $url )
        );

        // Nothing found on the page itself: fall back to the XML sitemap so a
        // bare homepage still yields the whole catalogue on arbitrary platforms.
        if ( empty( $by_url ) && empty( $no_url ) && empty( $to_follow ) ) {
            $origin = ( wp_parse_url( $url, PHP_URL_SCHEME ) ?: 'https' ) . '://' . wp_parse_url( $url, PHP_URL_HOST );
            foreach ( $this->discover_via_sitemap( $origin ) as $link ) {
                $to_follow[ $link ] = true;
            }
            Logger::info(
                sprintf( __( 'واکشی داخلی: %d نشانی محصول از نقشه‌ی سایت پیدا شد.', 'shopharvester-pro' ), count( $to_follow ) ),
                array(),
                array( 'channel' => 'import', 'source_url' => $url )
            );
        }

        // Follow discovered product pages to obtain full data.
        if ( $follow ) {
            $targets = array_unique( array_merge( array_keys( $by_url ), array_keys( $to_follow ) ) );
            $done    = 0;

            foreach ( $targets as $product_url ) {
                if ( $done >= $max_products ) {
                    break;
                }
                if ( microtime( true ) >= $this->deadline ) {
                    $truncated = true;
                    break;
                }
                $done++;

                $existing = $by_url[ $product_url ] ?? array();

                // Skip the fetch when the listing card already gave us enough:
                // either a full record (price + description) or, for shops whose
                // product pages are JS-only, at least title + price + an image.
                if ( $existing && '' !== ( $existing['price'] ?? '' )
                    && ( '' !== ( $existing['description'] ?? '' )
                        || ( '' !== ( $existing['title'] ?? '' ) && ! empty( $existing['images'] ) ) ) ) {
                    continue;
                }

                try {
                    $doc = $this->to_xpath( $this->fetch( $product_url ) );
                } catch ( \RuntimeException $e ) {
                    continue;
                }

                // Ignore category / listing pages picked up by the sitemap.
                if ( ! $existing && $this->looks_like_listing( $doc ) && ! $this->looks_like_product( $doc ) ) {
                    continue;
                }

                $single = $this->extract_single_product( $doc, $product_url, $this->extract_breadcrumb( $doc ) );
                if ( ! $single || '' === $single['title'] ) {
                    continue;
                }

                foreach ( $single as $key => $value ) {
                    if ( '' !== $value && array() !== $value && null !== $value ) {
                        $existing[ $key ] = $value;
                    }
                }

                $by_url[ $product_url ] = $existing;
            }
        }

        if ( $truncated ) {
            Logger::warning(
                __( 'واکشی داخلی به دلیل محدودیت زمان اجرا، ناقص متوقف شد؛ نتایج جزئی ذخیره می‌شود. برای ادامه، دوباره همان آدرس را اجرا کنید.', 'shopharvester-pro' ),
                array( 'collected' => count( $by_url ) + count( $no_url ) ),
                array( 'channel' => 'import', 'source_url' => $url )
            );
        }

        return array(
            'store'    => $store,
            'products' => array_merge( array_values( $by_url ), array_values( $no_url ) ),
        );
    }

    /* --------------------------------------------------------------------- *
     *  HTTP + DOM plumbing.
     * --------------------------------------------------------------------- */

    /**
     * Fetch a URL and return its HTML body.
     *
     * @param string $url URL.
     * @return string
     * @throws \RuntimeException On transport error or non-2xx status.
     */
    private function fetch( $url ) {
        $attempts = 0;
        $last_err = '';

        do {
            $attempts++;

            if ( $this->use_render ) {
                $response = Http_Client::get_rendered( $url );
            } else {
                $response = Http_Client::get(
                    $url,
                    array(
                        'timeout' => (int) Settings::get( 'builtin_timeout', 25 ),
                        'headers' => array(
                            'Accept'          => 'text/html,application/xhtml+xml',
                            'Accept-Language' => 'fa,en;q=0.8',
                        ),
                    )
                );
            }

            if ( is_wp_error( $response ) ) {
                $last_err = 'fetch_error: ' . $response->get_error_message();
                $retry    = (bool) preg_match( '/(timed out|Connection (reset|closed|refused)|Empty reply|Recv failure|resolve)/i', $last_err );
            } else {
                $code = (int) wp_remote_retrieve_response_code( $response );
                if ( $code >= 200 && $code < 300 ) {
                    $body = wp_remote_retrieve_body( $response );
                    if ( '' === trim( (string) $body ) ) {
                        throw new \RuntimeException( 'fetch_empty_body' );
                    }
                    return $body;
                }
                $last_err = 'fetch_status_' . $code;
                // 429/5xx are transient; 403/401/451 are hard blocks.
                $retry = in_array( $code, array( 425, 429, 500, 502, 503, 504 ), true );
            }

            if ( $retry && $attempts < 3 && microtime( true ) < $this->deadline ) {
                sleep( $attempts * 2 );
                continue;
            }
            break;
        } while ( true );

        // A source-site status / transport failure is not a plugin fault — the
        // caller (Job_Queue) turns this into a WARNING, not an error trace.
        throw new \RuntimeException( $last_err );
    }

    /**
     * Build a DOMXPath from an HTML string (UTF-8 safe).
     *
     * @param string $html HTML.
     * @return \DOMXPath
     */
    private function to_xpath( $html ) {
        $dom = new \DOMDocument();

        $previous = libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        return new \DOMXPath( $dom );
    }

    /* --------------------------------------------------------------------- *
     *  JSON-LD.
     * --------------------------------------------------------------------- */

    /**
     * Collect every JSON-LD node on the page (flattening @graph).
     *
     * @param \DOMXPath $xpath XPath.
     * @return array<int, array>
     */
    private function json_ld( \DOMXPath $xpath ) {
        $nodes = array();

        foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) as $script ) {
            $decoded = json_decode( trim( $script->textContent ), true );
            if ( ! is_array( $decoded ) ) {
                continue;
            }

            $candidates = isset( $decoded[0] ) ? $decoded : array( $decoded );
            foreach ( $candidates as $candidate ) {
                if ( ! is_array( $candidate ) ) {
                    continue;
                }
                if ( isset( $candidate['@graph'] ) && is_array( $candidate['@graph'] ) ) {
                    foreach ( $candidate['@graph'] as $graph_node ) {
                        if ( is_array( $graph_node ) ) {
                            $nodes[] = $graph_node;
                        }
                    }
                } else {
                    $nodes[] = $candidate;
                }
            }
        }

        return $nodes;
    }

    /**
     * @param array  $node JSON-LD node.
     * @param string $type Type to test for.
     * @return bool
     */
    private function is_type( array $node, $type ) {
        $node_type = $node['@type'] ?? '';
        $node_type = is_array( $node_type ) ? $node_type : array( $node_type );

        return in_array( $type, $node_type, true );
    }

    /* --------------------------------------------------------------------- *
     *  Store extraction.
     * --------------------------------------------------------------------- */

    /**
     * @param \DOMXPath $xpath XPath.
     * @param string    $url   Source URL.
     * @return array
     */
    private function extract_store( \DOMXPath $xpath, $url ) {
        $host = wp_parse_url( $url, PHP_URL_HOST );

        $org = array();
        foreach ( $this->json_ld( $xpath ) as $node ) {
            if ( $this->is_type( $node, 'Organization' ) || $this->is_type( $node, 'Store' ) || $this->is_type( $node, 'OnlineStore' ) || $this->is_type( $node, 'WebSite' ) ) {
                $org = $node;
                break;
            }
        }

        $email = $this->first_attr( $xpath, '//a[starts-with(@href, "mailto:")]', 'href' );
        $email = $email ? str_replace( 'mailto:', '', explode( '?', $email )[0] ) : '';

        $phone = $this->first_attr( $xpath, '//a[starts-with(@href, "tel:")]', 'href' );
        $phone = $phone ? str_replace( 'tel:', '', $phone ) : '';

        $socials = array();
        foreach ( $xpath->query( '//a[@href]' ) as $a ) {
            $href = $a->getAttribute( 'href' );
            foreach ( array( 'instagram', 'telegram', 't.me', 'twitter', 'facebook', 'linkedin', 'aparat', 'youtube' ) as $net ) {
                if ( false !== strpos( $href, $net ) && empty( $socials[ $net ] ) ) {
                    $socials[ $net ] = $href;
                }
            }
        }

        $name = $this->clean( $org['name'] ?? '' );
        if ( '' === $name ) {
            $name = $this->clean( $this->meta( $xpath, 'og:site_name' ) ) ?: $this->clean( $this->first_text( $xpath, '//title' ) ) ?: $host;
        }

        $logo = '';
        if ( ! empty( $org['logo'] ) ) {
            $logo = is_array( $org['logo'] ) ? ( $org['logo']['url'] ?? '' ) : $org['logo'];
        }
        if ( ! $logo ) {
            $logo = $this->first_attr( $xpath, '//img[contains(@class,"logo")] | //*[contains(@class,"logo")]//img | //header//img', 'src' );
        }

        return array(
            'name'        => $name,
            'brand'       => $this->clean( is_array( $org['brand'] ?? null ) ? ( $org['brand']['name'] ?? '' ) : ( $org['brand'] ?? '' ) ),
            'domain'      => $host,
            'email'       => $email ?: $this->clean( $org['email'] ?? '' ),
            'phone'       => $phone ?: $this->clean( $org['telephone'] ?? '' ),
            'address'     => $this->clean( $this->stringify_address( $org['address'] ?? '' ) ),
            'socials'     => $socials,
            'logo'        => $this->absolute( $logo, $url ),
            'description' => $this->clean( $this->meta( $xpath, 'description', 'name' ) ?: $this->meta( $xpath, 'og:description' ) ),
            'source_url'  => $url,
        );
    }

    /* --------------------------------------------------------------------- *
     *  Product extraction.
     * --------------------------------------------------------------------- */

    /**
     * @param \DOMXPath $xpath      XPath.
     * @param string    $url        Current page URL.
     * @param string[]  $breadcrumb Ordered category trail.
     * @return array<int, array>
     */
    private function extract_products( \DOMXPath $xpath, $url, array $breadcrumb ) {
        $products = array();

        foreach ( $this->json_ld( $xpath ) as $node ) {
            if ( ! $this->is_type( $node, 'Product' ) ) {
                continue;
            }

            $offers = $node['offers'] ?? array();
            if ( isset( $offers[0] ) ) {
                $offers = $offers[0];
            }

            $images = $node['image'] ?? array();
            $images = ( is_array( $images ) && ( isset( $images[0] ) || empty( $images ) ) ) ? $images : array( $images );
            $images = array_map(
                function ( $img ) use ( $url ) {
                    if ( is_array( $img ) ) {
                        $img = $img['url'] ?? ( $img['contentUrl'] ?? ( $img['@id'] ?? '' ) );
                    }
                    $abs = $this->absolute( (string) $img, $url );
                    return $this->looks_like_image_url( $abs ) ? $abs : '';
                },
                $images
            );

            $availability = (string) ( $offers['availability'] ?? '' );

            $products[] = array(
                'title'           => $this->clean( $node['name'] ?? '' ),
                'source_url'      => $this->absolute( $node['url'] ?? $url, $url ),
                'description'     => $this->clean( $node['description'] ?? '' ),
                'sku'            => $this->clean( $node['sku'] ?? ( $node['mpn'] ?? '' ) ),
                'brand'          => $this->clean( is_array( $node['brand'] ?? null ) ? ( $node['brand']['name'] ?? '' ) : ( $node['brand'] ?? '' ) ),
                'price'          => ( '' !== (string) ( $offers['price'] ?? ( $offers['lowPrice'] ?? '' ) ) )
                    ? (string) $this->reconcile_price( (string) ( $offers['price'] ?? $offers['lowPrice'] ), (string) ( $offers['priceCurrency'] ?? '' ), 0 )
                    : '',
                'stock'          => ( false !== stripos( $availability, 'InStock' ) ) ? 'instock' : ( $availability ? 'outofstock' : '' ),
                'categories'     => array_filter( array( $this->clean( $node['category'] ?? '' ) ) ),
                'categories_path' => $breadcrumb ? array( $breadcrumb ) : array(),
                'collections'    => array(),
                'attributes'     => $this->jsonld_attributes( $node ),
                'images'         => array_values( array_filter( array_unique( $images ) ) ),
            );
        }

        if ( ! empty( $products ) ) {
            return $products;
        }

        // Fallback: scan generic product cards.
        $card_query = '//li[contains(concat(" ", normalize-space(@class), " "), " product ")]'
            . ' | //*[contains(@class,"product-item")] | //*[contains(@class,"product-card")]'
            . ' | //*[contains(@class,"product_item")] | //*[contains(@class,"product-box")]'
            . ' | //*[contains(@class,"product-wrapper")] | //*[contains(@class,"productsItem")]'
            . ' | //*[contains(@class,"productItem")] | //a[contains(@class,"modern-product-card")]'
            . ' | //a[contains(@class,"product-link") and .//img]';

        foreach ( $xpath->query( $card_query ) as $card ) {
            // The card itself may be the <a>.
            $link = ( 'a' === strtolower( $card->nodeName ) && $card instanceof \DOMElement ) ? $card->getAttribute( 'href' ) : '';
            if ( '' === $link ) {
                $link = $this->node_attr( $xpath, './/a[contains(@class,"woocommerce-LoopProduct-link") or contains(@class,"woocommerce-loop-product__link")]', $card, 'href' );
            }
            if ( '' === $link ) {
                $link = $this->node_attr( $xpath, './/a[@href and not(contains(@href,"add-to-cart")) and not(contains(@class,"add_to_cart"))]', $card, 'href' );
            }
            $link_abs = $this->absolute( $link, $url );
            if ( '' === $link_abs
                || $link_abs === $url
                || $link_abs === rtrim( $url, '/' )
                || preg_match( '#(add-to-cart=|/cart/|/checkout/|/page/\d+|\?orderby=|/shop/?($|\?)|/product-category/|/product-tag/)#i', $link_abs ) ) {
                continue;
            }
            $link = $link_abs;
            $title = $this->node_text( $xpath, './/*[contains(@class,"woocommerce-loop-product__title") or contains(@class,"product-title") or contains(@class,"product_title")][1]', $card );
            if ( '' === $title ) {
                $title = $this->node_text( $xpath, './/*[contains(@class,"title") or self::h2 or self::h3 or self::h4][1]', $card );
            }
            $price = $this->node_text( $xpath, './/*[contains(@class,"price")][1]//bdi | .//*[contains(@class,"price")][1]//ins | .//*[contains(@class,"price")][1]', $card );
            $stock = '';
            // If the price slot has no digits (e.g. "ناموجود"), it is not a
            // price. Try to pull a real "<number> تومان" from the card text,
            // and flag the item out of stock when it says so.
            if ( ! preg_match( '/[۰-۹0-9]/u', (string) $price ) ) {
                if ( preg_match( '/(ناموجود|تمام\s*شد|اتمام\s*موجودی|out\s*of\s*stock)/iu', $this->clean( (string) $price ) . ' ' . $this->clean( $card->textContent ) ) ) {
                    $stock = 'outofstock';
                }
                $price = '';
            }
            if ( '' === $this->clean( $price ) ) {
                $card_text = $this->clean( $card->textContent );
                if ( preg_match( '/([۰-۹0-9][۰-۹0-9,٬\.]{2,})\s*(تومان|ریال|تومن)/u', $card_text, $m ) ) {
                    $price = $m[1] . ' ' . $m[2];
                    $stock = '';
                }
            }
            $img   = $this->node_attr( $xpath, './/img', $card, 'src' );
            if ( ! $img || false !== strpos( $img, 'data:image' ) ) {
                $img = $this->node_attr( $xpath, './/img', $card, 'data-src' )
                    ?: $this->node_attr( $xpath, './/img', $card, 'data-lazy-src' )
                    ?: $this->node_attr( $xpath, './/img', $card, 'srcset' );
                $img = $img ? trim( explode( ' ', trim( $img ) )[0] ) : '';
            }

            $title = $this->clean_product_title( $title );
            if ( '' === $title || '' === $link ) {
                continue;
            }
            // Archive / pagination page titles, not products.
            if ( preg_match( '#(آرشیو|صفحه\s*\d+\s*از\s*\d+|^page\s*\d+|بایگانی)#u', $title ) ) {
                continue;
            }

            $pair       = ( '' !== $this->clean( $price ) ) ? $this->split_prices( $price ) : array( 'price' => 0, 'sale' => 0 );
            $price_out  = $pair['price'] > 0 ? (string) $pair['price'] : '';
            $sale_out   = $pair['sale'] > 0 ? (string) $pair['sale'] : '';

            $products[] = array(
                'title'           => $title,
                'source_url'      => $this->absolute( $link, $url ),
                'description'     => '',
                'sku'            => '',
                'brand'          => '',
                'price'          => $price_out,
                'sale_price'     => $sale_out,
                'stock'          => $stock,
                'categories'     => array(),
                'categories_path' => $breadcrumb ? array( $breadcrumb ) : array(),
                'collections'    => array(),
                'attributes'     => array(),
                'images'         => $img ? array( $this->absolute( $img, $url ) ) : array(),
            );
        }

        // Nested selectors can match the same product twice (image anchor +
        // title anchor). Keep the richest row per source URL.
        $by_url  = array();
        $filled  = static function ( array $p ) {
            $n = 0;
            foreach ( array( 'title', 'price', 'sku', 'description' ) as $k ) {
                $n += ( '' !== (string) ( $p[ $k ] ?? '' ) ) ? 1 : 0;
            }
            $n += ! empty( $p['images'] ) ? 1 : 0;
            return $n;
        };
        foreach ( $products as $p ) {
            $key = $p['source_url'] ?: $p['title'];
            if ( ! isset( $by_url[ $key ] ) || $filled( $p ) > $filled( $by_url[ $key ] ) ) {
                $by_url[ $key ] = $p;
            }
        }
        $products = array_values( $by_url );

        // Strip a shop-name suffix shared by most card titles
        // ("… | هیبا", "… - فروشگاه X").
        $products = $this->strip_common_title_suffix( $products );

        return $products;
    }

    /**
     * Remove a " | X" / " - X" tail shared by the majority of a card batch's
     * titles (typically the shop name).
     *
     * @param array $products Extracted product rows.
     * @return array
     */
    private function strip_common_title_suffix( array $products ) {
        if ( count( $products ) < 4 ) {
            return $products;
        }

        $suffixes = array();
        foreach ( $products as $p ) {
            if ( preg_match( '/\s*[|\x{2013}]\s*(.{2,40})$|\s+-\s+(.{2,40})$/u', (string) $p['title'], $m ) ) {
                $tail = trim( $m[1] !== '' ? $m[1] : ( $m[2] ?? '' ) );
                if ( '' !== $tail ) {
                    $suffixes[ $tail ] = ( $suffixes[ $tail ] ?? 0 ) + 1;
                }
            }
        }
        if ( empty( $suffixes ) ) {
            return $products;
        }

        arsort( $suffixes );
        $top   = key( $suffixes );
        $count = reset( $suffixes );
        if ( $count < count( $products ) * 0.5 ) {
            return $products;
        }

        $quoted = preg_quote( $top, '/' );
        foreach ( $products as &$p ) {
            $p['title'] = trim(
                preg_replace( '/\s*[|\x{2013}]\s*' . $quoted . '$|\s+-\s+' . $quoted . '$/u', '', (string) $p['title'] ),
                " \t|-\x{2013}"
            );
        }
        unset( $p );

        return $products;
    }

    /**
     * Pull additionalProperty / PropertyValue pairs from a Product node.
     *
     * @param array $node Product node.
     * @return array
     */
    private function jsonld_attributes( array $node ) {
        $out = array();

        foreach ( (array) ( $node['additionalProperty'] ?? array() ) as $prop ) {
            if ( is_array( $prop ) && isset( $prop['name'] ) ) {
                $out[ $this->clean( $prop['name'] ) ] = $this->clean( (string) ( $prop['value'] ?? '' ) );
            }
        }

        if ( ! empty( $node['color'] ) ) {
            $out['رنگ'] = $this->clean( $node['color'] );
        }
        if ( ! empty( $node['material'] ) ) {
            $out['جنس'] = $this->clean( $node['material'] );
        }
        if ( ! empty( $node['size'] ) ) {
            $out['سایز'] = $this->clean( is_array( $node['size'] ) ? implode( ',', $node['size'] ) : $node['size'] );
        }

        return $out;
    }

    /**
     * Extract a single product from a product-detail page (JSON-LD first,
     * then OpenGraph / WooCommerce markup).
     *
     * @param \DOMXPath $xpath      XPath.
     * @param string    $url        Product URL.
     * @param string[]  $breadcrumb Category trail.
     * @return array|null
     */
    private function extract_single_product( \DOMXPath $xpath, $url, array $breadcrumb ) {
        // Only trust a structured Product node here — the card-scan fallback
        // inside extract_products() would match related-product widgets.
        $base = null;
        foreach ( $this->json_ld( $xpath ) as $ld ) {
            if ( $this->is_type( $ld, 'Product' ) ) {
                foreach ( $this->extract_products( $xpath, $url, $breadcrumb ) as $product ) {
                    if ( '' !== $product['title'] ) {
                        $base = $product;
                        break 2;
                    }
                }
                break;
            }
        }

        // Require a positive "this is a product page" signal so that shop,
        // category, pagination, cart and add-to-cart URLs are rejected.
        $is_product = null !== $base
            || 'product' === strtolower( (string) $this->meta( $xpath, 'og:type' ) )
            || '' !== $this->meta( $xpath, 'product:price:amount' )
            || $this->looks_like_product( $xpath );

        if ( ! $is_product ) {
            return null;
        }

        $title = $base['title'] ?? ( $this->meta( $xpath, 'og:title' ) ?: $this->first_text( $xpath, '//h1[1]' ) );
        $title = $this->clean_product_title( $title );
        if ( '' === $title ) {
            return null;
        }

        // Merge structured data with generous DOM fallbacks so custom (non
        // WooCommerce) shops still yield price / images / attributes.
        $price = $base['price'] ?? '';
        if ( '' === $price ) {
            $price = $this->find_price( $xpath );
        }

        $images = ! empty( $base['images'] ) ? $base['images'] : array();
        $images = array_merge( $images, $this->collect_images( $xpath, $url ) );
        $images = array_values( array_unique( array_filter( $images ) ) );

        $sku = $base['sku'] ?? '';
        if ( '' === $sku ) {
            $sku = $this->clean( $this->first_text( $xpath, '//*[contains(@class,"sku") or @itemprop="sku"][1]' ) );
        }

        $description = $base['description'] ?? '';
        if ( '' === $description ) {
            $description = $this->clean(
                $this->first_text( $xpath, '//*[contains(@class,"woocommerce-product-details__short-description")][1]' )
                ?: $this->first_text( $xpath, '//*[@id="tab-description" or contains(@class,"product-description") or contains(@class,"description")][1]' )
                ?: $this->meta( $xpath, 'og:description' )
            );
        }

        $attributes = $base['attributes'] ?? array();
        $attributes = array_merge( $this->html_attributes( $xpath ), is_array( $attributes ) ? $attributes : array() );

        $categories = $base['categories'] ?? array();

        return array(
            'title'           => $title,
            'source_url'      => $url,
            'description'     => $description,
            'sku'            => $sku,
            'brand'          => $base['brand'] ?? $this->clean( $this->first_text( $xpath, '//*[contains(@class,"brand") or @itemprop="brand"][1]' ) ),
            'price'          => $this->clean( $price ),
            'stock'          => $base['stock'] ?? '',
            'categories'     => array_values( array_filter( (array) $categories ) ),
            'categories_path' => $breadcrumb ? array( $breadcrumb ) : array(),
            'collections'    => array(),
            'attributes'     => $attributes,
            'images'         => $images,
        );
    }

    /**
     * Best-effort price detection across structured data, microdata, meta tags
     * and common CSS hooks (including Persian currency wording).
     *
     * @param \DOMXPath $xpath XPath.
     * @return string
     */
    private function find_price( \DOMXPath $xpath ) {
        // A visible "<number> تومان/ریال" string is the shopper-facing truth and
        // carries its own unit — collect it first so machine values below can be
        // reconciled against it.
        $display = '';
        foreach ( $xpath->query( '//*[contains(text(),"تومان") or contains(text(),"ریال") or contains(text(),"تومن") or contains(text(),"Toman")]' ) as $node ) {
            $text = $this->clean( $node->textContent );
            if ( mb_strlen( $text ) < 40 && preg_match( '/[\d۰-۹]/u', $text ) ) {
                $display = $text;
                break;
            }
        }
        $display_toman = '' !== $display ? $this->price_to_toman( $display ) : 0;

        // JSON-LD Offer nodes anywhere on the page (machine value + currency).
        foreach ( $this->json_ld( $xpath ) as $node ) {
            $offers = $node['offers'] ?? ( $this->is_type( $node, 'Offer' ) ? $node : null );
            if ( is_array( $offers ) && isset( $offers[0] ) ) {
                $offers = $offers[0];
            }
            $raw = is_array( $offers ) ? (string) ( $offers['price'] ?? ( $offers['lowPrice'] ?? '' ) ) : '';
            if ( '' !== $raw ) {
                $cur = is_array( $offers ) ? (string) ( $offers['priceCurrency'] ?? '' ) : '';
                return (string) $this->reconcile_price( $raw, $cur, $display_toman );
            }
        }

        $meta_queries = array(
            '//meta[@property="product:price:amount"]/@content',
            '//meta[@itemprop="price"]/@content',
            '//*[@itemprop="price"]/@content',
            '//*[@itemprop="price"]',
            '//*[@data-price]/@data-price',
        );
        foreach ( $meta_queries as $query ) {
            $node = $xpath->query( $query )->item( 0 );
            if ( $node && '' !== trim( $node->nodeValue ) ) {
                $cur = $xpath->query( '//meta[@property="product:price:currency"]/@content' )->item( 0 );
                return (string) $this->reconcile_price( $node->nodeValue, $cur ? $cur->nodeValue : '', $display_toman );
            }
        }

        $css_queries = array(
            // The actual selling price, when the markup distinguishes it from
            // a crossed-out original ("newPrice", "final-price", "sale-price"
            // vs. "oldPrice") — checked before the generic query below, which
            // would otherwise happily return the undiscounted price instead.
            '//*[contains(@class,"newPrice") or contains(@class,"new-price") or contains(@class,"final-price") or contains(@class,"sale-price") or contains(@class,"current-price")][not(.//*[contains(@class,"price")])][1]',
            '//*[contains(@class,"price")][not(contains(@class,"old")) and not(.//*[contains(@class,"price")])][1]',
            '//*[contains(@class,"price")][not(.//*[contains(@class,"price")])][1]',
            '//*[contains(@class,"amount")][1]',
            '//*[contains(@class,"product-price")][1]',
        );
        foreach ( $css_queries as $query ) {
            $text = $this->clean( $this->first_text( $xpath, $query ) );
            if ( '' !== $text && preg_match( '/\d/u', $text ) ) {
                return (string) $this->price_to_toman( $text );
            }
        }

        return '' !== $display ? (string) $display_toman : '';
    }

    /**
     * Turn a machine price value + optional currency into a Toman amount,
     * cross-checking against the page's visible Toman figure: when the machine
     * value is ~10x the displayed one (and no explicit currency says otherwise)
     * it is Rial and gets divided by 10.
     *
     * @param string $raw           Machine price (digits, maybe separators).
     * @param string $currency      ISO / hint from structured data.
     * @param int    $display_toman Visible price already normalised to Toman.
     * @return int
     */
    private function reconcile_price( $raw, $currency, $display_toman ) {
        $n = $this->digits_only( $raw );
        if ( $n <= 0 ) {
            return $display_toman > 0 ? $display_toman : 0;
        }

        $cur = mb_strtolower( trim( (string) $currency ) );
        if ( '' !== $cur ) {
            if ( preg_match( '/(irr|rial|ریال)/u', $cur ) ) {
                return (int) round( $n / 10 );
            }
            if ( preg_match( '/(irt|tmn|toman|تومان)/u', $cur ) ) {
                return $n;
            }
        }

        if ( $display_toman > 0 ) {
            if ( abs( $n - $display_toman * 10 ) <= $display_toman ) {
                return (int) round( $n / 10 );
            }
            if ( abs( $n - $display_toman ) <= max( 1, (int) ( $display_toman * 0.05 ) ) ) {
                return $n;
            }
        }

        return $n;
    }

    /**
     * Collect product image URLs from galleries, sliders, microdata and
     * lazy-load attributes.
     *
     * @param \DOMXPath $xpath XPath.
     * @param string    $url   Page URL.
     * @return string[]
     */
    private function collect_images( \DOMXPath $xpath, $url ) {
        $out = array();

        // og:image (may repeat), image_src link.
        foreach ( $xpath->query( '//meta[@property="og:image" or @property="og:image:url"]/@content | //link[@rel="image_src"]/@href' ) as $node ) {
            $out[] = $node->nodeValue;
        }

        $containers = '//*[contains(@class,"gallery") or contains(@class,"slider") or contains(@class,"swiper")'
            . ' or contains(@class,"thumbnails") or contains(@class,"product-image") or contains(@class,"product-images")'
            . ' or contains(@class,"product-gallery") or contains(@class,"woocommerce-product-gallery")]//img';

        foreach ( $xpath->query( $containers . ' | //img[@itemprop="image"]' ) as $img ) {
            foreach ( array( 'data-large_image', 'data-zoom-image', 'data-src', 'data-lazy-src', 'data-original', 'src' ) as $attr ) {
                $value = $img->getAttribute( $attr );
                if ( $value && false === strpos( $value, 'data:image' ) ) {
                    $out[] = $value;
                    break;
                }
            }
            $srcset = $img->getAttribute( 'srcset' );
            if ( $srcset ) {
                $first = trim( explode( ' ', trim( explode( ',', $srcset )[0] ) )[0] );
                if ( $first ) {
                    $out[] = $first;
                }
            }
        }

        $absolute = array();
        foreach ( $out as $candidate ) {
            $abs = $this->absolute( $candidate, $url );
            if ( '' !== $abs && $this->looks_like_image_url( $abs ) && ! preg_match( '#(sprite|placeholder|logo|icon|blank|spacer|loading)#i', $abs ) ) {
                $absolute[ $abs ] = true;
            }
        }

        return array_keys( $absolute );
    }

    /**
     * Pull attribute rows from a WooCommerce "additional information" table.
     *
     * @param \DOMXPath $xpath XPath.
     * @return array
     */
    private function html_attributes( \DOMXPath $xpath ) {
        $out = array();

        foreach ( $xpath->query( '//table[contains(@class,"woocommerce-product-attributes") or contains(@class,"shop_attributes")]//tr' ) as $row ) {
            $label = $this->clean( $this->node_text( $xpath, './/th', $row ) );
            $value = $this->clean( $this->node_text( $xpath, './/td', $row ) );
            if ( '' !== $label && '' !== $value ) {
                $out[ $label ] = $value;
            }
        }

        return $out;
    }

    /**
     * Find candidate product-detail links on a listing / home page.
     *
     * @param \DOMXPath $xpath XPath.
     * @param string    $base  Page URL.
     * @return string[] Absolute, same-host product URLs.
     */
    private function discover_product_links( \DOMXPath $xpath, $base ) {
        $host = wp_parse_url( $base, PHP_URL_HOST );

        $queries = array(
            '//li[contains(concat(" ", normalize-space(@class), " "), " product ")]//a[contains(@class,"woocommerce-LoopProduct-link") or contains(@class,"woocommerce-loop-product__link") or contains(@class,"product-title") or contains(@class,"product_title")]/@href',
            '//li[contains(concat(" ", normalize-space(@class), " "), " product ")]//a[not(contains(@class,"add_to_cart")) and not(contains(@href,"add-to-cart"))]/@href',
            '//*[contains(@class,"wc-block-grid__product")]//a[contains(@class,"wc-block-grid__product-link")]/@href',
            '//*[contains(@class,"product-item") or contains(@class,"product-card") or contains(@class,"product_item") or contains(@class,"productsItem") or contains(@class,"product-box") or contains(@class,"productItem") or contains(@class,"product-wrapper")]//a/@href',
            '//a[contains(@href,"/product/") or contains(@href,"/products/") or contains(@href,"/dp/") or contains(@href,"post_type=product")]/@href',
            // ID-style product bases used by many custom Iranian shops:
            //   /product-1246 , /q/<slug> , /p/<slug> , /pro/<slug> , /show/<id>
            '//a[contains(@href,"/product-") or contains(@href,"/q/") or contains(@href,"/show/") or contains(@href,"/kala/") or contains(@href,"/goods/") or contains(@href,"/item/")]/@href',
            // Persian WooCommerce product bases (raw and percent-encoded).
            '//a[contains(@href,"محصول") or contains(@href,"کالا") or contains(@href,"%D9%85%D8%AD%D8%B5%D9%88%D9%84") or contains(@href,"%DA%A9%D8%A7%D9%84%D8%A7")]/@href',
            '//a[contains(@class,"product-title") or contains(@class,"product_title")]/@href',
        );

        // URLs that are never a product-detail page.
        $exclude = '#(add-to-cart=|/cart/|/checkout/|/my-account|/wishlist|/compare|'
            . '/shop/?($|\?)|/shop/page/|/category/|/product-category/|/product-tag/|/tag/|/brand/|'
            . '\?(orderby|filter|min_price|max_price|paged|product_count|s)=|/page/\d+|/feed/?$|\.(xml|json|rss)$|'
            . '/(cart|checkout|login|register|contact|about|blog|faq)/?$)#i';

        $links = array();

        foreach ( $queries as $query ) {
            foreach ( $xpath->query( $query ) as $node ) {
                $href = ( $node instanceof \DOMAttr ) ? $node->nodeValue : $node->getAttribute( 'href' );
                $abs  = $this->absolute( $href, $base );
                if ( '' === $abs || wp_parse_url( $abs, PHP_URL_HOST ) !== $host ) {
                    continue;
                }

                $abs = strtok( $abs, '#' );
                if ( preg_match( $exclude, $abs ) ) {
                    continue;
                }

                $links[ $abs ] = true;
            }
        }

        return array_keys( $links );
    }

    /**
     * Does the URL path itself name a category / collection / shop listing?
     * (Independent of page content — safe to trust for JS-rendered sites.)
     *
     * @param string $url Target URL.
     * @return bool
     */
    private function url_is_listing_path( $url ) {
        $path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
        if ( '' === $path || '/' === $path ) {
            return true; // Home page — a listing, never one product.
        }

        $path = rtrim( $path, '/' );

        // A bare index segment ("/product", "/products", "/shop", "/q") with
        // nothing after it is a listing, not one product.
        if ( preg_match( '#/(product|products|q|p|kala|goods|item|items|show)$#i', $path ) ) {
            return true;
        }

        return (bool) preg_match(
            '#/(category|categories|cat|c|collection|collections|product-category|product-cat|'
            . 'shop|store|dokan|vendor|brand|brands|tag|tags|group|groups|list|lists|archive)(/|-|$)#i',
            $path . '/'
        );
    }

    /**
     * Heuristic: does this page represent a single product?
     *
     * @param \DOMXPath $xpath XPath.
     * @return bool
     */
    private function looks_like_product( \DOMXPath $xpath ) {
        foreach ( $this->json_ld( $xpath ) as $node ) {
            if ( $this->is_type( $node, 'Product' ) ) {
                return true;
            }
        }

        if ( 'product' === strtolower( (string) $this->meta( $xpath, 'og:type' ) ) ) {
            return true;
        }
        if ( '' !== $this->meta( $xpath, 'product:price:amount' ) ) {
            return true;
        }
        if ( $xpath->query( '//*[contains(@itemtype,"schema.org/Product")]' )->length ) {
            return true;
        }

        // A single add-to-cart control (but not a whole grid of them) plus a
        // product title container.
        $add_to_cart = $xpath->query( '//form[contains(@action,"add-to-cart")] | //button[contains(@name,"add-to-cart")] | //*[@data-product_id and not(ancestor::*[contains(@class,"products")])]' );
        if ( $add_to_cart->length === 1 && $xpath->query( '//*[contains(@class,"product_title") or contains(@class,"product-title") or contains(@class,"single-product")]' )->length ) {
            return true;
        }

        return false;
    }

    /**
     * Heuristic: does this page list many products (a shop / category page)?
     *
     * @param \DOMXPath $xpath XPath.
     * @return bool
     */
    private function looks_like_listing( \DOMXPath $xpath ) {
        $cards = $xpath->query(
            '//li[contains(concat(" ", normalize-space(@class), " "), " product ")]'
            . ' | //*[contains(@class,"product-item") or contains(@class,"product-card") or contains(@class,"wc-block-grid__product")]'
        );

        return $cards->length >= 4;
    }

    /**
     * Pull product URLs from the site's XML sitemap(s).
     *
     * @param string $origin Site origin (scheme://host).
     * @return string[]
     */
    private function discover_via_sitemap( $origin ) {
        $origin     = untrailingslashit( $origin );
        $candidates = array(
            $origin . '/sitemap_index.xml',
            $origin . '/sitemap.xml',
            $origin . '/wp-sitemap.xml',
            $origin . '/product-sitemap.xml',
            $origin . '/sitemap_products_1.xml',
        );

        $max      = max( 1, (int) Settings::get( 'builtin_max_products', 200 ) );
        $pattern  = '#/(product|products|p|item|dp|goods|kala|mahsul|%D9%85%D8%AD%D8%B5%D9%88%D9%84|%DA%A9%D8%A7%D9%84%D8%A7)/[^/]#i';
        $reject   = '#/(product-category|product-tag|category|tag|shop|cart|checkout|page)/|/page/\d+#i';
        $found    = array();
        $sitemaps = array();
        $seen_sm  = array();

        foreach ( $candidates as $sitemap_url ) {
            if ( isset( $seen_sm[ $sitemap_url ] ) ) {
                continue;
            }
            $seen_sm[ $sitemap_url ] = true;

            try {
                $xml = $this->fetch( $sitemap_url );
            } catch ( \RuntimeException $e ) {
                continue;
            }

            // A sitemap index: queue child sitemaps (product ones first).
            if ( false !== stripos( $xml, '<sitemapindex' ) ) {
                if ( preg_match_all( '#<loc>\s*([^<\s]+)\s*</loc>#i', $xml, $m ) ) {
                    foreach ( $m[1] as $child ) {
                        if ( preg_match( '#product#i', $child ) ) {
                            array_unshift( $sitemaps, $child );
                        } else {
                            $sitemaps[] = $child;
                        }
                    }
                }
                continue;
            }

            if ( preg_match_all( '#<loc>\s*([^<\s]+)\s*</loc>#i', $xml, $m ) ) {
                $is_product_sitemap = (bool) preg_match( '#product#i', $sitemap_url );
                foreach ( $m[1] as $loc ) {
                    $loc = html_entity_decode( trim( $loc ) );
                    if ( preg_match( $reject, $loc ) ) {
                        continue;
                    }
                    if ( $is_product_sitemap || preg_match( $pattern, $loc ) ) {
                        $found[ $loc ] = true;
                    }
                    if ( count( $found ) >= $max ) {
                        break 2;
                    }
                }
            }
        }

        // Process queued child sitemaps (bounded).
        $processed = 0;
        while ( $sitemaps && count( $found ) < $max && $processed < 15 && microtime( true ) < $this->deadline ) {
            $child = array_shift( $sitemaps );
            $processed++;

            if ( isset( $seen_sm[ $child ] ) ) {
                continue;
            }
            $seen_sm[ $child ] = true;

            try {
                $xml = $this->fetch( $child );
            } catch ( \RuntimeException $e ) {
                continue;
            }

            $is_product_sitemap = (bool) preg_match( '#product#i', $child );
            if ( preg_match_all( '#<loc>\s*([^<\s]+)\s*</loc>#i', $xml, $m ) ) {
                foreach ( $m[1] as $loc ) {
                    $loc = html_entity_decode( trim( $loc ) );
                    if ( preg_match( $reject, $loc ) ) {
                        continue;
                    }
                    if ( $is_product_sitemap || preg_match( $pattern, $loc ) ) {
                        $found[ $loc ] = true;
                    }
                    if ( count( $found ) >= $max ) {
                        break;
                    }
                }
            }
        }

        return array_slice( array_keys( $found ), 0, $max );
    }

    /* --------------------------------------------------------------------- *
     *  Breadcrumb + pagination.
     * --------------------------------------------------------------------- */

    /**
     * @param \DOMXPath $xpath XPath.
     * @return string[]
     */
    private function extract_breadcrumb( \DOMXPath $xpath ) {
        foreach ( $this->json_ld( $xpath ) as $node ) {
            if ( ! $this->is_type( $node, 'BreadcrumbList' ) ) {
                continue;
            }

            $items = $node['itemListElement'] ?? array();
            usort(
                $items,
                static function ( $a, $b ) {
                    return ( (int) ( $a['position'] ?? 0 ) ) <=> ( (int) ( $b['position'] ?? 0 ) );
                }
            );

            $names = array();
            foreach ( $items as $item ) {
                $label = $item['name'] ?? ( is_array( $item['item'] ?? null ) ? ( $item['item']['name'] ?? '' ) : '' );
                $label = $this->clean( $label );
                if ( '' !== $label ) {
                    $names[] = $label;
                }
            }

            if ( count( $names ) > 2 ) {
                return array_slice( $names, 1, -1 );
            }
            return array_slice( $names, 1 );
        }

        // Microdata BreadcrumbList.
        $names = array();
        foreach ( $xpath->query( '//*[contains(@itemtype,"BreadcrumbList")]//*[@itemprop="name"]' ) as $node ) {
            $label = $this->clean( $node->textContent ?: $node->getAttribute( 'content' ) );
            if ( '' !== $label ) {
                $names[] = $label;
            }
        }
        if ( $names ) {
            return count( $names ) > 2 ? array_slice( $names, 1, -1 ) : array_slice( $names, 1 );
        }

        // Common breadcrumb containers.
        $containers = '//*[contains(@class,"breadcrumb") or contains(@class,"bread-crumb")'
            . ' or contains(@class,"breadcrumbs") or contains(@class,"crumb") or contains(@class,"b-crumb")]'
            . '//a | //nav[contains(@aria-label,"read") or contains(@aria-label,"مسیر")]//a';

        foreach ( $xpath->query( $containers ) as $a ) {
            $label = $this->clean( $a->textContent );
            if ( '' !== $label && ! preg_match( '#^(خانه|صفحه اصلی|home)$#iu', $label ) ) {
                $names[] = $label;
            }
        }

        // Drop a trailing entry that merely repeats the product title.
        $h1 = $this->clean( $this->first_text( $xpath, '//h1[1]' ) );
        if ( $names && $h1 && $this->clean( end( $names ) ) === $h1 ) {
            array_pop( $names );
        }

        return $names;
    }

    /**
     * @param \DOMXPath $xpath XPath.
     * @param string    $url   Current URL.
     * @return string|null
     */
    private function find_next_page( \DOMXPath $xpath, $url ) {
        $candidates = array(
            '//link[@rel="next"]/@href',
            '//a[@rel="next"]/@href',
            '//a[contains(@class,"next")]/@href',
            '//*[contains(@class,"pagination")]//a[contains(@class,"next")]/@href',
        );

        foreach ( $candidates as $query ) {
            $node = $xpath->query( $query )->item( 0 );
            if ( ! $node ) {
                continue;
            }
            $href = trim( $node->nodeValue );
            // Ignore JS / anchor placeholders — a "next" control can carry
            // class="next" while its href is javascript:void(0) or "#".
            if ( '' === $href || 0 === stripos( $href, 'javascript:' ) || '#' === $href || 0 === strpos( $href, '#' ) ) {
                continue;
            }
            $abs = $this->absolute( $href, $url );
            if ( '' !== $abs && $abs !== $url ) {
                return $abs;
            }
        }

        // Fallback for shops whose pagination is JavaScript-driven (no <a href>):
        // if the page shows a pager / "next" control, walk ?page=N ourselves.
        $has_pager = $xpath->query(
            '//*[contains(@class,"pagination") or contains(@class,"paginate") or contains(@class,"pager")'
            . ' or contains(@class,"page-numbers") or @data-value or contains(@class,"btn-next")]'
        )->length;

        if ( $has_pager ) {
            $parts = wp_parse_url( $url );
            $query_args = array();
            if ( ! empty( $parts['query'] ) ) {
                parse_str( $parts['query'], $query_args );
            }
            $current = isset( $query_args['page'] ) ? max( 1, (int) $query_args['page'] ) : 1;
            if ( $current < 30 ) {
                return add_query_arg( 'page', $current + 1, $url );
            }
        }

        return null;
    }

    /* --------------------------------------------------------------------- *
     *  Small helpers.
     * --------------------------------------------------------------------- */

    /**
     * @param string $value Raw text.
     * @return string
     */
    private function clean( $value ) {
        $value = is_array( $value ) ? implode( ' ', $value ) : (string) $value;
        $value = wp_strip_all_tags( $value );
        return trim( preg_replace( '/\s+/u', ' ', $value ) );
    }

    /**
     * Trim the SEO tail many Iranian shops append to product / card titles:
     * "کراپ نایک - خرید انواع تاپ زنانه ..." → "کراپ نایک".
     *
     * @param string $title Raw title.
     * @return string
     */
    private function clean_product_title( $title ) {
        $original = $this->clean( $title );
        if ( '' === $original ) {
            return '';
        }

        $t = preg_replace(
            '/\s*[|\x{2013}\x{2014}\-]\s*(خرید|قیمت|فروشگاه|لیست\s*قیمت|بهترین\s*قیمت|فروش|سفارش|buy|price|shop)\b.*$/iu',
            '',
            $original
        );
        $t = preg_replace( '/^\s*خرید\s+(?=\S)/u', '', $t );
        $t = trim( (string) $t, " \t\n\r\0\x0B|-" );

        return '' !== $t ? $t : $original;
    }

    /**
     * Digits only, from a mixed Persian/Latin numeric string.
     *
     * @param string $raw Raw value.
     * @return int
     */
    private function digits_only( $raw ) {
        $groups = $this->price_groups( $raw );

        return $groups ? $groups[0] : 0;
    }

    /**
     * Every distinct number group in a price string, as integers, in the order
     * they appear. A discounted price renders both the old and the new value
     * ("۱۹۹٬۰۰۰  ۱۵۹٬۰۰۰"); concatenating their digits would yield a nonsense
     * amount, so each group is parsed separately.
     *
     * @param string $raw Raw price text.
     * @return int[]
     */
    private function price_groups( $raw ) {
        $map = array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9' );
        $raw = strtr( (string) $raw, $map );
        // Drop a decimal tail (".00" / "٫۰۰") — Iranian prices are whole numbers.
        $raw = preg_replace( '/([0-9])[.,٫،]([0-9]{1,2})(?![0-9])/u', '$1', $raw );

        // Only comma/dot-family thousand separators may sit *inside* a number
        // group — never whitespace of any kind. Persian sites never
        // space-group thousands, but they very often render two prices
        // (old/new) right next to each other separated only by whitespace
        // (e.g. two sibling <div>s); treating a space as a valid in-number
        // character would glue those two amounts into one nonsense number.
        if ( ! preg_match_all( '/[0-9][0-9.,٬٫\x{066C}]*[0-9]|[0-9]/u', $raw, $m ) ) {
            return array();
        }

        $out  = array();
        $seen = array();
        foreach ( $m[0] as $chunk ) {
            $n = (int) preg_replace( '/[^0-9]/u', '', $chunk );
            if ( $n >= 100 && ! isset( $seen[ $n ] ) ) {
                $seen[ $n ] = true;
                $out[]      = $n;
            }
        }

        return $out;
    }

    /**
     * Split a price string into a regular / sale pair (both in Toman).
     * When two amounts are present the larger is the regular price and the
     * smaller the sale price.
     *
     * @param string $raw      Raw price text.
     * @param string $currency Optional currency hint.
     * @return array{price:int, sale:int}
     */
    private function split_prices( $raw, $currency = '' ) {
        $groups = $this->price_groups( $raw );
        if ( ! $groups ) {
            return array( 'price' => 0, 'sale' => 0 );
        }

        $unit = function ( $n ) use ( $raw, $currency ) {
            $hay = mb_strtolower( (string) $raw . ' ' . (string) $currency );
            if ( preg_match( '/(ریال|rial|irr)/u', $hay ) && ! preg_match( '/(تومان|تومن|تمن|irt|toman)/u', $hay ) ) {
                return (int) round( $n / 10 );
            }
            return (int) $n;
        };

        if ( count( $groups ) >= 2 ) {
            $max = max( $groups );
            $min = min( $groups );
            return array( 'price' => $unit( $max ), 'sale' => $unit( $min ) );
        }

        return array( 'price' => $unit( $groups[0] ), 'sale' => 0 );
    }

    /**
     * Normalise a raw price to Toman (the currency dadapoosh-style sites use).
     * Rial values — flagged by the "ریال"/"IRR" unit or, for unit-less machine
     * values, cross-checked against the visible Toman text — are divided by 10.
     *
     * @param string $raw      Raw price string (may include a currency word).
     * @param string $currency Optional ISO / hint ("IRR", "IRT", "toman", …).
     * @return int Toman amount (0 when nothing usable).
     */
    private function price_to_toman( $raw, $currency = '' ) {
        $n = $this->digits_only( $raw );
        if ( $n <= 0 ) {
            return 0;
        }

        $hay = mb_strtolower( (string) $raw . ' ' . (string) $currency );

        if ( preg_match( '/(ریال|rial|irr)/u', $hay ) && ! preg_match( '/(تومان|تومن|تمن|irt|toman)/u', $hay ) ) {
            return (int) round( $n / 10 );
        }

        return $n;
    }

    /**
     * Does a URL plausibly point at an image file?
     *
     * @param string $url Absolute URL.
     * @return bool
     */
    private function looks_like_image_url( $url ) {
        if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
            return false;
        }
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        if ( preg_match( '#\.(jpe?g|png|gif|webp|avif|bmp|svg)$#i', $path ) ) {
            return true;
        }
        // Accept extension-less CDN/resizer URLs, but reject obvious page URLs.
        if ( preg_match( '#/(product|products|category|tag|page|cart|checkout|shop)/#i', $path ) ) {
            return false;
        }
        return (bool) preg_match( '#(image|img|media|photo|upload|/wp-content/|cdn|thumb|/i/|/p/)#i', $url );
    }

    /**
     * Resolve a possibly-relative URL against a base.
     *
     * @param string $maybe_relative Candidate URL.
     * @param string $base           Base URL.
     * @return string
     */
    private function absolute( $maybe_relative, $base ) {
        $maybe_relative = trim( (string) $maybe_relative );
        if ( '' === $maybe_relative || 0 === strpos( $maybe_relative, 'data:' ) || 0 === strpos( $maybe_relative, 'javascript:' ) ) {
            return '';
        }
        if ( preg_match( '#^https?://#i', $maybe_relative ) ) {
            return esc_url_raw( $maybe_relative );
        }
        // Some source sites emit a broken absolute URL with a stray leading
        // slash or their own host prepended (e.g.
        // "/http://dl.example.com/x.jpg"). Recover the real URL.
        if ( preg_match( '#^/+(https?://.+)$#i', $maybe_relative, $m ) ) {
            return esc_url_raw( $m[1] );
        }
        // Bare tokens that are clearly not URLs (e.g. a stray JSON-LD "@type"
        // value like "ImageObject" leaking into an image field).
        if ( false === strpos( $maybe_relative, '/' ) && false === strpos( $maybe_relative, '.' ) ) {
            return '';
        }
        if ( 0 === strpos( $maybe_relative, '//' ) ) {
            return esc_url_raw( ( is_ssl() ? 'https:' : 'http:' ) . $maybe_relative );
        }

        $parts  = wp_parse_url( $base );
        $origin = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' );
        if ( isset( $parts['port'] ) ) {
            $origin .= ':' . $parts['port'];
        }

        if ( 0 === strpos( $maybe_relative, '/' ) ) {
            return esc_url_raw( $origin . $maybe_relative );
        }

        $path = isset( $parts['path'] ) ? preg_replace( '#/[^/]*$#', '/', $parts['path'] ) : '/';
        return esc_url_raw( $origin . $path . $maybe_relative );
    }

    /**
     * @param mixed $address JSON-LD address (string or PostalAddress).
     * @return string
     */
    private function stringify_address( $address ) {
        if ( is_string( $address ) ) {
            return $address;
        }
        if ( is_array( $address ) ) {
            $keys = array( 'streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry' );
            $bits = array();
            foreach ( $keys as $key ) {
                if ( ! empty( $address[ $key ] ) ) {
                    $bits[] = is_array( $address[ $key ] ) ? ( $address[ $key ]['name'] ?? '' ) : $address[ $key ];
                }
            }
            return implode( '، ', array_filter( $bits ) );
        }
        return '';
    }

    /**
     * @param \DOMXPath $xpath XPath.
     * @param string    $name  Meta name/property value.
     * @param string    $attr  Attribute to match on ("property" or "name").
     * @return string
     */
    private function meta( \DOMXPath $xpath, $name, $attr = 'property' ) {
        $node = $xpath->query( sprintf( '//meta[@%s="%s"]/@content', $attr, $name ) )->item( 0 );
        return $node ? $node->nodeValue : '';
    }

    /**
     * @param \DOMXPath $xpath XPath.
     * @param string    $query XPath query.
     * @param string    $attr  Attribute name.
     * @return string
     */
    private function first_attr( \DOMXPath $xpath, $query, $attr ) {
        foreach ( $xpath->query( $query ) as $node ) {
            $value = $node->getAttribute( $attr );
            if ( '' !== trim( $value ) ) {
                return $value;
            }
        }
        return '';
    }

    /**
     * @param \DOMXPath $xpath XPath.
     * @param string    $query XPath query.
     * @return string
     */
    private function first_text( \DOMXPath $xpath, $query ) {
        $node = $xpath->query( $query )->item( 0 );
        return $node ? $node->textContent : '';
    }

    /**
     * @param \DOMXPath $xpath   XPath.
     * @param string    $query   Relative query.
     * @param \DOMNode  $context Context node.
     * @param string    $attr    Attribute.
     * @return string
     */
    private function node_attr( \DOMXPath $xpath, $query, $context, $attr ) {
        $node = $xpath->query( $query, $context )->item( 0 );
        return $node ? $node->getAttribute( $attr ) : '';
    }

    /**
     * @param \DOMXPath $xpath   XPath.
     * @param string    $query   Relative query.
     * @param \DOMNode  $context Context node.
     * @return string
     */
    private function node_text( \DOMXPath $xpath, $query, $context ) {
        $node = $xpath->query( $query, $context )->item( 0 );
        return $node ? $node->textContent : '';
    }
}
