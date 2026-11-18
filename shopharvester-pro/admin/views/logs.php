<?php
/**
 * Logs view — live filterable operational log.
 *
 * @package ShopHarvesterPro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$preset_job   = isset( $_GET['job_id'] ) ? (int) $_GET['job_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
$preset_level = isset( $_GET['level'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['level'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$levels       = array( 'INFO', 'SUCCESS', 'WARNING', 'ERROR' );
if ( ! in_array( $preset_level, $levels, true ) ) {
    $preset_level = '';
}
$export_url = wp_nonce_url( admin_url( 'admin-ajax.php?action=shp_export_logs' ), \ShopHarvesterPro\Admin\Admin_Menu::NONCE, 'nonce' );
$json_url   = add_query_arg( array( 'action' => 'shp_download_logs_json', 'nonce' => $nonce ), admin_url( 'admin-ajax.php' ) );
?>
<div class="shp-panel">
    <div class="shp-logs-toolbar">
        <select id="shp-log-level">
            <option value=""><?php esc_html_e( 'همه سطوح', 'shopharvester-pro' ); ?></option>
            <?php foreach ( $levels as $lvl ) : ?>
                <option value="<?php echo esc_attr( $lvl ); ?>" <?php selected( $preset_level, $lvl ); ?>><?php echo esc_html( $lvl ); ?></option>
            <?php endforeach; ?>
        </select>

        <select id="shp-log-channel">
            <option value=""><?php esc_html_e( 'همه کانال‌ها', 'shopharvester-pro' ); ?></option>
            <?php foreach ( array( 'general', 'queue', 'webhook', 'import', 'entities', 'dedup', 'ai', 'media', 'yoast', 'settings' ) as $ch ) : ?>
                <option value="<?php echo esc_attr( $ch ); ?>"><?php echo esc_html( $ch ); ?></option>
            <?php endforeach; ?>
        </select>

        <input type="search" id="shp-log-search" placeholder="<?php esc_attr_e( 'جستجو در پیام یا URL…', 'shopharvester-pro' ); ?>" />
        <input type="number" id="shp-log-job" placeholder="Job ID" value="<?php echo $preset_job ? (int) $preset_job : ''; ?>" min="0" />

        <button class="shp-btn shp-btn--small" id="shp-log-refresh"><?php esc_html_e( 'بروزرسانی', 'shopharvester-pro' ); ?></button>
        <label class="shp-inline-check"><input type="checkbox" id="shp-log-auto" /> <?php esc_html_e( 'بروزرسانی خودکار', 'shopharvester-pro' ); ?></label>
        <a class="shp-btn shp-btn--small shp-btn--ghost" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'خروجی CSV', 'shopharvester-pro' ); ?></a>
        <a class="shp-btn shp-btn--small shp-btn--ghost" href="<?php echo esc_url( $json_url ); ?>"><?php esc_html_e( 'خروجی JSON', 'shopharvester-pro' ); ?></a>
        <button class="shp-btn shp-btn--small shp-btn--primary" id="shp-run-queue" data-standalone="1"><?php esc_html_e( 'پردازش صف اکنون', 'shopharvester-pro' ); ?></button>
        <span class="shp-form-status" id="shp-report-status"></span>
    </div>

    <table class="shp-table shp-logs-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'زمان', 'shopharvester-pro' ); ?></th>
                <th><?php esc_html_e( 'سطح', 'shopharvester-pro' ); ?></th>
                <th><?php esc_html_e( 'کانال', 'shopharvester-pro' ); ?></th>
                <th><?php esc_html_e( 'پیام', 'shopharvester-pro' ); ?></th>
                <th><?php esc_html_e( 'جزئیات', 'shopharvester-pro' ); ?></th>
            </tr>
        </thead>
        <tbody id="shp-logs-body">
            <tr><td colspan="5" class="shp-muted"><?php esc_html_e( 'در حال بارگذاری…', 'shopharvester-pro' ); ?></td></tr>
        </tbody>
    </table>

    <div class="shp-pagination">
        <button class="shp-btn shp-btn--small" id="shp-log-prev"><?php esc_html_e( 'قبلی', 'shopharvester-pro' ); ?></button>
        <span id="shp-log-pageinfo">1</span>
        <button class="shp-btn shp-btn--small" id="shp-log-next"><?php esc_html_e( 'بعدی', 'shopharvester-pro' ); ?></button>
    </div>
</div>
