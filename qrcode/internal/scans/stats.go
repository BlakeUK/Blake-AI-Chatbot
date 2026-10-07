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

var breakdownColumns = map[string]string{
	"device_class": "(unknown)",
	"os":           "(unknown)",
	"browser":      "(unknown)",
	"country":      "(unknown)",
	"referer_host": "(direct / none)",
}

// Breakdown returns the top `limit` values of column for a link. column must
// be one of the known scan columns; it is whitelisted because it is spliced
// into the SQL text.
func Breakdown(ctx context.Context, d *sql.DB, linkID int64, column string, limit int, includeBots bool) ([]Count, error) {
	empty, ok := breakdownColumns[column]
	if !ok {
		return nil, fmt.Errorf("unknown breakdown column %q", column)
	}
	var total int64
	if err := d.QueryRowContext(ctx, `SELECT COUNT(*) FROM scans WHERE link_id = ? AND (is_bot = 0 OR ?)`,
		linkID, includeBots).Scan(&total); err != nil {
		return nil, err
	}
	rows, err := d.QueryContext(ctx, `SELECT `+column+`, COUNT(*) AS c FROM scans
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
			c.Label = empty
		}
		if total > 0 {
			c.Pct = float64(c.N) * 100 / float64(total)
		}
		out = append(out, c)
	}
	return out, rows.Err()
}

// ExportCSV streams every raw scan for a link. Cells that a spreadsheet would
// interpret as a formula are prefixed with an apostrophe (CSV injection).
func ExportCSV(ctx context.Context, d *sql.DB, w io.Writer, linkID int64) error {
	cw := csv.NewWriter(w)
	if err := cw.Write([]string{"scanned_at", "ip_hash", "country", "device_class", "os", "browser",
		"referer_host", "user_agent", "is_bot", "is_unique"}); err != nil {
		return err
	}
	rows, err := d.QueryContext(ctx, `SELECT scanned_at, ip_hash, country, device_class, os, browser,
		referer_host, user_agent, is_bot, is_unique FROM scans WHERE link_id = ? ORDER BY scanned_at, id`, linkID)
	if err != nil {
		return err
	}
	defer rows.Close()
	for rows.Next() {
		var at, ip, country, dev, os, br, ref, ua string
		var bot, uniq int
		if err := rows.Scan(&at, &ip, &country, &dev, &os, &br, &ref, &ua, &bot, &uniq); err != nil {
			return err
		}
		rec := []string{at, ip, country, dev, os, br, ref, ua, fmt.Sprint(bot), fmt.Sprint(uniq)}
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

func csvSafe(s string) string {
	if s != "" && strings.ContainsRune("=+-@\t\r", rune(s[0])) {
		return "'" + s
	}
	return s
}

// Extent returns the times of the first and last non-bot-or-bot scan of a
// link (ok is false when it has none). The chart uses it so scans recorded
// under an earlier, since-edited window are never silently left off the graph.
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
