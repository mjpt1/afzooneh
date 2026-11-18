<?php
/**
 * Developer-facing system report and full log export.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Produces a single copy/paste-ready diagnostics block plus a complete JSON
 * log dump — the artefacts a developer needs to debug an installation.
 */
class System_Report {

    /**
     * Build the plain-text diagnostics report.
     *
     * @return string
     */
    public static function generate() {
        global $wpdb;

        $lines = array();
        $row   = static function ( $key, $value ) use ( &$lines ) {
            $lines[] = str_pad( (string) $key, 26 ) . ' : ' . $value;
        };

        $lines[] = '=================================================';
        $lines[] = ' ShopHarvester Pro — System Report';
        $lines[] = '=================================================';
        $row( 'Generated', current_time( 'mysql' ) . ' (site tz)' );
        $row( 'Plugin version', SHP_VERSION );
        $row( 'DB version', get_option( 'shp_db_version', 'n/a' ) );
        $row( 'Rewrite version', get_option( 'shp_rewrite_version', 'n/a' ) );

        $lines[] = '';
        $lines[] = '--- Environment ---';
        $row( 'PHP', PHP_VERSION );
        $row( 'WordPress', get_bloginfo( 'version' ) );
        $row( 'Home / Site URL', home_url() . '  |  ' . site_url() );
        $row( 'Multisite', is_multisite() ? 'yes' : 'no' );
        $row( 'Server', isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'unknown' );
        $row( 'memory_limit', ini_get( 'memory_limit' ) );
        $row( 'max_execution_time', ini_get( 'max_execution_time' ) );
        $row( 'upload_max_filesize', ini_get( 'upload_max_filesize' ) );
        $row( 'allow_url_fopen', ini_get( 'allow_url_fopen' ) ? 'on' : 'off' );
        $row( 'cURL', function_exists( 'curl_version' ) ? ( curl_version()['version'] ?? 'yes' ) : 'MISSING' );
        $row( 'Extensions', implode(
            ', ',
            array_filter(
                array(
                    extension_loaded( 'mbstring' ) ? 'mbstring' : '!mbstring',
                    extension_loaded( 'dom' ) ? 'dom' : '!dom',
                    extension_loaded( 'libxml' ) ? 'libxml' : '!libxml',
                    extension_loaded( 'json' ) ? 'json' : '!json',
                    extension_loaded( 'gd' ) ? 'gd' : '',
                    extension_loaded( 'imagick' ) ? 'imagick' : '',
                )
            )
        ) );
        $row( 'WP memory limit', WP_MEMORY_LIMIT );
        $row( 'WP_DEBUG', ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'true' : 'false' );

        $lines[] = '';
        $lines[] = '--- Cron / Queue ---';
        $row( 'DISABLE_WP_CRON', ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? 'TRUE  <-- external cron required!' : 'false' );
        $row( 'ALTERNATE_WP_CRON', ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) ? 'true' : 'false' );
        $next = wp_next_scheduled( 'shp_process_job_queue' );
        $row( 'queue cron next run', $next ? ( gmdate( 'Y-m-d H:i:s', $next ) . ' UTC (' . ( $next > time() ? 'in ' : '' ) . human_time_diff( time(), $next ) . ')' ) : 'NOT SCHEDULED' );
        $maint = wp_next_scheduled( 'shp_daily_maintenance' );
        $row( 'daily maintenance', $maint ? gmdate( 'Y-m-d H:i:s', $maint ) . ' UTC' : 'not scheduled' );
        $lock = (int) get_transient( 'shp_queue_lock' );
        $row( 'queue lock', $lock ? ( 'LOCKED (' . human_time_diff( $lock, time() ) . ' ago)' ) : 'free' );
        $as_pending = Scheduler::pending_count();
        $row( 'Action Scheduler', Scheduler::available() ? ( 'active — ' . ( null === $as_pending ? 'n/a' : $as_pending . ' pending' ) ) : 'not available (using WP-Cron)' );
        $poll = (int) get_option( 'shp_last_runner_poll', 0 );
        $row( 'Last Playwright runner poll', $poll ? ( human_time_diff( $poll, time() ) . ' ago' ) : 'NEVER (no external runner connected)' );

        $lines[] = '';
        $lines[] = '--- Harvest config ---';
        $row( 'Product output', Settings::get( 'product_target' ) );
        $row( 'Marketplace mode', Settings::get( 'marketplace_mode' ) ? ( 'on — buy: ' . Settings::get( 'buy_button_mode' ) ) : 'off' );
        $row( 'Offer rows', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'shp_offers' ) ); // phpcs:ignore
        $row( 'Re-sync scope', Settings::get( 'resync_scope' ) );
        $row( 'Auto re-sync', Settings::get( 'auto_resync_hours' ) ? ( Settings::get( 'auto_resync_hours' ) . 'h' ) : 'off' );
        $row( 'Proxy', Http_Client::has_proxy() ? ( Settings::get( 'proxy_type' ) . ' proxy configured' ) : 'none' );
        $row( 'Request delay', Settings::get( 'request_delay_ms' ) . ' ms' );
        $row( 'Respect robots.txt', Settings::get( 'respect_robots' ) ? 'yes' : 'no' );
        $row( 'JS rendering API', Http_Client::has_js_render() ? 'configured' : ( Settings::get( 'js_render_enabled' ) ? 'ENABLED but template invalid' : 'off' ) );

        $lines[] = '';
        $lines[] = '--- Integrations ---';
        $row( 'WooCommerce', defined( 'WC_VERSION' ) ? WC_VERSION : ( class_exists( 'WooCommerce' ) ? 'active' : 'not active' ) );
        $row( 'WC Store API (self)', self::probe_endpoint( rest_url( 'wc/store/v1/products?per_page=1' ) ) );
        $row( 'Yoast SEO', defined( 'WPSEO_VERSION' ) ? WPSEO_VERSION : 'not active' );
        $theme = wp_get_theme();
        $row( 'Active theme', $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) );

        $lines[] = '';
        $lines[] = '--- Active plugins ---';
        foreach ( self::active_plugins() as $plugin ) {
            $lines[] = '  - ' . $plugin;
        }

        $lines[] = '';
        $lines[] = '--- Settings (secrets masked) ---';
        foreach ( Settings::all() as $key => $value ) {
            if ( preg_match( '/key|secret|token/i', $key ) ) {
                $value = $value ? ( '*** set (' . strlen( (string) $value ) . ' chars) ***' ) : '(empty)';
            } elseif ( 'ai_prompt_template' === $key ) {
                $value = '(' . strlen( (string) $value ) . ' chars)';
            } elseif ( is_bool( $value ) ) {
                $value = $value ? 'true' : 'false';
            } elseif ( is_array( $value ) ) {
                $value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
            }
            $lines[] = '  ' . str_pad( $key, 32 ) . ' = ' . $value;
        }

        $lines[] = '';
        $lines[] = '--- Database tables ---';
        $row( 'shp_db_version option', get_option( 'shp_db_version', 'n/a' ) );
        foreach ( Activator::table_names() as $name ) {
            $table  = $wpdb->prefix . $name;
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore
            $row( $name, $exists ? ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) . ' rows' ) : '*** MISSING ***' ); // phpcs:ignore
        }
        if ( get_option( 'shp_db_broken' ) ) {
            $row( 'DB CREATE TABLE', 'FAILED at ' . get_option( 'shp_db_broken' ) );
        }

        $lines[] = '';
        $lines[] = '--- Data ---';
        $stores   = wp_count_posts( Entity_Manager::STORE_CPT );
        $ptype    = Entity_Manager::product_post_type();
        $products = wp_count_posts( $ptype );
        $row( 'Store profiles', (int) ( $stores->publish ?? 0 ) );
        $row( 'Products [' . $ptype . '] (d/p/pend/trash)', sprintf( '%d / %d / %d / %d', $products->draft ?? 0, $products->publish ?? 0, $products->pending ?? 0, $products->trash ?? 0 ) );
        $row( 'Product categories', (int) wp_count_terms( array( 'taxonomy' => Entity_Manager::TAX_CAT, 'hide_empty' => false ) ) );
        $row( 'Log rows', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}shp_logs" ) ); // phpcs:ignore
        $row( 'De-dup hash rows', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}shp_product_hashes" ) ); // phpcs:ignore

        $lines[] = '';
        $lines[] = '--- Jobs by status ---';
        foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) c FROM {$wpdb->prefix}shp_jobs GROUP BY status" ) as $r ) { // phpcs:ignore
            $lines[] = '  ' . str_pad( (string) $r->status, 16 ) . ' ' . $r->c;
        }

        $lines[] = '';
        $lines[] = '--- Last 15 jobs ---';
        $jobs = $wpdb->get_results( "SELECT id, status, attempts, target_url, created_at, updated_at, LEFT(stats,300) stats, LEFT(last_error,500) last_error FROM {$wpdb->prefix}shp_jobs ORDER BY id DESC LIMIT 15" ); // phpcs:ignore
        foreach ( (array) $jobs as $j ) {
            $lines[] = sprintf( '  #%d [%s] attempts=%d  %s', $j->id, $j->status, $j->attempts, $j->target_url );
            $lines[] = sprintf( '      created %s / updated %s', $j->created_at, $j->updated_at );
            if ( $j->stats ) {
                $lines[] = '      stats: ' . $j->stats;
            }
            if ( $j->last_error ) {
                $lines[] = '      error: ' . str_replace( array( "\r", "\n" ), ' | ', $j->last_error );
            }
        }

        $lines[] = '';
        $lines[] = '--- Last 80 log entries (oldest first) ---';
        $logs = $wpdb->get_results( "SELECT created_at, level, channel, message, source_url, context FROM {$wpdb->prefix}shp_logs ORDER BY id DESC LIMIT 80" ); // phpcs:ignore
        foreach ( array_reverse( (array) $logs ) as $l ) {
            $lines[] = sprintf(
                '[%s] %-7s %-10s %s%s',
                $l->created_at,
                $l->level,
                $l->channel,
                $l->message,
                $l->source_url ? ( '  <' . $l->source_url . '>' ) : ''
            );
            if ( $l->context && 'null' !== $l->context ) {
                $lines[] = '        ctx: ' . mb_substr( (string) $l->context, 0, 600 );
            }
        }

        $lines[] = '';
        $lines[] = '=== end of report ===';

        return implode( "\n", $lines );
    }

    /**
     * Full log table as a portable JSON document.
     *
     * @param int $limit Max rows.
     * @return string
     */
    public static function full_log_json( $limit = 5000 ) {
        global $wpdb;

        $limit = max( 1, min( 50000, (int) $limit ) );
        $rows  = $wpdb->get_results( // phpcs:ignore
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}shp_logs ORDER BY id DESC LIMIT %d", $limit ),
            ARRAY_A
        );

        foreach ( $rows as &$r ) {
            $r['context'] = ( $r['context'] && 'null' !== $r['context'] ) ? json_decode( $r['context'], true ) : null;
        }
        unset( $r );

        return (string) wp_json_encode(
            array(
                'plugin'    => 'ShopHarvester Pro',
                'version'   => SHP_VERSION,
                'site'      => home_url(),
                'generated' => current_time( 'mysql' ),
                'count'     => count( $rows ),
                'logs'      => $rows,
            ),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * Outbound-connectivity self-test. Confirms the server can actually reach
     * the internet (the most common reason harvesting "does nothing").
     *
     * @param string[] $extra_urls Additional URLs to test.
     * @return array<int, array{url:string, ok:bool, info:string, ms:int}>
     */
    public static function network_test( array $extra_urls = array() ) {
        $targets = array_merge(
            array(
                'https://www.google.com/robots.txt',
                'https://raw.githubusercontent.com/robots.txt',
                home_url( '/' ),
                rest_url( 'wc/store/v1/products?per_page=1' ),
            ),
            array_filter( array_map( 'esc_url_raw', $extra_urls ) )
        );

        $results = array();
        foreach ( array_unique( $targets ) as $url ) {
            $start    = microtime( true );
            $response = wp_remote_get(
                $url,
                array(
                    'timeout'     => 12,
                    'redirection' => 3,
                    'sslverify'   => true,
                    'headers'     => array( 'User-Agent' => 'ShopHarvesterBot/1.0' ),
                )
            );
            $ms = (int) round( ( microtime( true ) - $start ) * 1000 );

            if ( is_wp_error( $response ) ) {
                $results[] = array( 'url' => $url, 'ok' => false, 'info' => $response->get_error_message(), 'ms' => $ms );
                continue;
            }

            $code      = (int) wp_remote_retrieve_response_code( $response );
            $results[] = array(
                'url'  => $url,
                'ok'   => ( $code >= 200 && $code < 400 ),
                'info' => 'HTTP ' . $code,
                'ms'   => $ms,
            );
        }

        return $results;
    }

    /**
     * Quick HTTP probe used for the Store API line.
     *
     * @param string $url URL.
     * @return string
     */
    private static function probe_endpoint( $url ) {
        $response = wp_remote_get( $url, array( 'timeout' => 10, 'sslverify' => false ) );
        if ( is_wp_error( $response ) ) {
            return 'ERROR: ' . $response->get_error_message();
        }

        return 'HTTP ' . (int) wp_remote_retrieve_response_code( $response );
    }

    /**
     * List of active plugins with versions.
     *
     * @return string[]
     */
    private static function active_plugins() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all    = get_plugins();
        $active = (array) get_option( 'active_plugins', array() );
        if ( is_multisite() ) {
            $active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
        }

        $out = array();
        foreach ( array_unique( $active ) as $file ) {
            $data  = $all[ $file ] ?? null;
            $out[] = $data ? ( $data['Name'] . ' ' . $data['Version'] ) : $file;
        }

        sort( $out, SORT_NATURAL | SORT_FLAG_CASE );

        return $out;
    }
}
