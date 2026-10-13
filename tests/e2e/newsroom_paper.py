#!/usr/bin/env python3
"""The Newsroom's newspaper, end to end in a real browser, in the sandboxed frame the admin uses, with fake news and fake (slow, like the real) AI answers.
Proves: AI rewrites and AI pictures end up on the printed pages; nothing is downloaded behind your back; a 'ready' panel offers Download and
Get a link; the PDF is a real two-sheet A3; the saved link serves exactly the same file; failures are explained. Run: python3 tests/e2e/newsroom_paper.py"""
import base64, hashlib, json, re, struct, sys, time, zlib
from playwright.sync_api import sync_playwright
HUB = open('src/Newsroom/hub.html', encoding='utf-8').read(); n = 0; fails = []
def ok(what, cond, extra=""):
    global n; n += 1; print(("  ok: " if cond else "  FAIL: ") + what + ("" if cond else "  -> " + str(extra)[:300]))
    if not cond: fails.append(what)
def png(w, h, rgb):
    raw = b''.join(b'\x00' + bytes(rgb) * w for _ in range(h)); ck = lambda t, d: struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    return b'\x89PNG\r\n\x1a\n' + ck(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0)) + ck(b'IDAT', zlib.compress(raw)) + ck(b'IEND', b'')
AI_IMG = base64.b64encode(png(800, 600, (60, 140, 90))).decode()
SENT = ["The new channel will move to a different multiplex from next Tuesday.", "Viewers on the Crystal Palace transmitter will need to retune their Freeview boxes.", "Engineers will carry out the change early in the morning.", "Ofcom has been told about the timetable for the work.", "Households that use a loft aerial may see the channel return later in the day."]
stats = {"feed": 0, "article": 0, "ai_text": 0, "ai_image": 0, "begin": 0, "chunks": []}; state = {"fail_begin": False, "received": b"", "begun": None, "busy": 0, "brief": "good"}   # the first 8 AI calls are answered "busy, wait" (503), like a server at its limit
def api(route):
    req = route.request; u = req.url; cors = {"access-control-allow-origin": "*", "access-control-allow-headers": "*"}
    if req.method == "OPTIONS": return route.fulfill(status=204, headers=cors)
    q = dict(re.findall(r"[?&](\w+)=([^&]*)", u)); a = q.get("action"); js = lambda code, o: route.fulfill(status=code, content_type="application/json", headers=cors, body=json.dumps(o))
    if a == "feed":
        stats["feed"] += 1; k = stats["feed"]
        return js(200, {"status": "ok", "feed": {"title": "Test"}, "items": [{"title": f"Freeview change {k}-{j}: transmitter retune news", "link": f"https://news.example/{k}/{j}", "pubDate": "Mon, 12 Oct 2026 09:00:00 +0000", "guid": f"{k}-{j}", "author": "", "thumbnail": "", "description": " ".join(SENT * 3)[:400], "content": "", "enclosure": {}, "categories": []} for j in range(10)]})
    if a == "article": stats["article"] += 1; return js(200, {"contents": "".join(f"<p>{s}</p>" for s in SENT * 4)})
    if a == "ai":
        body = json.loads(req.post_data or "{}"); b = body.get("body", {})
        if state["busy"] > 0: state["busy"] -= 1; stats["busy"] = stats.get("busy", 0) + 1; return js(503, {"error": "The AI helper is busy. Please try again."})
        if body.get("kind") == "image": stats["ai_image"] += 1; time.sleep(0.5); return js(200, {"candidates": [{"content": {"parts": [{"inlineData": {"mimeType": "image/png", "data": AI_IMG}}]}}]})
        sysx = (b.get("systemInstruction") or {}).get("parts", [{}])[0].get("text", "")
        if sysx.startswith("You write a short briefing"):
            stats["brief"] = stats.get("brief", 0) + 1; mode = state["brief"]
            if mode == "error": return js(500, {"error": "boom"})
            good = ["The new channel will move to a different multiplex from next Tuesday.", "Viewers on the Crystal Palace transmitter will need to retune their Freeview boxes."]
            secs = {"good": [good, [], ["Engineers will carry out the change early in the morning."]], "invented": [["Freesat services report no transponder alterations this week 1234.", "Viewers should tune to Astra 28.2 now."] + good[:1], [], []], "empty": [[], [], []]}[mode]
            return js(200, {"candidates": [{"content": {"parts": [{"text": json.dumps({"sections": [{"title": t, "items": i} for t, i in zip("abc", secs)]})}]}}]})
        stats["ai_text"] += 1; time.sleep(1.6); src = b["contents"][0]["parts"][0]["text"].split("SOURCE:", 1)[-1].strip(); sents = re.split(r"(?<=\.)\s+", src.split("\n\n", 1)[-1])
        out = {"headline": "Freeview channel to move multiplex", "caption": "Crystal Palace transmitter", "body": "\n\n".join(" ".join(sents[i:i + 2]) for i in range(0, 6, 2))}
        return js(200, {"candidates": [{"content": {"parts": [{"text": json.dumps(out)}]}}]})
    if a == "paper_begin":
        if state["fail_begin"]: return js(422, {"error": "You already have 5 saved newspapers. They are removed after two days."})
        stats["begin"] += 1; state["received"] = b""; state["begun"] = json.loads(req.post_data or "{}"); return js(200, {"id": "a1b2c3d4e5f6a1b2c3d4e5f6"})
    if a == "paper_chunk":
        off = int(q.get("offset", -1)); data = req.post_data_buffer or b""
        if off != len(state["received"]): return js(422, {"error": "That piece is out of order."})
        state["received"] += data; stats["chunks"].append(len(data)); return js(200, {"received": len(state["received"])})
    if a == "paper_finish": return js(200, {"url": "https://blakegroup.uk/api/newsroom.php?action=paper&id=a1b2c3d4e5f6a1b2c3d4e5f6&exp=1900000000&sig=abc", "expires": 1900000000, "name": state["begun"]["name"]})
    route.fulfill(status=404, headers=cors, body="{}")
boot = {"pass": "x" * 60, "api": "https://blakegroup.uk/api/newsroom.php", "prefs": {}}
page_html = HUB.replace("/*__NEWS_BOOT__*/", "window.NEWS = " + json.dumps(boot) + ";")
with sync_playwright() as p:
    b = p.chromium.launch(); ctx = b.new_context(viewport={"width": 1400, "height": 950}, accept_downloads=True)
    ctx.route("https://blakegroup.uk/api/newsroom.php*", api)
    ctx.route("https://top.test/", lambda r: r.fulfill(status=200, content_type="text/html", body='<html><body style="margin:0"><iframe id="nr" sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox allow-downloads" src="https://blakegroup.uk/admin/newsroom.php" style="width:100%;height:900px;border:0"></iframe></body></html>'))
    ctx.route("https://blakegroup.uk/admin/newsroom.php", lambda r: r.fulfill(status=200, content_type="text/html", body=page_html))
    pg = ctx.new_page(); downloads = []; pg.on("download", lambda d: downloads.append(d)); pg.on("pageerror", lambda e: print("  PAGE ERROR:", str(e)[:200])); pg.on("console", lambda m: print("  console", m.type + ":", m.text[:160]) if m.type == "error" else None); pg.goto("https://top.test/"); fr = pg.frame_locator("#nr")
    fr.locator("text=Freeview change").first.wait_for(timeout=40000); btn = fr.locator("button", has_text="Create Newspaper"); btn.wait_for(timeout=30000); pg.wait_for_timeout(2000)
    frame = [f for f in pg.frames if "newsroom.php" in f.url][0]
    frame.evaluate("window.__snap = ''; setInterval(() => { const s = document.getElementById('np-stage'); if (s && s.innerHTML.length > window.__snap.length) window.__snap = s.innerHTML; }, 150);")
    print("== the Retune Brief window")
    def brief(mode):
        state["brief"] = mode; fr.locator("button", has_text="AI Retune Brief").click(); fr.locator("#briefing-content [data-np=brief-note]").wait_for(timeout=15000); return fr.locator("#briefing-content").inner_text()
    def done(): fr.locator("#briefing-modal button", has_text="Done").click(); pg.wait_for_timeout(300)
    txt = brief("error"); ok("if the AI cannot answer it says so and shows the real latest headlines, with no made-up briefing", "could not be made right now" in txt and "Freeview change" in txt and "Routine channel" not in txt and fr.locator("[data-np=brief-item]").count() == 0, txt[:200]); done()
    txt = brief("empty"); ok("if nothing in the stories fits, it says so instead of inventing 'all stable'", fr.locator("[data-np=brief-empty]").count() == 1 and "nothing to brief" in txt and "stable" not in txt.lower(), txt[:200]); done()
    txt = brief("invented"); ok("a sentence with a number that is not in the stories is dropped; a true one stays", "1234" not in txt and "28.2" not in txt and "no transponder alterations" not in txt and "different multiplex" in txt, txt[:300]); done()
    txt = brief("good")
    ok("the brief is laid out as three headings with bullet lists, with no stray asterisks or hashes", fr.locator("[data-np=brief-heading]").count() == 3 and fr.locator("[data-np=brief-item]").count() == 3 and "*" not in txt and "#" not in txt, txt[:300])
    ok("a heading with nothing under it says so", "Nothing in the current stories." in txt and "SATELLITE AND TRANSPONDER CHANGES" in txt.upper())
    ok("it says it was written by AI from the stories and should be checked", "Written by AI" in txt and "Check anything you pass on" in txt)
    fr.locator("[data-np=brief-copy]").click(); pg.wait_for_timeout(300); ok("Copy brief works", fr.locator("[data-np=brief-copy]").inner_text() == "Copied", fr.locator("[data-np=brief-copy]").inner_text()); done()
    print("== creating the newspaper (slow AI, like the real one; the first 8 AI calls are answered 'busy')"); state["busy"] = 8; t0 = time.time(); btn.click()
    fr.locator("[data-np=ready]").wait_for(timeout=200000); took = time.time() - t0; pg.wait_for_timeout(500)
    print(f"  took {took:.0f} s; AI calls: {stats['ai_text']} rewrites, {stats['ai_image']} pictures")
    snap = frame.evaluate("window.__snap")
    ok("the AI rewrites were put into the printed pages (the AI's headline and copy appear in the layout)", "Freeview channel to move multiplex" in snap and stats["ai_text"] >= 5, (stats["ai_text"], snap.count("Freeview channel to move multiplex")))
    ok("when the server said 'busy' the page waited and tried again, so nothing was lost", stats.get("busy", 0) == 8 and "Freeview channel to move multiplex" in snap, stats)
    rpt = frame.evaluate("window.__npReport")
    ok("the AI Retune Brief is a box on the back page, in the space that used to be empty", rpt["retune"] and 'id="p4-retune"' in snap and "Retune Brief" in snap and "different multiplex from next Tuesday" in snap and "Engineers will carry out" in snap, rpt)
    ok("no part of the back page is left empty, and every story is placed", rpt["empty"] == 0 and rpt["placed"] == rpt["total"], rpt)
    ok("AI pictures were requested for stories without a photo and are in the printed pages", stats["ai_image"] >= 1 and AI_IMG[:60] in snap, (stats["ai_image"], AI_IMG[:30] in snap))
    ok("nothing was downloaded behind your back (no automatic save)", downloads == [], [d.suggested_filename for d in downloads])
    ok("a 'ready' panel appears, naming the file, its size and how many stories went in", re.search(r"The-Rigger-\w+-\d{4}-A3\.pdf\s+·\s+[\d.]+ MB\s+·\s+\d+ of \d+ stories", fr.locator("[data-np=info]").inner_text()) is not None, fr.locator("[data-np=info]").inner_text())
    ok("it has a Download button and a Get a link button", fr.locator("[data-np=download]").is_visible() and fr.locator("[data-np=getlink]").is_visible())

    print("== downloading")
    with pg.expect_download(timeout=15000) as di: fr.locator("[data-np=download]").click()
    d = di.value; path = d.path(); pdf = open(path, "rb").read(); open("/tmp/np_mock.pdf", "wb").write(pdf); down_sha = hashlib.sha256(pdf).hexdigest()
    ok("clicking Download gives the file", d.suggested_filename.startswith("The-Rigger-") and d.suggested_filename.endswith("-A3.pdf"), d.suggested_filename)
    pages = len(re.findall(rb"/Type\s*/Page\b", pdf)); box = re.search(rb"/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)", pdf)
    ok("it is a real PDF of two A3 landscape sheets (four A4 pages, double sided)", pdf.startswith(b"%PDF-") and pages == 2 and box and abs(float(box.group(1)) - 1190.55) < 2 and abs(float(box.group(2)) - 841.89) < 2, (pdf[:8], pages, box and box.groups()))
    ok("the panel says where to look for the file", "Downloads folder" in fr.locator("[data-np=status]").inner_text())

    print("== getting a link")
    state["fail_begin"] = True; fr.locator("[data-np=getlink]").click(); fr.locator("[data-np=status]", has_text="already have 5").wait_for(timeout=8000)
    ok("if the server refuses, the reason is shown in plain words and the button works again", fr.locator("[data-np=getlink]").is_enabled() and fr.locator("[data-np=linkbox]").count() == 0)
    state["fail_begin"] = False; fr.locator("[data-np=getlink]").click(); fr.locator("[data-np=linkbox]").wait_for(timeout=30000)
    url = fr.locator("[data-np=linkbox]").input_value()
    ok("a link appears, with how long it works", url.startswith("https://blakegroup.uk/api/newsroom.php?action=paper&id=") and "48 hours" in fr.locator("[data-np=status]").inner_text(), url)
    ok("the copy stored on the server is byte for byte the file that downloads", hashlib.sha256(state["received"]).hexdigest() == down_sha and len(state["received"]) == len(pdf), (len(state["received"]), len(pdf)))
    ok("it was sent in pieces, in order, each under the 2 MB limit", sum(stats["chunks"]) == len(pdf) and max(stats["chunks"]) <= 2 * 1024 * 1024 and state["begun"]["size"] == len(pdf), stats["chunks"])
    ok("Open goes to the link in a new window without sharing the page, and Copy is offered", fr.locator("[data-np=open]").get_attribute("href") == url and fr.locator("[data-np=open]").get_attribute("target") == "_blank" and "noopener" in fr.locator("[data-np=open]").get_attribute("rel") and fr.locator("[data-np=copy]").is_visible())
    pg.screenshot(path="/tmp/np_ready.png")
    fr.locator("[data-np=close]").click(); ok("the panel closes", fr.locator("[data-np=ready]").count() == 0)
    print("== a second newspaper when the AI cannot write the brief")
    brief("error"); done(); frame.evaluate("window.__snap = ''"); btn.click(); fr.locator("[data-np=ready]").wait_for(timeout=200000); pg.wait_for_timeout(500)
    rpt2 = frame.evaluate("window.__npReport"); snap2 = frame.evaluate("window.__snap")
    ok("without a brief the back page uses all four columns, and none is left empty", rpt2["retune"] is False and 'id="p4-retune"' not in snap2 and len(rpt2["fill"]) == 4 and rpt2["empty"] == 0, rpt2)
    ok("the stories are made as large as will fit, rather than the smallest that fit", rpt2["mode"].split("@")[0] in ("full", "short", "headline", "runin") and float(rpt2["mode"].split("@")[1]) >= 0.5, rpt2["mode"])
    ok("every story is still placed", rpt2["placed"] == rpt2["total"], rpt2)
    b.close()
print(f"\n{n} checks, {len(fails)} failed"); sys.exit(1 if fails else 0)
