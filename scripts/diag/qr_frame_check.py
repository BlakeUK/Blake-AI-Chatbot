#!/usr/bin/env python3
"""Can the QR tool be shown inside the program? Uses a real browser and the live QR tool. For every site address in the Caddyfile (where the admin
is served from) it builds the same chain the program uses: program (https://tauri.localhost) > admin page on that address > QR tool, and
requires the QR tool to show. A stranger's page, and the QR tool's public pages, must stay refused. Run from the repository root."""
import re, sys
from playwright.sync_api import sync_playwright
QR = "https://qr.blakegroup.uk"; PROGRAM = "https://tauri.localhost"; bad = 0
caddy = open("Caddyfile", encoding="utf-8").read(); hosts = []
for line in re.findall(r"^([a-z0-9][a-z0-9.-]*(?:, [a-z0-9.-]+)*) \{\s*$", caddy, re.M | re.I): hosts += [h.strip().lower() for h in line.split(",")]
hosts = sorted(set(h for h in hosts if not h.startswith("qr.")))
def shown(inner_path, middle):
    with sync_playwright() as p:
        b = p.chromium.launch(); ctx = b.new_context()
        ctx.route(PROGRAM + "/**", lambda r: r.fulfill(status=200, content_type="text/html", body=f'<iframe src="{middle}/embed" style="width:900px;height:600px"></iframe>'))
        ctx.route(middle + "/embed", lambda r: r.fulfill(status=200, content_type="text/html", body=f'<iframe src="{QR}{inner_path}" style="width:800px;height:500px"></iframe>'))
        pg = ctx.new_page(); pg.goto(PROGRAM + "/"); ok = False
        for _ in range(16):
            pg.wait_for_timeout(500)
            for f in pg.frames:
                if f.url.startswith(QR):
                    try: ok = ok or ("sign in" in f.inner_text("body").lower() or "ok" == f.inner_text("body").strip().lower())
                    except Exception: pass
            if ok: break
        b.close(); return ok
def check(what, got, want):
    global bad; good = got == want; print(("  PASS  " if good else "  FAIL  ") + what + ("" if good else f"   -> {'shown' if got else 'refused'}, expected {'shown' if want else 'refused'}")); bad += 0 if good else 1
print(f"== the QR tool inside the program, for each address the admin is served from: {hosts}")
assert len(hosts) >= 3, "the Caddyfile should list the site addresses"
for h in hosts: check(f"program > admin on {h} > QR tool is shown", shown("/admin/login", "https://" + h), True)
check("program > a stranger's page > QR tool stays refused", shown("/admin/login", "https://evil.example"), False)
check("the QR tool's public pages can never be framed, even by the admin", shown("/healthz", "https://" + hosts[0]), False)
print(f"\nRESULT: {'ALL PASSED' if not bad else str(bad) + ' FAILED'}"); sys.exit(1 if bad else 0)
