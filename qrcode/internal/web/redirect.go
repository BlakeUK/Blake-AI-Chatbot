package web

import (
	"net/http"
	"net/url"
	"regexp"
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
			s.record(r, l, ip, hash, now)
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

func (s *Server) record(r *http.Request, l *links.Link, ip, hash string, now time.Time) {
	agent := truncate(r.UserAgent(), 512)
	info := ua.Parse(agent)
	loc := s.geo.Lookup(ip)
	sc := scans.Scan{
		LinkID:      l.ID,
		At:          now,
		IPHash:      hash,
		Country:     loc.CountryISO,
		CountryName: loc.Country,
		Region:      loc.Region,
		City:        loc.City,
		Language:    parseLanguage(r.Header.Get("Accept-Language")),
		Destination: l.DestinationURL, // a snapshot: the link can be edited later
		DeviceClass: info.DeviceClass,
		OS:          info.OS,
		Browser:     info.Browser,
		RefererHost: refererHost(r.Referer()),
		UserAgent:   agent,
		IsBot:       info.IsBot,
	}
	// The full address is kept only if the operator has switched that on.
	if s.cfg.StoreFullIP {
		sc.IP = ip
	}
	s.writer.Submit(sc)
}

var langTag = regexp.MustCompile(`^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8}){0,3}$`)

// parseLanguage returns the visitor's preferred language from Accept-Language
// ("en-GB,en;q=0.9" gives "en-GB"), normalised so en-gb and en-GB count as one.
// Anything that is not a plausible language tag is dropped.
func parseLanguage(h string) string {
	first := strings.TrimSpace(strings.SplitN(h, ",", 2)[0])
	first = strings.TrimSpace(strings.SplitN(first, ";", 2)[0])
	if len(first) > 35 || !langTag.MatchString(first) {
		return ""
	}
	parts := strings.Split(first, "-")
	parts[0] = strings.ToLower(parts[0])
	for i := 1; i < len(parts); i++ {
		switch {
		case len(parts[i]) == 2 && isAlpha(parts[i]):
			parts[i] = strings.ToUpper(parts[i])
		case len(parts[i]) == 4 && isAlpha(parts[i]):
			parts[i] = strings.ToUpper(parts[i][:1]) + strings.ToLower(parts[i][1:])
		}
	}
	return strings.Join(parts, "-")
}

func isAlpha(s string) bool {
	for _, r := range s {
		if (r < 'a' || r > 'z') && (r < 'A' || r > 'Z') {
			return false
		}
	}
	return true
}

var languageNames = map[string]string{
	"en": "English", "fr": "French", "de": "German", "es": "Spanish", "it": "Italian", "nl": "Dutch", "pt": "Portuguese",
	"pl": "Polish", "ro": "Romanian", "ru": "Russian", "uk": "Ukrainian", "tr": "Turkish", "ar": "Arabic", "he": "Hebrew",
	"zh": "Chinese", "ja": "Japanese", "ko": "Korean", "hi": "Hindi", "ur": "Urdu", "bn": "Bengali", "pa": "Punjabi",
	"cy": "Welsh", "ga": "Irish", "gd": "Scottish Gaelic", "sv": "Swedish", "da": "Danish", "nb": "Norwegian", "no": "Norwegian",
	"fi": "Finnish", "cs": "Czech", "sk": "Slovak", "hu": "Hungarian", "el": "Greek", "bg": "Bulgarian", "lt": "Lithuanian",
	"lv": "Latvian", "et": "Estonian", "hr": "Croatian", "sr": "Serbian", "sl": "Slovenian", "id": "Indonesian", "vi": "Vietnamese",
	"th": "Thai", "fa": "Persian", "sw": "Swahili", "so": "Somali", "gu": "Gujarati", "ta": "Tamil", "te": "Telugu",
}

// languageLabel renders a tag for people: "en-GB" becomes "English (en-GB)".
func languageLabel(tag string) string {
	if tag == "" {
		return ""
	}
	if n, ok := languageNames[strings.ToLower(strings.SplitN(tag, "-", 2)[0])]; ok {
		return n + " (" + tag + ")"
	}
	return tag
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
