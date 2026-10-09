#!/usr/bin/env python3
"""Builds public/docs/blake-support-desk-staff-guide.pdf from docs/staff-guide/guide.html (needs playwright)."""
import pathlib, sys
from playwright.sync_api import sync_playwright
here = pathlib.Path(__file__).resolve().parent; out = here.parents[1] / "public/docs/blake-support-desk-staff-guide.pdf"
with sync_playwright() as p:
    b = p.chromium.launch(); pg = b.new_page()
    pg.goto((here / "guide.html").as_uri()); pg.wait_for_load_state("networkidle")
    pg.pdf(path=str(out), format="A4", print_background=True, prefer_css_page_size=True, display_header_footer=False)
    b.close()
# a fingerprint of everything the PDF is built from, checked by tests/cases/staff_guide_test.php
import hashlib
h = hashlib.sha256()
for f in [here / "guide.html"] + sorted((here / "img").glob("*.png")):
    h.update(f.name.encode()); h.update(f.read_bytes())
(here / "guide.sha256").write_text(h.hexdigest() + "\n")
print("wrote", out, out.stat().st_size // 1024, "KB")
