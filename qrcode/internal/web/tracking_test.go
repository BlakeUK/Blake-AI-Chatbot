package web

import (
	"fmt"
	"net/url"
	"sort"
	"strings"
	"testing"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/links"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/pages"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/qrtypes"
)

func columnsOf(t *testing.T, h *harness, table string) []string {
	t.Helper()
	rows, err := h.db.Query(`SELECT name FROM pragma_table_info(?)`, table)
	if err != nil {
		t.Fatal(err)
	}
	defer rows.Close()
	var cols []string
	for rows.Next() {
		var c string
		rows.Scan(&c)
		cols = append(cols, c)
	}
	sort.Strings(cols)
	return cols
}

// The description of what is tracked must match the database exactly. Add a
// column or a table and this fails until it is described for the people whose
// activity it records.
func TestTrackingReferenceMatchesTheDatabase(t *testing.T) {
	h := newHarness(t)
	documented := map[string]bool{}
	for _, td := range trackedTables {
		documented[td.Table] = true
		var want []string
		for _, f := range td.Fields {
			if strings.TrimSpace(f.Meaning) == "" {
				t.Errorf("%s.%s has no description", td.Table, f.Column)
			}
			want = append(want, f.Column)
		}
		sort.Strings(want)
		got := columnsOf(t, h, td.Table)
		if fmt.Sprint(got) != fmt.Sprint(want) {
			t.Errorf("table %s: the database has columns %v but Help describes %v", td.Table, got, want)
		}
	}
	rows, _ := h.db.Query(`SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' OR name = 'sqlite_sequence'`)
	defer rows.Close()
	for rows.Next() {
		var name string
		rows.Scan(&name)
		if _, other := otherTables[name]; !documented[name] && !other {
			t.Errorf("table %q is neither described as tracked data nor listed as holding no visitor information; decide which and document it", name)
		}
	}
	// the visitor-facing tables that matter most must be among the tracked ones
	for _, must := range []string{"scans", "page_events", "login_attempts", "sessions", "audit_log", "daily_salts", "users"} {
		if !documented[must] {
			t.Errorf("%s must be documented as tracked data", must)
		}
	}
}

func TestHelpShowsEverythingThatIsTracked(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	_, help := h.do(c, "GET", "/admin/help", nil, nil)
	for _, f := range trackingFeatures(365, false) {
		for _, part := range []string{f.Feature} {
			if !strings.Contains(help, strings.NewReplacer("'", "&#39;", `"`, "&#34;").Replace(part)) {
				t.Errorf("Help does not show the tracking entry %q", part)
			}
		}
		if f.Recorded == "" || f.NotRecorded == "" || f.Who == "" || f.Kept == "" {
			t.Errorf("tracking entry %q is incomplete: %+v", f.Feature, f)
		}
	}
	for _, td := range trackedTables {
		for _, f := range td.Fields {
			if !strings.Contains(help, "<code>"+f.Column+"</code>") {
				t.Errorf("Help does not list the stored field %s.%s", td.Table, f.Column)
			}
		}
	}
	// facts that must be stated, and the wrong claims that must be gone
	for _, want := range []string{"QR code records <strong>nothing</strong>", "user agent", "full identification text", "scrambled visitor token", "There is also no way to delete a single scan", "The visitor&#39;s internet address is <strong>not stored</strong>", "365 days"} {
		if !strings.Contains(help, want) {
			t.Errorf("Help should say %q", want)
		}
	}
	for _, gone := range []string{"never versions or models", "Never recorded:</strong> names, email addresses, cookies, or the full address"} {
		if strings.Contains(help, gone) {
			t.Errorf("Help still contains the inaccurate claim %q", gone)
		}
	}
	if strings.Contains(help, "switched ON for this system") {
		t.Error("Help claims full-address storage is on when it is off")
	}
}

func TestHelpReflectsFullAddressStorageAndRetention(t *testing.T) {
	h := newHarness(t, func(c *Config) { c.StoreFullIP = true; c.RetentionDays = 90 })
	c := h.adminClient()
	_, help := h.do(c, "GET", "/admin/help", nil, nil)
	if !strings.Contains(help, "Full internet-address storage is switched ON for this system") {
		t.Error("Help must say plainly when full-address storage is on")
	}
	if strings.Contains(help, "The visitor&#39;s internet address is <strong>not stored</strong>") {
		t.Error("Help says addresses are not stored while the setting is on")
	}
	if !strings.Contains(help, "after <strong>90 days</strong>") || !strings.Contains(help, "<td>90 days for scans") {
		t.Error("Help must show the configured retention period")
	}
}

func TestEveryPageSaysWhatIsTracked(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	for key, info := range pageHelp {
		if len(info.Tracked) < 30 {
			t.Errorf("page help %q has no useful 'what is tracked' text: %q", key, info.Tracked)
		}
	}
	l := h.mkLink(h.clk.Now().Add(-1), h.clk.Now().Add(3600e9), "redirect_untracked", "")
	for _, p := range []string{"/admin/", "/admin/links/new", "/admin/links/new?kind=dynamic&type=url", fmt.Sprintf("/admin/links/%d", l.ID), "/admin/campaigns", "/admin/bulk", "/admin/templates", "/admin/templates/new", "/admin/users", "/admin/pages", "/admin/pages/new"} {
		_, body := h.do(c, "GET", p, nil, nil)
		if !strings.Contains(body, "What is tracked.") || !strings.Contains(body, `href="/admin/help#privacy"`) {
			t.Errorf("%s does not say what is tracked", p)
		}
	}
}

// The limits table in Help must be the limits the code enforces.
func TestHelpLimitsTableMatchesTheCode(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	_, help := h.do(c, "GET", "/admin/help", nil, nil)
	row := func(thing, limit string) string { return "<td>" + thing + "</td><td>" + limit }
	for thing, limit := range map[string]string{
		"QR code name":                          fmt.Sprintf("%d characters", links.MaxLabelRunes),
		"Campaign name":                         fmt.Sprintf("%d characters", links.MaxCampaignRunes),
		"Web address":                           fmt.Sprintf("%s characters", commas(links.MaxURLLen)),
		"Static code content":                   fmt.Sprintf("About %d bytes", qrtypes.MaxStaticBytes),
		"Link page buttons":                     fmt.Sprintf("Up to %d per page; title %d characters, small text %d", pages.MaxItems, pages.MaxTitleRunes, pages.MaxDescRunes),
		"Link page name / heading / subheading": fmt.Sprintf("%d / %d / %d characters", pages.MaxNameRunes, pages.MaxHeadingRunes, pages.MaxSubRunes),
		"Bulk (CSV)":                            fmt.Sprintf("File up to 1 MB; up to %s rows as SVG, %d with PNG", commas(bulkMaxRowsSVG), bulkMaxRowsPNG),
		"Visitors":                              "30 requests a minute",
		"Lists":                                 fmt.Sprintf("%d codes per page; %d scans per page", perPage, recentPerPage),
	} {
		if !strings.Contains(help, row(thing, limit)) {
			t.Errorf("Help's limits table is wrong for %q: expected it to start %q", thing, limit)
		}
	}
	if def := (Config{}); def.RateLimitPerMin != 0 {
		t.Skip()
	}
	_ = url.Values{}
}

func commas(n int) string {
	s := fmt.Sprint(n)
	if len(s) > 3 {
		return s[:len(s)-3] + "," + s[len(s)-3:]
	}
	return s
}

// Help promises that visitors are not given tracking cookies. The one cookie a
// visitor can ever receive is the short-lived security token on the password
// page of a protected code, and Help must say so.
func TestVisitorsOnlyEverGetTheSecurityCookie(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	_, open := h.create(c, "dynamic", "url", url.Values{"f_url": {"https://www.blake-uk.com/"}})
	_, locked := h.create(c, "dynamic", "url", url.Values{"f_url": {"https://www.blake-uk.com/trade"}, "link_password": {"trade-only"}})
	h.makePage(c, pageSpec{slug: "nocookies"})
	hdr := map[string]string{"User-Agent": iphoneUA, "X-Forwarded-For": "203.0.113.150"}
	for name, path := range map[string]string{"scanning an ordinary code": "/r/" + open, "a link page": "/l/nocookies", "a link page stylesheet": "/l/nocookies/theme.css", "a missing code": "/r/ZZZZZZZZ", "a missing page": "/l/nosuch"} {
		if resp, _ := h.do(noRedirect(), "GET", path, nil, hdr); len(resp.Cookies()) != 0 {
			t.Errorf("%s sets cookies: %v", name, resp.Cookies())
		}
	}
	resp, _ := h.do(noRedirect(), "GET", "/r/"+locked, nil, hdr)
	if len(resp.Cookies()) != 1 || resp.Cookies()[0].Name != unlockCookie || !resp.Cookies()[0].HttpOnly {
		t.Errorf("the password page should set exactly one HttpOnly security cookie: %v", resp.Cookies())
	}
	_, help := h.do(c, "GET", "/admin/help", nil, nil)
	if strings.Contains(help, "no cookies and nothing") || strings.Contains(help, "Not recorded</dt><dd>Their internet (IP) address is not stored: it is used only to work out the approximate place and the scrambled token, then discarded. Also not recorded: names, email addresses, cookies") {
		t.Error("Help still claims there are no cookies at all")
	}
	if !strings.Contains(help, "security token") {
		t.Error("Help should explain the one cookie a visitor can receive")
	}
}
