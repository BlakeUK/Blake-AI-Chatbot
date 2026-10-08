package pages

import (
	"context"
	"database/sql"
	"errors"
	"log/slog"
	"sync"
	"sync/atomic"
	"time"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/db"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/scans"
)

// Event is one view of a page or one click on a button.
type Event struct {
	PageID      int64
	ItemID      int64 // 0 for a view
	At          time.Time
	Kind        string // "view" or "click"
	Source      string // "qr" or "direct"
	IPHash      string
	Country     string
	CountryName string
	DeviceClass string
	OS          string
	Browser     string
	Language    string
	RefererHost string
	IsBot       bool
}

// Writer records events from one goroutine in batches, like the QR scan
// writer: the page is served first and the record follows, so counting can
// never slow a visitor down.
type Writer struct {
	db       *sql.DB
	log      *slog.Logger
	ch       chan Event
	flushReq chan chan struct{}
	mu       sync.RWMutex
	closed   bool
	wg       sync.WaitGroup
	dropped  atomic.Int64
}

func NewWriter(d *sql.DB, buf int, log *slog.Logger) *Writer {
	return &Writer{db: d, log: log, ch: make(chan Event, buf), flushReq: make(chan chan struct{})}
}

func (w *Writer) Start() { w.wg.Add(1); go w.run() }

// Submit queues an event without ever blocking; a full queue drops it.
func (w *Writer) Submit(e Event) bool {
	w.mu.RLock()
	defer w.mu.RUnlock()
	if w.closed {
		return false
	}
	select {
	case w.ch <- e:
		return true
	default:
		w.dropped.Add(1)
		return false
	}
}

func (w *Writer) Dropped() int64 { return w.dropped.Load() }

// Flush blocks until everything queued so far is stored.
func (w *Writer) Flush(ctx context.Context) error {
	reply := make(chan struct{})
	select {
	case w.flushReq <- reply:
	case <-ctx.Done():
		return ctx.Err()
	}
	select {
	case <-reply:
		return nil
	case <-ctx.Done():
		return ctx.Err()
	}
}

// Close stops accepting events and drains the queue.
func (w *Writer) Close(ctx context.Context) error {
	w.mu.Lock()
	if !w.closed {
		w.closed = true
		close(w.ch)
	}
	w.mu.Unlock()
	done := make(chan struct{})
	go func() { w.wg.Wait(); close(done) }()
	select {
	case <-done:
		return nil
	case <-ctx.Done():
		return ctx.Err()
	}
}

func (w *Writer) run() {
	defer w.wg.Done()
	batch := make([]Event, 0, 64)
	tick := time.NewTicker(250 * time.Millisecond)
	defer tick.Stop()
	commit := func() {
		if len(batch) == 0 {
			return
		}
		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		defer cancel()
		if err := w.insert(ctx, batch); err != nil {
			w.log.Error("page events not written", "count", len(batch), "err", err)
		}
		batch = batch[:0]
	}
	for {
		select {
		case e, ok := <-w.ch:
			if !ok {
				commit()
				return
			}
			batch = append(batch, e)
			if len(batch) >= 100 {
				commit()
			}
		case <-tick.C:
			commit()
		case reply := <-w.flushReq:
		drain:
			for {
				select {
				case e, ok := <-w.ch:
					if !ok {
						break drain
					}
					batch = append(batch, e)
				default:
					break drain
				}
			}
			commit()
			close(reply)
		}
	}
}

func b2iv(b bool) int {
	if b {
		return 1
	}
	return 0
}

func (w *Writer) insert(ctx context.Context, batch []Event) error {
	tx, err := w.db.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	seen, err := tx.PrepareContext(ctx, `SELECT 1 FROM page_events WHERE page_id = ? AND kind = 'view' AND is_bot = 0 AND ip_hash = ? AND at > ? LIMIT 1`)
	if err != nil {
		return err
	}
	defer seen.Close()
	ins, err := tx.PrepareContext(ctx, `INSERT INTO page_events
		(page_id, item_id, at, kind, source, ip_hash, country, country_name, device_class, os, browser, language, referer_host, is_bot, is_unique)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`)
	if err != nil {
		return err
	}
	defer ins.Close()
	for _, e := range batch {
		unique := false
		if e.Kind == "view" {
			var one int
			err := seen.QueryRowContext(ctx, e.PageID, e.IPHash, db.TS(e.At.Add(-24*time.Hour))).Scan(&one)
			unique = errors.Is(err, sql.ErrNoRows)
			if err != nil && !unique {
				return err
			}
		}
		if _, err := ins.ExecContext(ctx, e.PageID, e.ItemID, db.TS(e.At), e.Kind, e.Source, e.IPHash, e.Country, e.CountryName,
			e.DeviceClass, e.OS, e.Browser, e.Language, e.RefererHost, b2iv(e.IsBot), b2iv(unique)); err != nil {
			return err
		}
	}
	return tx.Commit()
}

// ---------- statistics ----------

// Totals are the headline numbers for a page. Bots are left out of all but Bots.
type Totals struct {
	Views, Unique, ViaQR, Direct, Clicks, Bots int64
	LastView                                   time.Time
}

func Totalled(ctx context.Context, d *sql.DB, pageID int64) (Totals, error) {
	var t Totals
	var last string
	err := d.QueryRowContext(ctx, `SELECT
		COALESCE(SUM(kind='view' AND is_bot=0),0),
		COALESCE(SUM(kind='view' AND is_bot=0 AND is_unique=1),0),
		COALESCE(SUM(kind='view' AND is_bot=0 AND source='qr'),0),
		COALESCE(SUM(kind='view' AND is_bot=0 AND source<>'qr'),0),
		COALESCE(SUM(kind='click' AND is_bot=0),0),
		COALESCE(SUM(is_bot=1),0),
		COALESCE(MAX(CASE WHEN kind='view' AND is_bot=0 THEN at END),'')
		FROM page_events WHERE page_id = ?`, pageID).Scan(&t.Views, &t.Unique, &t.ViaQR, &t.Direct, &t.Clicks, &t.Bots, &last)
	if last != "" {
		t.LastView, _ = db.ParseTS(last)
	}
	return t, err
}

// PageTotals returns views and clicks for every page, for the list.
func PageTotals(ctx context.Context, d *sql.DB) (map[int64]Totals, error) {
	rows, err := d.QueryContext(ctx, `SELECT page_id,
		COALESCE(SUM(kind='view' AND is_bot=0),0), COALESCE(SUM(kind='click' AND is_bot=0),0)
		FROM page_events GROUP BY page_id`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := map[int64]Totals{}
	for rows.Next() {
		var id int64
		var t Totals
		if err := rows.Scan(&id, &t.Views, &t.Clicks); err != nil {
			return nil, err
		}
		out[id] = t
	}
	return out, rows.Err()
}

// ItemStat is the click record of one button.
type ItemStat struct {
	Clicks int64
	Last   time.Time
}

// ItemClicks returns the clicks of every button of a page, by button id
// (id 0 collects clicks on buttons that have since been removed).
func ItemClicks(ctx context.Context, d *sql.DB, pageID int64) (map[int64]ItemStat, error) {
	rows, err := d.QueryContext(ctx, `SELECT item_id, COUNT(*), MAX(at) FROM page_events
		WHERE page_id = ? AND kind = 'click' AND is_bot = 0 GROUP BY item_id`, pageID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := map[int64]ItemStat{}
	for rows.Next() {
		var id int64
		var s ItemStat
		var last string
		if err := rows.Scan(&id, &s.Clicks, &last); err != nil {
			return nil, err
		}
		s.Last, _ = db.ParseTS(last)
		out[id] = s
	}
	return out, rows.Err()
}

// ViewHours returns page views (not bots) per UTC hour between from and to,
// in the form scans.Regroup expects.
func ViewHours(ctx context.Context, d *sql.DB, pageID int64, from, to time.Time) (map[string]int64, error) {
	rows, err := d.QueryContext(ctx, `SELECT substr(at, 1, 13), COUNT(*) FROM page_events
		WHERE page_id = ? AND kind = 'view' AND is_bot = 0 AND at >= ? AND at < ? GROUP BY 1`, pageID, db.TS(from), db.TS(to))
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := map[string]int64{}
	for rows.Next() {
		var h string
		var n int64
		if err := rows.Scan(&h, &n); err != nil {
			return nil, err
		}
		out[h] = n
	}
	return out, rows.Err()
}

var viewBreakdowns = map[string]struct{ expr, empty string }{
	"device":   {"device_class", "(unknown)"},
	"os":       {"os", "(unknown)"},
	"country":  {"country_name", "(unknown)"},
	"referrer": {"referer_host", "(direct / none)"},
	"source":   {"CASE source WHEN 'qr' THEN 'QR code' ELSE 'Direct link' END", "(unknown)"},
	"language": {"language", "(unknown)"},
}

// ViewBreakdown returns the most common values for one dimension of page views.
func ViewBreakdown(ctx context.Context, d *sql.DB, pageID int64, key string, limit int) ([]scans.Count, error) {
	b, ok := viewBreakdowns[key]
	if !ok {
		return nil, errors.New("unknown breakdown")
	}
	var total int64
	if err := d.QueryRowContext(ctx, `SELECT COUNT(*) FROM page_events WHERE page_id = ? AND kind='view' AND is_bot=0`, pageID).Scan(&total); err != nil {
		return nil, err
	}
	rows, err := d.QueryContext(ctx, `SELECT `+b.expr+`, COUNT(*) AS c FROM page_events
		WHERE page_id = ? AND kind='view' AND is_bot=0 GROUP BY 1 ORDER BY c DESC, 1 LIMIT ?`, pageID, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []scans.Count
	for rows.Next() {
		var c scans.Count
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

// Purge deletes events older than cutoff.
func Purge(ctx context.Context, d *sql.DB, cutoff time.Time) (int64, error) {
	var total int64
	for {
		res, err := d.ExecContext(ctx, `DELETE FROM page_events WHERE id IN (SELECT id FROM page_events WHERE at < ? LIMIT 5000)`, db.TS(cutoff))
		if err != nil {
			return total, err
		}
		n, _ := res.RowsAffected()
		total += n
		if n < 5000 {
			return total, nil
		}
	}
}
