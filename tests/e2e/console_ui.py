#!/usr/bin/env python3
"""Browser test of the Windows console's screen (operator-console/dist/index.html) against a real server.
The native shell (Tauri) is stubbed and every call to it is recorded; what this cannot show is the native window itself.
Needs: php tests/e2e/console_server.php running, playwright."""
import json, sys
from playwright.sync_api import sync_playwright

B = "http://127.0.0.1:18503"; fails = []; n = 0
def ok(what, cond, extra=""):
    global n; n += 1
    print(("  ok: " if cond else "  FAIL: ") + what + ("" if cond else "  -> " + str(extra)[:300]))
    if not cond: fails.append(what)

STUB = """
window.__calls = {opened: [], windows: [], focused: 0};
class WV { constructor(label, o) { this.label = label; this.o = o; window.__calls.windows.push({label, url: o.url, title: o.title}); WV.all[label] = this; }
           once() {} show() {} setFocus() { window.__calls.focused++; } static getByLabel(l) { return WV.all[l] || null; } }
WV.all = {};
window.__TAURI__ = { shell: { open: u => { window.__calls.opened.push(u); return Promise.resolve(); } }, window: { WebviewWindow: window.__NO_WV ? undefined : WV, appWindow: { setTitle() {}, show() {}, setFocus() {}, hide() {}, onCloseRequested() { return Promise.resolve(() => {}); } } },
  app: { getVersion: () => Promise.resolve('0.18.0') }, invoke: () => Promise.resolve(null), notification: { isPermissionGranted: () => Promise.resolve(true), sendNotification() {}, requestPermission: () => Promise.resolve('granted') }, event: { listen: () => Promise.resolve(() => {}) } };
"""

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
    ok("the sidebar offers Chats, Tickets, Team, Admin and the two new entries", [l.split("\n")[0].strip() for l in labels][:6] == ["Chats", "Tickets", "Team", "Admin", "Writing assistant", "QR codes & links"], labels)
    ok("the existing entries still switch pages", (pg.click("#nav .nav-item[data-page=tickets]") or True) and pg.locator("#page-tickets").is_visible())

    print("== the writing assistant entry opens the admin on its tab")
    pg.click("#nav .nav-item[data-action=writer]")
    ok("it shows the Admin page and marks it as the current place", pg.locator("#page-admin").is_visible() and pg.locator("#nav .nav-item[data-page=admin]").get_attribute("class").count("active") == 1)
    fr = pg.frame_locator("#admin-frame")
    fr.locator("#writer-mount [data-w=go]").wait_for(timeout=10000)
    ok("the writing assistant is open inside it, with both modes", fr.locator("#writer-mount [data-w=modeImprove]").is_visible() and fr.locator("#writer-mount [data-w=modeReply]").is_visible())
    pg.screenshot(path="/tmp/console_writer.png")

    print("== the QR codes entry")
    pg.click("#nav .nav-item[data-action=qr]")
    c = pg.evaluate("window.__calls")
    ok("it opens the QR tool in its own app window, titled and addressed correctly", len(c["windows"]) == 1 and c["windows"][0]["label"] == "qrtool" and c["windows"][0]["url"] == "https://qr.blakegroup.uk/admin/" and "QR" in c["windows"][0]["title"], c)
    pg.click("#nav .nav-item[data-action=qr]")
    c = pg.evaluate("window.__calls")
    ok("pressing it again brings that window forward instead of opening a second", len(c["windows"]) == 1 and c["focused"] == 1 and c["opened"] == [], c)

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
    nw = b.new_context(viewport={"width": 1400, "height": 900}); nw.add_init_script("window.__NO_WV = true;" + STUB); np_ = nw.new_page()
    sign_in(np_, "wr-admin"); np_.click("#nav .nav-item[data-action=qr]")
    c = np_.evaluate("window.__calls")
    ok("if the app cannot make a window, the QR tool opens in the normal browser instead", c["opened"] == ["https://qr.blakegroup.uk/admin/"] and c["windows"] == [], c)
    b.close()
print(f"\n{n} checks, {len(fails)} failed"); sys.exit(1 if fails else 0)
