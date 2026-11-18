<?php
/**
 * Stores listing view.
 *
 * @package ShopHarvesterPro
 * @var array $stores
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use ShopHarvesterPro\Includes\Entity_Manager;
use ShopHarvesterPro\Includes\Subdomain_Manager;
?>
<div class="shp-panel">
    <h2><?php esc_html_e( 'فروشگاه‌های برداشت‌شده', 'shopharvester-pro' ); ?></h2>

    <?php
    $stores_page_id = (int) get_option( 'shp_stores_page_id' );
    if ( $stores_page_id && get_post( $stores_page_id ) ) :
        ?>
        <p class="shp-muted">
            <?php esc_html_e( 'صفحه‌ی عمومی فهرست فروشگاه‌ها:', 'shopharvester-pro' ); ?>
            <a href="<?php echo esc_url( get_permalink( $stores_page_id ) ); ?>" target="_blank" rel="noreferrer"><?php echo esc_html( get_permalink( $stores_page_id ) ); ?></a>
        </p>
    <?php endif; ?>

    <p class="shp-muted">
        <?php esc_html_e( 'شورت‌کدها:', 'shopharvester-pro' ); ?>
        <code>[shp_stores search="1" columns="3"]</code> ·
        <code>[shp_store_products store="ID" limit="24"]</code>
    </p>

    <?php if ( ! empty( $stores ) ) : ?>
        <p>
            <button class="shp-btn shp-btn--primary" id="shp-publish-all-drafts"><?php esc_html_e( 'انتشار همه‌ی پیش‌نویس‌های همه‌ی فروشگاه‌ها', 'shopharvester-pro' ); ?></button>
            <span class="shp-note" id="shp-publish-all-status" style="margin-inline-start:8px"></span>
        </p>
        <p class="shp-note"><?php esc_html_e( 'وضعیتِ همه‌ی محصولاتِ برداشت‌شده‌ی «پیش‌نویس» و «در انتظار بررسی» را دسته‌دسته به «منتشرشده» تغییر می‌دهد. برای تعداد زیاد، چند دقیقه طول می‌کشد؛ صفحه را نبندید.', 'shopharvester-pro' ); ?></p>

        <p style="margin-top:14px">
            <button class="shp-btn shp-btn--primary" id="shp-seo-optimize-all"><?php esc_html_e( 'بهینه‌سازی سئوی همه‌ی محصولات (چراغ سبز Yoast)', 'shopharvester-pro' ); ?></button>
            <label class="shp-inline-check" style="margin-inline-start:10px"><input type="checkbox" id="shp-seo-force" value="1" /> <?php esc_html_e( 'شاملِ محصولاتی که قبلاً بهینه شده‌اند', 'shopharvester-pro' ); ?></label>
            <span class="shp-note" id="shp-seo-status" style="margin-inline-start:8px"></span>
        </p>
        <p class="shp-note"><?php esc_html_e( 'برای هر محصولِ برداشت‌شده عبارتِ کانونی، توضیحِ متا، عنوانِ سئو، متنِ توضیحاتِ کافی با تیترهای H2، متنِ جایگزینِ تصاویر و برداشتنِ noindex را تنظیم می‌کند. اگر هوش مصنوعی در «تنظیمات» فعال و دارای کلید باشد، متن‌ها را با هوش مصنوعی می‌نویسد؛ در غیر این صورت از قالبِ مبتنی‌بر داده‌های واقعی استفاده می‌شود. متن‌هایی که خودتان ویرایش کرده‌اید تغییر نمی‌کنند.', 'shopharvester-pro' ); ?></p>

        <p style="margin-top:14px">
            <button class="shp-btn shp-btn--ghost" id="shp-repair-glued-prices"><?php esc_html_e( 'اصلاح قیمت‌های به‌هم‌چسبیده', 'shopharvester-pro' ); ?></button>
            <span class="shp-note" id="shp-repair-prices-status" style="margin-inline-start:8px"></span>
        </p>
        <p class="shp-note"><?php esc_html_e( 'باگی که در نسخه‌های قبلی قیمتِ اصلی و قیمتِ تخفیف‌خورده را در بعضی محصولات به‌هم می‌چسباند (مثلاً ۳۷۹۸۰۰۰۳۴۲۰۰۰۰) رفع شده؛ این دکمه محصولاتِ قبلاً برداشت‌شده را بررسی و در صورت امکان اصلاح می‌کند. اگر محصولی قابلِ اصلاح نبود (نتوانست دو عددِ معقول از آن دربیاورد)، بدون تغییر می‌ماند — آن فروشگاه را دوباره برداشت کنید.', 'shopharvester-pro' ); ?></p>

        <p style="margin-top:14px">
            <button class="shp-btn shp-btn--ghost" id="shp-purge-store-cats"><?php esc_html_e( 'حذف دسته‌های هم‌نام با فروشگاه‌ها', 'shopharvester-pro' ); ?></button>
            <span class="shp-note" id="shp-purge-cats-status" style="margin-inline-start:8px"></span>
        </p>
        <p class="shp-note"><?php esc_html_e( 'اگر در نسخه‌های قبلی نام هر فروشگاه به‌عنوان یک دسته‌ی محصول ساخته شده بود، این دکمه آن دسته‌ها را حذف می‌کند؛ زیردسته‌ها یک سطح بالا می‌آیند و محصولات دسته‌های واقعیِ خودشان را نگه می‌دارند. از این پس فروشگاه هرگز دسته نمی‌شود.', 'shopharvester-pro' ); ?></p>

        <?php if ( post_type_exists( 'rtcl_listing' ) ) : ?>
            <p style="margin-top:14px">
                <button class="shp-btn shp-btn--ghost" id="shp-purge-product-listings"><?php esc_html_e( 'حذف آگهی‌های تکیِ محصولات (نگه‌داشتن آگهیِ فروشگاه‌ها)', 'shopharvester-pro' ); ?></button>
                <span class="shp-note" id="shp-purge-status" style="margin-inline-start:8px"></span>
            </p>
            <p class="shp-note"><?php esc_html_e( 'اگر در نسخه‌های قبلی برای هر محصول یک «آگهی» ساخته شده، این دکمه همه‌ی آن آگهی‌های تکی را به زباله‌دان می‌برد و فقط آگهیِ خودِ فروشگاه‌ها را در /all-ads نگه می‌دارد. محصولات ووکامرس دست‌نخورده می‌مانند. برای جلوگیری از ساختِ دوباره، در «تنظیمات» گزینه‌ی «ساخت آگهی برای هر محصول» را خاموش کنید.', 'shopharvester-pro' ); ?></p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="shp-panel">
    <?php if ( empty( $stores ) ) : ?>
        <p class="shp-muted"><?php esc_html_e( 'فهرست خالی است.', 'shopharvester-pro' ); ?></p>
    <?php else : ?>
        <table class="shp-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'نام', 'shopharvester-pro' ); ?></th>
                    <th><?php esc_html_e( 'دامنه', 'shopharvester-pro' ); ?></th>
                    <th><?php esc_html_e( 'ایمیل', 'shopharvester-pro' ); ?></th>
                    <th><?php esc_html_e( 'تلفن', 'shopharvester-pro' ); ?></th>
                    <th><?php esc_html_e( 'کاربر فروشنده', 'shopharvester-pro' ); ?></th>
                    <th><?php esc_html_e( 'محصولات', 'shopharvester-pro' ); ?></th>
                    <th><?php esc_html_e( 'پیش‌نویس', 'shopharvester-pro' ); ?></th>
                    <th><?php esc_html_e( 'عملیات', 'shopharvester-pro' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach ( $stores as $store ) :
                    $id        = $store['id'];
                    $vendor_id = (int) get_post_meta( $id, '_shp_store_vendor_id', true );
                    $vendor    = $vendor_id ? get_userdata( $vendor_id ) : null;
                    $term_id   = (int) get_post_meta( $id, '_shp_store_term_id', true );
                    $term      = $term_id ? get_term( $term_id, Entity_Manager::TAX_STORE ) : null;
                    $count     = ( $term && ! is_wp_error( $term ) ) ? (int) $term->count : 0;

                    $ptype  = Entity_Manager::product_post_type();
                    $drafts = 0;
                    if ( $term_id ) {
                        $dq = new WP_Query(
                            array(
                                'post_type'      => $ptype,
                                'post_status'    => array( 'draft', 'pending' ),
                                'posts_per_page' => 1,
                                'fields'         => 'ids',
                                'no_found_rows'  => false,
                                'tax_query'      => array(
                                    array(
                                        'taxonomy' => Entity_Manager::TAX_STORE,
                                        'field'    => 'term_id',
                                        'terms'    => array( $term_id ),
                                    ),
                                ),
                            )
                        );
                        $drafts = (int) $dq->found_posts;
                        wp_reset_postdata();
                    }

                    $products_link = admin_url( 'edit.php?post_type=' . $ptype . '&' . Entity_Manager::TAX_STORE . '=' . rawurlencode( $store['slug'] ) );
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo esc_html( $store['name'] ); ?></a></td>
                        <td dir="ltr"><?php echo esc_html( get_post_meta( $id, '_shp_store_domain', true ) ); ?></td>
                        <td dir="ltr"><?php echo esc_html( get_post_meta( $id, '_shp_store_email', true ) ); ?></td>
                        <td dir="ltr"><?php echo esc_html( get_post_meta( $id, '_shp_store_phone', true ) ); ?></td>
                        <td><?php echo $vendor ? esc_html( $vendor->user_login ) : '—'; ?></td>
                        <td><a href="<?php echo esc_url( $products_link ); ?>"><?php echo esc_html( number_format_i18n( $count ) ); ?></a></td>
                        <td><?php echo esc_html( number_format_i18n( $drafts ) ); ?></td>
                        <td class="shp-row-actions">
                            <?php if ( $drafts > 0 ) : ?>
                                <button class="shp-btn shp-btn--small shp-btn--primary" data-action="publish-store" data-store="<?php echo (int) $id; ?>"><?php esc_html_e( 'انتشار همه پیش‌نویس‌ها', 'shopharvester-pro' ); ?></button>
                            <?php endif; ?>
                            <?php if ( $count > 0 ) : ?>
                                <button class="shp-btn shp-btn--small shp-btn--ghost" data-action="delete-store-products" data-store="<?php echo (int) $id; ?>"><?php esc_html_e( 'حذف محصولات', 'shopharvester-pro' ); ?></button>
                            <?php endif; ?>
                            <a class="shp-btn shp-btn--small shp-btn--ghost" href="<?php echo esc_url( Subdomain_Manager::store_url( $id ) ); ?>" target="_blank" rel="noreferrer"><?php esc_html_e( 'مشاهده', 'shopharvester-pro' ); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
( function () {
	// Values printed straight from PHP so these buttons keep working even when
	// an asset optimiser strips wp_localize_script's inline data.
	var api = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	var nonce = <?php echo wp_json_encode( wp_create_nonce( 'shp_admin_nonce' ) ); ?>;

	function call( action, extra ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', nonce );
		Object.keys( extra || {} ).forEach( function ( k ) { body.set( k, extra[ k ] ); } );
		return fetch( api, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() } )
			.then( function ( r ) { return r.json().catch( function () { return { success: false, data: { message: 'پاسخ نامعتبر (HTTP ' + r.status + ')' } }; } ); } );
	}

	// Two-click confirm so a browser that suppresses confirm() dialogs cannot
	// silently swallow the action.
	function arm( btn, run ) {
		if ( ! btn ) { return; }
		var armed = false, t;
		var label = btn.textContent;
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

	function loop( action, status, key, extra ) {
		var s = document.getElementById( status );
		var total = 0;
		if ( s ) { s.textContent = 'شروع…'; }
		function tick() {
			call( action, extra() ).then( function ( res ) {
				if ( ! res || res.success === false ) {
					if ( s ) { s.textContent = ( res && res.data && res.data.message ) || 'خطا'; }
					return;
				}
				var d = res.data || {};
				if ( d.reset ) { extra.reset(); tick(); return; }
				total += ( d[ key ] || 0 );
				if ( s ) { s.textContent = 'انجام‌شده: ' + total + ' — باقی‌مانده: ' + ( d.remaining || 0 ) + ( d.ai ? ' (AI)' : '' ); }
				if ( ( d[ key ] || 0 ) > 0 && ( d.remaining || 0 ) > 0 ) {
					tick();
				} else {
					if ( s ) { s.textContent = 'تمام شد — ' + total; }
					window.setTimeout( function () { window.location.reload(); }, 1400 );
				}
			} ).catch( function ( e ) {
				if ( s ) { s.textContent = 'خطای شبکه: ' + e.message; }
			} );
		}
		tick();
	}

	arm( document.getElementById( 'shp-publish-all-drafts' ), function () {
		loop( 'shp_publish_all_drafts', 'shp-publish-all-status', 'published', function () { return {}; } );
	} );

	arm( document.getElementById( 'shp-seo-optimize-all' ), function () {
		var forced = { v: document.getElementById( 'shp-seo-force' ) && document.getElementById( 'shp-seo-force' ).checked ? 1 : 0 };
		var fn = function () { return forced.v ? { force: 1 } : {}; };
		fn.reset = function () { forced.v = 0; };
		loop( 'shp_seo_optimize_all', 'shp-seo-status', 'processed', fn );
	} );

	arm( document.getElementById( 'shp-purge-product-listings' ), function () {
		loop( 'shp_purge_product_listings', 'shp-purge-status', 'trashed', function () { return {}; } );
	} );

	arm( document.getElementById( 'shp-repair-glued-prices' ), function () {
		loop( 'shp_repair_glued_prices', 'shp-repair-prices-status', 'checked', function () { return {}; } );
	} );

	arm( document.getElementById( 'shp-purge-store-cats' ), function () {
		var s = document.getElementById( 'shp-purge-cats-status' );
		if ( s ) { s.textContent = 'شروع…'; }
		call( 'shp_purge_store_categories', {} ).then( function ( res ) {
			if ( s ) { s.textContent = ( res && res.data && res.data.message ) || ( res && res.success ? 'تمام شد' : 'خطا' ); }
			window.setTimeout( function () { window.location.reload(); }, 1600 );
		} ).catch( function ( e ) {
			if ( s ) { s.textContent = 'خطای شبکه: ' + e.message; }
		} );
	} );
}() );
</script>
