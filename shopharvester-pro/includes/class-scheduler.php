<?php
/**
 * Action Scheduler integration (with graceful WP-Cron fallback).
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * When Action Scheduler is present (it ships with WooCommerce), the queue runs
 * on it — durable storage, automatic retries, concurrency limits and a visible
 * admin screen (WooCommerce → Status → Scheduled Actions). Otherwise the plugin
 * keeps using its own WP-Cron loop.
 */
class Scheduler {

    const GROUP = 'shopharvester';

    /**
     * Is Action Scheduler usable?
     *
     * @return bool
     */
    public static function available() {
        return function_exists( 'as_enqueue_async_action' )
            && function_exists( 'as_has_scheduled_action' )
            && function_exists( 'as_schedule_single_action' );
    }

    /**
     * Queue a job for immediate async processing.
     *
     * @param int $job_id Job ID.
     * @return bool True when handed to Action Scheduler.
     */
    public static function enqueue_job( $job_id ) {
        if ( ! self::available() ) {
            return false;
        }

        $args = array( 'job_id' => (int) $job_id );
        if ( ! as_has_scheduled_action( 'shp_as_process_job', $args, self::GROUP ) ) {
            as_enqueue_async_action( 'shp_as_process_job', $args, self::GROUP );
        }

        return true;
    }

    /**
     * Schedule the next pass of a job that still has work left.
     *
     * @param int $job_id Job ID.
     * @param int $delay  Seconds.
     * @return bool
     */
    public static function continue_job( $job_id, $delay = 5 ) {
        if ( ! self::available() ) {
            return false;
        }

        $args = array( 'job_id' => (int) $job_id );

        // Never stack more than one action per job — this is what prevents a
        // runaway backlog when several triggers race.
        if ( as_has_scheduled_action( 'shp_as_process_job', $args, self::GROUP ) ) {
            return true;
        }

        as_schedule_single_action( time() + max( 1, (int) $delay ), 'shp_as_process_job', $args, self::GROUP );

        return true;
    }

    /**
     * Cancel every pending ShopHarvester job action (backlog reset).
     *
     * @return void
     */
    public static function purge_queue() {
        if ( self::available() && function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'shp_as_process_job', array(), self::GROUP );
        }
    }

    /**
     * Keep the recurring auto-resync action in step with the setting.
     *
     * @param int $hours Interval in hours (0 = off).
     * @return bool
     */
    public static function sync_recurring( $hours ) {
        if ( ! self::available() || ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_unschedule_all_actions' ) ) {
            return false;
        }

        as_unschedule_all_actions( 'shp_as_auto_resync', array(), self::GROUP );

        if ( (int) $hours >= 1 ) {
            as_schedule_recurring_action(
                time() + (int) $hours * HOUR_IN_SECONDS,
                (int) $hours * HOUR_IN_SECONDS,
                'shp_as_auto_resync',
                array(),
                self::GROUP
            );
        }

        return true;
    }

    /**
     * Count pending ShopHarvester actions (for the system report).
     *
     * @return int|null Null when Action Scheduler is unavailable.
     */
    public static function pending_count() {
        if ( ! self::available() ) {
            return null;
        }

        if ( class_exists( '\ActionScheduler_Store' ) ) {
            try {
                return (int) \ActionScheduler_Store::instance()->query_actions(
                    array( 'group' => self::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING ),
                    'count'
                );
            } catch ( \Throwable $e ) {
                // Fall through to the slower path.
            }
        }

        if ( function_exists( 'as_get_scheduled_actions' ) ) {
            $ids = as_get_scheduled_actions(
                array( 'group' => self::GROUP, 'status' => 'pending', 'per_page' => 500 ),
                'ids'
            );
            return is_array( $ids ) ? count( $ids ) : 0;
        }

        return null;
    }
}
