#!/usr/bin/env python3
"""The Newsroom's AI helper under the load a newspaper creates: a burst of tiny calls (how many are refused?), a real request to each picture model,
and a request shaped like the newspaper's story rewrite. Usage: newsroom_ai_check.py <pass>"""
import base64, json, sys, time, urllib.error, urllib.request
from concurrent.futures import ThreadPoolExecutor
BASE = "https://blakegroup.uk/api/newsroom.php"; PASS = sys.argv[1]
def call(action, data=None, q=""):
    req = urllib.request.Request(f"{BASE}?action={action}{q}", data=json.dumps(data).encode() if data is not None else None, headers={"X-News-Pass": PASS, "Content-Type": "application/json"})
    t0 = time.time()
    try:
        r = urllib.request.urlopen(req, timeout=120); b = r.read(); return r.status, json.loads(b or b"{}"), time.time() - t0
    except urllib.error.HTTPError as e:
        b = e.read(); 
        try: return e.code, json.loads(b), time.time() - t0
        except Exception: return e.code, {"raw": b[:200].decode("utf-8", "replace")}, time.time() - t0
tiny = {"kind": "text", "body": {"contents": [{"role": "user", "parts": [{"text": "Reply with one word: ok"}]}], "generationConfig": {"maxOutputTokens": 8}}}
from collections import Counter
print("== 90 tiny AI calls, three at a time, like the page (a newspaper makes dozens of calls in a minute)")
t0 = time.time(); res = list(ThreadPoolExecutor(3).map(lambda i: call("ai", tiny)[0], range(90))); dt = time.time() - t0; c = Counter(res); print(f"  {len(res)} calls in {dt:.0f} s: {dict(c)}")
print("  RESULT:", "OK, none refused" if set(c) == {200} else "SOME REFUSED: " + str({k: v for k, v in c.items() if k != 200}))
print("== the same, ten at a time (more than the server allows at once: it should ask the extra ones to wait, with 503, not fail)")
t0 = time.time(); res = list(ThreadPoolExecutor(10).map(lambda i: call("ai", tiny)[0], range(40))); c = Counter(res); print(f"  {len(res)} calls in {time.time()-t0:.0f} s: {dict(c)}")
print("  RESULT:", "no 429s (the per-minute limit is not reached)" if not c.get(429) else "429s seen: " + str(c.get(429)), "| busy answers (503) are the cap working:", c.get(503, 0))
time.sleep(62)
print("== a real request to each picture model")
for m in ["gemini-3.1-flash-image", "gemini-2.5-flash-image"]:
    st, j, dt = call("ai", {"kind": "image", "model": m, "body": {"contents": [{"role": "user", "parts": [{"text": "Realistic editorial photograph of a rooftop TV aerial against a grey UK sky. No text."}]}], "generationConfig": {"responseModalities": ["IMAGE"], "imageConfig": {"aspectRatio": "4:3"}}}})
    parts = (j.get("candidates") or [{}])[0].get("content", {}).get("parts", []) if isinstance(j, dict) else []
    img = next((p.get("inlineData") or p.get("inline_data") for p in parts if p.get("inlineData") or p.get("inline_data")), None)
    print(f"  {m}: HTTP {st} in {dt:.0f} s; " + (f"picture returned ({img.get('mimeType')}, {len(img.get('data',''))*3//4//1024} KB)" if img else "NO PICTURE: " + json.dumps(j)[:260]))
print("== a story rewrite shaped like the newspaper's")
src = "The new channel will move to a different multiplex from next Tuesday. Viewers on the Crystal Palace transmitter will need to retune their Freeview boxes. The change happens early in the morning."
st, j, dt = call("ai", {"kind": "text", "body": {"systemInstruction": {"parts": [{"text": 'You are a sub-editor. Rewrite the source as original newspaper copy. Use only facts in the source. Return JSON only: {"headline": "...", "caption": "...", "body": "paragraph one\\n\\nparagraph two"}'}]},
    "contents": [{"role": "user", "parts": [{"text": "Target length: about 40 words.\n\nSOURCE:\n" + src}]}], "generationConfig": {"temperature": 0.2, "responseMimeType": "application/json"}}})
txt = "".join(p.get("text", "") for p in (j.get("candidates") or [{}])[0].get("content", {}).get("parts", [])) if st == 200 else ""
try: out = json.loads(txt.replace("```json", "").replace("```", "").strip()); print(f"  HTTP {st} in {dt:.0f} s; parsed OK; keys {sorted(out)}; headline: {out.get('headline')!r}")
except Exception as e: print(f"  HTTP {st} in {dt:.0f} s; NOT valid JSON ({e}): {txt[:200]!r} {json.dumps(j)[:200] if st != 200 else ''}")
