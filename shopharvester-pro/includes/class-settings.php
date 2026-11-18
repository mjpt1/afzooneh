<?php
/**
 * Central settings registry and accessor.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Typed wrapper around the single `shp_settings` option.
 */
class Settings {

    /**
     * Runtime cache of the settings array.
     *
     * @var array|null
     */
    private static $cache = null;

    /**
     * Default configuration values.
     *
     * @return array
     */
    public static function defaults() {
        return array(
            // General.
            'base_domain'            => self::guess_base_domain(),
            'subdomain_mode'         => 'virtual', // virtual | real
            'default_product_status' => 'draft',
            'product_target'         => 'native', // native | woocommerce | classima

            // Classified Listing (Classima) output.
            // Empty by default: `ad_type` on this kind of site marks a listing
            // as a *shop* (boutique / passage / chain). A harvested product is
            // not a shop, so it must not carry a shop ad_type or it pollutes the
            // stores directory.
            'classima_ad_type'         => '',
            'classima_form_id'         => 0,
            'classima_category_id'     => 0,
            'classima_location_id'     => 0,
            'classima_listing_status'  => 'draft', // draft | pending | publish
            'classima_make_vendor'     => true,     // also register a WCFM multivendor vendor + storefront (/store/<slug>) per source shop

            // Group each product listing under a "shop" listing for its source
            // store. On a Classified Listing directory the shop itself is just
            // another rtcl_listing; products nest under it via post_parent.
            'classima_create_listing'  => false,    // also create an rtcl_listing (آگهی) per product; off = WooCommerce + WCFM only
            'classima_shop_directory'  => true,     // create ONE rtcl_listing per source shop so the shop appears in /all-ads (independent of per-product listings)
            'classima_group_by_shop'   => true,
            'classima_shop_ad_type'    => '',       // ad_type for the shop listing (from rtcl_listing_types)
            'classima_shop_status'     => 'publish', // pending | publish
            'classima_hide_products_from_directory' => true, // product listings show only on their shop page, not the /all-ads directory
            'classima_buy_button'      => true,     // show a "buy on source shop" button on product listings

            // Marketplace / price-comparison mode (Torob-style).
            'marketplace_mode'       => false,
            'buy_button_mode'        => 'external', // external | native
            'buy_button_text'        => 'خرید از فروشگاه',
            'match_fuzzy_threshold'   => 90,
            'extract_full_specs'     => true,
            'duplicate_product_mode' => 'update', // update | skip
            'resync_scope'           => 'full',   // full | price_stock
            'auto_resync_hours'      => 0,        // 0 = off
            'purge_on_uninstall'     => false,

            // Outbound HTTP politeness / routing.
            'proxy_url'              => '',
            'proxy_type'             => 'http',   // http | socks5 | socks4
            'request_delay_ms'       => 0,
            'respect_robots'         => false,

            // Category / collection building.
            'category_target'                 => 'shp', // shp | woo | both
            'category_auto_create'            => true,
            'category_fuzzy_threshold'        => 82,
            'import_collections_as_categories' => true,

            // Built-in server-side scraper (no external runner needed).
            'builtin_scraper_enabled' => true,
            'builtin_max_pages'       => 5,
            'builtin_timeout'         => 25,
            'builtin_follow_products' => true,
            'builtin_max_products'    => 200,

            // JavaScript rendering via an external rendering API (so the
            // operator never has to run anything themselves).
            'js_render_enabled'      => false,
            'js_render_url_template' => '',
            'js_render_timeout'      => 60,
            'js_render_max_products' => 40,

            // AI engine.
            'ai_enabled'             => true,
            'ai_provider'            => 'openai', // openai | gemini | perplexity
            'ai_model'               => 'gpt-4o-mini',
            'openai_api_key'         => '',
            'gapgpt_api_key'         => '',
            'gapgpt_base_url'        => 'https://api.gapgpt.app/v1',
            'gemini_api_key'         => '',
            'perplexity_api_key'     => '',
            'ai_language'            => 'fa',
            'ai_timeout'             => 45,
            'ai_prompt_template'     => self::default_prompt_template(),

            // Media.
            'download_images'        => true,
            'max_images_per_product' => 8,
            'image_timeout'          => 30,

            // Webhook.
            'webhook_secret'         => wp_generate_password( 40, false, false ),

            // Logging.
            'log_retention_days'     => 30,
            'log_to_file_fallback'   => true,
        );
    }

    /**
     * The default AI prompt template. Variables are wrapped in {{curly}} tokens.
     *
     * @return string
     */
    public static function default_prompt_template() {
        return implode(
            "\n",
            array(
                'شما یک متخصص ارشد سئو و کپی‌رایتینگ فروشگاهی هستید و خروجی باید تحلیل محتوایی Yoast SEO را «سبز» کند.',
                'تنها بر پایه «حقایق استخراج‌شده» زیر خروجی تولید کنید و هیچ ویژگی، عدد یا ادعای کیفی از خود اضافه نکنید.',
                'زبان خروجی: {{language}}',
                '',
                'حقایق محصول:',
                'عنوان اصلی: {{title}}',
                'برند: {{brand}}',
                'قیمت: {{price}}',
                'دسته‌ها: {{categories}}',
                'ویژگی‌ها: {{attributes}}',
                'توضیحات خام: {{raw_description}}',
                'نام فروشگاه: {{store_name}}',
                '',
                'قواعد اجباری:',
                '- yoast_focus_keyphrase: ۲ تا ۴ کلمه، مهم‌ترین عبارتِ خودِ کالا (نه فقط نام برند، نه کلماتی مثل «خرید» یا «قیمت»).',
                '- long_description: HTML معتبر، حداقل ۳۲۰ کلمه. عبارت کانونی عیناً در «جمله‌ی اول» بیاید، و در «حداقل دو تگ <h2>». چند پاراگراف کوتاه و یک فهرست <ul> از ویژگی‌ها. تراکم عبارت کانونی حدود ۱ تا ۱٫۵٪ (نه بیشتر). بدون کلمات پرکننده و بدون تکرار مصنوعی.',
                '- yoast_metadesc: بین ۱۲۰ تا ۱۵۵ کاراکتر، شاملِ عبارت کانونی، لحن فعال.',
                '- yoast_title: حداکثر ۶۰ کاراکتر، با عبارت کانونی «شروع» شود.',
                '- url_slug: با خط تیره، شاملِ عبارت کانونی، حداکثر ۵ کلمه.',
                '- image_alt_texts: آرایه؛ هر متن کوتاه و شاملِ عبارت کانونی به‌صورت طبیعی.',
                '- short_description: یک تا دو جمله شاملِ عبارت کانونی.',
                '',
                'خروجی را «فقط» به صورت یک شیء JSON معتبر با این کلیدها بازگردانید:',
                'seo_title, short_description, long_description, yoast_title, yoast_metadesc, yoast_focus_keyphrase, url_slug, tags (آرایه), image_alt_texts (آرایه).',
            )
        );
    }

    /**
     * Best-effort detection of the site base domain.
     *
     * @return string
     */
    private static function guess_base_domain() {
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        return $host ? preg_replace( '/^www\./i', '', $host ) : '';
    }

    /**
     * Return the full merged settings array.
     *
     * @return array
     */
    public static function all() {
        if ( null === self::$cache ) {
            $stored     = get_option( 'shp_settings', array() );
            self::$cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
        }

        return self::$cache;
    }

    /**
     * Return a single setting value.
     *
     * @param string $key     Setting key.
     * @param mixed  $default Fallback value.
     * @return mixed
     */
    public static function get( $key, $default = null ) {
        $all = self::all();
        return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
    }

    /**
     * Persist a full settings array (already sanitized by the caller).
     *
     * @param array $values Settings values.
     * @return void
     */
    public static function update( array $values ) {
        $merged = wp_parse_args( $values, self::defaults() );
        update_option( 'shp_settings', $merged );
        self::$cache = $merged;
    }

    /**
     * Temporarily override settings for the current request only (not saved).
     * Used by the "test connection" tools so operators can try values before
     * committing them.
     *
     * @param array $values Partial settings.
     * @return void
     */
    public static function override( array $values ) {
        self::$cache = wp_parse_args( $values, self::all() );
    }

    /**
     * Sanitize a raw settings payload coming from the admin form.
     *
     * @param array $raw Raw values.
     * @return array
     */
    public static function sanitize( array $raw ) {
        $defaults = self::defaults();
        $clean    = array();

        $clean['base_domain']            = isset( $raw['base_domain'] ) ? preg_replace( '/[^a-z0-9\.\-]/i', '', (string) $raw['base_domain'] ) : $defaults['base_domain'];
        $clean['subdomain_mode']         = in_array( $raw['subdomain_mode'] ?? '', array( 'virtual', 'real' ), true ) ? $raw['subdomain_mode'] : 'virtual';
        $clean['default_product_status'] = 'draft'; // Enforced by design; products are never auto-published.
        $clean['duplicate_product_mode'] = in_array( $raw['duplicate_product_mode'] ?? '', array( 'update', 'skip' ), true ) ? $raw['duplicate_product_mode'] : 'update';
        $clean['resync_scope']           = in_array( $raw['resync_scope'] ?? '', array( 'full', 'price_stock' ), true ) ? $raw['resync_scope'] : 'full';
        $clean['auto_resync_hours']      = in_array( (int) ( $raw['auto_resync_hours'] ?? 0 ), array( 0, 6, 12, 24, 48, 72, 168 ), true ) ? (int) $raw['auto_resync_hours'] : 0;
        $target = (string) ( $raw['product_target'] ?? '' );
        if ( 'woocommerce' === $target && ( class_exists( 'WooCommerce' ) || post_type_exists( 'product' ) ) ) {
            $clean['product_target'] = 'woocommerce';
        } elseif ( 'classima' === $target && ( post_type_exists( 'rtcl_listing' ) || class_exists( '\Rtcl\Services\Importers\ListingIngester' ) ) ) {
            $clean['product_target'] = 'classima';
        } else {
            $clean['product_target'] = 'native';
        }

        $clean['classima_ad_type']        = sanitize_key( $raw['classima_ad_type'] ?? '' );
        $clean['classima_form_id']        = max( 0, (int) ( $raw['classima_form_id'] ?? 0 ) );
        $clean['classima_category_id']    = max( 0, (int) ( $raw['classima_category_id'] ?? 0 ) );
        $clean['classima_location_id']    = max( 0, (int) ( $raw['classima_location_id'] ?? 0 ) );
        $clean['classima_listing_status'] = in_array( $raw['classima_listing_status'] ?? '', array( 'draft', 'pending', 'publish' ), true ) ? $raw['classima_listing_status'] : 'draft';
        $clean['classima_make_vendor']    = ! empty( $raw['classima_make_vendor'] );
        $clean['classima_create_listing'] = ! empty( $raw['classima_create_listing'] );
        $clean['classima_shop_directory'] = ! empty( $raw['classima_shop_directory'] );
        $clean['classima_group_by_shop']  = ! empty( $raw['classima_group_by_shop'] );
        $clean['classima_shop_ad_type']   = sanitize_key( $raw['classima_shop_ad_type'] ?? '' );
        $clean['classima_shop_status']    = in_array( $raw['classima_shop_status'] ?? '', array( 'pending', 'publish' ), true ) ? $raw['classima_shop_status'] : 'publish';
        $clean['classima_hide_products_from_directory'] = ! empty( $raw['classima_hide_products_from_directory'] );
        $clean['classima_buy_button']     = ! empty( $raw['classima_buy_button'] );

        $clean['marketplace_mode']      = ! empty( $raw['marketplace_mode'] );
        $clean['buy_button_mode']       = in_array( $raw['buy_button_mode'] ?? '', array( 'external', 'native' ), true ) ? $raw['buy_button_mode'] : 'external';
        $clean['buy_button_text']       = sanitize_text_field( $raw['buy_button_text'] ?? '' ) ?: 'خرید از فروشگاه';
        $clean['match_fuzzy_threshold'] = max( 0, min( 100, (int) ( $raw['match_fuzzy_threshold'] ?? 90 ) ) );
        $clean['extract_full_specs']    = ! empty( $raw['extract_full_specs'] );

        $clean['proxy_url']              = trim( (string) ( $raw['proxy_url'] ?? '' ) );
        $clean['proxy_type']            = in_array( $raw['proxy_type'] ?? '', array( 'http', 'socks5', 'socks4' ), true ) ? $raw['proxy_type'] : 'http';
        $clean['request_delay_ms']      = max( 0, min( 60000, (int) ( $raw['request_delay_ms'] ?? 0 ) ) );
        $clean['respect_robots']        = ! empty( $raw['respect_robots'] );
        $clean['purge_on_uninstall']     = ! empty( $raw['purge_on_uninstall'] );

        $clean['category_target']                  = in_array( $raw['category_target'] ?? '', array( 'shp', 'woo', 'both' ), true ) ? $raw['category_target'] : 'shp';
        $clean['category_auto_create']             = ! empty( $raw['category_auto_create'] );
        $clean['category_fuzzy_threshold']         = max( 0, min( 100, (int) ( $raw['category_fuzzy_threshold'] ?? 82 ) ) );
        $clean['import_collections_as_categories'] = ! empty( $raw['import_collections_as_categories'] );

        $clean['builtin_scraper_enabled'] = ! empty( $raw['builtin_scraper_enabled'] );
        $clean['builtin_max_pages']       = max( 1, min( 50, (int) ( $raw['builtin_max_pages'] ?? 5 ) ) );
        $clean['builtin_timeout']         = max( 5, min( 120, (int) ( $raw['builtin_timeout'] ?? 25 ) ) );
        $clean['builtin_follow_products'] = ! empty( $raw['builtin_follow_products'] );
        $clean['builtin_max_products']    = max( 1, min( 10000, (int) ( $raw['builtin_max_products'] ?? 200 ) ) );

        $clean['js_render_enabled']      = ! empty( $raw['js_render_enabled'] );
        $clean['js_render_url_template'] = trim( (string) ( $raw['js_render_url_template'] ?? '' ) );
        $clean['js_render_timeout']      = max( 10, min( 180, (int) ( $raw['js_render_timeout'] ?? 60 ) ) );
        $clean['js_render_max_products'] = max( 1, min( 2000, (int) ( $raw['js_render_max_products'] ?? 40 ) ) );

        $clean['ai_enabled']         = ! empty( $raw['ai_enabled'] );
        $clean['ai_provider']        = in_array( $raw['ai_provider'] ?? '', array( 'openai', 'gapgpt', 'gemini', 'perplexity' ), true ) ? $raw['ai_provider'] : 'openai';
        $clean['ai_model']           = sanitize_text_field( $raw['ai_model'] ?? $defaults['ai_model'] );
        $clean['openai_api_key']     = sanitize_text_field( $raw['openai_api_key'] ?? '' );
        $clean['gapgpt_api_key']     = sanitize_text_field( $raw['gapgpt_api_key'] ?? '' );
        $clean['gapgpt_base_url']    = esc_url_raw( $raw['gapgpt_base_url'] ?? '' ) ?: $defaults['gapgpt_base_url'];
        $clean['gemini_api_key']     = sanitize_text_field( $raw['gemini_api_key'] ?? '' );
        $clean['perplexity_api_key'] = sanitize_text_field( $raw['perplexity_api_key'] ?? '' );
        $clean['ai_language']        = sanitize_text_field( $raw['ai_language'] ?? 'fa' );
        $clean['ai_timeout']         = max( 10, min( 180, (int) ( $raw['ai_timeout'] ?? 45 ) ) );
        $clean['ai_prompt_template'] = wp_kses_post( $raw['ai_prompt_template'] ?? $defaults['ai_prompt_template'] );

        $clean['download_images']        = ! empty( $raw['download_images'] );
        $clean['max_images_per_product'] = max( 1, min( 30, (int) ( $raw['max_images_per_product'] ?? 8 ) ) );
        $clean['image_timeout']          = max( 5, min( 120, (int) ( $raw['image_timeout'] ?? 30 ) ) );

        $clean['webhook_secret']       = sanitize_text_field( $raw['webhook_secret'] ?? '' ) ?: $defaults['webhook_secret'];
        $clean['log_retention_days']   = max( 1, min( 365, (int) ( $raw['log_retention_days'] ?? 30 ) ) );
        $clean['log_to_file_fallback'] = ! empty( $raw['log_to_file_fallback'] );

        return $clean;
    }
}
