#!/usr/bin/env python3
"""ShopHarvester Pro — headless runner.

Standalone worker that polls the WordPress REST API for pending scrape jobs,
renders each target URL with a real Chromium browser (handling JavaScript
single-page shops, infinite scroll, "load more" buttons and pagination),
extracts store + product data, and posts the normalized payload back to
WordPress, which stores every product strictly as a draft.

Quick start (Windows PowerShell):
    pip install playwright requests beautifulsoup4
    playwright install chromium
    $env:SHP_BASE_URL = "https://example.com"          # your WordPress site
    $env:SHP_SECRET   = "<کلید امنیتی وب‌هوک از تنظیمات>"
    python playwright_runner.py

Quick start (macOS / Linux):
    export SHP_BASE_URL="https://example.com"
    export SHP_SECRET="<webhook secret>"
    python3 playwright_runner.py

Environment variables:
    SHP_BASE_URL        WordPress site root (no trailing slash needed)
    SHP_SECRET          webhook secret (Settings → وب‌هوک و گزارش‌ها)
    SHP_POLL_INTERVAL   seconds between polls when idle (default 20)
    SHP_MAX_PAGES       max pagination pages to follow (default 10)
    SHP_MAX_PRODUCTS    max product pages to open per job (default 80)
    SHP_SCROLL_PASSES   infinite-scroll iterations on listing pages (default 25)
    SHP_HEADFUL         set to 1 to watch the browser (debugging)
    SHP_BLOCK_MEDIA     set to 0 to keep loading images/fonts (default: blocked)
"""

from __future__ import annotations

import os
import re
import time
import json
import logging
from urllib.parse import urljoin, urlparse

import requests
from bs4 import BeautifulSoup
from playwright.sync_api import sync_playwright, TimeoutError as PWTimeout

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")
log = logging.getLogger("shp-runner")

BASE_URL = os.environ.get("SHP_BASE_URL", "http://localhost").rstrip("/")
SECRET = os.environ.get("SHP_SECRET", "")
API = f"{BASE_URL}/wp-json/shopharvester/v1"
HEADERS = {"X-ShopHarvester-Secret": SECRET, "Content-Type": "application/json"}

POLL_INTERVAL = int(os.environ.get("SHP_POLL_INTERVAL", "20"))
MAX_PAGES = int(os.environ.get("SHP_MAX_PAGES", "10"))
MAX_PRODUCTS = int(os.environ.get("SHP_MAX_PRODUCTS", "80"))
SCROLL_PASSES = int(os.environ.get("SHP_SCROLL_PASSES", "25"))
HEADFUL = os.environ.get("SHP_HEADFUL", "") in ("1", "true", "yes")
BLOCK_MEDIA = os.environ.get("SHP_BLOCK_MEDIA", "1") in ("1", "true", "yes")

LOAD_MORE_TEXT = re.compile(
    r"(load\s*more|show\s*more|view\s*more|بیشتر|نمایش بیشتر|مشاهده بیشتر|محصولات بیشتر|بارگذاری)",
    re.I,
)
PRODUCT_URL_HINT = re.compile(
    r"/(product|products|p|dp|item|goods|kala|mahsul|محصول|کالا|%D9%85%D8%AD%D8%B5%D9%88%D9%84|%DA%A9%D8%A7%D9%84%D8%A7)[/\-]",
    re.I,
)
NON_PRODUCT_URL = re.compile(
    r"(add-to-cart=|/cart|/checkout|/login|/register|/account|/wishlist|/compare"
    r"|/category|/product-category|/tag|/blog|/faq|/contact|/about|\.(?:jpg|png|webp|pdf|xml|json)$)",
    re.I,
)


# --------------------------------------------------------------------------- #
#  REST helpers
# --------------------------------------------------------------------------- #
def fetch_pending() -> list[dict]:
    resp = requests.get(f"{API}/jobs/pending", headers=HEADERS, timeout=30)
    resp.raise_for_status()
    return resp.json().get("jobs", [])


def submit_result(job_id: int, payload: dict) -> None:
    resp = requests.post(
        f"{API}/jobs/{job_id}/result",
        headers=HEADERS,
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        timeout=180,
    )
    log.info("job %s -> %s %s", job_id, resp.status_code, resp.text[:200])


def clean_text(value) -> str:
    return re.sub(r"\s+", " ", str(value or "")).strip()


# --------------------------------------------------------------------------- #
#  JSON-LD + structured extraction
# --------------------------------------------------------------------------- #
def parse_json_ld(soup: BeautifulSoup) -> list[dict]:
    nodes: list[dict] = []
    for tag in soup.select('script[type="application/ld+json"]'):
        try:
            data = json.loads(tag.string or "{}")
        except (ValueError, TypeError):
            continue
        candidates = data if isinstance(data, list) else [data]
        for cand in candidates:
            if not isinstance(cand, dict):
                continue
            if isinstance(cand.get("@graph"), list):
                nodes.extend(d for d in cand["@graph"] if isinstance(d, dict))
            else:
                nodes.append(cand)
    return nodes


def _is_type(node: dict, wanted: str) -> bool:
    t = node.get("@type", "")
    return wanted in (t if isinstance(t, list) else [t])


def extract_breadcrumb(soup: BeautifulSoup) -> list[str]:
    for node in parse_json_ld(soup):
        if not _is_type(node, "BreadcrumbList"):
            continue
        items = sorted(node.get("itemListElement", []), key=lambda i: i.get("position", 0))
        names = []
        for i in items:
            label = i.get("name") or (i.get("item", {}) or {}).get("name")
            label = clean_text(label)
            if label:
                names.append(label)
        return names[1:-1] if len(names) > 2 else names[1:]

    crumbs = soup.select('[class*="breadcrumb"] a, [class*="crumb"] a, nav[aria-label*="readcrumb"] a')
    names = [clean_text(a.get_text()) for a in crumbs if clean_text(a.get_text())]
    names = [n for n in names if n.lower() not in ("خانه", "صفحه اصلی", "home")]
    return names


def _images_from_node(node: dict, base: str) -> list[str]:
    imgs = node.get("image", [])
    if isinstance(imgs, (str, dict)):
        imgs = [imgs]
    out = []
    for i in imgs:
        u = i.get("url") or i.get("contentUrl") if isinstance(i, dict) else i
        if u:
            out.append(urljoin(base, str(u)))
    return out


def _attrs_from_node(node: dict) -> dict:
    out = {}
    for prop in node.get("additionalProperty", []) or []:
        if isinstance(prop, dict) and prop.get("name"):
            out[clean_text(prop["name"])] = clean_text(prop.get("value", ""))
    for key, label in (("color", "رنگ"), ("material", "جنس"), ("size", "سایز")):
        if node.get(key):
            v = node[key]
            out[label] = clean_text(", ".join(v) if isinstance(v, list) else v)
    return out


def product_from_jsonld(node: dict, url: str, breadcrumb: list[str]) -> dict:
    offers = node.get("offers", {})
    if isinstance(offers, list):
        offers = offers[0] if offers else {}
    avail = str(offers.get("availability", ""))
    return {
        "title": clean_text(node.get("name")),
        "source_url": node.get("url") or url,
        "description": clean_text(node.get("description")),
        "sku": clean_text(node.get("sku") or node.get("mpn")),
        "brand": clean_text(
            node["brand"]["name"] if isinstance(node.get("brand"), dict) else node.get("brand")
        ),
        "price": clean_text(offers.get("price") or offers.get("lowPrice") or ""),
        "stock": "instock" if "InStock" in avail else ("outofstock" if avail else ""),
        "categories": [clean_text(node["category"])] if node.get("category") else [],
        "categories_path": [breadcrumb] if breadcrumb else [],
        "collections": [],
        "attributes": _attrs_from_node(node),
        "images": _images_from_node(node, url),
    }


# --------------------------------------------------------------------------- #
#  Store + DOM fallback extraction
# --------------------------------------------------------------------------- #
def extract_store(soup: BeautifulSoup, url: str) -> dict:
    host = urlparse(url).netloc
    ld = parse_json_ld(soup)
    org = next(
        (n for n in ld if _is_type(n, "Organization") or _is_type(n, "Store") or _is_type(n, "OnlineStore")),
        {},
    )
    mail = soup.select_one('a[href^="mailto:"]')
    tel = soup.select_one('a[href^="tel:"]')

    socials = {}
    for a in soup.select("a[href]"):
        href = a.get("href", "")
        for net in ("instagram", "telegram", "t.me", "twitter", "facebook", "linkedin", "aparat", "youtube"):
            if net in href and net not in socials:
                socials[net] = href

    logo = ""
    logo_img = soup.select_one('img[class*="logo"], .logo img, header img, [class*="logo"] img')
    if logo_img and logo_img.get("src"):
        logo = urljoin(url, logo_img["src"])

    og_name = soup.find("meta", property="og:site_name")
    return {
        "name": clean_text(org.get("name"))
        or clean_text(og_name.get("content") if og_name else "")
        or clean_text(soup.title.string if soup.title else host),
        "brand": clean_text(
            org.get("brand", {}).get("name") if isinstance(org.get("brand"), dict) else org.get("brand")
        ),
        "domain": host,
        "email": (mail.get("href", "").replace("mailto:", "").split("?")[0] if mail else "")
        or clean_text(org.get("email")),
        "phone": (tel.get("href", "").replace("tel:", "") if tel else "") or clean_text(org.get("telephone")),
        "address": clean_text(json.dumps(org.get("address"), ensure_ascii=False) if org.get("address") else ""),
        "socials": socials,
        "logo": logo,
        "description": clean_text((soup.find("meta", attrs={"name": "description"}) or {}).get("content", "")),
        "source_url": url,
    }


def find_price_dom(soup: BeautifulSoup) -> str:
    for sel in (
        '[itemprop="price"]',
        '[class*="final-price"]',
        '[class*="sale-price"]',
        '[class*="product-price"]',
        '[class*="price"]',
    ):
        el = soup.select_one(sel)
        if el:
            txt = clean_text(el.get("content") or el.get_text())
            if re.search(r"[\d۰-۹]", txt):
                return txt
    for el in soup.find_all(string=re.compile(r"(تومان|ریال)")):
        txt = clean_text(el)
        if len(txt) < 40 and re.search(r"[\d۰-۹]", txt):
            return txt
    return ""


def collect_images_dom(soup: BeautifulSoup, url: str) -> list[str]:
    out = []
    for m in soup.select('meta[property="og:image"], meta[property="og:image:url"]'):
        if m.get("content"):
            out.append(m["content"])
    for img in soup.select(
        '[class*="gallery"] img, [class*="slider"] img, [class*="swiper"] img, '
        '[class*="product-image"] img, [class*="product-gallery"] img, img[itemprop="image"]'
    ):
        src = (
            img.get("data-large_image")
            or img.get("data-zoom-image")
            or img.get("data-src")
            or img.get("src")
        )
        if src and "data:image" not in src:
            out.append(src)
    seen, res = set(), []
    for u in out:
        au = urljoin(url, u)
        if au not in seen and not re.search(r"(sprite|placeholder|logo|icon|blank|spacer)", au, re.I):
            seen.add(au)
            res.append(au)
    return res


def extract_single_product(soup: BeautifulSoup, url: str) -> dict | None:
    breadcrumb = extract_breadcrumb(soup)
    for node in parse_json_ld(soup):
        if _is_type(node, "Product"):
            p = product_from_jsonld(node, url, breadcrumb)
            if not p["images"]:
                p["images"] = collect_images_dom(soup, url)
            if not p["price"]:
                p["price"] = find_price_dom(soup)
            return p

    og_type = soup.find("meta", property="og:type")
    og_title = soup.find("meta", property="og:title")
    h1 = soup.find("h1")
    title = clean_text((og_title.get("content") if og_title else "") or (h1.get_text() if h1 else ""))
    is_product = (og_type and "product" in og_type.get("content", "").lower()) or bool(
        soup.select_one('[class*="product_title"], [class*="single-product"], form[action*="add-to-cart"]')
    )
    if not title or not is_product:
        return None

    og_desc = soup.find("meta", property="og:description")
    return {
        "title": title,
        "source_url": url,
        "description": clean_text(og_desc.get("content") if og_desc else ""),
        "sku": "",
        "brand": "",
        "price": find_price_dom(soup),
        "stock": "",
        "categories": [],
        "categories_path": [breadcrumb] if breadcrumb else [],
        "collections": [],
        "attributes": {},
        "images": collect_images_dom(soup, url),
    }


def extract_listing_products(soup: BeautifulSoup, url: str) -> list[dict]:
    breadcrumb = extract_breadcrumb(soup)
    products = []

    for node in parse_json_ld(soup):
        if _is_type(node, "Product"):
            products.append(product_from_jsonld(node, url, breadcrumb))
    if products:
        return products

    for card in soup.select(
        'li.product, [class*="product-item"], [class*="product-card"], [class*="product_item"], '
        '[class*="product-box"], [class*="ProductCard"], [class*="product-wrapper"]'
    ):
        link = card.select_one("a[href]")
        title = card.select_one('[class*="title"], [class*="name"], h2, h3, h4')
        price = card.select_one('[class*="price"]')
        img = card.select_one("img")
        if not (link and title):
            continue
        href = urljoin(url, link.get("href", ""))
        if NON_PRODUCT_URL.search(href):
            continue
        products.append(
            {
                "title": clean_text(title.get_text()),
                "source_url": href,
                "description": "",
                "sku": "",
                "brand": "",
                "price": clean_text(price.get_text() if price else ""),
                "stock": "",
                "categories": [],
                "categories_path": [breadcrumb] if breadcrumb else [],
                "collections": [],
                "attributes": {},
                "images": [urljoin(url, img.get("src") or img.get("data-src") or "")] if img else [],
            }
        )
    return products


def discover_product_links(soup: BeautifulSoup, base: str) -> list[str]:
    host = urlparse(base).netloc
    links: dict[str, None] = {}
    for a in soup.select("a[href]"):
        href = urljoin(base, a.get("href", "")).split("#")[0]
        if urlparse(href).netloc != host:
            continue
        if NON_PRODUCT_URL.search(href):
            continue
        in_card = a.find_parent(class_=re.compile(r"product", re.I)) is not None
        if in_card or PRODUCT_URL_HINT.search(href):
            links[href] = None
    return list(links)


def find_next_page(soup: BeautifulSoup, url: str) -> str | None:
    nxt = soup.select_one('a[rel="next"], link[rel="next"], .pagination .next a, a.next.page-numbers')
    href = nxt.get("href") if nxt else None
    return urljoin(url, href) if href else None


# --------------------------------------------------------------------------- #
#  Browser driving
# --------------------------------------------------------------------------- #
def auto_scroll(page) -> None:
    last = 0
    for _ in range(SCROLL_PASSES):
        page.mouse.wheel(0, 4000)
        page.wait_for_timeout(700)
        height = page.evaluate("document.body.scrollHeight")
        if height == last:
            break
        last = height


def click_load_more(page) -> None:
    for _ in range(15):
        clicked = page.evaluate(
            """() => {
                const rx = /load\\s*more|show\\s*more|بیشتر|نمایش بیشتر|مشاهده بیشتر|بارگذاری/i;
                const el = [...document.querySelectorAll('button, a, span, div')]
                    .find(e => rx.test((e.innerText || '').trim()) && e.offsetParent !== null);
                if (el) { el.click(); return true; }
                return false;
            }"""
        )
        if not clicked:
            return
        page.wait_for_timeout(1800)


def render(page, url: str) -> BeautifulSoup:
    try:
        page.goto(url, wait_until="domcontentloaded", timeout=60000)
    except PWTimeout:
        log.warning("goto timeout, using whatever loaded: %s", url)
    page.wait_for_timeout(2500)
    try:
        page.wait_for_load_state("networkidle", timeout=15000)
    except PWTimeout:
        pass
    return BeautifulSoup(page.content(), "html.parser")


def scrape(url: str) -> dict:
    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=not HEADFUL)
        context = browser.new_context(
            user_agent="Mozilla/5.0 (compatible; ShopHarvesterBot/1.0; +https://mahsen81.ir)",
            locale="fa-IR",
            viewport={"width": 1366, "height": 900},
        )
        if BLOCK_MEDIA:
            context.route(
                re.compile(r"\.(png|jpe?g|gif|webp|svg|woff2?|ttf|mp4|avif)(\?|$)", re.I),
                lambda route: route.abort(),
            )
        page = context.new_page()

        soup = render(page, url)
        store = extract_store(soup, url)

        products: dict[str, dict] = {}
        no_url: list[dict] = []
        follow: dict[str, None] = {}

        current_url, current_soup = url, soup
        for _ in range(MAX_PAGES):
            auto_scroll(page)
            click_load_more(page)
            current_soup = BeautifulSoup(page.content(), "html.parser")

            for p in extract_listing_products(current_soup, current_url):
                if p["source_url"]:
                    products.setdefault(p["source_url"], p)
                elif p["title"]:
                    no_url.append(p)
            for link in discover_product_links(current_soup, current_url):
                follow.setdefault(link, None)

            nxt = find_next_page(current_soup, current_url)
            if not nxt or nxt == current_url:
                break
            page.goto(nxt, wait_until="domcontentloaded", timeout=60000)
            page.wait_for_timeout(2000)
            current_url = nxt

        # If the submitted URL is itself a product page, keep just that.
        direct = extract_single_product(soup, url)
        if direct and not products and not no_url:
            browser.close()
            return {"store": store, "products": [direct]}

        # Follow product pages for full data.
        targets = list(dict.fromkeys(list(products.keys()) + list(follow.keys())))
        done = 0
        for purl in targets:
            if done >= MAX_PRODUCTS:
                break
            done += 1
            existing = products.get(purl, {})
            if existing.get("price") and existing.get("description"):
                continue
            try:
                psoup = render(page, purl)
            except Exception as exc:  # noqa: BLE001
                log.warning("product page failed %s: %s", purl, exc)
                continue
            single = extract_single_product(psoup, purl)
            if not single or not single["title"]:
                continue
            merged = {**existing, **{k: v for k, v in single.items() if v}}
            products[purl] = merged

        browser.close()

    return {"store": store, "products": list(products.values()) + no_url}


# --------------------------------------------------------------------------- #
#  Main loop
# --------------------------------------------------------------------------- #
def main() -> None:
    if not SECRET:
        raise SystemExit("SHP_SECRET تنظیم نشده است. کلید را از صفحه تنظیمات کپی کنید.")

    log.info("runner started against %s (headful=%s, block_media=%s)", API, HEADFUL, BLOCK_MEDIA)
    while True:
        try:
            jobs = fetch_pending()
        except requests.RequestException as exc:
            log.warning("poll failed: %s", exc)
            time.sleep(POLL_INTERVAL)
            continue

        if not jobs:
            time.sleep(POLL_INTERVAL)
            continue

        for job in jobs:
            log.info("processing job %s: %s", job["id"], job["target_url"])
            try:
                payload = scrape(job["target_url"])
                log.info("job %s -> %d products", job["id"], len(payload["products"]))
                submit_result(job["id"], payload)
            except Exception as exc:  # noqa: BLE001 - report every failure upstream
                log.exception("job %s failed", job["id"])
                submit_result(
                    job["id"],
                    {"store": {"source_url": job["target_url"]}, "products": [], "error": str(exc)},
                )

        time.sleep(2)


if __name__ == "__main__":
    main()
