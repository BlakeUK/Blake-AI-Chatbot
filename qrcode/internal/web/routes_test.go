package web

import (
	"io"
	"math/rand"
	"net"
	"net/http"
	"net/url"
	"os"
	"regexp"
	"strings"
	"testing"
	"time"
)

type route struct{ method, pattern, guard string }

// routesFromSource reads the real route table, so a route added later is
// covered automatically and cannot quietly skip these checks.
func routesFromSource(t *testing.T) []route {
	t.Helper()
	src, err := os.ReadFile("server.go")
	if err != nil {
		t.Fatal(err)
	}
	var out []route
	for _, m := range regexp.MustCompile(`mux\.HandleFunc\("(GET|POST) (/[^"]*)", s\.(authed|adminOnly)\(`).FindAllStringSubmatch(string(src), -1) {
		out = append(out, route{m[1], m[2], m[3]})
	}
	if len(out) < 40 {
		t.Fatalf("only %d guarded routes found in server.go; the pattern no longer matches how routes are written", len(out))
	}
	return out
}

func concretePath(pattern, id string) string {
	p := strings.NewReplacer("{$}", "", "{id}", id, "{code}", "AbCd1234", "{slug}", "x").Replace(pattern)
	return p
}

func noRedirect() *http.Client {
	return &http.Client{CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
}

func TestEveryGuardedRouteRefusesTheWrongPeople(t *testing.T) {
	h := newHarness(t)
	admin := h.adminClient()
	routes := routesFromSource(t)
	// something real to point the id-based routes at
	h.create(admin, "dynamic", "url", url.Values{"f_url": {"https://www.blake-uk.com/"}})
	h.makePage(admin, pageSpec{slug: "guard"})

	// a member, signed in with a password of their own
	h.do(admin, "POST", "/admin/users", url.Values{"csrf": {h.token(admin)}, "username": {"guardmember"}, "role": {"member"}, "password": {"guard-member-temp-1"}}, nil)
	m := h.loginAs("guardmember", "guard-member-temp-1")
	_, pp := h.do(m, "GET", "/admin/password", nil, nil)
	h.do(m, "POST", "/admin/password", url.Values{"csrf": {h.csrfFrom(pp)}, "current": {"guard-member-temp-1"}, "new": {"guard-member-own-pw-9"}, "confirm": {"guard-member-own-pw-9"}}, nil)

	signedOut := noRedirect()
	n := 0
	for _, r := range routes {
		path := concretePath(r.pattern, "1")
		// 1. nobody signed in: sent to the sign-in page, and nothing is shown
		var resp *http.Response
		var body string
		if r.method == "GET" {
			resp, body = h.do(signedOut, "GET", path, nil, nil)
		} else {
			resp, body = h.do(signedOut, "POST", path, url.Values{"csrf": {"x"}}, nil)
		}
		if resp.StatusCode != http.StatusSeeOther || resp.Header.Get("Location") != "/admin/login" {
			t.Errorf("signed out: %s %s -> %d %q, want a redirect to /admin/login", r.method, path, resp.StatusCode, resp.Header.Get("Location"))
		}
		if len(body) > 400 {
			t.Errorf("signed out: %s %s returned %d bytes of content", r.method, path, len(body))
		}
		// 2. signed in but with no (or a wrong) CSRF token: refused
		if r.method == "POST" && !strings.HasSuffix(path, "/logout") || r.method == "POST" {
			for name, v := range map[string]url.Values{"no token": {"x": {"y"}}, "wrong token": {"csrf": {"not-the-token"}}} {
				if resp, _ := h.do(admin, "POST", path, v, nil); resp.StatusCode != http.StatusForbidden {
					t.Errorf("admin, %s: POST %s -> %d, want 403", name, path, resp.StatusCode)
				}
			}
		}
		// 3. a member may not use anything marked admin-only
		if r.guard == "adminOnly" {
			var resp *http.Response
			if r.method == "GET" {
				resp, _ = h.do(m, "GET", path, nil, nil)
			} else {
				resp, _ = h.do(m, "POST", path, url.Values{"csrf": {h.token(m)}, "username": {"x"}, "role": {"admin"}, "password": {"a-long-enough-pw-1"}}, nil)
			}
			if resp.StatusCode != http.StatusForbidden {
				t.Errorf("member: %s %s -> %d, want 403", r.method, path, resp.StatusCode)
			}
		}
		n++
	}
	// and the opposite: a member can use everything that is not admin-only
	for _, p := range []string{"/admin/", "/admin/pages", "/admin/templates", "/admin/bulk", "/admin/help", "/admin/campaigns", "/admin/links/new"} {
		if resp, _ := h.do(m, "GET", p, nil, nil); resp.StatusCode != 200 {
			t.Errorf("member should be able to open %s, got %d", p, resp.StatusCode)
		}
	}
	t.Logf("%d guarded routes checked", n)
}

var nasty = []string{
	"", " ", "\x00", "a\x00b", "<script>alert(1)</script>", "' OR 1=1 --", "\"; DROP TABLE links; --", "../../../etc/passwd", "..\\..\\x", "-1", "0", "9999999999999999999999",
	"1e999", "NaN", "%00", "%", "%zz", "\u202e\u0000", "\U0001F600\U0001F600", "http://169.254.169.254/latest/meta-data/", "javascript:alert(1)", "data:text/html,<b>",
	"a\r\nX-Injected: yes", "{{.}}", "${jndi:ldap://x}", "https://", "https://" + strings.Repeat("a", 3000) + ".example", strings.Repeat("x", 60000), "#zzzzzz", "#ffffff",
	"dynamic", "static", "url", "wifi", "vcard", "smart_url", "app_stores", "midnight", "visionplus", "on", "true", "2026-02-30T25:61", "mailto:", "tel:",
}

var fieldNames = []string{
	"csrf", "label", "campaign", "kind", "type", "f_url", "f_ssid", "f_password", "f_security", "f_text", "f_first", "f_last", "f_email", "f_lat", "f_lng", "f_start", "f_end", "f_title",
	"f_ios_url", "f_android_url", "f_r1_match", "f_r1_value", "f_r1_url", "window", "start", "end", "expiry_mode", "fallback_url", "max_scans", "link_password", "clear_password",
	"fg", "bg", "pattern", "eye", "eye_color", "eye_same", "frame", "cta", "qr_ecc", "template_name", "template_id", "link_id", "remove_logo", "slug", "name", "brand", "theme", "accent",
	"accent_same", "title", "subtitle", "show_urls", "show_socials", "item_id", "item_title", "item_url", "item_icon", "item_desc", "username", "role", "password", "current", "new",
	"confirm", "campaign", "format", "template", "size", "ecc", "sp", "bots",
}

func garbageForm(rng *rand.Rand, csrf string) url.Values {
	v := url.Values{}
	if rng.Intn(5) != 0 {
		v.Set("csrf", csrf)
	}
	for i, k := 0, 1+rng.Intn(14); i < k; i++ {
		name := fieldNames[rng.Intn(len(fieldNames))]
		for j, c := 0, 1+rng.Intn(3); j < c; j++ {
			v.Add(name, nasty[rng.Intn(len(nasty))])
		}
	}
	return v
}

func TestHostileInputNeverCausesAServerErrorOrCrash(t *testing.T) {
	h := newHarness(t)
	admin := h.adminClient()
	routes := routesFromSource(t)
	h.create(admin, "dynamic", "url", url.Values{"f_url": {"https://www.blake-uk.com/"}})
	h.create(admin, "static", "text", url.Values{"f_text": {"hello"}})
	h.makePage(admin, pageSpec{slug: "hostile"})
	h.srv.links.SaveTemplate(nil2(), "T", `{"fg":"#000000"}`, nil)
	// Disposable accounts for the user-management routes to chew on. A "reset password" aimed at
	// the signed-in admin would (correctly) end that admin's own session and stop the barrage.
	for _, u := range []string{"victimone", "victimtwo"} {
		h.do(admin, "POST", "/admin/users", url.Values{"csrf": {h.token(admin)}, "username": {u}, "role": {"member"}, "password": {"victim-temp-pass-1"}}, nil)
	}
	rng := rand.New(rand.NewSource(20261008))
	userIDs := []string{"2", "3", "999", "-1", "abc", "0"}
	ids := []string{"1", "2", "3", "999", "-1", "abc", "0", "9999999999999999999"}
	sent, bad := 0, 0
	check := func(what string, resp *http.Response) {
		sent++
		if resp.StatusCode >= 500 {
			bad++
			t.Errorf("%s -> %d", what, resp.StatusCode)
		}
		if resp.Header.Get("X-Injected") != "" {
			t.Errorf("%s: a header was injected through user input", what)
		}
		for _, c := range resp.Cookies() {
			if c.Name == "x" || strings.Contains(c.Name, "\n") {
				t.Errorf("%s: a cookie was injected", what)
			}
		}
	}
	for _, r := range routes {
		if r.method == "POST" && strings.HasSuffix(r.pattern, "/logout") {
			continue // would end the session the rest of the test needs
		}
		for i := 0; i < 24; i++ {
			pool := ids
			if strings.HasPrefix(r.pattern, "/admin/users/{id}") {
				pool = userIDs
			}
			path := concretePath(r.pattern, pool[rng.Intn(len(pool))])
			if r.method == "GET" {
				q := url.Values{}
				for j, k := 0, rng.Intn(6); j < k; j++ {
					q.Add(fieldNames[rng.Intn(len(fieldNames))], nasty[rng.Intn(len(nasty))])
				}
				resp, _ := h.do(admin, "GET", path+"?"+q.Encode(), nil, nil)
				check("GET "+path+"?"+q.Encode()[:min(80, len(q.Encode()))], resp)
				continue
			}
			v := garbageForm(rng, h.token(admin))
			var resp *http.Response
			if rng.Intn(3) == 0 { // multipart with a junk "file" too
				resp, _ = h.postMultipart(admin, path, v, []string{"logo", "csv"}[rng.Intn(2)], []byte(nasty[rng.Intn(len(nasty))]))
			} else {
				resp, _ = h.do(admin, "POST", path, v, nil)
			}
			check("POST "+path, resp)
		}
	}
	// the public side: odd codes, slugs, ids and headers
	// the Go client refuses to send control characters in headers; the raw-socket test below sends those
	clean := func(s string) string {
		return strings.Map(func(r rune) rune {
			if r < 0x20 || r == 0x7f {
				return -1
			}
			return r
		}, s)
	}
	hdrs := func() map[string]string {
		return map[string]string{"User-Agent": clean(nasty[rng.Intn(len(nasty))]), "Accept-Language": clean(nasty[rng.Intn(len(nasty))]), "Referer": clean(nasty[rng.Intn(len(nasty))]), "X-Forwarded-For": []string{"", "not-an-ip", "1.2.3.4, 5.6.7.8", "::1", "999.999.1.1"}[rng.Intn(5)]}
	}
	for i := 0; i < 150; i++ {
		seg := url.PathEscape(nasty[rng.Intn(len(nasty))])
		for _, p := range []string{"/r/" + seg, "/l/" + seg, "/l/" + seg + "/theme.css", "/l/hostile/go/" + seg, "/l/" + seg + "/go/1", "/static/" + seg} {
			resp, _ := h.do(noRedirect(), "GET", p, nil, hdrs())
			check("GET "+p[:min(60, len(p))], resp)
		}
		resp, _ := h.do(noRedirect(), "POST", "/r/"+seg, url.Values{"password": {nasty[rng.Intn(len(nasty))]}, "csrf": {nasty[rng.Intn(len(nasty))]}}, hdrs())
		check("POST /r/"+seg[:min(20, len(seg))], resp)
	}
	// the server is still up and its log shows no panic
	if resp, _ := h.do(noRedirect(), "GET", "/healthz", nil, nil); resp.StatusCode != 200 {
		t.Fatalf("server unhealthy after the barrage: %d", resp.StatusCode)
	}
	if l := h.logs.String(); strings.Contains(l, "panic") || strings.Contains(l, "runtime error") {
		t.Errorf("a panic was logged:\n%.600s", l)
	}
	t.Logf("%d hostile requests sent, %d server errors", sent, bad)
}

// Malformed requests sent over a raw socket, the way a hostile client would.
func TestMalformedRawRequestsAreHandledSafely(t *testing.T) {
	h := newHarness(t)
	admin := h.adminClient()
	h.create(admin, "dynamic", "url", url.Values{"f_url": {"https://www.blake-uk.com/"}})
	h.makePage(admin, pageSpec{slug: "raw"})
	addr := strings.TrimPrefix(h.ts.URL, "http://")
	big := strings.Repeat("A", 100000)
	cases := map[string]string{
		"NUL in a header":              "GET /r/AbCd1234 HTTP/1.1\r\nHost: x\r\nUser-Agent: a\x00b\r\n\r\n",
		"control characters in a path": "GET /l/raw\x01\x02 HTTP/1.1\r\nHost: x\r\n\r\n",
		"header far too large":         "GET / HTTP/1.1\r\nHost: x\r\nX-Big: " + big + "\r\n\r\n",
		"URL far too long":             "GET /l/" + big + " HTTP/1.1\r\nHost: x\r\n\r\n",
		"path traversal":               "GET /static/../../etc/passwd HTTP/1.1\r\nHost: x\r\n\r\n",
		"encoded path traversal":       "GET /static/..%2f..%2f..%2fetc%2fpasswd HTTP/1.1\r\nHost: x\r\n\r\n",
		"backslash traversal":          "GET /static/..\\..\\x HTTP/1.1\r\nHost: x\r\n\r\n",
		"unknown method":               "FOO /admin/ HTTP/1.1\r\nHost: x\r\n\r\n",
		"TRACE":                        "TRACE /admin/ HTTP/1.1\r\nHost: x\r\n\r\n",
		"not HTTP at all":              "\x16\x03\x01\x02\x00\x01\x00\x01\xfc\x03\x03",
		"bad version":                  "GET / HTTP/9.9\r\nHost: x\r\n\r\n",
		"conflicting content lengths":  "POST /admin/login HTTP/1.1\r\nHost: x\r\nContent-Length: 4\r\nContent-Length: 40\r\n\r\nabcd",
		"smuggling attempt":            "POST /admin/login HTTP/1.1\r\nHost: x\r\nContent-Length: 4\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\nGET /admin/ HTTP/1.1\r\nHost: x\r\n\r\n",
		"body larger than allowed":     "POST /admin/login HTTP/1.1\r\nHost: x\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: 5000000\r\n\r\n" + strings.Repeat("a", 200000),
		"header injection in the path": "GET /l/raw%0d%0aSet-Cookie:%20x=1 HTTP/1.1\r\nHost: x\r\n\r\n",
		"absolute-form request target": "GET http://evil.example/admin/ HTTP/1.1\r\nHost: x\r\n\r\n",
	}
	for name, raw := range cases {
		conn, err := net.DialTimeout("tcp", addr, 3*time.Second)
		if err != nil {
			t.Fatal(err)
		}
		conn.SetDeadline(time.Now().Add(700 * time.Millisecond)) // a server waiting for more data is also a correct answer
		conn.Write([]byte(raw))
		reply, _ := io.ReadAll(io.LimitReader(conn, 1<<16))
		conn.Close()
		head := string(reply)
		if i := strings.Index(head, "\r\n"); i > 0 {
			head = head[:i]
		}
		if strings.HasPrefix(head, "HTTP/1.1 5") && !strings.HasPrefix(head, "HTTP/1.1 505") { // 505 is the right answer to an invented protocol version
			t.Errorf("%s -> %s", name, head)
		}
		if strings.Contains(string(reply), "root:x:0:0") {
			t.Errorf("%s: leaked a system file", name)
		}
		if strings.Contains(strings.ToLower(string(reply)), "\r\nset-cookie: x=1") {
			t.Errorf("%s: injected a cookie", name)
		}
		if strings.HasPrefix(head, "HTTP/1.1 200") && strings.Contains(name, "traversal") {
			t.Errorf("%s was served: %s", name, head)
		}
	}
	if resp, _ := h.do(noRedirect(), "GET", "/healthz", nil, nil); resp.StatusCode != 200 {
		t.Fatalf("server unhealthy afterwards: %d", resp.StatusCode)
	}
	if l := h.logs.String(); strings.Contains(l, "panic") || strings.Contains(l, "runtime error") {
		t.Errorf("a panic was logged:\n%.600s", l)
	}
}
