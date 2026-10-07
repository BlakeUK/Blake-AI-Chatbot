// Package geo resolves an IP address to an approximate place using an offline
// IP-geolocation database in MaxMind .mmdb format (DB-IP "City Lite", or
// GeoLite2 City). Nothing is ever sent to a third party. Without a database
// the resolver is a no-op that returns an empty Location.
//
// Locations are approximate: country is usually right, town is often only the
// nearest large town, and mobile networks can be placed in the wrong region.
package geo

import (
	"fmt"
	"net"
	"os"
	"strings"
	"sync"
	"time"

	"github.com/oschwald/geoip2-golang"
)

// Location is an approximate place. Any field may be empty.
type Location struct {
	CountryISO string // e.g. "GB"
	Country    string // e.g. "United Kingdom"
	Region     string // e.g. "England"
	City       string // e.g. "London"
}

type Resolver struct {
	mu     sync.RWMutex
	path   string
	r      *geoip2.Reader
	loaded time.Time // modification time of the file currently loaded
	static map[string]Location
}

// Open opens the database at path. An empty path returns a no-op resolver.
func Open(path string) (*Resolver, error) {
	g := &Resolver{path: path}
	if path == "" {
		return g, nil
	}
	if _, err := g.Reload(); err != nil {
		return nil, err
	}
	return g, nil
}

// Static returns a resolver backed by a fixed table. It exists for tests.
func Static(m map[string]Location) *Resolver { return &Resolver{static: m} }

// Active reports whether a real database is loaded (or a static table is set).
func (g *Resolver) Active() bool {
	if g == nil {
		return false
	}
	g.mu.RLock()
	defer g.mu.RUnlock()
	return g.r != nil || g.static != nil
}

// Reload re-reads the database if the file has changed since it was loaded,
// swapping it in atomically. If the new file cannot be opened the old
// database stays in use, so a bad download can never blind the tracker.
// It reports whether a new database was loaded.
func (g *Resolver) Reload() (bool, error) {
	if g == nil || g.path == "" {
		return false, nil
	}
	st, err := os.Stat(g.path)
	if err != nil {
		return false, err
	}
	g.mu.RLock()
	same := g.r != nil && st.ModTime().Equal(g.loaded)
	g.mu.RUnlock()
	if same {
		return false, nil
	}
	fresh, err := geoip2.Open(g.path)
	if err != nil {
		return false, fmt.Errorf("open %s: %w", g.path, err)
	}
	g.mu.Lock()
	old := g.r
	g.r, g.loaded = fresh, st.ModTime()
	g.mu.Unlock()
	if old != nil {
		old.Close()
	}
	return true, nil
}

// DatabaseType describes the loaded database, e.g. "DBIP-City-Lite".
func (g *Resolver) DatabaseType() string {
	if g == nil {
		return ""
	}
	g.mu.RLock()
	defer g.mu.RUnlock()
	if g.r == nil {
		return ""
	}
	return g.r.Metadata().DatabaseType
}

// Lookup returns the approximate location of ip, or an empty Location.
func (g *Resolver) Lookup(ip string) Location {
	if g == nil {
		return Location{}
	}
	g.mu.RLock()
	defer g.mu.RUnlock()
	if g.static != nil {
		return g.static[ip]
	}
	if g.r == nil {
		return Location{}
	}
	parsed := net.ParseIP(ip)
	if parsed == nil {
		return Location{}
	}
	if strings.Contains(g.r.Metadata().DatabaseType, "Country") {
		rec, err := g.r.Country(parsed)
		if err != nil || rec == nil {
			return Location{}
		}
		return Location{CountryISO: rec.Country.IsoCode, Country: rec.Country.Names["en"]}
	}
	rec, err := g.r.City(parsed)
	if err != nil || rec == nil {
		return Location{}
	}
	loc := Location{CountryISO: rec.Country.IsoCode, Country: rec.Country.Names["en"], City: rec.City.Names["en"]}
	if len(rec.Subdivisions) > 0 {
		loc.Region = rec.Subdivisions[0].Names["en"]
	}
	return loc
}

func (g *Resolver) Close() {
	if g == nil {
		return
	}
	g.mu.Lock()
	defer g.mu.Unlock()
	if g.r != nil {
		g.r.Close()
		g.r = nil
	}
}
