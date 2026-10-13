#!/usr/bin/env python3
"""A REAL newspaper, made through the live Newsroom in a real browser: real feeds, real article pages, the real AI for rewrites and pictures.
Measures how many AI calls were answered or refused, whether rewrites and AI pictures reached the printed pages, then downloads the PDF and fetches
the saved link and checks it is the same file. Usage: newsroom_paper_live.py <pass> <out dir> (run from the repository root)"""
import hashlib, json, os, re, sys, time, urllib.request
from collections import Counter
from playwright.sync_api import sync_playwright
PASS, OUT = sys.argv[1], sys.argv[2]; os.makedirs(OUT, exist_ok=True); bad = 0
HUB = open('src/Newsroom/hub.html', encoding='utf-8').read()
def check(what, cond, extra=""):
    global bad; print(("  PASS  " if cond else "  FAIL  ") + what + ("" if cond else f"   -> {extra}")); bad += 0 if cond else 1
boot = {"pass": PASS, "api": "https://blakegroup.uk/api/newsroom.php", "prefs": {}}
page_html = HUB.replace("/*__NEWS_BOOT__*/", "window.NEWS = " + json.dumps(boot) + ";")
calls = []
with sync_playwright() as p:
    b = p.chromium.launch(); ctx = b.new_context(viewport={"width": 1400, "height": 950}, accept_downloads=True)
    ctx.route("https://top.test/", lambda r: r.fulfill(status=200, content_type="text/html", body='<html><body style="margin:0"><iframe id="nr" sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox allow-downloads" src="https://blakegroup.uk/admin/newsroom.php" style="width:100%;height:900px;border:0"></iframe></body></html>'))
    ctx.route("https://blakegroup.uk/admin/newsroom.php", lambda r: r.fulfill(status=200, content_type="text/html", body=page_html))
    if os.environ.get("NO_PHOTOS") == "1":      # the stories' own photos cannot be loaded, so the page has to make pictures with the AI
        ctx.route(lambda u: "/api/newsroom.php" in u and "action=image" in u, lambda r: r.fulfill(status=404, content_type="application/json", headers={"access-control-allow-origin": "*"}, body='{"error":"blocked for this test"}'))
        print("== (the stories' own photos are blocked in this run, to make the page create pictures with the AI)")
    pg = ctx.new_page(); warns = []
    pg.on("console", lambda m: warns.append(m.text[:160]) if m.type == "warning" and ("Rewrite" in m.text or "Image generation" in m.text) else None)
    def on_resp(r):
        if "/api/newsroom.php?action=" in r.url:
            a = re.search(r"action=(\w+)", r.url).group(1); k = ""
            if a == "ai":
                try: k = json.loads(r.request.post_data or "{}").get("kind", "")
                except Exception: pass
            calls.append((a, k, r.status))
    pg.on("response", on_resp); pg.goto("https://top.test/"); fr = pg.frame_locator("#nr")
    print("== loading the real news"); fr.locator("button", has_text="Create Newspaper").wait_for(timeout=60000)
    frame = [f for f in pg.frames if "newsroom.php" in f.url][0]
    frame.wait_for_function("(() => { const b = [...document.querySelectorAll('button')].find(x => /Create Newspaper/.test(x.innerText)); return b && !b.disabled; })()", timeout=240000); pg.wait_for_timeout(2000)
    n_art = frame.evaluate("(document.body.innerText.match(/(\\d+) articles/) || [0, 0])[1]"); print(f"  {n_art} real stories loaded; feed requests: {Counter(s for a, k, s in calls if a == 'feed')}")
    frame.evaluate("window.__snap = ''; setInterval(() => { const s = document.getElementById('np-stage'); if (s && s.innerHTML.length > window.__snap.length) window.__snap = s.innerHTML; }, 200);")
    print("== creating the newspaper with the real AI (this takes minutes)"); t0 = time.time(); fr.locator("button", has_text="Create Newspaper").click()
    fr.locator("[data-np=ready]").wait_for(timeout=1500000); took = time.time() - t0; pg.wait_for_timeout(1000)
    ai_text = Counter(s for a, k, s in calls if a == "ai" and k == "text"); ai_img = Counter(s for a, k, s in calls if a == "ai" and k == "image"); art = Counter(s for a, k, s in calls if a == "article")
    print(f"  took {took/60:.1f} minutes"); print(f"  story rewrites (AI text): {dict(ai_text)}"); print(f"  AI pictures: {dict(ai_img)}"); print(f"  article pages fetched: {dict(art)}")
    rej = sum(1 for w in warns if "rejected" in w); fail = sum(1 for w in warns if "Rewrite failed" in w); print(f"  rewrites rejected by the page's own accuracy check: {rej}; failed: {fail}")
    check("no AI call was refused for going too fast (429)", ai_text.get(429, 0) + ai_img.get(429, 0) == 0, (dict(ai_text), dict(ai_img)))
    check("the AI answered the story rewrites", ai_text.get(200, 0) >= 3, dict(ai_text))
    snap = frame.evaluate("window.__snap"); n_ai_pics = len(re.findall(r'<img[^>]+src="data:image/(?:png|jpeg);base64,', snap)) + len(re.findall(r"background-image:\s*url\(['\"]?data:image/", snap))   # the page sets pictures as backgrounds; print(f"  pictures in the printed pages: {n_ai_pics} (AI drawn: {ai_img.get(200, 0)})")
    check("rewritten stories reached the printed pages", ai_text.get(200, 0) - rej - fail >= 1 and len(snap) > 5000, (ai_text.get(200, 0), rej, fail, len(snap)))
    if ai_img.get(200, 0): check("AI pictures reached the printed pages", n_ai_pics >= 1, n_ai_pics)
    report = frame.evaluate("window.__npReport || null"); print("  placed:", json.dumps({k: report.get(k) for k in ("total", "placed")}) if report else None)
    check("the panel is offered", fr.locator("[data-np=download]").is_visible())
    with pg.expect_download(timeout=20000) as di: fr.locator("[data-np=download]").click()
    d = di.value; pdf = open(d.path(), "rb").read(); open(os.path.join(OUT, "newspaper.pdf"), "wb").write(pdf); pages = len(re.findall(rb"/Type\s*/Page\b", pdf))
    check(f"the PDF downloads ({len(pdf)/1048576:.1f} MB, {pages} A3 sheets)", pdf.startswith(b"%PDF-") and pages == 2, (pdf[:8], pages))
    fr.locator("[data-np=getlink]").click(); fr.locator("[data-np=linkbox]").wait_for(timeout=120000); url = fr.locator("[data-np=linkbox]").input_value(); print("  link:", url[:90] + "...")
    r = urllib.request.urlopen(url, timeout=60); body = r.read(); check("the link serves the same file, as a download", r.status == 200 and hashlib.sha256(body).hexdigest() == hashlib.sha256(pdf).hexdigest() and "attachment" in r.headers.get("Content-Disposition", "") and r.headers.get("Content-Type") == "application/pdf", (r.status, r.headers.get("Content-Type"), len(body), len(pdf)))
    try: urllib.request.urlopen(url[:-3] + "AAA", timeout=30); check("a link with an altered signature is refused", False)
    except urllib.error.HTTPError as e: check("a link with an altered signature is refused", e.code == 404, e.code)
    pg.screenshot(path=os.path.join(OUT, "panel.png")); b.close()
print(f"\nRESULT: {'ALL PASSED' if not bad else str(bad) + ' FAILED'}"); sys.exit(1 if bad else 0)
