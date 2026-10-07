package geo

import (
	"io"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestNoopAndStatic(t *testing.T) {
	var nilR *Resolver
	if nilR.Lookup("8.8.8.8") != (Location{}) || nilR.Active() {
		t.Error("nil resolver must be a harmless no-op")
	}
	empty, err := Open("")
	if err != nil || empty.Active() || empty.Lookup("8.8.8.8") != (Location{}) {
		t.Errorf("empty-path resolver: %v", err)
	}
	if ok, err := empty.Reload(); ok || err != nil {
		t.Error("reload of the no-op resolver must do nothing")
	}
	s := Static(map[string]Location{"1.2.3.4": {CountryISO: "GB", Country: "United Kingdom", City: "Leeds"}})
	if !s.Active() || s.Lookup("1.2.3.4").City != "Leeds" || s.Lookup("9.9.9.9") != (Location{}) {
		t.Error("static resolver")
	}
}

func TestOpenRefusesGarbageButReloadKeepsOldDatabase(t *testing.T) {
	bad := filepath.Join(t.TempDir(), "bad.mmdb")
	os.WriteFile(bad, []byte("this is not a database"), 0o600)
	if _, err := Open(bad); err == nil {
		t.Error("Open accepted a corrupt file")
	}
}

// The remaining checks need a real database; point QRTRACK_GEOIP_TEST_DB at a
// DB-IP City Lite (or GeoLite2 City) file to run them.
func realDB(t *testing.T) string {
	p := os.Getenv("QRTRACK_GEOIP_TEST_DB")
	if p == "" {
		t.Skip("set QRTRACK_GEOIP_TEST_DB to a City .mmdb to run this test")
	}
	return p
}

func TestRealDatabaseLookups(t *testing.T) {
	g, err := Open(realDB(t))
	if err != nil {
		t.Fatal(err)
	}
	defer g.Close()
	if !g.Active() || g.DatabaseType() == "" {
		t.Error("database not active")
	}
	l := g.Lookup("81.2.69.142")
	if l.CountryISO != "GB" || l.Country != "United Kingdom" || l.City != "London" || l.Region != "England" {
		t.Errorf("81.2.69.142 = %+v, want London, England, GB", l)
	}
	if us := g.Lookup("8.8.8.8"); us.CountryISO != "US" {
		t.Errorf("8.8.8.8 = %+v", us)
	}
	if v6 := g.Lookup("2a00:1450:4009:81f::200e"); v6.CountryISO == "" {
		t.Errorf("IPv6 not resolved: %+v", v6)
	}
	for _, private := range []string{"192.168.1.1", "10.0.0.1", "127.0.0.1", "not-an-ip", ""} {
		if g.Lookup(private) != (Location{}) {
			t.Errorf("%q must resolve to nothing", private)
		}
	}
}

func TestReloadSwapsOnChangeAndSurvivesABadFile(t *testing.T) {
	src := realDB(t)
	dir := t.TempDir()
	path := filepath.Join(dir, "city.mmdb")
	// Replace the way the real updater does: write a new file, then rename it
	// over the old one. Overwriting a memory-mapped database in place would
	// crash the process, so the tracker's contract is "replace, never edit".
	replaceWith := func(from string, modtime time.Time) {
		in, err := os.Open(from)
		if err != nil {
			t.Fatal(err)
		}
		defer in.Close()
		tmp := path + ".new"
		out, _ := os.Create(tmp)
		io.Copy(out, in)
		out.Close()
		os.Chtimes(tmp, modtime, modtime)
		if err := os.Rename(tmp, path); err != nil {
			t.Fatal(err)
		}
	}
	replaceWith(src, time.Now())
	g, err := Open(path)
	if err != nil {
		t.Fatal(err)
	}
	defer g.Close()

	if changed, err := g.Reload(); changed || err != nil {
		t.Errorf("unchanged file reloaded: %v %v", changed, err)
	}
	// A corrupt replacement is rejected and the previous database keeps working.
	corrupt := filepath.Join(dir, "corrupt")
	os.WriteFile(corrupt, []byte("corrupt"), 0o600)
	replaceWith(corrupt, time.Now().Add(time.Hour))
	if changed, err := g.Reload(); changed || err == nil {
		t.Errorf("corrupt file: changed=%v err=%v, want rejected", changed, err)
	}
	if g.Lookup("81.2.69.142").City != "London" {
		t.Error("lookups stopped working after a bad update")
	}
	// A good replacement is picked up.
	replaceWith(src, time.Now().Add(2*time.Hour))
	if changed, err := g.Reload(); !changed || err != nil {
		t.Errorf("good replacement: changed=%v err=%v", changed, err)
	}
	if g.Lookup("81.2.69.142").City != "London" {
		t.Error("lookups wrong after reload")
	}
}
