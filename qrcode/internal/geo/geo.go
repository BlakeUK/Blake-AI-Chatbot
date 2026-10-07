// Package geo resolves an IP address to an ISO country code using an offline
// GeoLite2/GeoIP2 Country database. Nothing is sent to any third party, and
// only the two-letter country code is ever kept. Without a database file the
// resolver is a no-op that returns "".
package geo

import (
	"net"

	"github.com/oschwald/geoip2-golang"
)

type Resolver struct {
	r *geoip2.Reader
}

// Open opens the .mmdb at path. An empty path returns a no-op resolver.
func Open(path string) (*Resolver, error) {
	if path == "" {
		return &Resolver{}, nil
	}
	r, err := geoip2.Open(path)
	if err != nil {
		return nil, err
	}
	return &Resolver{r: r}, nil
}

// Country returns the ISO 3166-1 alpha-2 code for ip, or "" if unknown.
func (g *Resolver) Country(ip string) string {
	if g == nil || g.r == nil {
		return ""
	}
	parsed := net.ParseIP(ip)
	if parsed == nil {
		return ""
	}
	rec, err := g.r.Country(parsed)
	if err != nil || rec == nil {
		return ""
	}
	return rec.Country.IsoCode
}

func (g *Resolver) Close() {
	if g != nil && g.r != nil {
		g.r.Close()
	}
}
