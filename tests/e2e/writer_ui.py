#!/usr/bin/env python3
"""Browser test of the staff writing assistant (public/writer/). Needs: php tests/e2e/writer_server.php running, playwright.
Part A: the text handling library, in a real browser. Part B: the page, with the model call faked. Part C: the real endpoint's protections."""
import json, re, sys
from playwright.sync_api import sync_playwright

B = "http://127.0.0.1:18502"; fails = []; n = 0
def ok(what, cond, extra=""):
    global n; n += 1
    print(("  ok: " if cond else "  FAIL: ") + what + ("" if cond else "  -> " + str(extra)[:300]))
    if not cond: fails.append(what)

with sync_playwright() as p:
    b = p.chromium.launch()
    ctx = b.new_context(viewport={"width": 1280, "height": 1000}, permissions=["clipboard-read", "clipboard-write"])
    pg = ctx.new_page(); errs = []
    pg.on("pageerror", lambda e: errs.append(str(e))); pg.on("console", lambda m: errs.append(m.text) if m.type == "error" and "status of 401" not in m.text and "status of 422" not in m.text else None)
    pg.goto(B + "/writer/")
    ev = lambda js, arg=None: pg.evaluate(js, arg)

    print("== A. text handling")
    H2T = "(h) => { const d = new DOMParser().parseFromString(h, 'text/html'); return WriterLib.htmlToText(d.body); }"
    outlook = '<p class=MsoNormal>Hi <b>Sam</b>,</p><p>Please see <a href="https://www.blake-uk.com/support.html">https://www.blake-uk.com/support.html</a> and email <a href="mailto:sales@blake-uk.com">sales@blake-uk.com</a>.</p><ul><li>One</li><li>Two <i>item</i></li></ul><p>Thanks,<br>Dan</p>'
    ok("Outlook-style HTML becomes the light text format", ev(H2T, outlook) == "Hi **Sam**,\n\nPlease see https://www.blake-uk.com/support.html and email sales@blake-uk.com.\n\n- One\n- Two *item*\n\nThanks,\nDan", ev(H2T, outlook))
    ok("a link with its own label keeps both", ev(H2T, '<p>See <a href="https://x.example/a">our site</a></p>') == "See [our site](https://x.example/a)")
    ok("an unsafe link is reduced to its text", ev(H2T, '<p><a href="javascript:alert(1)">click</a></p>') == "click")
    ok("bold and italic written as styles (Word, web mail) are found", ev(H2T, '<p><span style="font-weight:700">bold</span> and <span style="font-style:italic">slanted</span></p>') == "**bold** and *slanted*")
    ok("nested bold is not doubled", ev(H2T, '<p><b><strong>x</strong></b></p>') == "**x**")
    ok("spaces inside bold stay outside the marks", ev(H2T, '<p><b>Hello </b>there</p>') == "**Hello** there")
    ok("scripts and styles are ignored", ev(H2T, '<style>p{color:red}</style><p>Hi</p><script>alert(1)</script>') == "Hi")
    ok("typed lines (browser divs) keep their blank lines", ev(H2T, '<div>Line one</div><div><br></div><div>Line two</div>') == "Line one\n\nLine two")
    ok("a numbered list", ev(H2T, '<ol><li>First</li><li>Second</li></ol>') == "1. First\n2. Second")
    ok("a literal asterisk survives the round trip", ev("(h)=>{const d=new DOMParser().parseFromString(h,'text/html');const t=WriterLib.htmlToText(d.body);const r=document.createElement('div');r.innerHTML=WriterLib.mdToHtml(t);return [t,r.textContent,r.querySelectorAll('em,strong').length];}", '<p>5 * 3 = 15 and 2 * 4</p>') == ["5 \\* 3 = 15 and 2 \\* 4", "5 * 3 = 15 and 2 * 4", 0])
    HTML_SAFE = """(md) => { const r = new DOMParser().parseFromString(WriterLib.mdToHtml(md), 'text/html');
        const bad = [...r.body.querySelectorAll('*')].filter(e => !['P','STRONG','EM','A','UL','OL','LI','BR'].includes(e.tagName) || [...e.attributes].some(a => !['href','style'].includes(a.name)));
        const links = [...r.body.querySelectorAll('a')].map(a => a.getAttribute('href'));
        return {bad: bad.map(e => e.outerHTML), links, text: r.body.textContent}; }"""
    for hostile in ['<img src=x onerror=alert(1)>', '<script>alert(1)</script> **x**', '[a](javascript:alert(1))', '[a](https://ok.example/"onmouseover="alert(1))', '[a](data:text/html,<b>)', '**<b onclick=1>**']:
        r = ev(HTML_SAFE, hostile)
        ok(f"hostile text is only ever shown as text: {hostile[:40]}", r["bad"] == [] and not any(l and l.lower().startswith(("javascript", "data")) for l in r["links"]), r)
    ok("formatting is rendered", ev("() => WriterLib.mdToHtml('Hi **Sam** and *Dan*\\n\\n- a\\n- b\\n\\n1. x\\n2. y\\n\\n[site](https://a.example)')") == '<p style="margin:0 0 1em 0">Hi <strong>Sam</strong> and <em>Dan</em></p><ul style="margin:0 0 1em 0"><li>a</li><li>b</li></ul><ol style="margin:0 0 1em 0"><li>x</li><li>y</li></ol><p style="margin:0 0 1em 0"><a href="https://a.example">site</a></p>')
    ok("plain copy drops the marks and keeps list markers and addresses", ev("() => WriterLib.mdToPlain('Hi **Sam**, see [site](https://a.example)\\n\\n- one\\n- *two*\\n\\n5 \\\\* 3')") == "Hi Sam, see site (https://a.example)\n\n- one\n- two\n\n5 * 3")
    ok("round trip: text -> html -> text is stable", ev("""() => ['Hi **Sam**,\\n\\nSee [site](https://a.example) now.\\n\\n- one\\n- two\\n\\nThanks,\\nDan', 'Plain line\\nsecond line\\n\\nNew paragraph'].map(t => { const d = document.createElement('div'); d.innerHTML = WriterLib.mdToHtml(t); return WriterLib.htmlToText(d) === t; })""") == [True, True])
    ok("typed text is shown as typed (no marks read)", ev("() => WriterLib.plainToHtml('a **b** <i>c</i>\\nnext\\n\\nsecond')") == '<p style="margin:0 0 1em 0">a **b** &lt;i&gt;c&lt;/i&gt;<br>next</p><p style="margin:0 0 1em 0">second</p>')
    d = ev("() => WriterLib.diffHtml('Hi Sam, thanks for the recieve today', 'Hi Sam, thanks for the receipt today')")
    ok("the comparison marks removed and added words", "<del>recieve</del>" in d and "<ins>receipt</ins>" in d and d.startswith("Hi Sam, thanks for the"), d)
    ok("identical text has no marks", "<ins>" not in ev("() => WriterLib.diffHtml('same words here', 'same words here')"))
    ok("a huge text is declined rather than freezing the page", ev("() => WriterLib.diffHtml('a '.repeat(3000), 'b '.repeat(3000))") is None)

    print("== B. the page")
    ok("not signed in: the sign-in card shows", pg.locator("#signin").is_visible() and not pg.locator("#tool").is_visible())
    pg.fill("#u", "wr-admin"); pg.fill("#p", "wrong-password"); pg.click("#signin-form button")
    pg.wait_for_timeout(500); ok("a wrong password is refused with a message", "Invalid" in pg.inner_text("#signin-error"), pg.inner_text("#signin-error"))
    pg.fill("#p", "wr-pass-12345"); pg.click("#signin-form button"); pg.wait_for_selector("#tool:visible", timeout=5000)
    ok("signing in shows the tool and who is signed in", "wr-admin" in pg.inner_text("#who") and pg.locator("#signout").is_visible())

    paste = """(h) => { const dt = new DataTransfer(); dt.setData('text/html', h); dt.setData('text/plain', 'x');
        document.getElementById('input').focus();
        document.getElementById('input').dispatchEvent(new ClipboardEvent('paste', {clipboardData: dt, bubbles: true, cancelable: true})); }"""
    pg.evaluate(paste, '<p>Hi <b>Sam</b>,</p><p>Can you recieve the order <i>4471</i> by 12/10/2026? <img src=x onerror="window.__pwned=1"><script>window.__pwned=1</script></p><ul><li>one</li><li>two</li></ul>')
    html = pg.inner_html("#input")
    ok("pasted formatting is kept in the box, rebuilt from safe parts only", "<strong>Sam</strong>" in html and "<em>4471</em>" in html and "<li>one</li>" in html and "<img" not in html and "<script" not in html and not pg.evaluate("window.__pwned"), html)
    ok("the character count shows", "of 6,000" in pg.inner_text("#count"))

    sent = {}
    def fake(route):
        sent.update(json.loads(route.request.post_data)); 
        route.fulfill(status=200, content_type="application/json", body=json.dumps({"improved": "Hi **Sam**,\n\nCan you receive the order *4471* by 12/10/2026?\n\n- one\n- two", "level": "light",
            "changes": [{"change": "\"recieve\" to \"receive\"", "why": "It was a spelling mistake."}, {"change": "Added a question mark", "why": "It is a question."}],
            "warnings": ["The improved version has a figure, date or price (\"13/10/2026\") that was not in your message."]}))
    pg.route("**/api/writer.php", fake)
    pg.select_option("#audience", "supplier"); pg.click("#go"); pg.wait_for_selector("#result:visible", timeout=5000)
    ok("what is sent: the text in the light format, the recipient and the sign-in token", sent.get("text", "").startswith("Hi **Sam**,") and "*4471*" in sent["text"] and sent.get("audience") == "supplier" and len(sent.get("csrf", "")) > 10, sent)
    ok("the result shows the level, the improved message with formatting, and the reasons", pg.inner_text("#level") == "Light edit" and "<strong>Sam</strong>" in pg.inner_html("#improved") and "<li>two</li>" in pg.inner_html("#improved") and pg.locator("#changes li").count() == 2 and "spelling mistake" in pg.inner_text("#changes"))
    ok("a warning about changed facts is shown", pg.locator("#warnings").is_visible() and "13/10/2026" in pg.inner_text("#warnings"))
    pg.check("#showdiff"); d = pg.inner_html("#improved")
    ok("'mark the changes' shows removed and added words", "<del>recieve</del>" in d and "<ins>receive</ins>" in d, d)
    pg.uncheck("#showdiff")
    pg.screenshot(path="/tmp/writer_desktop.png", full_page=True)

    pg.click("#copy"); pg.wait_for_selector("#copied:visible", timeout=3000)
    clip = pg.evaluate("""async () => { const items = await navigator.clipboard.read(); const t = items[0].types; const o = {types: t};
        o.html = t.includes('text/html') ? await (await items[0].getType('text/html')).text() : null; o.plain = t.includes('text/plain') ? await (await items[0].getType('text/plain')).text() : null; return o; }""")
    ok("copy puts real formatting AND plain text on the clipboard", "text/html" in clip["types"] and "text/plain" in clip["types"] and "<strong>Sam</strong>" in clip["html"] and "<ul" in clip["html"] and "**" not in clip["plain"] and "- one" in clip["plain"], clip)
    pg.click("#copyplain"); pg.wait_for_timeout(300)
    ok("plain copy has no formatting marks", pg.evaluate("navigator.clipboard.readText()") == "Hi Sam,\n\nCan you receive the order 4471 by 12/10/2026?\n\n- one\n- two")

    pg.click("#again"); ok("'Edit this version' puts the result back in the box", "<strong>Sam</strong>" in pg.inner_html("#input") and not pg.locator("#result").is_visible())
    pg.unroute("**/api/writer.php")
    pg.route("**/api/writer.php", lambda r: r.fulfill(status=422, content_type="application/json", body=json.dumps({"error": "This looks like it contains a payment card number. Please remove it before checking the message."})))
    pg.click("#go"); pg.wait_for_selector("#error:visible", timeout=3000)
    ok("a refused message shows the reason and no result", "card number" in pg.inner_text("#error") and not pg.locator("#result").is_visible())
    pg.unroute("**/api/writer.php")
    pg.route("**/api/writer.php", lambda r: r.fulfill(status=200, content_type="application/json", body=json.dumps({"improved": "Same.", "level": "none", "changes": [], "warnings": []})))
    pg.click("#go"); pg.wait_for_selector("#result:visible", timeout=3000)
    ok("when nothing needs changing it says so", pg.inner_text("#level") == "No changes needed" and pg.locator("#nochange").is_visible())
    pg.unroute("**/api/writer.php")
    pg.route("**/api/writer.php", lambda r: r.fulfill(status=401, content_type="application/json", body=json.dumps({"error": "Unauthorised"})))
    pg.click("#go"); pg.wait_for_timeout(500)
    ok("a session that has run out sends you back to sign in", pg.locator("#signin").is_visible() and not pg.locator("#tool").is_visible())
    ok("no script errors on the page", not errs, errs)

    print("== C. the real endpoint")
    ctx2 = b.new_context(); r = ctx2.request
    ok("signed out: refused (401)", r.post(B + "/api/writer.php", data=json.dumps({"text": "hello"}), headers={"Content-Type": "application/json"}).status == 401)
    login = r.post(B + "/api/admin/login.php", data=json.dumps({"username": "wr-viewer", "password": "wr-pass-12345"}), headers={"Content-Type": "application/json"}).json()
    tok = login["csrf"]
    post = lambda body, t=None: r.post(B + "/api/writer.php", data=json.dumps(dict(body, csrf=tok if t is None else t)), headers={"Content-Type": "application/json"})
    ok("a read-only staff role may use it (it is a writing aid, not an admin function)", post({"text": "hello there"}).status in (200, 503), post({"text": "hello there"}).status)
    ok("without the sign-in token: refused (403)", post({"text": "hello"}, "wrong-token").status == 403)
    ok("GET is not allowed (405)", r.get(B + "/api/writer.php").status == 405)
    for what, body, want in [("empty", {"text": "   "}, "Paste or type"), ("a card number", {"text": "my card 4111 1111 1111 1111"}, "card number"), ("too long", {"text": "a" * 6001}, "too long"), ("unknown recipient", {"text": "hi", "audience": "the_king"}, "who the message is for")]:
        x = post(body); ok(f"{what}: refused before any paid call (422) with a clear message", x.status == 422 and want in x.json().get("error", ""), (x.status, x.text()[:150]))
    ok("a good message passes the checks and reaches the model step (503 here: no Gemini key on this test site)", post({"text": "hi sam, can you send the invoice"}).status == 503)
    codes = [post({"text": "  "}).status for _ in range(20)]
    ok("rapid requests are rate limited (429)", 429 in codes, codes)
    mobile = b.new_context(viewport={"width": 390, "height": 844}, device_scale_factor=2, is_mobile=True); mp = mobile.new_page()
    mp.goto(B + "/writer/"); mp.fill("#u", "wr-admin"); mp.fill("#p", "wr-pass-12345"); mp.click("#signin-form button"); mp.wait_for_selector("#tool:visible", timeout=5000)
    mp.route("**/api/writer.php", fake); mp.evaluate(paste, '<p>Hi <b>Sam</b>, can you recieve it?</p>'); mp.click("#go"); mp.wait_for_selector("#result:visible")
    ok("on a phone: no sideways scrolling", mp.evaluate("document.documentElement.scrollWidth <= window.innerWidth"))
    mp.screenshot(path="/tmp/writer_phone.png", full_page=True)
    b.close()
print(f"\n{n} checks, {len(fails)} failed"); sys.exit(1 if fails else 0)
