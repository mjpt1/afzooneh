<?php
/**
 * Category mapping view.
 *
 * @package ShopHarvesterPro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use ShopHarvesterPro\Includes\Taxonomy_Mapper;

$map   = Taxonomy_Mapper::current_map();
$seen  = Taxonomy_Mapper::seen_names();
$rows  = array();

// Merge: every mapped rule + every seen source name not yet mapped.
foreach ( $map as $norm => $target ) {
    $rows[ $norm ] = array( 'source' => $norm, 'target' => $target );
}
$mapper = new Taxonomy_Mapper();
foreach ( $seen as $name ) {
    $norm = $mapper->normalize( $name );
    if ( ! isset( $rows[ $norm ] ) ) {
        $rows[ $norm ] = array( 'source' => $name, 'target' => '' );
    }
}
?>
<div class="shp-panel">
    <h2><?php esc_html_e( 'نگاشت دسته‌بندی‌ها و مجموعه‌ها', 'shopharvester-pro' ); ?></h2>
    <p class="shp-muted">
        <?php esc_html_e( 'نام دسته یا مجموعه‌ای که از سایت مبدأ خوانده می‌شود (ستون چپ) را به نام دلخواه مقصد در سایت شما نگاشت کنید. مقصد می‌تواند مسیر سلسله‌مراتبی باشد؛ برای نمونه: «کفش > ورزشی». ردیف‌های بدون مقدار مقصد، بدون تغییر ساخته می‌شوند.', 'shopharvester-pro' ); ?>
    </p>

    <table class="shp-table" id="shp-map-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'نام مبدأ (خوانده‌شده)', 'shopharvester-pro' ); ?></th>
                <th><?php esc_html_e( 'نام مقصد (در سایت شما)', 'shopharvester-pro' ); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $rows as $row ) : ?>
                <tr>
                    <td><input type="text" class="shp-map-source" value="<?php echo esc_attr( $row['source'] ); ?>" /></td>
                    <td><input type="text" class="shp-map-target" value="<?php echo esc_attr( $row['target'] ); ?>" placeholder="<?php esc_attr_e( 'خالی = بدون تغییر', 'shopharvester-pro' ); ?>" /></td>
                    <td><button type="button" class="shp-btn shp-btn--small shp-btn--ghost shp-map-del">&times;</button></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $rows ) ) : ?>
                <tr>
                    <td><input type="text" class="shp-map-source" value="" /></td>
                    <td><input type="text" class="shp-map-target" value="" /></td>
                    <td><button type="button" class="shp-btn shp-btn--small shp-btn--ghost shp-map-del">&times;</button></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <p>
        <button type="button" class="shp-btn shp-btn--small" id="shp-map-add"><?php esc_html_e( 'افزودن ردیف', 'shopharvester-pro' ); ?></button>
        <button type="button" class="shp-btn shp-btn--primary" id="shp-map-save"><?php esc_html_e( 'ذخیره نگاشت', 'shopharvester-pro' ); ?></button>
        <span class="shp-form-status" id="shp-map-status"></span>
    </p>
</div>

<div class="shp-panel">
    <h2><?php esc_html_e( 'دسته‌های تکراریِ هم‌نام', 'shopharvester-pro' ); ?></h2>
    <p class="shp-muted"><?php esc_html_e( 'از نسخه ۳.۴.۷، وقتی مسیرِ دسته‌ی سایتِ مبدأ با ساختارِ دسته‌های خودِ شما فرق دارد، افزونه به‌جای ساختنِ شاخه‌ی تکراری، دسته‌ی هم‌نامِ موجود را (در هر جای درخت) دوباره استفاده می‌کند. اگر از قبل دسته‌های تکراری ساخته شده، این دکمه آن‌ها را ادغام می‌کند: قدیمی‌ترین دسته نگه داشته می‌شود، زیردسته‌ها و محصولاتِ بقیه به آن منتقل می‌شوند و خودشان حذف می‌شوند.', 'shopharvester-pro' ); ?></p>
    <p>
        <button class="shp-btn shp-btn--ghost" id="shp-merge-cats"><?php esc_html_e( 'ادغام دسته‌های تکراریِ هم‌نام', 'shopharvester-pro' ); ?></button>
        <span class="shp-note" id="shp-merge-cats-status" style="margin-inline-start:8px"></span>
    </p>
</div>

<script>
( function () {
	var api = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	var nonce = <?php echo wp_json_encode( wp_create_nonce( 'shp_admin_nonce' ) ); ?>;

	function arm( btn, run ) {
		if ( ! btn ) { return; }
		var armed = false, t, label = btn.textContent;
		btn.addEventListener( 'click', function () {
			if ( btn.disabled ) { return; }
			if ( ! armed ) {
				armed = true;
				btn.textContent = 'برای تأیید دوباره کلیک کنید';
				t = window.setTimeout( function () { armed = false; btn.textContent = label; }, 4000 );
				return;
			}
			window.clearTimeout( t );
			armed = false;
			btn.textContent = label;
			btn.disabled = true;
			run();
		} );
	}

	arm( document.getElementById( 'shp-merge-cats' ), function () {
		var s = document.getElementById( 'shp-merge-cats-status' );
		if ( s ) { s.textContent = 'شروع…'; }
		var body = new URLSearchParams();
		body.set( 'action', 'shp_merge_duplicate_categories' );
		body.set( 'nonce', nonce );
		fetch( api, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() } )
			.then( function ( r ) { return r.json().catch( function () { return { success: false, data: { message: 'پاسخ نامعتبر (HTTP ' + r.status + ')' } }; } ); } )
			.then( function ( res ) {
				if ( s ) { s.textContent = ( res && res.data && res.data.message ) || ( res && res.success ? 'تمام شد' : 'خطا' ); }
				window.setTimeout( function () { window.location.reload(); }, 1600 );
			} )
			.catch( function ( e ) {
				if ( s ) { s.textContent = 'خطای شبکه: ' + e.message; }
			} );
	} );
}() );
</script>
