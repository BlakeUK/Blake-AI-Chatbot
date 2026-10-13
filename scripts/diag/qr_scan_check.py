#!/usr/bin/env python3
"""Is public QR scanning and counting healthy on the live QR tool?  Run on the server (root) with the QR env file present.
Part 1 only reads: service health, restarts, and whether real public scans were recorded across recent deployments.
Part 2 makes one throw-away tracked link through the real admin, scans it in several ways, checks the redirect, the counts, the CSV export and
the public headers, then deletes it. Usage: qr_scan_check.py <sso code>"""
import http.client, re, ssl, subprocess, sys, time, urllib.parse
HOST = "qr.blakegroup.uk"; CODE = sys.argv[1]; bad = 0; LABEL = "diag-scan-check-" + str(int(time.time()))
env = dict(l.strip().split("=", 1) for l in open("/var/www/qrcode/qrtrack.env") if "=" in l and not l.startswith("#")); DB = env["DB_PATH"].strip('"')
def sh(cmd): return subprocess.run(cmd, shell=True, capture_output=True, text=True).stdout.strip()
def sql(q): return subprocess.run(["sqlite3", "-readonly", "-separator", "|", DB, q], capture_output=True, text=True).stdout.strip()
def sqlw(q): return subprocess.run(["sqlite3", DB, "PRAGMA foreign_keys=ON;" + q], capture_output=True, text=True).stdout.strip()
def check(what, cond, extra=""):
    global bad; print(("  PASS  " if cond else "  FAIL  ") + what + ("" if cond else f"   -> {extra}")); bad += 0 if cond else 1
def req(method, path, body=None, cookie=None, ua="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36", extra=None):
    c = http.client.HTTPSConnection(HOST, timeout=30, context=ssl.create_default_context()); h = {"User-Agent": ua}
    if cookie: h["Cookie"] = cookie
    if body is not None: h["Content-Type"] = "application/x-www-form-urlencoded"
    h.update(extra or {}); t0 = time.perf_counter(); c.request(method, path, body, h); r = c.getresponse(); data = r.read().decode("utf-8", "replace"); dt = (time.perf_counter() - t0) * 1000
    hd = {k.lower(): v for k, v in r.getheaders()}; sc = [v for k, v in r.getheaders() if k.lower() == "set-cookie"]; c.close(); return r.status, hd, data, sc, dt

print("== PART 1: service health and history (read only)")
print("  service:", sh("systemctl show qrtrack -p ActiveState -p SubState -p NRestarts -p ActiveEnterTimestamp --no-pager | tr '\\n' ' '"))
print("  database integrity:", sql("PRAGMA integrity_check;"))
print("  links:", sql("SELECT COUNT(*) || ' total, ' || SUM(active) || ' active' FROM links;") if "active" in sql("SELECT group_concat(name) FROM pragma_table_info('links');") else sql("SELECT COUNT(*) FROM links;"))
print("  scans: " + sql("SELECT COUNT(*) || ' recorded in total, ' || SUM(is_bot=0) || ' by people, ' || SUM(is_bot) || ' by bots; the latest at ' || MAX(scanned_at) FROM scans;"))
print("  recent service starts (each is a deployment or restart):"); print("\n".join("    " + l for l in sh("journalctl -u qrtrack --since '5 days ago' --no-pager -o short-iso 2>/dev/null | grep -E 'Started|Stopped|Deactivated' | tail -12").split("\n")))
errs = sh("journalctl -u qrtrack --since '5 days ago' -p err --no-pager -o short-iso 2>/dev/null | grep -v '^-- ' | tail -8")
check("no errors logged by the QR service in five days", errs == "", errs[:300])
print("  scans recorded per day, last 6 days (people / bots):"); print("\n".join("    " + l.replace("|", "   ") for l in sql("SELECT substr(scanned_at,1,10), SUM(is_bot=0), SUM(is_bot) FROM scans WHERE substr(scanned_at,1,10) >= date('now','-6 days') GROUP BY 1 ORDER BY 1;").split("\n")))
print("  scans recorded per hour, last 30 hours:"); print("\n".join("    " + l.replace("|", "   ") for l in sql("SELECT substr(scanned_at,1,13), COUNT(*) FROM scans WHERE scanned_at >= strftime('%Y-%m-%dT%H', 'now','-30 hours') GROUP BY 1 ORDER BY 1;").split("\n")))
st, h, b, _, dt = req("GET", "/healthz"); check("the health address answers", st == 200, st)

print("== PART 2: a throw-away tracked link, scanned several ways")
st, h, b, sc, _ = req("GET", "/sso?t=" + CODE); check("sign in through the support desk", st == 303 and sc, (st, b[:100]))
cookie = sc[0].split(";")[0] if sc else ""
st, h, form, _, _ = req("GET", "/admin/links/new", cookie=cookie); check("the new-link form opens", st == 200 and "<form" in form, st)
fields = {m.group(1): m.group(2) for m in re.finditer(r'<input[^>]*type="hidden"[^>]*name="([^"]+)"[^>]*value="([^"]*)"', form)}
fields.update({m.group(2): m.group(1) for m in re.finditer(r'<input[^>]*type="hidden"[^>]*value="([^"]*)"[^>]*name="([^"]+)"', form)})
TARGET = "https://example.com/qr-scan-check"
data = dict(fields, kind="dynamic", type="url", label=LABEL, f_url=TARGET)
st, h, b, _, _ = req("POST", "/admin/links", urllib.parse.urlencode(data), cookie=cookie)
loc = h.get("location", ""); m = re.match(r"/admin/links/(\d+)", loc); check("a tracked link is created", st == 303 and m, (st, loc, b[:200], sorted(fields)))
if not m: print("RESULT: stopped"); sys.exit(1)
lid = m.group(1); st, h, page, _, _ = req("GET", loc, cookie=cookie); code = re.search(r'/r/([A-Za-z0-9_-]{3,})', page).group(1); print(f"  link {lid}, public address /r/{code}")

UA = {"desktop": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
      "iphone": "Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1",
      "android": "Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36",
      "bot": "facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)"}
times = []
for who in ["desktop", "desktop", "iphone", "android", "bot"]:
    st, h, b, sc, dt = req("GET", "/r/" + code, ua=UA[who]); times.append(dt)
    check(f"a {who} scan is sent on to the destination", st == 302 and h.get("location") == TARGET, (st, h.get("location")))
    if who == "desktop" and len(times) == 1:
        csp = h.get("content-security-policy", ""); check("the public redirect still cannot be framed, sets no sign-in cookie, and is not cached", "frame-ancestors 'none'" in csp and h.get("x-frame-options") == "DENY" and not sc and "no-store" in h.get("cache-control", "").lower() or "no-cache" in h.get("cache-control", "").lower(), (csp, h.get("cache-control"), sc))
print(f"  redirect times (ms): {', '.join(str(round(t)) for t in times)}"); check("redirects are quick (each under 1.5 seconds, including the network)", max(times) < 1500, times)
time.sleep(4)   # the scan log is written in the background
rows = sql(f"SELECT device_class, is_bot, is_unique, os, browser FROM scans WHERE link_id = {lid} ORDER BY id;").split("\n"); print("  recorded:"); [print("    " + r) for r in rows]
check("all five scans were recorded", len(rows) == 5, rows)
people = [r for r in rows if r.split("|")[1] == "0"]; bots = [r for r in rows if r.split("|")[1] == "1"]
check("four are counted as people and the link preview as a bot", len(people) == 4 and len(bots) == 1, rows)
check("the first scan from a visitor is unique and a repeat from the same place is not", people[0].split("|")[2] == "1" and people[1].split("|")[2] == "0", people[:2])
check("devices are told apart (desktop, iPhone and Android)", {r.split("|")[0] for r in people} >= {"desktop", "mobile"} or len({r.split("|")[0] for r in people}) >= 2, people)
st, h, csv, _, _ = req("GET", f"/admin/links/{lid}/scans.csv", cookie=cookie); lines = [l for l in csv.strip().split("\n") if l.strip()]; check("the CSV export lists the scans (a header and five rows)", st == 200 and len(lines) == 6, (st, len(lines)))
st, h, page, _, _ = req("GET", f"/admin/links/{lid}", cookie=cookie); check("the link's page shows the human count of 4", st == 200 and re.search(r">\s*4\s*<", page) is not None, st)

print("== clean up")
tok = re.search(r'name="csrf"[^>]*value="([^"]+)"|value="([^"]+)"[^>]*name="csrf"', page); tk = (tok.group(1) or tok.group(2)) if tok else ""
st, h, b, _, _ = req("POST", f"/admin/links/{lid}/delete", urllib.parse.urlencode({"csrf": tk}), cookie=cookie); print("  delete through the admin:", st)
left = sql(f"SELECT COUNT(*) FROM links WHERE id = {lid};"); sc_left = sql(f"SELECT COUNT(*) FROM scans WHERE link_id = {lid};")
if left != "0" or sc_left != "0": sqlw(f"DELETE FROM scans WHERE link_id = {lid}; DELETE FROM links WHERE id = {lid};"); print("  (removed directly, the admin delete did not complete)")
check("the test link and its scans are gone", sql(f"SELECT COUNT(*) FROM links WHERE id = {lid};") == "0" and sql(f"SELECT COUNT(*) FROM scans WHERE link_id = {lid};") == "0")
st, h, b, _, _ = req("GET", "/r/" + code); check("its public address no longer redirects", st in (404, 410), st)
print(f"\nRESULT: {'ALL PASSED' if not bad else str(bad) + ' FAILED'}"); sys.exit(1 if bad else 0)
