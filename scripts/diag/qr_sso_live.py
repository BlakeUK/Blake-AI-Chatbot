#!/usr/bin/env python3
"""QR sign-in through the support desk against the real QR tool: one-time codes, the session cookie, the framing headers, roles, and refusals."""
import http.client, ssl, sys
CODE, CODE_RO = sys.argv[1], sys.argv[2]; bad = 0; HOST = "qr.blakegroup.uk"
def get(path, cookie=None):
    c = http.client.HTTPSConnection(HOST, timeout=30, context=ssl.create_default_context()); c.request("GET", path, headers={"Cookie": cookie} if cookie else {})
    r = c.getresponse(); body = r.read().decode("utf-8", "replace"); h = {k.lower(): v for k, v in r.getheaders()}; ck = [v for k, v in r.getheaders() if k.lower() == "set-cookie"]; c.close(); return r.status, h, body, ck
def check(what, cond, extra=""):
    global bad; print(("  PASS  " if cond else "  FAIL  ") + what + ("" if cond else f"   -> {extra}")); bad += 0 if cond else 1
print("== signing in with a one-time code")
st, h, body, ck = get("/sso?t=" + CODE)
check("a genuine code redirects to the admin pages", st == 303 and h.get("location") == "/admin/", (st, h.get("location"), body[:150]))
sess = next((c for c in ck if c.startswith("qr_session") or "session" in c.split("=")[0]), ck[0] if ck else "")
check("the session cookie works inside the program's window (SameSite=None, Secure, HttpOnly)", all(x in sess for x in ["SameSite=None", "Secure", "HttpOnly"]), ck)
cookie = sess.split(";")[0]
st, h, body, ck2 = get("/admin/", cookie)
check("the admin pages open, signed in as the support desk person, with no password asked for", st == 200 and "sso.diag-sso" in body, (st, body[:150]))
csp = h.get("content-security-policy", "")
check("the signed-in pages may be shown by the website and the program, and by nobody else", "frame-ancestors 'self' https://blakegroup.uk https://tauri.localhost http://tauri.localhost tauri://localhost" in csp and "x-frame-options" not in h, (csp, h.get("x-frame-options")))
st, h, body, _ = get("/healthz"); check("public pages still cannot be framed at all", "frame-ancestors 'none'" in h.get("content-security-policy", "") and h.get("x-frame-options") == "DENY", h)
print("== refusals")
st, h, body, ck = get("/sso?t=" + CODE); check("the same code cannot be used twice", st == 403 and not ck and "already used" in body, (st, body[:120]))
st, h, body, ck = get("/sso?t=" + CODE_RO); check("a read-only support desk role is refused and gets no session", st == 403 and not ck, (st, ck))
st, h, body, ck = get("/sso?t=" + "Z" * 43); check("an unknown code is refused", st == 403 and not ck, st)
for bad_code in ["", "short", "a b" + "a" * 30, "../" + "a" * 30]:
    st, h, body, ck = get("/sso?t=" + bad_code.replace(" ", "%20")); check(f"a malformed code {bad_code!r} is refused", st == 400 and not ck, st)
st, h, body, ck = get("/admin/"); check("with no session the admin pages send you to sign in", st == 303 and h.get("location") == "/admin/login", (st, h.get("location")))
print("== the support desk's side")
c = http.client.HTTPSConnection("blakegroup.uk", timeout=30); c.request("GET", "/api/qr_sso_verify.php"); r = c.getresponse(); r.read(); check("the verify address does not answer a plain GET", r.status == 405, r.status)
c = http.client.HTTPSConnection("blakegroup.uk", timeout=30); c.request("POST", "/api/qr_sso_verify.php", "t=" + CODE, {"Content-Type": "application/x-www-form-urlencoded"}); r = c.getresponse(); b = r.read(); check("a spent code is a plain 404 to anyone who asks", r.status == 404 and b"username" not in b, (r.status, b))
print(f"\nRESULT: {'ALL PASSED' if not bad else str(bad) + ' FAILED'}"); sys.exit(1 if bad else 0)
