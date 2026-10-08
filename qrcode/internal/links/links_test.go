package links

import (
	"context"
	"path/filepath"
	"strings"
	"testing"
	"time"

	qrtrack "github.com/BlakeUK/Blake-AI-Chatbot/qrcode"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/db"
)

func TestGenerateCode(t *testing.T) {
	seen := map[string]bool{}
	counts := map[byte]int{}
	for i := 0; i < 20000; i++ {
		c, err := GenerateCode()
		if err != nil {
			t.Fatal(err)
		}
		if len(c) != CodeLen || !ValidCode(c) {
			t.Fatalf("bad code %q", c)
		}
		if seen[c] {
			t.Fatalf("duplicate code %q after %d", c, i)
		}
		seen[c] = true
		for j := 0; j < len(c); j++ {
			counts[c[j]]++
		}
	}
	// All 62 symbols must occur, and none wildly over/under-represented
	// (expected ~2580 each over 160k draws); catches modulo bias or a broken alphabet.
	if len(counts) != 62 {
		t.Fatalf("only %d distinct characters used", len(counts))
	}
	for ch, n := range counts {
		if n < 2200 || n > 2960 {
			t.Errorf("char %q appeared %d times, expected about 2580", ch, n)
		}
	}
}

func TestValidCode(t *testing.T) {
	for _, bad := range []string{"", "abc", "abcdefgh1", "abcdefg-", "abcdefg ", "../etc/p", "ABCDEFG\x00"} {
		if ValidCode(bad) {
			t.Errorf("ValidCode(%q) = true", bad)
		}
	}
	if !ValidCode("aZ09bY18") {
		t.Error("good code rejected")
	}
}

func TestValidateURL(t *testing.T) {
	good := []string{
		"https://www.blake-uk.com/category/aerials.html",
		"http://example.com",
		"HTTPS://Example.com/Path?q=1&r=2#frag",
		"https://example.com:8443/x",
	}
	for _, g := range good {
		if _, err := ValidateURL(g); err != nil {
			t.Errorf("ValidateURL(%q) rejected: %v", g, err)
		}
	}
	bad := map[string]string{
		"":                                  "empty",
		"   ":                               "blank",
		"javascript:alert(1)":               "javascript",
		"JaVaScRiPt:alert(1)":               "javascript mixed case",
		"data:text/html,<script>x</script>": "data",
		"ftp://example.com/file":            "ftp",
		"file:///etc/passwd":                "file",
		"//example.com/x":                   "scheme-relative",
		"/relative/path":                    "relative",
		"https://":                          "no host",
		"https://user:pass@example.com":     "credentials",
		"https://exa mple.com":              "space",
		"https://example.com/\nx":           "newline",
		"https://example.com/\x00":          "nul",
		"https://example.com/" + strings.Repeat("a", MaxURLLen): "too long",
	}
	for in, why := range bad {
		if _, err := ValidateURL(in); err == nil {
			t.Errorf("ValidateURL accepted %s: %q", why, in)
		}
	}
	// Exactly at the limit is fine.
	atLimit := "https://example.com/" + strings.Repeat("a", MaxURLLen-len("https://example.com/"))
	if _, err := ValidateURL(atLimit); err != nil {
		t.Errorf("URL of exactly %d chars rejected: %v", MaxURLLen, err)
	}
}

func TestInputClean(t *testing.T) {
	now := time.Date(2026, 10, 1, 12, 0, 0, 0, time.UTC)
	ok := Input{Label: " Poster A ", Destination: "https://www.blake-uk.com/", Start: now, End: now.Add(time.Hour), ExpiryMode: RedirectUntracked}
	out, errs := ok.Clean("qr.example.com")
	if len(errs) != 0 || out.Label != "Poster A" || out.QRECC != "M" {
		t.Fatalf("good input: %+v %v", out, errs)
	}
	cases := map[string]func(Input) Input{
		"label":        func(i Input) Input { i.Label = "   "; return i },
		"destination":  func(i Input) Input { i.Destination = "javascript:1"; return i },
		"window":       func(i Input) Input { i.End = i.Start; return i },
		"expiry_mode":  func(i Input) Input { i.ExpiryMode = "nope"; return i },
		"fallback_url": func(i Input) Input { i.ExpiryMode = RedirectFallbackURL; i.FallbackURL = ""; return i },
		"qr_ecc":       func(i Input) Input { i.QRECC = "Z"; return i },
	}
	for field, mut := range cases {
		if _, errs := mut(ok).Clean(""); errs[field] == "" {
			t.Errorf("expected error on %s, got %v", field, errs)
		}
	}
	long := ok
	long.Label = strings.Repeat("é", MaxLabelRunes+1)
	if _, errs := long.Clean(""); errs["label"] == "" {
		t.Error("101-rune label accepted")
	}
	long.Label = strings.Repeat("é", MaxLabelRunes)
	if _, errs := long.Clean(""); errs["label"] != "" {
		t.Error("100-rune label rejected")
	}
	loop := ok
	loop.Destination = "https://QR.example.com/r/abcdefgh"
	if _, errs := loop.Clean("qr.example.com"); errs["destination"] == "" {
		t.Error("self-referencing destination accepted")
	}
}

func TestStatusWindow(t *testing.T) {
	start := time.Date(2026, 10, 1, 12, 0, 0, 0, time.UTC)
	l := &Link{TrackStart: start, TrackEnd: start.Add(24 * time.Hour)}
	cases := []struct {
		at   time.Time
		want Status
	}{
		{start.Add(-time.Second), Scheduled},
		{start, Active}, // start is inclusive
		{start.Add(23*time.Hour + 59*time.Minute), Active},
		{start.Add(24 * time.Hour), Ended}, // end is exclusive
		{start.Add(48 * time.Hour), Ended},
	}
	for _, c := range cases {
		if got := l.StatusAt(c.at); got != c.want {
			t.Errorf("StatusAt(%v) = %s, want %s", c.at, got, c.want)
		}
	}
}

func newStore(t *testing.T) *Store {
	t.Helper()
	d, err := db.Open(filepath.Join(t.TempDir(), "t.db"))
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { d.Close() })
	if err := db.Migrate(context.Background(), d, qrtrack.Migrations, "migrations"); err != nil {
		t.Fatal(err)
	}
	return NewStore(d)
}

func TestStoreCRUDAndCodeCollisionRetry(t *testing.T) {
	ctx := context.Background()
	s := newStore(t)
	start := time.Now().UTC().Truncate(time.Second)
	in := Input{Label: "A", Destination: "https://example.com/a", Start: start, End: start.Add(time.Hour), ExpiryMode: RedirectUntracked, QRECC: "M"}

	calls := 0
	s.GenCode = func() (string, error) {
		calls++
		if calls <= 2 {
			return "AAAAAAAA", nil // first link takes it; second link collides once
		}
		return "BBBBBBBB", nil
	}
	l1, err := s.Create(ctx, in)
	if err != nil || l1.Code != "AAAAAAAA" {
		t.Fatalf("create 1: %v %+v", err, l1)
	}
	calls = 0
	l2, err := s.Create(ctx, in)
	if err != nil || l2.Code != "BBBBBBBB" {
		t.Fatalf("create 2 should have retried past the collision: %v %+v", err, l2)
	}

	got, err := s.ByCode(ctx, "BBBBBBBB")
	if err != nil || got.ID != l2.ID || !got.Enabled {
		t.Fatalf("ByCode: %v %+v", err, got)
	}
	in.Label = "Renamed"
	in.ExpiryMode = ShowExpiredPage
	if err := s.Update(ctx, l2.ID, in); err != nil {
		t.Fatal(err)
	}
	got, _ = s.Get(ctx, l2.ID)
	if got.Label != "Renamed" || got.ExpiryMode != ShowExpiredPage || got.Code != "BBBBBBBB" {
		t.Fatalf("update: %+v", got)
	}
	if err := s.SetEnabled(ctx, l2.ID, false); err != nil {
		t.Fatal(err)
	}
	got, _ = s.Get(ctx, l2.ID)
	if got.Enabled {
		t.Fatal("still enabled")
	}
	list, total, err := s.List(ctx, 1, 1, Filter{})
	if err != nil || total != 2 || len(list) != 1 || list[0].ID != l2.ID {
		t.Fatalf("list: %v total=%d %+v", err, total, list)
	}
	if err := s.Delete(ctx, l1.ID); err != nil {
		t.Fatal(err)
	}
	if _, err := s.Get(ctx, l1.ID); err != ErrNotFound {
		t.Fatalf("want ErrNotFound, got %v", err)
	}
	if err := s.Update(ctx, 9999, in); err != ErrNotFound {
		t.Fatalf("update missing: %v", err)
	}
}

func TestSiteName(t *testing.T) {
	cases := map[string]string{
		"https://www.facebook.com/blakeuk":               "Facebook",
		"https://m.facebook.com/blakeuk":                 "Facebook",
		"https://fb.me/abc":                              "Facebook",
		"https://www.instagram.com/blakeuk/":             "Instagram",
		"https://youtu.be/xyz":                           "YouTube",
		"https://www.youtube.com/watch?v=1":              "YouTube",
		"https://wa.me/447000000000":                     "WhatsApp",
		"https://www.blake-uk.com/category/aerials.html": "Blake UK website",
		"https://blake-uk.com/":                          "Blake UK website",
		"https://www.example.org/page":                   "example.org",
		"https://notfacebook.com/":                       "notfacebook.com", // suffix match must respect the dot
		"https://facebook.com.evil.example/":             "facebook.com.evil.example",
		"not a url":                                      "",
		"":                                               "",
	}
	for in, want := range cases {
		if got := SiteName(in); got != want {
			t.Errorf("SiteName(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestCampaignValidationAndCanonicalSpelling(t *testing.T) {
	now := time.Date(2026, 10, 1, 12, 0, 0, 0, time.UTC)
	ok := Input{Label: "x", Destination: "https://example.com", Start: now, End: now.Add(time.Hour), ExpiryMode: RedirectUntracked, QRECC: "M"}

	for _, c := range []struct {
		in, want string
		bad      bool
	}{
		{"  Exhibition   stand ", "Exhibition stand", false}, // trims and collapses spaces
		{"", "", false}, // optional
		{strings.Repeat("é", MaxCampaignRunes), strings.Repeat("é", MaxCampaignRunes), false},
		{strings.Repeat("é", MaxCampaignRunes+1), "", true},
		{"bad\x00name", "", true},
	} {
		in := ok
		in.Campaign = c.in
		out, errs := in.Clean("")
		if c.bad {
			if errs["campaign"] == "" {
				t.Errorf("campaign %q accepted", c.in)
			}
			continue
		}
		if errs["campaign"] != "" || out.Campaign != c.want {
			t.Errorf("campaign %q -> %q (%v), want %q", c.in, out.Campaign, errs, c.want)
		}
	}

	// "leaflet" typed later must reuse the spelling already in use.
	ctx := context.Background()
	s := newStore(t)
	mk := func(campaign string) *Link {
		in := ok
		in.Start, in.End = time.Now().UTC(), time.Now().UTC().Add(time.Hour)
		in.Campaign = campaign
		l, err := s.Create(ctx, in)
		if err != nil {
			t.Fatal(err)
		}
		return l
	}
	a := mk("Leaflet")
	b := mk("leaflet")
	c := mk("LEAFLET")
	mk("Product box")
	mk("")
	if b.Campaign != "Leaflet" || c.Campaign != "Leaflet" || a.Campaign != "Leaflet" {
		t.Errorf("campaign spellings split: %q %q %q", a.Campaign, b.Campaign, c.Campaign)
	}
	names, _ := s.Campaigns(ctx)
	if len(names) != 2 || names[0] != "Leaflet" || names[1] != "Product box" {
		t.Errorf("Campaigns = %v", names)
	}
	// filter
	got, total, _ := s.List(ctx, 1, 10, Filter{Campaign: "LEAFLET"})
	if total != 3 || len(got) != 3 {
		t.Errorf("filter by campaign: total=%d", total)
	}
	_, none, _ := s.List(ctx, 1, 10, Filter{Uncategorised: true})
	if none != 1 {
		t.Errorf("uncategorised = %d, want 1", none)
	}
	_, all, _ := s.List(ctx, 1, 10, Filter{})
	if all != 5 {
		t.Errorf("unfiltered = %d, want 5", all)
	}
	// editing the campaign through Update reuses the canonical spelling too
	in := ok
	in.Campaign = "product BOX"
	in.Start, in.End = time.Now().UTC(), time.Now().UTC().Add(time.Hour)
	if err := s.Update(ctx, a.ID, in); err != nil {
		t.Fatal(err)
	}
	g, _ := s.Get(ctx, a.ID)
	if g.Campaign != "Product box" {
		t.Errorf("update campaign = %q", g.Campaign)
	}
}

// ---------- kinds, types, rules, logo, limits ----------

func dyn(label string) Input {
	now := time.Now().UTC().Truncate(time.Second)
	return Input{Label: label, Destination: "https://www.blake-uk.com/", Start: now, End: now.Add(time.Hour), ExpiryMode: RedirectUntracked, QRECC: "M"}
}

func TestStaticVersusDynamicValidation(t *testing.T) {
	// A static code needs only content: no web address, no window, no limits.
	st := Input{Label: "Guest Wi-Fi", Kind: KindStatic, QRType: "wifi", Content: "WIFI:T:WPA;S:Guest;P:x;;", MaxScans: 99, PasswordHash: "hash",
		Rules: []Rule{{"os", "iOS", "https://a.example"}}}
	out, errs := st.Clean("")
	if len(errs) != 0 {
		t.Fatalf("static: %v", errs)
	}
	if out.Destination != "" || out.MaxScans != 0 || out.PasswordHash != "" || len(out.Rules) != 0 {
		t.Errorf("a static code must drop tracking-only settings: %+v", out)
	}
	if _, errs := (Input{Label: "x", Kind: KindStatic}).Clean(""); errs["content"] == "" {
		t.Error("static with no content accepted")
	}
	// Dynamic still needs a valid destination and window.
	if _, errs := (Input{Label: "x", Kind: KindDynamic}).Clean(""); errs["destination"] == "" || errs["window"] == "" {
		t.Errorf("dynamic with nothing: %v", errs)
	}
	if _, errs := (Input{Label: "x", Kind: "weird", Content: "c"}).Clean(""); errs["kind"] == "" {
		t.Error("unknown kind accepted")
	}
	// A dynamic document (vCard) has no address, but does have a window.
	now := time.Now()
	doc := Input{Label: "Contact", Kind: KindDynamic, QRType: "vcard", Content: "BEGIN:VCARD", Start: now, End: now.Add(time.Hour), ExpiryMode: RedirectUntracked}
	if _, errs := doc.Clean(""); len(errs) != 0 {
		t.Errorf("dynamic vCard: %v", errs)
	}
	lim := dyn("x")
	lim.MaxScans = -1
	if _, errs := lim.Clean(""); errs["max_scans"] == "" {
		t.Error("negative scan limit accepted")
	}
	lim.MaxScans = 10_000_001
	if _, errs := lim.Clean(""); errs["max_scans"] == "" {
		t.Error("absurd scan limit accepted")
	}
	r := dyn("x")
	r.Rules = []Rule{{"os", "iOS", "javascript:alert(1)"}}
	if _, errs := r.Clean(""); errs["rules"] == "" {
		t.Error("rule with a hostile URL accepted")
	}
}

func TestStoreKindsRulesLogoAndPassword(t *testing.T) {
	ctx := context.Background()
	s := newStore(t)

	in := dyn("App")
	in.QRType, in.Design = "app_stores", `{"pattern":"dots"}`
	in.Rules = []Rule{{"os", "iOS", "https://apps.apple.com/x"}, {"os", "Android", "https://play.google.com/y"}}
	in.Logo, in.PasswordHash, in.MaxScans = []byte("PNGDATA"), "$2a$10$hash", 50
	l, err := s.Create(ctx, in)
	if err != nil {
		t.Fatal(err)
	}
	if l.Kind != KindDynamic || l.QRType != "app_stores" || !l.HasLogo || !l.HasRules || l.MaxScans != 50 || l.PasswordHash == "" || l.Design != `{"pattern":"dots"}` {
		t.Fatalf("created: %+v", l)
	}
	rules, _ := s.Rules(ctx, l.ID)
	if len(rules) != 2 || rules[0].Value != "iOS" || rules[1].Value != "Android" {
		t.Errorf("rules: %+v", rules)
	}
	if logo, _ := s.Logo(ctx, l.ID); string(logo) != "PNGDATA" {
		t.Errorf("logo = %q", logo)
	}

	// Edit: keep the password, replace the rules, remove the logo.
	up := dyn("App 2")
	up.KeepPassword = true
	up.Rules = []Rule{{"country", "GB", "https://www.blake-uk.com/uk"}}
	up.LogoSet = true // nil logo + LogoSet = remove
	if err := s.Update(ctx, l.ID, up); err != nil {
		t.Fatal(err)
	}
	g, _ := s.Get(ctx, l.ID)
	if g.Label != "App 2" || g.PasswordHash != "$2a$10$hash" || g.HasLogo || !g.HasRules || g.MaxScans != 0 {
		t.Errorf("after edit: %+v", g)
	}
	if rules, _ := s.Rules(ctx, l.ID); len(rules) != 1 || rules[0].Match != "country" {
		t.Errorf("rules after edit: %+v", rules)
	}
	// Edit without KeepPassword and an empty hash removes the protection; no rules clears the flag.
	up2 := dyn("App 3")
	if err := s.Update(ctx, l.ID, up2); err != nil {
		t.Fatal(err)
	}
	g, _ = s.Get(ctx, l.ID)
	if g.PasswordHash != "" || g.HasRules {
		t.Errorf("protection/rules not cleared: %+v", g)
	}

	// A static code: content is immutable, the rest is editable.
	st, err := s.Create(ctx, Input{Label: "Wi-Fi", Kind: KindStatic, QRType: "wifi", Content: "WIFI:T:nopass;S:Guest;;", Data: `{"ssid":"Guest"}`, QRECC: "M"})
	if err != nil {
		t.Fatal(err)
	}
	if !st.IsStatic() || st.DestinationURL != "" {
		t.Errorf("static: %+v", st)
	}
	edit := Input{Label: "Renamed", Campaign: "Reception", Content: "WIFI:T:nopass;S:EVIL;;", Destination: "https://evil.example", QRECC: "H", Design: `{"fg":"#003366"}`}
	if err := s.Update(ctx, st.ID, edit); err != nil {
		t.Fatal(err)
	}
	g, _ = s.Get(ctx, st.ID)
	if g.Label != "Renamed" || g.Campaign != "Reception" || g.QRECC != "H" || g.Design != `{"fg":"#003366"}` {
		t.Errorf("static editable fields: %+v", g)
	}
	if g.Content != "WIFI:T:nopass;S:Guest;;" || g.DestinationURL != "" {
		t.Errorf("a static code's content must never change once printed: %+v", g)
	}
}

func TestCreateManyIsAllOrNothing(t *testing.T) {
	ctx := context.Background()
	s := newStore(t)
	good := []Input{dyn("a"), dyn("b"), dyn("c")}
	made, err := s.CreateMany(ctx, good)
	if err != nil || len(made) != 3 {
		t.Fatalf("create many: %v %d", err, len(made))
	}
	codes := map[string]bool{}
	for _, l := range made {
		codes[l.Code] = true
	}
	if len(codes) != 3 {
		t.Error("codes not unique")
	}
	var before int
	s.DB.QueryRow(`SELECT COUNT(*) FROM links`).Scan(&before)
	bad := dyn("bad")
	bad.Kind = "nonsense" // violates the table's CHECK constraint on the third row
	if _, err := s.CreateMany(ctx, []Input{dyn("d"), dyn("e"), bad}); err == nil {
		t.Fatal("expected failure")
	}
	var after int
	s.DB.QueryRow(`SELECT COUNT(*) FROM links`).Scan(&after)
	if after != before {
		t.Errorf("a failed bulk create left %d rows behind", after-before)
	}
}

func TestDesignTemplates(t *testing.T) {
	ctx := context.Background()
	s := newStore(t)
	if err := s.SaveTemplate(ctx, "  Blake   brand ", `{"fg":"#0b2a6f"}`, []byte("LOGO")); err != nil {
		t.Fatal(err)
	}
	if err := s.SaveTemplate(ctx, "blake BRAND", `{"fg":"#000000"}`, nil); err != nil { // same name, any case: replaces
		t.Fatal(err)
	}
	if err := s.SaveTemplate(ctx, "Second", `{}`, nil); err != nil {
		t.Fatal(err)
	}
	list, _ := s.Templates(ctx)
	if len(list) != 2 || list[0].Name != "Blake brand" || list[0].Design != `{"fg":"#000000"}` || list[0].HasLogo {
		t.Fatalf("templates: %+v", list)
	}
	tpl, logo, err := s.TemplateByID(ctx, list[1].ID)
	if err != nil || tpl.Name != "Second" || logo != nil {
		t.Errorf("by id: %+v %v %v", tpl, logo, err)
	}
	if err := s.SaveTemplate(ctx, "   ", "{}", nil); err == nil {
		t.Error("blank name accepted")
	}
	if err := s.SaveTemplate(ctx, strings.Repeat("n", 61), "{}", nil); err == nil {
		t.Error("61-char name accepted")
	}
	s.DeleteTemplate(ctx, list[0].ID)
	if list, _ := s.Templates(ctx); len(list) != 1 {
		t.Errorf("after delete: %d", len(list))
	}
	if _, _, err := s.TemplateByID(ctx, 9999); err != ErrNotFound {
		t.Errorf("missing template: %v", err)
	}
}
