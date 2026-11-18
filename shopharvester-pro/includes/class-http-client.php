<?php
/**
 * Shared outbound HTTP client: proxy, politeness delay, robots.txt.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Every remote fetch in the plugin (page scraping, Store/Shopify APIs, image
 * downloads) goes through here so proxy routing, per-host rate limiting and
 * robots.txt compliance are applied consistently.
 */
class Http_Client {

    /**
     * Wall-clock time of the last request per host.
     *
     * @var array<string, float>
     */
    private static $last_request = array();

    /**
     * Currently attached proxy callback (for begin()/end()).
     *
     * @var callable|null
     */
    private static $active_cb = null;

    /**
     * Attach the proxy routing to WP HTTP for the duration of a block of
     * requests made through WP core helpers such as download_url().
     *
     * @return void
     */
    public static function begin() {
        $cb = self::proxy_callback();
        if ( $cb ) {
            add_action( 'http_api_curl', $cb, 10, 1 );
            self::$active_cb = $cb;
        }
    }

    /**
     * Detach the proxy routing attached by begin().
     *
     * @return void
     */
    public static function end() {
        if ( self::$active_cb ) {
            remove_action( 'http_api_curl', self::$active_cb, 10 );
            self::$active_cb = null;
        }
    }

    /**
     * Perform a GET request with the shared policy applied.
     *
     * @param string $url  URL.
     * @param array  $args Extra wp_remote_get args (merged).
     * @return array|\WP_Error WP HTTP response or error.
     */
    public static function get( $url, array $args = array() ) {
        if ( ! Robots_Checker::allowed( $url ) ) {
            return new \WP_Error( 'shp_robots_blocked', sprintf( 'robots.txt disallows %s', $url ) );
        }

        self::throttle( $url );

        $defaults = array(
            'timeout'     => 20,
            'redirection' => 5,
            'sslverify'   => true,
            'user-agent'  => 'Mozilla/5.0 (compatible; ShopHarvesterBot/1.0; +https://mahsen81.ir)',
            'headers'     => array(),
        );

        $args = array_merge( $defaults, $args );

        $proxy_cb = self::proxy_callback();
        if ( $proxy_cb ) {
            add_action( 'http_api_curl', $proxy_cb, 10, 1 );
        }

        $response = wp_remote_get( $url, $args );

        if ( $proxy_cb ) {
            remove_action( 'http_api_curl', $proxy_cb, 10 );
        }

        return $response;
    }

    /**
     * Sleep just enough to honour the configured per-host delay.
     *
     * @param string $url URL about to be fetched.
     * @return void
     */
    private static function throttle( $url ) {
        $delay_ms = (int) Settings::get( 'request_delay_ms', 0 );
        if ( $delay_ms < 1 ) {
            return;
        }

        $host = (string) wp_parse_url( $url, PHP_URL_HOST );
        $now  = microtime( true );

        if ( isset( self::$last_request[ $host ] ) ) {
            $elapsed = ( $now - self::$last_request[ $host ] ) * 1000;
            if ( $elapsed < $delay_ms ) {
                usleep( (int) ( ( $delay_ms - $elapsed ) * 1000 ) );
            }
        }

        self::$last_request[ $host ] = microtime( true );
    }

    /**
     * Build the cURL proxy callback from settings, or null when disabled.
     *
     * @return callable|null
     */
    private static function proxy_callback() {
        $proxy = trim( (string) Settings::get( 'proxy_url', '' ) );
        if ( '' === $proxy ) {
            return null;
        }

        $type = (string) Settings::get( 'proxy_type', 'http' );

        return static function ( $handle ) use ( $proxy, $type ) {
            if ( ! is_resource( $handle ) && ! ( $handle instanceof \CurlHandle ) ) {
                return;
            }
            curl_setopt( $handle, CURLOPT_PROXY, $proxy );
            if ( 'socks5' === $type && defined( 'CURLPROXY_SOCKS5_HOSTNAME' ) ) {
                curl_setopt( $handle, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME );
            } elseif ( 'socks5' === $type && defined( 'CURLPROXY_SOCKS5' ) ) {
                curl_setopt( $handle, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5 );
            } elseif ( 'socks4' === $type && defined( 'CURLPROXY_SOCKS4' ) ) {
                curl_setopt( $handle, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4 );
            }
        };
    }

    /**
     * True when a proxy is configured (used for the system report).
     *
     * @return bool
     */
    public static function has_proxy() {
        return '' !== trim( (string) Settings::get( 'proxy_url', '' ) );
    }

    /**
     * Is a JavaScript rendering API configured?
     *
     * @return bool
     */
    public static function has_js_render() {
        $tpl = trim( (string) Settings::get( 'js_render_url_template', '' ) );
        return Settings::get( 'js_render_enabled' ) && '' !== $tpl && false !== strpos( $tpl, '{url}' );
    }

    /**
     * Fetch a URL through the configured JavaScript rendering API
     * (ScrapingBee / ScraperAPI / ZenRows / self-hosted Browserless, etc.).
     * The operator supplies a URL template containing `{url}`.
     *
     * @param string $url  Target page URL.
     * @param array  $args Extra wp_remote_get args.
     * @return array|\WP_Error Rendered HTML response, or error.
     */
    public static function get_rendered( $url, array $args = array() ) {
        if ( ! self::has_js_render() ) {
            return new \WP_Error( 'shp_no_js_render', 'JS rendering API is not configured.' );
        }

        $endpoint = str_replace( '{url}', rawurlencode( $url ), trim( (string) Settings::get( 'js_render_url_template', '' ) ) );

        return self::get(
            $endpoint,
            array_merge( array( 'timeout' => (int) Settings::get( 'js_render_timeout', 60 ) ), $args )
        );
    }
}
