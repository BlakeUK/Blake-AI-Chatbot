// Package db opens the SQLite database and applies embedded, versioned migrations.
package db

import (
	"context"
	"crypto/rand"
	"database/sql"
	"errors"
	"fmt"
	"io/fs"
	"net/url"
	"sort"
	"strings"
	"time"

	_ "modernc.org/sqlite" // pure-Go driver, so the binary builds with CGO_ENABLED=0
)

// Open opens (creating if needed) the database at path. WAL mode lets the
// redirect path read while the single scan-writer goroutine commits; every
// transaction is IMMEDIATE so writers queue on busy_timeout instead of
// failing with SQLITE_BUSY on lock upgrade.
func Open(path string) (*sql.DB, error) {
	q := url.Values{}
	q.Add("_pragma", "busy_timeout(5000)")
	q.Add("_pragma", "journal_mode(WAL)")
	q.Add("_pragma", "foreign_keys(1)")
	q.Add("_pragma", "synchronous(NORMAL)")
	q.Set("_txlock", "immediate")
	d, err := sql.Open("sqlite", "file:"+path+"?"+q.Encode())
	if err != nil {
		return nil, err
	}
	d.SetMaxOpenConns(8)
	d.SetConnMaxLifetime(0)
	if err := d.Ping(); err != nil {
		d.Close()
		return nil, err
	}
	return d, nil
}

// Migrate applies every NNNN_name.sql file in dir of fsys that has not been
// applied yet, each inside its own transaction, in filename order.
func Migrate(ctx context.Context, d *sql.DB, fsys fs.FS, dir string) error {
	if _, err := d.ExecContext(ctx, `CREATE TABLE IF NOT EXISTS schema_migrations (
		version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)`); err != nil {
		return err
	}
	entries, err := fs.ReadDir(fsys, dir)
	if err != nil {
		return err
	}
	var names []string
	for _, e := range entries {
		if !e.IsDir() && strings.HasSuffix(e.Name(), ".sql") {
			names = append(names, e.Name())
		}
	}
	sort.Strings(names)
	for _, name := range names {
		var n int
		if err := d.QueryRowContext(ctx, `SELECT COUNT(*) FROM schema_migrations WHERE version = ?`, name).Scan(&n); err != nil {
			return err
		}
		if n > 0 {
			continue
		}
		body, err := fs.ReadFile(fsys, dir+"/"+name)
		if err != nil {
			return err
		}
		tx, err := d.BeginTx(ctx, nil)
		if err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, string(body)); err != nil {
			tx.Rollback()
			return fmt.Errorf("migration %s: %w", name, err)
		}
		if _, err := tx.ExecContext(ctx, `INSERT INTO schema_migrations(version, applied_at) VALUES (?, ?)`,
			name, time.Now().UTC().Format(time.RFC3339)); err != nil {
			tx.Rollback()
			return err
		}
		if err := tx.Commit(); err != nil {
			return err
		}
	}
	return nil
}

// Secret returns the 32-byte server secret stored under key, creating it on first use.
func Secret(ctx context.Context, d *sql.DB, key string) ([]byte, error) {
	var v []byte
	err := d.QueryRowContext(ctx, `SELECT value FROM settings WHERE key = ?`, key).Scan(&v)
	if err == nil {
		return v, nil
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return nil, err
	}
	nv := make([]byte, 32)
	if _, err := rand.Read(nv); err != nil {
		return nil, err
	}
	// INSERT OR IGNORE then re-read, so two concurrent first runs agree on one value.
	if _, err := d.ExecContext(ctx, `INSERT OR IGNORE INTO settings(key, value) VALUES (?, ?)`, key, nv); err != nil {
		return nil, err
	}
	if err := d.QueryRowContext(ctx, `SELECT value FROM settings WHERE key = ?`, key).Scan(&v); err != nil {
		return nil, err
	}
	return v, nil
}

// TS formats t as the canonical stored timestamp (UTC, RFC3339, second precision).
func TS(t time.Time) string { return t.UTC().Format(time.RFC3339) }

// ParseTS parses a stored timestamp.
func ParseTS(s string) (time.Time, error) { return time.Parse(time.RFC3339, s) }
