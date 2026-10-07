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
	list, total, err := s.List(ctx, 1, 1)
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
