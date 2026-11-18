<?php
/**
 * Settings view.
 *
 * @package ShopHarvesterPro
 * @var array $settings
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Small helper to print a text field.
 *
 * @param string $key   Setting key.
 * @param string $label Label.
 * @param array  $settings Settings array.
 * @param string $type  Input type.
 * @return void
 */
$field = static function ( $key, $label, $settings, $type = 'text', $hint = '' ) {
    printf(
        '<label class="shp-field"><span>%s</span><input type="%s" name="settings[%s]" value="%s" dir="ltr" />%s</label>',
        esc_html( $label ),
        esc_attr( $type ),
        esc_attr( $key ),
        esc_attr( $settings[ $key ] ?? '' ),
        $hint ? '<small class="shp-hint">' . esc_html( $hint ) . '</small>' : ''
    );
};
?>
<form id="shp-settings-form">
    <div class="shp-grid-2">
        <div class="shp-panel">
            <h2><?php esc_html_e( 'عمومی', 'shopharvester-pro' ); ?></h2>
            <?php $field( 'base_domain', __( 'دامنه اصلی سایت', 'shopharvester-pro' ), $settings, 'text', 'example.com' ); ?>

            <label class="shp-field">
                <span><?php esc_html_e( 'حالت زیردامنه', 'shopharvester-pro' ); ?></span>
                <select name="settings[subdomain_mode]">
                    <option value="virtual" <?php selected( $settings['subdomain_mode'], 'virtual' ); ?>><?php esc_html_e( 'مجازی (مسیر /store/slug)', 'shopharvester-pro' ); ?></option>
                    <option value="real" <?php selected( $settings['subdomain_mode'], 'real' ); ?>><?php esc_html_e( 'واقعی (slug.example.com)', 'shopharvester-pro' ); ?></option>
                </select>
            </label>

            <label class="shp-field">
                <span><?php esc_html_e( 'نوع خروجی محصول', 'shopharvester-pro' ); ?></span>
                <select name="settings[product_target]">
                    <option value="native" <?php selected( $settings['product_target'], 'native' ); ?>><?php esc_html_e( 'نوع محتوای افزونه (shp_product)', 'shopharvester-pro' ); ?></option>
                    <option value="woocommerce" <?php selected( $settings['product_target'], 'woocommerce' ); ?> <?php disabled( ! ( class_exists( 'WooCommerce' ) || post_type_exists( 'product' ) ) ); ?>><?php esc_html_e( 'محصول واقعی ووکامرس', 'shopharvester-pro' ); ?></option>
                    <option value="classima" <?php selected( $settings['product_target'], 'classima' ); ?> <?php disabled( ! post_type_exists( 'rtcl_listing' ) ); ?>><?php esc_html_e( 'آگهی + فروشنده (Classima / Classified Listing)', 'shopharvester-pro' ); ?></option>
                </select>
                <small class="shp-hint"><?php esc_html_e( 'حالت ووکامرس: محصولات با سبد خرید، قیمت واقعی، موجودی، گالری، ویژگی‌ها و در صورت وجود، به‌صورت محصول متغیر ساخته می‌شوند. حالت آگهی: هر محصول هم‌زمان یک محصول ووکامرس (ویرایش کامل مثل قبل) و یک «آگهی» سایت (rtcl_listing) می‌سازد و برای هر فروشگاه مبدأ یک «فروشنده‌ی WCFM» ایجاد می‌کند.', 'shopharvester-pro' ); ?></small>
            </label>

            <?php if ( 'classima' === $settings['product_target'] || post_type_exists( 'rtcl_listing' ) ) : ?>
            <fieldset class="shp-field" style="border:1px solid var(--shp-border);border-radius:8px;padding:12px 14px;">
                <legend style="font-weight:700;font-size:13px;"><?php esc_html_e( 'تنظیمات حالت آگهی (Classima)', 'shopharvester-pro' ); ?></legend>

                <label class="shp-inline-check"><input type="checkbox" name="settings[classima_create_listing]" value="1" <?php checked( ! empty( $settings['classima_create_listing'] ) ); ?> /> <?php esc_html_e( 'برای هر محصول یک «آگهی» (rtcl_listing) هم بساز', 'shopharvester-pro' ); ?></label>
                <p class="shp-hint"><?php esc_html_e( 'خاموش (پیشنهادی برای این سایت): محصولات فقط محصول واقعی ووکامرس می‌شوند و از طریق فروشنده‌ی WCFM به فروشگاهشان وصل می‌شوند — نه آگهی، نه در فهرست آگهی‌ها. روشن: علاوه بر محصول ووکامرس، یک «آگهی» هم ساخته می‌شود که تنظیمات زیر روی آن اثر دارند.', 'shopharvester-pro' ); ?></p>

                <label class="shp-inline-check"><input type="checkbox" name="settings[classima_shop_directory]" value="1" <?php checked( ! empty( $settings['classima_shop_directory'] ) ); ?> /> <?php esc_html_e( 'برای هر فروشگاهِ مبدأ یک «آگهیِ فروشگاه» بساز تا در /all-ads دیده شود', 'shopharvester-pro' ); ?></label>
                <p class="shp-hint"><?php esc_html_e( 'مستقل از گزینه‌ی بالا. روشن (پیشنهادی): فقط خودِ فروشگاه در بخش آگهی‌ها فهرست می‌شود، نه تک‌تکِ محصولات. محصولات ووکامرس در صفحه‌ی همان فروشگاه دیده می‌شوند.', 'shopharvester-pro' ); ?></p>

                <label class="shp-field">
                    <span><?php esc_html_e( 'نوع آگهی (ad_type)', 'shopharvester-pro' ); ?></span>
                    <?php
                    $ad_types = function_exists( 'get_option' ) ? (array) get_option( 'rtcl_listing_types', array() ) : array();
                    ?>
                    <?php if ( $ad_types ) : ?>
                        <select name="settings[classima_ad_type]">
                            <option value=""><?php esc_html_e( '— بدون تعیین (پیشنهادی) —', 'shopharvester-pro' ); ?></option>
                            <?php foreach ( $ad_types as $slug => $label ) : ?>
                                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['classima_ad_type'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else : ?>
                        <input type="text" name="settings[classima_ad_type]" value="<?php echo esc_attr( $settings['classima_ad_type'] ); ?>" placeholder="" />
                    <?php endif; ?>
                    <small class="shp-hint"><?php esc_html_e( 'معمولاً این را خالی بگذارید. در این سایت «نوع آگهی» یک آگهی را به‌عنوان فروشگاه (بوتیک/پاساژ/زنجیره‌ای) در فهرست فروشگاه‌ها نشان می‌دهد؛ محصول برداشت‌شده نباید نوع فروشگاهی داشته باشد وگرنه در فهرست فروشگاه‌ها هم دیده می‌شود.', 'shopharvester-pro' ); ?></small>
                </label>

                <label class="shp-field">
                    <span><?php esc_html_e( 'فرم ثبت آگهی', 'shopharvester-pro' ); ?></span>
                    <?php
                    global $wpdb;
                    $shp_forms = array();
                    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'rtcl_forms' ) ) ) {
                        $shp_forms = $wpdb->get_results( "SELECT id, title FROM {$wpdb->prefix}rtcl_forms ORDER BY id ASC" ); // phpcs:ignore
                    }
                    ?>
                    <?php if ( $shp_forms ) : ?>
                        <select name="settings[classima_form_id]">
                            <option value="0"><?php esc_html_e( '— خودکار (فرم پیش‌فرض) —', 'shopharvester-pro' ); ?></option>
                            <?php foreach ( $shp_forms as $shp_form ) : ?>
                                <option value="<?php echo esc_attr( (int) $shp_form->id ); ?>" <?php selected( (int) $settings['classima_form_id'], (int) $shp_form->id ); ?>><?php echo esc_html( $shp_form->title ?: ( '#' . $shp_form->id ) ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else : ?>
                        <input type="number" min="0" name="settings[classima_form_id]" value="<?php echo esc_attr( (int) $settings['classima_form_id'] ); ?>" />
                        <small class="shp-hint"><?php esc_html_e( '۰ = خودکار.', 'shopharvester-pro' ); ?></small>
                    <?php endif; ?>
                </label>

                <label class="shp-field">
                    <span><?php esc_html_e( 'دسته‌ی پیش‌فرض آگهی', 'shopharvester-pro' ); ?></span>
                    <?php
                    if ( taxonomy_exists( 'rtcl_category' ) ) {
                        wp_dropdown_categories(
                            array(
                                'taxonomy'         => 'rtcl_category',
                                'name'             => 'settings[classima_category_id]',
                                'selected'         => (int) $settings['classima_category_id'],
                                'hierarchical'     => true,
                                'hide_empty'       => false,
                                'show_option_none' => esc_html__( '— بدون تعیین (از دسته‌های محصول) —', 'shopharvester-pro' ),
                                'option_none_value' => 0,
                            )
                        );
                    } else {
                        echo '<input type="number" min="0" name="settings[classima_category_id]" value="' . esc_attr( (int) $settings['classima_category_id'] ) . '" />';
                    }
                    ?>
                </label>

                <label class="shp-field">
                    <span><?php esc_html_e( 'موقعیت پیش‌فرض آگهی', 'shopharvester-pro' ); ?></span>
                    <?php
                    if ( taxonomy_exists( 'rtcl_location' ) ) {
                        wp_dropdown_categories(
                            array(
                                'taxonomy'         => 'rtcl_location',
                                'name'             => 'settings[classima_location_id]',
                                'selected'         => (int) $settings['classima_location_id'],
                                'hierarchical'     => true,
                                'hide_empty'       => false,
                                'show_option_none' => esc_html__( '— بدون تعیین —', 'shopharvester-pro' ),
                                'option_none_value' => 0,
                            )
                        );
                    } else {
                        echo '<input type="number" min="0" name="settings[classima_location_id]" value="' . esc_attr( (int) $settings['classima_location_id'] ) . '" />';
                    }
                    ?>
                </label>

                <label class="shp-field">
                    <span><?php esc_html_e( 'وضعیت آگهی هنگام ساخت', 'shopharvester-pro' ); ?></span>
                    <select name="settings[classima_listing_status]">
                        <option value="draft" <?php selected( $settings['classima_listing_status'], 'draft' ); ?>><?php esc_html_e( 'پیش‌نویس', 'shopharvester-pro' ); ?></option>
                        <option value="pending" <?php selected( $settings['classima_listing_status'], 'pending' ); ?>><?php esc_html_e( 'در انتظار بررسی', 'shopharvester-pro' ); ?></option>
                        <option value="publish" <?php selected( $settings['classima_listing_status'], 'publish' ); ?>><?php esc_html_e( 'منتشرشده', 'shopharvester-pro' ); ?></option>
                    </select>
                </label>

                <label class="shp-inline-check"><input type="checkbox" name="settings[classima_group_by_shop]" value="1" <?php checked( ! empty( $settings['classima_group_by_shop'] ) ); ?> /> <?php esc_html_e( 'هر محصول را زیر یک «آگهیِ فروشگاه» برای مبدأ خودش بنشان (post_parent)', 'shopharvester-pro' ); ?></label>
                <p class="shp-hint"><?php esc_html_e( 'برای هر فروشگاه مبدأ یک آگهیِ فروشگاه ساخته می‌شود (یا اگر آگهی فروشگاهی با همان نام از قبل باشد، به آن وصل می‌شود) و محصولات آن مبدأ زیرش قرار می‌گیرند. صفحه‌ی آن آگهیِ فروشگاه، فهرست محصولاتش را نشان می‌دهد.', 'shopharvester-pro' ); ?></p>

                <label class="shp-field">
                    <span><?php esc_html_e( 'نوع «آگهیِ فروشگاه» (ad_type)', 'shopharvester-pro' ); ?></span>
                    <?php if ( $ad_types ) : ?>
                        <select name="settings[classima_shop_ad_type]">
                            <option value=""><?php esc_html_e( '— بدون تعیین —', 'shopharvester-pro' ); ?></option>
                            <?php foreach ( $ad_types as $slug => $label ) : ?>
                                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['classima_shop_ad_type'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else : ?>
                        <input type="text" name="settings[classima_shop_ad_type]" value="<?php echo esc_attr( $settings['classima_shop_ad_type'] ); ?>" />
                    <?php endif; ?>
                    <small class="shp-hint"><?php esc_html_e( 'نوعی که آگهیِ فروشگاه با آن در فهرست فروشگاه‌ها دیده می‌شود (مثلاً «فروشگاه های زنجیره ای»). برای خودِ محصولات این را خالی بگذارید.', 'shopharvester-pro' ); ?></small>
                </label>

                <label class="shp-field">
                    <span><?php esc_html_e( 'وضعیت «آگهیِ فروشگاه» هنگام ساخت', 'shopharvester-pro' ); ?></span>
                    <select name="settings[classima_shop_status]">
                        <option value="publish" <?php selected( $settings['classima_shop_status'], 'publish' ); ?>><?php esc_html_e( 'منتشرشده (پیشنهادی)', 'shopharvester-pro' ); ?></option>
                        <option value="pending" <?php selected( $settings['classima_shop_status'], 'pending' ); ?>><?php esc_html_e( 'در انتظار بررسی', 'shopharvester-pro' ); ?></option>
                    </select>
                </label>

                <label class="shp-inline-check"><input type="checkbox" name="settings[classima_hide_products_from_directory]" value="1" <?php checked( ! empty( $settings['classima_hide_products_from_directory'] ) ); ?> /> <?php esc_html_e( 'محصولات را از فهرست آگهی‌ها/فروشگاه‌های سایت (all-ads و آرشیوها) پنهان کن — فقط در صفحه‌ی فروشگاه خودشان دیده شوند', 'shopharvester-pro' ); ?></label>

                <label class="shp-inline-check"><input type="checkbox" name="settings[classima_buy_button]" value="1" <?php checked( ! empty( $settings['classima_buy_button'] ) ); ?> /> <?php esc_html_e( 'روی صفحه‌ی هر محصول، دکمه‌ی «خرید» که به صفحه‌ی محصول در سایت مبدأ می‌رود نمایش بده', 'shopharvester-pro' ); ?></label>
                <p class="shp-hint"><?php esc_html_e( 'متن این دکمه از «متن دکمه‌ی خرید» در بخش مارکت‌پلیس گرفته می‌شود.', 'shopharvester-pro' ); ?></p>

                <label class="shp-inline-check"><input type="checkbox" name="settings[classima_make_vendor]" value="1" <?php checked( ! empty( $settings['classima_make_vendor'] ) ); ?> /> <?php esc_html_e( 'همچنین برای هر فروشگاه مبدأ یک «فروشنده و استورفرانت WCFM» بساز (صفحه‌ی /store/<نامک>/)', 'shopharvester-pro' ); ?></label>
                <p class="shp-hint"><?php esc_html_e( 'محصولات هم در آگهیِ فروشگاه (/listing/...) و هم در استورفرانت WCFM (/store/...) همان فروشگاه نشان داده می‌شوند، چون هر دو نویسنده‌شان همان فروشنده است.', 'shopharvester-pro' ); ?></p>
            </fieldset>
            <?php endif; ?>

            <h3><?php esc_html_e( 'حالت مارکت‌پلیس / مقایسه قیمت (مثل ترب)', 'shopharvester-pro' ); ?></h3>
            <label class="shp-inline-check"><input type="checkbox" name="settings[marketplace_mode]" value="1" <?php checked( ! empty( $settings['marketplace_mode'] ) ); ?> /> <?php esc_html_e( 'فعال‌سازی حالت مارکت‌پلیس', 'shopharvester-pro' ); ?></label>
            <p class="shp-hint"><?php esc_html_e( 'یک محصول یکسان از چند فروشگاه به یک «محصول متعارف» با چند «پیشنهاد فروشگاه» تبدیل می‌شود؛ صفحه‌ی محصول فهرست فروشگاه‌ها و قیمت‌ها را نشان می‌دهد و دکمه‌ی خرید به سایت مادر هدایت می‌کند.', 'shopharvester-pro' ); ?></p>

            <label class="shp-field">
                <span><?php esc_html_e( 'رفتار دکمه‌ی خرید', 'shopharvester-pro' ); ?></span>
                <select name="settings[buy_button_mode]">
                    <option value="external" <?php selected( $settings['buy_button_mode'], 'external' ); ?>><?php esc_html_e( 'هدایت به فروشگاه مادر (محصول خارجی ووکامرس)', 'shopharvester-pro' ); ?></option>
                    <option value="native" <?php selected( $settings['buy_button_mode'], 'native' ); ?>><?php esc_html_e( 'افزودن به سبد خرید همین سایت', 'shopharvester-pro' ); ?></option>
                </select>
            </label>
            <?php
            $field( 'buy_button_text', __( 'متن دکمه‌ی خرید', 'shopharvester-pro' ), $settings, 'text', 'خرید از فروشگاه' );
            $field( 'match_fuzzy_threshold', __( 'آستانه تطبیق محصول بین فروشگاه‌ها (۰ تا ۱۰۰)', 'shopharvester-pro' ), $settings, 'number', '۹۰ توصیه می‌شود' );
            ?>
            <label class="shp-inline-check"><input type="checkbox" name="settings[extract_full_specs]" value="1" <?php checked( ! empty( $settings['extract_full_specs'] ) ); ?> /> <?php esc_html_e( 'استخراج کامل جدول مشخصات فنی', 'shopharvester-pro' ); ?></label>

            <label class="shp-field">
                <span><?php esc_html_e( 'رفتار محصول تکراری', 'shopharvester-pro' ); ?></span>
                <select name="settings[duplicate_product_mode]">
                    <option value="update" <?php selected( $settings['duplicate_product_mode'], 'update' ); ?>><?php esc_html_e( 'بروزرسانی', 'shopharvester-pro' ); ?></option>
                    <option value="skip" <?php selected( $settings['duplicate_product_mode'], 'skip' ); ?>><?php esc_html_e( 'نادیده گرفتن', 'shopharvester-pro' ); ?></option>
                </select>
                <small class="shp-hint"><?php esc_html_e( 'وقتی همان آدرس دوباره برداشت شود: «بروزرسانی» رکورد موجود را تازه می‌کند، «نادیده گرفتن» آن را دست‌نخورده می‌گذارد.', 'shopharvester-pro' ); ?></small>
            </label>

            <label class="shp-field">
                <span><?php esc_html_e( 'دامنه‌ی بروزرسانی مجدد', 'shopharvester-pro' ); ?></span>
                <select name="settings[resync_scope]">
                    <option value="full" <?php selected( $settings['resync_scope'], 'full' ); ?>><?php esc_html_e( 'کامل (عنوان، توضیحات، تصاویر، سئو، قیمت، موجودی)', 'shopharvester-pro' ); ?></option>
                    <option value="price_stock" <?php selected( $settings['resync_scope'], 'price_stock' ); ?>><?php esc_html_e( 'فقط قیمت، موجودی، SKU و ویژگی‌ها', 'shopharvester-pro' ); ?></option>
                </select>
                <small class="shp-hint"><?php esc_html_e( 'اگر روی محصولات ویرایش دستی انجام داده‌اید، گزینه‌ی «فقط قیمت و موجودی» را انتخاب کنید تا نوشته‌های شما بازنویسی نشوند.', 'shopharvester-pro' ); ?></small>
            </label>

            <label class="shp-field">
                <span><?php esc_html_e( 'همگام‌سازی خودکار', 'shopharvester-pro' ); ?></span>
                <select name="settings[auto_resync_hours]">
                    <?php
                    $opts = array(
                        0   => __( 'غیرفعال', 'shopharvester-pro' ),
                        6   => __( 'هر ۶ ساعت', 'shopharvester-pro' ),
                        12  => __( 'هر ۱۲ ساعت', 'shopharvester-pro' ),
                        24  => __( 'روزانه', 'shopharvester-pro' ),
                        48  => __( 'هر ۲ روز', 'shopharvester-pro' ),
                        72  => __( 'هر ۳ روز', 'shopharvester-pro' ),
                        168 => __( 'هفتگی', 'shopharvester-pro' ),
                    );
                    foreach ( $opts as $h => $label ) {
                        printf( '<option value="%d" %s>%s</option>', (int) $h, selected( (int) $settings['auto_resync_hours'], (int) $h, false ), esc_html( $label ) );
                    }
                    ?>
                </select>
                <small class="shp-hint"><?php esc_html_e( 'اگر فعال باشد، همه‌ی آدرس‌هایی که قبلاً با موفقیت برداشت شده‌اند در بازه‌ی تعیین‌شده دوباره برداشت و طبق «دامنه‌ی بروزرسانی مجدد» تازه می‌شوند. به WP-Cron فعال نیاز دارد.', 'shopharvester-pro' ); ?></small>
            </label>

            <p class="shp-note"><?php esc_html_e( 'وضعیت انتشار محصولاتِ جدید همیشه «پیش‌نویس» است. اگر محصولی را منتشر کرده باشید، بروزرسانی مجدد آن را به پیش‌نویس برنمی‌گرداند.', 'shopharvester-pro' ); ?></p>

            <h3><?php esc_html_e( 'دسته‌بندی و مجموعه‌ها', 'shopharvester-pro' ); ?></h3>

            <label class="shp-field">
                <span><?php esc_html_e( 'محل ذخیره دسته‌ها', 'shopharvester-pro' ); ?></span>
                <select name="settings[category_target]">
                    <option value="shp" <?php selected( $settings['category_target'], 'shp' ); ?>><?php esc_html_e( 'تاکسونومی افزونه (shp_product_cat)', 'shopharvester-pro' ); ?></option>
                    <option value="woo" <?php selected( $settings['category_target'], 'woo' ); ?>><?php esc_html_e( 'دسته‌های ووکامرس (product_cat)', 'shopharvester-pro' ); ?></option>
                    <option value="both" <?php selected( $settings['category_target'], 'both' ); ?>><?php esc_html_e( 'هر دو', 'shopharvester-pro' ); ?></option>
                </select>
                <small class="shp-hint"><?php esc_html_e( 'گزینه‌های ووکامرس تنها زمانی اعمال می‌شوند که ووکامرس فعال باشد.', 'shopharvester-pro' ); ?></small>
            </label>

            <?php $field( 'category_fuzzy_threshold', __( 'آستانه تطبیق تقریبی نام دسته (۰ تا ۱۰۰)', 'shopharvester-pro' ), $settings, 'number', '۸۲ توصیه می‌شود؛ ۰ یعنی غیرفعال' ); ?>

            <label class="shp-inline-check"><input type="checkbox" name="settings[category_auto_create]" value="1" <?php checked( ! empty( $settings['category_auto_create'] ) ); ?> /> <?php esc_html_e( 'ساخت خودکار دسته‌های موجودنبود به‌صورت سلسله‌مراتبی', 'shopharvester-pro' ); ?></label>
            <label class="shp-inline-check"><input type="checkbox" name="settings[import_collections_as_categories]" value="1" <?php checked( ! empty( $settings['import_collections_as_categories'] ) ); ?> /> <?php esc_html_e( 'درون‌ریزی «مجموعه‌ها»ی فروشگاه مبدأ به‌عنوان دسته', 'shopharvester-pro' ); ?></label>

            <label class="shp-inline-check"><input type="checkbox" name="settings[purge_on_uninstall]" value="1" <?php checked( ! empty( $settings['purge_on_uninstall'] ) ); ?> /> <?php esc_html_e( 'حذف کامل داده‌ها هنگام حذف افزونه', 'shopharvester-pro' ); ?></label>
        </div>

        <div class="shp-panel">
            <h2><?php esc_html_e( 'موتور هوش مصنوعی', 'shopharvester-pro' ); ?></h2>
            <label class="shp-inline-check"><input type="checkbox" name="settings[ai_enabled]" value="1" <?php checked( ! empty( $settings['ai_enabled'] ) ); ?> /> <?php esc_html_e( 'فعال‌سازی غنی‌سازی هوشمند', 'shopharvester-pro' ); ?></label>

            <label class="shp-field">
                <span><?php esc_html_e( 'ارائه‌دهنده', 'shopharvester-pro' ); ?></span>
                <select name="settings[ai_provider]">
                    <option value="openai" <?php selected( $settings['ai_provider'], 'openai' ); ?>>OpenAI</option>
                    <option value="gapgpt" <?php selected( $settings['ai_provider'], 'gapgpt' ); ?>><?php esc_html_e( 'گپ‌جی‌پی‌تی (GapGPT)', 'shopharvester-pro' ); ?></option>
                    <option value="gemini" <?php selected( $settings['ai_provider'], 'gemini' ); ?>>Gemini</option>
                    <option value="perplexity" <?php selected( $settings['ai_provider'], 'perplexity' ); ?>>Perplexity</option>
                </select>
                <small class="shp-hint"><?php esc_html_e( 'گپ‌جی‌پی‌تی سازگار با OpenAI است و مدل‌های GPT، Claude و Gemini را واسطه می‌کند.', 'shopharvester-pro' ); ?></small>
            </label>

            <?php
            $field( 'ai_model', __( 'نام مدل', 'shopharvester-pro' ), $settings, 'text', 'gpt-4o-mini / gpt-4o / claude-3-5-sonnet / gemini-1.5-flash / sonar' );
            $field( 'openai_api_key', 'OpenAI API Key', $settings, 'password' );
            $field( 'gapgpt_api_key', 'GapGPT API Key', $settings, 'password' );
            $field( 'gapgpt_base_url', __( 'آدرس پایه گپ‌جی‌پی‌تی', 'shopharvester-pro' ), $settings, 'text', 'https://api.gapgpt.app/v1' );
            $field( 'gemini_api_key', 'Gemini API Key', $settings, 'password' );
            $field( 'perplexity_api_key', 'Perplexity API Key', $settings, 'password' );
            $field( 'ai_language', __( 'زبان خروجی', 'shopharvester-pro' ), $settings, 'text', 'fa' );
            $field( 'ai_timeout', __( 'مهلت پاسخ (ثانیه)', 'shopharvester-pro' ), $settings, 'number' );
            ?>

            <p>
                <button type="button" class="shp-btn shp-btn--primary" id="shp-ai-test"><?php esc_html_e( 'تست اتصال به هوش مصنوعی', 'shopharvester-pro' ); ?></button>
                <span class="shp-form-status" id="shp-ai-test-status"></span>
            </p>
            <p class="shp-hint"><?php esc_html_e( 'تست با مقادیر همین فرم انجام می‌شود؛ نیازی به ذخیره‌ی قبلی نیست.', 'shopharvester-pro' ); ?></p>

            <label class="shp-field">
                <span><?php esc_html_e( 'قالب پرامپت', 'shopharvester-pro' ); ?></span>
                <textarea name="settings[ai_prompt_template]" rows="10"><?php echo esc_textarea( $settings['ai_prompt_template'] ); ?></textarea>
                <small class="shp-hint"><?php esc_html_e( 'متغیرها: {{language}} {{title}} {{brand}} {{price}} {{categories}} {{attributes}} {{raw_description}} {{store_name}}', 'shopharvester-pro' ); ?></small>
            </label>
        </div>

        <div class="shp-panel">
            <h2><?php esc_html_e( 'رسانه', 'shopharvester-pro' ); ?></h2>
            <label class="shp-inline-check"><input type="checkbox" name="settings[download_images]" value="1" <?php checked( ! empty( $settings['download_images'] ) ); ?> /> <?php esc_html_e( 'دانلود تصاویر محصول به کتابخانه رسانه', 'shopharvester-pro' ); ?></label>
            <?php
            $field( 'max_images_per_product', __( 'حداکثر تصویر برای هر محصول', 'shopharvester-pro' ), $settings, 'number' );
            $field( 'image_timeout', __( 'مهلت دانلود هر تصویر (ثانیه)', 'shopharvester-pro' ), $settings, 'number' );
            ?>
        </div>

        <div class="shp-panel">
            <h2><?php esc_html_e( 'واکشی‌کننده داخلی', 'shopharvester-pro' ); ?></h2>
            <label class="shp-inline-check"><input type="checkbox" name="settings[builtin_scraper_enabled]" value="1" <?php checked( ! empty( $settings['builtin_scraper_enabled'] ) ); ?> /> <?php esc_html_e( 'فعال‌سازی واکشی سمت سرور (بدون نیاز به سرویس بیرونی)', 'shopharvester-pro' ); ?></label>
            <label class="shp-inline-check"><input type="checkbox" name="settings[builtin_follow_products]" value="1" <?php checked( ! empty( $settings['builtin_follow_products'] ) ); ?> /> <?php esc_html_e( 'باز کردن صفحه‌ی تک‌تک محصولات برای دریافت اطلاعات کامل', 'shopharvester-pro' ); ?></label>
            <?php
            $field( 'builtin_max_pages', __( 'حداکثر صفحه برای دنبال‌کردن صفحه‌بندی', 'shopharvester-pro' ), $settings, 'number' );
            $field( 'builtin_max_products', __( 'حداکثر تعداد محصول در هر اجرا (تا ۱۰۰۰۰)', 'shopharvester-pro' ), $settings, 'number' );
            $field( 'builtin_timeout', __( 'مهلت واکشی هر صفحه (ثانیه)', 'shopharvester-pro' ), $settings, 'number' );
            ?>
            <h3><?php esc_html_e( 'رندر جاوااسکریپت (سرویس بیرونی)', 'shopharvester-pro' ); ?></h3>
            <p class="shp-hint"><?php esc_html_e( 'برای سایت‌های تک‌صفحه‌ای (SPA) که محتوا را با جاوااسکریپت می‌سازند. یک سرویس رندر مثل ScrapingBee، ScraperAPI یا ZenRows بگیرید و قالب آدرس آن را اینجا بگذارید؛ افزونه صفحه‌ی رندر‌شده را دریافت و پردازش می‌کند. نیازی به اجرای هیچ برنامه‌ای روی کامپیوتر نیست.', 'shopharvester-pro' ); ?></p>

            <label class="shp-inline-check"><input type="checkbox" name="settings[js_render_enabled]" value="1" <?php checked( ! empty( $settings['js_render_enabled'] ) ); ?> /> <?php esc_html_e( 'فعال‌سازی رندر جاوااسکریپت (به‌عنوان آخرین تلاش)', 'shopharvester-pro' ); ?></label>

            <label class="shp-field">
                <span><?php esc_html_e( 'قالب آدرس سرویس رندر', 'shopharvester-pro' ); ?></span>
                <input type="text" name="settings[js_render_url_template]" value="<?php echo esc_attr( $settings['js_render_url_template'] ); ?>" dir="ltr" placeholder="https://app.scrapingbee.com/api/v1/?api_key=KEY&render_js=true&url={url}" />
                <small class="shp-hint"><?php esc_html_e( 'باید شامل {url} باشد (جای آدرس صفحه‌ی هدف). نمونه‌ها: ScraperAPI → https://api.scraperapi.com/?api_key=KEY&render=true&url={url} — ZenRows → https://api.zenrows.com/v1/?apikey=KEY&js_render=true&url={url}', 'shopharvester-pro' ); ?></small>
            </label>

            <?php
            $field( 'js_render_timeout', __( 'مهلت رندر (ثانیه)', 'shopharvester-pro' ), $settings, 'number' );
            $field( 'js_render_max_products', __( 'حداکثر محصول با رندر جاوااسکریپت (هزینه‌بر است)', 'shopharvester-pro' ), $settings, 'number' );
            ?>
        </div>

        <div class="shp-panel">
            <h2><?php esc_html_e( 'شبکه و ادب اسکرپ', 'shopharvester-pro' ); ?></h2>

            <label class="shp-field">
                <span><?php esc_html_e( 'پروکسی خروجی', 'shopharvester-pro' ); ?></span>
                <input type="text" name="settings[proxy_url]" value="<?php echo esc_attr( $settings['proxy_url'] ); ?>" dir="ltr" placeholder="http://user:pass@host:port" />
                <small class="shp-hint"><?php esc_html_e( 'اگر آی‌پی سرور شما توسط سایت هدف مسدود می‌شود، همه‌ی درخواست‌های اسکرپ و دانلود تصویر از این پروکسی عبور می‌کنند. خالی = بدون پروکسی.', 'shopharvester-pro' ); ?></small>
            </label>

            <label class="shp-field">
                <span><?php esc_html_e( 'نوع پروکسی', 'shopharvester-pro' ); ?></span>
                <select name="settings[proxy_type]">
                    <option value="http" <?php selected( $settings['proxy_type'], 'http' ); ?>>HTTP / HTTPS</option>
                    <option value="socks5" <?php selected( $settings['proxy_type'], 'socks5' ); ?>>SOCKS5</option>
                    <option value="socks4" <?php selected( $settings['proxy_type'], 'socks4' ); ?>>SOCKS4</option>
                </select>
            </label>

            <?php $field( 'request_delay_ms', __( 'تأخیر بین درخواست‌ها به هر دامنه (میلی‌ثانیه)', 'shopharvester-pro' ), $settings, 'number', '۰ = بدون تأخیر؛ ۱۰۰۰ تا ۳۰۰۰ برای ادب بیشتر' ); ?>

            <label class="shp-inline-check"><input type="checkbox" name="settings[respect_robots]" value="1" <?php checked( ! empty( $settings['respect_robots'] ) ); ?> /> <?php esc_html_e( 'احترام به robots.txt سایت هدف', 'shopharvester-pro' ); ?></label>
        </div>

        <div class="shp-panel">
            <h2><?php esc_html_e( 'وب‌هوک و گزارش‌ها', 'shopharvester-pro' ); ?></h2>
            <?php
            $field( 'webhook_secret', __( 'کلید امنیتی وب‌هوک', 'shopharvester-pro' ), $settings, 'text' );
            $field( 'log_retention_days', __( 'نگهداری گزارش‌ها (روز)', 'shopharvester-pro' ), $settings, 'number' );
            ?>
            <label class="shp-inline-check"><input type="checkbox" name="settings[log_to_file_fallback]" value="1" <?php checked( ! empty( $settings['log_to_file_fallback'] ) ); ?> /> <?php esc_html_e( 'ذخیره پشتیبان گزارش‌ها در فایل هنگام خطای پایگاه‌داده', 'shopharvester-pro' ); ?></label>
        </div>
    </div>

    <div class="shp-panel">
        <button type="submit" class="shp-btn shp-btn--primary"><?php esc_html_e( 'ذخیره تنظیمات', 'shopharvester-pro' ); ?></button>
        <span class="shp-form-status" id="shp-settings-status"></span>
    </div>
</form>
