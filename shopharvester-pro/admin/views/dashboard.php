<?php
/**
 * Dashboard view.
 *
 * @package ShopHarvesterPro
 * @var array $stats Aggregated counters.
 * @var array $stores Store list.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$by_status = $stats['jobs_by_status'];
?>
<div class="shp-panel shp-dash-hero">
    <img class="shp-help-logo" src="<?php echo esc_url( SHP_PLUGIN_URL . 'assets/logo.svg' ); ?>" alt="برداشت‌گر فروشگاه پرو" />
</div>
<div class="shp-panel">
    <div class="shp-cards" id="shp-stat-cards">
        <div class="shp-card">
            <span class="shp-card__label"><?php esc_html_e( 'محصولات (پیش‌نویس)', 'shopharvester-pro' ); ?></span>
            <span class="shp-card__value" data-stat="products_draft"><?php echo esc_html( number_format_i18n( $stats['products_draft'] ) ); ?></span>
        </div>
        <div class="shp-card">
            <span class="shp-card__label"><?php esc_html_e( 'مجموع محصولات', 'shopharvester-pro' ); ?></span>
            <span class="shp-card__value" data-stat="products_total"><?php echo esc_html( number_format_i18n( $stats['products_total'] ) ); ?></span>
        </div>
        <div class="shp-card">
            <span class="shp-card__label"><?php esc_html_e( 'فروشگاه‌ها', 'shopharvester-pro' ); ?></span>
            <span class="shp-card__value" data-stat="stores_total"><?php echo esc_html( number_format_i18n( $stats['stores_total'] ) ); ?></span>
        </div>
        <div class="shp-card">
            <span class="shp-card__label"><?php esc_html_e( 'کارهای در صف', 'shopharvester-pro' ); ?></span>
            <span class="shp-card__value" data-stat="jobs_total"><?php echo esc_html( number_format_i18n( $stats['jobs_total'] ) ); ?></span>
        </div>
        <a class="shp-card shp-card--danger shp-card--link" href="<?php echo esc_url( admin_url( 'admin.php?page=shopharvester-logs&level=ERROR' ) ); ?>">
            <span class="shp-card__label"><?php esc_html_e( 'خطاهای ۲۴ ساعت اخیر', 'shopharvester-pro' ); ?></span>
            <span class="shp-card__value" data-stat="errors_24h"><?php echo esc_html( number_format_i18n( $stats['errors_24h'] ) ); ?></span>
        </a>
    </div>
</div>

<div class="shp-panel">
    <h2><?php esc_html_e( 'وضعیت کارها', 'shopharvester-pro' ); ?></h2>
    <div class="shp-badges">
        <?php
        $labels = array(
            'queued'          => __( 'در صف', 'shopharvester-pro' ),
            'awaiting_runner' => __( 'منتظر اجراکننده', 'shopharvester-pro' ),
            'processing'      => __( 'در حال پردازش', 'shopharvester-pro' ),
            'completed'       => __( 'تکمیل‌شده', 'shopharvester-pro' ),
            'retry'           => __( 'اجرای مجدد', 'shopharvester-pro' ),
            'failed'          => __( 'ناموفق', 'shopharvester-pro' ),
            'cancelled'       => __( 'لغوشده', 'shopharvester-pro' ),
        );
        foreach ( $labels as $key => $label ) :
            $count = isset( $by_status[ $key ] ) ? (int) $by_status[ $key ] : 0;
            ?>
            <span class="shp-badge shp-badge--<?php echo esc_attr( $key ); ?>">
                <?php echo esc_html( $label ); ?>: <strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong>
            </span>
        <?php endforeach; ?>
    </div>
</div>

<?php
$recent_errors = \ShopHarvesterPro\Includes\Logger::query(
    array(
        'level'    => 'ERROR',
        'per_page' => 15,
        'paged'    => 1,
    )
);
?>
<div class="shp-panel">
    <h2>
        <?php esc_html_e( 'خطاهای اخیر', 'shopharvester-pro' ); ?>
        <a class="shp-link-more" href="<?php echo esc_url( admin_url( 'admin.php?page=shopharvester-logs&level=ERROR' ) ); ?>"><?php esc_html_e( 'مشاهده همه در گزارش‌ها ←', 'shopharvester-pro' ); ?></a>
    </h2>
    <?php if ( empty( $recent_errors['items'] ) ) : ?>
        <p class="shp-muted"><?php esc_html_e( 'هیچ خطایی ثبت نشده است.', 'shopharvester-pro' ); ?></p>
    <?php else : ?>
        <table class="shp-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'زمان', 'shopharvester-pro' ); ?></th>
                    <th><?php esc_html_e( 'کانال', 'shopharvester-pro' ); ?></th>
                    <th><?php esc_html_e( 'پیام', 'shopharvester-pro' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $recent_errors['items'] as $row ) : ?>
                    <tr>
                        <td dir="ltr"><?php echo esc_html( $row->created_at ); ?></td>
                        <td><code><?php echo esc_html( $row->channel ); ?></code></td>
                        <td>
                            <?php echo esc_html( $row->message ); ?>
                            <?php if ( ! empty( $row->source_url ) ) : ?>
                                <br /><a dir="ltr" href="<?php echo esc_url( $row->source_url ); ?>" target="_blank" rel="noreferrer noopener"><?php echo esc_html( $row->source_url ); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="shp-panel">
    <h2><?php esc_html_e( 'آخرین فروشگاه‌ها', 'shopharvester-pro' ); ?></h2>
    <?php if ( empty( $stores ) ) : ?>
        <p class="shp-muted"><?php esc_html_e( 'هنوز فروشگاهی برداشت نشده است.', 'shopharvester-pro' ); ?></p>
    <?php else : ?>
        <table class="shp-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'نام فروشگاه', 'shopharvester-pro' ); ?></th>
                    <th><?php esc_html_e( 'نامک', 'shopharvester-pro' ); ?></th>
                    <th><?php esc_html_e( 'آدرس زیردامنه', 'shopharvester-pro' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( array_slice( $stores, 0, 15 ) as $store ) : ?>
                    <tr>
                        <td><?php echo esc_html( $store['name'] ); ?></td>
                        <td><code><?php echo esc_html( $store['slug'] ); ?></code></td>
                        <td><a href="<?php echo esc_url( \ShopHarvesterPro\Includes\Subdomain_Manager::store_url( $store['id'] ) ); ?>" target="_blank" rel="noreferrer"><?php echo esc_html( \ShopHarvesterPro\Includes\Subdomain_Manager::store_url( $store['id'] ) ); ?></a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
