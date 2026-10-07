package web

import (
	"bytes"
	"context"
	"database/sql"
	"fmt"
	"image/png"
	"io"
	"log/slog"
	"net"
	"net/http"
	"net/http/cookiejar"
	"net/http/httptest"
	"net/url"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"sync"
	"testing"
	"time"

	"golang.org/x/crypto/bcrypt"

	qrtrack "github.com/BlakeUK/Blake-AI-Chatbot/qrcode"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/auth"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/db"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/geo"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/links"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/scans"
)

const (
	seedPW = "initial-pw"
	newPW  = "a-brand-new-password-1"
)

type clock struct {
	mu sync.Mutex
	t  time.Time
}

func (c *clock) Now() time.Time { c.mu.Lock(); defer c.mu.Unlock(); return c.t }
func (c *clock) Advance(d time.Duration) {
	c.mu.Lock()
	c.t = c.t.Add(d)
	c.mu.Unlock()
}

type syncBuf struct {
	mu sync.Mutex
	b  bytes.Buffer
}

func (s *syncBuf) Write(p []byte) (int, error) { s.mu.Lock(); defer s.mu.Unlock(); return s.b.Write(p) }
func (s *syncBuf) String() string              { s.mu.Lock(); defer s.mu.Unlock(); return s.b.String() }

type harness struct {
	t      *testing.T
	srv    *Server
	ts     *httptest.Server
	db     *sql.DB
	clk    *clock
	logs   *syncBuf
	writer *scans.Writer
	links  *links.Store
	auth   *auth.Service
}

func newHarness(t *testing.T, tweak ...func(*Config)) *harness {
	t.Helper()
	d, err := db.Open(filepath.Join(t.TempDir(), "t.db"))
	if err != nil {
		t.Fatal(err)
	}
	ctx := context.Background()
	if err := db.Migrate(ctx, d, qrtrack.Migrations, "migrations"); err != nil {
		t.Fatal(err)
	}
	// Start at the real time: cookie jars compare cookie expiry with the wall clock.
	clk := &clock{t: time.Now().UTC().Truncate(time.Second)}
	logs := &syncBuf{}
	log := slog.New(slog.NewTextHandler(logs, &slog.HandlerOptions{Level: slog.LevelDebug}))

	key, _ := db.Secret(ctx, d, "login_ip_key")
	a := auth.New(d, key)
	a.Cost = bcrypt.MinCost
	a.Now = clk.Now
	if ok, err := a.Seed(ctx, "admin", seedPW); !ok || err != nil {
		t.Fatalf("seed: %v %v", ok, err)
	}
	w := scans.NewWriter(d, 4096, log)
	w.Start()
	st := links.NewStore(d)
	st.Now = clk.Now

	_, loopback, _ := net.ParseCIDR("127.0.0.1/32")
	_, private, _ := net.ParseCIDR("10.0.0.0/8")
	london, _ := time.LoadLocation("Europe/London")
	cfg := Config{BaseURL: "http://qr.test", TrustedProxies: []*net.IPNet{loopback, private}, Location: london}
	for _, f := range tweak {
		f(&cfg)
	}
	srv, err := New(cfg, Deps{DB: d, Links: st, Auth: a, Hasher: scans.NewHasher(d, clk.Now), Writer: w,
		Geo: &geo.Resolver{}, Now: clk.Now, Log: log, Assets: qrtrack.Web})
	if err != nil {
		t.Fatal(err)
	}
	ts := httptest.NewServer(srv.Handler())
	h := &harness{t: t, srv: srv, ts: ts, db: d, clk: clk, logs: logs, writer: w, links: st, auth: a}
	t.Cleanup(func() {
		ts.Close()
		cctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		w.Close(cctx)
		d.Close()
	})
	return h
}

func (h *harness) client() *http.Client {
	jar, _ := cookiejar.New(nil)
	return &http.Client{Jar: jar, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
}

func (h *harness) do(c *http.Client, method, path string, form url.Values, hdr map[string]string) (*http.Response, string) {
	h.t.Helper()
	var body io.Reader
	if form != nil {
		body = strings.NewReader(form.Encode())
	}
	req, _ := http.NewRequest(method, h.ts.URL+path, body)
	if form != nil {
		req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	}
	for k, v := range hdr {
		req.Header.Set(k, v)
	}
	resp, err := c.Do(req)
	if err != nil {
		h.t.Fatal(err)
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(resp.Body)
	return resp, string(b)
}

var csrfRe = regexp.MustCompile(`name="csrf" value="([^"]+)"`)

func (h *harness) csrfFrom(body string) string {
	m := csrfRe.FindStringSubmatch(body)
	if m == nil {
		h.t.Fatalf("no csrf token in page:\n%.400s", body)
	}
	return m[1]
}

// rawLogin performs the sign-in form for the given password and source IP.
func (h *harness) rawLogin(c *http.Client, pw, ip string) (*http.Response, string) {
	_, page := h.do(c, "GET", "/admin/login", nil, map[string]string{"X-Forwarded-For": ip})
	return h.do(c, "POST", "/admin/login", url.Values{"csrf": {h.csrfFrom(page)}, "username": {"admin"}, "password": {pw}},
		map[string]string{"X-Forwarded-For": ip})
}

// adminClient signs in, completes the forced password change and returns a
// ready-to-use authenticated client.
func (h *harness) adminClient() *http.Client {
	h.t.Helper()
	c := h.client()
	resp, _ := h.rawLogin(c, seedPW, "198.51.100.200")
	if resp.StatusCode != http.StatusSeeOther {
		h.t.Fatalf("login status %d", resp.StatusCode)
	}
	_, page := h.do(c, "GET", "/admin/password", nil, nil)
	resp, body := h.do(c, "POST", "/admin/password", url.Values{"csrf": {h.csrfFrom(page)}, "current": {seedPW}, "new": {newPW}, "confirm": {newPW}}, nil)
	if resp.StatusCode != http.StatusSeeOther {
		h.t.Fatalf("password change status %d: %.300s", resp.StatusCode, body)
	}
	return c
}

func (h *harness) token(c *http.Client) string {
	_, page := h.do(c, "GET", "/admin/links/new", nil, nil)
	return h.csrfFrom(page)
}

func (h *harness) createViaForm(c *http.Client, v url.Values) (*http.Response, string) {
	v.Set("csrf", h.token(c))
	return h.do(c, "POST", "/admin/links", v, nil)
}

func (h *harness) mkLink(start, end time.Time, mode links.ExpiryMode, fallback string) *links.Link {
	h.t.Helper()
	l, err := h.links.Create(context.Background(), links.Input{
		Label: "Poster", Destination: "https://www.blake-uk.com/category/aerials.html", Start: start, End: end,
		ExpiryMode: mode, FallbackURL: fallback, QRECC: "M"})
	if err != nil {
		h.t.Fatal(err)
	}
	return l
}

func (h *harness) scanCount(linkID int64) (all, bots int) {
	h.t.Helper()
	if err := h.writer.Flush(context.Background()); err != nil {
		h.t.Fatal(err)
	}
	h.db.QueryRow(`SELECT COALESCE(SUM(is_bot=0),0), COALESCE(SUM(is_bot),0) FROM scans WHERE link_id=?`, linkID).Scan(&all, &bots)
	return
}

const iphoneUA = "Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1"

func (h *harness) scan(code, ip, agent string) *http.Response {
	c := &http.Client{CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	resp, _ := h.do(c, "GET", "/r/"+code, nil, map[string]string{"X-Forwarded-For": ip, "User-Agent": agent})
	return resp
}

// ---------- authentication ----------

func TestLoginFlowWithForcedPasswordChange(t *testing.T) {
	h := newHarness(t)
	c := h.client()

	// Not signed in: bounced to the login page.
	resp, _ := h.do(c, "GET", "/admin/", nil, nil)
	if resp.StatusCode != http.StatusSeeOther || resp.Header.Get("Location") != "/admin/login" {
		t.Fatalf("unauthenticated /admin/: %d %s", resp.StatusCode, resp.Header.Get("Location"))
	}

	// Wrong password: 401 with the generic message only.
	resp, body := h.rawLogin(c, "wrong-password", "198.51.100.1")
	if resp.StatusCode != http.StatusUnauthorized || !strings.Contains(body, "Invalid username or password.") {
		t.Fatalf("wrong password: %d %.200s", resp.StatusCode, body)
	}

	// Right password: signed in, but every admin page redirects to the forced change.
	resp, _ = h.rawLogin(c, seedPW, "198.51.100.1")
	if resp.StatusCode != http.StatusSeeOther {
		t.Fatalf("login: %d", resp.StatusCode)
	}
	cookie := ""
	for _, ck := range resp.Cookies() {
		if ck.Name == sessionCookie {
			cookie = ck.Value
			if !ck.HttpOnly || ck.SameSite != http.SameSiteStrictMode {
				t.Errorf("session cookie flags wrong: %+v", ck)
			}
			if ck.Expires.IsZero() {
				t.Error("session cookie has no absolute expiry")
			}
		}
	}
	if cookie == "" {
		t.Fatal("no session cookie")
	}
	resp, _ = h.do(c, "GET", "/admin/", nil, nil)
	if resp.StatusCode != http.StatusSeeOther || resp.Header.Get("Location") != "/admin/password" {
		t.Fatalf("expected forced password change, got %d %s", resp.StatusCode, resp.Header.Get("Location"))
	}
	resp, _ = h.do(c, "GET", "/admin/links/new", nil, nil)
	if resp.Header.Get("Location") != "/admin/password" {
		t.Error("forced change not enforced on other admin pages")
	}

	_, page := h.do(c, "GET", "/admin/password", nil, nil)
	tok := h.csrfFrom(page)

	// Missing CSRF token: refused.
	resp, _ = h.do(c, "POST", "/admin/password", url.Values{"current": {seedPW}, "new": {newPW}, "confirm": {newPW}}, nil)
	if resp.StatusCode != http.StatusForbidden {
		t.Errorf("POST without CSRF token: %d", resp.StatusCode)
	}
	// Too short: refused with a message.
	resp, body = h.do(c, "POST", "/admin/password", url.Values{"csrf": {tok}, "current": {seedPW}, "new": {"short"}, "confirm": {"short"}}, nil)
	if resp.StatusCode != http.StatusUnprocessableEntity || !strings.Contains(body, "at least 12 characters") {
		t.Errorf("short password: %d %.200s", resp.StatusCode, body)
	}
	// Mismatch.
	resp, _ = h.do(c, "POST", "/admin/password", url.Values{"csrf": {tok}, "current": {seedPW}, "new": {newPW}, "confirm": {newPW + "x"}}, nil)
	if resp.StatusCode != http.StatusUnprocessableEntity {
		t.Errorf("mismatched confirmation: %d", resp.StatusCode)
	}
	// Valid change.
	resp, _ = h.do(c, "POST", "/admin/password", url.Values{"csrf": {tok}, "current": {seedPW}, "new": {newPW}, "confirm": {newPW}}, nil)
	if resp.StatusCode != http.StatusSeeOther || resp.Header.Get("Location") != "/admin/" {
		t.Fatalf("change: %d %s", resp.StatusCode, resp.Header.Get("Location"))
	}
	resp, body = h.do(c, "GET", "/admin/", nil, nil)
	if resp.StatusCode != http.StatusOK || !strings.Contains(body, "Tracked links") {
		t.Fatalf("dashboard after change: %d", resp.StatusCode)
	}

	// Logout kills the session server-side: replaying the old cookie fails.
	resp, _ = h.do(c, "POST", "/admin/logout", url.Values{"csrf": {tok}}, nil)
	if resp.StatusCode != http.StatusSeeOther {
		t.Fatalf("logout: %d", resp.StatusCode)
	}
	replay, _ := http.NewRequest("GET", h.ts.URL+"/admin/", nil)
	replay.AddCookie(&http.Cookie{Name: sessionCookie, Value: cookie})
	r2, _ := (&http.Client{CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}).Do(replay)
	if r2.StatusCode != http.StatusSeeOther || r2.Header.Get("Location") != "/admin/login" {
		t.Errorf("old cookie still works after logout: %d", r2.StatusCode)
	}
}

func TestCSRFOnEveryAdminPost(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	l := h.mkLink(h.clk.Now(), h.clk.Now().Add(time.Hour), links.RedirectUntracked, "")
	for _, p := range []string{"/admin/links", fmt.Sprintf("/admin/links/%d", l.ID), fmt.Sprintf("/admin/links/%d/toggle", l.ID),
		fmt.Sprintf("/admin/links/%d/delete", l.ID), "/admin/logout", "/admin/password"} {
		for name, form := range map[string]url.Values{"missing": {"label": {"x"}}, "wrong": {"csrf": {"not-the-token"}, "label": {"x"}}} {
			resp, _ := h.do(c, "POST", p, form, nil)
			if resp.StatusCode != http.StatusForbidden {
				t.Errorf("POST %s with %s CSRF token: status %d, want 403", p, name, resp.StatusCode)
			}
		}
	}
	if _, err := h.links.Get(context.Background(), l.ID); err != nil {
		t.Error("link was deleted by a CSRF-less request")
	}
}

func TestLoginFormCSRF(t *testing.T) {
	h := newHarness(t)
	c := h.client()
	h.do(c, "GET", "/admin/login", nil, nil)
	resp, _ := h.do(c, "POST", "/admin/login", url.Values{"csrf": {"forged"}, "username": {"admin"}, "password": {seedPW}}, nil)
	if resp.StatusCode != http.StatusForbidden {
		t.Errorf("login with forged CSRF token: %d", resp.StatusCode)
	}
	bare := h.client() // never loaded the form, so has no cookie
	resp, _ = h.do(bare, "POST", "/admin/login", url.Values{"username": {"admin"}, "password": {seedPW}}, nil)
	if resp.StatusCode != http.StatusForbidden {
		t.Errorf("login without CSRF cookie: %d", resp.StatusCode)
	}
}

func TestLockoutOverHTTP(t *testing.T) {
	h := newHarness(t)
	ip := "198.51.100.50"
	for i := 0; i < 5; i++ {
		resp, _ := h.rawLogin(h.client(), "wrong", ip)
		if resp.StatusCode != http.StatusUnauthorized {
			t.Fatalf("attempt %d: %d", i, resp.StatusCode)
		}
		h.clk.Advance(time.Minute)
	}
	resp, body := h.rawLogin(h.client(), seedPW, ip) // correct password, but locked
	if resp.StatusCode != http.StatusTooManyRequests || !strings.Contains(body, "Too many failed attempts") {
		t.Fatalf("expected lockout: %d %.200s", resp.StatusCode, body)
	}
	if resp.Header.Get("Retry-After") == "" {
		t.Error("lockout response has no Retry-After")
	}
	if resp, _ := h.rawLogin(h.client(), seedPW, "198.51.100.51"); resp.StatusCode != http.StatusSeeOther {
		t.Errorf("a different address was locked out too: %d", resp.StatusCode)
	}
	h.clk.Advance(16 * time.Minute)
	if resp, _ := h.rawLogin(h.client(), seedPW, ip); resp.StatusCode != http.StatusSeeOther {
		t.Errorf("lock did not lift after 15 minutes: %d", resp.StatusCode)
	}
}

// ---------- the core flow ----------

func TestCreateLinkScanAndDashboardWithin2Seconds(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()

	// Create through the real form with the 7-day preset.
	resp, _ := h.createViaForm(c, url.Values{
		"label": {"Autumn poster"}, "destination": {"https://www.blake-uk.com/category/aerials.html"},
		"window": {"7d"}, "expiry_mode": {"redirect_untracked"}, "qr_ecc": {"H"},
	})
	if resp.StatusCode != http.StatusSeeOther || !strings.HasPrefix(resp.Header.Get("Location"), "/admin/links/") {
		t.Fatalf("create: %d %s", resp.StatusCode, resp.Header.Get("Location"))
	}
	var id int64
	var code string
	var start, end string
	h.db.QueryRow(`SELECT id, code, track_start, track_end FROM links`).Scan(&id, &code, &start, &end)
	s, _ := db.ParseTS(start)
	e, _ := db.ParseTS(end)
	if e.Sub(s) != 7*24*time.Hour || !s.Equal(h.clk.Now()) {
		t.Errorf("7d preset stored as %s to %s", start, end)
	}

	// A phone scans it. No auth, no cookie.
	resp = h.scan(code, "203.0.113.10", iphoneUA)
	if resp.StatusCode != http.StatusFound || resp.Header.Get("Location") != "https://www.blake-uk.com/category/aerials.html" {
		t.Fatalf("scan redirect: %d -> %s", resp.StatusCode, resp.Header.Get("Location"))
	}
	if resp.Header.Get("Cache-Control") != "no-store" {
		t.Errorf("redirect Cache-Control = %q", resp.Header.Get("Cache-Control"))
	}

	// It must appear on the dashboard within 2 s WITHOUT any manual flush.
	deadline := time.Now().Add(2 * time.Second)
	var body string
	for {
		_, body = h.do(c, "GET", "/admin/", nil, nil)
		if strings.Contains(body, `class="num">1</td>`) {
			break
		}
		if time.Now().After(deadline) {
			t.Fatalf("scan not on the dashboard after 2s:\n%s", body)
		}
		time.Sleep(50 * time.Millisecond)
	}

	// Detail page: totals, chart, breakdown, countdown, QR.
	_, body = h.do(c, "GET", fmt.Sprintf("/admin/links/%d", id), nil, nil)
	for _, want := range []string{"Autumn poster", "all scans", `class="chart"`, "mobile", "iOS", "Safari", "left in the tracking window",
		fmt.Sprintf("http://qr.test/r/%s", code), "qr.svg", "PNG 1024"} {
		if !strings.Contains(body, want) {
			t.Errorf("detail page missing %q", want)
		}
	}

	// Bots and link previews are recorded but excluded from the headline.
	h.scan(code, "203.0.113.11", "WhatsApp/2.23.20.0 A")
	h.scan(code, "203.0.113.12", "facebookexternalhit/1.1")
	all, bots := h.scanCount(id)
	if all != 1 || bots != 2 {
		t.Errorf("humans=%d bots=%d, want 1 and 2", all, bots)
	}
}

func TestWindowBehaviours(t *testing.T) {
	h := newHarness(t)
	now := h.clk.Now()
	dest := "https://www.blake-uk.com/category/aerials.html"

	active := h.mkLink(now.Add(-time.Hour), now.Add(time.Hour), links.RedirectUntracked, "")
	future := h.mkLink(now.Add(time.Hour), now.Add(2*time.Hour), links.RedirectUntracked, "")

	if resp := h.scan(active.Code, "203.0.113.1", iphoneUA); resp.StatusCode != 302 {
		t.Fatalf("active: %d", resp.StatusCode)
	}
	if n, _ := h.scanCount(active.ID); n != 1 {
		t.Errorf("active window: %d scans logged, want 1", n)
	}
	// Before the window: redirects, not logged.
	if resp := h.scan(future.Code, "203.0.113.2", iphoneUA); resp.StatusCode != 302 || resp.Header.Get("Location") != dest {
		t.Errorf("scheduled: %d", resp.StatusCode)
	}
	if n, _ := h.scanCount(future.ID); n != 0 {
		t.Errorf("scan before the window was logged (%d)", n)
	}

	// Move past the end of the window; exercise all three expiry behaviours.
	h.clk.Advance(3 * time.Hour)
	untracked := active
	expiredPage := h.mkLink(now.Add(-2*time.Hour), now.Add(-time.Hour), links.ShowExpiredPage, "")
	fallback := h.mkLink(now.Add(-2*time.Hour), now.Add(-time.Hour), links.RedirectFallbackURL, "https://www.blake-uk.com/category/sale.html")

	resp := h.scan(untracked.Code, "203.0.113.3", iphoneUA)
	if resp.StatusCode != 302 || resp.Header.Get("Location") != dest {
		t.Errorf("redirect_untracked after the window: %d %s", resp.StatusCode, resp.Header.Get("Location"))
	}
	if n, _ := h.scanCount(untracked.ID); n != 1 {
		t.Errorf("scan after the window was logged: total %d, want still 1", n)
	}

	c := &http.Client{CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	r, body := h.do(c, "GET", "/r/"+expiredPage.Code, nil, map[string]string{"User-Agent": iphoneUA})
	if r.StatusCode != http.StatusGone || !strings.Contains(body, "expired") {
		t.Errorf("show_expired_page: %d %.120s", r.StatusCode, body)
	}
	if n, _ := h.scanCount(expiredPage.ID); n != 0 {
		t.Error("expired-page visit was logged")
	}
	r = h.scan(fallback.Code, "203.0.113.4", iphoneUA)
	if r.StatusCode != 302 || r.Header.Get("Location") != "https://www.blake-uk.com/category/sale.html" {
		t.Errorf("redirect_fallback_url: %d %s", r.StatusCode, r.Header.Get("Location"))
	}
	if n, _ := h.scanCount(fallback.ID); n != 0 {
		t.Error("fallback redirect was logged")
	}
}

func TestDisabledUnknownAndHEAD(t *testing.T) {
	h := newHarness(t)
	now := h.clk.Now()
	l := h.mkLink(now.Add(-time.Hour), now.Add(time.Hour), links.RedirectUntracked, "")

	// HEAD is served but never counted.
	c := &http.Client{CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	r, _ := h.do(c, "HEAD", "/r/"+l.Code, nil, map[string]string{"User-Agent": iphoneUA, "X-Forwarded-For": "203.0.113.5"})
	if r.StatusCode != 302 {
		t.Errorf("HEAD: %d", r.StatusCode)
	}
	if n, _ := h.scanCount(l.ID); n != 0 {
		t.Error("HEAD request was counted as a scan")
	}

	if err := h.links.SetEnabled(context.Background(), l.ID, false); err != nil {
		t.Fatal(err)
	}
	if r := h.scan(l.Code, "203.0.113.6", iphoneUA); r.StatusCode != http.StatusGone {
		t.Errorf("disabled link: %d, want 410", r.StatusCode)
	}
	if n, _ := h.scanCount(l.ID); n != 0 {
		t.Error("disabled link logged a scan")
	}
	for _, code := range []string{"ZZZZZZZZ", "short", "waytoolongcodehere", "..%2f..%2fetc", "ab cdefg", "abcdefg%00"} {
		if r := h.scan(code, "203.0.113.7", iphoneUA); r.StatusCode != http.StatusNotFound {
			t.Errorf("code %q: %d, want 404", code, r.StatusCode)
		}
	}
}

func TestOpenRedirectImpossible(t *testing.T) {
	h := newHarness(t)
	now := h.clk.Now()
	l := h.mkLink(now.Add(-time.Hour), now.Add(time.Hour), links.RedirectUntracked, "")
	c := &http.Client{CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	for _, q := range []string{"?url=https://evil.example", "?next=//evil.example", "?redirect=https://evil.example&to=https://evil.example", "?r=javascript:alert(1)"} {
		r, _ := h.do(c, "GET", "/r/"+l.Code+q, nil, map[string]string{"User-Agent": iphoneUA, "Referer": "https://evil.example/x?y=1"})
		if loc := r.Header.Get("Location"); loc != l.DestinationURL {
			t.Errorf("query %q changed the redirect target to %q", q, loc)
		}
	}
}

func TestRateLimitOnRedirect(t *testing.T) {
	h := newHarness(t, func(c *Config) { c.RateLimitPerMin = 5 })
	now := h.clk.Now()
	l := h.mkLink(now.Add(-time.Hour), now.Add(time.Hour), links.RedirectUntracked, "")
	for i := 0; i < 5; i++ {
		if r := h.scan(l.Code, "203.0.113.20", iphoneUA); r.StatusCode != 302 {
			t.Fatalf("request %d: %d", i, r.StatusCode)
		}
	}
	r := h.scan(l.Code, "203.0.113.20", iphoneUA)
	if r.StatusCode != http.StatusTooManyRequests || r.Header.Get("Retry-After") == "" {
		t.Errorf("6th request: %d, want 429", r.StatusCode)
	}
	if r := h.scan(l.Code, "203.0.113.21", iphoneUA); r.StatusCode != 302 {
		t.Errorf("other client throttled: %d", r.StatusCode)
	}
	h.clk.Advance(30 * time.Second) // 5/min refills ~2.5 tokens in 30 s
	if r := h.scan(l.Code, "203.0.113.20", iphoneUA); r.StatusCode != 302 {
		t.Errorf("bucket did not refill: %d", r.StatusCode)
	}
}

func TestClientIPTrust(t *testing.T) {
	h := newHarness(t)
	ip := func(remote, xff string) string {
		r := httptest.NewRequest("GET", "/r/x", nil)
		r.RemoteAddr = remote
		if xff != "" {
			r.Header.Set("X-Forwarded-For", xff)
		}
		return h.srv.clientIP(r)
	}
	cases := []struct{ name, remote, xff, want string }{
		{"direct client, no header", "198.51.100.5:4000", "", "198.51.100.5"},
		{"untrusted peer cannot spoof", "198.51.100.5:4000", "1.2.3.4", "198.51.100.5"},
		{"trusted proxy, single client", "127.0.0.1:5000", "203.0.113.9", "203.0.113.9"},
		{"forged leftmost entry ignored", "127.0.0.1:5000", "1.1.1.1, 203.0.113.9", "203.0.113.9"},
		{"chain through trusted hop", "127.0.0.1:5000", "203.0.113.9, 10.0.0.5", "203.0.113.9"},
		{"garbage entries skipped", "127.0.0.1:5000", "203.0.113.9, not-an-ip", "203.0.113.9"},
		{"all trusted falls back to peer", "127.0.0.1:5000", "10.0.0.1", "127.0.0.1"},
		{"ipv6 client", "127.0.0.1:5000", "2001:db8::7", "2001:db8::7"},
	}
	for _, c := range cases {
		if got := ip(c.remote, c.xff); got != c.want {
			t.Errorf("%s: got %s want %s", c.name, got, c.want)
		}
	}
}

// ---------- privacy ----------

func TestNoRawIPsOrPlaintextCredentialsAnywhere(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	now := h.clk.Now()
	l := h.mkLink(now.Add(-time.Hour), now.Add(time.Hour), links.RedirectUntracked, "")
	rawIPs := []string{"203.0.113.77", "198.51.100.88", "2001:db8::beef"}
	for _, ip := range rawIPs {
		h.scan(l.Code, ip, iphoneUA)
	}
	h.rawLogin(h.client(), "bad-password-attempt", "198.51.100.99")
	h.scanCount(l.ID)
	h.do(c, "GET", fmt.Sprintf("/admin/links/%d", l.ID), nil, nil)
	h.do(c, "GET", fmt.Sprintf("/admin/links/%d/scans.csv", l.ID), nil, nil)

	var dump strings.Builder
	tables := []string{"users", "sessions", "links", "scans", "login_attempts", "settings", "daily_salts", "schema_migrations"}
	for _, tb := range tables {
		rows, err := h.db.Query("SELECT * FROM " + tb)
		if err != nil {
			t.Fatal(err)
		}
		cols, _ := rows.Columns()
		for rows.Next() {
			vals := make([]any, len(cols))
			ptrs := make([]any, len(cols))
			for i := range vals {
				ptrs[i] = &vals[i]
			}
			rows.Scan(ptrs...)
			for _, v := range vals {
				switch x := v.(type) {
				case []byte:
					dump.WriteString(string(x))
				default:
					fmt.Fprint(&dump, x)
				}
				dump.WriteByte('|')
			}
			dump.WriteByte('\n')
		}
		rows.Close()
	}
	// Also catch the WAL, where recent writes live until checkpointed.
	h.db.Exec(`PRAGMA wal_checkpoint(TRUNCATE)`)

	secrets := append([]string{seedPW, newPW, "bad-password-attempt", "198.51.100.200"}, rawIPs...)
	for _, s := range secrets {
		if strings.Contains(dump.String(), s) {
			t.Errorf("database contains %q in plaintext", s)
		}
		if strings.Contains(h.logs.String(), s) {
			t.Errorf("application log contains %q", s)
		}
	}
	var n int
	h.db.QueryRow(`SELECT COUNT(*) FROM scans WHERE length(ip_hash) = 64`).Scan(&n)
	if n != 3 {
		t.Errorf("%d scans have a 64-char hash, want 3", n)
	}
}

// ---------- hardening ----------

func TestSecurityHeaders(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	for _, p := range []string{"/admin/login", "/admin/", "/admin/links/new", "/healthz", "/r/ZZZZZZZZ", "/nope"} {
		resp, _ := h.do(c, "GET", p, nil, nil)
		hd := resp.Header
		if csp := hd.Get("Content-Security-Policy"); !strings.Contains(csp, "default-src 'self'") || !strings.Contains(csp, "frame-ancestors 'none'") {
			t.Errorf("%s CSP = %q", p, csp)
		}
		if hd.Get("X-Content-Type-Options") != "nosniff" || hd.Get("X-Frame-Options") != "DENY" ||
			hd.Get("Referrer-Policy") != "strict-origin-when-cross-origin" {
			t.Errorf("%s missing hardening headers: %v", p, hd)
		}
		if strings.HasPrefix(p, "/admin") && hd.Get("Cache-Control") != "no-store" {
			t.Errorf("%s Cache-Control = %q", p, hd.Get("Cache-Control"))
		}
	}
}

func TestRequestBodyLimit(t *testing.T) {
	h := newHarness(t)
	c := h.client()
	_, page := h.do(c, "GET", "/admin/login", nil, nil)
	big := url.Values{"csrf": {h.csrfFrom(page)}, "username": {"admin"}, "password": {strings.Repeat("a", 100<<10)}}
	resp, _ := h.do(c, "POST", "/admin/login", big, nil)
	if resp.StatusCode == http.StatusOK || resp.StatusCode == http.StatusSeeOther {
		t.Errorf("100 KB body accepted: %d", resp.StatusCode)
	}
}

func TestLinkValidationRejectsBadInput(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	base := func() url.Values {
		return url.Values{"label": {"ok"}, "destination": {"https://www.blake-uk.com/"}, "window": {"24h"}, "expiry_mode": {"redirect_untracked"}, "qr_ecc": {"M"}}
	}
	bad := map[string]func(url.Values){
		"javascript destination": func(v url.Values) { v.Set("destination", "javascript:alert(1)") },
		"data destination":       func(v url.Values) { v.Set("destination", "data:text/html,hi") },
		"empty label":            func(v url.Values) { v.Set("label", " ") },
		"long label":             func(v url.Values) { v.Set("label", strings.Repeat("x", 101)) },
		"self destination":       func(v url.Values) { v.Set("destination", "http://qr.test/r/abcdefgh") },
		"fallback missing":       func(v url.Values) { v.Set("expiry_mode", "redirect_fallback_url") },
		"bad ecc":                func(v url.Values) { v.Set("qr_ecc", "Z") },
		"bad window":             func(v url.Values) { v.Set("window", "custom"); v.Set("start", "garbage"); v.Set("end", "") },
		"end before start": func(v url.Values) {
			v.Set("window", "custom")
			v.Set("start", "2026-10-02T10:00")
			v.Set("end", "2026-10-01T10:00")
		},
	}
	for name, mut := range bad {
		v := base()
		mut(v)
		resp, body := h.createViaForm(c, v)
		if resp.StatusCode != http.StatusUnprocessableEntity || !strings.Contains(body, "field-error") {
			t.Errorf("%s: status %d (want 422 with a field error)", name, resp.StatusCode)
		}
	}
	var n int
	h.db.QueryRow(`SELECT COUNT(*) FROM links`).Scan(&n)
	if n != 0 {
		t.Errorf("%d links created from invalid input", n)
	}
	// And a custom window is read as London time and stored as UTC (BST, UTC+1, in October).
	v := base()
	v.Set("window", "custom")
	v.Set("start", "2026-10-05T09:00")
	v.Set("end", "2026-10-06T09:00")
	if resp, _ := h.createViaForm(c, v); resp.StatusCode != http.StatusSeeOther {
		t.Fatalf("valid custom window: %d", resp.StatusCode)
	}
	var st string
	h.db.QueryRow(`SELECT track_start FROM links`).Scan(&st)
	if st != "2026-10-05T08:00:00Z" {
		t.Errorf("09:00 London stored as %s, want 08:00Z", st)
	}
}

func TestEditToggleDeleteLifecycle(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	now := h.clk.Now()
	l := h.mkLink(now.Add(-time.Hour), now.Add(time.Hour), links.RedirectUntracked, "")
	h.scan(l.Code, "203.0.113.30", iphoneUA)
	if n, _ := h.scanCount(l.ID); n != 1 {
		t.Fatal("setup scan missing")
	}
	tok := h.token(c)

	resp, _ := h.do(c, "POST", fmt.Sprintf("/admin/links/%d", l.ID), url.Values{
		"csrf": {tok}, "label": {"Renamed"}, "destination": {"https://www.blake-uk.com/category/sale.html"},
		"window": {"custom"}, "start": {"2026-10-01T09:00"}, "end": {"2026-12-01T09:00"},
		"expiry_mode": {"show_expired_page"}, "qr_ecc": {"Q"}}, nil)
	if resp.StatusCode != http.StatusSeeOther {
		t.Fatalf("edit: %d", resp.StatusCode)
	}
	got, _ := h.links.Get(context.Background(), l.ID)
	if got.Label != "Renamed" || got.DestinationURL != "https://www.blake-uk.com/category/sale.html" || got.QRECC != "Q" || got.Code != l.Code {
		t.Errorf("edit not applied (or code changed): %+v", got)
	}
	if r := h.scan(l.Code, "203.0.113.31", iphoneUA); r.Header.Get("Location") != got.DestinationURL {
		t.Errorf("edited destination not used: %s", r.Header.Get("Location"))
	}

	h.do(c, "POST", fmt.Sprintf("/admin/links/%d/toggle", l.ID), url.Values{"csrf": {tok}}, nil)
	if r := h.scan(l.Code, "203.0.113.32", iphoneUA); r.StatusCode != http.StatusGone {
		t.Errorf("after disabling: %d", r.StatusCode)
	}
	h.do(c, "POST", fmt.Sprintf("/admin/links/%d/toggle", l.ID), url.Values{"csrf": {tok}}, nil)
	if r := h.scan(l.Code, "203.0.113.33", iphoneUA); r.StatusCode != 302 {
		t.Errorf("after re-enabling: %d", r.StatusCode)
	}

	h.do(c, "POST", fmt.Sprintf("/admin/links/%d/delete", l.ID), url.Values{"csrf": {tok}}, nil)
	if r := h.scan(l.Code, "203.0.113.34", iphoneUA); r.StatusCode != http.StatusNotFound {
		t.Errorf("after deleting: %d", r.StatusCode)
	}
	var n int
	h.db.QueryRow(`SELECT COUNT(*) FROM scans`).Scan(&n)
	if n != 0 {
		t.Errorf("%d orphaned scans after deleting the link", n)
	}
}

func TestDownloadsQRAndCSV(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	now := h.clk.Now()
	l := h.mkLink(now.Add(-time.Hour), now.Add(time.Hour), links.RedirectUntracked, "")
	h.scan(l.Code, "203.0.113.40", iphoneUA)
	h.scanCount(l.ID)

	for _, px := range []int{256, 512, 1024} {
		resp, body := h.do(c, "GET", fmt.Sprintf("/admin/links/%d/qr.png?size=%d&download=1", l.ID, px), nil, nil)
		img, err := png.Decode(strings.NewReader(body))
		if resp.StatusCode != 200 || err != nil || img.Bounds().Dx() != px {
			t.Errorf("png %d: status %d err %v", px, resp.StatusCode, err)
		}
		if !strings.Contains(resp.Header.Get("Content-Disposition"), fmt.Sprintf("qr-%s-%d.png", l.Code, px)) {
			t.Errorf("png %d disposition: %q", px, resp.Header.Get("Content-Disposition"))
		}
	}
	if resp, _ := h.do(c, "GET", fmt.Sprintf("/admin/links/%d/qr.png?size=300", l.ID), nil, nil); resp.StatusCode != 400 {
		t.Errorf("unsupported size: %d", resp.StatusCode)
	}
	resp, body := h.do(c, "GET", fmt.Sprintf("/admin/links/%d/qr.svg?download=1&ecc=H", l.ID), nil, nil)
	if resp.StatusCode != 200 || resp.Header.Get("Content-Type") != "image/svg+xml" || !strings.HasPrefix(body, "<svg") {
		t.Errorf("svg: %d %s", resp.StatusCode, resp.Header.Get("Content-Type"))
	}
	resp, body = h.do(c, "GET", fmt.Sprintf("/admin/links/%d/scans.csv", l.ID), nil, nil)
	if resp.StatusCode != 200 || !strings.HasPrefix(resp.Header.Get("Content-Type"), "text/csv") ||
		!strings.HasPrefix(body, "scanned_at,ip_hash,") || strings.Count(body, "\n") != 2 {
		t.Errorf("csv: %d %q", resp.StatusCode, body)
	}
	// Unauthenticated access to downloads is refused.
	anon := h.client()
	for _, p := range []string{"/qr.png", "/qr.svg", "/scans.csv"} {
		if r, _ := h.do(anon, "GET", fmt.Sprintf("/admin/links/%d%s", l.ID, p), nil, nil); r.StatusCode != http.StatusSeeOther {
			t.Errorf("anonymous GET %s: %d", p, r.StatusCode)
		}
	}
}

// Every page must satisfy the strict CSP: no inline styles, scripts or handlers.
func TestPagesAreCSPClean(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	now := h.clk.Now()
	l := h.mkLink(now.Add(-time.Hour), now.Add(48*time.Hour), links.RedirectUntracked, "")
	h.scan(l.Code, "203.0.113.50", iphoneUA)
	h.scanCount(l.ID)
	inline := regexp.MustCompile(`(?i)\sstyle\s*=|<style|<script[^>]*>[^<]|\son[a-z]+\s*=`)
	pages := []string{"/admin/login", "/admin/", "/admin/links/new", fmt.Sprintf("/admin/links/%d", l.ID),
		fmt.Sprintf("/admin/links/%d?bots=1", l.ID), fmt.Sprintf("/admin/links/%d/edit", l.ID), "/admin/password", "/nope"}
	for _, p := range pages {
		resp, body := h.do(c, "GET", p, nil, nil)
		if p != "/nope" && resp.StatusCode != 200 {
			t.Errorf("%s: status %d", p, resp.StatusCode)
		}
		if m := inline.FindString(body); m != "" {
			t.Errorf("%s contains inline code the CSP would block: %q", p, m)
		}
		if strings.Contains(body, "<no value>") || strings.Contains(body, "%!") {
			t.Errorf("%s has a template formatting artefact", p)
		}
	}
	for _, p := range []string{"/static/app.css", "/static/app.js"} {
		if resp, _ := h.do(c, "GET", p, nil, nil); resp.StatusCode != 200 {
			t.Errorf("%s: %d", p, resp.StatusCode)
		}
	}
	if resp, body := h.do(c, "GET", "/healthz", nil, nil); resp.StatusCode != 200 || !strings.Contains(body, "ok") {
		t.Errorf("healthz: %d", resp.StatusCode)
	}
}

func TestPaginationOnList(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	now := h.clk.Now()
	for i := 0; i < perPage+5; i++ {
		h.mkLink(now, now.Add(time.Hour), links.RedirectUntracked, "")
	}
	_, p1 := h.do(c, "GET", "/admin/", nil, nil)
	_, p2 := h.do(c, "GET", "/admin/?page=2", nil, nil)
	if strings.Count(p1, "<tr>")-1 != perPage || strings.Count(p2, "<tr>")-1 != 5 {
		t.Errorf("rows page1=%d page2=%d", strings.Count(p1, "<tr>")-1, strings.Count(p2, "<tr>")-1)
	}
	if !strings.Contains(p1, "Older") || !strings.Contains(p2, "Newer") {
		t.Error("pager links missing")
	}
}

// ---------- performance ----------

func percentile(d []time.Duration, p float64) time.Duration {
	sort.Slice(d, func(i, j int) bool { return d[i] < d[j] })
	return d[int(float64(len(d)-1)*p)]
}

// TestRedirectLatency checks the stated target: p99 under 5 ms for /r/<code>
// at 500 requests a second, with the scan writer committing concurrently.
// Opt-in (QRTRACK_BENCH=1) because wall-clock timing is meaningless under -race.
func TestRedirectLatency(t *testing.T) {
	if testing.Short() || !benchEnabled() {
		t.Skip("set QRTRACK_BENCH=1 to run the latency check")
	}
	h := newHarness(t, func(c *Config) { c.RateLimitPerMin = 1000000 })
	now := h.clk.Now()
	l := h.mkLink(now.Add(-time.Hour), now.Add(48*time.Hour), links.RedirectUntracked, "")
	handler := h.srv.Handler()

	const rps, seconds = 500, 6
	total := rps * seconds
	lat := make([]time.Duration, total)
	var wg sync.WaitGroup
	tick := time.NewTicker(time.Second / rps)
	defer tick.Stop()
	for i := 0; i < total; i++ {
		<-tick.C
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			r := httptest.NewRequest("GET", "/r/"+l.Code, nil)
			r.RemoteAddr = "127.0.0.1:1"
			r.Header.Set("X-Forwarded-For", fmt.Sprintf("203.0.%d.%d", (i/250)%250, i%250+1))
			r.Header.Set("User-Agent", iphoneUA)
			w := httptest.NewRecorder()
			start := time.Now()
			handler.ServeHTTP(w, r)
			lat[i] = time.Since(start)
			if w.Code != 302 {
				t.Errorf("status %d", w.Code)
			}
		}(i)
	}
	wg.Wait()
	p50, p99, max := percentile(lat, .50), percentile(lat, .99), percentile(lat, 1)
	t.Logf("%d requests at %d/s: p50=%v p99=%v max=%v", total, rps, p50, p99, max)
	if p99 >= 5*time.Millisecond {
		t.Errorf("p99 = %v, target < 5ms", p99)
	}
	if all, _ := h.scanCount(l.ID); all < total*9/10 {
		t.Errorf("only %d of %d scans recorded", all, total)
	}
}

func BenchmarkRedirect(b *testing.B) {
	t := &testing.T{}
	_ = t
	d, _ := db.Open(filepath.Join(b.TempDir(), "b.db"))
	defer d.Close()
	ctx := context.Background()
	db.Migrate(ctx, d, qrtrack.Migrations, "migrations")
	log := slog.New(slog.NewTextHandler(io.Discard, nil))
	w := scans.NewWriter(d, 4096, log)
	w.Start()
	defer w.Close(ctx)
	st := links.NewStore(d)
	_, lo, _ := net.ParseCIDR("127.0.0.1/32")
	a := auth.New(d, []byte("k"))
	srv, err := New(Config{BaseURL: "http://qr.test", TrustedProxies: []*net.IPNet{lo}, RateLimitPerMin: 1 << 30}, Deps{
		DB: d, Links: st, Auth: a, Hasher: scans.NewHasher(d, time.Now), Writer: w, Geo: &geo.Resolver{}, Now: time.Now, Log: log, Assets: qrtrack.Web})
	if err != nil {
		b.Fatal(err)
	}
	l, _ := st.Create(ctx, links.Input{Label: "b", Destination: "https://example.com/", Start: time.Now().Add(-time.Hour), End: time.Now().Add(time.Hour), ExpiryMode: links.RedirectUntracked, QRECC: "M"})
	h := srv.Handler()
	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		r := httptest.NewRequest("GET", "/r/"+l.Code, nil)
		r.RemoteAddr = "127.0.0.1:1"
		r.Header.Set("X-Forwarded-For", fmt.Sprintf("203.0.%d.%d", (i/250)%250, i%250+1))
		h.ServeHTTP(httptest.NewRecorder(), r)
	}
}
