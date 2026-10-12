#!/usr/bin/env python3
"""Browser test of the Newsroom against a real server (tests/e2e/files_server.php running on :18504).
Proves: it runs sandboxed with a limited pass and no cookies; hostile feed text cannot run script in it; direct opening gives no pass;
and the real endpoints refuse every internal address, every bad request, and anything without the pass."""
import json, re, sys
from playwright.sync_api import sync_playwright

B = "http://127.0.0.1:18504"; fails = []; n = 0
def ok(what, cond, extra=""):
    global n; n += 1; print(("  ok: " if cond else "  FAIL: ") + what + ("" if cond else "  -> " + str(extra)[:300]))
    if not cond: fails.append(what)

def sign_in(ctx, user):
    pg = ctx.new_page(); pg.goto(B + "/admin/"); pg.wait_for_selector("#login-user", timeout=8000)
    pg.fill("#login-user", user); pg.fill("#login-pass", "wr-pass-12345"); pg.click("#login-step-password button"); pg.wait_for_selector("nav button", state="visible", timeout=8000)
    return pg

EVIL = [{"title": "&lt;img src=x onerror=\"document.title='XSS-title'\"&gt; Freeview retune", "link": "https://news.example/a", "pubDate": "Mon, 12 Oct 2026 09:00:00 +0000", "guid": "1",
         "author": "<svg onload=\"document.title='XSS-author'\">", "thumbnail": "x\" onerror=\"document.title='XSS-thumb'", "description": "&lt;img src=x onerror=\"document.title='XSS-desc'\"&gt; Short summary of the retune",
         "content": "", "enclosure": {}, "categories": []},
        {"title": "Second story about Freesat", "link": "javascript:document.title='XSS-link'", "pubDate": "Mon, 12 Oct 2026 08:00:00 +0000", "guid": "2", "author": "Pat", "thumbnail": "", "description": "Plain text", "content": "", "enclosure": {}, "categories": []}]

with sync_playwright() as p:
    b = p.chromium.launch(); ctx = b.new_context(viewport={"width": 1400, "height": 950}); pg = sign_in(ctx, "fs-admin"); errs = []; dialogs = []
    pg.on("dialog", lambda d: (dialogs.append(d.message), d.dismiss())); seen = []
    def feed_route(route):
        req = route.request; u = req.url
        seen.append({"url": u, "headers": req.all_headers(), "method": req.method})
        if "action=feed" in u: route.fulfill(status=200, content_type="application/json", headers={"access-control-allow-origin": "*"}, body=json.dumps({"status": "ok", "feed": {"title": "Test"}, "items": EVIL}))
        elif req.method == "OPTIONS": route.fulfill(status=204, headers={"access-control-allow-origin": "*", "access-control-allow-headers": "X-News-Pass, Content-Type", "access-control-allow-methods": "GET, POST, OPTIONS"})
        else: route.fulfill(status=404, content_type="application/json", headers={"access-control-allow-origin": "*"}, body='{"error":"not in this test"}')
    ctx.route("**/api/newsroom.php*", feed_route)

    print("== A. the Newsroom tab")
    pg.locator("nav button", has_text="Newsroom").click(); pg.wait_for_selector("#newsroom-frame")
    fr = pg.frame_locator("#newsroom-frame")
    fr.locator("text=Second story about Freesat").first.wait_for(timeout=20000)
    frame = [f for f in pg.frames if "newsroom.php" in f.url][0]
    ok("the page runs in a sandbox with no origin of its own, so it cannot touch the admin or its sign-in", frame.evaluate("window.origin") == "null" and pg.get_attribute("#newsroom-frame", "sandbox") and "allow-same-origin" not in pg.get_attribute("#newsroom-frame", "sandbox"))
    ok("it was given a limited pass and its own settings, and nothing else", frame.evaluate("Object.keys(window.NEWS).sort().join(',')") == "api,pass,prefs" and len(frame.evaluate("window.NEWS.pass")) > 40)
    ok("it cannot read the admin page, its variables or its storage", frame.evaluate("(() => { try { return typeof parent.document.body; } catch (e) { return 'blocked'; } })()") == "blocked" and frame.evaluate("(() => { try { localStorage.getItem('x'); return 'allowed'; } catch (e) { return 'blocked'; } })()") == "blocked")
    news_calls = [s for s in seen if "action=feed" in s["url"]]
    ok("it fetched its news from this server (not from any free proxy), with the pass and without any cookie", len(news_calls) >= 3 and all(c["headers"].get("x-news-pass") and "cookie" not in c["headers"] for c in news_calls), [c["headers"] for c in news_calls][:1])
    ok("no request went to a third-party proxy or to Google directly", not any(re.search(r"rss2json|allorigins|weserv|googleapis\.com/v1beta", s["url"]) for s in seen))
    pg.wait_for_timeout(1500)

    print("== B. hostile feed text")
    ok("text built to run script was shown as plain text and ran nothing (title, author, picture address, summary, link)", not any(x in frame.evaluate("document.title") for x in ["XSS"]) and not dialogs, (frame.evaluate("document.title"), dialogs))
    body = frame.evaluate("document.body.innerText")
    ok("the story is there, with the dangerous text visible as text", "Freeview retune" in body and "Second story about Freesat" in body)
    ok("no element built from feed text carries an event handler", frame.evaluate("document.querySelectorAll('[onerror],[onload],[onclick*=XSS],svg[onload]').length") == 0 and frame.evaluate("[...document.querySelectorAll('a[href]')].filter(a => a.href.toLowerCase().startsWith('javascript:')).length") == 0, frame.evaluate("[...document.querySelectorAll('a[href]')].map(a=>a.href).filter(h=>h.startsWith('javascript')).slice(0,3)"))
    pg.screenshot(path="/tmp/newsroom.png")

    print("== C. opened directly, outside the tab")
    top = ctx.new_page(); top.goto(B + "/admin/newsroom.php")
    ok("opened in a tab of its own it shows only a notice, with no pass and none of the page", "Open the Newsroom from the admin" in top.inner_text("body") and top.evaluate("typeof window.NEWS") == "undefined" and "pass" not in top.content())

    print("== D. the real endpoints")
    rq = b.new_context().request       # no cookies at all: the pass is the only thing that opens these endpoints
    pas = frame.evaluate("window.NEWS.pass")
    H = {"X-News-Pass": pas}; G = lambda q, h=None: rq.get(B + "/api/newsroom.php?" + q, headers=h if h is not None else H)
    ok("with no pass, a wrong pass, or only a signed-in cookie, everything is refused (401)", all(G("action=feed&url=https://example.com/", h).status == 401 for h in [{}, {"X-News-Pass": "nope"}, {"X-News-Pass": pas[:-3] + "AAA"}, {"X-News-Pass": pas + "x"}]))
    r = rq.fetch(B + "/api/newsroom.php?action=feed", method="OPTIONS", headers={"Origin": "null", "Access-Control-Request-Method": "GET", "Access-Control-Request-Headers": "x-news-pass"})
    ok("the sandbox's cross-origin check is answered, allowing the pass header but no credentials", r.status == 204 and r.headers.get("access-control-allow-origin") == "*" and "x-news-pass" in r.headers.get("access-control-allow-headers", "").lower() and "access-control-allow-credentials" not in r.headers)
    bad_urls = ["http://127.0.0.1/", "http://127.0.0.1:80/admin/", "http://localhost/", "http://localhost./", "http://[::1]/", "http://[::ffff:127.0.0.1]/", "http://169.254.169.254/latest/meta-data/", "http://10.0.0.1/", "http://192.168.1.1/",
                "http://172.16.0.1/", "http://0.0.0.0/", "http://2130706433/", "http://0x7f000001/", "http://127.1/", "file:///etc/passwd", "ftp://example.com/", "gopher://example.com/", "javascript:alert(1)", "http://user:pw@example.com/", "http://example.com:22/", "http://example.com:6379/", "", "not a url"]
    from urllib.parse import quote
    results = {(a, u): G(f"action={a}&url={quote(u, safe='')}") for a in ("feed", "article", "image") for u in bad_urls}
    ok(f"every internal or odd address is refused by all three fetchers ({len(results)} requests): none is fetched", all(r.status == 422 for r in results.values()), [(k, r.status) for k, r in results.items() if r.status != 422][:4])
    ok("the refusals say why, in plain words", "private network" in results[("feed", "http://127.0.0.1/")].json()["error"] and "http and https" in results[("feed", "ftp://example.com/")].json()["error"])
    ok("a name that does not exist is reported, not guessed", G("action=feed&url=" + quote("http://this-host-does-not-exist.invalid/", safe="")).status == 422)
    ok("unknown actions and the wrong method are refused", G("action=unknown").status == 400 and G("action=ai").status == 405 and G("action=pref").status == 405)
    P = lambda q, body, h=None: rq.post(B + "/api/newsroom.php?" + q, data=json.dumps(body), headers=dict(h if h is not None else H, **{"Content-Type": "application/json"}))
    good_ai = {"kind": "text", "body": {"contents": [{"parts": [{"text": "Summarise"}]}]}}
    ok("the AI helper refuses bad requests (pictures from other models, files, other output types, nothing, too big)", all(P("action=ai", x).status == 422 for x in [{"kind": "image", "model": "gemini-1.5-pro", "body": good_ai["body"]}, {"kind": "text", "body": {"contents": [{"parts": [{"inlineData": {"mimeType": "image/png", "data": "AAAA"}}]}]}},
        {"kind": "text", "body": {"contents": [{"parts": [{"text": "x"}]}], "generationConfig": {"responseModalities": ["AUDIO"]}}}, {"kind": "text", "body": {}}, {"kind": "text", "body": {"contents": [{"parts": [{"text": "a" * 100001}]}]}}, {}]))
    r = P("action=ai", good_ai); ok("a good AI request gets as far as needing the company's key (503 here: the test site has none)", r.status == 503 and "not set up" in r.json()["error"], (r.status, r.text()))
    ok("the AI helper also refuses without the pass", P("action=ai", good_ai, {}).status == 401)
    ok("saving works for the two allowed settings, and refuses anything else", P("action=pref", {"k": "cb_bookmarks", "v": json.dumps([{"id": "1", "title": "Saved"}])}).status == 200 and P("action=pref", {"k": "cb_custom_feeds", "v": "[]"}).status == 200
       and all(P("action=pref", x).status == 422 for x in [{"k": "cb_gemini_key", "v": json.dumps("abc")}, {"k": "other", "v": "1"}, {"k": "cb_bookmarks", "v": "not json {"}, {"k": "cb_bookmarks", "v": json.dumps(["a" * 200001])}, {"k": "", "v": ""}]))
    pg.evaluate("reloadNewsroom()"); pg.wait_for_function("[...document.querySelectorAll('iframe')].length > 0"); pg.wait_for_timeout(2500)
    frame2 = [f for f in pg.frames if "newsroom.php" in f.url][0]; prefs2 = frame2.evaluate("window.NEWS.prefs")
    ok("saved bookmarks come back the next time the same person opens the Newsroom", "Saved" in prefs2.get("cb_bookmarks", "") and prefs2.get("cb_custom_feeds") == "[]", prefs2)
    ctx3 = b.new_context(); p3 = sign_in(ctx3, "fs-colleague"); p3.locator("nav button", has_text="Newsroom").click(); p3.wait_for_selector("#newsroom-frame"); p3.wait_for_timeout(2500)
    f3 = [f for f in p3.frames if "newsroom.php" in f.url][0]
    ok("a colleague's Newsroom has none of someone else's bookmarks, and a pass of its own", f3.evaluate("window.NEWS.prefs") == {} and f3.evaluate("window.NEWS.pass") != pas)
    ok("signed out, the page itself is refused", b.new_context().request.get(B + "/admin/newsroom.php", headers={"Sec-Fetch-Dest": "iframe"}).status == 401)
    served = frame2.evaluate("document.documentElement.outerHTML")
    ok("the served page has no key prompt and no Gemini key field", all(x not in served for x in ["window.prompt(", "cb_gemini_key'"]) , "")
    ok("no script errors in the admin", not errs, errs)
    b.close()
print(f"\n{n} checks, {len(fails)} failed"); sys.exit(1 if fails else 0)
