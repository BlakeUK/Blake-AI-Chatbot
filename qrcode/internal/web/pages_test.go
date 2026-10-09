package web

import (
	"context"
	"encoding/json"
	"fmt"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/qr"
	"html"
	"net/http"
	"net/url"
	"regexp"
	"strings"
	"testing"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/geo"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/pages"
)

// ---------- helpers ----------

type pageSpec struct {
	slug, brand, theme string
	extra              url.Values
	rows               [][4]string // title, address, icon, small text
}

// makePage creates a page through the real form, as a person would.
func (h *harness) makePage(c *http.Client, sp pageSpec) (id int64) {
	h.t.Helper()
	if sp.brand == "" {
		sp.brand = "visionplus"
	}
	if sp.theme == "" {
		sp.theme = "midnight"
	}
	if sp.slug == "" {
		sp.slug = "links-" + fmt.Sprint(len(sp.rows))
	}
	if sp.rows == nil {
		sp.rows = [][4]string{{"Website", "https://www.visionplus.co.uk", "auto", ""}, {"Facebook", "https://www.facebook.com/visionplusuk", "auto", ""}, {"Email", "mailto:sales@visionplus.co.uk", "auto", ""}}
	}
	v := url.Values{"csrf": {h.token(c)}, "slug": {sp.slug}, "name": {"Page " + sp.slug}, "brand": {sp.brand}, "theme": {sp.theme},
		"accent_same": {"on"}, "title": {""}, "show_urls": {"on"}, "show_socials": {"on"}}
	for _, r := range sp.rows {
		v.Add("item_id", "")
		v.Add("item_title", r[0])
		v.Add("item_url", r[1])
		v.Add("item_icon", r[2])
		v.Add("item_desc", r[3])
	}
	for k, vs := range sp.extra {
		v[k] = vs
	}
	resp, body := h.do(c, "POST", "/admin/pages", v, nil)
	if resp.StatusCode != http.StatusSeeOther {
		h.t.Fatalf("create page: %d\n%s", resp.StatusCode, firstErrors(body))
	}
	h.db.QueryRow(`SELECT id FROM link_pages WHERE slug = ?`, strings.ToLower(sp.slug)).Scan(&id)
	return id
}

func (h *harness) flushPages() {
	h.t.Helper()
	if err := h.pageWriter.Flush(context.Background()); err != nil {
		h.t.Fatal(err)
	}
}

func (h *harness) pageCounts(id int64) (views, clicks, qrViews int) {
	h.flushPages()
	h.db.QueryRow(`SELECT COALESCE(SUM(kind='view' AND is_bot=0),0), COALESCE(SUM(kind='click' AND is_bot=0),0), COALESCE(SUM(kind='view' AND is_bot=0 AND source='qr'),0) FROM page_events WHERE page_id=?`, id).Scan(&views, &clicks, &qrViews)
	return
}

func visit(h *harness, path, ua, ip string) (*http.Response, string) {
	c := &http.Client{CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	return h.do(c, "GET", path, nil, map[string]string{"User-Agent": ua, "X-Forwarded-For": ip})
}

// ---------- the public page ----------

func TestPublicPageRendersEachBrandAndTheme(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	cases := []struct{ brand, theme, logo string }{
		{"visionplus", "midnight", "visionplus-on-dark.png"}, {"visionplus", "daylight", "visionplus-on-light.png"}, {"visionplus", "bold", "visionplus-on-dark.png"},
		{"blake-uk", "midnight", "blake-uk-on-dark.png"}, {"blake-uk", "daylight", "blake-uk-on-light.png"},
		{"solwise", "bold", "solwise-on-dark.png"}, {"solwise", "daylight", "solwise-on-light.png"},
	}
	for i, tc := range cases {
		slug := fmt.Sprintf("p%d-%s-%s", i, tc.brand, tc.theme)
		id := h.makePage(c, pageSpec{slug: slug, brand: tc.brand, theme: tc.theme})
		resp, page := h.do(h.client(), "GET", "/l/"+slug, nil, nil) // anyone, no sign-in
		if resp.StatusCode != 200 {
			t.Fatalf("%s: %d", slug, resp.StatusCode)
		}
		for _, want := range []string{"/static/img/brands/" + tc.logo, "theme-" + tc.theme, "brand-" + tc.brand, "/static/pages.css", "/l/" + slug + "/theme.css", "Official Links", `class="lp-btn"`, "lp-chev"} {
			if !strings.Contains(page, want) {
				t.Errorf("%s missing %q", slug, want)
			}
		}
		// a public page is self-contained under the strict policy: no inline style, no script
		for _, bad := range []string{" style=", "<script", "<style", "onclick=", "javascript:"} {
			if strings.Contains(page, bad) {
				t.Errorf("%s contains %q, which the Content Security Policy forbids", slug, bad)
			}
		}
		if csp := resp.Header.Get("Content-Security-Policy"); !strings.Contains(csp, "frame-ancestors 'none'") || !strings.Contains(csp, "style-src 'self'") {
			t.Errorf("%s: weak CSP %q", slug, csp)
		}
		if resp.Header.Get("X-Frame-Options") != "DENY" || !strings.Contains(resp.Header.Get("X-Robots-Tag"), "noindex") {
			t.Errorf("%s: framing/robots headers", slug)
		}
		resp, css := h.do(h.client(), "GET", "/l/"+slug+"/theme.css", nil, nil)
		if resp.StatusCode != 200 || !strings.HasPrefix(resp.Header.Get("Content-Type"), "text/css") || !regexp.MustCompile(`--accent:#[0-9a-f]{6};--ink:#[0-9a-f]{6}`).MatchString(css) {
			t.Errorf("%s theme.css: %d %q", slug, resp.StatusCode, css)
		}
		_ = id
	}
	// the colours really are the brands'
	h.makePage(c, pageSpec{slug: "vp-gold", brand: "visionplus", theme: "midnight"})
	if _, css := h.do(h.client(), "GET", "/l/vp-gold/theme.css", nil, nil); !strings.Contains(css, "--accent:#dd9833") {
		t.Errorf("VisionPlus should use its gold: %s", css)
	}
	h.makePage(c, pageSpec{slug: "blake-light", brand: "blake-uk", theme: "daylight"})
	if _, css := h.do(h.client(), "GET", "/l/blake-light/theme.css", nil, nil); !strings.Contains(css, "--accent:#485cc7") {
		t.Errorf("Blake UK should use its blue: %s", css)
	}
	// every static asset the page uses is really served
	for _, p := range []string{"/static/pages.css", "/static/img/brands/blake-uk-on-dark.png", "/static/img/brands/solwise-on-light.png", "/static/img/brands/visionplus-on-light.png"} {
		if r, b := h.do(h.client(), "GET", p, nil, nil); r.StatusCode != 200 || len(b) < 300 {
			t.Errorf("%s: %d (%d bytes)", p, r.StatusCode, len(b))
		}
	}
}

func TestPublicPageContentAndEscaping(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	id := h.makePage(c, pageSpec{slug: "content", extra: url.Values{"title": {`Shop & <b>Save</b>`}, "subtitle": {`Say "hi" <script>x</script>`}},
		rows: [][4]string{
			{"Website", "https://www.visionplus.co.uk", "auto", ""}, {"Facebook", "https://www.facebook.com/visionplusuk", "auto", "Our page"},
			{"Call us", "tel:+44 114 223 5000", "auto", ""}, {"Email", "mailto:sales@visionplus.co.uk", "auto", ""},
			{`<img src=x onerror=alert(1)>`, "https://x.example/?a=1&b=2", "link", ""},
		}})
	_, page := h.do(h.client(), "GET", "/l/content", nil, nil)
	for _, want := range []string{"Shop &amp; &lt;b&gt;Save&lt;/b&gt;", "Say &#34;hi&#34; &lt;script&gt;x&lt;/script&gt;", "&lt;img src=x onerror=alert(1)&gt;"} {
		if !strings.Contains(page, want) {
			t.Errorf("text should be escaped as %q", want)
		}
	}
	if strings.Contains(page, "<script") || strings.Contains(page, "<img src=x") || strings.Contains(page, "<b>Save") {
		t.Error("user text became markup")
	}
	var items []int64
	rows, _ := h.db.Query(`SELECT id FROM link_page_items WHERE page_id=? ORDER BY position`, id)
	for rows.Next() {
		var i int64
		rows.Scan(&i)
		items = append(items, i)
	}
	rows.Close()
	// web buttons go through the tracked address; the real address never appears as a link target
	for _, i := range items[:2] {
		if !strings.Contains(page, fmt.Sprintf(`href="/l/content/go/%d"`, i)) {
			t.Errorf("button %d should link through /go", i)
		}
	}
	if strings.Contains(page, `href="https://www.visionplus.co.uk"`) {
		t.Error("tracked buttons must not link straight to their target")
	}
	// mail and phone open the visitor's own app directly (a phone number is tidied)
	decoded := html.UnescapeString(page) // html/template writes "+" as &#43;, which a browser reads back as "+"
	for _, want := range []string{`href="tel:+441142235000"`, `href="mailto:sales@visionplus.co.uk"`} {
		if !strings.Contains(decoded, want) {
			t.Errorf("missing direct link %s", want)
		}
	}
	// the small text under a title: the address by default, the author's text if given, and nothing when switched off
	for _, want := range []string{"<small>https://www.visionplus.co.uk</small>", "<small>Our page</small>", "<small>+441142235000</small>", "<small>sales@visionplus.co.uk</small>"} {
		if !strings.Contains(decoded, want) {
			t.Errorf("missing small text %q", want)
		}
	}
	// the social row appears for social buttons only
	if n := strings.Count(page, `class="lp-soc"`); n != 1 || !strings.Contains(page, `aria-label="Facebook"`) {
		t.Errorf("social row has %d tiles, want 1 (Facebook)", n)
	}
	h.makePage(c, pageSpec{slug: "quiet", extra: url.Values{"show_urls": nil, "show_socials": nil}})
	_, quiet := h.do(h.client(), "GET", "/l/quiet", nil, nil)
	if strings.Contains(quiet, "<small>") || strings.Contains(quiet, "lp-socials") {
		t.Error("addresses and the social row should be hideable")
	}
	// case-insensitive address, unknown and switched-off pages
	if r, _ := h.do(h.client(), "GET", "/l/CONTENT", nil, nil); r.StatusCode != 200 {
		t.Errorf("addresses are not case sensitive: %d", r.StatusCode)
	}
	if r, b := h.do(h.client(), "GET", "/l/nope", nil, nil); r.StatusCode != 404 || !strings.Contains(b, "Page not found") {
		t.Errorf("unknown page: %d", r.StatusCode)
	}
	h.do(c, "POST", fmt.Sprintf("/admin/pages/%d/toggle", id), url.Values{"csrf": {h.token(c)}}, nil)
	for _, p := range []string{"/l/content", "/l/content/theme.css", fmt.Sprintf("/l/content/go/%d", items[0])} {
		if r, _ := h.do(h.client(), "GET", p, nil, nil); r.StatusCode != 404 {
			t.Errorf("switched-off page %s: %d, want 404", p, r.StatusCode)
		}
	}
}

// ---------- views and clicks ----------

func TestViewsAndClicksAreCountedAndRedirectSafely(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	id := h.makePage(c, pageSpec{slug: "count"})
	other := h.makePage(c, pageSpec{slug: "other", rows: [][4]string{{"Elsewhere", "https://elsewhere.example", "auto", ""}}})
	var web, mail, foreign int64
	h.db.QueryRow(`SELECT id FROM link_page_items WHERE page_id=? AND url LIKE 'https://www.visionplus%'`, id).Scan(&web)
	h.db.QueryRow(`SELECT id FROM link_page_items WHERE page_id=? AND url LIKE 'mailto:%'`, id).Scan(&mail)
	h.db.QueryRow(`SELECT id FROM link_page_items WHERE page_id=?`, other).Scan(&foreign)

	visit(h, "/l/count", iphoneUA, "203.0.113.1")
	visit(h, "/l/count?s=qr", androidUA, "203.0.113.2")
	visit(h, "/l/count?s=qr", androidUA, "203.0.113.2")         // the same person again: a view, but not a new unique one
	visit(h, "/l/count?s=other-junk", windowsUA, "203.0.113.3") // only s=qr marks a QR visit
	visit(h, "/l/count", "WhatsApp/2.23.20 A", "203.0.113.4")   // a link-preview robot
	h.do(&http.Client{CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}, "HEAD", "/l/count", nil, map[string]string{"User-Agent": iphoneUA, "X-Forwarded-For": "203.0.113.5"})
	v, cl, q := h.pageCounts(id)
	if v != 4 || q != 2 || cl != 0 {
		t.Errorf("views=%d (want 4: bots and HEAD excluded) via-qr=%d (want 2) clicks=%d", v, q, cl)
	}

	// a click goes to the stored address and is counted
	r, _ := visit(h, fmt.Sprintf("/l/count/go/%d", web), iphoneUA, "203.0.113.1")
	if r.StatusCode != 302 || r.Header.Get("Location") != "https://www.visionplus.co.uk" {
		t.Errorf("click: %d -> %q", r.StatusCode, r.Header.Get("Location"))
	}
	// the destination can only come from the database: nothing in the request changes it
	r, _ = visit(h, fmt.Sprintf("/l/count/go/%d?url=https://evil.example&to=https://evil.example", web), iphoneUA, "203.0.113.1")
	if r.Header.Get("Location") != "https://www.visionplus.co.uk" {
		t.Errorf("open redirect: %q", r.Header.Get("Location"))
	}
	// a button of another page, a mail button (not tracked), a nonsense id and a missing page all fail safely
	for name, path := range map[string]string{
		"other page's button": fmt.Sprintf("/l/count/go/%d", foreign), "mailto button": fmt.Sprintf("/l/count/go/%d", mail),
		"nonsense id": "/l/count/go/abc", "missing id": "/l/count/go/999999", "missing page": fmt.Sprintf("/l/nosuch/go/%d", web),
	} {
		if r, _ := visit(h, path, iphoneUA, "203.0.113.9"); r.StatusCode != 404 || r.Header.Get("Location") != "" {
			t.Errorf("%s: %d location %q, want a plain 404", name, r.StatusCode, r.Header.Get("Location"))
		}
	}
	visit(h, fmt.Sprintf("/l/count/go/%d", web), "Slackbot-LinkExpanding 1.0", "203.0.113.7")
	if _, cl, _ = h.pageCounts(id); cl != 2 {
		t.Errorf("clicks = %d, want 2 (bots do not count)", cl)
	}

	// the statistics page reports it
	_, det := h.do(c, "GET", fmt.Sprintf("/admin/pages/%d", id), nil, nil)
	for _, want := range []string{"views", "via a QR code", "button clicks", "QR code", "Direct link", "Clicks on each button", "Website"} {
		if !strings.Contains(det, want) {
			t.Errorf("statistics missing %q", want)
		}
	}
	_, list := h.do(c, "GET", "/admin/pages", nil, nil)
	if !strings.Contains(list, "Page count") || !strings.Contains(list, "<td class=\"num\">4</td>") {
		t.Errorf("the list should show the 4 views")
	}
	// a flood from one visitor is limited, and it never blocks the page itself
	for i := 0; i < 40; i++ {
		visit(h, "/l/count", iphoneUA, "203.0.113.200")
	}
	if r, _ := visit(h, "/l/count", iphoneUA, "203.0.113.200"); r.StatusCode != 200 {
		t.Errorf("the page must still be served to a heavy visitor: %d", r.StatusCode)
	}
	if v2, _, _ := h.pageCounts(id); v2 > 4+31 {
		t.Errorf("one visitor recorded %d views; the rate limit should cap them", v2-4)
	}
}

// ---------- the editor ----------

func TestPageEditorValidationAndEditing(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	_, form := h.do(c, "GET", "/admin/pages/new", nil, nil)
	for _, want := range []string{"1. Company", "2. Theme", "3. The page", "4. Buttons", "Live preview", "Midnight", "Daylight", "Bold", "Blake UK", "VisionPlus", "Solwise", "What is this? How do I use it?", `name="item_url"`, "/static/img/brands/solwise-on-light.png", `id="page-preview"`} {
		if !strings.Contains(form, want) {
			t.Errorf("editor missing %q", want)
		}
	}
	if n := strings.Count(form, `data-row`); n < 5 {
		t.Errorf("a new page should offer at least 5 empty rows, has %d", n)
	}
	_, ex := h.do(c, "GET", "/admin/pages/new?brand=visionplus&example=1", nil, nil)
	for _, want := range []string{"https://www.facebook.com/visionplusuk", "https://www.tiktok.com/@visionplusuk", `value="visionplus-links"`, `value="VisionPlus Official Links"`} {
		if !strings.Contains(ex, want) {
			t.Errorf("the VisionPlus starter is missing %q", want)
		}
	}

	post := func(extra url.Values, rows [][3]string) (*http.Response, string) {
		v := url.Values{"csrf": {h.token(c)}, "slug": {"edit-me"}, "name": {"Edit me"}, "brand": {"blake-uk"}, "theme": {"daylight"}, "accent_same": {"on"}}
		for _, r := range rows {
			v.Add("item_id", r[2])
			v.Add("item_title", r[0])
			v.Add("item_url", r[1])
			v.Add("item_icon", "auto")
			v.Add("item_desc", "")
		}
		for k, vs := range extra {
			v[k] = vs
		}
		return h.do(c, "POST", "/admin/pages", v, nil)
	}
	// errors are shown next to the row they belong to, and nothing is saved
	good := [][3]string{{"Site", "https://www.blake-uk.com", ""}}
	for name, tc := range map[string]struct {
		extra url.Values
		rows  [][3]string
		want  string
	}{
		"bad address":       {nil, [][3]string{{"Bad", "javascript:alert(1)", ""}}, "field-error"},
		"missing title":     {nil, [][3]string{{"", "https://a.example", ""}}, "Give the button a title"},
		"no buttons":        {nil, [][3]string{{"", "", ""}, {"", "", ""}}, "Add at least one button"},
		"bad slug":          {url.Values{"slug": {"a b"}}, good, "page address must be 3 to 40"},
		"no name":           {url.Values{"name": {""}}, good, "Give the page a name"},
		"unreadable accent": {url.Values{"accent_same": nil, "accent": {"#0b2a6f"}, "brand": {"visionplus"}, "theme": {"midnight"}}, good, "too close to the"},
		"unknown theme":     {url.Values{"theme": {"neon"}}, good, "Choose a theme"},
		"colour injection":  {url.Values{"accent_same": nil, "accent": {`#fff" onload="x`}}, good, "field-error"},
	} {
		resp, body := post(tc.extra, tc.rows)
		if resp.StatusCode != http.StatusUnprocessableEntity || !strings.Contains(body, tc.want) {
			t.Errorf("%s: %d (wanted %q)\n%s", name, resp.StatusCode, tc.want, firstErrors(body))
		}
		if strings.Contains(body, `onload="x`) {
			t.Errorf("%s: injected markup echoed back", name)
		}
	}
	var n int
	h.db.QueryRow(`SELECT COUNT(*) FROM link_pages`).Scan(&n)
	if n != 0 {
		t.Fatalf("%d pages saved from invalid forms", n)
	}
	// a name with no address uses the name; then the same address is refused
	if resp, body := post(url.Values{"slug": {""}, "name": {"My Cool Page!"}}, good); resp.StatusCode != http.StatusSeeOther {
		t.Fatalf("slug from name: %d %s", resp.StatusCode, firstErrors(body))
	}
	var slug string
	h.db.QueryRow(`SELECT slug FROM link_pages`).Scan(&slug)
	if slug != "my-cool-page" {
		t.Errorf("slug = %q", slug)
	}
	if resp, body := post(url.Values{"slug": {"MY-COOL-PAGE"}}, good); resp.StatusCode != http.StatusUnprocessableEntity || !strings.Contains(body, "already used") {
		t.Errorf("duplicate address: %d", resp.StatusCode)
	}

	// editing keeps a button's identity (and so its clicks), reorders, adds and removes
	id := h.makePage(c, pageSpec{slug: "editing", rows: [][4]string{{"One", "https://one.example", "auto", ""}, {"Two", "https://two.example", "auto", ""}, {"Three", "https://three.example", "auto", ""}}})
	var one, two, three int64
	h.db.QueryRow(`SELECT id FROM link_page_items WHERE page_id=? AND title='One'`, id).Scan(&one)
	h.db.QueryRow(`SELECT id FROM link_page_items WHERE page_id=? AND title='Two'`, id).Scan(&two)
	h.db.QueryRow(`SELECT id FROM link_page_items WHERE page_id=? AND title='Three'`, id).Scan(&three)
	visit(h, fmt.Sprintf("/l/editing/go/%d", two), iphoneUA, "203.0.113.50")
	h.flushPages()
	_, edit := h.do(c, "GET", fmt.Sprintf("/admin/pages/%d/edit", id), nil, nil)
	for _, want := range []string{`value="One"`, `value="https://two.example"`, fmt.Sprintf(`name="item_id" value="%d"`, three), "Save changes"} {
		if !strings.Contains(edit, want) {
			t.Errorf("edit form missing %q", want)
		}
	}
	v := url.Values{"csrf": {h.token(c)}, "slug": {"editing"}, "name": {"Edited"}, "brand": {"solwise"}, "theme": {"bold"}, "accent_same": {"on"}, "title": {"Fresh title"}}
	for _, r := range [][2]string{{fmt.Sprint(two), "Two (now first)"}, {"", "Brand new"}, {fmt.Sprint(one), "One (last)"}} { // Three is dropped
		v.Add("item_id", r[0])
		v.Add("item_title", r[1])
		v.Add("item_url", map[string]string{fmt.Sprint(two): "https://two.example", "": "https://new.example", fmt.Sprint(one): "https://one.example"}[r[0]])
		v.Add("item_icon", "auto")
		v.Add("item_desc", "")
	}
	if resp, body := h.do(c, "POST", fmt.Sprintf("/admin/pages/%d", id), v, nil); resp.StatusCode != http.StatusSeeOther {
		t.Fatalf("update: %d %s", resp.StatusCode, firstErrors(body))
	}
	var titles []string
	rows, _ := h.db.Query(`SELECT id || ':' || title FROM link_page_items WHERE page_id=? ORDER BY position`, id)
	for rows.Next() {
		var s string
		rows.Scan(&s)
		titles = append(titles, s)
	}
	rows.Close()
	if len(titles) != 3 || titles[0] != fmt.Sprintf("%d:Two (now first)", two) || titles[2] != fmt.Sprintf("%d:One (last)", one) || strings.HasPrefix(titles[1], fmt.Sprint(three)+":") {
		t.Errorf("after edit: %v", titles)
	}
	var clicks int
	h.db.QueryRow(`SELECT COUNT(*) FROM page_events WHERE page_id=? AND item_id=? AND kind='click'`, id, two).Scan(&clicks)
	if clicks != 1 {
		t.Error("the click history must survive an edit")
	}
	_, pub := h.do(h.client(), "GET", "/l/editing", nil, nil)
	if !strings.Contains(pub, "Fresh title") || !strings.Contains(pub, "theme-bold") || !strings.Contains(pub, "brand-solwise") || !strings.Contains(pub, "solwise-on-dark.png") {
		t.Error("the public page should show the edit at once")
	}
	// a forged item id from another page is ignored
	var foreign int64
	h.db.QueryRow(`SELECT id FROM link_page_items WHERE page_id=(SELECT id FROM link_pages WHERE slug='my-cool-page')`).Scan(&foreign)
	v2 := url.Values{"csrf": {h.token(c)}, "slug": {"editing"}, "name": {"Edited"}, "brand": {"solwise"}, "theme": {"bold"}, "accent_same": {"on"},
		"item_id": {fmt.Sprint(foreign)}, "item_title": {"Hijack"}, "item_url": {"https://evil.example"}, "item_icon": {"auto"}, "item_desc": {""}}
	h.do(c, "POST", fmt.Sprintf("/admin/pages/%d", id), v2, nil)
	var t0 string
	h.db.QueryRow(`SELECT title FROM link_page_items WHERE id=?`, foreign).Scan(&t0)
	if t0 != "Site" {
		t.Errorf("another page's button was changed to %q", t0)
	}

	// CSRF, sign-in, delete
	for _, p := range []string{"/admin/pages", fmt.Sprintf("/admin/pages/%d", id), fmt.Sprintf("/admin/pages/%d/toggle", id), fmt.Sprintf("/admin/pages/%d/delete", id), fmt.Sprintf("/admin/pages/%d/qr", id)} {
		if r, _ := h.do(c, "POST", p, url.Values{"slug": {"x"}}, nil); r.StatusCode != http.StatusForbidden {
			t.Errorf("POST %s without a CSRF token: %d", p, r.StatusCode)
		}
	}
	for _, p := range []string{"/admin/pages", "/admin/pages/new", fmt.Sprintf("/admin/pages/%d", id), fmt.Sprintf("/admin/pages/%d/edit", id), "/admin/pages/preview", "/admin/pages/preview.css"} {
		if r, _ := h.do(h.client(), "GET", p, nil, nil); r.StatusCode != http.StatusSeeOther {
			t.Errorf("GET %s while signed out: %d", p, r.StatusCode)
		}
	}
	if r, _ := h.do(c, "POST", fmt.Sprintf("/admin/pages/%d/delete", id), url.Values{"csrf": {h.token(c)}}, nil); r.StatusCode != http.StatusSeeOther {
		t.Fatalf("delete: %d", r.StatusCode)
	}
	h.db.QueryRow(`SELECT COUNT(*) FROM link_page_items WHERE page_id=?`, id).Scan(&n)
	if r, _ := h.do(h.client(), "GET", "/l/editing", nil, nil); r.StatusCode != 404 || n != 0 {
		t.Errorf("deleted page: %d, %d buttons left", r.StatusCode, n)
	}
	for _, want := range []string{"page.create", "page.update", "page.delete"} {
		h.db.QueryRow(`SELECT COUNT(*) FROM audit_log WHERE action=?`, want).Scan(&n)
		if n == 0 {
			t.Errorf("audit trail has no %s", want)
		}
	}
}

// ---------- preview ----------

func TestLivePreview(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	q := url.Values{"brand": {"solwise"}, "theme": {"daylight"}, "title": {"Draft heading"}, "show_urls": {"1"}, "show_socials": {"1"}}
	q.Add("it_title", "Typed button")
	q.Add("it_url", "https://typed.example/path")
	q.Add("it_icon", "auto")
	q.Add("it_desc", "")
	q.Add("it_title", "Half finished")
	q.Add("it_url", "javascript:alert(1)") // an unfinished or hostile row is simply not drawn
	q.Add("it_icon", "auto")
	q.Add("it_desc", "")
	resp, body := h.do(c, "GET", "/admin/pages/preview?"+q.Encode(), nil, nil)
	if resp.StatusCode != 200 {
		t.Fatalf("preview: %d", resp.StatusCode)
	}
	for _, want := range []string{"Draft heading", "Typed button", "theme-daylight", "solwise-on-light.png", `href="https://typed.example/path"`, `target="_blank"`, "/admin/pages/preview.css?"} {
		if !strings.Contains(body, want) {
			t.Errorf("preview missing %q", want)
		}
	}
	if strings.Contains(body, "Half finished") || strings.Contains(body, "javascript:") {
		t.Error("a hostile row must not be drawn")
	}
	// it may be framed by this site's own editor, and by nobody else
	if resp.Header.Get("X-Frame-Options") != "SAMEORIGIN" || !strings.Contains(resp.Header.Get("Content-Security-Policy"), "frame-ancestors 'self'") {
		t.Errorf("preview framing headers: %q %q", resp.Header.Get("X-Frame-Options"), resp.Header.Get("Content-Security-Policy"))
	}
	if _, pub := h.do(h.client(), "GET", "/admin/login", nil, nil); true {
		_ = pub
	}
	// bad parameters fall back to sensible defaults rather than failing
	if r, b := h.do(c, "GET", "/admin/pages/preview?brand=zzz&theme=zzz&accent=%22%3E%3Cscript%3E", nil, nil); r.StatusCode != 200 || !strings.Contains(b, "theme-midnight") || strings.Contains(b, "<script") {
		t.Errorf("preview with junk: %d", r.StatusCode)
	}
	if r, css := h.do(c, "GET", "/admin/pages/preview.css?brand=visionplus&theme=midnight&accent=%23ff0000", nil, nil); r.StatusCode != 200 || !strings.Contains(css, "--accent:#ff0000") {
		t.Errorf("preview css: %d %q", r.StatusCode, css)
	}
	if _, css := h.do(c, "GET", "/admin/pages/preview.css?brand=visionplus&theme=midnight&accent=%23050505", nil, nil); !strings.Contains(css, "--accent:#dd9833") {
		t.Errorf("an unreadable accent should preview as the brand colour: %q", css)
	}
	// nothing was counted or saved by previewing
	var n int
	h.db.QueryRow(`SELECT COUNT(*) FROM page_events`).Scan(&n)
	h.flushPages()
	h.db.QueryRow(`SELECT COUNT(*) FROM link_pages`).Scan(&n)
	if n != 0 {
		t.Error("previewing must not save anything")
	}
	// the editor embeds it, starting from the saved values
	_, ed := h.do(c, "GET", "/admin/pages/new?brand=solwise&example=1", nil, nil)
	if !strings.Contains(ed, `src="/admin/pages/preview?`) || !strings.Contains(ed, "brand=solwise") {
		t.Error("the editor's preview frame should start from the form's values")
	}
}

// ---------- a QR code for a page ----------

func TestMakeAQRCodeForAPage(t *testing.T) {
	h := newHarness(t)
	h.srv.geo = geo.Static(map[string]geo.Location{})
	c := h.adminClient()
	for _, brand := range []string{"visionplus", "blake-uk", "solwise"} {
		id := h.makePage(c, pageSpec{slug: "qr-" + brand, brand: brand})
		resp, body := h.do(c, "POST", fmt.Sprintf("/admin/pages/%d/qr", id), url.Values{"csrf": {h.token(c)}}, nil)
		if resp.StatusCode != http.StatusSeeOther || !strings.HasPrefix(resp.Header.Get("Location"), "/admin/links/") {
			t.Fatalf("%s: %d %s", brand, resp.StatusCode, firstErrors(body))
		}
		var linkID int64
		var dest, design, campaign, kind string
		var end string
		h.db.QueryRow(`SELECT id, destination_url, design, campaign, kind, track_end FROM links ORDER BY id DESC LIMIT 1`).Scan(&linkID, &dest, &design, &campaign, &kind, &end)
		if dest != "http://qr.test/l/qr-"+brand+"?s=qr" || kind != "dynamic" || campaign != "Link pages" || !strings.Contains(design, `"frame":"box"`) {
			t.Errorf("%s: stored %s %s %s %s", brand, dest, kind, campaign, design)
		}
		// whatever the brand colour, the design that was chosen must really be scannable
		if _, errs := (qrDesignProbe{design}).check(); errs != nil {
			t.Errorf("%s: default QR design refused: %v", brand, errs)
		}
		_, pg := h.do(c, "GET", resp.Header.Get("Location"), nil, nil)
		if !strings.Contains(pg, "QR for Page qr-"+brand) {
			t.Errorf("%s: the new code page did not load", brand)
		}
		_, svg := h.do(c, "GET", resp.Header.Get("Location")+"/qr.svg", nil, nil)
		if !strings.Contains(svg, "SCAN FOR OUR LINKS") {
			t.Errorf("%s: QR image should carry the call to action", brand)
		}
	}
	// the whole journey: scan the QR code, land on the page, and the view is marked as coming from a QR code
	id := h.makePage(c, pageSpec{slug: "journey"})
	h.do(c, "POST", fmt.Sprintf("/admin/pages/%d/qr", id), url.Values{"csrf": {h.token(c)}}, nil)
	var code string
	h.db.QueryRow(`SELECT code FROM links ORDER BY id DESC LIMIT 1`).Scan(&code)
	r, _ := visit(h, "/r/"+code, iphoneUA, "203.0.113.60")
	if r.StatusCode != 302 || r.Header.Get("Location") != "http://qr.test/l/journey?s=qr" {
		t.Fatalf("scan: %d -> %q", r.StatusCode, r.Header.Get("Location"))
	}
	visit(h, "/l/journey?s=qr", iphoneUA, "203.0.113.60") // what the phone does next
	var journeyLink int64
	h.db.QueryRow(`SELECT id FROM links WHERE code = ?`, code).Scan(&journeyLink)
	h.scanCount(journeyLink) // let the scan writer commit
	if v, _, viaQR := h.pageCounts(id); v != 1 || viaQR != 1 {
		t.Errorf("views=%d via QR=%d, want 1 and 1", v, viaQR)
	}
	_, det := h.do(c, "GET", fmt.Sprintf("/admin/pages/%d", id), nil, nil)
	if !strings.Contains(det, "QR for Page journey") || !strings.Contains(det, "1 scan") {
		t.Errorf("the page's statistics should list its QR code")
	}
	// the tracker still refuses a destination that would loop back into the redirector
	if _, body := h.createRaw(c, "https://qr.test/r/abcdefgh"); !strings.Contains(body, "must not point back") {
		t.Error("loop protection for /r/ must remain")
	}
}

// qrDesignProbe checks a stored design the way the form does.
type qrDesignProbe struct{ json string }

func (p qrDesignProbe) check() (struct{}, error) {
	return struct{}{}, probeDesign(p.json)
}

func (h *harness) createRaw(c *http.Client, dest string) (*http.Response, string) {
	return h.createViaForm(c, url.Values{"label": {"loop"}, "destination": {dest}, "window": {"7d"}, "expiry_mode": {"redirect_untracked"}, "qr_ecc": {"M"}})
}

func TestHelpDescribesLinkPages(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	_, help := h.do(c, "GET", "/admin/help", nil, nil)
	for _, want := range []string{`id="link-pages"`, "Link pages", "Midnight", "Daylight", "Bold", "Blake UK", "VisionPlus", "Solwise", "Make a QR code for this page", "Live preview", "mailto:", "tel:", "Accent colour"} {
		if !strings.Contains(help, want) {
			t.Errorf("Help does not cover %q", want)
		}
	}
	_, nav := h.do(c, "GET", "/admin/", nil, nil)
	if !strings.Contains(nav, `<a href="/admin/pages">Link pages</a>`) {
		t.Error("the menu needs a Link pages tab")
	}
	for _, key := range []string{"pages", "page_form", "page_detail"} {
		info := pageHelp[key]
		if info.What == "" || info.How == "" || !strings.Contains(help, `id="`+info.Anchor+`"`) {
			t.Errorf("page help %q is incomplete or points nowhere", key)
		}
	}
	for path, key := range map[string]string{"/admin/pages": "pages", "/admin/pages/new": "page_form"} {
		_, b := h.do(c, "GET", path, nil, nil)
		if !strings.Contains(b, "What is this? How do I use it?") || !strings.Contains(b, `href="/admin/help#`+pageHelp[key].Anchor+`"`) {
			t.Errorf("%s has no What/How box", path)
		}
	}
	_ = pages.Brands
}

// probeDesign applies the same rules the QR code form applies to a design.
func probeDesign(js string) error {
	var d qr.Design
	if err := json.Unmarshal([]byte(js), &d); err != nil {
		return err
	}
	_, err := d.Normalise()
	return err
}

// A page whose address is the start of another page's address must not claim
// that other page's QR codes (links and links-2).
func TestQRListIsNotConfusedByAddressesThatShareAStart(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	short := h.makePage(c, pageSpec{slug: "links"})
	long := h.makePage(c, pageSpec{slug: "links-2"})
	h.do(c, "POST", fmt.Sprintf("/admin/pages/%d/qr", long), url.Values{"csrf": {h.token(c)}}, nil)
	_, shortPage := h.do(c, "GET", fmt.Sprintf("/admin/pages/%d", short), nil, nil)
	_, longPage := h.do(c, "GET", fmt.Sprintf("/admin/pages/%d", long), nil, nil)
	if strings.Contains(shortPage, "QR for Page links-2") {
		t.Error("the page 'links' is listing the QR code that belongs to 'links-2'")
	}
	if !strings.Contains(longPage, "QR for Page links-2") {
		t.Error("the page 'links-2' should list its own QR code")
	}
	h.do(c, "POST", fmt.Sprintf("/admin/pages/%d/qr", short), url.Values{"csrf": {h.token(c)}}, nil)
	_, shortPage = h.do(c, "GET", fmt.Sprintf("/admin/pages/%d", short), nil, nil)
	if !strings.Contains(shortPage, "QR for Page links<") && !strings.Contains(shortPage, "QR for Page links ") && !strings.Contains(shortPage, "QR for Page links\"") {
		t.Errorf("the page 'links' should list its own QR code now")
	}
}

// A QR code made for a page must keep working when the page's address changes.
func TestChangingAPageAddressKeepsItsQRCodesWorking(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	id := h.makePage(c, pageSpec{slug: "old-address"})
	other := h.makePage(c, pageSpec{slug: "old-address-two"}) // shares a start with the first: must not be touched
	h.do(c, "POST", fmt.Sprintf("/admin/pages/%d/qr", id), url.Values{"csrf": {h.token(c)}}, nil)
	h.do(c, "POST", fmt.Sprintf("/admin/pages/%d/qr", other), url.Values{"csrf": {h.token(c)}}, nil)
	var code, otherCode string
	h.db.QueryRow(`SELECT code FROM links WHERE destination_url = 'http://qr.test/l/old-address?s=qr'`).Scan(&code)
	h.db.QueryRow(`SELECT code FROM links WHERE destination_url = 'http://qr.test/l/old-address-two?s=qr'`).Scan(&otherCode)

	v := url.Values{"csrf": {h.token(c)}, "slug": {"new-address"}, "name": {"Renamed"}, "brand": {"visionplus"}, "theme": {"midnight"}, "accent_same": {"on"},
		"item_id": {""}, "item_title": {"Site"}, "item_url": {"https://www.visionplus.co.uk"}, "item_icon": {"auto"}, "item_desc": {""}}
	if r, body := h.do(c, "POST", fmt.Sprintf("/admin/pages/%d", id), v, nil); r.StatusCode != http.StatusSeeOther {
		t.Fatalf("rename: %d %s", r.StatusCode, firstErrors(body))
	}
	r, _ := visit(h, "/r/"+code, iphoneUA, "203.0.113.70")
	if r.Header.Get("Location") != "http://qr.test/l/new-address?s=qr" {
		t.Errorf("the QR code still points at %q after the page moved", r.Header.Get("Location"))
	}
	if r, _ := visit(h, "/l/new-address?s=qr", iphoneUA, "203.0.113.70"); r.StatusCode != 200 {
		t.Errorf("the new address should serve the page: %d", r.StatusCode)
	}
	if r, _ := visit(h, "/l/old-address", iphoneUA, "203.0.113.70"); r.StatusCode != 404 {
		t.Errorf("the old address should be gone: %d", r.StatusCode)
	}
	if r, _ := visit(h, "/r/"+otherCode, iphoneUA, "203.0.113.71"); r.Header.Get("Location") != "http://qr.test/l/old-address-two?s=qr" {
		t.Errorf("another page's QR code was changed: %q", r.Header.Get("Location"))
	}
	// the code's own edit form shows the new address too
	var lid int64
	h.db.QueryRow(`SELECT id FROM links WHERE code = ?`, code).Scan(&lid)
	_, edit := h.do(c, "GET", fmt.Sprintf("/admin/links/%d/edit", lid), nil, nil)
	if !strings.Contains(edit, "l/new-address?s=qr") || strings.Contains(edit, "l/old-address?s=qr") {
		t.Error("the QR code's edit form should show the new address")
	}
}

// "Pressed a button" is the share of visitors who pressed at least one button, not clicks divided by views:
// someone pressing three buttons must not push it above 100%.
func TestPressedAButtonCountsVisitorsNotPresses(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	id := h.makePage(c, pageSpec{slug: "funnel", rows: [][4]string{{"One", "https://one.example", "auto", ""}, {"Two", "https://two.example", "auto", ""}}})
	var a, b int64
	h.db.QueryRow(`SELECT id FROM link_page_items WHERE page_id=? ORDER BY position LIMIT 1`, id).Scan(&a)
	h.db.QueryRow(`SELECT id FROM link_page_items WHERE page_id=? ORDER BY position LIMIT 1 OFFSET 1`, id).Scan(&b)
	for _, ip := range []string{"203.0.113.21", "203.0.113.22", "203.0.113.23"} {
		visit(h, "/l/funnel?s=qr", iphoneUA, ip) // three people open the page
	}
	for _, press := range []struct {
		item int64
		ip   string
	}{{a, "203.0.113.21"}, {a, "203.0.113.21"}, {b, "203.0.113.21"}, {a, "203.0.113.22"}} { // one presses three times, one once, one never
		visit(h, fmt.Sprintf("/l/funnel/go/%d", press.item), iphoneUA, press.ip)
	}
	visit(h, fmt.Sprintf("/l/funnel/go/%d", a), "Slackbot-LinkExpanding 1.0", "203.0.113.24") // a bot changes nothing
	if v, cl, _ := h.pageCounts(id); v != 3 || cl != 4 {
		t.Fatalf("views=%d clicks=%d, want 3 and 4", v, cl)
	}
	_, det := h.do(c, "GET", fmt.Sprintf("/admin/pages/%d", id), nil, nil)
	if !strings.Contains(det, `<span class="big">67%</span><span class="muted">of visitors pressed a button`) {
		t.Errorf("expected 67%% (2 of 3 visitors pressed a button), page said:\n%s", regexp.MustCompile(`(?s)<div class="stats">.*?</div>\s*</div>`).FindString(det))
	}
	if strings.Contains(det, "133%") || strings.Contains(det, "clicked something") {
		t.Error("the old clicks-divided-by-views figure is back")
	}
	for _, want := range []string{"Each time the page is opened", "Each press of a web button", "One visit can make several clicks, or none", "The share of unique visitors who pressed at least one button"} {
		if !strings.Contains(det, want) {
			t.Errorf("the statistics screen should explain: %q", want)
		}
	}
	// the list says what its columns mean
	_, list := h.do(c, "GET", "/admin/pages", nil, nil)
	if !strings.Contains(list, `title="Times the page was opened"`) || !strings.Contains(list, "One visit can make several clicks") {
		t.Error("the Link pages list should say what Views and Clicks are")
	}
	// Help explains the difference
	_, help := h.do(c, "GET", "/admin/help", nil, nil)
	for _, want := range []string{"Views or clicks: what is the difference?", "100 scans", "85 button clicks", "60% pressed a button", "cannot go above 100%"} {
		if !strings.Contains(help, want) {
			t.Errorf("Help should say %q", want)
		}
	}
}
