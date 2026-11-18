<?php
/**
 * Turns a harvested product into a page that passes the Yoast SEO
 * content analysis (the "green bullet"): a real focus keyphrase, a
 * keyphrase-bearing meta description, an intro sentence and sub-headings that
 * contain the keyphrase, enough body text, and image alt attributes.
 *
 * Works with AI enrichment when it is available and falls back to a
 * fact-anchored template (built only from scraped data) when it is not.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SEO metadata + body writer for harvested products.
 */
class Seo_Optimizer {

    /**
     * Marker meta holding a hash of the body we generated, so a re-run never
     * overwrites text an editor has since rewritten.
     */
    const BODY_MARK = '_shp_seo_body_hash';

    /**
     * Optimise one product.
     *
     * @param int   $product_id Product / listing post ID.
     * @param array $enrichment  AI enrichment payload (may be empty).
     * @param array $facts       Original scraped facts (may be empty — meta is used as a fallback).
     * @return bool True when something was written.
     */
    public function apply( $product_id, array $enrichment = array(), array $facts = array() ) {
        $product_id = (int) $product_id;
        $post       = get_post( $product_id );
        if ( ! $post ) {
            return false;
        }
        if ( get_post_meta( $product_id, '_shp_locked', true ) ) {
            return false;
        }

        $title = wp_strip_all_tags( get_the_title( $product_id ) );
        $brand = (string) get_post_meta( $product_id, '_shp_brand', true );
        if ( '' === $brand && ! empty( $facts['brand'] ) ) {
            $brand = (string) $facts['brand'];
        }

        $keyphrase = trim( (string) ( $enrichment['yoast_focus_keyphrase'] ?? '' ) );
        if ( '' === $keyphrase ) {
            $keyphrase = $this->derive_keyphrase( $title, $brand );
        }
        if ( '' === $keyphrase ) {
            return false;
        }

        $attributes = $this->attributes( $product_id, $facts );
        $store_name = $this->store_name( $product_id, $facts );
        $categories = $this->categories( $product_id );
        $price      = $this->price( $product_id, $facts );

        // --- 1) Body text -------------------------------------------------
        $ai_body   = trim( (string) ( $enrichment['long_description'] ?? '' ) );
        $body      = $this->word_count( $ai_body ) >= 240 ? $ai_body : '';
        if ( '' === $body ) {
            $body = $this->build_body( $keyphrase, $title, $brand, $store_name, $categories, $price, $attributes );
        }
        $body = $this->ensure_keyphrase_shape( $body, $keyphrase );

        $current      = (string) $post->post_content;
        $our_previous = (string) get_post_meta( $product_id, self::BODY_MARK, true );
        $human_edited = '' !== trim( $current )
            && md5( $current ) !== $our_previous
            && $this->word_count( $current ) > 60;

        if ( ! $human_edited ) {
            $updated = wp_update_post(
                array( 'ID' => $product_id, 'post_content' => $body ),
                true
            );
            if ( ! is_wp_error( $updated ) ) {
                update_post_meta( $product_id, self::BODY_MARK, md5( $body ) );
                if ( function_exists( 'wc_get_product' ) && 'product' === $post->post_type ) {
                    $wc = wc_get_product( $product_id );
                    if ( $wc ) {
                        $wc->set_description( $body );
                        $wc->save();
                        update_post_meta( $product_id, self::BODY_MARK, md5( (string) get_post_field( 'post_content', $product_id ) ) );
                    }
                }
            }
        }

        // --- 2) Short description / excerpt ------------------------------
        $short = trim( (string) ( $enrichment['short_description'] ?? '' ) );
        if ( '' === $short ) {
            $short = $this->sentence_case( sprintf(
                /* translators: 1: keyphrase, 2: store name */
                __( '%1$s با بهترین قیمت و ارسال سریع در %2$s. مشخصات کامل، تصاویر و امکان سفارش آنلاین.', 'shopharvester-pro' ),
                $keyphrase,
                $store_name ?: get_bloginfo( 'name' )
            ) );
        }
        if ( '' === trim( (string) $post->post_excerpt ) ) {
            wp_update_post( array( 'ID' => $product_id, 'post_excerpt' => $short ) );
        }

        // --- 3) Meta description --------------------------------------
        $metadesc = trim( (string) ( $enrichment['yoast_metadesc'] ?? '' ) );
        if ( ! $this->metadesc_ok( $metadesc, $keyphrase ) ) {
            $metadesc = $this->build_metadesc( $keyphrase, $store_name, $categories, $price );
        }

        // --- 4) SEO title -------------------------------------------
        $seo_title = trim( (string) ( $enrichment['yoast_title'] ?? '' ) );
        if ( '' === $seo_title || false === mb_stripos( $seo_title, $keyphrase ) ) {
            $suffix    = $store_name ? ' | ' . $store_name : ' | ' . get_bloginfo( 'name' );
            $seo_title = mb_substr( $keyphrase . ( $brand && false === mb_stripos( $keyphrase, $brand ) ? ' ' . $brand : '' ), 0, 55 );
            if ( mb_strlen( $seo_title . $suffix ) <= 60 ) {
                $seo_title .= $suffix;
            }
        }

        // --- 5) Write the SEO meta (Yoast + Rank Math) --------------
        $this->write_meta( $product_id, $keyphrase, $metadesc, $seo_title, (array) ( $enrichment['tags'] ?? array() ) );

        // --- 6) Slug (drafts only, to avoid breaking live URLs) -----
        if ( 'publish' !== $post->post_status ) {
            $slug_src = trim( (string) ( $enrichment['url_slug'] ?? '' ) ) ?: $keyphrase;
            $slug     = sanitize_title( $slug_src );
            if ( '' === $slug ) {
                $slug = sanitize_title( remove_accents( $slug_src ) );
            }
            if ( '' !== $slug && $slug !== $post->post_name ) {
                wp_update_post( array( 'ID' => $product_id, 'post_name' => wp_unique_post_slug( $slug, $product_id, $post->post_status, $post->post_type, 0 ) ) );
            }
        }

        // --- 7) Image alt attributes -------------------------------
        $this->fill_image_alts( $product_id, $keyphrase, (array) ( $enrichment['image_alt_texts'] ?? array() ) );

        update_post_meta( $product_id, '_shp_seo_applied_at', current_time( 'mysql' ) );

        return true;
    }

    /* --------------------------------------------------------------------- *
     *  Meta writers.
     * --------------------------------------------------------------------- */

    /**
     * @param int    $product_id Product ID.
     * @param string $keyphrase  Focus keyphrase.
     * @param string $metadesc   Meta description.
     * @param string $seo_title  SEO title.
     * @param array  $tags       Related keyphrases.
     * @return void
     */
    private function write_meta( $product_id, $keyphrase, $metadesc, $seo_title, array $tags ) {
        // Yoast.
        update_post_meta( $product_id, '_yoast_wpseo_focuskw', $keyphrase );
        update_post_meta( $product_id, '_yoast_wpseo_metadesc', $metadesc );
        if ( '' !== $seo_title ) {
            update_post_meta( $product_id, '_yoast_wpseo_title', $seo_title );
        }
        if ( '1' === (string) get_post_meta( $product_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
            update_post_meta( $product_id, '_yoast_wpseo_meta-robots-noindex', '0' );
        }
        $related = array_values( array_filter( array_map( 'sanitize_text_field', $tags ) ) );
        if ( $related ) {
            $kw = array();
            foreach ( array_slice( $related, 0, 4 ) as $i => $term ) {
                $kw[ (string) ( $i + 1 ) ] = array( 'keyword' => $term, 'score' => '', 'metadesc' => '' );
            }
            update_post_meta( $product_id, '_yoast_wpseo_focuskeywords', wp_json_encode( array_values( $kw ) ) );
        }

        // Rank Math.
        if ( '' === (string) get_post_meta( $product_id, 'rank_math_focus_keyword', true ) ) {
            update_post_meta( $product_id, 'rank_math_focus_keyword', $keyphrase );
        }
        if ( '' === (string) get_post_meta( $product_id, 'rank_math_description', true ) ) {
            update_post_meta( $product_id, 'rank_math_description', $metadesc );
        }
        if ( '' === (string) get_post_meta( $product_id, 'rank_math_title', true ) && '' !== $seo_title ) {
            update_post_meta( $product_id, 'rank_math_title', $seo_title );
        }
        $rm_robots = get_post_meta( $product_id, 'rank_math_robots', true );
        if ( is_array( $rm_robots ) && in_array( 'noindex', $rm_robots, true ) ) {
            update_post_meta( $product_id, 'rank_math_robots', array_values( array_diff( $rm_robots, array( 'noindex' ) ) ) );
        }
    }

    /**
     * Set an alt text on the featured + gallery images where one is missing.
     *
     * @param int    $product_id Product ID.
     * @param string $keyphrase  Focus keyphrase.
     * @param string[] $alts     Optional per-image alt texts from AI.
     * @return void
     */
    private function fill_image_alts( $product_id, $keyphrase, array $alts ) {
        $ids = array();
        $thumb = get_post_thumbnail_id( $product_id );
        if ( $thumb ) {
            $ids[] = (int) $thumb;
        }
        $gallery = get_post_meta( $product_id, '_shp_gallery_ids', true );
        if ( is_array( $gallery ) ) {
            foreach ( $gallery as $g ) {
                $ids[] = (int) $g;
            }
        }
        if ( function_exists( 'wc_get_product' ) ) {
            $wc = wc_get_product( $product_id );
            if ( $wc ) {
                foreach ( (array) $wc->get_gallery_image_ids() as $g ) {
                    $ids[] = (int) $g;
                }
            }
        }
        $ids = array_values( array_unique( array_filter( $ids ) ) );

        foreach ( $ids as $i => $att_id ) {
            $existing = (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true );
            if ( '' !== $existing && false !== mb_stripos( $existing, $keyphrase ) ) {
                continue;
            }
            if ( '' === $existing ) {
                $alt = isset( $alts[ $i ] ) && '' !== trim( (string) $alts[ $i ] )
                    ? sanitize_text_field( $alts[ $i ] )
                    : ( 0 === $i ? $keyphrase : $keyphrase . ' - ' . ( $i + 1 ) );
                update_post_meta( $att_id, '_wp_attachment_image_alt', $alt );
            }
        }
    }

    /* --------------------------------------------------------------------- *
     *  Body + description builders (fact-anchored, no invented claims).
     * --------------------------------------------------------------------- */

    /**
     * A ~320-word HTML product description built only from scraped facts.
     *
     * @param string $keyphrase  Focus keyphrase.
     * @param string $title      Product title.
     * @param string $brand      Brand.
     * @param string $store      Source store name.
     * @param array  $categories Category names.
     * @param string $price      Human price string.
     * @param array  $attributes name => value(s) map.
     * @return string
     */
    private function build_body( $keyphrase, $title, $brand, $store, array $categories, $price, array $attributes ) {
        $cat  = $categories ? $categories[ count( $categories ) - 1 ] : '';
        $site = get_bloginfo( 'name' );

        $intro = sprintf(
            /* translators: 1: keyphrase, 2: site name */
            __( '%1$s یکی از محصولاتی است که در %2$s برای شما گردآوری شده است. در این صفحه مشخصات، تصاویر و اطلاعات خرید %1$s را می‌بینید و می‌توانید آن را با سایر محصولات مشابه مقایسه کنید.', 'shopharvester-pro' ),
            $keyphrase,
            $site
        );
        if ( '' !== $brand ) {
            $intro .= ' ' . sprintf( __( 'این محصول از برند %1$s عرضه شده است.', 'shopharvester-pro' ), $brand );
        }
        if ( '' !== $cat ) {
            $intro .= ' ' . sprintf( __( 'دسته‌بندی این کالا: %s.', 'shopharvester-pro' ), $cat );
        }

        $spec_h2 = sprintf( __( 'مشخصات %s', 'shopharvester-pro' ), $keyphrase );
        $spec    = '';
        if ( $attributes ) {
            $spec .= '<ul>';
            foreach ( $attributes as $k => $v ) {
                $v = is_array( $v ) ? implode( '، ', $v ) : $v;
                $spec .= '<li>' . esc_html( $k . ': ' . $v ) . '</li>';
            }
            $spec .= '</ul>';
            $spec .= '<p>' . esc_html( sprintf(
                __( 'جدول بالا مهم‌ترین ویژگی‌های %s را نشان می‌دهد؛ پیش از خرید این موارد را با نیاز خود بسنجید.', 'shopharvester-pro' ),
                $keyphrase
            ) ) . '</p>';
        } else {
            $spec .= '<p>' . esc_html( sprintf(
                __( 'برای مشاهده‌ی جزئیات کامل و به‌روزترین وضعیت موجودی %1$s، از دکمه‌ی خرید به صفحه‌ی اصلی محصول در %2$s بروید.', 'shopharvester-pro' ),
                $keyphrase,
                $store ?: $site
            ) ) . '</p>';
        }

        $buy_h2 = sprintf( __( 'خرید %s', 'shopharvester-pro' ), $keyphrase );
        $buy    = '<p>' . esc_html( sprintf(
            __( 'برای خرید %1$s کافی است روی دکمه‌ی خرید کلیک کنید تا به صفحه‌ی فروش این محصول هدایت شوید.', 'shopharvester-pro' ),
            $keyphrase
        ) );
        if ( '' !== $price ) {
            $buy .= ' ' . esc_html( sprintf( __( 'قیمت فعلی %1$s برابر %2$s است و ممکن است تغییر کند.', 'shopharvester-pro' ), $keyphrase, $price ) );
        }
        if ( '' !== $store ) {
            $buy .= ' ' . esc_html( sprintf( __( 'فروشنده‌ی این کالا فروشگاه %s است.', 'shopharvester-pro' ), $store ) );
        }
        $buy .= '</p>';

        $why_h2 = sprintf( __( 'نکاتی درباره‌ی %s', 'shopharvester-pro' ), $keyphrase );
        $why    = '<p>' . esc_html( sprintf(
            __( 'هنگام انتخاب %1$s به سایز، جنس و کاربرد آن دقت کنید و در صورت وجود چند گزینه‌ی مشابه، مشخصات و قیمت آن‌ها را کنار هم بگذارید. صفحه‌ی %1$s در %2$s به‌صورت دوره‌ای بازبینی می‌شود تا اطلاعات آن با منبع اصلی هماهنگ بماند.', 'shopharvester-pro' ),
            $keyphrase,
            $site
        ) ) . '</p>';

        return '<p>' . esc_html( $intro ) . '</p>'
            . '<h2>' . esc_html( $spec_h2 ) . '</h2>' . $spec
            . '<h2>' . esc_html( $buy_h2 ) . '</h2>' . $buy
            . '<h2>' . esc_html( $why_h2 ) . '</h2>' . $why;
    }

    /**
     * Make sure the keyphrase is in the first sentence and in at least one
     * sub-heading of an AI-supplied body.
     *
     * @param string $body      HTML body.
     * @param string $keyphrase Focus keyphrase.
     * @return string
     */
    private function ensure_keyphrase_shape( $body, $keyphrase ) {
        $body = trim( $body );
        if ( '' === $body ) {
            return $body;
        }

        // Keyphrase in an <h2>?
        if ( ! preg_match( '/<h[23][^>]*>[^<]*' . preg_quote( $keyphrase, '/' ) . '/iu', $body ) ) {
            if ( preg_match( '/<h([23])([^>]*)>(.*?)<\/h\1>/su', $body, $m ) ) {
                $new  = '<h' . $m[1] . $m[2] . '>' . $keyphrase . ' – ' . $m[3] . '</h' . $m[1] . '>';
                $body = preg_replace( '/<h([23])([^>]*)>(.*?)<\/h\1>/su', $new, $body, 1 );
            } else {
                $body = '<h2>' . esc_html( sprintf( __( 'درباره‌ی %s', 'shopharvester-pro' ), $keyphrase ) ) . '</h2>' . $body;
            }
        }

        // Keyphrase in the opening ~120 chars?
        $plain = wp_strip_all_tags( $body );
        if ( false === mb_stripos( mb_substr( $plain, 0, 130 ), $keyphrase ) ) {
            $lead = '<p>' . esc_html( sprintf( __( '%s — مشخصات، تصاویر و اطلاعات خرید در ادامه.', 'shopharvester-pro' ), $keyphrase ) ) . '</p>';
            $body = $lead . $body;
        }

        return $body;
    }

    /**
     * @param string $keyphrase  Focus keyphrase.
     * @param string $store      Store name.
     * @param array  $categories Categories.
     * @param string $price      Price string.
     * @return string 120–156 char meta description containing the keyphrase.
     */
    private function build_metadesc( $keyphrase, $store, array $categories, $price ) {
        $where = $store ?: get_bloginfo( 'name' );
        $desc  = sprintf( __( 'خرید %1$s از %2$s؛ مشاهده‌ی مشخصات کامل، تصاویر و مقایسه‌ی قیمت.', 'shopharvester-pro' ), $keyphrase, $where );
        if ( '' !== $price && mb_strlen( $desc ) < 110 ) {
            $desc = sprintf( __( 'خرید %1$s از %2$s با قیمت %3$s؛ مشخصات کامل، تصاویر و امکان سفارش آنلاین.', 'shopharvester-pro' ), $keyphrase, $where, $price );
        }
        if ( mb_strlen( $desc ) < 120 && $categories ) {
            $desc .= ' ' . sprintf( __( 'دسته: %s.', 'shopharvester-pro' ), $categories[ count( $categories ) - 1 ] );
        }
        if ( mb_strlen( $desc ) > 156 ) {
            $desc = rtrim( mb_substr( $desc, 0, 153 ) ) . '…';
        }

        return $desc;
    }

    /**
     * @param string $desc      Candidate meta description.
     * @param string $keyphrase Focus keyphrase.
     * @return bool
     */
    private function metadesc_ok( $desc, $keyphrase ) {
        $len = mb_strlen( $desc );

        return $len >= 110 && $len <= 160 && false !== mb_stripos( $desc, $keyphrase );
    }

    /* --------------------------------------------------------------------- *
     *  Fact helpers.
     * --------------------------------------------------------------------- */

    /**
     * A 2–4 word focus keyphrase: the product title with the brand and common
     * filler words removed.
     *
     * @param string $title Product title.
     * @param string $brand Brand.
     * @return string
     */
    private function derive_keyphrase( $title, $brand ) {
        $title = wp_strip_all_tags( (string) $title );
        $title = preg_replace( '/[|\x{2013}\x{2014}\-–—:،,()\[\]"«»]+/u', ' ', $title );
        if ( '' !== $brand ) {
            $title = preg_replace( '/\b' . preg_quote( $brand, '/' ) . '\b/iu', ' ', $title );
        }
        $stop  = array( 'خرید', 'قیمت', 'فروش', 'فروشگاه', 'آنلاین', 'اینترنتی', 'اورجینال', 'اصل', 'جدید', 'کد', 'مدل', 'با', 'و', 'در', 'از', 'ست' );
        $words = preg_split( '/\s+/u', trim( $title ) );
        $words = array_values( array_filter( $words, static function ( $w ) use ( $stop ) {
            return '' !== $w && ! in_array( $w, $stop, true ) && ! preg_match( '/^[0-9۰-۹]+$/u', $w );
        } ) );

        return trim( implode( ' ', array_slice( $words, 0, 4 ) ) );
    }

    /**
     * @param int   $product_id Product ID.
     * @param array $facts      Facts.
     * @return array name => value(s)
     */
    private function attributes( $product_id, array $facts ) {
        if ( ! empty( $facts['attributes'] ) && is_array( $facts['attributes'] ) ) {
            return $facts['attributes'];
        }
        $meta = get_post_meta( $product_id, '_shp_attributes', true );

        return is_array( $meta ) ? $meta : array();
    }

    /**
     * @param int   $product_id Product ID.
     * @param array $facts      Facts.
     * @return string
     */
    private function store_name( $product_id, array $facts ) {
        if ( ! empty( $facts['store_name'] ) ) {
            return (string) $facts['store_name'];
        }
        $store_id = (int) get_post_meta( $product_id, '_shp_store_id', true );

        return $store_id ? wp_strip_all_tags( (string) get_the_title( $store_id ) ) : '';
    }

    /**
     * @param int $product_id Product ID.
     * @return string[]
     */
    private function categories( $product_id ) {
        $out = array();
        foreach ( array( 'product_cat', Entity_Manager::TAX_CAT ) as $tax ) {
            if ( ! taxonomy_exists( $tax ) ) {
                continue;
            }
            $terms = get_the_terms( $product_id, $tax );
            if ( is_array( $terms ) ) {
                foreach ( $terms as $t ) {
                    $out[] = $t->name;
                }
            }
        }

        return array_values( array_unique( $out ) );
    }

    /**
     * @param int   $product_id Product ID.
     * @param array $facts      Facts.
     * @return string
     */
    private function price( $product_id, array $facts ) {
        $raw = (string) get_post_meta( $product_id, '_shp_price', true );
        if ( '' === $raw && ! empty( $facts['price'] ) ) {
            $raw = (string) $facts['price'];
        }
        $digits = preg_replace( '/[^0-9]/', '', $this->to_latin_digits( $raw ) );
        if ( '' === $digits ) {
            return '';
        }

        return number_format_i18n( (float) $digits ) . ' ' . __( 'تومان', 'shopharvester-pro' );
    }

    /**
     * @param string $s Mixed-digit string.
     * @return string
     */
    private function to_latin_digits( $s ) {
        return strtr( (string) $s, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' ) );
    }

    /**
     * @param string $text Text.
     * @return int
     */
    private function word_count( $text ) {
        $text = trim( wp_strip_all_tags( (string) $text ) );

        return '' === $text ? 0 : count( preg_split( '/\s+/u', $text ) );
    }

    /**
     * @param string $s Text.
     * @return string
     */
    private function sentence_case( $s ) {
        return trim( preg_replace( '/\s+/u', ' ', (string) $s ) );
    }
}
