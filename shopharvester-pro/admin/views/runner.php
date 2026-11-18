<?php
/**
 * Runner view — submit URLs / payloads and watch job progress.
 *
 * @package ShopHarvesterPro
 * @var Job_Queue $jobs
 * @var array     $stores
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$job_list = $jobs->query( array( 'per_page' => 30 ) );
$settings = \ShopHarvesterPro\Includes\Settings::all();
$rest_ns  = esc_url_raw( rest_url( 'shopharvester/v1' ) );

// Non-AJAX result feedback (fallback path).
$posted = isset( $_GET['shp_submitted'] ) ? sanitize_text_field( wp_unslash( $_GET['shp_submitted'] ) ) : ''; // phpcs:ignore
?>
<div class="shp-grid-2">
    <div class="shp-panel">
        <h2><?php esc_html_e( 'اجرای برداشت جدید', 'shopharvester-pro' ); ?></h2>

        <?php if ( 'ok' === $posted ) : ?>
            <div class="notice notice-success inline"><p><?php esc_html_e( 'کارها در صف قرار گرفتند.', 'shopharvester-pro' ); ?></p></div>
        <?php elseif ( 'db' === $posted ) : ?>
            <div class="notice notice-error inline"><p><?php esc_html_e( 'جدول‌های دیتابیس افزونه وجود ندارند. افزونه را غیرفعال و دوباره فعال کنید.', 'shopharvester-pro' ); ?></p></div>
        <?php elseif ( 'empty' === $posted ) : ?>
            <div class="notice notice-error inline"><p><?php esc_html_e( 'حداقل یک آدرس وارد کنید.', 'shopharvester-pro' ); ?></p></div>
        <?php endif; ?>

        <form id="shp-run-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="shp_submit_run" />
            <?php wp_nonce_field( 'shp_submit_run_form', 'shp_form_nonce' ); ?>
            <label class="shp-field">
                <span><?php esc_html_e( 'آدرس(های) هدف — هر خط یک URL', 'shopharvester-pro' ); ?></span>
                <textarea name="urls" rows="4" placeholder="https://example-shop.com/&#10;https://another-shop.com/products"></textarea>
            </label>

            <label class="shp-field">
                <span><?php esc_html_e( 'اختصاص به زیردامنه (اختیاری)', 'shopharvester-pro' ); ?></span>
                <select name="subdomain">
                    <option value=""><?php esc_html_e( '— تشخیص خودکار از دامنه فروشگاه —', 'shopharvester-pro' ); ?></option>
                    <?php foreach ( $stores as $store ) : ?>
                        <option value="<?php echo esc_attr( $store['slug'] ); ?>"><?php echo esc_html( $store['name'] . ' (' . $store['slug'] . ')' ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <details class="shp-details">
                <summary><?php esc_html_e( 'ارسال مستقیم محموله JSON (برای اجراکننده Playwright/Scrapy)', 'shopharvester-pro' ); ?></summary>
                <label class="shp-field">
                    <textarea name="payload" rows="8" dir="ltr" placeholder='{"store":{"name":"","domain":"","email":""},"products":[{"title":"","source_url":"","price":"","images":[]}]}'></textarea>
                </label>
            </details>

            <button type="submit" class="shp-btn shp-btn--primary"><?php esc_html_e( 'شروع برداشت', 'shopharvester-pro' ); ?></button>
            <span class="shp-form-status" id="shp-run-status"></span>
        </form>
    </div>

    <div class="shp-panel">
        <h2><?php esc_html_e( 'اتصال اجراکننده بیرونی', 'shopharvester-pro' ); ?></h2>
        <p class="shp-muted"><?php esc_html_e( 'سرویس هدلس (Playwright/Scrapy) با کلید زیر به این نقاط پایانی متصل می‌شود.', 'shopharvester-pro' ); ?></p>
        <table class="shp-kv">
            <tr><th><?php esc_html_e( 'دریافت کارهای در انتظار', 'shopharvester-pro' ); ?></th><td dir="ltr"><code>GET <?php echo esc_html( $rest_ns ); ?>/jobs/pending</code></td></tr>
            <tr><th><?php esc_html_e( 'ارسال نتیجه یک کار', 'shopharvester-pro' ); ?></th><td dir="ltr"><code>POST <?php echo esc_html( $rest_ns ); ?>/jobs/{id}/result</code></td></tr>
            <tr><th><?php esc_html_e( 'ارسال یکجای داده', 'shopharvester-pro' ); ?></th><td dir="ltr"><code>POST <?php echo esc_html( $rest_ns ); ?>/ingest</code></td></tr>
            <tr><th><?php esc_html_e( 'هدر احراز هویت', 'shopharvester-pro' ); ?></th><td dir="ltr"><code>X-ShopHarvester-Secret: <?php echo esc_html( $settings['webhook_secret'] ); ?></code></td></tr>
        </table>
    </div>
</div>

<div class="shp-panel">
    <h2><?php esc_html_e( 'کارهای اخیر', 'shopharvester-pro' ); ?></h2>
    <table class="shp-table" id="shp-jobs-table">
        <thead>
            <tr>
                <th>#</th>
                <th><?php esc_html_e( 'آدرس هدف', 'shopharvester-pro' ); ?></th>
                <th><?php esc_html_e( 'وضعیت', 'shopharvester-pro' ); ?></th>
                <th><?php esc_html_e( 'نتیجه', 'shopharvester-pro' ); ?></th>
                <th><?php esc_html_e( 'تلاش‌ها', 'shopharvester-pro' ); ?></th>
                <th><?php esc_html_e( 'زمان', 'shopharvester-pro' ); ?></th>
                <th><?php esc_html_e( 'عملیات', 'shopharvester-pro' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $job_list['items'] as $job ) :
            $stats = json_decode( (string) $job->stats, true ) ?: array(); ?>
            <tr>
                <td><?php echo (int) $job->id; ?></td>
                <td class="shp-ellipsis" dir="ltr"><?php echo esc_html( $job->target_url ); ?></td>
                <td><span class="shp-badge shp-badge--<?php echo esc_attr( $job->status ); ?>"><?php echo esc_html( $job->status ); ?></span></td>
                <td>
                    <?php
                    if ( $stats ) {
                        printf(
                            /* translators: 1: imported 2: updated 3: skipped 4: failed */
                            esc_html__( '%1$d جدید / %2$d بروزرسانی / %3$d تکراری / %4$d خطا', 'shopharvester-pro' ),
                            (int) ( $stats['imported'] ?? 0 ),
                            (int) ( $stats['updated'] ?? 0 ),
                            (int) ( $stats['skipped'] ?? 0 ),
                            (int) ( $stats['failed'] ?? 0 )
                        );
                    } else {
                        echo '—';
                    }
                    ?>
                </td>
                <td><?php echo (int) $job->attempts; ?></td>
                <td><?php echo esc_html( $job->updated_at ); ?></td>
                <td class="shp-row-actions">
                    <?php if ( ! in_array( $job->status, array( 'completed', 'cancelled' ), true ) ) : ?>
                        <button class="shp-btn shp-btn--small" data-action="run" data-job="<?php echo (int) $job->id; ?>"><?php esc_html_e( 'اجرا', 'shopharvester-pro' ); ?></button>
                    <?php endif; ?>
                    <?php if ( in_array( $job->status, array( 'failed', 'retry', 'awaiting_runner', 'cancelled', 'completed' ), true ) ) : ?>
                        <button class="shp-btn shp-btn--small shp-btn--warn" data-action="retry" data-job="<?php echo (int) $job->id; ?>"><?php esc_html_e( 'اجرای مجدد', 'shopharvester-pro' ); ?></button>
                    <?php endif; ?>
                    <?php if ( in_array( $job->status, array( 'queued', 'retry', 'processing', 'awaiting_runner', 'received' ), true ) ) : ?>
                        <button class="shp-btn shp-btn--small shp-btn--ghost" data-action="cancel" data-job="<?php echo (int) $job->id; ?>"><?php esc_html_e( 'لغو', 'shopharvester-pro' ); ?></button>
                    <?php endif; ?>
                    <a class="shp-btn shp-btn--small shp-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=shopharvester-logs&job_id=' . (int) $job->id ) ); ?>"><?php esc_html_e( 'گزارش‌ها', 'shopharvester-pro' ); ?></a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ( empty( $job_list['items'] ) ) : ?>
            <tr><td colspan="7" class="shp-muted"><?php esc_html_e( 'هنوز کاری ثبت نشده است.', 'shopharvester-pro' ); ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
