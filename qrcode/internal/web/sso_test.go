package web

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/links"
)

// deskStub plays the support desk: it hands out one-time codes and answers the verify address, once per code.
type deskStub struct {
	mu     sync.Mutex
	codes  map[string]ssoClaims
	status int // when non-zero, answer every check with this status
	calls  []url.Values
	hdrs   []http.Header
	ts     *httptest.Server
}

func newDesk(t *testing.T) *deskStub {
	d := &deskStub{codes: map[string]ssoClaims{}}
	d.ts = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		d.mu.Lock()
		defer d.mu.Unlock()
		r.ParseForm()
		d.calls = append(d.calls, r.PostForm)
		d.hdrs = append(d.hdrs, r.Header.Clone())
		if r.Method != http.MethodPost {
			w.WriteHeader(405)
			return
		}
		if d.status != 0 {
			w.WriteHeader(d.status)
			return
		}
		c, ok := d.codes[r.PostFormValue("t")]
		if !ok {
			w.WriteHeader(404)
			w.Write([]byte(`{"ok":false}`))
			return
		}
		delete(d.codes, r.PostFormValue("t")) // one use only
		json.NewEncoder(w).Encode(c)
	}))
	t.Cleanup(d.ts.Close)
	return d
}

func (d *deskStub) issue(code, user, role string) {
	d.mu.Lock()
	d.codes[code] = ssoClaims{OK: true, Username: user, Role: role}
	d.mu.Unlock()
}

const code1 = "AbCdEfGhIjKlMnOpQrStUvWx-_0123456789"

func ssoHarness(t *testing.T, d *deskStub, tweak ...func(*Config)) *harness {
	return newHarness(t, append([]func(*Config){func(c *Config) { c.SSOVerifyURL = d.ts.URL + "/api/qr_sso_verify.php" }}, tweak...)...)
}

func TestSSOSignsInWithoutAPassword(t *testing.T) {
	d := newDesk(t)
	h := ssoHarness(t, d)
	d.issue(code1, "Pat.Jones", "admin")
	c := h.client()
	resp, _ := h.do(c, "GET", "/sso?t="+code1, nil, nil)
	if resp.StatusCode != 303 || resp.Header.Get("Location") != "/admin/" {
		t.Fatalf("want a redirect to /admin/, got %d %q", resp.StatusCode, resp.Header.Get("Location"))
	}
	resp, body := h.do(c, "GET", "/admin/", nil, nil)
	if resp.StatusCode != 200 || !strings.Contains(body, "sso.pat.jones") {
		t.Fatalf("not signed in as the support desk person (%d): %.300s", resp.StatusCode, body)
	}
	if r, _ := h.do(c, "GET", "/admin/users", nil, nil); r.StatusCode != 200 {
		t.Fatalf("an admin from the support desk should reach Users, got %d", r.StatusCode)
	}
	if len(d.calls) != 1 || d.calls[0].Get("t") != code1 || len(d.hdrs[0].Values("Cookie")) != 0 {
		t.Fatalf("the code should be checked once, with the code and no cookies: %v", d.calls)
	}
	// the account exists once, with no usable password, so it can never be reached by the password form
	var n int
	h.db.QueryRow(`SELECT COUNT(*) FROM users WHERE username = 'sso.pat.jones' AND must_change_password = 0 AND role = 'admin'`).Scan(&n)
	if n != 1 {
		t.Fatalf("the account was not created as expected")
	}
	for _, pw := range []string{"", "sso.pat.jones", "password", seedPW} {
		_, page := h.do(h.client(), "GET", "/admin/login", nil, nil)
		r, _ := h.do(h.client(), "POST", "/admin/login", url.Values{"csrf": {h.csrfFrom(page)}, "username": {"sso.pat.jones"}, "password": {pw}}, nil)
		if r.StatusCode == 303 {
			t.Fatalf("password %q must not work for a support desk account", pw)
		}
	}
}

func TestSSORoleFollowsTheSupportDeskAndTheAccountIsReused(t *testing.T) {
	d := newDesk(t)
	h := ssoHarness(t, d)
	signIn := func(role string) *http.Client {
		d.issue(code1, "sam", role)
		c := h.client()
		if r, _ := h.do(c, "GET", "/sso?t="+code1, nil, nil); r.StatusCode != 303 {
			t.Fatalf("sign-in failed: %d", r.StatusCode)
		}
		return c
	}
	c := signIn("member")
	if r, _ := h.do(c, "GET", "/admin/users", nil, nil); r.StatusCode == 200 {
		t.Fatalf("a member must not reach Users")
	}
	if r, _ := h.do(c, "GET", "/admin/", nil, nil); r.StatusCode != 200 {
		t.Fatalf("a member should reach the home page, got %d", r.StatusCode)
	}
	c = signIn("admin")
	if r, _ := h.do(c, "GET", "/admin/users", nil, nil); r.StatusCode != 200 {
		t.Fatalf("after being made an admin, Users should open, got %d", r.StatusCode)
	}
	signIn("member")
	var n int
	h.db.QueryRow(`SELECT COUNT(*) FROM users WHERE username LIKE 'sso.%'`).Scan(&n)
	if n != 1 {
		t.Fatalf("the same person must reuse one account, found %d", n)
	}
	var role string
	h.db.QueryRow(`SELECT role FROM users WHERE username = 'sso.sam'`).Scan(&role)
	if role != "member" {
		t.Fatalf("the role should follow the support desk, got %s", role)
	}
	var acts int
	h.db.QueryRow(`SELECT COUNT(*) FROM audit_log WHERE detail LIKE '%through the support desk%' OR detail LIKE '%follows the support desk%'`).Scan(&acts)
	if acts < 3 {
		t.Fatalf("sign-ins and role changes should be in the activity log, found %d", acts)
	}
}

func TestSSORefusesBadOrUsedCodes(t *testing.T) {
	d := newDesk(t)
	h := ssoHarness(t, d)
	d.issue(code1, "pat", "admin")
	c := h.client()
	if r, _ := h.do(c, "GET", "/sso?t="+code1, nil, nil); r.StatusCode != 303 {
		t.Fatalf("first use should work, got %d", r.StatusCode)
	}
	fresh := h.client()
	r, body := h.do(fresh, "GET", "/sso?t="+code1, nil, nil)
	if r.StatusCode != 403 || !strings.Contains(body, "expired or was already used") || len(r.Header.Values("Set-Cookie")) != 0 {
		t.Fatalf("a used code must be refused with no session: %d %.200s", r.StatusCode, body)
	}
	before := len(d.calls)
	for _, bad := range []string{"", "short", strings.Repeat("a", 121), "has space aaaaaaaaaaaaaaaaaaaa", "semi;colon-aaaaaaaaaaaaaaaaa", "../../etc/passwd-aaaaaaaaaaaaaaa", "new\nline-aaaaaaaaaaaaaaaaaaaaa"} {
		r, _ := h.do(h.client(), "GET", "/sso?t="+url.QueryEscape(bad), nil, nil)
		if r.StatusCode != 400 || len(r.Header.Values("Set-Cookie")) != 0 {
			t.Fatalf("a malformed code %q must be refused without asking the support desk: %d", bad, r.StatusCode)
		}
	}
	if r, _ := h.do(h.client(), "GET", "/sso", nil, nil); r.StatusCode != 400 {
		t.Fatalf("no code: want 400, got %d", r.StatusCode)
	}
	if len(d.calls) != before {
		t.Fatalf("malformed codes must never be sent on to the support desk")
	}
	if r, _ := h.do(h.client(), "GET", "/sso?t=ZZZZZZZZZZZZZZZZZZZZZZZZZZ", nil, nil); r.StatusCode != 403 {
		t.Fatalf("an unknown code: want 403, got %d", r.StatusCode)
	}
	if r, _ := h.do(h.client(), "GET", "/admin/", nil, nil); r.StatusCode != 303 {
		t.Fatalf("with no session, /admin/ must redirect to sign in, got %d", r.StatusCode)
	}
}

func TestSSOHandlesATroubledOrLyingSupportDesk(t *testing.T) {
	d := newDesk(t)
	h := ssoHarness(t, d)
	for _, st := range []int{500, 502, 503, 302, 301, 204} {
		d.status = st
		r, body := h.do(h.client(), "GET", "/sso?t="+code1, nil, nil)
		if r.StatusCode != 502 || len(r.Header.Values("Set-Cookie")) != 0 || !strings.Contains(body, "could not be reached") {
			t.Fatalf("a support desk answering %d must give 502 and no session, got %d", st, r.StatusCode)
		}
	}
	d.status = 0
	for name, c := range map[string]ssoClaims{
		"not ok":       {OK: false, Username: "pat", Role: "admin"},
		"path in name": {OK: true, Username: "../admin", Role: "admin"},
		"empty name":   {OK: true, Username: "", Role: "admin"},
		"spaces":       {OK: true, Username: "pat jones", Role: "admin"},
		"markup":       {OK: true, Username: "<b>pat</b>", Role: "admin"},
		"too long":     {OK: true, Username: strings.Repeat("a", 65), Role: "admin"},
		"leading dot":  {OK: true, Username: ".pat", Role: "admin"},
		"unknown role": {OK: true, Username: "pat", Role: "owner"},
		"empty role":   {OK: true, Username: "pat", Role: ""},
		"capital role": {OK: true, Username: "pat", Role: "Admin"},
	} {
		d.mu.Lock()
		d.codes[code1] = c
		d.mu.Unlock()
		r, _ := h.do(h.client(), "GET", "/sso?t="+code1, nil, nil)
		if r.StatusCode != 403 || len(r.Header.Values("Set-Cookie")) != 0 {
			t.Fatalf("%s: unacceptable details must be refused with no session, got %d", name, r.StatusCode)
		}
	}
	var n int
	h.db.QueryRow(`SELECT COUNT(*) FROM users WHERE username LIKE 'sso.%'`).Scan(&n)
	if n != 0 {
		t.Fatalf("no account may be created from refused details, found %d", n)
	}
	d.ts.Close() // the support desk is down altogether
	if r, _ := h.do(h.client(), "GET", "/sso?t="+code1, nil, nil); r.StatusCode != 502 {
		t.Fatalf("an unreachable support desk must give 502, got %d", r.StatusCode)
	}
}

func TestSSOIsOffUnlessConfigured(t *testing.T) {
	h := newHarness(t)
	if r, _ := h.do(h.client(), "GET", "/sso?t="+code1, nil, nil); r.StatusCode != 404 {
		t.Fatalf("with no SSO_VERIFY_URL the address must not exist, got %d", r.StatusCode)
	}
}

func TestSSOSessionCookieWorksInsideAnotherSitesFrame(t *testing.T) {
	d := newDesk(t)
	h := ssoHarness(t, d, func(c *Config) { c.BaseURL = "https://qr.test" })
	d.issue(code1, "pat", "admin")
	r, _ := h.do(h.client(), "GET", "/sso?t="+code1, nil, nil)
	sc := strings.Join(r.Header.Values("Set-Cookie"), "\n")
	for _, want := range []string{"SameSite=None", "Secure", "HttpOnly", "Path=/"} {
		if !strings.Contains(sc, want) {
			t.Fatalf("the session cookie needs %s to work in a frame: %s", want, sc)
		}
	}
	// the ordinary password sign-in keeps the strictest setting
	c := h.client()
	_, page := h.do(c, "GET", "/admin/login", nil, nil)
	r, _ = h.do(c, "POST", "/admin/login", url.Values{"csrf": {h.csrfFrom(page)}, "username": {"admin"}, "password": {seedPW}}, nil)
	if sc := strings.Join(r.Header.Values("Set-Cookie"), "\n"); !strings.Contains(sc, "SameSite=Strict") || strings.Contains(sc, "SameSite=None") {
		t.Fatalf("the password sign-in must stay SameSite=Strict: %s", sc)
	}
	// without HTTPS a browser would refuse SameSite=None, so it falls back to Lax
	h2 := ssoHarness(t, d)
	d.issue(code1, "pat", "admin")
	r, _ = h2.do(h2.client(), "GET", "/sso?t="+code1, nil, nil)
	if sc := strings.Join(r.Header.Values("Set-Cookie"), "\n"); !strings.Contains(sc, "SameSite=Lax") || strings.Contains(sc, "Secure") {
		t.Fatalf("over plain http the cookie should be Lax and not Secure: %s", sc)
	}
}

func TestSSOStillNeedsTheSecurityTokenOnEveryPost(t *testing.T) {
	d := newDesk(t)
	h := ssoHarness(t, d)
	d.issue(code1, "pat", "admin")
	c := h.client()
	h.do(c, "GET", "/sso?t="+code1, nil, nil)
	r, _ := h.do(c, "POST", "/admin/logout", url.Values{}, nil)
	if r.StatusCode != 403 {
		t.Fatalf("a POST without the security token must be refused even with the session cookie, got %d", r.StatusCode)
	}
	r, _ = h.do(c, "POST", "/admin/logout", url.Values{"csrf": {"wrong-token"}}, nil)
	if r.StatusCode != 403 {
		t.Fatalf("a POST with the wrong token must be refused, got %d", r.StatusCode)
	}
}

func TestSSOLogoutClearsTheCookieInBothForms(t *testing.T) {
	d := newDesk(t)
	h := ssoHarness(t, d, func(c *Config) { c.BaseURL = "https://qr.test" })
	d.issue(code1, "pat", "admin")
	c := h.client()
	r, _ := h.do(c, "GET", "/sso?t="+code1, nil, nil)
	var sess string
	for _, ck := range r.Cookies() {
		if ck.Name == sessionCookie {
			sess = ck.Value
		}
	}
	_, page := h.do(c, "GET", "/admin/", map[string][]string(nil), map[string]string{"Cookie": sessionCookie + "=" + sess})
	tok := h.csrfFrom(page)
	r, _ = h.do(h.client(), "POST", "/admin/logout", url.Values{"csrf": {tok}}, map[string]string{"Cookie": sessionCookie + "=" + sess})
	sc := strings.Join(r.Header.Values("Set-Cookie"), "\n")
	if strings.Count(sc, sessionCookie+"=;") < 2 || !strings.Contains(sc, "SameSite=None") || !strings.Contains(sc, "SameSite=Strict") {
		t.Fatalf("logout must clear the cookie as set by either sign-in: %s", sc)
	}
	if r, _ := h.do(h.client(), "GET", "/admin/", nil, map[string]string{"Cookie": sessionCookie + "=" + sess}); r.StatusCode != 303 {
		t.Fatalf("the session must be gone on the server after logout, got %d", r.StatusCode)
	}
}

func TestFramingIsAllowedOnlyForTheSignedInPagesAndOnlyByTheListedOrigins(t *testing.T) {
	d := newDesk(t)
	origins := []string{"https://blakegroup.uk", "https://tauri.localhost", "tauri://localhost"}
	h := ssoHarness(t, d, func(c *Config) { c.FrameAncestors = origins })
	for _, p := range []string{"/admin/login", "/admin/", "/sso?t=" + code1} {
		r, _ := h.do(h.client(), "GET", p, nil, nil)
		csp := r.Header.Get("Content-Security-Policy")
		if !strings.Contains(csp, "frame-ancestors 'self' https://blakegroup.uk https://tauri.localhost tauri://localhost;") || r.Header.Get("X-Frame-Options") != "" {
			t.Fatalf("%s should be framable by the listed origins only: csp=%q xfo=%q", p, csp, r.Header.Get("X-Frame-Options"))
		}
	}
	l := h.mkLink(h.clk.Now().Add(-time.Hour), h.clk.Now().Add(time.Hour), links.RedirectUntracked, "")
	for _, p := range []string{"/r/" + l.Code, "/", "/healthz", "/nothing-here"} {
		r, _ := h.do(h.client(), "GET", p, nil, nil)
		if !strings.Contains(r.Header.Get("Content-Security-Policy"), "frame-ancestors 'none'") || r.Header.Get("X-Frame-Options") != "DENY" {
			t.Fatalf("%s is public and must never be framable: csp=%q xfo=%q", p, r.Header.Get("Content-Security-Policy"), r.Header.Get("X-Frame-Options"))
		}
	}
	plain := newHarness(t)
	r, _ := plain.do(plain.client(), "GET", "/admin/login", nil, nil)
	if !strings.Contains(r.Header.Get("Content-Security-Policy"), "frame-ancestors 'none'") || r.Header.Get("X-Frame-Options") != "DENY" {
		t.Fatalf("with no origins listed nothing may be framed")
	}
}

func TestSSOSettingsAreCheckedAtStartUp(t *testing.T) {
	good := Config{BaseURL: "https://qr.example.com"}
	for name, c := range map[string]Config{
		"http to the internet":  {SSOVerifyURL: "http://blakegroup.uk/verify"},
		"ftp":                   {SSOVerifyURL: "ftp://blakegroup.uk/verify"},
		"no host":               {SSOVerifyURL: "https:///verify"},
		"password in the url":   {SSOVerifyURL: "https://u:p@blakegroup.uk/verify"},
		"relative":              {SSOVerifyURL: "/verify"},
		"origin with a path":    {FrameAncestors: []string{"https://blakegroup.uk/x"}},
		"origin with semicolon": {FrameAncestors: []string{"https://a.example; script-src *"}},
		"origin with space":     {FrameAncestors: []string{"https://a.example https://b.example"}},
		"wildcard":              {FrameAncestors: []string{"*"}},
		"data":                  {FrameAncestors: []string{"data:"}},
		"scheme only":           {FrameAncestors: []string{"https:"}},
		"quote":                 {FrameAncestors: []string{"'none'"}},
	} {
		c.BaseURL = good.BaseURL
		if validateSSOConfig(c) == nil {
			t.Fatalf("%s must be refused at start-up", name)
		}
	}
	for name, c := range map[string]Config{
		"https":    {SSOVerifyURL: "https://blakegroup.uk/api/qr_sso_verify.php"},
		"loopback": {SSOVerifyURL: "http://127.0.0.1:8081/verify"},
		"origins":  {FrameAncestors: []string{"https://blakegroup.uk", "https://tauri.localhost", "http://tauri.localhost", "tauri://localhost", "https://a.example:8443"}},
		"none":     {},
	} {
		if err := validateSSOConfig(c); err != nil {
			t.Fatalf("%s should be accepted: %v", name, err)
		}
	}
}
