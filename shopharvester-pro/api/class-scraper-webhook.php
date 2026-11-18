<?php
/**
 * REST endpoints for external scraper runners (Playwright / Scrapy / n8n).
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Api;

use ShopHarvesterPro\Includes\Job_Queue;
use ShopHarvesterPro\Includes\Logger;
use ShopHarvesterPro\Includes\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Namespace: shopharvester/v1
 *
 *  POST /ingest        Accept a full store+products payload in one shot.
 *  POST /jobs          Register a target URL to be scraped by a runner.
 *  POST /jobs/{id}/result   Deliver scraped results for a pending job.
 *  GET  /jobs/pending  Runner polls this to pick up work.
 */
class Scraper_Webhook {

    const NS = 'shopharvester/v1';

    /**
     * @var Job_Queue
     */
    private $jobs;

    /**
     * Constructor.
     *
     * @param Job_Queue $jobs Job queue.
     */
    public function __construct( Job_Queue $jobs ) {
        $this->jobs = $jobs;
    }

    /**
     * Register all routes.
     *
     * @return void
     */
    public function register_routes() {
        $auth = array( $this, 'authorize' );

        register_rest_route(
            self::NS,
            '/ingest',
            array(
                'methods'             => 'POST',
                'permission_callback' => $auth,
                'callback'            => array( $this, 'handle_ingest' ),
            )
        );

        register_rest_route(
            self::NS,
            '/jobs',
            array(
                'methods'             => 'POST',
                'permission_callback' => $auth,
                'callback'            => array( $this, 'handle_create_job' ),
            )
        );

        register_rest_route(
            self::NS,
            '/jobs/pending',
            array(
                'methods'             => 'GET',
                'permission_callback' => $auth,
                'callback'            => array( $this, 'handle_pending' ),
            )
        );

        register_rest_route(
            self::NS,
            '/jobs/(?P<id>\d+)/result',
            array(
                'methods'             => 'POST',
                'permission_callback' => $auth,
                'callback'            => array( $this, 'handle_job_result' ),
                'args'                => array(
                    'id' => array( 'validate_callback' => 'is_numeric' ),
                ),
            )
        );
    }

    /**
     * Shared-secret authentication. The runner sends the secret in the
     * `X-ShopHarvester-Secret` header (or `?secret=` for quick tests).
     *
     * @param WP_REST_Request $request Request.
     * @return bool|WP_Error
     */
    public function authorize( WP_REST_Request $request ) {
        $expected = (string) Settings::get( 'webhook_secret' );
        $provided = (string) ( $request->get_header( 'x_shopharvester_secret' ) ?: $request->get_param( 'secret' ) );

        if ( $expected && hash_equals( $expected, $provided ) ) {
            return true;
        }

        // Logged-in administrators may always call the endpoints.
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        return new WP_Error( 'shp_forbidden', __( 'کلید احراز هویت وب‌هوک نامعتبر است.', 'shopharvester-pro' ), array( 'status' => 401 ) );
    }

    /**
     * One-shot ingest: create a job that already carries its payload.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function handle_ingest( WP_REST_Request $request ) {
        $payload = $this->extract_payload( $request );

        if ( empty( $payload['products'] ) && empty( $payload['store'] ) ) {
            return new WP_REST_Response(
                array( 'ok' => false, 'message' => __( 'ساختار داده نامعتبر است: کلیدهای store و products الزامی‌اند.', 'shopharvester-pro' ) ),
                422
            );
        }

        $job_id = $this->jobs->create(
            array(
                'target_url' => $payload['store']['source_url'] ?? '',
                'subdomain'  => $request->get_param( 'subdomain' ),
                'payload'    => $payload,
                'status'     => 'queued',
            )
        );

        Logger::info(
            __( 'محموله اسکرپ از طریق REST دریافت شد.', 'shopharvester-pro' ),
            array( 'products' => count( $payload['products'] ?? array() ) ),
            array( 'job_id' => $job_id, 'channel' => 'webhook' )
        );

        // Kick the queue immediately so results are near real-time.
        $this->jobs->process_one( $this->jobs->get( $job_id ) );

        return new WP_REST_Response( array( 'ok' => true, 'job_id' => $job_id ), 201 );
    }

    /**
     * Register a URL for a runner to scrape later.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function handle_create_job( WP_REST_Request $request ) {
        $url = esc_url_raw( (string) $request->get_param( 'target_url' ) );
        if ( ! $url ) {
            return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'target_url الزامی است.', 'shopharvester-pro' ) ), 422 );
        }

        $job_id = $this->jobs->create(
            array(
                'target_url' => $url,
                'subdomain'  => $request->get_param( 'subdomain' ),
                'status'     => 'queued',
            )
        );

        return new WP_REST_Response( array( 'ok' => true, 'job_id' => $job_id ), 201 );
    }

    /**
     * Runner polls for jobs that still need data.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function handle_pending( WP_REST_Request $request ) {
        update_option( 'shp_last_runner_poll', time(), false );

        $result = $this->jobs->query( array( 'status' => 'awaiting_runner', 'per_page' => 20 ) );
        $extra  = $this->jobs->query( array( 'status' => 'queued', 'per_page' => 20 ) );

        $items = array();
        foreach ( array_merge( $result['items'], $extra['items'] ) as $job ) {
            if ( $job->payload ) {
                continue;
            }
            $items[] = array(
                'id'         => (int) $job->id,
                'target_url' => $job->target_url,
                'subdomain'  => $job->subdomain,
            );
        }

        return new WP_REST_Response( array( 'ok' => true, 'jobs' => $items ), 200 );
    }

    /**
     * Runner delivers scraped results for a specific job.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function handle_job_result( WP_REST_Request $request ) {
        $job_id = (int) $request['id'];
        $job    = $this->jobs->get( $job_id );

        if ( ! $job ) {
            return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'کار یافت نشد.', 'shopharvester-pro' ) ), 404 );
        }

        $payload = $this->extract_payload( $request );
        if ( empty( $payload['products'] ) ) {
            return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'products خالی است.', 'shopharvester-pro' ) ), 422 );
        }

        $this->jobs->attach_payload( $job_id, $payload );
        $stats = $this->jobs->process_one( $this->jobs->get( $job_id ) );

        return new WP_REST_Response( array( 'ok' => true, 'job_id' => $job_id, 'stats' => $stats ), 200 );
    }

    /**
     * Pull and lightly shape the JSON body.
     *
     * @param WP_REST_Request $request Request.
     * @return array
     */
    private function extract_payload( WP_REST_Request $request ) {
        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            $body = $request->get_params();
        }

        return array(
            'store'    => isset( $body['store'] ) && is_array( $body['store'] ) ? $body['store'] : array(),
            'products' => isset( $body['products'] ) && is_array( $body['products'] ) ? $body['products'] : array(),
        );
    }
}
