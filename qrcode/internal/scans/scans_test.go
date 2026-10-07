package scans

import (
	"bytes"
	"context"
	"database/sql"
	"io"
	"log/slog"
	"path/filepath"
	"strings"
	"testing"
	"time"

	qrtrack "github.com/BlakeUK/Blake-AI-Chatbot/qrcode"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/db"
)

func testDB(t *testing.T) *sql.DB {
	t.Helper()
	d, err := db.Open(filepath.Join(t.TempDir(), "t.db"))
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { d.Close() })
	if err := db.Migrate(context.Background(), d, qrtrack.Migrations, "migrations"); err != nil {
		t.Fatal(err)
	}
	return d
}

func addLink(t *testing.T, d *sql.DB) int64 {
	t.Helper()
	res, err := d.Exec(`INSERT INTO links (code, label, destination_url, track_start, track_end, created_at, updated_at)
		VALUES ('AAAAAAAA','l','https://example.com','2026-01-01T00:00:00Z','2027-01-01T00:00:00Z','2026-01-01T00:00:00Z','2026-01-01T00:00:00Z')`)
	if err != nil {
		t.Fatal(err)
	}
	id, _ := res.LastInsertId()
	return id
}

func quiet() *slog.Logger { return slog.New(slog.NewTextHandler(io.Discard, nil)) }

func TestHasherDailyRotation(t *testing.T) {
	d := testDB(t)
	now := time.Date(2026, 10, 1, 12, 0, 0, 0, time.UTC)
	h := NewHasher(d, func() time.Time { return now })
	ctx := context.Background()

	a1, _ := h.Hash(ctx, "203.0.113.7")
	a2, _ := h.Hash(ctx, "203.0.113.7")
	b, _ := h.Hash(ctx, "203.0.113.8")
	if a1 != a2 {
		t.Error("same IP, same day must hash identically")
	}
	if a1 == b {
		t.Error("different IPs must hash differently")
	}
	if strings.Contains(a1, "203") && strings.Contains(a1, "0.113") {
		t.Error("hash looks like it contains the IP")
	}
	now = now.Add(24 * time.Hour)
	next, _ := h.Hash(ctx, "203.0.113.7")
	if next == a1 {
		t.Error("the same IP must hash differently on a different day")
	}
	// A second Hasher (e.g. after a restart) on the same day must agree.
	h2 := NewHasher(d, func() time.Time { return now })
	again, _ := h2.Hash(ctx, "203.0.113.7")
	if again != next {
		t.Error("salt must persist across restarts within a day")
	}
}

func TestUniqueScanLogic(t *testing.T) {
	d := testDB(t)
	id := addLink(t, d)
	w := NewWriter(d, 64, quiet())
	w.Start()
	defer w.Close(context.Background())
	ctx := context.Background()

	t0 := time.Date(2026, 10, 1, 9, 0, 0, 0, time.UTC)
	mk := func(ip string, at time.Time, bot bool) Scan {
		return Scan{LinkID: id, At: at, IPHash: ip, DeviceClass: "mobile", IsBot: bot}
	}
	for _, s := range []Scan{
		mk("A", t0, false),                                  // first sighting: unique
		mk("A", t0.Add(time.Hour), false),                   // same visitor within 24h: not unique
		mk("B", t0.Add(time.Hour), false),                   // different visitor: unique
		mk("A", t0.Add(23*time.Hour+59*time.Minute), false), // still within 24h of the last A
		mk("A", t0.Add(49*time.Hour), false),                // more than 24h after every earlier A: unique again
		mk("C", t0, true),                                   // bot, unique flag set but excluded from headlines
	} {
		if !w.Submit(s) {
			t.Fatal("submit refused")
		}
	}
	if err := w.Flush(ctx); err != nil {
		t.Fatal(err)
	}
	tot, err := TotalsFor(ctx, d, []int64{id})
	if err != nil {
		t.Fatal(err)
	}
	got := tot[id]
	if got.All != 5 || got.Unique != 3 || got.Bots != 1 {
		t.Errorf("totals = %+v, want All=5 Unique=3 Bots=1", got)
	}
	if !got.Last.Equal(t0.Add(49 * time.Hour)) {
		t.Errorf("last = %v", got.Last)
	}
}

func TestWriterNeverBlocksWhenFull(t *testing.T) {
	d := testDB(t)
	id := addLink(t, d)
	w := NewWriter(d, 2, quiet()) // deliberately not started: nothing drains the queue
	start := time.Now()
	accepted := 0
	for i := 0; i < 1000; i++ {
		if w.Submit(Scan{LinkID: id, At: time.Now(), IPHash: "x", DeviceClass: "desktop"}) {
			accepted++
		}
	}
	if time.Since(start) > 200*time.Millisecond {
		t.Errorf("Submit blocked: 1000 calls took %v", time.Since(start))
	}
	if accepted != 2 || w.Dropped() != 998 {
		t.Errorf("accepted=%d dropped=%d, want 2 and 998", accepted, w.Dropped())
	}
}

func TestCloseDrainsQueue(t *testing.T) {
	d := testDB(t)
	id := addLink(t, d)
	w := NewWriter(d, 512, quiet())
	w.Start()
	for i := 0; i < 300; i++ {
		w.Submit(Scan{LinkID: id, At: time.Now(), IPHash: "x", DeviceClass: "desktop"})
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := w.Close(ctx); err != nil {
		t.Fatal(err)
	}
	var n int
	d.QueryRow(`SELECT COUNT(*) FROM scans`).Scan(&n)
	if n != 300 {
		t.Errorf("after Close, %d rows written, want 300 (graceful shutdown must drain)", n)
	}
	if w.Submit(Scan{LinkID: id}) {
		t.Error("Submit after Close must be refused, not panic")
	}
}

func TestSeriesGroupsByLondonDay(t *testing.T) {
	d := testDB(t)
	id := addLink(t, d)
	london, err := time.LoadLocation("Europe/London")
	if err != nil {
		t.Skip("tzdata unavailable")
	}
	w := NewWriter(d, 64, quiet())
	w.Start()
	defer w.Close(context.Background())
	// 23:30 UTC on 14 Jun is 00:30 BST on 15 Jun: it belongs to the 15th locally.
	at := func(s string) time.Time { x, _ := time.Parse(time.RFC3339, s); return x }
	for _, ts := range []string{"2026-06-14T10:00:00Z", "2026-06-14T23:30:00Z", "2026-06-15T08:00:00Z"} {
		w.Submit(Scan{LinkID: id, At: at(ts), IPHash: ts, DeviceClass: "desktop"})
	}
	w.Submit(Scan{LinkID: id, At: at("2026-06-14T12:00:00Z"), IPHash: "bot", DeviceClass: "bot", IsBot: true})
	w.Flush(context.Background())

	from, to := at("2026-06-14T00:00:00Z"), at("2026-06-16T00:00:00Z")
	b, err := Series(context.Background(), d, id, from, to, false, false, london)
	if err != nil {
		t.Fatal(err)
	}
	counts := map[string]int64{}
	for _, x := range b {
		counts[x.Label] = x.Count
	}
	if counts["14 Jun"] != 1 || counts["15 Jun"] != 2 {
		t.Errorf("per-London-day counts = %v, want 14 Jun:1 15 Jun:2 (bots excluded)", counts)
	}
	withBots, _ := Series(context.Background(), d, id, from, to, false, true, london)
	var all int64
	for _, x := range withBots {
		all += x.Count
	}
	if all != 4 {
		t.Errorf("with bots total = %d, want 4", all)
	}
	hourly, _ := Series(context.Background(), d, id, at("2026-06-14T09:00:00Z"), at("2026-06-14T12:00:00Z"), true, false, london)
	if len(hourly) != 3 || hourly[1].Count != 1 {
		t.Errorf("hourly = %+v", hourly)
	}
}

func TestBreakdownAndCSV(t *testing.T) {
	d := testDB(t)
	id := addLink(t, d)
	w := NewWriter(d, 64, quiet())
	w.Start()
	defer w.Close(context.Background())
	now := time.Now()
	w.Submit(Scan{LinkID: id, At: now, IPHash: "1", DeviceClass: "mobile", OS: "iOS", Browser: "Safari", Country: "GB", UserAgent: "=HYPERLINK(\"http://evil\")"})
	w.Submit(Scan{LinkID: id, At: now, IPHash: "2", DeviceClass: "mobile", OS: "Android", Browser: "Chrome", Country: "GB", RefererHost: "example.org"})
	w.Submit(Scan{LinkID: id, At: now, IPHash: "3", DeviceClass: "desktop", OS: "Windows", Browser: "Edge"})
	w.Flush(context.Background())

	br, err := Breakdown(context.Background(), d, id, "device_class", 10, false)
	if err != nil || len(br) != 2 || br[0].Label != "mobile" || br[0].N != 2 {
		t.Fatalf("breakdown: %v %+v", err, br)
	}
	if br[0].Pct < 66 || br[0].Pct > 67 {
		t.Errorf("pct = %v", br[0].Pct)
	}
	ref, _ := Breakdown(context.Background(), d, id, "referer_host", 10, false)
	foundDirect := false
	for _, r := range ref {
		if r.Label == "(direct / none)" && r.N == 2 {
			foundDirect = true
		}
	}
	if !foundDirect {
		t.Errorf("referer breakdown: %+v", ref)
	}
	if _, err := Breakdown(context.Background(), d, id, "user_agent; DROP TABLE scans", 5, false); err == nil {
		t.Error("non-whitelisted column accepted: SQL injection surface")
	}

	var buf bytes.Buffer
	if err := ExportCSV(context.Background(), d, &buf, id, time.UTC); err != nil {
		t.Fatal(err)
	}
	out := buf.String()
	if !strings.HasPrefix(out, "scanned_at_utc,scanned_at_local,campaign,") || strings.Count(out, "\n") != 4 {
		t.Errorf("csv shape wrong:\n%s", out)
	}
	if strings.Contains(out, ",=HYPERLINK") || !strings.Contains(out, "'=HYPERLINK") {
		t.Errorf("formula cell not neutralised:\n%s", out)
	}
}

func TestPurge(t *testing.T) {
	d := testDB(t)
	id := addLink(t, d)
	now := time.Date(2026, 10, 1, 0, 0, 0, 0, time.UTC)
	ins := func(at time.Time) {
		d.Exec(`INSERT INTO scans(link_id, scanned_at, ip_hash, device_class) VALUES (?,?,?,?)`, id, db.TS(at), "h", "mobile")
	}
	ins(now.AddDate(0, 0, -400))
	ins(now.AddDate(0, 0, -366))
	ins(now.AddDate(0, 0, -10))
	d.Exec(`INSERT INTO daily_salts(day, salt) VALUES ('2026-09-20', x'00'), ('2026-09-30', x'00')`)
	n, err := Purge(context.Background(), d, now.AddDate(0, 0, -365), now)
	if err != nil || n != 2 {
		t.Fatalf("purged %d, err %v", n, err)
	}
	var left, salts int
	d.QueryRow(`SELECT COUNT(*) FROM scans`).Scan(&left)
	d.QueryRow(`SELECT COUNT(*) FROM daily_salts`).Scan(&salts)
	if left != 1 || salts != 1 {
		t.Errorf("left=%d salts=%d, want 1 and 1", left, salts)
	}
}

func TestNewFieldsAreStoredAndShown(t *testing.T) {
	d := testDB(t)
	id := addLink(t, d)
	d.Exec(`UPDATE links SET campaign = 'Exhibition stand', destination_url = 'https://www.facebook.com/blakeuk' WHERE id = ?`, id)
	w := NewWriter(d, 64, quiet())
	w.Start()
	defer w.Close(context.Background())
	london, _ := time.LoadLocation("Europe/London")
	at := time.Date(2026, 6, 15, 12, 34, 56, 0, time.UTC) // 13:34:56 BST
	w.Submit(Scan{LinkID: id, At: at, IPHash: "h1", Country: "GB", CountryName: "United Kingdom", Region: "England", City: "London",
		Language: "en-GB", Destination: "https://www.facebook.com/blakeuk", DeviceClass: "mobile", OS: "iOS", Browser: "Safari"})
	w.Submit(Scan{LinkID: id, At: at.Add(time.Minute), IPHash: "h2", Country: "GB", CountryName: "United Kingdom", Region: "Scotland", City: "Glasgow",
		Language: "en-GB", DeviceClass: "desktop", OS: "Windows", Browser: "Edge", IP: "203.0.113.5"})
	w.Submit(Scan{LinkID: id, At: at.Add(2 * time.Minute), IPHash: "h3", Country: "FR", CountryName: "France", City: "Paris",
		Language: "fr-FR", DeviceClass: "mobile", OS: "Android", Browser: "Chrome"})
	w.Submit(Scan{LinkID: id, At: at.Add(3 * time.Minute), IPHash: "b", DeviceClass: "bot", IsBot: true})
	if err := w.Flush(context.Background()); err != nil {
		t.Fatal(err)
	}
	ctx := context.Background()

	rows, total, err := Recent(ctx, d, id, 10, 0, false)
	if err != nil || total != 3 || len(rows) != 3 {
		t.Fatalf("recent: %v total=%d rows=%d (bots must be hidden by default)", err, total, len(rows))
	}
	if rows[0].City != "Paris" || rows[2].City != "London" { // newest first
		t.Errorf("order: %v %v %v", rows[0].City, rows[1].City, rows[2].City)
	}
	r := rows[2]
	if r.Campaign != "Exhibition stand" || r.Region != "England" || r.CountryName != "United Kingdom" || r.Language != "en-GB" ||
		r.Destination != "https://www.facebook.com/blakeuk" || r.DeviceClass != "mobile" || r.OS != "iOS" || r.Browser != "Safari" {
		t.Errorf("row = %+v", r)
	}
	// A scan stored without a destination snapshot falls back to the link's current one.
	if rows[1].Destination != "https://www.facebook.com/blakeuk" || rows[1].IP != "203.0.113.5" {
		t.Errorf("fallback destination / ip: %+v", rows[1])
	}
	if got := r.At.In(london).Format("02 Jan 2006 15:04:05"); got != "15 Jun 2026 13:34:56" {
		t.Errorf("exact local time = %q", got)
	}
	// Pagination and the bot toggle.
	p2, _, _ := Recent(ctx, d, id, 2, 2, false)
	if len(p2) != 1 || p2[0].City != "London" {
		t.Errorf("page 2 = %+v", p2)
	}
	if _, withBots, _ := Recent(ctx, d, id, 10, 0, true); withBots != 4 {
		t.Errorf("with bots total = %d", withBots)
	}

	for key, want := range map[string]string{"language": "en-GB", "country": "United Kingdom", "city": "London (GB)", "region": "England (GB)", "device_class": "mobile"} {
		got, err := Breakdown(ctx, d, id, key, 10, false)
		if err != nil || len(got) == 0 {
			t.Fatalf("%s: %v %v", key, err, got)
		}
		found := false
		for _, g := range got {
			if g.Label == want {
				found = true
			}
		}
		if !found {
			t.Errorf("breakdown %s missing %q: %+v", key, want, got)
		}
	}
	if lang, _ := Breakdown(ctx, d, id, "language", 10, false); lang[0].Label != "en-GB" || lang[0].N != 2 {
		t.Errorf("language breakdown: %+v", lang)
	}
	if dest, _ := Breakdown(ctx, d, id, "destination", 10, false); len(dest) == 0 {
		t.Error("destination breakdown empty")
	}

	var buf bytes.Buffer
	if err := ExportCSV(ctx, d, &buf, 0, london); err != nil { // 0 = every link
		t.Fatal(err)
	}
	csvText := buf.String()
	for _, want := range []string{"scanned_at_utc,scanned_at_local,campaign,link_label,link_code,destination,destination_site,country,region,town,language",
		"2026-06-15 13:34:56", "2026-06-15T12:34:56Z", "Exhibition stand", "Facebook", "Glasgow", "fr-FR", "203.0.113.5"} {
		if !strings.Contains(csvText, want) {
			t.Errorf("CSV missing %q:\n%s", want, csvText)
		}
	}
}

func TestCampaignTotals(t *testing.T) {
	d := testDB(t)
	mk := func(code, campaign string) int64 {
		res, err := d.Exec(`INSERT INTO links (code, label, destination_url, track_start, track_end, campaign, created_at, updated_at)
			VALUES (?, ?, 'https://example.com', '2026-01-01T00:00:00Z', '2027-01-01T00:00:00Z', ?, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')`, code, code, campaign)
		if err != nil {
			t.Fatal(err)
		}
		id, _ := res.LastInsertId()
		return id
	}
	a1, a2, b, none := mk("AAAAAAA1", "Leaflet"), mk("AAAAAAA2", "Leaflet"), mk("BBBBBBBB", "Product box"), mk("CCCCCCCC", "")
	w := NewWriter(d, 64, quiet())
	w.Start()
	defer w.Close(context.Background())
	now := time.Now()
	add := func(link int64, hash string, bot bool) {
		w.Submit(Scan{LinkID: link, At: now, IPHash: hash, DeviceClass: "mobile", IsBot: bot})
	}
	add(a1, "x", false)
	add(a1, "x", false) // same visitor: scan but not unique
	add(a2, "y", false)
	add(a2, "z", true)
	add(b, "x", false)
	add(none, "q", false)
	w.Flush(context.Background())
	got, err := CampaignTotals(context.Background(), d)
	if err != nil {
		t.Fatal(err)
	}
	by := map[string]CampaignRow{}
	for _, r := range got {
		by[r.Campaign] = r
	}
	l := by["Leaflet"]
	if l.Links != 2 || l.Scans != 3 || l.Unique != 2 || l.Bots != 1 {
		t.Errorf("Leaflet = %+v, want links=2 scans=3 unique=2 bots=1", l)
	}
	if p := by["Product box"]; p.Links != 1 || p.Scans != 1 {
		t.Errorf("Product box = %+v", p)
	}
	if n := by[""]; n.Links != 1 || n.Scans != 1 {
		t.Errorf("uncategorised = %+v", n)
	}
	if got[len(got)-1].Campaign != "" {
		t.Error("uncategorised links must be listed last")
	}
}
