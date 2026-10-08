package pages

import (
	"context"
	"io"
	"log/slog"
	"path/filepath"
	"strings"
	"testing"
	"time"

	qrtrack "github.com/BlakeUK/Blake-AI-Chatbot/qrcode"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/db"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/scans"
)

func testStore(t *testing.T) *Store {
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

func good() Input {
	return Input{Slug: "visionplus", Name: "VisionPlus links", Brand: "visionplus", Theme: "midnight", Title: "Official Links", ShowURLs: true, ShowSocials: true,
		Items: []ItemInput{{Row: 0, Title: "Website", URL: "https://www.visionplus.co.uk"}, {Row: 1, Title: "Facebook", URL: "https://www.facebook.com/visionplusuk"}}}
}

func TestBrandsThemesAndIcons(t *testing.T) {
	if len(Brands) != 3 || len(Themes) < 3 {
		t.Fatalf("brands %d themes %d", len(Brands), len(Themes))
	}
	for _, b := range Brands {
		for _, p := range []string{b.LogoOnLight, b.LogoOnDark} {
			if !strings.HasPrefix(p, "/static/img/brands/") {
				t.Errorf("%s logo path %q", b.ID, p)
			}
		}
		for _, c := range []string{b.Accent, b.AccentOnDark} {
			if _, ok := hexRGB(c); !ok {
				t.Errorf("%s has an invalid colour %q", b.ID, c)
			}
		}
		// every brand's own colours must be readable on the theme they are used with
		for _, th := range Themes {
			if _, err := Resolve(b, th, ""); err != nil {
				t.Errorf("%s on %s: %v", b.ID, th.ID, err)
			}
			if lk, _ := Resolve(b, th, ""); th.Dark && lk.Logo != b.LogoOnDark || !th.Dark && lk.Logo != b.LogoOnLight {
				t.Errorf("%s/%s uses the wrong logo", b.ID, th.ID)
			}
		}
		for _, ex := range b.ExampleLinks {
			if _, err := CleanLinkTarget(ex.URL); err != nil {
				t.Errorf("%s example %q: %v", b.ID, ex.URL, err)
			}
		}
	}
	for _, n := range IconNames {
		if n != "auto" && !strings.Contains(Icon(n), "<svg") {
			t.Errorf("icon %q has no artwork", n)
		}
		if strings.Contains(Icon(n), "style=") || strings.Contains(Icon(n), "<script") {
			t.Errorf("icon %q carries inline style/script, which the CSP forbids", n)
		}
	}
	for raw, want := range map[string]string{
		"https://www.facebook.com/x": "facebook", "https://m.facebook.com/x": "facebook", "https://fb.me/x": "facebook", "https://instagram.com/x": "instagram",
		"https://www.linkedin.com/company/x": "linkedin", "https://www.tiktok.com/@x": "tiktok", "https://youtu.be/x": "youtube", "https://x.com/x": "x",
		"https://wa.me/447": "whatsapp", "mailto:a@b.co": "email", "tel:+441": "phone", "https://www.visionplus.co.uk": "website",
		"https://notfacebook.com/": "website", "https://facebook.com.evil.example/": "website",
	} {
		if got := DetectIcon(raw); got != want {
			t.Errorf("DetectIcon(%q) = %s, want %s", raw, got, want)
		}
	}
	if !IsSocial("tiktok") || IsSocial("website") || IsSocial("email") {
		t.Error("IsSocial")
	}
}

func TestSlugify(t *testing.T) {
	for in, want := range map[string]string{"VisionPlus Official Links": "visionplus-official-links", "  Blake UK!! ": "blake-uk", "Ünï cödé 99": "n-c-d-99", "---": "", "": "", strings.Repeat("a", 60): strings.Repeat("a", 40)} {
		if got := Slugify(in); got != want {
			t.Errorf("Slugify(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestCleanValidation(t *testing.T) {
	out, errs := good().Clean()
	if len(errs) != 0 {
		t.Fatalf("good page: %v", errs)
	}
	if out.Items[0].Icon != "website" || out.Items[1].Icon != "facebook" {
		t.Errorf("auto icons: %+v", out.Items)
	}
	// the slug is case-insensitive and trimmed
	in := good()
	in.Slug = "  VisionPlus-2  "
	if o, errs := in.Clean(); len(errs) != 0 || o.Slug != "visionplus-2" {
		t.Errorf("slug normalisation: %q %v", o.Slug, errs)
	}
	for name, mut := range map[string]func(*Input){
		"slug too short":   func(i *Input) { i.Slug = "ab" },
		"slug dash edge":   func(i *Input) { i.Slug = "-abc" },
		"slug slash":       func(i *Input) { i.Slug = "a/b" },
		"slug space":       func(i *Input) { i.Slug = "a b" },
		"slug too long":    func(i *Input) { i.Slug = strings.Repeat("a", 41) },
		"no name":          func(i *Input) { i.Name = " " },
		"unknown brand":    func(i *Input) { i.Brand = "acme" },
		"unknown theme":    func(i *Input) { i.Theme = "neon" },
		"heading too long": func(i *Input) { i.Title = strings.Repeat("h", 81) },
		"no buttons":       func(i *Input) { i.Items = nil },
		"only blank rows":  func(i *Input) { i.Items = []ItemInput{{Row: 0}, {Row: 1}} },
	} {
		in := good()
		mut(&in)
		if _, errs := in.Clean(); len(errs) == 0 {
			t.Errorf("%s accepted", name)
		}
	}
	// button problems point at their row
	in = good()
	in.Items = []ItemInput{{Row: 0, Title: "OK", URL: "https://a.example"}, {Row: 2, Title: "", URL: "https://b.example"}, {Row: 3, Title: "Bad", URL: "javascript:alert(1)"},
		{Row: 4, Title: strings.Repeat("t", 61), URL: "https://c.example"}, {Row: 5, Title: "Icon", URL: "https://d.example", Icon: "skull"}}
	_, errs = in.Clean()
	for _, k := range []string{"item2_title", "item3_url", "item4_title", "item5_icon"} {
		if errs[k] == "" {
			t.Errorf("expected an error for %s, got %v", k, errs)
		}
	}
	if errs["item0_title"] != "" || errs["item0_url"] != "" {
		t.Errorf("the good row was blamed: %v", errs)
	}
	// blank rows between real ones are simply ignored
	in = good()
	in.Items = []ItemInput{{Row: 0, Title: "A", URL: "https://a.example"}, {Row: 1}, {Row: 2, Title: "B", URL: "https://b.example"}}
	if o, errs := in.Clean(); len(errs) != 0 || len(o.Items) != 2 {
		t.Errorf("blank rows: %v %d", errs, len(o.Items))
	}
	in.Items = make([]ItemInput, MaxItems+1)
	for i := range in.Items {
		in.Items[i] = ItemInput{Row: i, Title: "T", URL: "https://a.example"}
	}
	if _, errs := in.Clean(); errs["items"] == "" {
		t.Error("31 buttons accepted")
	}
}

func TestLinkTargets(t *testing.T) {
	ok := map[string]string{
		"https://www.blake-uk.com/": "https://www.blake-uk.com/", "mailto:Sales@Blake-UK.com": "mailto:Sales@Blake-UK.com", "TEL:+44 114 223 5000": "tel:+441142235000", "tel:0114 223 5000": "tel:01142235000",
	}
	for in, want := range ok {
		if got, err := CleanLinkTarget(in); err != nil || got != want {
			t.Errorf("CleanLinkTarget(%q) = %q, %v", in, got, err)
		}
	}
	for _, bad := range []string{"", "javascript:alert(1)", "data:text/html,x", "ftp://x.example", "//evil.example", "mailto:", "mailto:notanemail", "tel:", "tel:abc", "tel:+44;rm", "https://u:p@x.example", "https://x.example/a\nb", "file:///etc/passwd", "vbscript:x"} {
		if got, err := CleanLinkTarget(bad); err == nil {
			t.Errorf("accepted %q as %q", bad, got)
		}
	}
}

func TestAccentContrastDependsOnTheTheme(t *testing.T) {
	vp, _ := BrandByID("visionplus")
	mid, _ := ThemeByID("midnight")
	day, _ := ThemeByID("daylight")
	bold, _ := ThemeByID("bold")
	cases := []struct {
		accent string
		theme  Theme
		ok     bool
	}{
		{"#ffeb3b", mid, true}, {"#0b2a6f", mid, false}, // navy on a navy page: invisible
		{"#0b2a6f", day, true}, {"#ffeb3b", day, false}, // pale yellow on white: invisible
		{"#dd9833", bold, true}, {"#050505", bold, false},
	}
	for _, c := range cases {
		_, err := Resolve(vp, c.theme, c.accent)
		if (err == nil) != c.ok {
			t.Errorf("%s on %s: err=%v, want ok=%v", c.accent, c.theme.ID, err, c.ok)
		}
	}
	if _, err := Resolve(vp, mid, `#fff" onload="x`); err == nil {
		t.Error("colour injection accepted")
	}
	// the CSS carries only validated hex values
	lk, _ := Resolve(vp, mid, "")
	css := lk.CSS()
	if !strings.Contains(css, "--accent:#dd9833") || strings.ContainsAny(css, `"'<>;{}`[:0]) {
		t.Errorf("css: %s", css)
	}
	// readable text on the accent: dark text on gold, white on navy
	if lk.Ink == "#ffffff" {
		t.Errorf("gold needs dark text, got %s", lk.Ink)
	}
	sw, _ := BrandByID("solwise")
	if l2, _ := Resolve(sw, day, ""); l2.Ink != "#ffffff" {
		t.Errorf("indigo needs white text, got %s", l2.Ink)
	}
	// the page-level validation applies the same rule
	in := good()
	in.Accent = "#0b2a6f"
	if _, errs := in.Clean(); errs["accent"] == "" {
		t.Error("navy accent on Midnight accepted by Clean")
	}
}

func TestStoreCRUDAndItemIdentity(t *testing.T) {
	ctx := context.Background()
	s := testStore(t)
	in, _ := good().Clean()
	p, err := s.Create(ctx, in)
	if err != nil || len(p.Items) != 2 || p.Slug != "visionplus" || !p.Enabled {
		t.Fatalf("create: %v %+v", err, p)
	}
	// slugs are unique ignoring case
	dup := in
	dup.Slug = "VisionPlus"
	if _, err := s.Create(ctx, dup); err != ErrSlugTaken {
		t.Errorf("duplicate slug: %v", err)
	}
	if got, err := s.BySlug(ctx, "VISIONPLUS"); err != nil || got.ID != p.ID {
		t.Errorf("BySlug is case-insensitive: %v", err)
	}
	if _, err := s.BySlug(ctx, "nope"); err != ErrNotFound {
		t.Errorf("missing: %v", err)
	}

	// An edit keeps the ids of buttons it still contains, adds new ones, and drops the rest.
	website, facebook := p.Items[0], p.Items[1]
	edit, _ := Input{Slug: "visionplus", Name: "Renamed", Brand: "visionplus", Theme: "daylight", Title: "New heading", ShowURLs: false, ShowSocials: true,
		Items: []ItemInput{
			{ID: facebook.ID, Row: 0, Title: "Facebook (moved first)", URL: "https://www.facebook.com/visionplusuk"},
			{Row: 1, Title: "Brand new", URL: "https://new.example"},
		}}.Clean()
	if err := s.Update(ctx, p.ID, edit); err != nil {
		t.Fatal(err)
	}
	g, _ := s.Get(ctx, p.ID)
	if g.Name != "Renamed" || g.Theme != "daylight" || g.ShowURLs || len(g.Items) != 2 {
		t.Fatalf("after edit: %+v", g)
	}
	if g.Items[0].ID != facebook.ID || g.Items[0].Title != "Facebook (moved first)" || g.Items[0].Position != 0 {
		t.Errorf("an edited button must keep its id so its click history survives: %+v", g.Items[0])
	}
	if g.Items[1].ID == website.ID || g.Items[1].ID == facebook.ID {
		t.Error("a new button must get a new id")
	}
	if _, err := s.ItemOf(ctx, p.ID, website.ID); err != ErrNotFound {
		t.Error("a removed button should be gone")
	}

	// Another page's button id cannot be hijacked through a crafted form.
	in2, _ := Input{Slug: "other", Name: "Other", Brand: "blake-uk", Theme: "midnight", Items: []ItemInput{{Row: 0, Title: "Mine", URL: "https://mine.example"}}}.Clean()
	other, _ := s.Create(ctx, in2)
	steal, _ := Input{Slug: "other", Name: "Other", Brand: "blake-uk", Theme: "midnight", Items: []ItemInput{{ID: facebook.ID, Row: 0, Title: "Stolen", URL: "https://evil.example"}}}.Clean()
	s.Update(ctx, other.ID, steal)
	fb, _ := s.ItemOf(ctx, p.ID, facebook.ID)
	if fb.Title != "Facebook (moved first)" || fb.URL != "https://www.facebook.com/visionplusuk" {
		t.Errorf("another page's button was overwritten: %+v", fb)
	}
	// renaming onto an existing slug is refused
	clash, _ := Input{Slug: "visionplus", Name: "Other", Brand: "blake-uk", Theme: "midnight", Items: []ItemInput{{Row: 0, Title: "x", URL: "https://x.example"}}}.Clean()
	if err := s.Update(ctx, other.ID, clash); err != ErrSlugTaken {
		t.Errorf("slug clash on update: %v", err)
	}

	list, _ := s.List(ctx)
	if len(list) != 2 || list[0].ID != other.ID {
		t.Errorf("list newest first: %+v", list)
	}
	s.SetEnabled(ctx, p.ID, false)
	if g, _ := s.Get(ctx, p.ID); g.Enabled {
		t.Error("disable")
	}
	if err := s.Delete(ctx, p.ID); err != nil {
		t.Fatal(err)
	}
	var n int
	s.DB.QueryRow(`SELECT COUNT(*) FROM link_page_items WHERE page_id = ?`, p.ID).Scan(&n)
	if _, err := s.Get(ctx, p.ID); err != ErrNotFound || n != 0 {
		t.Errorf("delete should cascade: %v %d", err, n)
	}
	if ex := Example("visionplus"); len(ex) != 5 || ex[1].Title != "Facebook" {
		t.Errorf("example: %+v", ex)
	}
	if Example("nope") != nil {
		t.Error("unknown brand example")
	}
}

func TestEventsAndStatistics(t *testing.T) {
	ctx := context.Background()
	s := testStore(t)
	in, _ := good().Clean()
	p, _ := s.Create(ctx, in)
	w := NewWriter(s.DB, 64, slog.New(slog.NewTextHandler(io.Discard, nil)))
	w.Start()
	defer w.Close(ctx)
	now := time.Date(2026, 10, 8, 12, 0, 0, 0, time.UTC)
	ev := func(kind, source, hash string, item int64, bot bool, at time.Time, country string) Event {
		return Event{PageID: p.ID, ItemID: item, At: at, Kind: kind, Source: source, IPHash: hash, CountryName: country, DeviceClass: "mobile", IsBot: bot}
	}
	for _, e := range []Event{
		ev("view", "qr", "A", 0, false, now, "United Kingdom"),
		ev("view", "qr", "A", 0, false, now.Add(time.Hour), "United Kingdom"),        // same person within 24h: not unique
		ev("view", "direct", "B", 0, false, now.Add(time.Hour), "France"),            // new person
		ev("view", "direct", "A", 0, false, now.Add(26*time.Hour), "United Kingdom"), // more than 24h later: unique again
		ev("view", "direct", "BOT", 0, true, now, ""),
		ev("click", "qr", "A", p.Items[0].ID, false, now, "United Kingdom"),
		ev("click", "qr", "A", p.Items[0].ID, false, now.Add(time.Minute), "United Kingdom"),
		ev("click", "direct", "B", p.Items[1].ID, false, now.Add(2*time.Minute), "France"),
		ev("click", "direct", "BOT", p.Items[1].ID, true, now, ""),
	} {
		if !w.Submit(e) {
			t.Fatal("submit refused")
		}
	}
	if err := w.Flush(ctx); err != nil {
		t.Fatal(err)
	}
	tot, err := Totalled(ctx, s.DB, p.ID)
	if err != nil {
		t.Fatal(err)
	}
	if tot.Views != 4 || tot.Unique != 3 || tot.ViaQR != 2 || tot.Direct != 2 || tot.Clicks != 3 || tot.Bots != 2 {
		t.Errorf("totals = %+v, want views 4 unique 3 qr 2 direct 2 clicks 3 bots 2", tot)
	}
	if !tot.LastView.Equal(now.Add(26 * time.Hour)) {
		t.Errorf("last view %v", tot.LastView)
	}
	per, _ := ItemClicks(ctx, s.DB, p.ID)
	if per[p.Items[0].ID].Clicks != 2 || per[p.Items[1].ID].Clicks != 1 {
		t.Errorf("per-button clicks (bots excluded): %+v", per)
	}
	all, _ := PageTotals(ctx, s.DB)
	if all[p.ID].Views != 4 || all[p.ID].Clicks != 3 {
		t.Errorf("list totals: %+v", all[p.ID])
	}
	hours, _ := ViewHours(ctx, s.DB, p.ID, now.Add(-time.Hour), now.Add(48*time.Hour))
	london, _ := time.LoadLocation("Europe/London")
	var sum int64
	for _, b := range scans.Regroup(hours, now.Add(-time.Hour), now.Add(48*time.Hour), false, london) {
		sum += b.Count
	}
	if sum != 4 {
		t.Errorf("chart total %d, want 4", sum)
	}
	bd, _ := ViewBreakdown(ctx, s.DB, p.ID, "source", 5)
	if len(bd) != 2 || bd[0].Label != "QR code" && bd[0].Label != "Direct link" {
		t.Errorf("source breakdown: %+v", bd)
	}
	if c, _ := ViewBreakdown(ctx, s.DB, p.ID, "country", 5); len(c) == 0 || c[0].Label != "United Kingdom" || c[0].N != 3 {
		t.Errorf("country breakdown: %+v", c)
	}
	if _, err := ViewBreakdown(ctx, s.DB, p.ID, "x; DROP TABLE page_events", 5); err == nil {
		t.Error("non-whitelisted column accepted")
	}
	// non-blocking when full; purge
	full := NewWriter(s.DB, 1, slog.New(slog.NewTextHandler(io.Discard, nil)))
	full.Submit(ev("view", "qr", "z", 0, false, now, ""))
	if full.Submit(ev("view", "qr", "z", 0, false, now, "")) || full.Dropped() != 1 {
		t.Error("a full queue must drop, not block")
	}
	n, _ := Purge(ctx, s.DB, now.Add(time.Minute))
	left, _ := Totalled(ctx, s.DB, p.ID)
	if n != 4 || left.Views != 3 { // the four events before the cutoff go; the three later views stay
		t.Errorf("purge removed %d, %d views left", n, left.Views)
	}
}

func TestBotViewDoesNotMakeAHumanLookLikeARepeatVisitor(t *testing.T) {
	ctx := context.Background()
	s := testStore(t)
	in, _ := good().Clean()
	p, _ := s.Create(ctx, in)
	w := NewWriter(s.DB, 64, slog.New(slog.NewTextHandler(io.Discard, nil)))
	w.Start()
	defer w.Close(ctx)
	now := time.Now().UTC()
	w.Submit(Event{PageID: p.ID, At: now, Kind: "view", Source: "direct", IPHash: "same", DeviceClass: "bot", IsBot: true})
	w.Submit(Event{PageID: p.ID, At: now.Add(time.Second), Kind: "view", Source: "qr", IPHash: "same", DeviceClass: "mobile"})
	w.Submit(Event{PageID: p.ID, At: now.Add(2 * time.Second), Kind: "view", Source: "qr", IPHash: "same", DeviceClass: "mobile"})
	w.Flush(ctx)
	tot, _ := Totalled(ctx, s.DB, p.ID)
	if tot.Views != 2 || tot.Unique != 1 {
		t.Errorf("views=%d unique=%d, want 2 and 1", tot.Views, tot.Unique)
	}
}

func TestMailtoRejectsControlCharactersAndMarkup(t *testing.T) {
	for _, bad := range []string{"mailto:0@0.\x16", "mailto:a@b.co\x00", "mailto:a<b>@c.co", `mailto:a"b@c.co`, "mailto:a'b@c.co", "mailto:a\u200b@b.co\x7f"} {
		if got, err := CleanLinkTarget(bad); err == nil {
			t.Errorf("accepted %q as %q", bad, got)
		}
	}
	if got, err := CleanLinkTarget("mailto:Sales@Blake-UK.com"); err != nil || got != "mailto:Sales@Blake-UK.com" {
		t.Errorf("a normal address must still work: %q %v", got, err)
	}
}
