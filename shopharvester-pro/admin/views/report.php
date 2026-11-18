<?php
/**
 * System report view — developer diagnostics.
 *
 * @package ShopHarvesterPro
 * @var string $nonce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use ShopHarvesterPro\Includes\System_Report;

$report       = System_Report::generate();
$download_txt = add_query_arg(
    array(
        'action' => 'shp_download_report',
        'nonce'  => $nonce,
    ),
    admin_url( 'admin-ajax.php' )
);
$download_json = add_query_arg(
    array(
        'action' => 'shp_download_logs_json',
        'nonce'  => $nonce,
    ),
    admin_url( 'admin-ajax.php' )
);
?>
<div class="shp-panel">
    <h2><?php esc_html_e( 'گزارش سیستم برای توسعه‌دهنده', 'shopharvester-pro' ); ?></h2>
    <p class="shp-muted">
        <?php esc_html_e( 'این گزارش شامل نسخه‌ها، پیکربندی سرور، وضعیت WP-Cron، افزونه‌های فعال، تنظیمات (با کلیدهای API پنهان‌شده)، وضعیت کارها و ۸۰ رویداد آخر گزارش است. کلیدهای API هرگز در خروجی قرار نمی‌گیرند.', 'shopharvester-pro' ); ?>
    </p>

    <p class="shp-report-actions">
        <button type="button" class="shp-btn shp-btn--primary" id="shp-copy-report"><?php esc_html_e( 'کپی گزارش', 'shopharvester-pro' ); ?></button>
        <a class="shp-btn" href="<?php echo esc_url( $download_txt ); ?>"><?php esc_html_e( 'دانلود گزارش (.txt)', 'shopharvester-pro' ); ?></a>
        <a class="shp-btn" href="<?php echo esc_url( $download_json ); ?>"><?php esc_html_e( 'دانلود لاگ کامل (.json)', 'shopharvester-pro' ); ?></a>
        <button type="button" class="shp-btn shp-btn--ghost" id="shp-run-queue"><?php esc_html_e( 'پردازش صف اکنون', 'shopharvester-pro' ); ?></button>
        <span class="shp-form-status" id="shp-report-status"></span>
    </p>

    <h3><?php esc_html_e( 'تست اتصال شبکه‌ی سرور', 'shopharvester-pro' ); ?></h3>
    <p class="shp-muted"><?php esc_html_e( 'بررسی می‌کند که سرور شما اصلاً می‌تواند به اینترنت و سایت هدف وصل شود یا نه. اگر همه‌ی خطوط قرمز باشند، هیچ اسکرپری کار نمی‌کند و باید با میزبان درباره‌ی اجازه‌ی اتصال خروجی صحبت کنید.', 'shopharvester-pro' ); ?></p>
    <p>
        <input type="url" id="shp-net-url" placeholder="https://example.com/  (اختیاری: آدرس سایت هدف)" style="width:360px;max-width:100%" />
        <button type="button" class="shp-btn shp-btn--primary" id="shp-net-run"><?php esc_html_e( 'اجرای تست', 'shopharvester-pro' ); ?></button>
    </p>
    <div class="shp-net-test" id="shp-net-results"></div>

    <h3><?php esc_html_e( 'گزارش کامل', 'shopharvester-pro' ); ?></h3>
    <textarea id="shp-report-text" class="shp-report-text" readonly rows="28" dir="ltr" spellcheck="false"
        style="width:100%;background:#0f172a;color:#e2e8f0;border:1px solid #334155;border-radius:10px;padding:14px;font-family:Consolas,Menlo,Monaco,monospace;font-size:12px;line-height:1.5;white-space:pre;overflow:auto;-webkit-text-fill-color:#e2e8f0;"><?php echo esc_textarea( $report ); ?></textarea>
</div>
