#!/usr/bin/env python3
"""Browser test of the Windows console's screen (operator-console/dist/index.html) against a real server.
The native shell (Tauri) is stubbed and every call to it is recorded; what this cannot show is the native window itself.
Needs: php tests/e2e/console_server.php running, playwright."""
import json, os, sys
from playwright.sync_api import sync_playwright

B = "http://127.0.0.1:18503"; fails = []; n = 0
def ok(what, cond, extra=""):
    global n; n += 1
    print(("  ok: " if cond else "  FAIL: ") + what + ("" if cond else "  -> " + str(extra)[:300]))
    if not cond: fails.append(what)

def make_stub(shape):
    """The native shell as the screen sees it. 'v2' is Tauri 2 (the shape now shipped); 'v1' is Tauri 1, which the screen still supports."""
    return """
window.__SHAPE = '""" + shape + """';
window.__calls = {opened: [], windows: [], focused: 0};
class WV { constructor(label, o) { this.label = label; this.o = o; window.__calls.windows.push({label, url: o.url, title: o.title}); WV.all[label] = this; }
           once() {} show() {} setFocus() { window.__calls.focused++; }
           static getByLabel(l) { const w = WV.all[l] || null; return window.__SHAPE === 'v2' ? Promise.resolve(w) : w; } }
WV.all = {};
const win = { setAlwaysOnTop() { return Promise.resolve(); }, show() { return Promise.resolve(); }, hide() { return Promise.resolve(); }, unminimize() { return Promise.resolve(); }, setFocus() { return Promise.resolve(); },
              requestUserAttention() { return Promise.resolve(); }, setTitle() {}, onCloseRequested() { return Promise.resolve(() => {}); } };
const common = { shell: { open: u => { window.__calls.opened.push(u); return Promise.resolve(); } }, app: { getVersion: () => Promise.resolve("0.20.0") },
  notification: { isPermissionGranted: () => Promise.resolve(true), sendNotification() {}, requestPermission: () => Promise.resolve('granted') }, event: { listen: () => Promise.resolve(() => {}) } };
const WVC = window.__NO_WV ? undefined : WV;
if (window.__SHAPE === 'v2') window.__TAURI__ = Object.assign({}, common, { core: { invoke: () => Promise.resolve(null) }, window: { getCurrentWindow: () => win }, webviewWindow: { WebviewWindow: WVC } });
else window.__TAURI__ = Object.assign({}, common, { invoke: () => Promise.resolve(null), window: { appWindow: win, WebviewWindow: WVC } });
"""

SHAPE = os.environ.get("TAURI_SHAPE", "v2")
STUB = make_stub(SHAPE)

def sign_in(pg, user):
    pg.goto(B + "/_console/index.html"); pg.evaluate(f"localStorage.setItem('opconsole_server', '{B}')"); pg.reload()
    pg.wait_for_selector("#login-user", timeout=8000)
    pg.fill("#login-user", user); pg.fill("#login-pass", "wr-pass-12345"); pg.click("#login-btn")
    pg.wait_for_selector("#sidebar", state="visible", timeout=8000)

with sync_playwright() as p:
    b = p.chromium.launch()
    ctx = b.new_context(viewport={"width": 1400, "height": 900}); ctx.add_init_script(STUB)
    pg = ctx.new_page(); errs = []
    pg.on("pageerror", lambda e: errs.append(str(e)))
    sign_in(pg, "wr-admin")

    print("== the sidebar")
    labels = [x.strip() for x in pg.locator("#nav .nav-item").all_inner_texts()]
    ok("the left column offers Chats, Tickets, Team, Admin, then Writing assistant, File sharing, QR codes & links and Newsroom", [l.split("\n")[0].strip() for l in labels][:8] == ["Chats", "Tickets", "Team", "Admin", "Writing assistant", "File sharing", "QR codes & links", "Newsroom"], labels)
    ok("the existing entries still switch pages", (pg.click("#nav .nav-item[data-page=tickets]") or True) and pg.locator("#page-tickets").is_visible())

    print("== the writing assistant entry opens the admin on its tab")
    pg.click("#nav .nav-item[data-tab=writer]")
    ok("it shows the Admin page in the centre and marks Writing assistant, not Admin, as the current place", pg.locator("#page-admin").is_visible() and "active" in pg.locator("#nav .nav-item[data-tab=writer]").get_attribute("class") and "active" not in pg.locator("#nav .nav-item[data-page=admin]").get_attribute("class") and pg.locator("#nav .nav-item.active").count() == 1)
    fr = pg.frame_locator("#admin-frame")
    fr.locator("#writer-mount [data-w=go]").wait_for(timeout=10000)
    ok("the writing assistant is open inside it, with both modes", fr.locator("#writer-mount [data-w=modeImprove]").is_visible() and fr.locator("#writer-mount [data-w=modeReply]").is_visible())
    pg.screenshot(path="/tmp/console_writer.png")

    print("== File sharing, QR codes & links and Newsroom open in the centre like the rest")
    pg.route("https://qr.blakegroup.uk/**", lambda r: r.fulfill(status=200, content_type="text/html", body="<html><body>QR tool stub</body></html>"))
    pg.route("**/api/newsroom.php*", lambda r: r.fulfill(status=200, content_type="application/json", headers={"access-control-allow-origin": "*"}, body='{"status":"error"}'))
    for tab, label in [("filesharing", "File sharing"), ("newsroom", "Newsroom"), ("qr", "QR codes & links")]:
        pg.click(f"#nav .nav-item[data-tab={tab}]"); fr.locator(f"#tab-{tab}.active").wait_for(timeout=10000)
        ok(f"{label} opens in the centre of the program, and is the highlighted entry", pg.locator("#page-admin").is_visible() and "active" in pg.locator(f"#nav .nav-item[data-tab={tab}]").get_attribute("class") and pg.locator("#nav .nav-item.active").count() == 1)
    fr.frame_locator("#newsroom-frame").locator("body").wait_for(state="attached", timeout=8000)
    ok("the Newsroom page is loaded inside its sandboxed frame", fr.locator("#newsroom-frame").get_attribute("sandbox") is not None and "allow-same-origin" not in fr.locator("#newsroom-frame").get_attribute("sandbox"))
    fr.locator("#qr-frame[src^='https://qr.blakegroup.uk/sso?t=']").wait_for(timeout=10000)
    src1 = fr.locator("#qr-frame").get_attribute("src")
    ok("the QR tool is signed in automatically with a one-time code, with no login asked for and no separate window", len(src1.split("t=")[1]) == 43 and pg.evaluate("window.__calls.windows.length") == 0, src1)
    ok("the QR tool shows inside the centre window", fr.frame_locator("#qr-frame").locator("body").inner_text() == "QR tool stub")
    fr.locator("#tab-qr button", has_text="Reload").click(); pg.wait_for_timeout(1200)
    src2 = fr.locator("#qr-frame").get_attribute("src")
    ok("Reload signs in again with a fresh one-time code", src2 != src1 and src2.startswith("https://qr.blakegroup.uk/sso?t="))
    fr.locator("#tab-qr button", has_text="Open in its own window").click(); pg.wait_for_function("window.__calls.opened.length > 0", timeout=8000)
    o = pg.evaluate("window.__calls.opened")
    ok("Open in its own window hands a fresh signed-in address to the computer's own browser", len(o) == 1 and o[0].startswith("https://qr.blakegroup.uk/sso?t=") and o[0] != src1 and o[0] != src2, o)
    pg.evaluate("window.__calls.opened.length = 0")      # what follows starts from a clean list
    pg.click("#nav .nav-item[data-page=tickets]")
    ok("going back to another page clears the highlight from the tab entries", pg.locator("#nav .nav-item.active").count() == 1 and "active" in pg.locator("#nav .nav-item[data-page=tickets]").get_attribute("class"))

    print("== guides open in the computer's own viewer")
    pg.evaluate("window.__msgs = []; window.addEventListener('message', e => { if (e.data && e.data.openExternal) window.__msgs.push(e.data.openExternal); })")
    pg.click("#nav .nav-item[data-page=admin]"); fr.locator("nav button", has_text="Dashboard").click()
    ok("the admin dashboard has a Guides card with both guides", fr.locator("#tab-dashboard button", has_text="Staff guide (PDF)").count() == 1 and fr.locator("#tab-dashboard button", has_text="QR codes and link pages manual").count() == 1)
    fr.locator("#tab-dashboard button", has_text="Staff guide (PDF)").click(); pg.wait_for_timeout(300)
    ok("pressing Staff guide inside the app sends the request for the guide's address to the app", len(pg.evaluate("window.__msgs")) == 1 and pg.evaluate("window.__msgs")[0].endswith("/docs/blake-support-desk-staff-guide.pdf"), pg.evaluate("window.__msgs"))
    ok("(this test site is not a company address, so the app rightly did not open it)", pg.evaluate("window.__calls.opened") == [])
    fr.locator("body").evaluate("() => parent.postMessage({openExternal: 'https://blakegroup.uk/docs/blake-support-desk-staff-guide.pdf'}, '*')"); pg.wait_for_timeout(300)
    fr.locator("body").evaluate("() => parent.postMessage({openExternal: 'https://qr.blakegroup.uk/admin/manual.pdf'}, '*')"); pg.wait_for_timeout(300)
    ok("a genuine company address from the admin frame is opened in the system viewer", pg.evaluate("window.__calls.opened") == ["https://blakegroup.uk/docs/blake-support-desk-staff-guide.pdf", "https://qr.blakegroup.uk/admin/manual.pdf"], pg.evaluate("window.__calls.opened"))
    pg.evaluate("window.__calls.opened.length = 0")
    for what, url, where in [("another website", "https://evil.example/x.pdf", "frame"), ("an unencrypted address", "http://blakegroup.uk/a.pdf", "frame"), ("a script address", "javascript:alert(1)", "frame"),
                             ("a look-alike address", "https://blakegroup.uk.evil.example/a.pdf", "frame"), ("an address with a quote in it", "https://blakegroup.uk/a\".pdf", "frame"), ("the company address, but not from the admin frame", "https://blakegroup.uk/a.pdf", "top")]:
        if where == "frame": fr.locator("body").evaluate("(b, u) => parent.postMessage({openExternal: u}, '*')", url)
        else: pg.evaluate("(u) => window.postMessage({openExternal: u}, '*')", url)
    pg.wait_for_timeout(400)
    ok("anything else is ignored: other sites, plain http, scripts, look-alikes, quotes, and messages from anywhere but the admin frame", pg.evaluate("window.__calls.opened") == [], pg.evaluate("window.__calls.opened"))

    print("== the conversation with the customer, in a ticket")
    pg.click("#nav .nav-item[data-page=tickets]"); pg.wait_for_selector("#page-tickets tbody tr", timeout=8000)
    pg.click("#page-tickets tbody tr >> nth=0"); pg.wait_for_selector("#conv-panel", timeout=6000)
    pg.wait_for_selector("#conv-list .cmsg", timeout=6000)
    ok("the thread is shown, oldest first, labelled customer and staff", pg.locator("#conv-list .cmsg").count() == 2 and "What should I check" in pg.inner_text("#conv-list .cmsg >> nth=0") and "wr-admin" in pg.inner_text("#conv-list .cmsg.staff"))
    pg.fill("#conv-input", "Thanks Pete. Is the cable <b>visibly damaged</b>?"); pg.click("#conv-send")
    pg.wait_for_function("document.querySelectorAll('#conv-list .cmsg').length === 3", timeout=8000)
    ok("a reply is sent, shown in the thread, and its text is not treated as markup", "visibly damaged" in pg.inner_text("#conv-list .cmsg >> nth=2") and pg.locator("#conv-list b").count() == 0 and pg.input_value("#conv-input") == "" and "emailed" in pg.inner_text("#conv-status"), pg.inner_text("#conv-status"))
    pg.click("#conv-send"); ok("an empty reply is refused with a message", "Type a reply first" in pg.inner_text("#conv-status"))
    pg.once("dialog", lambda d: d.accept()); pg.click("#conv-reset"); pg.wait_for_function("document.getElementById('conv-status').textContent.includes('reset')", timeout=5000)
    ok("the customer's link can be reset", "reset" in pg.inner_text("#conv-status"))
    pg.screenshot(path="/tmp/console_ticket.png")
    ok("no script errors in the console screen", not errs, errs)

    print("== a read-only staff member, and the fallbacks")
    v = b.new_context(viewport={"width": 1400, "height": 900}); v.add_init_script(STUB); vp = v.new_page()
    sign_in(vp, "wr-viewer"); vp.click("#nav .nav-item[data-page=tickets]"); vp.wait_for_selector("#page-tickets tbody tr", timeout=8000)
    vp.click("#page-tickets tbody tr >> nth=0"); vp.wait_for_selector("#conv-panel", timeout=6000); vp.wait_for_selector("#conv-list .cmsg", timeout=6000)
    ok("a read-only role can read the thread but has no reply box", vp.locator("#conv-list .cmsg").count() >= 2 and vp.locator("#conv-input").count() == 0 and "but not reply" in vp.inner_text("#conv-panel"))
    vp.keyboard.press("Escape"); vp.mouse.click(8, 450); vp.wait_for_timeout(500)      # close the ticket panel
    vp.click("#nav .nav-item[data-tab=qr]"); vf = vp.frame_locator("#admin-frame"); vf.locator("#qr-msg").wait_for(state="visible", timeout=8000)
    ok("a read-only role is told it cannot use the QR tool, and no code is issued for it", "cannot use the QR tool" in vf.locator("#qr-msg").inner_text() and not (vf.locator("#qr-frame").get_attribute("src") or "").startswith("https://"))
    b.close()
print(f"\n{n} checks, {len(fails)} failed"); sys.exit(1 if fails else 0)
