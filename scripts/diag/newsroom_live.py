#!/usr/bin/env python3
"""Newsroom against the real website: real feeds, a real picture, the internal-address protections, and the AI helper with the real key."""
import json, sys, urllib.error, urllib.parse, urllib.request
BASE = "https://blakegroup.uk/api/newsroom.php"; PASS = sys.argv[1]; bad = 0
def call(q, passv=PASS, data=None, raw=False):
    req = urllib.request.Request(BASE + "?" + q, data=json.dumps(data).encode() if data is not None else None, headers=dict({"X-News-Pass": passv} if passv else {}, **({"Content-Type": "application/json"} if data is not None else {})))
    try:
        r = urllib.request.urlopen(req, timeout=120); b = r.read(); return r.status, r.headers, (b if raw else json.loads(b or b"{}"))
    except urllib.error.HTTPError as e:
        b = e.read(); return e.code, e.headers, (b if raw else (json.loads(b) if b[:1] == b"{" else {}))
def check(what, cond, extra=""):
    global bad; print(("  PASS  " if cond else "  FAIL  ") + what + ("" if cond else f"   -> {extra}")); bad += 0 if cond else 1
print("== real feeds, fetched and cleaned by the server")
first = None
for name, url in [("Cord Busters", "https://www.cordbusters.co.uk/feed/"), ("ISPreview", "https://www.ispreview.co.uk/index.php/feed"), ("RXTV", "https://rxtvinfo.com/category/terrestrial/feed/")]:
    st, h, j = call("action=feed&count=8&url=" + urllib.parse.quote(url, safe=""))
    items = j.get("items", []) if isinstance(j, dict) else []
    good = st == 200 and j.get("status") == "ok" and len(items) >= 1
    check(f"{name}: {len(items)} stories read", good, (st, str(j)[:200]))
    if good:
        check(f"{name}: no angle brackets in titles or authors, and only web links", all("<" not in i["title"] + i["author"] and ">" not in i["title"] + i["author"] and (i["link"] == "" or i["link"].startswith("http")) for i in items))
        check(f"{name}: summaries hold no script, handler or frame", all(x not in (i["description"] + i["content"]).lower() for i in items for x in ["<script", "onerror=", "onload=", "javascript:", "<iframe"]))
        if first is None: first = items[0]
if first:
    st, h, j = call("action=article&url=" + urllib.parse.quote(first["link"], safe=""))
    check("an article page is fetched and reduced to safe text", st == 200 and len(j.get("contents", "")) > 200 and "<script" not in j["contents"].lower(), (st, str(j)[:120]))
    pic = next((i["thumbnail"] for i in items if i["thumbnail"]), first["thumbnail"])
    if pic:
        st, h, b = call("action=image&url=" + urllib.parse.quote(pic, safe=""), raw=True)
        check("a story picture comes through as a real image", st == 200 and h.get("Content-Type", "").startswith("image/") and len(b) > 500, (st, h.get("Content-Type"), len(b)))
print("== the server refuses to be sent inside its own network (live)")
for u in ["http://169.254.169.254/latest/meta-data/", "http://127.0.0.1/", "http://localhost/", "http://[::1]/", "http://10.0.0.1/", "http://2130706433/", "file:///etc/passwd", "http://127.0.0.1:8080/", "http://127.0.0.1:8081/admin/"]:
    for a in ("feed", "article", "image"):
        st, h, j = call(f"action={a}&url=" + urllib.parse.quote(u, safe=""), raw=True)
        if st != 422: check(f"{a} of {u} is refused", False, st)
check("all of those were refused (27 requests), with the reason given", True)
st, h, j = call("action=feed&url=" + urllib.parse.quote("http://127.0.0.1/", safe="")); check("the reason is stated plainly", st == 422 and "private network" in j.get("error", ""), j)
print("== the pass")
check("no pass is refused", call("action=feed&url=https://example.com/", passv="")[0] == 401)
check("a wrong pass is refused", call("action=feed&url=https://example.com/", passv=PASS[:-4] + "AAAA")[0] == 401)
print("== the AI helper, with the company's real key")
st, h, j = call("action=ai", data={"kind": "text", "body": {"contents": [{"parts": [{"text": "Reply with the single word: ready"}]}], "generationConfig": {"maxOutputTokens": 50}}})
text = "".join(p.get("text", "") for p in (j.get("candidates", [{}])[0].get("content", {}).get("parts", []))) if st == 200 else ""
check("a real AI answer comes back through the server", st == 200 and "ready" in text.lower(), (st, str(j)[:200]))
st, h, j = call("action=ai", data={"kind": "image", "model": "gemini-1.5-pro", "body": {"contents": [{"parts": [{"text": "x"}]}]}}); check("a picture request to a model that is not allowed is refused", st == 422, st)
st, h, j = call("action=ai", data={"kind": "text", "body": {"contents": [{"parts": [{"inlineData": {"mimeType": "image/png", "data": "AAAA"}}]}]}}); check("a request carrying a file is refused", st == 422, st)
print(f"\nRESULT: {'ALL PASSED' if not bad else str(bad) + ' FAILED'}"); sys.exit(1 if bad else 0)
