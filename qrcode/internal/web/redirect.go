package web

import (
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/links"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/scans"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/ua"
)

// redirect is the hot path every QR scan takes. Order matters: cheap checks
// first, one indexed lookup, then a 302 to the stored destination. Logging
// the scan is a non-blocking channel send, so it can never slow the redirect.
// The target is only ever read from the database, never from the request, so
// this cannot be used as an open redirect.
func (s *Server) redirect(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	w.Header().Set("Cache-Control", "no-store")

	ip := s.clientIP(r)
	hash, herr := s.hasher.Hash(ctx, ip)
	if herr != nil {
		// Hashing needs the database; if it is unavailable we still prefer to
		// keep redirecting. We fall back to an unlogged, per-IP-less limiter key.
		s.log.Error("ip hash failed", "err", herr)
		hash = ""
	}
	if hash != "" && !s.limit.Allow(hash) {
		w.Header().Set("Retry-After", "60")
		s.errorPage(w, http.StatusTooManyRequests, "Too many requests", "Please wait a minute and try again.")
		return
	}

	code := r.PathValue("code")
	if !links.ValidCode(code) {
		s.errorPage(w, http.StatusNotFound, "Link not found", "This link does not exist.")
		return
	}
	l, err := s.links.ByCode(ctx, code)
	if err == links.ErrNotFound {
		s.errorPage(w, http.StatusNotFound, "Link not found", "This link does not exist.")
		return
	}
	if err != nil {
		s.log.Error("link lookup failed", "err", err)
		s.errorPage(w, http.StatusInternalServerError, "Something went wrong", "Please try again shortly.")
		return
	}
	if !l.Enabled {
		s.errorPage(w, http.StatusGone, "Link disabled", "This link has been switched off.")
		return
	}

	now := s.now()
	switch l.StatusAt(now) {
	case links.Active:
		// HEAD requests (prefetchers, uptime checks) are served but not counted.
		if r.Method == http.MethodGet && hash != "" {
			s.record(r, l.ID, ip, hash, now)
		}
		http.Redirect(w, r, l.DestinationURL, http.StatusFound)
	case links.Scheduled:
		// Before the window opens the link already works; it just isn't counted.
		http.Redirect(w, r, l.DestinationURL, http.StatusFound)
	default: // Ended: behaviour chosen per link
		switch l.ExpiryMode {
		case links.ShowExpiredPage:
			s.errorPage(w, http.StatusGone, "This link has expired", "This QR code is no longer active.")
		case links.RedirectFallbackURL:
			http.Redirect(w, r, l.FallbackURL, http.StatusFound)
		default:
			http.Redirect(w, r, l.DestinationURL, http.StatusFound)
		}
	}
}

func (s *Server) record(r *http.Request, linkID int64, ip, hash string, now time.Time) {
	agent := truncate(r.UserAgent(), 512)
	info := ua.Parse(agent)
	s.writer.Submit(scans.Scan{
		LinkID:      linkID,
		At:          now,
		IPHash:      hash,
		Country:     s.geo.Country(ip),
		DeviceClass: info.DeviceClass,
		OS:          info.OS,
		Browser:     info.Browser,
		RefererHost: refererHost(r.Referer()),
		UserAgent:   agent,
		IsBot:       info.IsBot,
	})
}

// refererHost keeps only the host of the Referer header; paths and query
// strings can carry personal data and are never stored.
func refererHost(ref string) string {
	if ref == "" {
		return ""
	}
	u, err := url.Parse(ref)
	if err != nil {
		return ""
	}
	return truncate(strings.ToLower(u.Hostname()), 253)
}

func truncate(s string, max int) string {
	if len(s) <= max {
		return s
	}
	return strings.ToValidUTF8(s[:max], "")
}
