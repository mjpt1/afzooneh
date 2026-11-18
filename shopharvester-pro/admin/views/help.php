<?php
/**
 * Help / getting-started guide for operators.
 *
 * @package ShopHarvesterPro
 * @var string $base_url
 * @var array  $settings
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$stores_page_id  = (int) get_option( 'shp_stores_page_id' );
$stores_page     = ( $stores_page_id && get_post( $stores_page_id ) ) ? get_permalink( $stores_page_id ) : '';
$compare_page_id = (int) get_option( 'shp_compare_page_id' );
$compare_page    = ( $compare_page_id && get_post( $compare_page_id ) ) ? get_permalink( $compare_page_id ) : '';
$webhook_url     = rest_url( 'shopharvester/v1/ingest' );
$marketplace     = ! empty( $settings['marketplace_mode'] );
?>
<div class="shp-panel shp-help">

    <div class="shp-help-hero">
        <img class="shp-help-logo" src="<?php echo esc_url( SHP_PLUGIN_URL . 'assets/logo.svg' ); ?>" alt="برداشت‌گر فروشگاه پرو" />
        <span class="shp-help-ver"><?php esc_html_e( 'نسخه', 'shopharvester-pro' ); ?> <?php echo esc_html( SHP_VERSION ); ?></span>
    </div>

    <h2><?php esc_html_e( 'راهنمای افزونه', 'shopharvester-pro' ); ?></h2>
    <p class="shp-muted"><?php esc_html_e( 'این افزونه اطلاعات محصولات یک یا چند فروشگاه اینترنتی را استخراج می‌کند، برای هر فروشگاه یک «پروفایل فروشگاه» و کاربر فروشنده می‌سازد، محصولات را (پیش‌نویس) ثبت می‌کند، در صورت تنظیم متادیتای سئو را با هوش مصنوعی و Yoast پر می‌کند، و در «حالت مارکت‌پلیس» محصولات یکسان چند فروشگاه را برای مقایسه‌ی قیمت در هم ادغام می‌کند.', 'shopharvester-pro' ); ?></p>

    <h2><?php esc_html_e( 'مسیر راه‌اندازی', 'shopharvester-pro' ); ?></h2>
    <ol class="shp-help-steps">
        <li>
            <h3><?php esc_html_e( '۱) تنظیمات پایه', 'shopharvester-pro' ); ?></h3>
            <ul>
                <li><strong><?php esc_html_e( 'دامنه‌ی اصلی سایت', 'shopharvester-pro' ); ?></strong> — <?php esc_html_e( 'برای صفحات فروشگاه و زیردامنه.', 'shopharvester-pro' ); ?></li>
                <li><strong><?php esc_html_e( 'نوع خروجی محصول', 'shopharvester-pro' ); ?></strong> — <?php esc_html_e( '«نوع محتوای افزونه»، «محصول واقعی ووکامرس» (سبد خرید، قیمت، موجودی، محصول متغیر)، یا «آگهی + فروشنده (Classima)». برای مارکت‌پلیس، ووکامرس را انتخاب کنید.', 'shopharvester-pro' ); ?></li>
                <li><strong><?php esc_html_e( 'حالت «آگهی + فروشنده» (سایت‌های Classified Listing / Classima)', 'shopharvester-pro' ); ?></strong> — <?php esc_html_e( 'به‌طور پیش‌فرض هر محصول برداشت‌شده فقط یک محصول واقعی ووکامرس می‌شود (ویرایش کامل: قیمت، موجودی، ویژگی، متغیر) که نویسنده‌اش «فروشنده‌ی WCFM» همان فروشگاه مبدأ است — پس در استورفرانت آن فروشنده (/store/...) دیده می‌شود؛ هیچ آگهی‌ای ساخته نمی‌شود و در فهرست آگهی‌های سایت هم ظاهر نمی‌شود. اگر می‌خواهی علاوه بر این، یک «آگهی» (rtcl_listing) هم ساخته شود، تیک «برای هر محصول یک آگهی هم بساز» را در «تنظیمات حالت آگهی» بزن — در آن صورت آگهی زیر «آگهیِ فروشگاهِ» مبدأش می‌نشیند و بقیه‌ی تنظیمات (نوع آگهی، فرم، دسته، موقعیت) روی آن اثر می‌کنند.', 'shopharvester-pro' ); ?></li>
                <li><strong><?php esc_html_e( 'آدرس واقعیِ فروشگاه چیست؟', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( 'آدرس هر فروشنده را حدس نزن — از خودِ سایت پیدا کن: صفحه‌ی هر محصولِ همان فروشگاه، لینکِ «فروشگاه» را نشان می‌دهد؛ همان‌جا را باز کن. نامک واقعی چیزی شبیه vendor_store-XXXX است (نه store-XXXX). «خلاصه فروشگاه‌ها» در پنل هم از نسخه‌ی ۳.۲.۸ همین آدرس درست را نشان می‌دهد.', 'shopharvester-pro' ); ?></li>
                <li><strong><?php esc_html_e( 'محل ذخیره‌ی دسته‌ها', 'shopharvester-pro' ); ?></strong> — <?php esc_html_e( 'تاکسونومی افزونه، دسته‌های ووکامرس، یا هر دو.', 'shopharvester-pro' ); ?></li>
                <li><strong><?php esc_html_e( 'هوش مصنوعی (اختیاری)', 'shopharvester-pro' ); ?></strong> — <?php esc_html_e( 'برای بازنویسی عنوان/توضیحات/سئو. ارائه‌دهنده و کلید را وارد و دکمه‌ی «تست اتصال به هوش مصنوعی» را بزنید. بدون کلید هم افزونه کار می‌کند و داده‌ی خام ذخیره می‌شود.', 'shopharvester-pro' ); ?></li>
                <li><strong><?php esc_html_e( 'شبکه و ادب اسکرپ', 'shopharvester-pro' ); ?></strong> — <?php esc_html_e( 'اگر آی‌پی سرور مسدود می‌شود، پروکسی خروجی وارد کنید؛ در صورت نیاز تأخیر بین درخواست‌ها و احترام به robots.txt.', 'shopharvester-pro' ); ?></li>
            </ul>
        </li>

        <?php if ( $marketplace ) : ?>
        <li>
            <h3><?php esc_html_e( '۲) تنظیمات مارکت‌پلیس (فعال است)', 'shopharvester-pro' ); ?></h3>
            <ul>
                <li><?php esc_html_e( 'یک محصول یکسان از چند فروشگاه به یک «محصول متعارف» با چند «پیشنهاد فروشگاه» تبدیل می‌شود.', 'shopharvester-pro' ); ?></li>
                <li><?php esc_html_e( '«رفتار دکمه‌ی خرید» = هدایت به فروشگاه مادر → محصول به‌صورت «محصول خارجی/Affiliate» ووکامرس ساخته می‌شود و دکمه به ارزان‌ترین پیشنهاد لینک می‌دهد.', 'shopharvester-pro' ); ?></li>
                <li><?php esc_html_e( '«آستانه تطبیق» (پیش‌فرض ۹۰): هرچه بالاتر، تطبیق سخت‌گیرانه‌تر. اگر محصولات اشتباه ادغام می‌شوند بالا ببرید؛ اگر ادغام نمی‌شوند پایین بیاورید.', 'shopharvester-pro' ); ?></li>
                <li><?php esc_html_e( 'تطبیق بر اساس بارکد/مدل، سپس کلید «برند|عنوان»، سپس تطبیق فازی عنوان انجام می‌شود.', 'shopharvester-pro' ); ?></li>
            </ul>
        </li>
        <?php endif; ?>

        <li>
            <h3><?php echo $marketplace ? esc_html__( '۳) وارد کردن آدرس‌ها', 'shopharvester-pro' ) : esc_html__( '۲) وارد کردن آدرس‌ها', 'shopharvester-pro' ); ?></h3>
            <p><?php esc_html_e( 'تب «اجرای برداشت» — هر خط یک URL. بهترین آدرس‌ها:', 'shopharvester-pro' ); ?></p>
            <ul>
                <li><?php esc_html_e( 'در حالت آگهی: صفحه‌ی هر محصول یک دکمه‌ی «خرید» دارد که به صفحه‌ی محصول در سایت مبدأ می‌رود. متن دکمه در تنظیمات ← بخش مارکت‌پلیس ← «متن دکمه‌ی خرید».', 'shopharvester-pro' ); ?></li>
                <li><?php esc_html_e( 'صفحه‌ی اصلی فروشگاه ووکامرسی یا Shopify (همه‌ی محصولات از API خوانده می‌شود).', 'shopharvester-pro' ); ?></li>
                <li><?php esc_html_e( 'صفحه‌ی دسته‌بندی / لیست محصولات.', 'shopharvester-pro' ); ?></li>
                <li><?php esc_html_e( 'صفحه‌ی یک محصول مشخص.', 'shopharvester-pro' ); ?></li>
            </ul>
            <p class="shp-hint"><?php esc_html_e( 'زنجیره‌ی منابع: WooCommerce Store API → Shopify → اسکرپ HTML/نقشه‌ی سایت → رندر جاوااسکریپت → اجراکننده‌ی Playwright.', 'shopharvester-pro' ); ?></p>
        </li>

        <li>
            <h3><?php echo $marketplace ? esc_html__( '۴) پردازش در پس‌زمینه', 'shopharvester-pro' ) : esc_html__( '۳) پردازش در پس‌زمینه', 'shopharvester-pro' ); ?></h3>
            <p><?php esc_html_e( 'کار تکه‌ای و در پس‌زمینه (Action Scheduler یا WP-Cron) انجام می‌شود. جدول «کارهای اخیر» خودش تازه می‌شود: «در حال پردازش: X از Y» ← «کامل شد».', 'shopharvester-pro' ); ?></p>
            <p><?php esc_html_e( 'اگر جلو نرفت: «گزارش سیستم» ← «پردازش صف اکنون». برای متوقف کردن یک کار: دکمه‌ی «لغو» روبه‌روی آن.', 'shopharvester-pro' ); ?></p>
        </li>

        <li>
            <h3><?php echo $marketplace ? esc_html__( '۵) بازبینی و انتشار', 'shopharvester-pro' ) : esc_html__( '۴) بازبینی و انتشار', 'shopharvester-pro' ); ?></h3>
            <p><?php esc_html_e( 'محصولات عمداً «پیش‌نویس» ثبت می‌شوند.', 'shopharvester-pro' ); ?></p>
            <ul>
                <li><?php esc_html_e( '«محصولات برداشت‌شده» → انتخاب چند مورد → «اقدامات گروهی: انتشار». در حالت ووکامرس/آگهی این منو به فهرست محصولات ووکامرس با فیلتر «برداشت‌شده» می‌رود (فقط محصولاتی که افزونه ساخته).', 'shopharvester-pro' ); ?></li>
                <li><?php esc_html_e( '«خلاصه فروشگاه‌ها» → «انتشار همه پیش‌نویس‌ها» برای یک فروشگاه.', 'shopharvester-pro' ); ?></li>
                <li><?php esc_html_e( '«خلاصه فروشگاه‌ها» → «انتشار همه‌ی پیش‌نویس‌های همه‌ی فروشگاه‌ها» — یک‌جا برای همه (دسته‌دسته، صفحه را نبندید).', 'shopharvester-pro' ); ?></li>
                <li><?php esc_html_e( 'اقدام گروهی «قفل کردن» تا محصول در برداشت‌های بعدی تغییر نکند.', 'shopharvester-pro' ); ?></li>
                <?php if ( $marketplace ) : ?>
                    <li><?php esc_html_e( 'روی صفحه‌ی ویرایش هر محصول، متاباکس «پیشنهادهای فروشگاه‌ها» را برای مشاهده/حذف/افزودن دستی پیشنهاد ببینید.', 'shopharvester-pro' ); ?></li>
                <?php endif; ?>
            </ul>
        </li>

        <li>
            <h3><?php echo $marketplace ? esc_html__( '۶) نگاشت دسته‌ها', 'shopharvester-pro' ) : esc_html__( '۵) نگاشت دسته‌ها', 'shopharvester-pro' ); ?></h3>
            <p><?php esc_html_e( 'تب «نگاشت دسته‌ها»: نام دسته‌های خوانده‌شده از مبدأ را به نام/مسیر دلخواه سایت خودتان نگاشت کنید (مثلاً: کفش > ورزشی).', 'shopharvester-pro' ); ?></p>
        </li>

        <li>
            <h3><?php echo $marketplace ? esc_html__( '۷) نمایش در سایت', 'shopharvester-pro' ) : esc_html__( '۶) نمایش در سایت', 'shopharvester-pro' ); ?></h3>
            <?php if ( $stores_page ) : ?>
                <p><?php esc_html_e( 'صفحه‌ی فهرست فروشگاه‌ها:', 'shopharvester-pro' ); ?> <a href="<?php echo esc_url( $stores_page ); ?>" target="_blank" rel="noreferrer"><?php echo esc_html( $stores_page ); ?></a></p>
            <?php endif; ?>
            <?php if ( $compare_page ) : ?>
                <p><?php esc_html_e( 'صفحه‌ی مقایسه محصولات:', 'shopharvester-pro' ); ?> <a href="<?php echo esc_url( $compare_page ); ?>" target="_blank" rel="noreferrer"><?php echo esc_html( $compare_page ); ?></a></p>
            <?php endif; ?>
            <p><?php esc_html_e( 'شورت‌کدها:', 'shopharvester-pro' ); ?></p>
            <ul>
                <li><code>[shp_stores]</code> — <?php esc_html_e( 'فهرست همه‌ی فروشگاه‌ها', 'shopharvester-pro' ); ?> (<code>search="1" columns="4" orderby="products"</code>)</li>
                <li><code>[shp_store_products store="123" limit="24"]</code> — <?php esc_html_e( 'محصولات یک فروشگاه', 'shopharvester-pro' ); ?></li>
                <li><code>[shp_offers product="123"]</code> — <?php esc_html_e( 'فهرست فروشگاه‌ها و قیمت‌های یک محصول', 'shopharvester-pro' ); ?></li>
                <li><code>[shp_specs product="123"]</code> — <?php esc_html_e( 'جدول مشخصات فنی', 'shopharvester-pro' ); ?></li>
                <li><code>[shp_compare]</code> — <?php esc_html_e( 'صفحه‌ی مقایسه', 'shopharvester-pro' ); ?> · <code>[shp_compare_button product="123"]</code></li>
            </ul>
            <p><?php esc_html_e( 'صفحه‌ی تک‌محصول به‌طور خودکار گالری، مشخصات فنی، فهرست فروشگاه‌ها و دکمه‌ی «افزودن به مقایسه» را نشان می‌دهد. صفحه‌ی تک‌فروشگاه هم مشخصات فروشگاه + شبکه‌ی محصولات آن.', 'shopharvester-pro' ); ?></p>
        </li>

        <li>
            <h3><?php esc_html_e( 'ویرایش پروفایل فروشگاه', 'shopharvester-pro' ); ?></h3>
            <p><?php esc_html_e( 'ShopHarvester ← «پروفایل فروشگاه‌ها» ← روی نام فروشگاه کلیک کنید. صفحه با ویرایشگر کلاسیک وردپرس باز می‌شود؛ در متاباکس «اطلاعات فروشگاه» می‌توانید برند، دامنه، ایمیل، تلفن، نشانی، لوگو و شبکه‌های اجتماعی را تغییر دهید. عنوانِ نوشته = نام فروشگاه؛ متنِ ویرایشگر = توضیحاتِ فروشگاه.', 'shopharvester-pro' ); ?></p>
            <p><?php esc_html_e( 'با «به‌روزرسانی»، همه‌ی این اطلاعات به‌صورت خودکار به «آگهیِ فروشگاه» (صفحه‌ی /listing/…/ که در فهرست آگهی‌ها دیده می‌شود) و به «فروشگاهِ WCFM» (صفحه‌ی /store/…/) هم منتقل می‌شود. پیوندِ هر دو در پایینِ همین متاباکس هست.', 'shopharvester-pro' ); ?></p>
            <p><?php esc_html_e( 'نام فروشگاه هیچ‌وقت به‌عنوان «دسته‌بندی محصول» ساخته نمی‌شود. اگر از نسخه‌های قبلی چنین دسته‌هایی مانده، در «خلاصه فروشگاه‌ها» دکمه‌ی «حذف دسته‌های هم‌نام با فروشگاه‌ها» را بزنید.', 'shopharvester-pro' ); ?></p>
        </li>
    </ol>

    <h2><?php esc_html_e( 'بروزرسانی قیمت و موجودی', 'shopharvester-pro' ); ?></h2>
    <ul class="shp-help-trouble">
        <li><?php esc_html_e( 'افزونه سایت مبدأ را «رصد زنده» نمی‌کند؛ برای بروزرسانی همان آدرس باید دوباره برداشت شود (دستی یا خودکار).', 'shopharvester-pro' ); ?></li>
        <li><strong><?php esc_html_e( 'خودکار:', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( 'تنظیمات ← «همگام‌سازی خودکار» روی بازه‌ی دلخواه (روزانه…). همه‌ی آدرس‌های برداشت‌شده دوباره خوانده می‌شوند. در حالت مارکت‌پلیس، قیمت هر «پیشنهاد» تازه می‌شود.', 'shopharvester-pro' ); ?></li>
        <li><strong><?php esc_html_e( 'دامنه‌ی بروزرسانی:', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( 'اگر محصولات را دستی ویرایش کرده‌اید، «فقط قیمت، موجودی، SKU و ویژگی‌ها» را انتخاب کنید.', 'shopharvester-pro' ); ?></li>
        <li><?php esc_html_e( 'بروزرسانی مجدد، محصول منتشرشده را به پیش‌نویس برنمی‌گرداند و نامک سفارشی را حفظ می‌کند.', 'shopharvester-pro' ); ?></li>
    </ul>

    <h2><?php esc_html_e( 'رفع اشکال', 'shopharvester-pro' ); ?></h2>
    <ul class="shp-help-trouble">
        <li><strong><?php esc_html_e( 'اصلاً چیزی برداشت نمی‌شود و در لاگ هم نیست:', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( 'به «گزارش سیستم» بروید و بخش «Database tables» را ببینید. اگر جدولی «MISSING» بود، افزونه را غیرفعال و دوباره فعال کنید (یا با هاست درباره‌ی دسترسی CREATE TABLE صحبت کنید).', 'shopharvester-pro' ); ?></li>
        <li><strong><?php esc_html_e( 'هیچ محصولی نمی‌آید:', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( '«گزارش سیستم» ← «تست اتصال شبکه‌ی سرور» با آدرس سایت هدف. اگر قرمز بود، سرور شما به آن سایت دسترسی ندارد → پروکسی یا سرویس رندر لازم است.', 'shopharvester-pro' ); ?></li>
        <li><strong><?php esc_html_e( 'کار روی «در صف» می‌ماند:', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( 'WP-Cron کار نمی‌کند. «پردازش صف اکنون» را بزنید، یا کران واقعی سرور به wp-cron.php وصل کنید. (اگر ووکامرس فعال باشد، Action Scheduler صف را می‌گرداند.)', 'shopharvester-pro' ); ?></li>
        <li><strong><?php esc_html_e( 'صفحه‌ی «اجرای برداشت» هی خودش را ریلود می‌کند و نمی‌گذارد آدرس تایپ/پیست کنم:', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( 'از نسخه ۳.۴.۶ رفع شد — تا وقتی فوکوس روی کادرِ آدرس باشد ریلود نمی‌شود. اگر باز هم دیدید، صفحه را رفرش کنید تا نسخه‌ی جدیدِ اسکریپت لود شود.', 'shopharvester-pro' ); ?></li>
        <li><strong><?php esc_html_e( 'ویرایشِ «پروفایل فروشگاه» ذخیره/منتشر نمی‌شود:', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( 'معمولاً یعنی افزونه‌ی کشِ سایت خودِ فرمِ ویرایش را کش کرده و کدِ امنیتیِ آن کهنه شده. مسیرِ کاملِ /wp-admin/ را از کشِ سایت مستثنا کنید (نه فقط چند صفحه). برای تأیید: در یک پنجره‌ی ناشناس (Incognito) دوباره وارد شوید و امتحان کنید.', 'shopharvester-pro' ); ?></li>
        <li><strong><?php esc_html_e( 'برداشت ۰ محصول می‌دهد یا در «منتظر اجراکننده» می‌ماند:', 'shopharvester-pro' ); ?></strong>
            <ul>
                <li><?php esc_html_e( 'اول آدرس یک صفحه‌ی دسته‌بندی (نه صفحه‌ی خانه) را بدهید. افزونه از نسخه ۳.۳.۰ نشانه‌گذاریِ فروشگاه‌های سفارشیِ ایرانی را هم می‌شناسد (لینک‌های /product-<شناسه>، /q/…، کلاس‌های کارتِ غیر‌ووکامرسی) و صفحه‌بندیِ جاوااسکریپتی را با ?page=N خودش دنبال می‌کند — رایگان، بدون سرویس.', 'shopharvester-pro' ); ?></li>
                <li><?php esc_html_e( 'اگر سایت واقعاً SPA است (HTML خام تقریباً خالی): تنظیمات ← «رندر جاوااسکریپت» را با سرویسی مثل ScrapingBee/ScraperAPI/ZenRows فعال کنید (قالب آدرس با {url}).', 'shopharvester-pro' ); ?></li>
                <li><?php esc_html_e( 'یا اجراکننده‌ی Playwright روی یک ماشین که نتیجه را به این آدرس می‌فرستد:', 'shopharvester-pro' ); ?> <code dir="ltr"><?php echo esc_html( $webhook_url ); ?></code></li>
            </ul>
        </li>
        <li><strong><?php esc_html_e( 'کاری گیر کرده:', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( '«اجرای برداشت» ← دکمه‌ی «لغو» روبه‌روی آن کار.', 'shopharvester-pro' ); ?></li>
        <li><strong><?php esc_html_e( 'محصولات اشتباه در هم ادغام می‌شوند (مارکت‌پلیس):', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( '«آستانه تطبیق» را در تنظیمات بالاتر ببرید (مثلاً ۹۵). برعکس، اگر ادغام نمی‌شوند پایین‌تر (۸۰).', 'shopharvester-pro' ); ?></li>
        <li><strong><?php esc_html_e( 'پنل کند شده / صفحات باز نمی‌شوند:', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( 'معمولاً انباشت صف Action Scheduler. یک صفحه‌ی افزونه را باز کنید (خودترمیمی اجرا می‌شود) یا «پردازش صف اکنون» را بزنید.', 'shopharvester-pro' ); ?></li>
        <li><strong><?php esc_html_e( 'محصولات در فهرست آگهی‌ها (/all-ads) دیده می‌شوند، نه فقط خودِ فروشگاه:', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( 'در «تنظیمات حالت آگهی» تیکِ «برای هر محصول یک آگهی بساز» را بردارید و «برای هر فروشگاهِ مبدأ یک آگهیِ فروشگاه بساز» را روشن بگذارید. سپس در «خلاصه فروشگاه‌ها» دکمه‌ی «حذف آگهی‌های تکیِ محصولات» را بزنید تا آگهی‌های محصولاتِ قبلی به زباله‌دان بروند (محصولاتِ ووکامرس دست‌نخورده می‌مانند).', 'shopharvester-pro' ); ?></li>
        <li><strong><?php esc_html_e( 'محصولِ منتشرشده سئو نمی‌شود / چراغ Yoast سبز نیست:', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( 'در «خلاصه فروشگاه‌ها» دکمه‌ی «بهینه‌سازی سئوی همه‌ی محصولات» را بزنید. برای هر محصول عبارتِ کانونی، توضیحِ متا، عنوانِ سئو، متنِ توضیحاتِ کافی با تیترهای H2 و متنِ جایگزینِ تصاویر تنظیم می‌شود. اگر هوش مصنوعی در «تنظیمات» فعال و دارای کلید باشد متن‌ها را می‌نویسد؛ وگرنه از قالبِ مبتنی‌بر داده‌های واقعی استفاده می‌شود. محصولاتِ تازه هنگام برداشت خودکار بهینه می‌شوند.', 'shopharvester-pro' ); ?></li>
        <li><strong><?php esc_html_e( 'قیمت اشتباه/خیلی بزرگ ثبت شده (دو قیمت به‌هم چسبیده، مثلاً ۳۷۹۸۰۰۰۳۴۲۰۰۰۰):', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( 'از نسخه ۳.۴.۷ به‌طور قطعی رفع شده (علتش پیدا شد: تفکیکِ نادرستِ دو عددِ کنارِ هم در کارتِ محصول). برای محصولاتِ قبلاً خراب‌شده، در «خلاصه فروشگاه‌ها» دکمه‌ی «اصلاح قیمت‌های به‌هم‌چسبیده» را بزنید؛ اگر محصولی اصلاح نشد، همان فروشگاه را دوباره برداشت کنید.', 'shopharvester-pro' ); ?></li>
        <li><strong><?php esc_html_e( 'برای یک دسته که خودم ساخته بودم، افزونه دوباره یک دسته با همان نام ساخته:', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( 'از نسخه ۳.۴.۷ رفع شده — الان دسته‌ی هم‌نام را در هر جای درخت (نه فقط زیرِ همان والد) پیدا و دوباره استفاده می‌کند. برای دسته‌های تکراریِ قبلی، «نگاشت دسته‌ها» ← «ادغام دسته‌های تکراریِ هم‌نام» را بزنید.', 'shopharvester-pro' ); ?></li>
        <li><strong><?php esc_html_e( 'فروشگاه/آگهیِ تازه ۴۰۴ می‌دهد (حالت آگهی):', 'shopharvester-pro' ); ?></strong>
            <ul>
                <li><?php esc_html_e( 'اگر فروشگاه «در انتظار بررسی» ساخته شده، تا تأیید نشدن در سایت ۴۰۴ می‌دهد. یا در «تنظیمات حالت آگهی» گزینه‌ی «وضعیت فروشگاه» را روی «منتشرشده» بگذارید، یا فروشگاه را از پنل آگهی‌ها تأیید کنید.', 'shopharvester-pro' ); ?></li>
                <li><?php esc_html_e( 'اگر منتشرشده است ولی باز ۴۰۴ می‌دهد: قوانین بازنویسی باید تازه شوند. افزونه از نسخه ۳.۱.۶ این کار را خودکار انجام می‌دهد؛ اگر دسترسی به «پیوندهای یکتا» ندارید، در phpMyAdmin این را بزنید: DELETE FROM wp_options WHERE option_name = \'rewrite_rules\'; سپس یک صفحه از سایت را باز کنید.', 'shopharvester-pro' ); ?></li>
            </ul>
        </li>
        <li><strong><?php esc_html_e( 'صفحه‌ی تکِ محصول یا دسته‌بندی ۴۰۴ می‌دهد (ولی محصول در جستجو هست):', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( 'قوانینِ بازنویسیِ ووکامرس ناقص شده. از نسخه ۳.۳.۸ افزونه خودش ترمیم می‌کند (چند بار صفحه‌ی سایت را باز کنید). رفعِ فوری: در phpMyAdmin بزنید DELETE FROM wp_options WHERE option_name = \'rewrite_rules\'; و یک صفحه را باز کنید.', 'shopharvester-pro' ); ?></li>
        <li><strong><?php esc_html_e( 'عکس محصولات در فهرست/صفحه‌ی فروشگاه نمایش داده نمی‌شود:', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( 'هاست بعضی سایزهای میانیِ WebP را نمی‌سازد. از ۳.۳.۸ تصاویرِ جدید به JPEG تبدیل می‌شوند. برای تصاویرِ قبلی: افزونه‌ی «Regenerate Thumbnails» را نصب و اجرا کنید، یا همان فروشگاه‌ها را دوباره برداشت کنید.', 'shopharvester-pro' ); ?></li>
        <li><strong><?php esc_html_e( 'صفحه‌ی فروشگاه‌ها خالی است / محصولات در پروفایل فروشنده دیده نمی‌شوند:', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( 'رفع شد در نسخه ۳.۳.۷ — محصولات باید به نامِ فروشنده‌ی فروشگاهِ مبدأ ثبت شوند تا صفحه‌ی فروشگاهِ WCFM آن‌ها را نشان دهد. پس از بروزرسانی، افزونه خودش در پس‌زمینه نویسنده‌ی همه‌ی محصولاتِ قدیمی را اصلاح می‌کند (چند دقیقه با هر بار باز کردنِ پیشخوان). شرط: تیک «ساخت فروشنده‌ی WCFM» روشن باشد و محصولات «منتشر» شده باشند (نه پیش‌نویس).', 'shopharvester-pro' ); ?></li>
        <li><strong><?php esc_html_e( 'خطای «مدیریت انبار برای محصولات خارجی ممکن نیست»:', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( 'برطرف شد در نسخه ۳.۳.۶ — محصولاتِ خارجی (لینک به سایت فروشنده) اصلاً موجودی ندارند و افزونه دیگر برایشان وضعیت انبار تنظیم نمی‌کند.', 'shopharvester-pro' ); ?></li>
        <li><strong><?php esc_html_e( 'لاگ پر از «ساخت دسته … ناموفق بود» شده:', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( 'معمولاً یک افزونه‌ی فارسی‌ساز (مثل WP-Parsidate) در ساختِ واژه‌ها اختلال می‌کند. از نسخه ۳.۳.۶ هر دسته فقط یک‌بار در هر اجرا تلاش و ثبت می‌شود و اگر ردیف ساخته شده باشد مستقیم از دیتابیس پیدا می‌شود. برای رفعِ کامل، در تنظیماتِ آن افزونه «اصلاح نامک/اسلاگ» را خاموش کنید.', 'shopharvester-pro' ); ?></li>
        <li><strong><?php esc_html_e( 'قیمت‌ها ۱۰ برابر (یا یک‌دهم) ثبت می‌شوند:', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( 'از نسخه ۳.۳.۵ افزونه واحد قیمت را تشخیص می‌دهد و همه‌چیز را به تومان یکسان می‌کند: مقدار ماشینی سایت مبدأ (JSON-LD / data-price / متادیتا) اگر ریالی باشد (priceCurrency=IRR یا واژه‌ی «ریال»، یا ~۱۰ برابرِ قیمتِ تومانیِ نمایش‌داده‌شده) بر ۱۰ تقسیم می‌شود. محصولاتِ قبل از این نسخه را دوباره برداشت کنید تا قیمتشان اصلاح شود.', 'shopharvester-pro' ); ?></li>
        <li><strong><?php esc_html_e( 'نویسنده‌ی محصولات چه کسی است؟', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( 'در حالت عادی/ووکامرس: همان کاربری که برداشت را اجرا کرده. در حالت «آگهی + فروشنده»: فروشنده‌ی همان فروشگاه (کاربر واقعی). هرگز کاربر ۰ یا نام افزونه.', 'shopharvester-pro' ); ?></li>
        <li><strong><?php esc_html_e( 'خطای «کلید هوش مصنوعی تنظیم نشده»:', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( 'یعنی گزینه‌ی هوش مصنوعی روشن است ولی کلید API وارد نشده. یا کلید را در تنظیمات وارد کنید، یا تیک «فعال‌سازی هوش مصنوعی» را بردارید. در این حالت محصولات با داده‌ی خام ذخیره می‌شوند و برداشت متوقف نمی‌شود.', 'shopharvester-pro' ); ?></li>
        <li><strong><?php esc_html_e( 'داشبورد می‌گوید خطا دارم ولی کجاست؟', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( 'روی کارت قرمز «خطاهای ۲۴ ساعت اخیر» کلیک کنید تا مستقیم به «گزارش‌ها» با فیلتر ERROR بروید. فهرست خطاهای اخیر هم پایین‌تر در همان داشبورد نشان داده می‌شود. اگر شمارنده صفر شد یعنی خطاها قدیمی‌تر از ۲۴ ساعت بوده‌اند.', 'shopharvester-pro' ); ?></li>
        <li><strong><?php esc_html_e( 'برای پشتیبانی:', 'shopharvester-pro' ); ?></strong> <?php esc_html_e( '«گزارش سیستم» ← «کپی گزارش» یا «دانلود لاگ کامل».', 'shopharvester-pro' ); ?></li>
    </ul>
</div>
