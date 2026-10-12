#!/usr/bin/env python3
"""Moves real, large data through file sharing over HTTP: a file of exactly 500 MB, one byte over, a customer's download of it, and a zip of it.
Needs: FS_FILES_DIR=<a disk with ~1.5 GB free> php tests/e2e/files_server.php running."""
import hashlib, http.cookiejar, io, json, os, sys, time, urllib.request, urllib.error, zipfile

B = "http://127.0.0.1:18504"; MAX_FILE = 500 * 1024 * 1024; PIECE = 2 * 1024 * 1024; fails = []; n = 0
def ok(what, cond, extra=""):
    global n; n += 1; print(("  ok: " if cond else "  FAIL: ") + what + ("" if cond else "  -> " + str(extra)[:300]))
    if not cond: fails.append(what)
jar = http.cookiejar.CookieJar(); op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
def call(url, body=None, headers=None, method=None, raw=False):
    h = dict(headers or {}); data = None
    if body is not None and not isinstance(body, (bytes, bytearray)): data = json.dumps(body).encode(); h["Content-Type"] = "application/json"
    elif body is not None: data = body
    req = urllib.request.Request(B + url, data=data, headers=h, method=method or ("POST" if data is not None else "GET"))
    try:
        r = op.open(req, timeout=300); return r if raw else (r.status, json.loads(r.read() or b"{}"))
    except urllib.error.HTTPError as e:
        return e if raw else (e.code, json.loads(e.read() or b"{}"))
code, me = call("/api/admin/login.php", {"username": "fs-admin", "password": "wr-pass-12345"}); csrf = me["csrf"]
post = lambda b: call("/api/admin/files.php", dict(b, csrf=csrf))

print("== the limit")
st, r = post({"action": "upload_begin", "parent": 0, "name": "too-big.bin", "size": MAX_FILE + 1})
ok("one byte over 500 MB is refused before any data is sent, with the limit in the message", st == 422 and "500 MB" in r.get("error", ""), (st, r))

print("== a file of exactly 500 MB, in 2 MB pieces")
block = os.urandom(PIECE); h = hashlib.sha256(); t0 = time.time()
st, r = post({"action": "upload_begin", "parent": 0, "name": "exactly-500MB.bin", "size": MAX_FILE}); up = r["upload"]
off = 0; idx = 0
while off < MAX_FILE:
    piece = idx.to_bytes(8, "big") + block[8:]; piece = piece[:min(PIECE, MAX_FILE - off)]
    code, j = call(f"/api/admin/files.php?action=upload_chunk&upload={up}&offset={off}", piece, {"X-CSRF-Token": csrf, "Content-Type": "application/octet-stream"})
    if code != 200: print("chunk failed", code, j); sys.exit(1)
    h.update(piece); off = j["received"]; idx += 1
st, fin = post({"action": "upload_finish", "upload": up}); node = fin["node"]; up_s = time.time() - t0
want = h.hexdigest()
ok(f"it uploaded ({idx} pieces, {up_s:.0f} s) and is exactly 524,288,000 bytes", st == 200 and node["size"] == MAX_FILE, (st, fin))
fsroot = json.load(open("/tmp/files_e2e.json"))["files"]; stored = None
for d, _, fs in os.walk(fsroot):
    for f in fs:
        p = os.path.join(d, f)
        if not os.path.relpath(d, fsroot).startswith("tmp") and os.path.getsize(p) == MAX_FILE: stored = p
ok("the stored bytes are identical to what was sent (checksum of the file on disk)", stored and hashlib.sha256(open(stored, "rb").read()).hexdigest() == want)

print("== a customer downloads it from a link")
st, sh = post({"action": "share_create", "kind": "link", "ids": [node["id"]], "title": "Big file"}); url = sh["share"]["url"].replace("https://blakegroup.uk", B)
t0 = time.time(); r = call(url.split(B)[1] + f"?d={node['id']}", raw=True); hh = hashlib.sha256(); got = 0
while True:
    b = r.read(1 << 20)
    if not b: break
    hh.update(b); got += len(b)
dl_s = time.time() - t0
ok(f"it streams back complete and identical ({got:,} bytes in {dl_s:.0f} s)", got == MAX_FILE and hh.hexdigest() == want, got)
ok("as an attachment with its length stated, so a browser can show progress", r.status == 200 and r.headers["Content-Length"] == str(MAX_FILE) and r.headers["Content-Disposition"].startswith("attachment") and r.headers["Content-Type"] == "application/octet-stream")

print("== download everything as one zip")
st, small = post({"action": "mkdir", "name": "notes"}); 
st, sh2 = post({"action": "share_create", "kind": "link", "ids": [node["id"]], "title": "Zip it"}); zurl = sh2["share"]["url"].replace("https://blakegroup.uk", B)
zpath = "/dev/shm/large_test.zip"; t0 = time.time(); r = call(zurl.split(B)[1] + "?z=all", raw=True)
with open(zpath, "wb") as out:
    while True:
        b = r.read(1 << 20)
        if not b: break
        out.write(b)
zs = time.time() - t0; z = zipfile.ZipFile(zpath)
ok(f"the zip is valid ({os.path.getsize(zpath):,} bytes, {zs:.0f} s) and holds the 500 MB file intact", z.testzip() is None and z.namelist() == ["exactly-500MB.bin"] and z.getinfo("exactly-500MB.bin").file_size == MAX_FILE and hashlib.sha256(z.read("exactly-500MB.bin")).hexdigest() == want)
os.remove(zpath)
print("== tidy up")
post({"action": "share_revoke", "id": sh["share"]["id"]}); post({"action": "share_revoke", "id": sh2["share"]["id"]})
st, d = post({"action": "delete", "ids": [node["id"]]}); ok("the big file is deleted and its space is given back", d.get("deleted") == 1 and not (stored and os.path.exists(stored)))
print(f"\n{n} checks, {len(fails)} failed"); sys.exit(1 if fails else 0)
