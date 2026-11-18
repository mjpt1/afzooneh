<?php
/**
 * Minimal robots.txt fetch + parse + evaluate.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Caches and evaluates a target host's robots.txt so the scraper can stay
 * polite. Only the `Disallow` / `Allow` directives for `*` and our own
 * user-agent token are considered; wildcards (`*`, `$`) are supported.
 */
class Robots_Checker {

    const CACHE_PREFIX = 'shp_robots_';
    const UA_TOKEN     = 'shopharvesterbot';

    /**
     * Is a URL allowed to be fetched?
     *
     * @param string $url URL.
     * @return bool
     */
    public static function allowed( $url ) {
        if ( ! Settings::get( 'respect_robots' ) ) {
            return true;
        }

        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) ) {
            return true;
        }

        $rules = self::rules_for_host( ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] );
        if ( empty( $rules ) ) {
            return true;
        }

        $path  = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
        $match = array( 'len' => -1, 'allow' => true );

        foreach ( $rules as $rule ) {
            if ( '' === $rule['path'] ) {
                continue;
            }
            if ( self::path_matches( $rule['path'], $path ) ) {
                $len = strlen( $rule['path'] );
                if ( $len > $match['len'] ) {
                    $match = array( 'len' => $len, 'allow' => $rule['allow'] );
                }
            }
        }

        return $match['allow'];
    }

    /**
     * Fetch + parse (cached) the rules that apply to us for a host.
     *
     * @param string $origin scheme://host
     * @return array<int, array{allow:bool, path:string}>
     */
    private static function rules_for_host( $origin ) {
        $key    = self::CACHE_PREFIX . md5( $origin );
        $cached = get_transient( $key );
        if ( false !== $cached ) {
            return is_array( $cached ) ? $cached : array();
        }

        $response = wp_remote_get(
            $origin . '/robots.txt',
            array( 'timeout' => 10, 'redirection' => 3, 'headers' => array( 'User-Agent' => self::UA_TOKEN ) )
        );

        $rules = array();
        if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
            $rules = self::parse( (string) wp_remote_retrieve_body( $response ) );
        }

        set_transient( $key, $rules, HOUR_IN_SECONDS );

        return $rules;
    }

    /**
     * Parse a robots.txt body into the directive list that applies to us.
     * A group whose User-agent is `*` or contains our token is included.
     *
     * @param string $body robots.txt content.
     * @return array
     */
    private static function parse( $body ) {
        $lines   = preg_split( '/\r\n|\r|\n/', $body );
        $groups  = array();
        $current = null;
        $agents  = array();

        foreach ( $lines as $line ) {
            $line = trim( preg_replace( '/#.*$/', '', $line ) );
            if ( '' === $line || false === strpos( $line, ':' ) ) {
                continue;
            }

            list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
            $field                 = strtolower( $field );

            if ( 'user-agent' === $field ) {
                if ( null !== $current && ! empty( $current['rules'] ) ) {
                    $groups[] = $current;
                    $current  = null;
                    $agents   = array();
                }
                $agents[]                = strtolower( $value );
                $current                 = $current ?: array( 'agents' => array(), 'rules' => array() );
                $current['agents']        = array_merge( $current['agents'], $agents );
            } elseif ( in_array( $field, array( 'allow', 'disallow' ), true ) && null !== $current ) {
                $current['rules'][] = array(
                    'allow' => ( 'allow' === $field ),
                    'path'  => $value,
                );
            }
        }
        if ( null !== $current && ! empty( $current['rules'] ) ) {
            $groups[] = $current;
        }

        $applicable = array();
        foreach ( $groups as $group ) {
            foreach ( $group['agents'] as $agent ) {
                if ( '*' === $agent || false !== strpos( $agent, self::UA_TOKEN ) ) {
                    $applicable = array_merge( $applicable, $group['rules'] );
                    break;
                }
            }
        }

        return $applicable;
    }

    /**
     * robots.txt path matching with `*` and `$` wildcards.
     *
     * @param string $pattern Rule path.
     * @param string $path    Request path.
     * @return bool
     */
    private static function path_matches( $pattern, $path ) {
        $regex = preg_quote( $pattern, '#' );
        $regex = str_replace( '\*', '.*', $regex );
        if ( '\$' === substr( $regex, -2 ) ) {
            $regex = substr( $regex, 0, -2 ) . '$';
        }

        return (bool) preg_match( '#^' . $regex . '#', $path );
    }
}
