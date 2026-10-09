#!/usr/bin/env python3
"""Builds the PDF manual (web/manual/qrtrack-manual.pdf) from the app's own Help page.

How it works
  1. Starts the real qrtrack binary on a scratch database and fills it with FICTIONAL demo data.
  2. Takes screenshots of the real screens with a headless browser.
  3. Reads the Help sections straight from the running /admin/help page, so the manual cannot differ from Help.
  4. Adds a cover, contents, task finder, appendices and an A-Z index, prints to A4 PDF with Chromium,
     finds each heading and term in the PDF to fill in real page numbers, and renders again.
  5. Adds bookmarks and metadata, including a hash of the sources, so a test can tell when Help has
     changed and the manual needs rebuilding.

Run it:   QRTRACK_BIN=/path/to/qrtrack GEOIP_DB=/path/to/city.mmdb python3 tools/manual/build_manual.py
Needs:    playwright (with Chromium), beautifulsoup4, pillow, pdfplumber, pypdf.
"""
import base64, datetime, hashlib, html, io, os, random, re, shutil, sqlite3, subprocess, sys, tempfile, time, urllib.request
from pathlib import Path

import pdfplumber
from bs4 import BeautifulSoup
from PIL import Image
from playwright.sync_api import sync_playwright
from pypdf import PdfReader, PdfWriter

ROOT = Path(__file__).resolve().parents[2]
BIN = os.environ.get("QRTRACK_BIN", "")
GEO = os.environ.get("GEOIP_DB", "")
OUT = Path(os.environ.get("OUT", ROOT / "web/manual/qrtrack-manual.pdf"))
PORT = int(os.environ.get("MANUAL_PORT", "18400"))
BASE = f"http://127.0.0.1:{PORT}"
PUBLIC = "https://qr.blakegroup.uk"
ADMIN_PW = "Manual-Demo-Passw0rd!"
SOURCES = ["web/templates/help.html", "internal/web/tracking.go", "internal/web/help.go", "internal/qrtypes/qrtypes.go",
           "tools/manual/build_manual.py", "tools/manual/manual.css"]
TITLE = "QR Codes and Link Pages: User Manual"
random.seed(20261008)


def source_hash():
    h = hashlib.sha256()
    for rel in SOURCES:
        h.update((ROOT / rel).read_bytes())
        h.update(b"\0")
    return h.hexdigest()


def b64(path, mime):
    return f"data:{mime};base64," + base64.b64encode(Path(path).read_bytes()).decode()


def img_uri(name):
    p = ROOT / "web/static/img" / name
    return b64(p, "image/webp" if name.endswith(".webp") else "image/png")


# ---------------------------------------------------------------- the app and its demo data
def start_server(tmp):
    env = dict(os.environ, LISTEN_ADDR=f"127.0.0.1:{PORT}", DB_PATH=str(tmp / "demo.db"), BASE_URL=PUBLIC,
               ADMIN_USER="admin", ADMIN_PASSWORD="Manual-Seed-pw-123", GEOIP_DB=GEO)
    if not GEO:
        env.pop("GEOIP_DB")
    proc = subprocess.Popen([BIN], env=env, stdout=open(tmp / "app.log", "w"), stderr=subprocess.STDOUT)
    for _ in range(60):
        try:
            urllib.request.urlopen(BASE + "/healthz", timeout=1)
            return proc
        except Exception:
            time.sleep(0.25)
    proc.kill()
    raise SystemExit("the app did not start: " + (tmp / "app.log").read_text()[-400:])


def fill(pg, fields):
    for k, v in fields.items():
        loc = pg.locator(f"[name='{k}']").first
        kind = loc.evaluate("e => e.tagName + ':' + (e.type || '')")
        if kind.startswith("SELECT"):
            loc.select_option(v)
        elif "checkbox" in kind:
            loc.check() if v else loc.uncheck()
        elif "color" in kind:
            loc.evaluate("(e, v) => { e.value = v; e.dispatchEvent(new Event('input', {bubbles: true})); }", v)
        else:
            loc.fill(v)


def seed_ui(pg):
    pg.goto(BASE + "/admin/login")
    pg.fill("input[name=username]", "admin"); pg.fill("input[name=password]", "Manual-Seed-pw-123"); pg.click("button.primary")
    pg.fill("input[name=current]", "Manual-Seed-pw-123"); pg.fill("input[name=new]", ADMIN_PW); pg.fill("input[name=confirm]", ADMIN_PW)
    pg.click("button.primary"); pg.wait_for_url(BASE + "/admin/")

    def make(kind, typ, fields, common):
        pg.goto(f"{BASE}/admin/links/new?kind={kind}&type={typ}")
        fill(pg, {**common, **fields})
        pg.click("button.primary:has-text('Create QR code')"); pg.wait_for_load_state()
        assert "/admin/links/" in pg.url and not pg.url.endswith("/new"), f"could not create {typ}"

    dyn = {"window": "90d"}
    make("dynamic", "url", {"f_url": "https://www.blake-uk.com/category/aerials.html", "fg": "#0b2a6f", "pattern": "rounded", "eye": "rounded"},
         {**dyn, "label": "Autumn leaflet, front page", "campaign": "Leaflet"})
    make("dynamic", "url", {"f_url": "https://www.blake-uk.com/instruction-manuals.html"}, {**dyn, "label": "Instruction manuals poster", "campaign": "Exhibition stand"})
    make("dynamic", "url", {"f_url": "https://www.blake-uk.com/support.html", "max_scans": "500"}, {**dyn, "label": "Product box: technical support", "campaign": "Product box"})
    make("dynamic", "url", {"f_url": "https://www.blake-uk.com/trade.html", "link_password": "trade-only"}, {**dyn, "label": "Trade price list (password)", "campaign": "Trade counter"})
    make("dynamic", "app_stores", {"f_ios_url": "https://apps.apple.com/app/id1", "f_android_url": "https://play.google.com/store/apps/details?id=uk.example",
                                    "f_url": "https://www.example.com/app"}, {**dyn, "label": "Installer app: one code, right store", "campaign": "Product box"})
    make("dynamic", "smart_url", {"f_url": "https://www.blake-uk.com/", "f_r1_match": "country", "f_r1_value": "FR", "f_r1_url": "https://www.blake-uk.com/fr",
                                   "f_r2_match": "os", "f_r2_value": "iOS", "f_r2_url": "https://www.blake-uk.com/ios"}, {**dyn, "label": "Smart routing example", "campaign": "Exhibition stand"})
    make("dynamic", "vcard", {"f_first": "Alex", "f_last": "Example", "f_org": "Blake UK", "f_phone": "+441142235000", "f_email": "sales@example.com"}, {**dyn, "label": "Sales contact card", "campaign": "Trade counter"})
    make("static", "wifi", {"f_ssid": "Blake Guest", "f_password": "welcome-123", "f_security": "WPA"}, {"label": "Reception Wi-Fi", "campaign": "Reception"})
    make("static", "text", {"f_text": "Thank you for visiting."}, {"label": "Thank-you message", "campaign": "Reception"})
    # a saved design, the explicit way
    pg.goto(BASE + "/admin/templates/new")
    fill(pg, {"fg": "#0b2a6f", "pattern": "rounded", "eye": "circle", "frame": "box", "cta": "SCAN FOR MANUAL", "template_name": "Blake UK blue"})
    pg.click("button.primary:has-text('Save design')"); pg.wait_for_url("**/admin/templates?saved=*")
    pg.goto(BASE + "/admin/templates/new")
    fill(pg, {"fg": "#28225e", "pattern": "dots", "eye": "circle", "frame": "banner", "cta": "SCAN ME", "template_name": "Solwise indigo"})
    pg.click("button.primary:has-text('Save design')"); pg.wait_for_url("**/admin/templates?saved=*")
    # one link page per company, in a different theme each, and a QR code for the first
    for brand, theme in (("visionplus", "midnight"), ("blake-uk", "daylight"), ("solwise", "bold")):
        pg.goto(f"{BASE}/admin/pages/new?brand={brand}&example=1")
        pg.check(f"input[name=theme][value={theme}]"); pg.click("button.primary:has-text('Create page')"); pg.wait_for_url("**/admin/pages/*")
        if brand == "visionplus":
            pg.click("button:has-text('Make a QR code for this page')"); pg.wait_for_load_state()
    for name in ("sales", "support"):
        pg.goto(BASE + "/admin/users"); pg.fill("form[action='/admin/users'] input[name=username]", name)
        pg.select_option("form[action='/admin/users'] select[name=role]", "member"); pg.fill("form[action='/admin/users'] input[name=password]", "demo-temp-password-1")
        pg.locator("form[action='/admin/users'] button.primary").click(); pg.wait_for_load_state()


PLACES = [("GB", "United Kingdom", "England", "Sheffield", 40), ("GB", "United Kingdom", "England", "Leeds", 12), ("GB", "United Kingdom", "England", "London", 18),
          ("GB", "United Kingdom", "Scotland", "Glasgow", 6), ("IE", "Ireland", "Leinster", "Dublin", 6), ("FR", "France", "Ile-de-France", "Paris", 5),
          ("DE", "Germany", "Berlin", "Berlin", 4), ("NL", "Netherlands", "North Holland", "Amsterdam", 3), ("US", "United States", "California", "San Francisco", 2)]
DEVICES = [("mobile", "iOS", "Safari", "Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1", 44),
           ("mobile", "Android", "Chrome", "Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/124.0 Mobile Safari/537.36", 34),
           ("desktop", "Windows", "Edge", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36 Edg/124.0", 11),
           ("desktop", "macOS", "Safari", "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17.4 Safari/605.1.15", 6),
           ("tablet", "iOS", "Safari", "Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1", 5)]
LANGS = [("en-GB", 70), ("en-US", 8), ("fr-FR", 5), ("de-DE", 4), ("cy-GB", 3), ("en-IE", 6), ("nl-NL", 4)]
REFERERS = [("", 70), ("l.facebook.com", 9), ("www.google.com", 8), ("t.co", 4), ("www.linkedin.com", 5), ("l.instagram.com", 4)]


def pick(rows):
    total = sum(r[-1] for r in rows); x = random.uniform(0, total)
    for r in rows:
        x -= r[-1]
        if x <= 0:
            return r
    return rows[-1]


def when(days=21):
    d = datetime.datetime.now(datetime.timezone.utc).replace(tzinfo=None) - datetime.timedelta(days=random.random() ** 1.4 * days)
    d = d.replace(hour=int(random.triangular(7, 22, 13)), minute=random.randint(0, 59), second=random.randint(0, 59), microsecond=0)
    return d.strftime("%Y-%m-%dT%H:%M:%SZ")


def seed_data(dbpath):
    """Back-dated, entirely fictional scans, page views and clicks, so the charts have something to show."""
    c = sqlite3.connect(dbpath, timeout=20)
    links = c.execute("SELECT id, destination_url FROM links WHERE kind='dynamic' AND destination_url <> ''").fetchall()
    for lid, dest in links:
        for _ in range(random.randint(35, 260)):
            cc, cn, reg, city, _w = pick(PLACES); dev = pick(DEVICES); lang = pick(LANGS)[0]; ref = pick(REFERERS)[0]
            bot = random.random() < 0.04
            c.execute("INSERT INTO scans(link_id, scanned_at, ip_hash, ip, country, country_name, region, city, language, destination_url, device_class, os, browser, referer_host, user_agent, is_bot, is_unique) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                      (lid, when(), "%016x" % random.getrandbits(64), "", cc, cn, reg, city, lang, dest, "bot" if bot else dev[0], dev[1], dev[2], ref, dev[3], int(bot), int(random.random() < .72)))
        c.execute("UPDATE links SET scan_count = (SELECT COUNT(*) FROM scans WHERE link_id = links.id AND is_bot = 0) WHERE id = ?", (lid,))
    for pid, in c.execute("SELECT id FROM link_pages").fetchall():
        items = [r[0] for r in c.execute("SELECT id FROM link_page_items WHERE page_id=? ORDER BY position", (pid,))]
        weights = [max(1, 9 - i * 2) for i in range(len(items))]
        for _ in range(random.randint(90, 320)):
            cc, cn, _r, _c, _w = pick(PLACES); dev = pick(DEVICES); lang = pick(LANGS)[0]; ref = pick(REFERERS)[0]
            at = when(); src = "qr" if random.random() < .45 else "direct"; h = "%016x" % random.getrandbits(64)
            c.execute("INSERT INTO page_events(page_id,item_id,at,kind,source,ip_hash,country,country_name,device_class,os,browser,language,referer_host,is_bot,is_unique) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,?)",
                      (pid, 0, at, "view", src, h, cc, cn, dev[0], dev[1], dev[2], lang, ref, int(random.random() < .7)))
            if random.random() < .56 and items:
                c.execute("INSERT INTO page_events(page_id,item_id,at,kind,source,ip_hash,country,country_name,device_class,os,browser,language,referer_host,is_bot,is_unique) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,0)",
                          (pid, random.choices(items, weights)[0], at, "click", src, h, cc, cn, dev[0], dev[1], dev[2], lang, ref))
    c.commit(); c.close()


# ---------------------------------------------------------------- screenshots
def jpeg(png_bytes, path, maxw=1500):
    im = Image.open(io.BytesIO(png_bytes)).convert("RGB")
    if im.width > maxw:
        im = im.resize((maxw, int(im.height * maxw / im.width)), Image.LANCZOS)
    im.save(path, "JPEG", quality=84, optimize=True)
    return path


def shoot(browser, tmp):
    out = {}
    ctx = browser.new_context(viewport={"width": 1280, "height": 860}, device_scale_factor=1.25)
    pg = ctx.new_page()

    def snap(name, **kw):
        out[name] = jpeg(pg.screenshot(**kw), tmp / f"{name}.jpg")

    def snap_el(name, selector):
        out[name] = jpeg(pg.locator(selector).first.screenshot(), tmp / f"{name}.jpg")

    pg.goto(BASE + "/admin/login"); snap("login")
    pg.fill("input[name=username]", "admin"); pg.fill("input[name=password]", ADMIN_PW); pg.click("button.primary"); pg.wait_for_url(BASE + "/admin/")
    pg.goto(BASE + "/admin/"); snap("codes")
    pg.goto(BASE + "/admin/links/new"); snap("chooser")
    pg.goto(BASE + "/admin/links/new?kind=dynamic&type=url"); pg.wait_for_timeout(500); snap("form", clip={"x": 0, "y": 0, "width": 1280, "height": 860})
    snap_el("form_tracking", "section.card:has(h2:text-is('Tracking window'))")
    snap_el("form_limits", "section.card:has(h2:text-is('Limits and protection'))")
    snap_el("design_card", "#design")
    pg.goto(BASE + "/admin/templates"); pg.wait_for_timeout(500); snap("designs")
    pg.goto(BASE + "/admin/links/1"); pg.wait_for_timeout(400); snap("detail", clip={"x": 0, "y": 0, "width": 1280, "height": 900})
    snap_el("detail_stats", "section.card:has(h2:text-is('Scans'))")
    snap_el("detail_breakdowns", ".grid3")
    pg.goto(BASE + "/admin/links/6"); snap_el("smart", ".grid2 > section.card")
    pg.goto(BASE + "/admin/links/8"); snap("static_detail", clip={"x": 0, "y": 0, "width": 1280, "height": 760})
    pg.goto(BASE + "/admin/campaigns"); snap("campaigns")
    pg.goto(BASE + "/admin/bulk"); snap("bulk")
    pg.goto(BASE + "/admin/users"); snap("users")
    pg.goto(BASE + "/admin/pages"); snap("pages")
    pg.goto(BASE + "/admin/pages/1/edit"); pg.wait_for_timeout(900); snap("page_editor", clip={"x": 0, "y": 0, "width": 1280, "height": 1000})
    pg.goto(BASE + "/admin/pages/1"); pg.wait_for_timeout(400); snap("page_detail", clip={"x": 0, "y": 0, "width": 1280, "height": 1250})
    pg.goto(BASE + "/admin/password"); snap("password", clip={"x": 0, "y": 0, "width": 1280, "height": 560})
    pg.goto(BASE + "/admin/help"); snap("help", clip={"x": 0, "y": 0, "width": 1280, "height": 760})
    # the three public pages, side by side as a phone would show them
    ph = browser.new_context(viewport={"width": 390, "height": 844}, device_scale_factor=1.5, is_mobile=True).new_page()
    tiles = []
    for slug in ("visionplus-links", "blake-uk-links", "solwise-links"):
        ph.goto(f"{BASE}/l/{slug}"); ph.wait_for_timeout(1000)
        tiles.append(Image.open(io.BytesIO(ph.screenshot())).convert("RGB").crop((0, 0, 585, 1000)))
    sheet = Image.new("RGB", (585 * 3 + 80, 1000), (236, 239, 247))
    for i, t in enumerate(tiles):
        sheet.paste(t, (i * (585 + 40), 0))
    sheet.save(tmp / "public_pages.jpg", "JPEG", quality=86); out["public_pages"] = tmp / "public_pages.jpg"
    ctx.close()
    return out


# ---------------------------------------------------------------- the document
FIGURES = {  # section id -> [(shot, caption)]
    "codes": [("codes", "The Codes page: every QR code, with its type, campaign, destination, window and scan counts.")],
    "static-dynamic": [("chooser", "New QR code: choose a dynamic (tracked) or static (untracked) code, then a type."),
                       ("static_detail", "A static code's page says plainly that it is not tracked and cannot be changed.")],
    "creating": [("form", "The QR code form: details, what the code does, and a live preview of its design.")],
    "design": [("design_card", "The Design panel: colours, dot and corner styles, frame and text, logo, error correction and saved designs.")],
    "save-designs": [("designs", "Saved designs: reuse a look on any code, edit it, or delete it.")],
    "tracking": [("form_tracking", "Tracking window and what happens when it ends.")],
    "limits-password": [("form_limits", "Limits and protection: a scan limit and a password.")],
    "smart-routing": [("smart", "A smart-routing code lists its rules; the first match wins.")],
    "downloads-printing": [("detail", "A code's page: tracking address, the QR picture and its PNG and SVG downloads.")],
    "reading-stats": [("detail_stats", "Scans: totals and a chart by the day (UK time)."), ("detail_breakdowns", "Breakdowns by device, system, browser, language, country, region, town and referrer.")],
    "campaigns": [("campaigns", "Campaigns add up the scans of every code in each campaign.")],
    "bulk": [("bulk", "Bulk: one code per row of a CSV, returned as a ZIP.")],
    "link-pages": [("page_editor", "The link page editor: company, theme, page details and buttons, with a live preview."),
                   ("public_pages", "The same engine, three companies, three themes: VisionPlus (Midnight), Blake UK (Daylight), Solwise (Bold)."),
                   ("page_detail", "A link page's statistics: views, QR visits, clicks per button.")],
    "users": [("users", "Users: add people, change roles, reset passwords, and the activity log.")],
    "account": [("login", "The sign-in page."), ("password", "Choosing your own password.")],
}
TASKS = [("Make my first QR code", "quick-start"), ("Choose between a tracked and an untracked code", "static-dynamic"),
         ("See exactly what is recorded about visitors", "privacy"), ("Put a logo in the middle of a code", "design"),
         ("Save a design so I can reuse it", "save-designs"), ("Make a code stop after a date", "tracking"),
         ("Stop a code after 500 scans, or protect it with a password", "limits-password"),
         ("Send iPhone and Android users to different places", "smart-routing"), ("Print a code properly and test it", "downloads-printing"),
         ("Find out which poster or leaflet works best", "campaigns"), ("Download scans into a spreadsheet", "exports"),
         ("Make 200 product codes from a spreadsheet", "bulk"), ("Build a Linktree-style page for Blake UK, VisionPlus or Solwise", "link-pages"),
         ("Understand the difference between views and clicks", "link-pages"),
         ("Add a colleague, or reset their password", "users"), ("I am locked out or forgot my password", "account"),
         ("Check a limit (sizes, lengths, how many)", "limits"), ("Something is not working", "troubleshooting")]
TERMS = [  # (index entry, [search needles])
    ("Accent colour", ["accent colour"]), ("Activity log", ["activity log"]), ("Admin and member roles", ["member can", "role"]),
    ("App stores code", ["app stores"]), ("Bold theme", ["bold:", "bold theme", "bold ("]), ("Bots and link previews", ["bots", "robots"]),
    ("Bulk creation", ["bulk"]), ("Buttons on a link page", ["buttons"]), ("Campaigns", ["campaign"]), ("Clicks (link page)", ["button clicks", "clicks"]),
    ("Company (Blake UK, VisionPlus, Solwise)", ["visionplus"]), ("Contact card (vCard)", ["vcard"]),
    ("CSV export", ["csv"]), ("Daylight theme", ["daylight"]), ("Delete a code", ["delete"]), ("Design (colours, dots, corners)", ["dot style", "corner style"]),
    ("Disable a code", ["disable"]), ("Downloads (PNG and SVG)", ["svg"]), ("Dynamic code", ["dynamic code", "dynamic (tracked)"]),
    ("Email button", ["mailto:"]), ("Error correction", ["error correction"]), ("Event (calendar)", ["calendar event"]), ("Expired page", ["expired page"]),
    ("Fallback address", ["fallback address"]), ("Frame and text under the code", ["frame"]), ("Google Form and Google review", ["google form", "google review"]),
    ("Icons", ["icon"]), ("Language setting", ["language"]), ("Limits", ["limits at a glance", "limit"]),
    ("Link page", ["link page"]), ("Live preview", ["live preview"]), ("Location (map) code", ["latitude"]), ("Lockout", ["locked out", "lockout"]),
    ("Logo in the centre", ["logo"]), ("Midnight theme", ["midnight"]), ("Name and campaign", ["campaign"]), ("Passwords (a code)", ["password"]),
    ("Passwords (your account)", ["your own new one", "at least 12"]), ("Phone button", ["tel:"]), ("Pressed a button (percentage)", ["pressed a button"]), ("Scans, views and clicks", ["scans, views and clicks"]), ("Views and clicks: the difference", ["views or clicks"]), ("Printing", ["printing checklist"]),
    ("Privacy", ["privacy"]), ("QR code for a link page", ["make a qr code for this page"]), ("Quick start", ["quick start"]),
    ("Referrer", ["referrer"]), ("Reset a password", ["reset password"]), ("Retention", ["retention", "deleted automatically"]),
    ("Saved design", ["saved design"]), ("Scan limit", ["scan limit"]), ("Scrambled visitor token", ["scrambled visitor token", "visitor token"]),
    ("Sessions and signing out", ["session"]), ("Smart URL", ["smart url"]), ("SMS code", ["sms"]), ("Social icons row", ["social icons", "social tiles"]),
    ("Static code", ["static code", "static qr"]), ("Statistics", ["statistics"]), ("Themes", ["theme"]), ("Tracking address", ["tracking address"]),
    ("Tracking window", ["tracking window"]), ("Troubleshooting", ["troubleshooting"]), ("Unique visitors", ["unique"]),
    ("Users", ["users"]), ("What is tracked", ["what is tracked", "not recorded"]), ("WhatsApp code", ["whatsapp"]), ("Wi-Fi code", ["wi-fi"]),
]


def build_html(secs, shots, tocpages, termpages, css, matrix):
    n = {s["id"]: i for i, s in enumerate(secs, 1)}
    today = datetime.date.today().strftime("%-d %B %Y")
    def pg(i): return tocpages.get(i, "0")
    toc = "".join(f'<li><span class="t"><span class="num">{i}</span><a href="#{s["id"]}">{html.escape(s["title"])}</a></span><span class="dots"></span><span class="n">{pg(s["id"])}</span></li>' for i, s in enumerate(secs, 1))
    tasks = "".join(f'<tr><td>{html.escape(t)}</td><td><a href="#{sid}">Section {n[sid]}: {html.escape(next(s["title"] for s in secs if s["id"] == sid))}</a> (page {pg(sid)})</td></tr>' for t, sid in TASKS)
    letters = {}
    for term, _ in sorted(TERMS, key=lambda t: t[0].lower()):
        pgs = termpages.get(term, [])
        letters.setdefault(term[0].upper(), []).append(f'<div class="term"><span class="t">{html.escape(term)}</span><span class="dots"></span><span class="n">{", ".join(map(str, pgs[:6])) or "-"}</span></div>')
    index = "".join(f'<div class="letter">{k}</div>' + "".join(v) for k, v in sorted(letters.items()))
    body = []
    for s in secs:
        figs = "".join(f'<figure><img src="{b64(shots[name], "image/jpeg")}" alt="{html.escape(cap)}"><figcaption>{html.escape(cap)}</figcaption></figure>' for name, cap in FIGURES.get(s["id"], []))
        body.append(s["html"].replace("</section>", figs + "</section>") if figs else s["html"])
    mat = "".join(f'<tr><td>{html.escape(m[0])}</td><td class="{"y" if m[1] else "n"}">{"✓" if m[1] else "–"}</td><td class="{"y" if m[2] else "n"}">{"✓" if m[2] else "–"}</td><td>{html.escape(m[3])}</td></tr>' for m in matrix)
    logo = img_uri("blake-logo-blue.png")
    return f"""<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><title>{TITLE}</title><style>{css}
body {{ counter-reset: fig; }} figure {{ counter-increment: fig; }} figcaption::before {{ content: "Figure " counter(fig) ". "; font-weight: 700; }}</style></head><body>
<!--COVER--><section class="cover">
  <img class="logo" src="{logo}" alt="Blake UK">
  <div class="hero"><svg width="420" height="420" viewBox="0 0 420 420" aria-hidden="true"><g fill="none" stroke="#fff" stroke-width="2"><circle cx="210" cy="210" r="60"/><circle cx="210" cy="210" r="110"/><circle cx="210" cy="210" r="160"/><circle cx="210" cy="210" r="200"/></g></svg>
    <p class="eyebrow">USER MANUAL</p><h1>QR Codes<br>and Link Pages</h1>
    <p class="lead">Create, design and track QR codes and company link pages for Blake UK, VisionPlus and Solwise. Every function, every section, every ability, and exactly what is tracked.</p></div>
  <div class="maxcard"><img class="banner" src="{img_uri('max-banner.webp')}" alt="Max, My AI eXpert"><div class="brands"><img src="{b64(ROOT/'web/static/img/brands/blake-uk-on-light.png','image/png')}" alt="Blake UK"><img src="{b64(ROOT/'web/static/img/brands/visionplus-on-light.png','image/png')}" alt="VisionPlus"><img src="{b64(ROOT/'web/static/img/brands/solwise-on-light.png','image/png')}" alt="Solwise"></div></div>
  <div class="foot"><span>qr.blakegroup.uk</span><span>Issued {today}</span></div>
</section><!--/COVER-->

<section class="frontpage"><h2>About this manual</h2>
<p>This manual explains everything the QR Codes and Link Pages system does, in the order you are likely to need it. It is built from the Help page inside the system, so the two always say the same thing. If you are signed in, press <strong>Help</strong> in the menu to read the same material on screen.</p>
<h3>How each section is written</h3>
<ul><li><strong>What it is.</strong> What the feature is for, in a sentence or two.</li><li><strong>How to use it.</strong> The steps, with the exact names of buttons and boxes.</li><li><strong>What is tracked.</strong> Whether the feature records anything about visitors, and what. Section {n['privacy']} lists everything in one place.</li></ul>
<h3>Conventions</h3>
<ul><li><strong>Bold</strong> text is the name of a menu item, button or box on screen. <em>Italic</em> text is the name of an option.</li><li><code>This style</code> is something you type exactly as shown.</li><li>All names, figures and scans in the screenshots are fictional demonstration data.</li></ul>
<h3>Where to start</h3>
<ul><li>New to the system: read section 1, then sections 3 and {n['privacy']}.</li><li>Looking for something specific: use the task finder and the index at the back.</li><li>Worried about privacy: section {n['privacy']}, and Appendix A for the stored fields.</li></ul>
<h3>Roles</h3>
<p>An <strong>admin</strong> can do everything, including managing users. A <strong>member</strong> can make and manage QR codes, link pages, campaigns and designs, and read all statistics, but cannot manage users. Parts of this manual that only admins can use say so.</p></section>

<section class="frontpage toc"><h2>Contents</h2><ol>{toc}</ol>
<ol style="margin-top:6mm"><li><span class="t"><span class="num">A</span><a href="#appendix-a">QR code types at a glance</a></span><span class="dots"></span><span class="n">{pg('appendix-a')}</span></li>
<li><span class="t"><span class="num">B</span><a href="#appendix-b">Where to find things in the menu</a></span><span class="dots"></span><span class="n">{pg('appendix-b')}</span></li>
<li><span class="t"><span class="num"></span><a href="#index">Index of terms (A to Z)</a></span><span class="dots"></span><span class="n">{pg('index')}</span></li></ol></section>

<section class="frontpage"><h2 id="taskfinder">Task finder: I want to...</h2><p class="muted">Find what you are trying to do, then go to the section shown.</p><table class="tasks"><tbody>{tasks}</tbody></table></section>

{"".join(body)}

<section class="helpsec" id="appendix-a" style="break-before:page"><h2>Appendix A. QR code types at a glance</h2>
<p>Static codes hold their content and are never tracked. Dynamic codes go through the system, can be changed later, and are tracked. Section {n['types']} explains what to enter for each type.</p>
<table class="matrix"><thead><tr><th>Type</th><th>Static</th><th>Dynamic</th><th>What it does</th></tr></thead><tbody>{mat}</tbody></table></section>

<section class="helpsec" id="appendix-b"><h2>Appendix B. Where to find things in the menu</h2>
<table><thead><tr><th>Menu item</th><th>What it opens</th><th>Section</th></tr></thead><tbody>
<tr><td><strong>Codes</strong></td><td>The list of every QR code</td><td>{n['codes']}</td></tr>
<tr><td><strong>New QR code</strong></td><td>Choose static or dynamic, then a type, then the form</td><td>{n['static-dynamic']}, {n['creating']}</td></tr>
<tr><td><strong>Campaigns</strong></td><td>Totals for each campaign</td><td>{n['campaigns']}</td></tr>
<tr><td><strong>Link pages</strong></td><td>Build and measure Linktree-style pages</td><td>{n['link-pages']}</td></tr>
<tr><td><strong>Bulk</strong></td><td>Many codes from one CSV</td><td>{n['bulk']}</td></tr>
<tr><td><strong>Designs</strong></td><td>Saved looks, and the New design page</td><td>{n['save-designs']}</td></tr>
<tr><td><strong>Export scans</strong></td><td>Every scan as a CSV</td><td>{n['exports']}</td></tr>
<tr><td><strong>Users</strong> (admins)</td><td>People, roles, passwords, activity log</td><td>{n['users']}</td></tr>
<tr><td><strong>Help</strong></td><td>This material on screen, plus the PDF download</td><td>All</td></tr>
<tr><td><strong>Sign out</strong>, top right</td><td>Ends your session. Max opens Help; the Blake UK logo opens blake-uk.com</td><td>{n['account']}</td></tr>
</tbody></table></section>

<section class="helpsec" id="index" style="break-before:page"><h2>Index of terms</h2><div class="index2">{index}</div></section>

<!--BACK--><section class="backcover"><img src="{logo}" alt="Blake UK"><p><strong>Blake UK Group</strong></p><p class="small">This manual describes the QR Codes and Link Pages system at qr.blakegroup.uk.<br>For anything not covered here, use the Help page in the system or ask your administrator.</p></section><!--/BACK-->
</body></html>"""


def split_doc(doc):
    """The cover and back cover are full-bleed pages with no running header or footer, so they are printed separately."""
    cover = re.search(r"<!--COVER-->(.*?)<!--/COVER-->", doc, re.S).group(1)
    back = re.search(r"<!--BACK-->(.*?)<!--/BACK-->", doc, re.S).group(1)
    head = doc[:doc.index("<body>") + 6]
    body = doc[doc.index("<body>") + 6:].replace(cover, "").replace(back, "").replace("<!--COVER--><!--/COVER-->", "").replace("<!--BACK--><!--/BACK-->", "")
    return head, cover, body.replace("</body></html>", ""), back


def print_pdf(browser, html_doc, out, margins):
    pg = browser.new_context().new_page()
    pg.set_content(html_doc, wait_until="load"); pg.emulate_media(media="print")
    if margins:
        hdr = '<div style="font:8px Carlito,Arial,sans-serif;color:#6b7280;width:100%;padding:0 18mm;display:flex;justify-content:space-between"><span>Blake UK Group · QR Codes and Link Pages</span><span>User manual</span></div>'
        ftr = '<div style="font:8px Carlito,Arial,sans-serif;color:#6b7280;width:100%;padding:0 18mm;display:flex;justify-content:space-between"><span>qr.blakegroup.uk</span><span>Page <span class="pageNumber"></span> of <span class="totalPages"></span></span></div>'
        pg.pdf(path=str(out), format="A4", print_background=True, display_header_footer=True, header_template=hdr, footer_template=ftr,
               margin={"top": "22mm", "bottom": "20mm", "left": "18mm", "right": "18mm"})
    else:
        pg.pdf(path=str(out), width="210mm", height="297mm", print_background=True, margin={"top": "0", "bottom": "0", "left": "0", "right": "0"}, prefer_css_page_size=True)
    pg.context.close()


def render_pdf(browser, doc, tmp):
    """Prints cover, body and back cover, and returns the paths. Printed page numbers count the body only."""
    head, cover, body, back = split_doc(doc)
    full = "@page { size: A4; margin: 0; } body { margin: 0; }"
    print_pdf(browser, head.replace("</style>", full + "</style>") + cover + "</body></html>", tmp / "cover.pdf", False)
    print_pdf(browser, head + body + "</body></html>", tmp / "body.pdf", True)
    print_pdf(browser, head.replace("</style>", full + "</style>") + back + "</body></html>", tmp / "back.pdf", False)


def page_texts(pdf):
    with pdfplumber.open(pdf) as p:
        return [re.sub(r"\s+", " ", (pg.extract_text() or "")).lower() for pg in p.pages]


def locate(texts, secs, matrix_marker="appendix a. qr code types"):
    heads = {}
    first = None
    for i, s in enumerate(secs, 1):
        needle = f"{i}. {s['title']}".lower()
        needle = re.sub(r"\{\{.*?\}\}", "", needle).replace("  ", " ").strip()
        for pi, t in enumerate(texts, 1):
            if needle[:40] in t and (first is None or pi >= first):
                heads[s["id"]] = pi
                first = first or pi
                break
    for key, mark in (("appendix-a", "appendix a. qr code types"), ("appendix-b", "appendix b. where to find"), ("index", "index of terms")):
        for pi in range(len(texts), 0, -1):
            if mark in texts[pi - 1] and pi > (first or 0):
                heads[key] = pi
    body_pages = range(first or 1, heads.get("appendix-a", len(texts)))
    terms = {}
    for term, needles in TERMS:
        hits = [pi for pi in body_pages if any(nd in texts[pi - 1] for nd in needles)]
        terms[term] = hits
    return heads, terms


def matrix_rows(secs):
    soup = BeautifulSoup(next(s["html"] for s in secs if s["id"] == "types"), "html.parser")
    rows = []
    for t in soup.select(".typehelp"):
        label = t.find("h3").get_text(" ", strip=True)
        label = t.find("h3").find(string=True, recursive=False).strip()
        badges = [b.get_text(strip=True) for b in t.select(".badge")]
        rows.append((label, "static" in badges, "dynamic" in badges, t.find("p").get_text(" ", strip=True)))
    return rows


def main():
    if not BIN or not Path(BIN).exists():
        raise SystemExit("set QRTRACK_BIN to a built qrtrack binary")
    tmp = Path(tempfile.mkdtemp(prefix="manual-"))
    proc = start_server(tmp)
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch()
            ctx = browser.new_context(viewport={"width": 1280, "height": 860}); pg = ctx.new_page()
            seed_ui(pg); seed_data(tmp / "demo.db"); ctx.close()
            shots = shoot(browser, tmp)
            ctx = browser.new_context(viewport={"width": 1280, "height": 860}); pg = ctx.new_page()
            pg.goto(BASE + "/admin/login"); pg.fill("input[name=username]", "admin"); pg.fill("input[name=password]", ADMIN_PW); pg.click("button.primary"); pg.wait_for_url(BASE + "/admin/")
            pg.goto(BASE + "/admin/help")
            raw = pg.evaluate("[...document.querySelectorAll('section.helpsec')].map(s => ({id: s.id, title: s.querySelector('h2').textContent.replace(/^\\d+\\.\\s*/, '').trim(), html: s.outerHTML}))")
            ctx.close()
            secs = [dict(r, html=re.sub(r'<h2>\d+\.', lambda m: m.group(0), r["html"])) for r in raw]
            matrix = matrix_rows(secs)
            css = (ROOT / "tools/manual/manual.css").read_text()
            tocpages, termpages = {}, {}
            for attempt in range(4):
                doc = build_html(secs, shots, tocpages, termpages, css, matrix)
                render_pdf(browser, doc, tmp)
                heads, terms = locate(page_texts(tmp / "body.pdf"), secs)
                if heads == tocpages and terms == termpages:
                    break
                tocpages, termpages = heads, terms
            else:
                print("warning: page numbers did not settle", file=sys.stderr)
            browser.close()
        # bookmarks and metadata
        w = PdfWriter()
        for part in ("cover", "body", "back"):
            for page in PdfReader(str(tmp / f"{part}.pdf")).pages:
                w.add_page(page)
        total = len(w.pages)
        # the viewer shows the same numbers as the footer: the cover is unnumbered and the body counts from 1
        w.set_page_label(0, 0, prefix="Cover")
        w.set_page_label(1, total - 2, style="/D", start=1)
        w.set_page_label(total - 1, total - 1, prefix="Back")
        w.add_outline_item("Cover", 0); w.add_outline_item("About this manual", 1); w.add_outline_item("Contents", 2); w.add_outline_item("Task finder", 3)
        for i, s in enumerate(secs, 1):
            if s["id"] in tocpages:
                w.add_outline_item(f"{i}. {re.sub(r'{{.*?}}', '', s['title']).strip()}", tocpages[s["id"]])
        for key, name in (("appendix-a", "Appendix A. QR code types at a glance"), ("appendix-b", "Appendix B. Where to find things"), ("index", "Index of terms")):
            if key in tocpages:
                w.add_outline_item(name, tocpages[key])
        w.add_metadata({"/Title": TITLE, "/Author": "Blake UK Group", "/Subject": "User manual for the QR Codes and Link Pages system at qr.blakegroup.uk",
                        "/Keywords": "QR codes, link pages, tracking, privacy, Blake UK, VisionPlus, Solwise", "/Creator": "tools/manual/build_manual.py",
                        "/ManualSourceHash": source_hash()})
        OUT.parent.mkdir(parents=True, exist_ok=True)
        with open(OUT, "wb") as f:
            w.write(f)
        print(f"wrote {OUT}  {OUT.stat().st_size // 1024} KB, {total} pages, hash {source_hash()[:12]}")
    finally:
        proc.terminate()
        try:
            proc.wait(5)
        except Exception:
            proc.kill()
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    main()
