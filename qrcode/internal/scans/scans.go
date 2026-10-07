// Package scans records and reports scan events. Recording is deliberately
// decoupled from the redirect: handlers hand a Scan to Writer.Submit, which
// never blocks, and a single goroutine batches them into SQLite.
package scans

import (
	"context"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"errors"
	"log/slog"
	"sync"
	"sync/atomic"
	"time"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/db"
)

// Scan is one recorded visit to a tracking URL.
type Scan struct {
	LinkID      int64
	At          time.Time
	IPHash      string
	IP          string // empty unless the operator enabled STORE_FULL_IP
	Country     string // ISO code, e.g. GB
	CountryName string
	Region      string
	City        string
	Language    string // primary browser language tag, e.g. en-GB
	Destination string // where this scan was actually sent
	DeviceClass string
	OS          string
	Browser     string
	RefererHost string
	UserAgent   string
	IsBot       bool
}

// Hasher turns a client IP into a pseudonymous token that is stable for one
// UTC day and unlinkable across days. The per-day salt lives in the database
// and is deleted after a short grace period, after which even the operator
// cannot tell whether two old tokens came from the same address.
type Hasher struct {
	db  *sql.DB
	now func() time.Time

	mu   sync.RWMutex
	day  string
	salt []byte
}

func NewHasher(d *sql.DB, now func() time.Time) *Hasher {
	return &Hasher{db: d, now: now}
}

func (h *Hasher) todaysSalt(ctx context.Context) ([]byte, error) {
	day := h.now().UTC().Format("2006-01-02")
	h.mu.RLock()
	if h.day == day && h.salt != nil {
		s := h.salt
		h.mu.RUnlock()
		return s, nil
	}
	h.mu.RUnlock()

	h.mu.Lock()
	defer h.mu.Unlock()
	if h.day == day && h.salt != nil {
		return h.salt, nil
	}
	fresh := make([]byte, 32)
	if _, err := rand.Read(fresh); err != nil {
		return nil, err
	}
	if _, err := h.db.ExecContext(ctx, `INSERT OR IGNORE INTO daily_salts(day, salt) VALUES (?, ?)`, day, fresh); err != nil {
		return nil, err
	}
	var salt []byte
	if err := h.db.QueryRowContext(ctx, `SELECT salt FROM daily_salts WHERE day = ?`, day).Scan(&salt); err != nil {
		return nil, err
	}
	h.day, h.salt = day, salt
	return salt, nil
}

// Hash returns hex(HMAC-SHA256(today's salt, ip)).
func (h *Hasher) Hash(ctx context.Context, ip string) (string, error) {
	salt, err := h.todaysSalt(ctx)
	if err != nil {
		return "", err
	}
	m := hmac.New(sha256.New, salt)
	m.Write([]byte(ip))
	return hex.EncodeToString(m.Sum(nil)), nil
}

// Writer batches scans into SQLite from a single goroutine.
type Writer struct {
	db  *sql.DB
	log *slog.Logger
	ch  chan Scan

	flushReq chan chan struct{}
	mu       sync.RWMutex
	closed   bool
	wg       sync.WaitGroup
	dropped  atomic.Int64
	failed   atomic.Int64
}

// NewWriter creates a writer with a queue of buf scans. Call Start to run it.
func NewWriter(d *sql.DB, buf int, log *slog.Logger) *Writer {
	return &Writer{db: d, log: log, ch: make(chan Scan, buf), flushReq: make(chan chan struct{})}
}

func (w *Writer) Start() {
	w.wg.Add(1)
	go w.run()
}

// Submit queues s without ever blocking the caller. It returns false (and
// counts a drop) if the queue is full or the writer is closed: losing one
// statistic is always better than delaying a redirect.
func (w *Writer) Submit(s Scan) bool {
	w.mu.RLock()
	defer w.mu.RUnlock()
	if w.closed {
		return false
	}
	select {
	case w.ch <- s:
		return true
	default:
		w.dropped.Add(1)
		return false
	}
}

func (w *Writer) Dropped() int64 { return w.dropped.Load() }
func (w *Writer) Failed() int64  { return w.failed.Load() }

// Flush blocks until everything queued before the call has been committed.
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

// Close stops accepting scans, drains what is queued, and waits for the
// goroutine to finish (or ctx to expire).
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
	batch := make([]Scan, 0, 128)
	tick := time.NewTicker(250 * time.Millisecond)
	defer tick.Stop()
	for {
		select {
		case s, ok := <-w.ch:
			if !ok {
				w.commit(batch)
				return
			}
			batch = append(batch, s)
			if len(batch) >= 100 {
				w.commit(batch)
				batch = batch[:0]
			}
		case <-tick.C:
			if len(batch) > 0 {
				w.commit(batch)
				batch = batch[:0]
			}
		case reply := <-w.flushReq:
		drain:
			for {
				select {
				case s, ok := <-w.ch:
					if !ok {
						break drain
					}
					batch = append(batch, s)
				default:
					break drain
				}
			}
			w.commit(batch)
			batch = batch[:0]
			close(reply)
		}
	}
}

func (w *Writer) commit(batch []Scan) {
	if len(batch) == 0 {
		return
	}
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()
	if err := w.insert(ctx, batch); err != nil {
		w.failed.Add(int64(len(batch)))
		w.log.Error("scan batch not written", "count", len(batch), "err", err)
	}
}

func (w *Writer) insert(ctx context.Context, batch []Scan) error {
	tx, err := w.db.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	seen, err := tx.PrepareContext(ctx, `SELECT 1 FROM scans WHERE link_id = ? AND ip_hash = ? AND scanned_at > ? LIMIT 1`)
	if err != nil {
		return err
	}
	defer seen.Close()
	ins, err := tx.PrepareContext(ctx, `INSERT INTO scans
		(link_id, scanned_at, ip_hash, ip, country, country_name, region, city, language, destination_url,
		 device_class, os, browser, referer_host, user_agent, is_bot, is_unique)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`)
	if err != nil {
		return err
	}
	defer ins.Close()
	for _, s := range batch {
		// A scan is "unique" if this hashed visitor has not scanned this link
		// in the 24 hours before it. Earlier rows in this same transaction
		// are visible to the query, so duplicates within a batch are caught.
		var one int
		err := seen.QueryRowContext(ctx, s.LinkID, s.IPHash, db.TS(s.At.Add(-24*time.Hour))).Scan(&one)
		unique := errors.Is(err, sql.ErrNoRows)
		if err != nil && !unique {
			return err
		}
		if _, err := ins.ExecContext(ctx, s.LinkID, db.TS(s.At), s.IPHash, s.IP, s.Country, s.CountryName,
			s.Region, s.City, s.Language, s.Destination, s.DeviceClass,
			s.OS, s.Browser, s.RefererHost, s.UserAgent, b2i(s.IsBot), b2i(unique)); err != nil {
			return err
		}
	}
	return tx.Commit()
}

func b2i(b bool) int {
	if b {
		return 1
	}
	return 0
}

// Purge deletes scans older than cutoff (in small batches so the writer is
// never starved) and daily salts older than three days. It returns the
// number of scan rows removed.
func Purge(ctx context.Context, d *sql.DB, cutoff, now time.Time) (int64, error) {
	var total int64
	for {
		res, err := d.ExecContext(ctx, `DELETE FROM scans WHERE id IN
			(SELECT id FROM scans WHERE scanned_at < ? LIMIT 5000)`, db.TS(cutoff))
		if err != nil {
			return total, err
		}
		n, _ := res.RowsAffected()
		total += n
		if n < 5000 {
			break
		}
	}
	_, err := d.ExecContext(ctx, `DELETE FROM daily_salts WHERE day < ?`,
		now.UTC().AddDate(0, 0, -3).Format("2006-01-02"))
	return total, err
}
