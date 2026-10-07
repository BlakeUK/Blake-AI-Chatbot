package scans

import (
	"context"
	"database/sql"
	"encoding/csv"
	"fmt"
	"io"
	"strings"
	"time"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/db"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/links"
)

// Totals are the headline numbers for a link. All and Unique exclude bots;
// Bots counts the excluded rows so the dashboard can say how many were hidden.
type Totals struct {
	All, Unique, Bots int64
	Last              time.Time // most recent non-bot scan; zero if none
}

// TotalsFor returns Totals for each of ids (links with no scans are absent).
func TotalsFor(ctx context.Context, d *sql.DB, ids []int64) (map[int64]Totals, error) {
	out := map[int64]Totals{}
	if len(ids) == 0 {
		return out, nil
	}
	ph := strings.TrimSuffix(strings.Repeat("?,", len(ids)), ",")
	args := make([]any, len(ids))
	for i, id := range ids {
		args[i] = id
	}
	rows, err := d.QueryContext(ctx, `SELECT link_id,
			SUM(CASE WHEN is_bot = 0 THEN 1 ELSE 0 END),
			SUM(CASE WHEN is_bot = 0 AND is_unique = 1 THEN 1 ELSE 0 END),
			SUM(is_bot),
			COALESCE(MAX(CASE WHEN is_bot = 0 THEN scanned_at END), '')
		FROM scans WHERE link_id IN (`+ph+`) GROUP BY link_id`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	for rows.Next() {
		var id int64
		var t Totals
		var last string
		if err := rows.Scan(&id, &t.All, &t.Unique, &t.Bots, &last); err != nil {
			return nil, err
		}
		if last != "" {
			t.Last, _ = db.ParseTS(last)
		}
		out[id] = t
	}
	return out, rows.Err()
}

// Bucket is one bar of the scans-over-time chart.
type Bucket struct {
	Start time.Time
	Label string
	Count int64
}

const maxBuckets = 400

// Series counts scans per hour or per day between from and to, in loc
// (Europe/London in the UI). Counting happens per UTC hour in SQL and is
// regrouped into local days in Go, so daylight-saving changes put scans on
// the right local day.
func Series(ctx context.Context, d *sql.DB, linkID int64, from, to time.Time, hourly, includeBots bool, loc *time.Location) ([]Bucket, error) {
	if !to.After(from) {
		return nil, nil
	}
	if !hourly {
		if min := to.AddDate(0, 0, -maxBuckets); from.Before(min) {
			from = min
		}
	}
	rows, err := d.QueryContext(ctx, `SELECT substr(scanned_at, 1, 13), COUNT(*) FROM scans
		WHERE link_id = ? AND scanned_at >= ? AND scanned_at < ? AND (is_bot = 0 OR ?)
		GROUP BY 1`, linkID, db.TS(from), db.TS(to), includeBots)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	counts := map[string]int64{}
	for rows.Next() {
		var hour string
		var n int64
		if err := rows.Scan(&hour, &n); err != nil {
			return nil, err
		}
		t, err := time.Parse("2006-01-02T15", hour)
		if err != nil {
			continue
		}
		counts[bucketKey(t.In(loc), hourly)] += n
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}

	var out []Bucket
	cur := from.In(loc)
	if hourly {
		cur = time.Date(cur.Year(), cur.Month(), cur.Day(), cur.Hour(), 0, 0, 0, loc)
	} else {
		cur = time.Date(cur.Year(), cur.Month(), cur.Day(), 0, 0, 0, 0, loc)
	}
	for cur.Before(to) && len(out) < maxBuckets*24 {
		label := cur.Format("02 Jan")
		if hourly {
			label = cur.Format("02 Jan 15:00")
		}
		out = append(out, Bucket{Start: cur, Label: label, Count: counts[bucketKey(cur, hourly)]})
		if hourly {
			cur = cur.Add(time.Hour)
		} else {
			cur = time.Date(cur.Year(), cur.Month(), cur.Day()+1, 0, 0, 0, 0, loc)
		}
	}
	return out, nil
}

func bucketKey(t time.Time, hourly bool) string {
	if hourly {
		// UTC, not local: on the night the clocks go back, two different UTC
		// hours share one local label and must not be merged.
		return t.UTC().Format("2006-01-02T15")
	}
	return t.Format("2006-01-02")
}

// Count is one row of a breakdown table.
type Count struct {
	Label string
	N     int64
	Pct   float64
}

// breakdowns maps a public key to the SQL expression to group by and the label
// used for rows where it is empty. Keys and expressions are fixed here, never
// built from request data, so nothing user-supplied reaches the SQL text.
var breakdowns = map[string]struct{ expr, empty string }{
	"device_class": {"device_class", "(unknown)"},
	"os":           {"os", "(unknown)"},
	"browser":      {"browser", "(unknown)"},
	"language":     {"language", "(unknown)"},
	"country":      {"country_name", "(unknown)"},
	"region":       {"CASE WHEN region = '' THEN '' WHEN country <> '' THEN region || ' (' || country || ')' ELSE region END", "(unknown)"},
	"city":         {"CASE WHEN city = '' THEN '' WHEN country <> '' THEN city || ' (' || country || ')' ELSE city END", "(unknown)"},
	"referer_host": {"referer_host", "(direct / none)"},
	"destination":  {"destination_url", "(not recorded)"},
}

// Breakdown returns the top `limit` values for one dimension of a link's scans.
func Breakdown(ctx context.Context, d *sql.DB, linkID int64, key string, limit int, includeBots bool) ([]Count, error) {
	b, ok := breakdowns[key]
	if !ok {
		return nil, fmt.Errorf("unknown breakdown %q", key)
	}
	var total int64
	if err := d.QueryRowContext(ctx, `SELECT COUNT(*) FROM scans WHERE link_id = ? AND (is_bot = 0 OR ?)`,
		linkID, includeBots).Scan(&total); err != nil {
		return nil, err
	}
	rows, err := d.QueryContext(ctx, `SELECT `+b.expr+`, COUNT(*) AS c FROM scans
		WHERE link_id = ? AND (is_bot = 0 OR ?) GROUP BY 1 ORDER BY c DESC, 1 LIMIT ?`,
		linkID, includeBots, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []Count
	for rows.Next() {
		var c Count
		if err := rows.Scan(&c.Label, &c.N); err != nil {
			return nil, err
		}
		if c.Label == "" {
			c.Label = b.empty
		}
		if total > 0 {
			c.Pct = float64(c.N) * 100 / float64(total)
		}
		out = append(out, c)
	}
	return out, rows.Err()
}

// Row is one recorded scan as shown in the "recent scans" table.
type Row struct {
	At          time.Time
	Campaign    string
	LinkLabel   string
	LinkCode    string
	Country     string
	CountryName string
	Region      string
	City        string
	Language    string
	Destination string
	DeviceClass string
	OS          string
	Browser     string
	RefererHost string
	IP          string
	IPHash      string
	UserAgent   string
	IsBot       bool
	IsUnique    bool
}

// Recent returns one page of a link's scans, newest first, with the total.
func Recent(ctx context.Context, d *sql.DB, linkID int64, limit, offset int, includeBots bool) ([]Row, int64, error) {
	var total int64
	if err := d.QueryRowContext(ctx, `SELECT COUNT(*) FROM scans WHERE link_id = ? AND (is_bot = 0 OR ?)`,
		linkID, includeBots).Scan(&total); err != nil {
		return nil, 0, err
	}
	rows, err := d.QueryContext(ctx, scanSelect+` WHERE s.link_id = ? AND (s.is_bot = 0 OR ?)
		ORDER BY s.scanned_at DESC, s.id DESC LIMIT ? OFFSET ?`, linkID, includeBots, limit, offset)
	if err != nil {
		return nil, 0, err
	}
	defer rows.Close()
	out, err := scanRows(rows)
	return out, total, err
}

const scanSelect = `SELECT s.scanned_at, l.campaign, l.label, l.code, s.country, s.country_name, s.region, s.city,
	s.language, CASE WHEN s.destination_url = '' THEN l.destination_url ELSE s.destination_url END,
	s.device_class, s.os, s.browser, s.referer_host, s.ip, s.ip_hash, s.user_agent, s.is_bot, s.is_unique
	FROM scans s JOIN links l ON l.id = s.link_id`

func scanRows(rows *sql.Rows) ([]Row, error) {
	var out []Row
	for rows.Next() {
		r, err := scanOne(rows)
		if err != nil {
			return nil, err
		}
		out = append(out, r)
	}
	return out, rows.Err()
}

// CampaignRow is one line of the per-campaign summary.
type CampaignRow struct {
	Campaign string // "" means links with no campaign
	Links    int64
	Scans    int64 // non-bot
	Unique   int64
	Bots     int64
	Last     time.Time
}

// CampaignTotals sums scans per campaign across all links.
func CampaignTotals(ctx context.Context, d *sql.DB) ([]CampaignRow, error) {
	rows, err := d.QueryContext(ctx, `SELECT l.campaign, COUNT(DISTINCT l.id),
			COALESCE(SUM(CASE WHEN s.is_bot = 0 THEN 1 ELSE 0 END), 0),
			COALESCE(SUM(CASE WHEN s.is_bot = 0 AND s.is_unique = 1 THEN 1 ELSE 0 END), 0),
			COALESCE(SUM(s.is_bot), 0),
			COALESCE(MAX(CASE WHEN s.is_bot = 0 THEN s.scanned_at END), '')
		FROM links l LEFT JOIN scans s ON s.link_id = l.id
		GROUP BY l.campaign ORDER BY (l.campaign = ''), SUM(CASE WHEN s.is_bot = 0 THEN 1 ELSE 0 END) DESC, l.campaign COLLATE NOCASE`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []CampaignRow
	for rows.Next() {
		var c CampaignRow
		var last string
		if err := rows.Scan(&c.Campaign, &c.Links, &c.Scans, &c.Unique, &c.Bots, &last); err != nil {
			return nil, err
		}
		if last != "" {
			c.Last, _ = db.ParseTS(last)
		}
		out = append(out, c)
	}
	return out, rows.Err()
}

// ExportCSV streams scans as CSV: every link when linkID is 0, otherwise one
// link. Times are given both in UTC and in loc (Europe/London in the UI).
// Cells that a spreadsheet would interpret as a formula are prefixed with an
// apostrophe (CSV injection).
func ExportCSV(ctx context.Context, d *sql.DB, w io.Writer, linkID int64, loc *time.Location) error {
	cw := csv.NewWriter(w)
	if err := cw.Write([]string{"scanned_at_utc", "scanned_at_local", "campaign", "link_label", "link_code",
		"destination", "destination_site", "country", "region", "town", "language", "device", "os", "browser",
		"referrer_host", "is_bot", "is_unique", "ip", "ip_hash", "user_agent"}); err != nil {
		return err
	}
	where, args := "", []any{}
	if linkID != 0 {
		where, args = " WHERE s.link_id = ?", []any{linkID}
	}
	rows, err := d.QueryContext(ctx, scanSelect+where+` ORDER BY s.scanned_at, s.id`, args...)
	if err != nil {
		return err
	}
	defer rows.Close()
	for rows.Next() {
		batch, err := scanOne(rows)
		if err != nil {
			return err
		}
		rec := []string{db.TS(batch.At), batch.At.In(loc).Format("2006-01-02 15:04:05"), batch.Campaign, batch.LinkLabel, batch.LinkCode,
			batch.Destination, links.SiteName(batch.Destination), batch.CountryName, batch.Region, batch.City, batch.Language,
			batch.DeviceClass, batch.OS, batch.Browser, batch.RefererHost, fmt.Sprint(b2i(batch.IsBot)), fmt.Sprint(b2i(batch.IsUnique)),
			batch.IP, batch.IPHash, batch.UserAgent}
		for i, c := range rec {
			rec[i] = csvSafe(c)
		}
		if err := cw.Write(rec); err != nil {
			return err
		}
	}
	if err := rows.Err(); err != nil {
		return err
	}
	cw.Flush()
	return cw.Error()
}

// scanOne reads the current row of a scanSelect query.
func scanOne(rows *sql.Rows) (Row, error) {
	var r Row
	var at string
	var bot, uniq int
	if err := rows.Scan(&at, &r.Campaign, &r.LinkLabel, &r.LinkCode, &r.Country, &r.CountryName, &r.Region, &r.City,
		&r.Language, &r.Destination, &r.DeviceClass, &r.OS, &r.Browser, &r.RefererHost, &r.IP, &r.IPHash, &r.UserAgent, &bot, &uniq); err != nil {
		return r, err
	}
	r.At, _ = db.ParseTS(at)
	r.IsBot, r.IsUnique = bot == 1, uniq == 1
	return r, nil
}

func csvSafe(s string) string {
	if s != "" && strings.ContainsRune("=+-@\t\r", rune(s[0])) {
		return "'" + s
	}
	return s
}

// Extent returns the times of the first and last scan of a link (ok is false
// when it has none). The chart uses it so scans recorded under an earlier,
// since-edited window are never silently left off the graph.
func Extent(ctx context.Context, d *sql.DB, linkID int64) (first, last time.Time, ok bool, err error) {
	var a, b sql.NullString
	if err = d.QueryRowContext(ctx, `SELECT MIN(scanned_at), MAX(scanned_at) FROM scans WHERE link_id = ?`, linkID).Scan(&a, &b); err != nil {
		return
	}
	if !a.Valid || !b.Valid {
		return
	}
	if first, err = db.ParseTS(a.String); err != nil {
		return
	}
	if last, err = db.ParseTS(b.String); err != nil {
		return
	}
	return first, last, true, nil
}
