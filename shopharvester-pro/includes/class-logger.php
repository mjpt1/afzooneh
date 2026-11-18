<?php
/**
 * Database-driven logger with file fallback.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Structured logging for every stage of the harvesting pipeline.
 */
class Logger {

    const INFO    = 'INFO';
    const SUCCESS = 'SUCCESS';
    const WARNING = 'WARNING';
    const ERROR   = 'ERROR';

    /**
     * Write a log record.
     *
     * @param string $level      One of the level constants.
     * @param string $message    Human readable message (already localized).
     * @param array  $context    Arbitrary structured context.
     * @param array  $meta       Optional { job_id, channel, source_url }.
     * @return int|false Inserted row id or false on failure.
     */
    public static function log( $level, $message, array $context = array(), array $meta = array() ) {
        global $wpdb;

        $level = strtoupper( $level );
        if ( ! in_array( $level, array( self::INFO, self::SUCCESS, self::WARNING, self::ERROR ), true ) ) {
            $level = self::INFO;
        }

        $row = array(
            'created_at' => current_time( 'mysql' ),
            'level'      => $level,
            'channel'    => isset( $meta['channel'] ) ? substr( (string) $meta['channel'], 0, 60 ) : 'general',
            'message'    => wp_strip_all_tags( (string) $message ),
            'context'    => $context ? wp_json_encode( $context, JSON_UNESCAPED_UNICODE ) : null,
            'job_id'     => isset( $meta['job_id'] ) ? (int) $meta['job_id'] : null,
            'source_url' => isset( $meta['source_url'] ) ? esc_url_raw( $meta['source_url'] ) : null,
        );

        $inserted = $wpdb->insert( $wpdb->prefix . 'shp_logs', $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        if ( false === $inserted && Settings::get( 'log_to_file_fallback' ) ) {
            self::file_fallback( $row );
            return false;
        }

        return $wpdb->insert_id;
    }

    /** Convenience wrappers. */
    public static function info( $message, $context = array(), $meta = array() ) {
        return self::log( self::INFO, $message, $context, $meta );
    }
    public static function success( $message, $context = array(), $meta = array() ) {
        return self::log( self::SUCCESS, $message, $context, $meta );
    }
    public static function warning( $message, $context = array(), $meta = array() ) {
        return self::log( self::WARNING, $message, $context, $meta );
    }
    public static function error( $message, $context = array(), $meta = array() ) {
        return self::log( self::ERROR, $message, $context, $meta );
    }

    /**
     * Record an exception with its full stack trace.
     *
     * @param \Throwable $e    Exception.
     * @param array      $meta Log meta.
     * @return void
     */
    public static function exception( \Throwable $e, array $meta = array() ) {
        self::log(
            self::ERROR,
            sprintf( '%s: %s', get_class( $e ), $e->getMessage() ),
            array(
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ),
            $meta
        );
    }

    /**
     * Append a record to the fallback log file.
     *
     * @param array $row Row data.
     * @return void
     */
    private static function file_fallback( array $row ) {
        $uploads = wp_upload_dir();
        $dir     = trailingslashit( $uploads['basedir'] ) . 'shopharvester-logs';

        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
            file_put_contents( $dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore
            file_put_contents( $dir . '/index.php', "<?php // Silence is golden." ); // phpcs:ignore
        }

        $line = sprintf(
            "[%s] %s %s %s %s\n",
            $row['created_at'],
            $row['level'],
            $row['channel'],
            $row['message'],
            $row['context'] ?: ''
        );

        file_put_contents( $dir . '/fallback-' . gmdate( 'Y-m-d' ) . '.log', $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore
    }

    /**
     * Query log rows for the admin table.
     *
     * @param array $args Filters { level, channel, job_id, search, paged, per_page }.
     * @return array { items: array, total: int }
     */
    public static function query( array $args = array() ) {
        global $wpdb;

        $args = wp_parse_args(
            $args,
            array(
                'level'    => '',
                'channel'  => '',
                'job_id'   => 0,
                'search'   => '',
                'paged'    => 1,
                'per_page' => 25,
            )
        );

        $table  = $wpdb->prefix . 'shp_logs';
        $where  = array( '1=1' );
        $params = array();

        if ( $args['level'] ) {
            $where[]  = 'level = %s';
            $params[] = strtoupper( $args['level'] );
        }
        if ( $args['channel'] ) {
            $where[]  = 'channel = %s';
            $params[] = $args['channel'];
        }
        if ( $args['job_id'] ) {
            $where[]  = 'job_id = %d';
            $params[] = (int) $args['job_id'];
        }
        if ( '' !== $args['search'] ) {
            $where[]  = '(message LIKE %s OR source_url LIKE %s)';
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where );
        $per_page  = max( 1, (int) $args['per_page'] );
        $offset    = ( max( 1, (int) $args['paged'] ) - 1 ) * $per_page;

        $total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $total_sql, $params ) : $total_sql ); // phpcs:ignore

        $list_sql          = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
        $list_params       = array_merge( $params, array( $per_page, $offset ) );
        $items             = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) ); // phpcs:ignore

        return array(
            'items' => $items ?: array(),
            'total' => $total,
        );
    }

    /**
     * Delete log rows older than the retention window.
     *
     * @return int Rows deleted.
     */
    public static function prune() {
        global $wpdb;

        $days = (int) Settings::get( 'log_retention_days', 30 );
        $cut  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

        return (int) $wpdb->query( // phpcs:ignore
            $wpdb->prepare( "DELETE FROM {$wpdb->prefix}shp_logs WHERE created_at < %s", $cut )
        );
    }

    /**
     * Build a CSV export of the current filter selection.
     *
     * @param array $args Same filters as query().
     * @return string CSV content.
     */
    public static function export_csv( array $args = array() ) {
        $args['per_page'] = 100000;
        $args['paged']    = 1;
        $result           = self::query( $args );

        $fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore
        fputcsv( $fh, array( 'id', 'created_at', 'level', 'channel', 'message', 'source_url', 'job_id', 'context' ) );

        foreach ( $result['items'] as $row ) {
            fputcsv(
                $fh,
                array( $row->id, $row->created_at, $row->level, $row->channel, $row->message, $row->source_url, $row->job_id, $row->context )
            );
        }

        rewind( $fh );
        $csv = stream_get_contents( $fh );
        fclose( $fh ); // phpcs:ignore

        return $csv;
    }
}
