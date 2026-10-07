package db_test

import (
	"context"
	"io/fs"
	"path/filepath"
	"testing"
	"testing/fstest"

	qrtrack "github.com/BlakeUK/Blake-AI-Chatbot/qrcode"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/db"
)

// A database created by the first release, already holding an admin, a link
// and scans, must upgrade in place without losing anything.
func TestUpgradeFromFirstReleaseKeepsData(t *testing.T) {
	ctx := context.Background()
	d, err := db.Open(filepath.Join(t.TempDir(), "old.db"))
	if err != nil {
		t.Fatal(err)
	}
	defer d.Close()

	first, err := fs.ReadFile(qrtrack.Migrations, "migrations/0001_init.sql")
	if err != nil {
		t.Fatal(err)
	}
	v1 := fstest.MapFS{"migrations/0001_init.sql": {Data: first}}
	if err := db.Migrate(ctx, d, v1, "migrations"); err != nil {
		t.Fatal(err)
	}
	mustExec := func(q string, a ...any) {
		if _, err := d.ExecContext(ctx, q, a...); err != nil {
			t.Fatalf("%s: %v", q, err)
		}
	}
	mustExec(`INSERT INTO users(username,password_hash,must_change_password,created_at,updated_at) VALUES ('admin','$2a$hash',1,'2026-10-07T21:50:52Z','2026-10-07T21:50:52Z')`)
	mustExec(`INSERT INTO links(code,label,destination_url,track_start,track_end,created_at,updated_at) VALUES ('AAAAAAAA','Old link','https://www.facebook.com/x','2026-10-07T00:00:00Z','2026-11-07T00:00:00Z','2026-10-07T00:00:00Z','2026-10-07T00:00:00Z')`)
	mustExec(`INSERT INTO scans(link_id,scanned_at,ip_hash,country,device_class,os,browser,is_unique) VALUES (1,'2026-10-07T10:00:00Z','abc','GB','mobile','iOS','Safari',1)`)

	// Upgrade to the current schema.
	if err := db.Migrate(ctx, d, qrtrack.Migrations, "migrations"); err != nil {
		t.Fatalf("upgrade failed: %v", err)
	}
	var user, label, campaign, device, country, region, lang, dest, ip string
	var unique int
	d.QueryRow(`SELECT username FROM users`).Scan(&user)
	d.QueryRow(`SELECT label, campaign FROM links`).Scan(&label, &campaign)
	d.QueryRow(`SELECT device_class, country, region, language, destination_url, ip, is_unique FROM scans`).Scan(&device, &country, &region, &lang, &dest, &ip, &unique)
	if user != "admin" || label != "Old link" || campaign != "" || device != "mobile" || country != "GB" || unique != 1 {
		t.Errorf("data changed by the upgrade: %q %q %q %q %q %d", user, label, campaign, device, country, unique)
	}
	if region != "" || lang != "" || dest != "" || ip != "" {
		t.Errorf("new columns should default to empty for old rows: %q %q %q %q", region, lang, dest, ip)
	}
	// Idempotent: running migrations again changes nothing and does not error.
	if err := db.Migrate(ctx, d, qrtrack.Migrations, "migrations"); err != nil {
		t.Fatalf("second run: %v", err)
	}
	var applied int
	d.QueryRow(`SELECT COUNT(*) FROM schema_migrations`).Scan(&applied)
	if applied != 2 {
		t.Errorf("%d migrations recorded, want 2", applied)
	}
}
