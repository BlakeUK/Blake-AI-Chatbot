package web

import (
	"archive/zip"
	"bytes"
	"context"
	"encoding/xml"
	"fmt"
	"image"
	"image/color"
	"image/png"
	"io"
	"mime/multipart"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"
	"testing"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/geo"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/qrtypes"
)

const (
	androidUA = "Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36"
	windowsUA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36"
)

// ---------- helpers ----------

func (h *harness) loginAs(user, pw string) *http.Client {
	h.t.Helper()
	c := h.client()
	_, page := h.do(c, "GET", "/admin/login", nil, nil)
	resp, body := h.do(c, "POST", "/admin/login", url.Values{"csrf": {h.csrfFrom(page)}, "username": {user}, "password": {pw}}, nil)
	if resp.StatusCode != http.StatusSeeOther {
		h.t.Fatalf("login %s: %d %.200s", user, resp.StatusCode, body)
	}
	return c
}

func (h *harness) postMultipart(c *http.Client, path string, fields url.Values, fileField string, file []byte) (*http.Response, string) {
	h.t.Helper()
	var buf bytes.Buffer
	mw := multipart.NewWriter(&buf)
	for k, vs := range fields {
		for _, v := range vs {
			mw.WriteField(k, v)
		}
	}
	if file != nil {
		fw, _ := mw.CreateFormFile(fileField, "upload.bin")
		fw.Write(file)
	}
	mw.Close()
	req, _ := http.NewRequest("POST", h.ts.URL+path, &buf)
	req.Header.Set("Content-Type", mw.FormDataContentType())
	resp, err := c.Do(req)
	if err != nil {
		h.t.Fatal(err)
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(resp.Body)
	return resp, string(b)
}

func (h *harness) create(c *http.Client, kind, typ string, extra url.Values) (int64, string) {
	h.t.Helper()
	v := url.Values{"kind": {kind}, "type": {typ}, "label": {"Test " + typ}, "window": {"7d"}, "expiry_mode": {"redirect_untracked"}, "qr_ecc": {"M"}, "csrf": {h.token(c)}}
	for k, vs := range extra {
		v[k] = vs
	}
	resp, body := h.do(c, "POST", "/admin/links", v, nil)
	if resp.StatusCode != http.StatusSeeOther {
		h.t.Fatalf("create %s/%s: %d\n%s", kind, typ, resp.StatusCode, firstErrors(body))
	}
	var id int64
	var code string
	h.db.QueryRow(`SELECT id, code FROM links ORDER BY id DESC LIMIT 1`).Scan(&id, &code)
	return id, code
}

func firstErrors(body string) string {
	var out []string
	for _, m := range []string{`class="error"`, `class="field-error"`} {
		for i, rest := 0, body; i < 3; i++ {
			k := strings.Index(rest, m)
			if k < 0 {
				break
			}
			end := strings.Index(rest[k:], "</")
			if end > 0 {
				out = append(out, rest[k:k+end])
			}
			rest = rest[k+len(m):]
		}
	}
	return strings.Join(out, "\n")
}

func (h *harness) scanHdr(code string, hdr map[string]string) *http.Response {
	c := &http.Client{CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	resp, _ := h.do(c, "GET", "/r/"+code, nil, hdr)
	return resp
}

func (h *harness) humans(id int64) int {
	all, _ := h.scanCount(id)
	return all
}

func pngBytes(w, hgt int, col color.RGBA) []byte {
	img := image.NewRGBA(image.Rect(0, 0, w, hgt))
	for y := 0; y < hgt; y++ {
		for x := 0; x < w; x++ {
			img.Set(x, y, col)
		}
	}
	var b bytes.Buffer
	png.Encode(&b, img)
	return b.Bytes()
}

func zbarFile(t *testing.T, name string, data []byte) string {
	t.Helper()
	p := filepath.Join(t.TempDir(), name)
	os.WriteFile(p, data, 0o600)
	out, _ := exec.Command("zbarimg", "--raw", "-q", "-Sdisable", "-Sqrcode.enable", p).Output()
	return strings.TrimSpace(string(out))
}

func haveZbar() bool { _, err := exec.LookPath("zbarimg"); return err == nil }

// ---------- type chooser and forms ----------

func TestChooserAndEveryTypeFormRenders(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	_, page := h.do(c, "GET", "/admin/links/new", nil, nil)
	for _, want := range []string{"Static or dynamic?", "Dynamic (tracked) codes", "Static codes", "Website link", "Wi-Fi network", "App stores", "Smart URL", "Contact card (vCard)", "Plain text"} {
		if !strings.Contains(page, want) {
			t.Errorf("chooser missing %q", want)
		}
	}
	// Wi-Fi is static only, app stores dynamic only: the chooser must not offer the impossible.
	for _, bad := range []string{"kind=dynamic&amp;type=wifi", "kind=dynamic&amp;type=text", "kind=static&amp;type=app_stores", "kind=static&amp;type=smart_url"} {
		if strings.Contains(page, bad) {
			t.Errorf("chooser offers %q", bad)
		}
	}
	// A combination that cannot exist falls back to the chooser rather than an error.
	_, page = h.do(c, "GET", "/admin/links/new?kind=dynamic&type=wifi", nil, nil)
	if !strings.Contains(page, "Static or dynamic?") {
		t.Error("dynamic wifi should show the chooser")
	}
	n := 0
	for _, spec := range qrtypes.All() {
		for _, kind := range []string{qrtypes.KindStatic, qrtypes.KindDynamic} {
			if !spec.Supports(kind) {
				continue
			}
			resp, body := h.do(c, "GET", fmt.Sprintf("/admin/links/new?kind=%s&type=%s", kind, spec.Type), nil, nil)
			if resp.StatusCode != 200 || !strings.Contains(body, spec.Label) {
				t.Errorf("%s/%s form: %d", kind, spec.Type, resp.StatusCode)
				continue
			}
			for _, f := range spec.Fields {
				if !strings.Contains(body, `name="f_`+f.Name+`"`) {
					t.Errorf("%s/%s form missing field %s", kind, spec.Type, f.Name)
				}
			}
			// tracking options only make sense for dynamic codes
			if hasTracking := strings.Contains(body, "Tracking window"); hasTracking != (kind == qrtypes.KindDynamic) {
				t.Errorf("%s/%s: tracking section present=%v", kind, spec.Type, hasTracking)
			}
			n++
		}
	}
	t.Logf("%d type/kind forms rendered", n)
}

// ---------- static codes ----------

func TestStaticCodeIsUntrackedAndImmutable(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	id, code := h.create(c, "static", "wifi", url.Values{"label": {"Guest Wi-Fi"}, "campaign": {"Reception"}, "f_ssid": {"Blake Guest"}, "f_password": {"correct;horse"}, "f_security": {"WPA"}})

	var kind, typ, content, dest string
	h.db.QueryRow(`SELECT kind, qr_type, content, destination_url FROM links WHERE id=?`, id).Scan(&kind, &typ, &content, &dest)
	if kind != "static" || typ != "wifi" || content != `WIFI:T:WPA;S:Blake Guest;P:correct\;horse;;` || dest != "" {
		t.Fatalf("stored: %s %s %q %q", kind, typ, content, dest)
	}

	// Not tracked: the tracking address does not exist for a static code.
	if r := h.scanHdr(code, map[string]string{"User-Agent": iphoneUA, "X-Forwarded-For": "203.0.113.5"}); r.StatusCode != http.StatusNotFound {
		t.Errorf("/r/%s for a static code: %d, want 404", code, r.StatusCode)
	}
	if n := h.humans(id); n != 0 {
		t.Errorf("a static code recorded %d scans", n)
	}

	_, list := h.do(c, "GET", "/admin/", nil, nil)
	if !strings.Contains(list, "static") || !strings.Contains(list, "untracked") || !strings.Contains(list, "Wi-Fi network") {
		t.Errorf("list does not show the static code clearly")
	}
	_, detail := h.do(c, "GET", fmt.Sprintf("/admin/links/%d", id), nil, nil)
	for _, want := range []string{"Static code", "not tracked", "cannot be changed", "Blake Guest", "qr.svg", "PNG 1024"} {
		if !strings.Contains(detail, want) {
			t.Errorf("static detail missing %q", want)
		}
	}
	for _, no := range []string{"Scans over time", "Tracking URL", "Individual scans"} {
		if strings.Contains(detail, no) {
			t.Errorf("static detail must not show %q", no)
		}
	}

	// The image encodes the content itself (a phone joins the Wi-Fi without ever touching this server).
	if haveZbar() {
		_, png512 := h.do(c, "GET", fmt.Sprintf("/admin/links/%d/qr.png?size=512", id), nil, nil)
		if got := zbarFile(t, "s.png", []byte(png512)); got != content {
			t.Errorf("static QR decodes to %q, want the Wi-Fi payload", got)
		}
	}

	// Editing may rename and restyle, but never change what is printed.
	tok := h.token(c)
	resp, _ := h.do(c, "POST", fmt.Sprintf("/admin/links/%d", id), url.Values{"csrf": {tok}, "label": {"Renamed"}, "campaign": {"Lobby"}, "qr_ecc": {"H"},
		"f_ssid": {"EVIL"}, "f_password": {"hacked"}, "fg": {"#003366"}, "pattern": {"rounded"}}, nil)
	if resp.StatusCode != http.StatusSeeOther {
		t.Fatalf("edit static: %d", resp.StatusCode)
	}
	var label, camp, ecc, design, content2 string
	h.db.QueryRow(`SELECT label, campaign, qr_ecc, design, content FROM links WHERE id=?`, id).Scan(&label, &camp, &ecc, &design, &content2)
	if label != "Renamed" || camp != "Lobby" || ecc != "H" || !strings.Contains(design, "#003366") {
		t.Errorf("editable parts not saved: %s %s %s %s", label, camp, ecc, design)
	}
	if content2 != content {
		t.Errorf("static content changed to %q", content2)
	}
	_, edit := h.do(c, "GET", fmt.Sprintf("/admin/links/%d/edit", id), nil, nil)
	if !strings.Contains(edit, "cannot be changed") || strings.Contains(edit, `name="f_ssid"`) || strings.Contains(edit, "Tracking window") {
		t.Error("static edit form should show the fixed content, not editable fields or tracking options")
	}
}

func TestStaticAndDynamicOfTheSameLinkDiffer(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	_, sc := h.create(c, "static", "url", url.Values{"f_url": {"https://www.blake-uk.com/category/aerials.html"}})
	did, dc := h.create(c, "dynamic", "url", url.Values{"f_url": {"https://www.blake-uk.com/category/aerials.html"}})
	var sid int64
	h.db.QueryRow(`SELECT id FROM links WHERE code=?`, sc).Scan(&sid)
	if !haveZbar() {
		t.Skip("zbarimg not installed")
	}
	_, sp := h.do(c, "GET", fmt.Sprintf("/admin/links/%d/qr.png?size=512", sid), nil, nil)
	_, dp := h.do(c, "GET", fmt.Sprintf("/admin/links/%d/qr.png?size=512", did), nil, nil)
	if got := zbarFile(t, "a.png", []byte(sp)); got != "https://www.blake-uk.com/category/aerials.html" {
		t.Errorf("static url code holds %q, want the destination itself", got)
	}
	if got := zbarFile(t, "b.png", []byte(dp)); got != "http://qr.test/r/"+dc {
		t.Errorf("dynamic code holds %q, want the tracking address", got)
	}
}

// ---------- dynamic documents and smart routing ----------

func TestDynamicVCardAndEventAreDelivered(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	id, code := h.create(c, "dynamic", "vcard", url.Values{"f_first": {"Ann"}, "f_last": {"Smith"}, "f_org": {"Blake UK"}, "f_phone": {"+441142235000"}, "f_email": {"ann@blake-uk.com"}})
	r := h.scanHdr(code, map[string]string{"User-Agent": iphoneUA, "X-Forwarded-For": "203.0.113.20"})
	if r.StatusCode != 200 || !strings.HasPrefix(r.Header.Get("Content-Type"), "text/vcard") || !strings.Contains(r.Header.Get("Content-Disposition"), "contact.vcf") {
		t.Fatalf("vcard response: %d %s %s", r.StatusCode, r.Header.Get("Content-Type"), r.Header.Get("Content-Disposition"))
	}
	cl := &http.Client{CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	_, body := h.do(cl, "GET", "/r/"+code, nil, map[string]string{"User-Agent": androidUA, "X-Forwarded-For": "203.0.113.21"})
	for _, want := range []string{"BEGIN:VCARD", "FN:Ann Smith", "ORG:Blake UK", "TEL;TYPE=WORK,VOICE:+441142235000", "END:VCARD"} {
		if !strings.Contains(body, want) {
			t.Errorf("vcard body missing %q", want)
		}
	}
	h.do(cl, "HEAD", "/r/"+code, nil, map[string]string{"User-Agent": iphoneUA, "X-Forwarded-For": "203.0.113.22"})
	if n := h.humans(id); n != 2 {
		t.Errorf("vCard deliveries counted = %d, want 2 (HEAD must not count)", n)
	}

	_, ecode := h.create(c, "dynamic", "event", url.Values{"f_title": {"Open day"}, "f_start": {"2026-12-01T10:00"}, "f_end": {"2026-12-01T16:00"}, "f_place": {"Sheffield"}})
	r = h.scanHdr(ecode, map[string]string{"User-Agent": iphoneUA, "X-Forwarded-For": "203.0.113.23"})
	if !strings.HasPrefix(r.Header.Get("Content-Type"), "text/calendar") || !strings.Contains(r.Header.Get("Content-Disposition"), "event.ics") {
		t.Errorf("event response: %s %s", r.Header.Get("Content-Type"), r.Header.Get("Content-Disposition"))
	}
}

func TestAppStoresAndSmartRouting(t *testing.T) {
	h := newHarness(t)
	h.withGeo()
	h.srv.geo = geo.Static(map[string]geo.Location{
		"81.2.69.142":  {CountryISO: "GB", Country: "United Kingdom", City: "London"},
		"203.0.113.50": {CountryISO: "FR", Country: "France", City: "Paris"},
	})
	c := h.adminClient()

	id, code := h.create(c, "dynamic", "app_stores", url.Values{"f_ios_url": {"https://apps.apple.com/app/id1"}, "f_android_url": {"https://play.google.com/store/apps/details?id=x"}, "f_url": {"https://www.example.com/app"}})
	for ua, want := range map[string]string{iphoneUA: "https://apps.apple.com/app/id1", androidUA: "https://play.google.com/store/apps/details?id=x", windowsUA: "https://www.example.com/app"} {
		if got := h.scanHdr(code, map[string]string{"User-Agent": ua, "X-Forwarded-For": "81.2.69.142"}).Header.Get("Location"); got != want {
			t.Errorf("UA %.30s -> %s, want %s", ua, got, want)
		}
	}
	h.scanCount(id)
	var dests []string
	rows, _ := h.db.Query(`SELECT destination_url FROM scans WHERE link_id=? ORDER BY id`, id)
	for rows.Next() {
		var d string
		rows.Scan(&d)
		dests = append(dests, d)
	}
	rows.Close()
	sort.Strings(dests) // the three scans were sent in random order (map iteration), so compare as a set
	wantDests := []string{"https://apps.apple.com/app/id1", "https://play.google.com/store/apps/details?id=x", "https://www.example.com/app"}
	if len(dests) != 3 || dests[0] != wantDests[0] || dests[1] != wantDests[1] || dests[2] != wantDests[2] {
		t.Errorf("each scan must record where THAT visitor was sent: %v", dests)
	}

	// Smart URL: country, language, os and device rules, first match wins.
	_, scode := h.create(c, "dynamic", "smart_url", url.Values{
		"f_url":      {"https://www.blake-uk.com/"},
		"f_r1_match": {"country"}, "f_r1_value": {"fr"}, "f_r1_url": {"https://www.blake-uk.com/fr"},
		"f_r2_match": {"language"}, "f_r2_value": {"cy"}, "f_r2_url": {"https://www.blake-uk.com/cy"},
		"f_r3_match": {"os"}, "f_r3_value": {"android"}, "f_r3_url": {"https://m.blake-uk.com/android"},
		"f_r4_match": {"device"}, "f_r4_value": {"desktop"}, "f_r4_url": {"https://www.blake-uk.com/desktop"},
	})
	loc := func(ip, ua, lang string) string {
		return h.scanHdr(scode, map[string]string{"User-Agent": ua, "X-Forwarded-For": ip, "Accept-Language": lang}).Header.Get("Location")
	}
	cases := []struct{ name, got, want string }{
		{"country match", loc("203.0.113.50", iphoneUA, "en-GB"), "https://www.blake-uk.com/fr"},
		{"country beats later os rule (first match wins)", loc("203.0.113.50", androidUA, "en-GB"), "https://www.blake-uk.com/fr"},
		{"language prefix cy matches cy-GB", loc("81.2.69.142", iphoneUA, "cy-GB,cy;q=0.9"), "https://www.blake-uk.com/cy"},
		{"os rule", loc("81.2.69.142", androidUA, "en-GB"), "https://m.blake-uk.com/android"},
		{"device rule", loc("81.2.69.142", windowsUA, "en-GB"), "https://www.blake-uk.com/desktop"},
		{"nothing matches: default", loc("81.2.69.142", iphoneUA, "en-GB"), "https://www.blake-uk.com/"},
		{"unknown country and no language: default", loc("198.51.100.77", iphoneUA, ""), "https://www.blake-uk.com/"},
	}
	for _, c := range cases {
		if c.got != c.want {
			t.Errorf("%s: sent to %q, want %q", c.name, c.got, c.want)
		}
	}
	_, detail := h.do(c, "GET", "/admin/links/2", nil, nil)
	if !strings.Contains(detail, "Smart routing") {
		t.Error("detail page should list the rules")
	}
}

// ---------- scan limit ----------

func TestScanLimitEndsTheCode(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	id, code := h.create(c, "dynamic", "url", url.Values{"f_url": {"https://www.blake-uk.com/offer"}, "max_scans": {"2"}, "expiry_mode": {"show_expired_page"}})
	hit := func(ip, ua string) int {
		r := h.scanHdr(code, map[string]string{"User-Agent": ua, "X-Forwarded-For": ip})
		h.scanCount(id) // let the writer commit so the counter is current
		return r.StatusCode
	}
	if hit("203.0.113.1", "WhatsApp/2.23 A") != 302 || hit("203.0.113.2", "Slackbot-LinkExpanding 1.0") != 302 || hit("203.0.113.3", "facebookexternalhit/1.1") != 302 {
		t.Fatal("bots must never use up the limit")
	}
	if hit("203.0.113.4", iphoneUA) != 302 || hit("203.0.113.5", androidUA) != 302 {
		t.Fatal("the first two people must get through")
	}
	if got := hit("203.0.113.6", windowsUA); got != http.StatusGone {
		t.Errorf("third person: %d, want 410 (limit of 2 reached)", got)
	}
	if n := h.humans(id); n != 2 {
		t.Errorf("counted %d, want 2", n)
	}
	_, detail := h.do(c, "GET", fmt.Sprintf("/admin/links/%d", id), nil, nil)
	if !strings.Contains(detail, "2 of 2 used") {
		t.Error("detail should show the limit used")
	}
	// raising the limit (an edit) brings it back to life
	tok := h.token(c)
	h.do(c, "POST", fmt.Sprintf("/admin/links/%d", id), url.Values{"csrf": {tok}, "label": {"x"}, "f_url": {"https://www.blake-uk.com/offer"}, "window": {"7d"}, "expiry_mode": {"show_expired_page"}, "qr_ecc": {"M"}, "max_scans": {"10"}}, nil)
	if got := hit("203.0.113.7", windowsUA); got != 302 {
		t.Errorf("after raising the limit: %d", got)
	}
}

// ---------- password protection ----------

func TestPostCannotInflateScansOnAnUnprotectedCode(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	id, code := h.create(c, "dynamic", "url", url.Values{"f_url": {"https://www.blake-uk.com/"}})
	for i := 0; i < 5; i++ {
		r, _ := h.do(h.client(), "POST", "/r/"+code, url.Values{"x": {"y"}}, map[string]string{"User-Agent": iphoneUA, "X-Forwarded-For": fmt.Sprintf("203.0.113.%d", 100+i)})
		if r.StatusCode != http.StatusMethodNotAllowed {
			t.Errorf("POST to an unprotected code: %d, want 405", r.StatusCode)
		}
	}
	if n := h.humans(id); n != 0 {
		t.Errorf("POSTs were counted as %d scans", n)
	}
}

func TestPasswordProtectedCode(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	id, code := h.create(c, "dynamic", "url", url.Values{"f_url": {"https://www.blake-uk.com/trade"}, "link_password": {"letmein-trade"}})
	var hash string
	h.db.QueryRow(`SELECT password_hash FROM links WHERE id=?`, id).Scan(&hash)
	if strings.Contains(hash, "letmein") || !strings.HasPrefix(hash, "$2") {
		t.Fatalf("link password must be stored as a bcrypt hash, got %q", hash)
	}

	visitor := h.client()
	hdr := map[string]string{"User-Agent": iphoneUA, "X-Forwarded-For": "203.0.113.30"}
	resp, page := h.do(visitor, "GET", "/r/"+code, nil, hdr)
	if resp.StatusCode != 200 || !strings.Contains(page, "This code is protected") || resp.Header.Get("Location") != "" {
		t.Fatalf("protected code must show a password page, not redirect: %d", resp.StatusCode)
	}
	if strings.Contains(page, "blake-uk.com/trade") {
		t.Error("the destination leaked on the password page")
	}
	if n := h.humans(id); n != 0 {
		t.Errorf("a visit that never entered the password was counted (%d)", n)
	}
	tok := h.csrfFrom(page)

	// forged / missing CSRF
	if r, _ := h.do(h.client(), "POST", "/r/"+code, url.Values{"password": {"letmein-trade"}}, hdr); r.StatusCode != http.StatusForbidden {
		t.Errorf("POST with no cookie/token: %d", r.StatusCode)
	}
	if r, _ := h.do(visitor, "POST", "/r/"+code, url.Values{"csrf": {"forged"}, "password": {"letmein-trade"}}, hdr); r.StatusCode != http.StatusForbidden {
		t.Errorf("POST with forged token: %d", r.StatusCode)
	}
	// wrong password
	resp, page = h.do(visitor, "POST", "/r/"+code, url.Values{"csrf": {tok}, "password": {"nope"}}, hdr)
	if resp.StatusCode != http.StatusUnauthorized || !strings.Contains(page, "not right") || resp.Header.Get("Location") != "" {
		t.Errorf("wrong password: %d", resp.StatusCode)
	}
	if n := h.humans(id); n != 0 {
		t.Errorf("a wrong password was counted as a scan (%d)", n)
	}
	tok = h.csrfFrom(page)
	// right password: sent on, and now counted
	resp, _ = h.do(visitor, "POST", "/r/"+code, url.Values{"csrf": {tok}, "password": {"letmein-trade"}}, hdr)
	if resp.StatusCode != http.StatusSeeOther || resp.Header.Get("Location") != "https://www.blake-uk.com/trade" {
		t.Fatalf("right password: %d -> %s", resp.StatusCode, resp.Header.Get("Location"))
	}
	if n := h.humans(id); n != 1 {
		t.Errorf("unlocked scan counted %d, want 1", n)
	}

	// brute force: the wrong-password throttle kicks in from one visitor
	atk := h.client()
	atkHdr := map[string]string{"User-Agent": iphoneUA, "X-Forwarded-For": "203.0.113.99"}
	_, p := h.do(atk, "GET", "/r/"+code, nil, atkHdr)
	t2 := h.csrfFrom(p)
	var last int
	for i := 0; i < 10; i++ {
		r, page := h.do(atk, "POST", "/r/"+code, url.Values{"csrf": {t2}, "password": {fmt.Sprint("guess", i)}}, atkHdr)
		last = r.StatusCode
		if r.StatusCode == http.StatusUnauthorized {
			t2 = h.csrfFrom(page) // the token is renewed after every wrong answer
		}
	}
	if last != http.StatusTooManyRequests {
		t.Errorf("after 10 wrong guesses the status is %d, want 429", last)
	}
	// another visitor is not affected
	if r, _ := h.do(h.client(), "GET", "/r/"+code, nil, map[string]string{"User-Agent": iphoneUA, "X-Forwarded-For": "203.0.113.31"}); r.StatusCode != 200 {
		t.Errorf("other visitor: %d", r.StatusCode)
	}
	// HEAD does not reveal or count anything
	h.do(visitor, "HEAD", "/r/"+code, nil, hdr)

	// editing: leaving the field empty keeps the password; "remove" clears it
	t3 := h.token(c)
	edit := func(extra url.Values) {
		v := url.Values{"csrf": {t3}, "label": {"x"}, "f_url": {"https://www.blake-uk.com/trade"}, "window": {"7d"}, "expiry_mode": {"redirect_untracked"}, "qr_ecc": {"M"}}
		for k, vs := range extra {
			v[k] = vs
		}
		h.do(c, "POST", fmt.Sprintf("/admin/links/%d", id), v, nil)
	}
	edit(nil)
	if r, _ := h.do(h.client(), "GET", "/r/"+code, nil, map[string]string{"User-Agent": iphoneUA, "X-Forwarded-For": "203.0.113.32"}); r.StatusCode != 200 {
		t.Error("editing without touching the password must keep it")
	}
	edit(url.Values{"clear_password": {"on"}})
	if r := h.scanHdr(code, map[string]string{"User-Agent": iphoneUA, "X-Forwarded-For": "203.0.113.33"}); r.StatusCode != 302 {
		t.Errorf("after removing the password: %d", r.StatusCode)
	}
}

// ---------- design, logo, preview ----------

func TestDesignLogoAndPreview(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	logo := pngBytes(300, 300, color.RGBA{200, 30, 30, 255})

	fields := url.Values{"kind": {"dynamic"}, "type": {"url"}, "label": {"Branded"}, "f_url": {"https://www.blake-uk.com/manual"}, "window": {"30d"}, "expiry_mode": {"redirect_untracked"},
		"qr_ecc": {"M"}, "fg": {"#0b2a6f"}, "bg": {"#ffffff"}, "pattern": {"dots"}, "eye": {"circle"}, "eye_same": {"on"}, "frame": {"box"}, "cta": {"SCAN FOR MANUAL"},
		"template_name": {"Blake blue"}, "csrf": {h.token(c)}}
	resp, body := h.postMultipart(c, "/admin/links", fields, "logo", logo)
	if resp.StatusCode != http.StatusSeeOther {
		t.Fatalf("create with logo: %d\n%s", resp.StatusCode, firstErrors(body))
	}
	var id int64
	var design string
	var hasLogo int
	h.db.QueryRow(`SELECT id, design, logo IS NOT NULL FROM links`).Scan(&id, &design, &hasLogo)
	if hasLogo != 1 || !strings.Contains(design, `"pattern":"dots"`) || !strings.Contains(design, "SCAN FOR MANUAL") || !strings.Contains(design, "#0b2a6f") {
		t.Errorf("design/logo not stored: logo=%d %s", hasLogo, design)
	}

	// downloads carry the design: framed PNG is taller than wide, SVG has the logo and the text
	_, pngBody := h.do(c, "GET", fmt.Sprintf("/admin/links/%d/qr.png?size=512&download=1", id), nil, nil)
	img, err := png.Decode(strings.NewReader(pngBody))
	if err != nil || img.Bounds().Dx() != 512 || img.Bounds().Dy() <= 512 {
		t.Errorf("framed PNG: %v %v", err, img.Bounds())
	}
	_, svg := h.do(c, "GET", fmt.Sprintf("/admin/links/%d/qr.svg", id), nil, nil)
	if !strings.Contains(svg, "<image") || !strings.Contains(svg, "SCAN FOR MANUAL") || !strings.Contains(svg, "<circle") {
		t.Error("SVG is missing the logo, the call to action or the circle styling")
	}
	if err := xml.NewDecoder(strings.NewReader(svg)).Decode(new(struct{ XMLName xml.Name })); err != nil && !strings.Contains(err.Error(), "EOF") {
		t.Errorf("SVG not well-formed: %v", err)
	}
	// and it still scans, logo and all, to the tracking address
	if haveZbar() {
		var code string
		h.db.QueryRow(`SELECT code FROM links WHERE id=?`, id).Scan(&code)
		if got := zbarFile(t, "d.png", []byte(pngBody)); got != "http://qr.test/r/"+code {
			t.Errorf("styled code with logo decodes to %q", got)
		}
	}
	// ECC was raised automatically because of the logo (M requested)
	if strings.Contains(svg, "<svg") && h.srv == nil {
		t.Skip()
	}

	// the design was saved as a reusable template, including its logo
	tpls, _ := h.srv.links.Templates(nil2())
	if len(tpls) != 1 || tpls[0].Name != "Blake blue" || !tpls[0].HasLogo {
		t.Errorf("template not saved: %+v", tpls)
	}
	_, form := h.do(c, "GET", fmt.Sprintf("/admin/links/new?kind=dynamic&type=url&template=%d", tpls[0].ID), nil, nil)
	if !strings.Contains(form, `value="#0b2a6f"`) || !strings.Contains(form, "SCAN FOR MANUAL") {
		t.Error("applying a template should prefill the design")
	}
	// a code created from the template (no upload) inherits its logo
	f2 := url.Values{"kind": {"dynamic"}, "type": {"url"}, "label": {"From template"}, "f_url": {"https://www.blake-uk.com/x"}, "window": {"7d"}, "expiry_mode": {"redirect_untracked"}, "qr_ecc": {"M"},
		"fg": {"#0b2a6f"}, "pattern": {"dots"}, "eye": {"circle"}, "eye_same": {"on"}, "frame": {"box"}, "cta": {"SCAN FOR MANUAL"}, "template_id": {fmt.Sprint(tpls[0].ID)}, "csrf": {h.token(c)}}
	if resp, _ := h.do(c, "POST", "/admin/links", f2, nil); resp.StatusCode != http.StatusSeeOther {
		t.Fatalf("create from template: %d", resp.StatusCode)
	}
	var n int
	h.db.QueryRow(`SELECT COUNT(*) FROM links WHERE label='From template' AND logo IS NOT NULL`).Scan(&n)
	if n != 1 {
		t.Error("template logo not applied to the new code")
	}

	// bad designs and bad logos are refused with a reason
	bad := map[string]func(url.Values){
		"low contrast":  func(v url.Values) { v.Set("fg", "#ffeb3b") },
		"inverted":      func(v url.Values) { v.Set("fg", "#ffffff"); v.Set("bg", "#000000") },
		"unknown dots":  func(v url.Values) { v.Set("pattern", "stars") },
		"cta too long":  func(v url.Values) { v.Set("cta", strings.Repeat("x", 30)) },
		"colour attack": func(v url.Values) { v.Set("fg", `#000" onload="x`) },
	}
	for name, mut := range bad {
		v := url.Values{"kind": {"dynamic"}, "type": {"url"}, "label": {"x"}, "f_url": {"https://www.blake-uk.com/"}, "window": {"7d"}, "expiry_mode": {"redirect_untracked"}, "qr_ecc": {"M"}, "frame": {"box"}, "csrf": {h.token(c)}}
		mut(v)
		resp, body := h.do(c, "POST", "/admin/links", v, nil)
		if resp.StatusCode != http.StatusUnprocessableEntity || !strings.Contains(body, "field-error") {
			t.Errorf("%s: %d", name, resp.StatusCode)
		}
	}
	for name, data := range map[string][]byte{"text file": []byte("hello"), "html": []byte("<script>alert(1)</script>"), "svg": []byte(`<svg xmlns="http://www.w3.org/2000/svg"/>`)} {
		v := url.Values{"kind": {"dynamic"}, "type": {"url"}, "label": {"x"}, "f_url": {"https://www.blake-uk.com/"}, "window": {"7d"}, "expiry_mode": {"redirect_untracked"}, "qr_ecc": {"M"}, "csrf": {h.token(c)}}
		resp, body := h.postMultipart(c, "/admin/links", v, "logo", data)
		if resp.StatusCode != http.StatusUnprocessableEntity || !strings.Contains(body, "PNG, JPEG or GIF") {
			t.Errorf("logo %s: %d", name, resp.StatusCode)
		}
	}
	// removing the logo on edit
	v := url.Values{"csrf": {h.token(c)}, "label": {"Branded"}, "f_url": {"https://www.blake-uk.com/manual"}, "window": {"30d"}, "expiry_mode": {"redirect_untracked"}, "qr_ecc": {"M"}, "remove_logo": {"on"}, "frame": {"box"}, "pattern": {"dots"}, "eye": {"circle"}, "fg": {"#0b2a6f"}}
	h.do(c, "POST", fmt.Sprintf("/admin/links/%d", id), v, nil)
	h.db.QueryRow(`SELECT logo IS NOT NULL FROM links WHERE id=?`, id).Scan(&hasLogo)
	if hasLogo != 0 {
		t.Error("remove_logo did not remove it")
	}

	// the live preview endpoint
	resp, body = h.do(c, "GET", "/admin/preview.svg?fg=%230b2a6f&pattern=dots&eye=circle&frame=box&cta=HELLO&qr_ecc=M", nil, nil)
	if resp.StatusCode != 200 || resp.Header.Get("Content-Type") != "image/svg+xml" || !strings.Contains(body, "HELLO") {
		t.Errorf("preview: %d %s", resp.StatusCode, resp.Header.Get("Content-Type"))
	}
	resp, body = h.do(c, "GET", "/admin/preview.svg?fg=%23ffeb3b", nil, nil)
	if resp.StatusCode != http.StatusBadRequest || !strings.Contains(body, "too close to the background") {
		t.Errorf("preview must explain a design that would not scan: %d %q", resp.StatusCode, body)
	}
	if resp, _ := h.do(h.client(), "GET", "/admin/preview.svg", nil, nil); resp.StatusCode != http.StatusSeeOther {
		t.Errorf("preview needs sign-in: %d", resp.StatusCode)
	}
}

// ---------- bulk ----------

func TestBulkCreate(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	csv := "label,url,campaign\nAerial A1 box,https://www.blake-uk.com/aerials/a1.html,Product box\nAerial A2 box,https://www.blake-uk.com/aerials/a2.html,\n=cmd|' /C calc'!A0,https://www.blake-uk.com/x,Leaflet\n"
	post := func(csv string, extra url.Values) (*http.Response, string) {
		v := url.Values{"csrf": {h.token(c)}, "campaign": {"Default camp"}, "window": {"30d"}, "format": {"svg"}, "qr_ecc": {"M"}}
		for k, vs := range extra {
			v[k] = vs
		}
		return h.postMultipart(c, "/admin/bulk", v, "csv", []byte(csv))
	}
	resp, body := post(csv, nil)
	if resp.StatusCode != 200 || resp.Header.Get("Content-Type") != "application/zip" {
		t.Fatalf("bulk: %d %s\n%.300s", resp.StatusCode, resp.Header.Get("Content-Type"), firstErrors(body))
	}
	zr, err := zip.NewReader(strings.NewReader(body), int64(len(body)))
	if err != nil {
		t.Fatal(err)
	}
	files := map[string]string{}
	for _, f := range zr.File {
		rc, _ := f.Open()
		b, _ := io.ReadAll(rc)
		rc.Close()
		files[f.Name] = string(b)
	}
	if len(files) != 4 || files["codes.csv"] == "" { // 3 svgs + the listing
		t.Fatalf("zip contents: %v", keys(files))
	}
	for name, b := range files {
		if strings.HasSuffix(name, ".svg") {
			if err := xml.NewDecoder(strings.NewReader(b)).Decode(new(struct{ XMLName xml.Name })); err != nil && !strings.Contains(err.Error(), "EOF") {
				t.Errorf("%s not well-formed: %v", name, err)
			}
		}
	}
	listing := files["codes.csv"]
	for _, want := range []string{"label,campaign,destination,short_url,code,file", "Aerial A1 box,Product box,https://www.blake-uk.com/aerials/a1.html,http://qr.test/r/", "Aerial A2 box,Default camp,", "'=cmd"} {
		if !strings.Contains(listing, want) {
			t.Errorf("codes.csv missing %q:\n%s", want, listing)
		}
	}
	var n int
	h.db.QueryRow(`SELECT COUNT(*) FROM links WHERE kind='dynamic'`).Scan(&n)
	if n != 3 {
		t.Errorf("%d links created, want 3", n)
	}
	if haveZbar() {
		var code string
		h.db.QueryRow(`SELECT code FROM links ORDER BY id LIMIT 1`).Scan(&code)
		svg, _ := files["qr-"+code+".svg"], 0
		p := filepath.Join(t.TempDir(), "b.svg")
		os.WriteFile(p, []byte(svg), 0o600)
		if _, err := exec.LookPath("rsvg-convert"); err == nil {
			out := filepath.Join(t.TempDir(), "b.png")
			exec.Command("rsvg-convert", "-w", "600", "-o", out, p).Run()
			b, _ := os.ReadFile(out)
			if got := zbarFile(t, "b.png", b); got != "http://qr.test/r/"+code {
				t.Errorf("bulk SVG decodes to %q", got)
			}
		}
	}

	// all-or-nothing: one bad row stops everything
	before := n
	resp, body = post("label,url\nGood,https://www.blake-uk.com/ok\nBad,javascript:alert(1)\n", nil)
	if resp.StatusCode != http.StatusUnprocessableEntity || !strings.Contains(body, "Row 3") || !strings.Contains(body, "Nothing was created") {
		t.Errorf("bad row: %d", resp.StatusCode)
	}
	h.db.QueryRow(`SELECT COUNT(*) FROM links`).Scan(&n)
	if n != before {
		t.Errorf("a failed bulk run created %d links", n-before)
	}
	for name, bad := range map[string]string{"no url column": "label,site\nA,https://x.example\n", "header only": "label,url\n", "empty": "", "not csv": "\x00\x01\x02"} {
		if resp, _ := post(bad, nil); resp.StatusCode != http.StatusUnprocessableEntity {
			t.Errorf("%s: %d", name, resp.StatusCode)
		}
	}
	// size limits per format
	var big strings.Builder
	big.WriteString("label,url\n")
	for i := 0; i < 260; i++ {
		fmt.Fprintf(&big, "Row %d,https://www.blake-uk.com/p/%d\n", i, i)
	}
	if resp, body := post(big.String(), url.Values{"format": {"png"}}); resp.StatusCode != http.StatusUnprocessableEntity || !strings.Contains(body, "limit is 250") {
		t.Errorf("260 rows as PNG: %d", resp.StatusCode)
	}
	if resp, _ := post(big.String(), nil); resp.StatusCode != 200 {
		t.Errorf("260 rows as SVG should be fine: %d", resp.StatusCode)
	}
	// unauthenticated access is refused
	if r, _ := h.do(h.client(), "GET", "/admin/bulk", nil, nil); r.StatusCode != http.StatusSeeOther {
		t.Errorf("bulk page without sign-in: %d", r.StatusCode)
	}
}

func keys(m map[string]string) []string {
	var k []string
	for n := range m {
		k = append(k, n)
	}
	return k
}

// ---------- users ----------

func TestUserManagementOverHTTP(t *testing.T) {
	h := newHarness(t)
	admin := h.adminClient()

	_, page := h.do(admin, "GET", "/admin/users", nil, nil)
	if !strings.Contains(page, "Add a user") || !strings.Contains(page, "<strong>admin</strong>") || !strings.Contains(page, `href="/admin/users"`) {
		t.Fatal("admin should see the Users page and menu link")
	}

	// add with a typed temporary password
	add := func(name, role, pw string) (*http.Response, string) {
		return h.do(admin, "POST", "/admin/users", url.Values{"csrf": {h.token(admin)}, "username": {name}, "role": {role}, "password": {pw}}, nil)
	}
	if resp, body := add("alice", "member", "alices-temp-pw-1"); resp.StatusCode != 200 || strings.Contains(body, "alices-temp-pw-1") {
		t.Fatalf("add alice: %d (a typed password must not be echoed back)", resp.StatusCode)
	}
	// add with a generated password: shown once
	resp, body := add("bob", "admin", "")
	if resp.StatusCode != 200 || !strings.Contains(body, "Temporary password for bob") {
		t.Fatalf("add bob: %d", resp.StatusCode)
	}
	bobPW := between(body, `<code class="big-code">`, `</code>`)
	if len(bobPW) != 16 {
		t.Fatalf("generated password %q", bobPW)
	}
	if _, again := h.do(admin, "GET", "/admin/users", nil, nil); strings.Contains(again, bobPW) {
		t.Error("a generated password must be shown once only")
	}
	// rules
	for name, args := range map[string][3]string{"duplicate (case)": {"ALICE", "member", "another-long-pw-12"}, "bad name": {"a b", "member", "another-long-pw-12"}, "short password": {"carol", "member", "short"}, "bad role": {"carol", "root", "another-long-pw-12"}} {
		if resp, body := add(args[0], args[1], args[2]); resp.StatusCode != http.StatusUnprocessableEntity || !strings.Contains(body, `class="error"`) {
			t.Errorf("%s: %d", name, resp.StatusCode)
		}
	}

	// alice (member): forced change, then works, but cannot manage users
	alice := h.loginAs("alice", "alices-temp-pw-1")
	if r, _ := h.do(alice, "GET", "/admin/", nil, nil); r.Header.Get("Location") != "/admin/password" {
		t.Error("a new user must be forced to change their password")
	}
	_, pp := h.do(alice, "GET", "/admin/password", nil, nil)
	if r, _ := h.do(alice, "POST", "/admin/password", url.Values{"csrf": {h.csrfFrom(pp)}, "current": {"alices-temp-pw-1"}, "new": {"alices-own-password-9"}, "confirm": {"alices-own-password-9"}}, nil); r.StatusCode != http.StatusSeeOther {
		t.Fatalf("alice changes password: %d", r.StatusCode)
	}
	if r, body := h.do(alice, "GET", "/admin/users", nil, nil); r.StatusCode != http.StatusForbidden || !strings.Contains(body, "Only an admin") {
		t.Errorf("member opened the Users page: %d", r.StatusCode)
	}
	for _, p := range []string{"/admin/users", "/admin/users/1/reset", "/admin/users/1/delete", "/admin/users/1/role"} {
		if r, _ := h.do(alice, "POST", p, url.Values{"csrf": {h.token(alice)}, "username": {"x"}, "role": {"admin"}}, nil); r.StatusCode != http.StatusForbidden {
			t.Errorf("member POST %s: %d, want 403", p, r.StatusCode)
		}
	}
	if _, nav := h.do(alice, "GET", "/admin/", nil, nil); strings.Contains(nav, `href="/admin/users"`) {
		t.Error("members must not see the Users menu link")
	}
	// but a member can do the real work
	if _, code := h.create(alice, "dynamic", "url", url.Values{"f_url": {"https://www.blake-uk.com/"}}); code == "" {
		t.Error("a member must be able to create QR codes")
	}

	// reset alice's password: shown once, her session dies, old password dead
	var aliceID int64
	h.db.QueryRow(`SELECT id FROM users WHERE username='alice'`).Scan(&aliceID)
	resp, body = h.do(admin, "POST", fmt.Sprintf("/admin/users/%d/reset", aliceID), url.Values{"csrf": {h.token(admin)}, "password": {""}}, nil)
	newPW := between(body, `<code class="big-code">`, `</code>`)
	if resp.StatusCode != 200 || len(newPW) != 16 {
		t.Fatalf("reset: %d %q", resp.StatusCode, newPW)
	}
	if r, _ := h.do(alice, "GET", "/admin/", nil, nil); r.Header.Get("Location") != "/admin/login" {
		t.Error("reset must sign the person out")
	}
	if _, pg := h.do(h.client(), "GET", "/admin/login", nil, nil); true {
		r, _ := h.do(h.client(), "POST", "/admin/login", url.Values{"csrf": {h.csrfFrom(pg)}, "username": {"alice"}, "password": {"alices-own-password-9"}}, nil)
		if r.StatusCode == http.StatusSeeOther {
			t.Error("the old password still works after a reset")
		}
	}
	a2 := h.loginAs("alice", newPW)
	if r, _ := h.do(a2, "GET", "/admin/", nil, nil); r.Header.Get("Location") != "/admin/password" {
		t.Error("a reset password must force a change again")
	}

	// role change and the guards
	var adminID, bobID int64
	h.db.QueryRow(`SELECT id FROM users WHERE username='admin'`).Scan(&adminID)
	h.db.QueryRow(`SELECT id FROM users WHERE username='bob'`).Scan(&bobID)
	if r, _ := h.do(admin, "POST", fmt.Sprintf("/admin/users/%d/delete", adminID), url.Values{"csrf": {h.token(admin)}}, nil); r.StatusCode != http.StatusUnprocessableEntity {
		t.Errorf("deleting yourself: %d", r.StatusCode)
	}
	if r, body := h.do(admin, "POST", fmt.Sprintf("/admin/users/%d/role", bobID), url.Values{"csrf": {h.token(admin)}, "role": {"member"}}, nil); r.StatusCode != http.StatusSeeOther {
		t.Fatalf("demote bob while another admin exists: %d %s", r.StatusCode, firstErrors(body))
	}
	if r, body := h.do(admin, "POST", fmt.Sprintf("/admin/users/%d/role", adminID), url.Values{"csrf": {h.token(admin)}, "role": {"member"}}, nil); r.StatusCode != http.StatusUnprocessableEntity || !strings.Contains(body, "last admin") {
		t.Errorf("demoting the last admin: %d", r.StatusCode)
	}
	// remove bob
	if r, _ := h.do(admin, "POST", fmt.Sprintf("/admin/users/%d/delete", bobID), url.Values{"csrf": {h.token(admin)}}, nil); r.StatusCode != http.StatusSeeOther {
		t.Fatalf("remove bob: %d", r.StatusCode)
	}
	_, pg := h.do(h.client(), "GET", "/admin/login", nil, nil)
	if r, _ := h.do(h.client(), "POST", "/admin/login", url.Values{"csrf": {h.csrfFrom(pg)}, "username": {"bob"}, "password": {bobPW}}, nil); r.StatusCode == http.StatusSeeOther {
		t.Error("a removed user can still sign in")
	}

	// CSRF on every users POST
	for _, p := range []string{"/admin/users", fmt.Sprintf("/admin/users/%d/reset", aliceID), fmt.Sprintf("/admin/users/%d/delete", aliceID), fmt.Sprintf("/admin/users/%d/role", aliceID)} {
		if r, _ := h.do(admin, "POST", p, url.Values{"username": {"x"}}, nil); r.StatusCode != http.StatusForbidden {
			t.Errorf("POST %s without a CSRF token: %d", p, r.StatusCode)
		}
	}
	// audit trail: who did what, with no passwords in it
	_, final := h.do(admin, "GET", "/admin/users", nil, nil)
	for _, want := range []string{"Recent activity", "user.create", "user.reset_password", "user.delete", "user.role", "qr.create"} {
		if !strings.Contains(final, want) {
			t.Errorf("activity log missing %q", want)
		}
	}
	for _, secret := range []string{"alices-temp-pw-1", "alices-own-password-9", bobPW, newPW} {
		if strings.Contains(final, secret) {
			t.Errorf("a password appears on the users page: %q", secret)
		}
	}
	var audit string
	rows, _ := h.db.Query(`SELECT actor || ' ' || action || ' ' || target || ' ' || detail FROM audit_log`)
	for rows.Next() {
		var s string
		rows.Scan(&s)
		audit += s + "\n"
	}
	rows.Close()
	for _, secret := range []string{"alices-temp-pw-1", bobPW, newPW} {
		if strings.Contains(audit, secret) {
			t.Errorf("a password was written to the audit log: %q", secret)
		}
	}
}

func between(s, a, b string) string {
	i := strings.Index(s, a)
	if i < 0 {
		return ""
	}
	s = s[i+len(a):]
	j := strings.Index(s, b)
	if j < 0 {
		return ""
	}
	return s[:j]
}

func TestDesignTemplatesPage(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	h.srv.links.SaveTemplate(nil2(), "Brand", `{"fg":"#0b2a6f"}`, nil)
	_, page := h.do(c, "GET", "/admin/templates", nil, nil)
	if !strings.Contains(page, "Brand") {
		t.Fatal("template not listed")
	}
	tpls, _ := h.srv.links.Templates(nil2())
	if r, _ := h.do(c, "POST", fmt.Sprintf("/admin/templates/%d/delete", tpls[0].ID), url.Values{"csrf": {h.token(c)}}, nil); r.StatusCode != http.StatusSeeOther {
		t.Fatalf("delete: %d", r.StatusCode)
	}
	if left, _ := h.srv.links.Templates(nil2()); len(left) != 0 {
		t.Error("template not deleted")
	}
	_ = httptest.NewRecorder
}

func nil2() context.Context { return context.Background() }
