<?php
/**
 * Persistent job queue for scrape / import runs.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * CRUD + processing for the `shp_jobs` table. Jobs are created by the admin
 * runner or the REST webhook and consumed by cron / manual triggers.
 */
class Job_Queue {

    const MAX_ATTEMPTS = 3;

    /**
     * Create a new job.
     *
     * @param array $args { target_url, subdomain, store_id, payload, status }.
     * @return int Job ID.
     */
    public function create( array $args ) {
        global $wpdb;

        $target_url = esc_url_raw( $args['target_url'] ?? '' );

        // De-duplicate URL-only jobs: if the same URL is already active or
        // parked, reuse it (and un-park an awaiting_runner job) instead of
        // stacking identical rows.
        if ( ! isset( $args['payload'] ) && '' !== $target_url ) {
            $existing = $wpdb->get_row( // phpcs:ignore
                $wpdb->prepare(
                    "SELECT id, status FROM {$wpdb->prefix}shp_jobs
                     WHERE target_url = %s AND (payload IS NULL OR payload = '')
                       AND status IN ('queued','retry','processing','awaiting_runner')
                     ORDER BY id DESC LIMIT 1",
                    $target_url
                )
            );

            if ( $existing ) {
                if ( 'awaiting_runner' === $existing->status ) {
                    $this->update( (int) $existing->id, array( 'status' => 'queued', 'attempts' => 0, 'stats' => null, 'last_error' => null ) );
                    Scheduler::enqueue_job( (int) $existing->id );
                }
                Logger::info(
                    sprintf( __( 'کار موجود #%d برای این آدرس استفاده شد.', 'shopharvester-pro' ), $existing->id ),
                    array( 'target_url' => $target_url ),
                    array( 'job_id' => (int) $existing->id, 'channel' => 'queue', 'source_url' => $target_url )
                );
                return (int) $existing->id;
            }
        }

        $now = current_time( 'mysql' );
        $row = array(
            'created_at' => $now,
            'updated_at' => $now,
            'status'     => in_array( $args['status'] ?? '', array( 'queued', 'received' ), true ) ? $args['status'] : 'queued',
            'target_url' => $target_url,
            'subdomain'  => sanitize_text_field( $args['subdomain'] ?? '' ),
            'store_id'   => isset( $args['store_id'] ) ? (int) $args['store_id'] : null,
            'created_by' => isset( $args['created_by'] ) ? (int) $args['created_by'] : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 ),
            'payload'    => isset( $args['payload'] ) ? wp_json_encode( $args['payload'], JSON_UNESCAPED_UNICODE ) : null,
            'attempts'   => 0,
            'stats'      => null,
            'last_error' => null,
        );

        $inserted = $wpdb->insert( $wpdb->prefix . 'shp_jobs', $row ); // phpcs:ignore

        // Older installs may not have the created_by column yet (added in
        // DB 1.2.0). Retry once without it rather than failing the job.
        if ( false === $inserted && false !== stripos( (string) $wpdb->last_error, 'created_by' ) ) {
            unset( $row['created_by'] );
            $inserted = $wpdb->insert( $wpdb->prefix . 'shp_jobs', $row ); // phpcs:ignore
        }

        $job_id = (int) $wpdb->insert_id;

        if ( false === $inserted || ! $job_id ) {
            update_option( 'shp_db_broken', current_time( 'mysql' ) );
            Logger::error(
                __( 'ثبت کار در دیتابیس ناموفق بود — احتمالاً جدول‌های افزونه وجود ندارند. افزونه را دوباره فعال کنید.', 'shopharvester-pro' ),
                array( 'db_error' => $wpdb->last_error ),
                array( 'channel' => 'queue', 'source_url' => $target_url )
            );
            return 0;
        }

        Logger::info(
            sprintf( __( 'کار جدید در صف قرار گرفت (#%d).', 'shopharvester-pro' ), $job_id ),
            array( 'target_url' => $row['target_url'], 'has_payload' => (bool) $row['payload'] ),
            array( 'job_id' => $job_id, 'channel' => 'queue', 'source_url' => $row['target_url'] )
        );

        Scheduler::enqueue_job( $job_id );

        return $job_id;
    }

    /**
     * Keep Action Scheduler in sync with the job table: the DB is the source of
     * truth. Cancels the whole `shp_as_process_job` backlog and re-enqueues
     * exactly one action per pending job. Throttled, and a no-op without AS.
     *
     * @return void
     */
    public function reconcile_scheduler() {
        if ( ! Scheduler::available() || get_transient( 'shp_as_reconcile' ) ) {
            return;
        }
        set_transient( 'shp_as_reconcile', 1, 5 * MINUTE_IN_SECONDS );

        global $wpdb;
        $db_pending = (int) $wpdb->get_var( // phpcs:ignore
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}shp_jobs WHERE status IN ('queued','retry') AND attempts < %d",
                self::MAX_ATTEMPTS
            )
        );
        $ids = $wpdb->get_col( // phpcs:ignore
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}shp_jobs WHERE status IN ('queued','retry') AND attempts < %d ORDER BY id ASC LIMIT 50",
                self::MAX_ATTEMPTS
            )
        );

        // Runaway detection: far more scheduled actions than pending jobs.
        $as_pending = Scheduler::pending_count();
        if ( null !== $as_pending && $as_pending > max( 10, $db_pending * 2 + 5 ) ) {
            Scheduler::purge_queue();
            Logger::warning(
                sprintf( __( 'صف Action Scheduler بازنشانی شد (%d اکشن انباشته).', 'shopharvester-pro' ), $as_pending ),
                array(),
                array( 'channel' => 'queue' )
            );
        }

        foreach ( (array) $ids as $id ) {
            Scheduler::enqueue_job( (int) $id );
        }
    }

    /**
     * Action Scheduler callback: process one job, then reschedule itself if the
     * job still has unfinished batches.
     *
     * @param int $job_id Job ID.
     * @return void
     */
    public function process_job_by_id( $job_id ) {
        $job = $this->get( (int) $job_id );
        if ( ! $job || ! in_array( $job->status, array( 'queued', 'retry', 'received', 'processing' ), true ) ) {
            return;
        }

        $this->process_one( $job );

        $fresh = $this->get( (int) $job_id );
        if ( $fresh && in_array( $fresh->status, array( 'queued', 'retry' ), true ) && (int) $fresh->attempts < self::MAX_ATTEMPTS ) {
            Scheduler::continue_job( $job_id, 5 );
        }
    }

    /**
     * Ask WP-Cron to run the queue as soon as possible, without blocking the
     * current request.
     *
     * @return void
     */
    public function schedule_soon() {
        if ( Scheduler::available() ) {
            global $wpdb;
            $ids = $wpdb->get_col( // phpcs:ignore
                $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}shp_jobs WHERE status IN ('queued','retry') AND attempts < %d ORDER BY id ASC LIMIT 50",
                    self::MAX_ATTEMPTS
                )
            );
            foreach ( (array) $ids as $id ) {
                Scheduler::enqueue_job( (int) $id );
            }
            return;
        }

        if ( ! wp_next_scheduled( 'shp_process_job_queue' ) || wp_next_scheduled( 'shp_process_job_queue' ) > time() + 30 ) {
            wp_schedule_single_event( time() + 1, 'shp_process_job_queue' );
        }

        if ( function_exists( 'spawn_cron' ) ) {
            spawn_cron();
        }
    }

    /**
     * Fetch a job row.
     *
     * @param int $job_id Job ID.
     * @return object|null
     */
    public function get( $job_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}shp_jobs WHERE id = %d", $job_id ) ); // phpcs:ignore
    }

    /**
     * Update arbitrary columns on a job.
     *
     * @param int   $job_id Job ID.
     * @param array $data   Column => value.
     * @return void
     */
    private function update( $job_id, array $data ) {
        global $wpdb;
        $data['updated_at'] = current_time( 'mysql' );
        $wpdb->update( $wpdb->prefix . 'shp_jobs', $data, array( 'id' => $job_id ) ); // phpcs:ignore
    }

    /**
     * Attach a scraped payload to an existing job (used by the webhook when
     * the runner posts results back asynchronously).
     *
     * @param int   $job_id  Job ID.
     * @param array $payload Payload.
     * @return void
     */
    public function attach_payload( $job_id, array $payload ) {
        $this->update(
            $job_id,
            array(
                'payload' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
                'status'  => 'queued',
            )
        );
    }

    /**
     * Process every job that is due to run.
     *
     * @return void
     */
    public function process_due() {
        global $wpdb;

        // Recover jobs abandoned mid-run (PHP timeout, fatal, restart). Reset
        // the attempt counter so a heavy job is not permanently stalled.
        $wpdb->query( // phpcs:ignore
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}shp_jobs SET status = 'retry', attempts = 0
                 WHERE status = 'processing' AND updated_at < %s",
                gmdate( 'Y-m-d H:i:s', time() - 3 * MINUTE_IN_SECONDS )
            )
        );

        $jobs = $wpdb->get_results( // phpcs:ignore
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}shp_jobs
                 WHERE status IN ('queued','retry') AND attempts < %d
                 ORDER BY id ASC LIMIT 3",
                self::MAX_ATTEMPTS
            )
        );

        foreach ( (array) $jobs as $job ) {
            $this->process_one( $job );
        }

        // If work remains, line up another pass.
        $pending = (int) $wpdb->get_var( // phpcs:ignore
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}shp_jobs WHERE status IN ('queued','retry') AND attempts < %d",
                self::MAX_ATTEMPTS
            )
        );

        if ( $pending > 0 && ! wp_next_scheduled( 'shp_process_job_queue' ) ) {
            wp_schedule_single_event( time() + 20, 'shp_process_job_queue' );
        }
    }

    /**
     * Fallback queue runner for hosts where WP-Cron is disabled or its loopback
     * request is blocked: process pending jobs while an operator is on one of
     * the plugin's admin screens. A short-lived lock prevents overlap.
     *
     * @return void
     */
    public function maybe_process_on_admin() {
        global $wpdb;

        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if ( 0 !== strpos( $page, 'shopharvester' ) ) {
            return;
        }

        // Recover jobs abandoned mid-run, then hand every pending job to a
        // background runner. This NEVER processes inline — admin pages must
        // render immediately.
        if ( get_transient( 'shp_orphan_check' ) ) {
            return;
        }
        set_transient( 'shp_orphan_check', 1, 90 );

        $wpdb->query( // phpcs:ignore
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}shp_jobs SET status = 'retry', attempts = 0
                 WHERE status = 'processing' AND updated_at < %s",
                gmdate( 'Y-m-d H:i:s', time() - 3 * MINUTE_IN_SECONDS )
            )
        );

        $ids = $wpdb->get_col( // phpcs:ignore
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}shp_jobs WHERE status IN ('queued','retry') AND attempts < %d ORDER BY id ASC LIMIT 50",
                self::MAX_ATTEMPTS
            )
        );

        if ( empty( $ids ) ) {
            return;
        }

        if ( Scheduler::available() ) {
            foreach ( $ids as $id ) {
                Scheduler::enqueue_job( (int) $id );
            }
            return;
        }

        // No Action Scheduler: make sure WP-Cron is scheduled and kick it.
        // Processing happens in the background — never inline, so the admin
        // page renders instantly. Use "پردازش صف اکنون" for an on-demand run.
        if ( ! wp_next_scheduled( 'shp_process_job_queue' ) ) {
            wp_schedule_event( time() + 30, 'shp_every_minute', 'shp_process_job_queue' );
        }
        $this->schedule_soon();
    }

    /**
     * Keep the automatic re-sync cron event in step with the setting.
     *
     * @return void
     */
    public function maybe_schedule_resync() {
        $hours     = (int) Settings::get( 'auto_resync_hours', 0 );
        $scheduled = (int) get_option( 'shp_resync_scheduled_hours', -1 );

        if ( $hours === $scheduled && ( $hours < 1 || wp_next_scheduled( 'shp_auto_resync' ) ) ) {
            return;
        }

        $existing = wp_next_scheduled( 'shp_auto_resync' );
        if ( $existing ) {
            wp_unschedule_event( $existing, 'shp_auto_resync' );
        }

        if ( Scheduler::available() ) {
            Scheduler::sync_recurring( $hours );
        } elseif ( $hours >= 1 ) {
            wp_schedule_event( time() + $hours * HOUR_IN_SECONDS, 'shp_resync_' . $hours . 'h', 'shp_auto_resync' );
        }

        update_option( 'shp_resync_scheduled_hours', $hours, false );
    }

    /**
     * Cron handler: re-queue every distinct source URL that has completed at
     * least once, so products are re-harvested and refreshed.
     *
     * @return void
     */
    public function auto_resync() {
        global $wpdb;

        $urls = $wpdb->get_col( // phpcs:ignore
            "SELECT DISTINCT target_url FROM {$wpdb->prefix}shp_jobs WHERE status = 'completed' AND target_url <> ''"
        );

        $queued = 0;
        foreach ( (array) $urls as $url ) {
            $active = (int) $wpdb->get_var( // phpcs:ignore
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}shp_jobs WHERE target_url = %s AND status IN ('queued','processing','retry')",
                    $url
                )
            );
            if ( $active ) {
                continue;
            }

            $this->create( array( 'target_url' => $url, 'status' => 'queued' ) );
            $queued++;
        }

        if ( $queued ) {
            Logger::info(
                sprintf( __( 'همگام‌سازی خودکار: %d آدرس دوباره در صف قرار گرفت.', 'shopharvester-pro' ), $queued ),
                array(),
                array( 'channel' => 'queue' )
            );
            $this->schedule_soon();
        }
    }

    /**
     * Public wrapper so the admin can force a queue pass on demand.
     *
     * @return array { pending: int, processed_hint: string }
     */
    public function run_now() {
        global $wpdb;

        delete_transient( 'shp_queue_lock' );
        delete_transient( 'shp_orphan_check' );

        // Reset any runaway Action Scheduler backlog, recover stale jobs, then
        // process a bounded batch inline for immediate feedback.
        Scheduler::purge_queue();

        $wpdb->query( // phpcs:ignore
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}shp_jobs SET status = 'retry', attempts = 0
                 WHERE status = 'processing' AND updated_at < %s",
                gmdate( 'Y-m-d H:i:s', time() - 2 * MINUTE_IN_SECONDS )
            )
        );

        $this->process_due();

        // Hand whatever is left to the background runner cleanly.
        $ids = $wpdb->get_col( // phpcs:ignore
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}shp_jobs WHERE status IN ('queued','retry') AND attempts < %d ORDER BY id ASC LIMIT 50",
                self::MAX_ATTEMPTS
            )
        );
        foreach ( (array) $ids as $id ) {
            Scheduler::enqueue_job( (int) $id );
        }

        $pending = (int) $wpdb->get_var( // phpcs:ignore
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}shp_jobs WHERE status IN ('queued','retry','processing') AND attempts < %d",
                self::MAX_ATTEMPTS
            )
        );

        return array( 'pending' => $pending );
    }

    /**
     * Process a single job row.
     *
     * @param object $job Job row.
     * @return array Stats.
     */
    public function process_one( $job ) {
        // Per-job lock: several triggers (Action Scheduler, WP-Cron, the manual
        // button) can fire for the same job at once — only one may run it.
        $lock_key = 'shp_job_' . (int) $job->id;
        if ( get_transient( $lock_key ) ) {
            return array();
        }
        set_transient( $lock_key, time(), 10 * MINUTE_IN_SECONDS );

        try {
            return $this->run_job( $job );
        } finally {
            delete_transient( $lock_key );
        }
    }

    /**
     * The actual per-job work (always called under the lock in process_one()).
     *
     * @param object $job Job row.
     * @return array
     */
    private function run_job( $job ) {
        $this->update( $job->id, array( 'status' => 'processing', 'attempts' => (int) $job->attempts + 1 ) );

        $payload = json_decode( (string) $job->payload, true );

        // No payload yet: try the built-in PHP scraper, then fall back to an
        // external headless runner.
        if ( ! is_array( $payload ) || empty( $payload['products'] ) ) {

            if ( Settings::get( 'builtin_scraper_enabled' ) && ! empty( $job->target_url ) ) {
                try {
                    // 1) WooCommerce Store API — structured, reliable.
                    $payload = ( new Woo_Store_Api() )->harvest( $job->target_url );
                    $source  = 'woo-store-api';

                    // 2) Shopify storefront JSON feed.
                    if ( ! is_array( $payload ) || empty( $payload['products'] ) ) {
                        $payload = ( new Shopify_Api() )->harvest( $job->target_url );
                        $source  = 'shopify';
                    }

                    // 3) Generic HTML scraping (JSON-LD / OG / microdata / sitemap).
                    if ( ! is_array( $payload ) || empty( $payload['products'] ) ) {
                        $payload = ( new Http_Scraper() )->scrape( $job->target_url );
                        $source  = 'html';
                    }

                    // 4) JavaScript rendering API — for SPA shops, no external
                    // worker needed.
                    if ( ( ! is_array( $payload ) || empty( $payload['products'] ) ) && Http_Client::has_js_render() ) {
                        $payload = ( new Http_Scraper() )->scrape( $job->target_url, true );
                        $source  = 'js-render';
                    }

                    Logger::info(
                        sprintf(
                            __( 'واکشی‌کننده داخلی (%1$s) %2$d محصول از کار #%3$d استخراج کرد.', 'shopharvester-pro' ),
                            $source,
                            is_array( $payload ) ? count( $payload['products'] ) : 0,
                            $job->id
                        ),
                        array( 'target_url' => $job->target_url ),
                        array( 'job_id' => $job->id, 'channel' => 'queue', 'source_url' => $job->target_url )
                    );

                    if ( is_array( $payload ) ) {
                        $this->update( $job->id, array( 'payload' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) ) );
                    }
                } catch ( \Throwable $e ) {
                    $msg = $e->getMessage();
                    // A source-site block / timeout is not a plugin error — log
                    // it as a warning with guidance and let the runner fallback
                    // take over, instead of a scary stack trace.
                    if ( preg_match( '/^fetch_(status_(4|5)\d\d|error|empty_body)/', $msg ) ) {
                        $code = '';
                        if ( preg_match( '/fetch_status_(\d+)/', $msg, $m ) ) {
                            $code = $m[1];
                        }
                        Logger::warning(
                            sprintf(
                                /* translators: %s: HTTP status or reason */
                                __( 'سایت مبدأ فعلاً پاسخ نمی‌دهد (%s). چند دقیقه‌ی دیگر همین آدرس را دوباره اجرا کنید؛ اگر تکرار شد یعنی سایت مبدأ ربات‌ها را محدود کرده است.', 'shopharvester-pro' ),
                                '' !== $code ? 'HTTP ' . $code : $msg
                            ),
                            array( 'target_url' => $job->target_url ),
                            array( 'job_id' => $job->id, 'channel' => 'queue', 'source_url' => $job->target_url )
                        );
                    } else {
                        Logger::exception( $e, array( 'job_id' => $job->id, 'channel' => 'queue' ) );
                    }
                    $payload = null;
                }
            }

            if ( ! is_array( $payload ) || empty( $payload['products'] ) ) {
                if ( Http_Client::has_js_render() ) {
                    $hint = __( 'حتی با سرویس رندر جاوااسکریپت هم محصولی پیدا نشد. ممکن است آدرس اشتباه باشد یا ساختار سایت با سلکتورهای عمومی نخواند.', 'shopharvester-pro' );
                } elseif ( ! (int) get_option( 'shp_last_runner_poll', 0 ) ) {
                    $hint = __( 'سایت با جاوااسکریپت رندر می‌شود. برای برداشت خودکار آن، در تنظیمات «رندر جاوااسکریپت» را با یک سرویس فعال کنید (یا اجراکننده Playwright را راه بیندازید).', 'shopharvester-pro' );
                } else {
                    $hint = __( 'واکشی‌کننده داخلی محصولی پیدا نکرد (احتمالاً سایت وابسته به جاوااسکریپت است)؛ در انتظار اجراکننده هدلس.', 'shopharvester-pro' );
                }
                $never_polled = ! (int) get_option( 'shp_last_runner_poll', 0 );

                $this->update(
                    $job->id,
                    array( 'status' => 'awaiting_runner', 'last_error' => $hint )
                );
                Logger::warning(
                    sprintf( __( 'کار #%d داده‌ای ندارد و منتظر اجراکننده بیرونی است.', 'shopharvester-pro' ), $job->id ),
                    array( 'target_url' => $job->target_url, 'runner_ever_connected' => ! $never_polled ),
                    array( 'job_id' => $job->id, 'channel' => 'queue' )
                );
                return array();
            }
        }

        if ( empty( $payload['store']['source_url'] ) ) {
            $payload['store']['source_url'] = $job->target_url;
        }

        try {
            $importer = new Importer();

            $prev   = json_decode( (string) $job->stats, true );
            $prev   = is_array( $prev ) ? $prev : array();
            $cursor = (int) ( $prev['cursor'] ?? 0 );

            $batch_size = max( 1, (int) apply_filters( 'shp_import_batch_size', 5 ) );
            $stats      = $importer->run( $payload, (int) $job->id, $cursor, $batch_size );

            $agg = array(
                'store_id' => $stats['store_id'] ?: (int) ( $prev['store_id'] ?? 0 ),
                'found'    => (int) $stats['found'],
                'imported' => (int) ( $prev['imported'] ?? 0 ) + (int) $stats['imported'],
                'updated'  => (int) ( $prev['updated'] ?? 0 ) + (int) $stats['updated'],
                'skipped'  => (int) ( $prev['skipped'] ?? 0 ) + (int) $stats['skipped'],
                'failed'   => (int) ( $prev['failed'] ?? 0 ) + (int) $stats['failed'],
                'cursor'   => $cursor + (int) $stats['processed'],
            );

            // More products remain: keep the job queued and continue next pass.
            if ( empty( $stats['done'] ) ) {
                $this->update(
                    $job->id,
                    array(
                        'status'     => 'queued',
                        'attempts'   => 0,
                        'store_id'   => $agg['store_id'] ?: null,
                        'stats'      => wp_json_encode( $agg, JSON_UNESCAPED_UNICODE ),
                        'last_error' => sprintf( __( 'در حال پردازش: %1$d از %2$d محصول.', 'shopharvester-pro' ), $agg['cursor'], $agg['found'] ),
                    )
                );
                $this->schedule_soon();
                return $agg;
            }

            $this->update(
                $job->id,
                array(
                    'status'     => 'completed',
                    'store_id'   => $agg['store_id'] ?: null,
                    'stats'      => wp_json_encode( $agg, JSON_UNESCAPED_UNICODE ),
                    'last_error' => null,
                )
            );

            Logger::success(
                sprintf(
                    __( 'کار #%1$d کامل شد: %2$d جدید، %3$d بروزرسانی، %4$d تکراری، %5$d ناموفق.', 'shopharvester-pro' ),
                    $job->id,
                    $agg['imported'],
                    $agg['updated'],
                    $agg['skipped'],
                    $agg['failed']
                ),
                $agg,
                array( 'job_id' => $job->id, 'channel' => 'queue' )
            );

            return $agg;
        } catch ( \Throwable $e ) {
            Logger::exception( $e, array( 'job_id' => $job->id, 'channel' => 'queue' ) );

            $status = ( (int) $job->attempts + 1 ) >= self::MAX_ATTEMPTS ? 'failed' : 'retry';
            $this->update(
                $job->id,
                array(
                    'status'     => $status,
                    'last_error' => $e->getMessage() . "\n" . $e->getTraceAsString(),
                )
            );

            return array();
        }
    }

    /**
     * Cancel a job: stop any scheduled action, release its lock and mark it
     * cancelled so no runner picks it up again.
     *
     * @param int $job_id Job ID.
     * @return bool
     */
    public function cancel( $job_id ) {
        $job = $this->get( (int) $job_id );
        if ( ! $job ) {
            return false;
        }

        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'shp_as_process_job', array( 'job_id' => (int) $job_id ), Scheduler::GROUP );
        }
        delete_transient( 'shp_job_' . (int) $job_id );

        $this->update(
            (int) $job_id,
            array( 'status' => 'cancelled', 'last_error' => __( 'توسط کاربر لغو شد.', 'shopharvester-pro' ) )
        );

        Logger::info(
            sprintf( __( 'کار #%d لغو شد.', 'shopharvester-pro' ), (int) $job_id ),
            array(),
            array( 'job_id' => (int) $job_id, 'channel' => 'queue' )
        );

        return true;
    }

    /**
     * Re-queue a failed job.
     *
     * @param int $job_id Job ID.
     * @return bool
     */
    public function retry( $job_id ) {
        $job = $this->get( $job_id );
        if ( ! $job ) {
            return false;
        }

        $this->update( $job_id, array( 'status' => 'queued', 'attempts' => 0, 'stats' => null, 'last_error' => null ) );
        Logger::info(
            sprintf( __( 'کار #%d برای اجرای مجدد در صف قرار گرفت.', 'shopharvester-pro' ), $job_id ),
            array(),
            array( 'job_id' => $job_id, 'channel' => 'queue' )
        );

        return true;
    }

    /**
     * Paginated job listing for the admin table.
     *
     * @param array $args { status, paged, per_page }.
     * @return array { items, total }
     */
    public function query( array $args = array() ) {
        global $wpdb;

        $args = wp_parse_args( $args, array( 'status' => '', 'paged' => 1, 'per_page' => 20 ) );
        $table = $wpdb->prefix . 'shp_jobs';

        $where  = '1=1';
        $params = array();
        if ( $args['status'] ) {
            $where   .= ' AND status = %s';
            $params[] = $args['status'];
        }

        $per_page = max( 1, (int) $args['per_page'] );
        $offset   = ( max( 1, (int) $args['paged'] ) - 1 ) * $per_page;

        $total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ) : "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore

        $items = $wpdb->get_results( // phpcs:ignore
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
                array_merge( $params, array( $per_page, $offset ) )
            )
        );

        return array( 'items' => $items ?: array(), 'total' => $total );
    }

    /**
     * Aggregate counters for the dashboard cards.
     *
     * @return array
     */
    public function dashboard_stats() {
        global $wpdb;

        $jobs_table = $wpdb->prefix . 'shp_jobs';
        $rows       = $wpdb->get_results( "SELECT status, COUNT(*) c FROM {$jobs_table} GROUP BY status", OBJECT_K ); // phpcs:ignore

        $by_status = array();
        foreach ( (array) $rows as $status => $row ) {
            $by_status[ $status ] = (int) $row->c;
        }

        $products = wp_count_posts( Entity_Manager::product_post_type() );
        $stores   = wp_count_posts( Entity_Manager::STORE_CPT );

        // Logger stores created_at in site-local time (current_time), so the
        // 24h cutoff must be computed in the same basis — not UTC.
        $cutoff     = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - DAY_IN_SECONDS );
        $errors_24h = (int) $wpdb->get_var( // phpcs:ignore
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}shp_logs WHERE level = 'ERROR' AND created_at > %s",
                $cutoff
            )
        );

        return array(
            'jobs_by_status'  => $by_status,
            'jobs_total'      => array_sum( $by_status ),
            'products_draft'  => (int) ( $products->draft ?? 0 ),
            'products_total'  => array_sum( (array) $products ),
            'stores_total'    => (int) ( $stores->publish ?? 0 ),
            'errors_24h'      => $errors_24h,
        );
    }
}
