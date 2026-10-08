package web

import (
	"fmt"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/auth"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/pages"
	"image/color"
	"net/http"
	"net/url"
	"regexp"
	"strings"
	"testing"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/qrtypes"
)

func TestBrandingAssetsAndHeader(t *testing.T) {
	h := newHarness(t)
	anon := h.client()
	for path, ct := range map[string]string{
		"/static/img/max-head.png": "image/png", "/static/img/blake-logo-blue.png": "image/png", "/static/img/blake-logo-white.png": "image/png",
		"/static/img/max-banner.webp": "image/webp", "/static/img/favicon.png": "image/png",
	} {
		resp, body := h.do(anon, "GET", path, nil, nil)
		if resp.StatusCode != 200 || len(body) < 500 || !strings.HasPrefix(resp.Header.Get("Content-Type"), ct) {
			t.Errorf("%s: %d %s %d bytes", path, resp.StatusCode, resp.Header.Get("Content-Type"), len(body))
		}
	}
	// The sign-in page (not signed in) carries the branding top right but no menu.
	_, login := h.do(anon, "GET", "/admin/login", nil, nil)
	for _, want := range []string{`class="brandmark"`, "/static/img/max-head.png", "/static/img/blake-logo-blue.png", `media="(prefers-color-scheme: dark)"`, "/static/img/blake-logo-white.png", `alt="Blake UK"`, `rel="icon"`} {
		if !strings.Contains(login, want) {
			t.Errorf("login page missing %q", want)
		}
	}
	if strings.Contains(login, "<nav>") {
		t.Error("the menu must not show to people who are not signed in")
	}
	if strings.Contains(login, `href="/admin/help"`) {
		t.Error("signed-out Max must not link into the signed-in Help page")
	}
	// Signed in: Max opens Help, the logo opens the Blake site, and the menu has Help.
	c := h.adminClient()
	_, page := h.do(c, "GET", "/admin/", nil, nil)
	if !strings.Contains(page, `class="max" href="/admin/help"`) || !strings.Contains(page, `href="https://www.blake-uk.com/"`) || !strings.Contains(page, `rel="noopener noreferrer"`) {
		t.Error("signed-in header: Max should open Help and the logo should open blake-uk.com safely")
	}
	// the brand sits after the sign-out control, i.e. at the right-hand end of the bar
	if strings.Index(page, `class="brandmark"`) < strings.Index(page, "Sign out") {
		t.Error("the branding should be the last (rightmost) item in the header")
	}
	// the public pages visitors see are branded too
	l := h.mkLink(h.clk.Now().Add(-1), h.clk.Now().Add(3600e9), "redirect_untracked", "")
	if r := h.scanHdr("ZZZZZZZZ", map[string]string{"User-Agent": iphoneUA}); r.StatusCode != 404 {
		t.Fatal("setup")
	}
	_, nf := h.do(anon, "GET", "/r/ZZZZZZZZ", nil, nil)
	if !strings.Contains(nf, "/static/img/blake-logo-blue.png") {
		t.Error("the not-found page a customer sees should carry the Blake logo")
	}
	_ = l
}

func TestHelpPageIsCompleteAndAccurate(t *testing.T) {
	h := newHarness(t)
	if r, _ := h.do(h.client(), "GET", "/admin/help", nil, nil); r.StatusCode != http.StatusSeeOther {
		t.Errorf("Help needs sign-in: %d", r.StatusCode)
	}
	c := h.adminClient()
	resp, page := h.do(c, "GET", "/admin/help", nil, nil)
	if resp.StatusCode != 200 {
		t.Fatalf("help: %d", resp.StatusCode)
	}
	// every contents link points at a section that exists, and every section is in the contents
	ids := map[string]bool{}
	for _, m := range regexp.MustCompile(`id="([a-z0-9-]+)"`).FindAllStringSubmatch(page, -1) {
		ids[m[1]] = true
	}
	toc := regexp.MustCompile(`<li><a href="#([a-z0-9-]+)">`).FindAllStringSubmatch(page, -1)
	if len(toc) < 20 {
		t.Errorf("only %d contents entries", len(toc))
	}
	inTOC := map[string]bool{}
	for _, m := range toc {
		inTOC[m[1]] = true
		if !ids[m[1]] {
			t.Errorf("contents links to #%s but no such section", m[1])
		}
	}
	for _, m := range regexp.MustCompile(`<section class="card helpsec" id="([a-z0-9-]+)"`).FindAllStringSubmatch(page, -1) {
		if !inTOC[m[1]] {
			t.Errorf("section #%s is not in the contents", m[1])
		}
	}
	// the topics that were asked for are all there
	for _, want := range []string{"Quick start", "static or dynamic", "How to save and reuse designs", "Save design only", "Designs page", "Use a saved design", "Bulk", "Users and roles", "Troubleshooting", "Glossary", "Max, My AI eXpert"} {
		if !strings.Contains(strings.ToLower(page), strings.ToLower(want)) {
			t.Errorf("Help does not mention %q", want)
		}
	}
	// every menu item is explained somewhere
	for _, item := range []string{"New QR code", "Campaigns", "Bulk", "Designs", "Export scans", "Users", "Help"} {
		if !strings.Contains(page, item) {
			t.Errorf("menu item %q is not covered by Help", item)
		}
	}
	// the type reference matches the catalogue exactly, and carries the guidance
	for _, spec := range qrtypes.All() {
		if !strings.Contains(page, `id="type-`+spec.Type+`"`) || !strings.Contains(page, spec.Label) {
			t.Errorf("Help is missing the type %s", spec.Type)
		}
		if !strings.Contains(page, strings.ReplaceAll(strings.ReplaceAll(spec.HowTo[:40], "'", "&#39;"), `"`, "&#34;")) {
			t.Errorf("Help does not show the how-to for %s", spec.Type)
		}
	}
	// facts quoted in Help are the real limits
	for _, want := range []string{"1,000 rows", "250 rows", "five wrong tries", "12 hours", "at least 12 characters", "365 days", "24 characters", "1 MB"} {
		if !strings.Contains(page, want) {
			t.Errorf("Help should state %q", want)
		}
	}
	// admins see the admin wording; members are told it is admin-only
	if strings.Contains(page, "(admins only)") {
		t.Error("admins should not be told Users is admins-only")
	}
	h.do(c, "POST", "/admin/users", url.Values{"csrf": {h.token(c)}, "username": {"mary"}, "role": {"member"}, "password": {"marys-temp-password-1"}}, nil)
	m := h.loginAs("mary", "marys-temp-password-1")
	_, pp := h.do(m, "GET", "/admin/password", nil, nil)
	h.do(m, "POST", "/admin/password", url.Values{"csrf": {h.csrfFrom(pp)}, "current": {"marys-temp-password-1"}, "new": {"marys-own-password-22"}, "confirm": {"marys-own-password-22"}}, nil)
	if _, mp := h.do(m, "GET", "/admin/help", nil, nil); !strings.Contains(mp, "(admins only)") {
		t.Error("a member's Help should say Users is for admins only")
	}
}

func TestEveryPageExplainsItself(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	_, help := h.do(c, "GET", "/admin/help", nil, nil)
	l := h.mkLink(h.clk.Now().Add(-1), h.clk.Now().Add(3600e9), "redirect_untracked", "")
	pages := map[string]string{
		"/admin/": "links", "/admin/links/new": "link_choose", "/admin/links/new?kind=dynamic&type=url": "link_form",
		"/admin/links/new?kind=static&type=wifi": "link_form", fmt.Sprintf("/admin/links/%d", l.ID): "link_detail", "/admin/campaigns": "campaigns",
		"/admin/bulk": "bulk", "/admin/templates": "templates", "/admin/templates/new": "template_form", "/admin/users": "users",
	}
	for path, key := range pages {
		_, body := h.do(c, "GET", path, nil, nil)
		if !strings.Contains(body, "What is this? How do I use it?") || !strings.Contains(body, "What it is.") || !strings.Contains(body, "How to use it.") {
			t.Errorf("%s has no What/How box", path)
		}
		info := pageHelp[key]
		if info.What == "" || info.How == "" || !strings.Contains(body, `href="/admin/help#`+info.Anchor+`"`) {
			t.Errorf("%s does not link to its Help section", path)
		}
		if !strings.Contains(help, `id="`+info.Anchor+`"`) {
			t.Errorf("%s points to #%s which Help does not have", path, info.Anchor)
		}
	}
	// the forced-change page explains itself too (shown to a brand-new user)
	h.do(c, "POST", "/admin/users", url.Values{"csrf": {h.token(c)}, "username": {"newbie"}, "role": {"member"}, "password": {"newbies-temp-password-1"}}, nil)
	n := h.loginAs("newbie", "newbies-temp-password-1")
	_, pw := h.do(n, "GET", "/admin/password", nil, nil)
	if !strings.Contains(pw, "What is this? How do I use it?") || !strings.Contains(pw, "#account") {
		t.Error("the change-password page should explain itself")
	}
	// each section of the form explains what it is and how to use it, and says how to save a design
	_, form := h.do(c, "GET", "/admin/links/new?kind=dynamic&type=url", nil, nil)
	for _, want := range []string{"Labels that keep your codes organised", "The dates during which scans are counted", "A scan limit stops the code", "The look of the code", "Save this design to use again", "Save design only", "href=\"/admin/help#tracking\"", "href=\"/admin/help#limits-password\""} {
		if !strings.Contains(form, want) {
			t.Errorf("the form is missing the guidance %q", want)
		}
	}
	// the QR code page explains each feature
	_, det := h.do(c, "GET", fmt.Sprintf("/admin/links/%d", l.ID), nil, nil)
	for _, want := range []string{"The picture to print or share", "Download SVG for printing", "How many people scanned", "Breakdowns", "Every scan, newest first"} {
		if !strings.Contains(det, want) {
			t.Errorf("the code page is missing the guidance %q", want)
		}
	}
}

// ---------- saving designs ----------

func TestSavingDesigns(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	design := func(extra url.Values) url.Values {
		v := url.Values{"template_name": {"Blake blue"}, "fg": {"#0b2a6f"}, "bg": {"#ffffff"}, "pattern": {"rounded"}, "eye": {"circle"}, "eye_same": {"on"}, "frame": {"box"}, "cta": {"SCAN ME"}, "qr_ecc": {"M"}, "csrf": {h.token(c)}}
		for k, vs := range extra {
			v[k] = vs
		}
		return v
	}
	count := func() (n int) { h.db.QueryRow(`SELECT COUNT(*) FROM qr_templates`).Scan(&n); return }

	// The page that makes a design exists, and says what it does.
	_, pg := h.do(c, "GET", "/admin/templates/new", nil, nil)
	for _, want := range []string{"New design", "Save design", "Save this design to use again", `name="template_name"`, "Corners match the dots"} {
		if !strings.Contains(pg, want) {
			t.Errorf("design page missing %q", want)
		}
	}
	if strings.Contains(pg, "Save design only") || strings.Contains(pg, `name="label"`) {
		t.Error("the standalone design page has no QR code attached")
	}

	// Save with no QR code created.
	resp, _ := h.do(c, "POST", "/admin/templates", design(nil), nil)
	if resp.StatusCode != http.StatusSeeOther || !strings.HasPrefix(resp.Header.Get("Location"), "/admin/templates?saved=") {
		t.Fatalf("save: %d %s", resp.StatusCode, resp.Header.Get("Location"))
	}
	if count() != 1 {
		t.Fatal("design not saved")
	}
	var links int
	h.db.QueryRow(`SELECT COUNT(*) FROM links`).Scan(&links)
	if links != 0 {
		t.Errorf("saving a design must not create a QR code (%d created)", links)
	}
	_, list := h.do(c, "GET", resp.Header.Get("Location"), nil, nil)
	if !strings.Contains(list, "Saved the design") || !strings.Contains(list, "Blake blue") {
		t.Error("the Designs page should confirm the save and list the design")
	}

	// A name is required, a design that cannot scan is refused, and neither saves anything.
	for name, v := range map[string]url.Values{
		"no name":       design(url.Values{"template_name": {"  "}}),
		"low contrast":  design(url.Values{"template_name": {"Pale"}, "fg": {"#ffeb3b"}}),
		"name too long": design(url.Values{"template_name": {strings.Repeat("n", 61)}}),
		"unknown frame": design(url.Values{"template_name": {"Odd"}, "frame": {"neon"}}),
	} {
		r, body := h.do(c, "POST", "/admin/templates", v, nil)
		if r.StatusCode != http.StatusUnprocessableEntity || !strings.Contains(body, "field-error") {
			t.Errorf("%s: %d", name, r.StatusCode)
		}
	}
	if r, body := h.do(c, "POST", "/admin/templates", design(url.Values{"template_name": {"  "}}), nil); !strings.Contains(body, "Give the design a name") {
		t.Errorf("the missing-name message should be friendly (status %d)", r.StatusCode)
	}
	if count() != 1 {
		t.Errorf("a refused design was saved (count %d)", count())
	}

	// Saving under an existing name replaces it (in any letter case).
	h.do(c, "POST", "/admin/templates", design(url.Values{"template_name": {"BLAKE blue"}, "fg": {"#1b5e20"}, "pattern": {"dots"}}), nil)
	var d string
	h.db.QueryRow(`SELECT design FROM qr_templates`).Scan(&d)
	if count() != 1 || !strings.Contains(d, "#1b5e20") || !strings.Contains(d, "dots") {
		t.Errorf("same name should replace: count %d, %s", count(), d)
	}

	// A logo uploads with the design, is kept when the design is edited without a new upload, and can be removed.
	logo := pngBytes(300, 300, color.RGBA{200, 30, 30, 255})
	if r, body := h.postMultipart(c, "/admin/templates", design(url.Values{"template_name": {"With logo"}}), "logo", logo); r.StatusCode != http.StatusSeeOther {
		t.Fatalf("save with logo: %d %s", r.StatusCode, firstErrors(body))
	}
	var tid int64
	var hasLogo int
	h.db.QueryRow(`SELECT id, logo IS NOT NULL FROM qr_templates WHERE name='With logo'`).Scan(&tid, &hasLogo)
	if hasLogo != 1 {
		t.Fatal("logo not saved with the design")
	}
	h.do(c, "POST", "/admin/templates", design(url.Values{"template_name": {"With logo"}, "template_id": {fmt.Sprint(tid)}, "fg": {"#003366"}}), nil)
	h.db.QueryRow(`SELECT logo IS NOT NULL FROM qr_templates WHERE name='With logo'`).Scan(&hasLogo)
	if hasLogo != 1 {
		t.Error("editing a design without uploading again must keep its logo")
	}
	h.do(c, "POST", "/admin/templates", design(url.Values{"template_name": {"With logo"}, "template_id": {fmt.Sprint(tid)}, "remove_logo": {"on"}}), nil)
	h.db.QueryRow(`SELECT logo IS NOT NULL FROM qr_templates WHERE name='With logo'`).Scan(&hasLogo)
	if hasLogo != 0 {
		t.Error("remove logo should clear it")
	}
	if r, _ := h.postMultipart(c, "/admin/templates", design(url.Values{"template_name": {"Bad logo"}}), "logo", []byte("<script>x</script>")); r.StatusCode != http.StatusUnprocessableEntity {
		t.Errorf("a non-image logo: %d", r.StatusCode)
	}

	// The edit page is pre-filled with the saved design and its name.
	_, edit := h.do(c, "GET", fmt.Sprintf("/admin/templates/new?template=%d", tid), nil, nil)
	if !strings.Contains(edit, "Edit design") || !strings.Contains(edit, `value="With logo"`) || !strings.Contains(edit, "#0b2a6f") {
		t.Error("editing a design should show its name and settings")
	}

	// From a code's form: "Save design only" is offered, posts to the design handler, and reuses that code's logo.
	id, _ := h.create(c, "dynamic", "url", url.Values{"f_url": {"https://www.blake-uk.com/"}})
	if r, body := h.postMultipart(c, fmt.Sprintf("/admin/links/%d", id), url.Values{"csrf": {h.token(c)}, "label": {"x"}, "f_url": {"https://www.blake-uk.com/"}, "window": {"7d"}, "expiry_mode": {"redirect_untracked"}, "qr_ecc": {"M"}}, "logo", logo); r.StatusCode != http.StatusSeeOther {
		t.Fatalf("attach logo to code: %d %s", r.StatusCode, firstErrors(body))
	}
	_, ef := h.do(c, "GET", fmt.Sprintf("/admin/links/%d/edit", id), nil, nil)
	for _, want := range []string{`formaction="/admin/templates"`, "formnovalidate", "Save design only", fmt.Sprintf(`name="link_id" value="%d"`, id), "Design name"} {
		if !strings.Contains(ef, want) {
			t.Errorf("the code's form is missing %q", want)
		}
	}
	h.do(c, "POST", "/admin/templates", design(url.Values{"template_name": {"From code"}, "link_id": {fmt.Sprint(id)}}), nil)
	h.db.QueryRow(`SELECT logo IS NOT NULL FROM qr_templates WHERE name='From code'`).Scan(&hasLogo)
	if hasLogo != 1 {
		t.Error("saving the design only from a code's form should include that code's logo")
	}

	// Using it: the template appears on the Designs page and on new-code forms, and in bulk.
	_, designs := h.do(c, "GET", "/admin/templates", nil, nil)
	for _, want := range []string{"From code", "Use for a new code", "Edit", "Delete"} {
		if !strings.Contains(designs, want) {
			t.Errorf("Designs page missing %q", want)
		}
	}
	_, nf := h.do(c, "GET", "/admin/links/new?kind=dynamic&type=url", nil, nil)
	if !strings.Contains(nf, "Use a saved design") || !strings.Contains(nf, "From code") {
		t.Error("new-code form should offer the saved designs")
	}
	_, bulk := h.do(c, "GET", "/admin/bulk", nil, nil)
	if !strings.Contains(bulk, "From code") {
		t.Error("bulk should offer the saved designs")
	}

	// CSRF and sign-in
	if r, _ := h.do(c, "POST", "/admin/templates", url.Values{"template_name": {"x"}}, nil); r.StatusCode != http.StatusForbidden {
		t.Errorf("save without CSRF token: %d", r.StatusCode)
	}
	if r, _ := h.do(h.client(), "POST", "/admin/templates", design(nil), nil); r.StatusCode != http.StatusSeeOther {
		t.Errorf("save while signed out: %d", r.StatusCode)
	}
	// the audit trail records it, by name only
	var n int
	h.db.QueryRow(`SELECT COUNT(*) FROM audit_log WHERE action='design.save'`).Scan(&n)
	if n < 3 {
		t.Errorf("design saves were not audited (%d)", n)
	}
}

// Every row's controls on the Users page must have a name a screen reader can announce.
func TestUsersPageControlsAreLabelled(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	h.do(c, "POST", "/admin/users", url.Values{"csrf": {h.token(c)}, "username": {"labelled"}, "role": {"member"}, "password": {"labelled-temp-pass-1"}}, nil)
	_, page := h.do(c, "GET", "/admin/users", nil, nil)
	for _, want := range []string{`aria-label="Role for admin"`, `aria-label="Role for labelled"`, `aria-label="New temporary password for labelled"`} {
		if !strings.Contains(page, want) {
			t.Errorf("Users page missing %q", want)
		}
	}
}

// Help quotes specific limits. If a limit in the code changes, this fails until Help says the same.
func TestHelpQuotesTheRealLimits(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	_, help := h.do(c, "GET", "/admin/help", nil, nil)
	words := map[int]string{3: "three", 4: "four", 5: "five", 6: "six", 10: "ten"}
	svc := auth.New(h.db, []byte("k"))
	for what, want := range map[string]string{
		"sign-in failures before lockout": fmt.Sprintf("%s wrong tries within %d minutes", words[auth.MaxFailures], int(auth.FailureWindow.Minutes())),
		"lockout length":                  fmt.Sprintf("locked out for %d minutes", int(auth.LockoutDuration.Minutes())),
		"idle sign-out":                   fmt.Sprintf("after %d hours of inactivity", int(svc.IdleTTL.Hours())),
		"absolute sign-out":               fmt.Sprintf("after %d days", int(svc.AbsTTL.Hours()/24)),
		"codes per page":                  fmt.Sprintf("more than %d codes", perPage),
		"scans per page":                  fmt.Sprintf("%d to a page", recentPerPage),
		"bulk rows as SVG":                "up to 1,000 rows",
		"bulk rows with PNG":              fmt.Sprintf("up to %d rows", bulkMaxRowsPNG),
		"buttons on a link page":          fmt.Sprintf("up to %d buttons", pages.MaxItems),
		"link page heading":               "Heading",
		"breakdown size":                  "ten most common",
		"default retention":               "365 days",
		"call to action length":           "24 characters",
		"minimum password":                "at least 12 characters",
	} {
		if !strings.Contains(help, want) {
			t.Errorf("Help should say %q (%s); the code's limit may have changed", want, what)
		}
	}
	if bulkMaxRowsSVG != 1000 {
		t.Errorf("bulkMaxRowsSVG is %d but Help says 1,000", bulkMaxRowsSVG)
	}
	if auth.MaxFailures > 10 || words[auth.MaxFailures] == "" {
		t.Errorf("add %d to the number words in this test", auth.MaxFailures)
	}
}
