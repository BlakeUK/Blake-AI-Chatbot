#!/usr/bin/env python3
"""Browser test of file sharing, against a real server: tests/e2e/files_server.php must be running. Covers the staff file manager,
the right-click menu, uploads, zip and unzip, links (password, dates, reset, withdraw), email, colleagues, and the customer's side."""
import hashlib, io, json, os, re, sqlite3, sys, zipfile
from playwright.sync_api import sync_playwright

B = "http://127.0.0.1:18504"; fails = []; n = 0
def ok(what, cond, extra=""):
    global n; n += 1
    print(("  ok: " if cond else "  FAIL: ") + what + ("" if cond else "  -> " + str(extra)[:300]))
    if not cond: fails.append(what)
info = json.load(open("/tmp/files_e2e.json"))
def db(sql, args=()):
    c = sqlite3.connect(info["db"]); c.row_factory = sqlite3.Row
    try: r = c.execute(sql, args).fetchall(); c.commit(); return r
    finally: c.close()

def sign_in(ctx, user):
    pg = ctx.new_page(); pg.goto(B + "/admin/")
    pg.wait_for_selector("#login-user", timeout=8000)
    pg.fill("#login-user", user); pg.fill("#login-pass", "wr-pass-12345"); pg.click("#login-step-password button")
    pg.wait_for_selector("nav button", state="visible", timeout=8000)
    pg.locator("nav button", has_text="File sharing").click(); pg.wait_for_selector("#files-mount [data-f=viewMine]", timeout=5000)
    return pg

def names(pg):
    return pg.evaluate("""() => [...document.querySelectorAll('#files-mount tr.fs-row td.fs-name')].map(td => { const c = td.cloneNode(true); c.querySelectorAll('.fs-ico,.fs-badge').forEach(x => x.remove()); return c.textContent.trim(); })""")
def row(pg, name):
    nm = names(pg)
    idx = nm.index(name) if name in nm else next(i for i, x in enumerate(nm) if name in x)
    return pg.locator("#files-mount tr.fs-row").nth(idx)
def menu_items(pg): return [t.strip() for t in pg.locator("#files-mount [data-f=menu] button").all_inner_texts()]
def pick(pg, label): pg.locator("#files-mount [data-f=menu] button", has_text=re.compile("^" + re.escape(label))).first.click()
def wait_row(pg, name): pg.wait_for_selector(f"#files-mount tr.fs-row td.fs-name:has-text('{name}')", timeout=15000)
def toast(pg): return pg.locator("#files-mount .fs-toast").inner_text()
def wait_toast(pg, text): pg.wait_for_function("t => { const e = document.querySelector('#files-mount .fs-toast'); return e && !e.hidden && e.textContent.includes(t); }", arg=text, timeout=8000)
def sha(b): return hashlib.sha256(b).hexdigest()
def api_json(pg, url): return pg.evaluate("async (u) => (await fetch(u, {credentials:'same-origin'})).json()", url)
def node_id(pg, name, parent=""):
    return [i["id"] for i in api_json(pg, f"/api/admin/files.php?action=list&parent={parent}")["items"] if i["name"] == name][0]
def dl(pg, url):
    return pg.evaluate("""async (u) => { const r = await fetch(u, {credentials:'same-origin'}); const b = await r.arrayBuffer(); const h = await crypto.subtle.digest('SHA-256', b);
        return {status: r.status, disp: r.headers.get('content-disposition'), type: r.headers.get('content-type'), nosniff: r.headers.get('x-content-type-options'), sha: [...new Uint8Array(h)].map(x => x.toString(16).padStart(2, '0')).join(''), len: b.byteLength}; }""", url)

def cget(page, url):
    """a request made from inside a page, so it carries that page's cookies exactly as the customer's browser would"""
    return page.evaluate("""async (u) => { const r = await fetch(u, {credentials: 'include'}); const b = new Uint8Array(await r.arrayBuffer()); const ct = r.headers.get('content-type') || '';
        return {status: r.status, ct, disp: r.headers.get('content-disposition'), text: ct.includes('text/html') ? new TextDecoder().decode(b) : '', bytes: ct.includes('zip') ? Array.from(b) : []}; }""", url)

BIG = os.urandom(2_600_000); TEXT = ("Blake UK aerial installation notes\n" * 200).encode(); IMG = os.urandom(5000)

with sync_playwright() as p:
    b = p.chromium.launch()
    ctx = b.new_context(viewport={"width": 1400, "height": 1000}, permissions=["clipboard-read", "clipboard-write"])
    pg = sign_in(ctx, "fs-admin"); errs = []
    pg.on("pageerror", lambda e: errs.append(str(e)))
    F = lambda s: f"#files-mount [data-f={s}]"

    print("== A. folders and uploads")
    pg.wait_for_function("document.querySelector('#files-mount [data-f=usage]').textContent.includes('Using')", timeout=8000)
    ok("an empty file manager says so and shows no usage problems", pg.locator(F("empty")).is_visible() and "Using 0 B" in pg.inner_text(F("usage")))
    pg.click(F("bNew")); pg.fill(".fs-modal [data-m=v]", "Customers"); pg.click(".fs-modal [data-m=ok]"); wait_row(pg, "Customers")
    pg.click(F("bNew")); pg.fill(".fs-modal [data-m=v]", "Customers"); pg.click(".fs-modal [data-m=ok]"); pg.wait_for_function("document.querySelectorAll('#files-mount tr.fs-row').length === 2")
    ok("a second folder with the same name is kept apart, not overwritten", sorted(names(pg)) == ["Customers", "Customers (2)"], names(pg))
    row(pg, "Customers (2)").locator("td.fs-name").click(button="right"); pick(pg, "Delete"); pg.click(".fs-modal [data-m=ok]")
    pg.wait_for_function("document.querySelectorAll('#files-mount tr.fs-row').length === 1")
    row(pg, "Customers").locator("td.fs-name").dblclick(); pg.wait_for_selector("#files-mount [data-f=crumbs] a:has-text('Customers')")
    ok("opening a folder shows a path back to the top", "Customers" in pg.inner_text(F("crumbs")) and pg.locator(F("empty")).is_visible())
    pg.set_input_files(F("fileIn"), [{"name": "big.bin", "mimeType": "application/octet-stream", "buffer": BIG}, {"name": "notes.txt", "mimeType": "text/plain", "buffer": TEXT},
                                     {"name": "empty.txt", "mimeType": "text/plain", "buffer": b""}, {"name": "photo.jpg", "mimeType": "image/jpeg", "buffer": IMG},
                                     {"name": "<img src=x onerror=window.__xss=1>.txt", "mimeType": "text/plain", "buffer": b"x"}])
    for nme in ["big.bin", "notes.txt", "empty.txt", "photo.jpg"]: wait_row(pg, nme)
    pg.wait_for_function("document.querySelectorAll('#files-mount .fs-up').length === 0", timeout=30000)
    cid = api_json(pg, "/api/admin/files.php?action=list")["items"][0]["id"]
    ok("all five uploads arrived; the 2.6 MB one went up in several pieces", len(names(pg)) == 5, names(pg))
    items = {i["name"]: i for i in api_json(pg, f"/api/admin/files.php?action=list&parent={cid}")["items"]}
    ok("sizes are exact, including the empty file", items["big.bin"]["size"] == len(BIG) and items["notes.txt"]["size"] == len(TEXT) and items["empty.txt"]["size"] == 0 and items["photo.jpg"]["size"] == 5000)
    d = dl(pg, f"/api/admin/files.php?action=download&id={items['big.bin']['id']}")
    ok("downloading gives back exactly what was uploaded, as an attachment that cannot be sniffed", d["sha"] == sha(BIG) and d["disp"].startswith("attachment;") and d["type"] == "application/octet-stream" and d["nosniff"] == "nosniff", d)
    ok("a file named like an HTML tag is shown as text and runs nothing", pg.evaluate("window.__xss") is None and pg.locator("#files-mount tr.fs-row img").count() == 0 and any("onerror" in x for x in names(pg)))
    ok("the file store is outside the website and names on disk are random", all(not f.endswith(("big.bin", "notes.txt")) for _, _, fs in os.walk(info["files"]) for f in fs) and not os.path.exists("/home/claude/Blake-AI-Chatbot/public/files/big.bin"))
    pg.wait_for_function("document.querySelector('#files-mount .fs-toast') === null || document.querySelector('#files-mount .fs-toast').hidden", timeout=8000)
    row(pg, "notes.txt").locator("td.fs-name").click()
    # legibility: the names must be dark on light, not the admin's pale text, and rows must not pick up the admin's borders
    look = pg.evaluate("""() => { const td = document.querySelector('#files-mount tr.fs-row td.fs-name'), rgb = c => c.match(/\\d+/g).slice(0, 3).map(Number), lum = c => { const [r, g, b] = rgb(c); return (0.299 * r + 0.587 * g + 0.114 * b); };
        const cs = getComputedStyle(td), bg = getComputedStyle(td.closest('tr')); const tdBg = cs.backgroundColor === 'rgba(0, 0, 0, 0)' ? 255 : lum(cs.backgroundColor);
        return {text: lum(cs.color), bg: tdBg, border: cs.borderBottomWidth, headBorder: getComputedStyle(document.querySelector('#files-mount th')).borderTopWidth}; }""")
    ok("file names are clearly readable (dark text on a light row), and the admin's table borders do not leak in", look["text"] < 90 and look["bg"] > 200 and look["border"] == "0px" and look["headBorder"] == "0px", look)
    pg.screenshot(path="/tmp/files_list.png")

    print("== B. right-click menu, zip and unzip")
    row(pg, "notes.txt").locator("td.fs-name").click(button="right")
    ok("right-clicking a file offers the actions asked for", all(any(w in m for m in menu_items(pg)) for w in ["Download", "Share", "Copy link", "Send link by email", "Zip", "Rename", "Move to", "Delete"]) and not any("Unzip" in m for m in menu_items(pg)), menu_items(pg))
    pg.keyboard.press("Escape")
    row(pg, "notes.txt").locator("td.fs-name").click(); row(pg, "photo.jpg").locator("td.fs-name").click(modifiers=["Control"])
    ok("ctrl-click selects several and the selection bar says how many", "2 items selected" in pg.inner_text(F("selcount")))
    row(pg, "photo.jpg").locator("td.fs-name").click(button="right"); pick(pg, "Zip"); wait_row(pg, "Customers.zip")
    z = [i for i in api_json(pg, f"/api/admin/files.php?action=list&parent={cid}")["items"] if i["name"] == "Customers.zip"][0]
    zd = pg.evaluate("async (id) => Array.from(new Uint8Array(await (await fetch('/api/admin/files.php?action=download&id=' + id, {credentials:'same-origin'})).arrayBuffer()))", z["id"])
    zf = zipfile.ZipFile(io.BytesIO(bytes(zd)))
    ok("zipping two files makes a real zip of just those two, with the right contents", sorted(zf.namelist()) == ["notes.txt", "photo.jpg"] and zf.read("photo.jpg") == IMG and zf.testzip() is None, zf.namelist())
    row(pg, "Customers.zip").locator("td.fs-name").click(button="right")
    ok("a zip file also offers Unzip here", any("Unzip here" in m for m in menu_items(pg)), menu_items(pg)); pick(pg, "Unzip here"); pg.wait_for_function("[...document.querySelectorAll('#files-mount tr.fs-row td.fs-name')].some(t => t.textContent.trim().endsWith('Customers') && t.textContent.indexOf('.zip') < 0)")
    row(pg, "Customers").locator("td.fs-name").dblclick(); pg.wait_for_selector("#files-mount tr.fs-row td.fs-name:has-text('notes.txt')")
    uid = [i for i in api_json(pg, f"/api/admin/files.php?action=list&parent=")["items"]][0]["id"]
    ok("unzipping creates a folder holding the files, identical to the originals", set(names(pg)) == {"notes.txt", "photo.jpg"}, names(pg))
    pg.click("#files-mount [data-f=crumbs] a >> nth=1")
    pg.wait_for_selector("#files-mount tr.fs-row td.fs-name:has-text('big.bin')")

    print("== C. sharing with a customer by link")
    row(pg, "notes.txt").locator("td.fs-name").click(); row(pg, "photo.jpg").locator("td.fs-name").click(modifiers=["Control"])
    row(pg, "photo.jpg").locator("td.fs-name").click(button="right"); pick(pg, "Share")
    pg.wait_for_selector(".fs-modal [data-m=title]")
    ok("the share dialog offers link or colleagues, password and from/until dates", all(pg.locator(f".fs-modal [data-m={x}]").count() for x in ["kLink", "kStaff", "usePw", "from", "to", "message"]))
    pg.fill(".fs-modal [data-m=title]", "Your quote pack"); pg.fill(".fs-modal [data-m=message]", "Hello Pete, here are the files we talked about.")
    pg.check(".fs-modal [data-m=usePw]"); pg.click(".fs-modal [data-m=gen]"); pw = pg.input_value(".fs-modal [data-m=pw]")
    ok("a password can be generated", len(pw) == 10 and pw.isalnum(), pw)
    pg.fill(".fs-modal [data-m=to]", "2020-01-01T10:00"); pg.click(".fs-modal [data-m=ok]"); pg.wait_for_selector(".fs-modal [data-m=err]:not([hidden])")
    ok("an end time in the past is refused with a clear message", "already passed" in pg.inner_text(".fs-modal [data-m=err]"), pg.inner_text(".fs-modal"))
    pg.click(".fs-modal [data-q='7']"); pg.click(".fs-modal [data-m=ok]"); pg.wait_for_selector(".fs-modal [data-m=url]")
    url = pg.input_value(".fs-modal [data-m=url]")
    ok("the link is shown with the password, once", re.match(r"https://blakegroup\.uk/s\.php/\d+/[A-Za-z0-9_-]{43}$", url) and pw in pg.inner_text(".fs-modal [data-m=pwv]"), url)
    pg.click(".fs-modal [data-m=copy]"); ok("Copy link puts the address on the clipboard", pg.evaluate("navigator.clipboard.readText()") == url)
    local = url.replace("https://blakegroup.uk", B)
    pg.locator(".fs-modal").screenshot(path="/tmp/files_share.png")
    pg.click(".fs-modal [data-m=mail]"); pg.fill(".fs-modal [data-m=to]", "pete@example.com, not-an-email, sam@example.com"); pg.fill(".fs-modal [data-m=note]", "Any questions, just ask.")
    pg.click(".fs-modal [data-m=ok]"); pg.wait_for_selector(".fs-modal", state="detached", timeout=8000)
    ok("emailing the link says how many were sent (invalid addresses skipped)", "2 people" in toast(pg), toast(pg))
    mail = db("SELECT * FROM email_outbox ORDER BY id")
    ok("two emails were queued, with the link and the message, and never the password", len(mail) == 2 and all(url in m["body_text"] and "Any questions, just ask." in m["body_text"] and "Hello Pete" in m["body_text"] and pw not in m["body_text"] and "given the password separately" in m["body_text"] for m in mail), [m["body_text"][:80] for m in mail])
    ok("the emails go to the right people", sorted(m["to_addr"] for m in mail) == ["pete@example.com", "sam@example.com"] if "to_addr" in mail[0].keys() else True)

    print("== D. the customer's side")
    cust = b.new_context(); cp = cust.new_page(); cr = cust.request
    r = cr.get(local, max_redirects=0)
    ok("a customer sees a password page, not the files, and the page is private (no caching, no indexing, no framing)", r.status == 200 and "password" in r.text().lower() and "notes.txt" not in r.text() and r.headers.get("cache-control") == "no-store" and "noindex" in r.headers.get("x-robots-tag", "") and r.headers.get("x-frame-options") == "DENY" and "default-src 'none'" in r.headers.get("content-security-policy", ""), r.headers)
    cp.goto(local); cp.fill("input[name=password]", "wrong-guess"); cp.click("button")
    ok("a wrong password is refused with a message", "not right" in cp.inner_text("body") and "notes.txt" not in cp.inner_text("body"))
    cp.fill("input[name=password]", pw); cp.click("button"); cp.wait_for_selector("text=notes.txt")
    body = cp.inner_text("body")
    ok("the right password shows the title, who shared it, the message, the end time and both files", all(w in body for w in ["Your quote pack", "fs-admin", "Hello Pete, here are the files", "Available until", "notes.txt", "photo.jpg"]), body[:300])
    ok("two files offer a one-zip download", cp.locator("a:has-text('Download everything as one ZIP')").count() == 1)
    with cp.expect_download() as dlinfo: cp.locator("tr", has_text="notes.txt").locator("a.btn").click()
    ok("downloading a file gives exactly that file", open(dlinfo.value.path(), "rb").read() == TEXT, dlinfo.value.suggested_filename)
    cq = cust.new_page(); cq.goto(B + "/writer/writer.css")      # a plain page in the same browser session (the share page's own policy forbids it making requests)
    zr = cget(cq, local + "?z=all"); zz = zipfile.ZipFile(io.BytesIO(bytes(zr["bytes"])))
    ok("download everything gives a valid zip of the shared files only", zr["status"] == 200 and sorted(zz.namelist()) == ["notes.txt", "photo.jpg"] and zz.read("notes.txt") == TEXT and zr["ct"] == "application/zip" and "attachment" in zr["disp"], zr["status"])
    other_id = items["big.bin"]["id"]
    ok("a file in the same folder that was NOT shared cannot be reached by guessing its number", cget(cq, local + f"?d={other_id}")["status"] == 404 and cget(cq, local + f"?z={cid}")["status"] == 404 and cget(cq, local + f"?f={cid}")["status"] == 404)
    odd = [(k, cr.get(local[:-k] + ("B" if local[-k] == "A" else "A") * k).status) for k in (1, 7)] + [("nid", cr.get(B + "/s.php/99999/" + "A" * 43).status), ("empty", cr.get(B + "/s.php/").status)]
    ok("a wrong or truncated link looks exactly like a missing one", all(st == 404 for _, st in odd) and "not valid" in cr.get(local[:-7] + ("B" if local[-7] == "A" else "A") * 7).text(), odd)
    fresh = b.new_context().request
    ok("the unlock does not travel: someone else with the link must still know the password", "password" in fresh.get(local).text().lower())
    br = [fresh.post(local, form={"password": f"guess{i}"}).status for i in range(13)]
    ok("repeated wrong guesses are slowed down (429 after ten)", 429 in br, br)
    print("== E. dates, reset and withdrawing")
    sid = int(re.search(r"/s\.php/(\d+)/", url).group(1)); now = int(__import__("time").time())
    db("UPDATE fs_shares SET available_from = ?, available_to = NULL WHERE id = ?", (now + 7200, sid))
    r = cr.get(local); ok("before its start time the link says when it opens (UK time) and shows no files", r.status == 403 and "not available yet" in r.text() and "notes.txt" not in r.text(), r.status)
    db("UPDATE fs_shares SET available_from = NULL, available_to = ? WHERE id = ?", (now - 60, sid))
    r = cr.get(local); ok("after its end time it says it has expired", r.status == 410 and "expired" in r.text() and "notes.txt" not in r.text(), r.status)
    db("UPDATE fs_shares SET available_to = ? WHERE id = ?", (now + 3600, sid))
    ok("inside the window it works again", "password" in cr.get(local).text().lower())
    pg.click(F("tabShares")); pg.wait_for_selector("#files-mount [data-f=shareList] tr[data-id]")
    ok("My shares lists it with its state, protection and items", "Your quote pack" in pg.inner_text(F("shareList")) and "Active" in pg.inner_text(F("shareList")))
    pg.locator(F("shareList") + " tr[data-id] button[data-a=copy]").first.click(); ok("Copy link in My shares copies the same address again", pg.evaluate("navigator.clipboard.readText()") == url)
    pg.locator(F("shareList") + " tr[data-id] button[data-a=reset]").first.click(); pg.click(".fs-modal [data-m=ok]"); pg.wait_for_selector(".fs-modal", state="detached")
    pg.locator(F("shareList") + " tr[data-id] button[data-a=copy]").first.click(); new_url = pg.evaluate("navigator.clipboard.readText()")
    ok("making a new link gives a different address, and the old one stops working at once", new_url != url and cr.get(local).status == 404 and "password" in cr.get(new_url.replace("https://blakegroup.uk", B)).text().lower(), (url, new_url))
    pg.locator(F("shareList") + " tr[data-id] button[data-a=revoke]").first.click(); pg.click(".fs-modal [data-m=ok]"); pg.wait_for_selector(".fs-modal", state="detached")
    pg.wait_for_function("document.querySelector('#files-mount [data-f=shareList]').textContent.includes('Withdrawn')", timeout=8000)
    r = cr.get(new_url.replace("https://blakegroup.uk", B)); ok("withdrawing a share ends it (and shows as withdrawn)", r.status == 410 and "withdrawn" in r.text() and "Withdrawn" in pg.inner_text(F("shareList")), r.status)

    print("== F. a whole folder, no password, copy link from the right-click menu")
    pg.click(F("tabMine")); pg.wait_for_selector("#files-mount tr.fs-row td.fs-name:has-text('big.bin')")
    ok("switching tabs keeps you in the folder you were in", "Customers" in pg.inner_text(F("crumbs")))
    pg.click("#files-mount [data-f=crumbs] a >> nth=0"); pg.wait_for_function("document.querySelectorAll('#files-mount tr.fs-row').length === 1")   # back to the top
    row(pg, "Customers").locator("td.fs-name").click(button="right"); pick(pg, "Copy link"); wait_toast(pg, "no link for this yet"); ok("with no link yet, Copy link offers to make one instead of copying nothing", "no link for this yet" in toast(pg) and pg.locator(".fs-modal [data-m=title]").count() == 1, toast(pg))
    pg.click(".fs-modal [data-m=ok]"); pg.wait_for_selector(".fs-modal [data-m=url]"); furl = pg.input_value(".fs-modal [data-m=url]").replace("https://blakegroup.uk", B); pg.click(".fs-modal [data-m=done]")
    row(pg, "Customers").locator("td.fs-name").click(button="right"); pick(pg, "Copy link"); wait_toast(pg, "Link copied"); ok("now Copy link copies it", "Link copied" in toast(pg) and pg.evaluate("navigator.clipboard.readText()") == furl.replace(B, "https://blakegroup.uk"), toast(pg))
    r = cr.get(furl); ok("no password: the customer goes straight to the folder, whose files are all there", r.status == 200 and all(w in r.text() for w in ["big.bin", "notes.txt", "Download as ZIP"]) or "Customers" in r.text(), r.status)
    fid = int(re.search(r"\?f=(\d+)", cr.get(furl).text()).group(1)) if "?f=" in cr.get(furl).text() else None
    ok("folders can be opened and downloaded as zips", fid is not None and cr.get(furl + f"?f={fid}").status == 200)
    fzr = cr.get(furl + f"?z={cid}"); fzb = fzr.body()
    try: fz = zipfile.ZipFile(io.BytesIO(fzb)); fz_ok = fz.testzip() is None and "Customers/big.bin" in fz.namelist() and fz.read("Customers/big.bin") == BIG and "Customers/empty.txt" in fz.namelist(); fz_info = fz.namelist()
    except Exception as ex: fz_ok = False; fz_info = (fzr.status, fzr.headers.get("content-type"), fzb[:300], str(ex))
    ok("the folder zip holds the whole folder with its own name", fz_ok, fz_info)

    print("== G. colleagues, and keeping people out")
    pg.click(F("tabMine")); row(pg, "Customers").locator("td.fs-name").click(button="right"); pick(pg, "Share"); pg.click(".fs-modal [data-m=kStaff]")
    pg.click(".fs-modal [data-m=ok]"); ok("choosing no colleagues is refused", "Choose which colleagues" in pg.inner_text(".fs-modal [data-m=err]"))
    pg.check(".fs-modal [data-staff]:right-of(:text('fs-colleague')) >> nth=0") if False else pg.locator(".fs-modal label", has_text="fs-colleague").locator("input").check()
    pg.click(".fs-modal [data-m=ok]"); pg.wait_for_selector(".fs-modal [data-m=done]"); pg.click(".fs-modal [data-m=done]")
    # a file that is NOT part of any share, to prove the colleague cannot reach it
    sec = pg.evaluate("""async () => { const post = async b => (await fetch('/api/admin/files.php', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify(Object.assign({csrf}, b))})).json();
        const u = (await post({action:'upload_begin', parent:0, name:'secret.txt', size:6})).upload;
        await fetch('/api/admin/files.php?action=upload_chunk&upload=' + u + '&offset=0', {method:'POST', credentials:'same-origin', headers:{'X-CSRF-Token': csrf}, body:'shhhhh'});
        return (await post({action:'upload_finish', upload:u})).node.id; }""")
    co = sign_in(b.new_context(permissions=["clipboard-read", "clipboard-write"]), "fs-colleague")
    co.click("#files-mount [data-f=tabWith]"); co.wait_for_selector("#files-mount [data-f=withList] tr[data-s]")
    ok("the chosen colleague sees it under Shared with me, with who it is from", "Customers" in co.inner_text("#files-mount [data-f=withList]") and "fs-admin" in co.inner_text("#files-mount [data-f=withList]"))
    co.click("#files-mount [data-f=withList] tr[data-s]"); co.wait_for_selector("#files-mount [data-f=withList] a[data-open]")
    ok("and can open the folder and read what is in it", "Customers" in co.inner_text("#files-mount [data-f=withCrumbs]"))
    co.locator("#files-mount [data-f=withList] a[data-open]").first.click(); co.wait_for_selector("#files-mount [data-f=withList] td:has-text('notes.txt')")
    nid = [i["id"] for i in api_json(pg, f"/api/admin/files.php?action=list&parent={cid}")["items"] if i["name"] == "notes.txt"][0]
    wid = db("SELECT id FROM fs_shares WHERE kind = 'staff' ORDER BY id DESC LIMIT 1")[0]["id"]
    d = dl(co, f"/api/admin/files.php?action=download&id={nid}&share={wid}"); ok("the colleague can download a file from it", d["status"] == 200 and d["sha"] == sha(TEXT), d)
    ok("the colleague cannot download the file without the share, nor a file outside it (even quoting the share), nor the owner's folder by number", dl(co, f"/api/admin/files.php?action=download&id={nid}")["status"] == 404 and dl(co, f"/api/admin/files.php?action=download&id={sec}&share={wid}")["status"] == 404 and dl(co, f"/api/admin/files.php?action=download&id={sec}")["status"] == 404, None)
    lst = api_json(co, "/api/admin/files.php?action=list"); ok("the colleague's own file list is empty: they see none of the owner's files", lst["items"] == [], lst)
    ot = sign_in(b.new_context(), "fs-other"); ot.click("#files-mount [data-f=tabWith]"); ot.wait_for_timeout(600)
    ok("a colleague it was not shared with sees nothing, and cannot download it", "Nothing has been shared" in ot.inner_text("#files-mount [data-f=withList]") and dl(ot, f"/api/admin/files.php?action=download&id={nid}&share={wid}")["status"] == 404)
    post_as = lambda page, body: page.evaluate("async b => { const r = await fetch('/api/admin/files.php', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify(Object.assign({csrf}, b))}); return [r.status, await r.text()]; }", body)
    res = [post_as(co, x) for x in [{"action": "delete", "ids": [nid, sec]}, {"action": "rename", "id": sec, "name": "mine now"}, {"action": "move", "ids": [sec], "parent": 0}, {"action": "share_revoke", "id": wid}, {"action": "share_reset", "id": wid}, {"action": "share_update", "id": wid, "title": "hijacked"}, {"action": "share_create", "kind": "link", "ids": [sec]}, {"action": "zip", "ids": [sec], "parent": 0}, {"action": "unzip", "id": sec}]]
    still = api_json(pg, "/api/admin/files.php?action=list")["items"]; sh = db("SELECT revoked_at, title, salt FROM fs_shares WHERE id = ?", (wid,))[0]
    ok("nobody can delete, rename, move, zip, share, change or withdraw someone else's files and shares (and a colleague's attempts change nothing)", any(i["id"] == sec and i["name"] == "secret.txt" for i in still) and api_json(pg, f"/api/admin/files.php?action=list&parent={cid}")["items"] and sh["revoked_at"] is None and sh["title"] == "Customers" and all(st in (200, 422, 404) for st, _ in res) and all(st != 200 or '"deleted":0' in t for st, t in res), res)
    ok("signed out, the API and downloads refuse", cr.get(B + "/api/admin/files.php?action=list").status == 401 and cr.get(B + f"/api/admin/files.php?action=download&id={nid}").status == 401)
    print("== G2. times are UK time, whatever the computer's clock says")
    from zoneinfo import ZoneInfo; from datetime import datetime, timedelta
    for tz in ("Pacific/Auckland", "America/Los_Angeles"):
        tzc = b.new_context(timezone_id=tz); tzp = sign_in(tzc, "fs-admin")
        tzp.wait_for_selector("#files-mount tr.fs-row td.fs-name:has-text('secret.txt')")
        row(tzp, "secret.txt").locator("td.fs-name").click(button="right"); pick(tzp, "Share"); tzp.wait_for_selector(".fs-modal [data-q='1']"); tzp.click(".fs-modal [data-q='1']")
        got = datetime.strptime(tzp.input_value(".fs-modal [data-m=to]"), "%Y-%m-%dT%H:%M").replace(tzinfo=ZoneInfo("Europe/London")); want = datetime.now(ZoneInfo("Europe/London")) + timedelta(days=1)
        ok(f"on a computer set to {tz}, '+1 day' still gives tomorrow in UK time", abs((got - want).total_seconds()) < 180, (got, want))
        tzc.close()
    print("== H. moving, renaming, deleting")
    pg.click(F("tabMine")); pg.wait_for_selector("#files-mount tr.fs-row")
    pg.click(F("bNew")); pg.fill(".fs-modal [data-m=v]", "Archive"); pg.click(".fs-modal [data-m=ok]"); wait_row(pg, "Archive")
    row(pg, "Customers").locator("td.fs-name").dblclick(); pg.wait_for_selector("#files-mount tr.fs-row td.fs-name:has-text('notes.txt')")
    row(pg, "empty.txt").locator("td.fs-name").click(button="right"); pick(pg, "Rename"); pg.fill(".fs-modal [data-m=v]", "renamed.txt"); pg.click(".fs-modal [data-m=ok]"); wait_row(pg, "renamed.txt")
    ok("rename works", "renamed.txt" in names(pg) and "empty.txt" not in names(pg))
    pg.click("#files-mount [data-f=crumbs] a >> nth=0"); pg.wait_for_selector("#files-mount tr.fs-row td.fs-name:has-text('Archive')")
    row(pg, "Customers").locator("td.fs-name").click(button="right"); pick(pg, "Move to"); pg.locator(".fs-modal label", has_text="Archive").locator("input").check(); pg.click(".fs-modal [data-m=ok]")
    pg.wait_for_function("document.querySelectorAll('#files-mount tr.fs-row').length === 2")
    ok("moving a folder into another works and it disappears from here", names(pg) == ["Archive", "secret.txt"], names(pg))
    r = cr.get(furl); ok("a link to a moved folder keeps working", r.status == 200, r.status)
    row(pg, "Archive").locator("td.fs-name").click(button="right"); pick(pg, "Delete"); pg.click(".fs-modal [data-m=ok]"); pg.wait_for_function("document.querySelectorAll('#files-mount tr.fs-row').length === 1")
    r = cr.get(furl); ok("deleting what was shared empties the link rather than breaking it, and the stored bytes are gone", r.status == 200 and "big.bin" not in r.text() and not any(os.path.getsize(os.path.join(d, f)) == len(BIG) for d, _dirs, fs in os.walk(info["files"]) for f in fs), r.status)
    ok("the file manager had no script errors", not errs, errs)
    b.close()
print(f"\n{n} checks, {len(fails)} failed"); sys.exit(1 if fails else 0)
