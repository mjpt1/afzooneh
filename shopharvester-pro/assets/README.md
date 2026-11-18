# نشان‌های افزونه (Plugin assets)

فایل‌های برند:

| فایل | کاربرد |
|---|---|
| `icon.svg` | آیکون مربعی افزونه — گرادیان بنفش/نیلی، کیسه‌ی خرید + فلش رو به پایین (برداشت) + میله‌های مقایسه |
| `logo.svg` | نشان افقی (آیکون + نام + نشان PRO + شعار فارسی) — برای هدر پنل و بازاریابی |
| `banner.svg` | بنر تیره برای صفحه‌ی معرفی افزونه |

## ساخت نسخه‌ی PNG برای WordPress.org / مخزن

اگر افزونه در مخزن WordPress.org منتشر شود، به فایل‌های PNG با نام‌های استاندارد نیاز است (در پوشه‌ی `assets/` مخزن SVN):

```
assets/icon-128x128.png      ← از icon.svg با ابعاد 128×128
assets/icon-256x256.png      ← از icon.svg با ابعاد 256×256
assets/icon.svg              ← همین فایل (WordPress.org از SVG هم پشتیبانی می‌کند)
assets/banner-772x250.png    ← از banner.svg
assets/banner-1544x500.png   ← از banner.svg (رتینا)
```

تبدیل با ابزار خط فرمان (نیازمند `rsvg-convert` یا `inkscape` یا ImageMagick):

```bash
rsvg-convert -w 256 -h 256 icon.svg   -o icon-256x256.png
rsvg-convert -w 128 -h 128 icon.svg   -o icon-128x128.png
rsvg-convert -w 772 -h 250 banner.svg -o banner-772x250.png
rsvg-convert -w 1544 -h 500 banner.svg -o banner-1544x500.png
```

یا آنلاین: هر مبدل SVG→PNG (مثلاً cloudconvert) با همین ابعاد.

## رنگ‌های برند

- نیلی: `#6366f1`
- بنفش: `#8b5cf6`
- تیره (بنر): `#0f172a` → `#1e1b4b`
