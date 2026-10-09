package web

import (
	"fmt"
	"net/http"
	"net/url"
	"regexp"
	"strings"
	"time"

	"golang.org/x/crypto/bcrypt"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/geo"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/links"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/scans"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/ua"
)

// visitor is what we know about the person scanning, used for smart rules and
// recorded with the scan.
type visitor struct {
	ip, hash string
	info     ua.Info
	loc      geo.Location
	lang     string
}

// redirect is the hot path every QR scan takes. Order matters: cheap checks
// first, one indexed lookup, then a 302 to the stored destination. Logging the
// scan is a non-blocking channel send, so it can never slow the redirect. The
// target only ever comes from the database, never from the request, so this
// cannot be used as an open redirect. Static codes never reach this handler:
// they hold their content directly and are not tracked.
func (s *Server) redirect(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	w.Header().Set("Cache-Control", "no-store")

	ip := s.clientIP(r)
	hash, herr := s.hasher.Hash(ctx, ip)
	if herr != nil {
		// Hashing needs the database; if it is unavailable we still prefer to
		// keep redirecting, just without logging or per-visitor limits.
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
	if l.IsStatic() {
		s.errorPage(w, http.StatusNotFound, "Link not found", "This is a static QR code. It does not use this address.")
		return
	}
	if !l.Enabled {
		s.errorPage(w, http.StatusGone, "Link disabled", "This link has been switched off.")
		return
	}
	// POST exists only to submit the password form of a protected code. Anywhere
	// else it would let a script run up the scan count without being a scan.
	if r.Method == http.MethodPost && l.PasswordHash == "" {
		w.Header().Set("Allow", "GET, HEAD")
		s.errorPage(w, http.StatusMethodNotAllowed, "Not allowed", "This address only accepts a normal visit.")
		return
	}

	now := s.now()
	status := l.StatusAt(now)
	// A scan limit ends the code exactly like the end of its window does.
	if status == links.Active && l.MaxScans > 0 && l.ScanCount >= l.MaxScans {
		status = links.Ended
	}
	if status == links.Ended && s.ended(w, r, l) {
		return
	}

	v := visitor{ip: ip, hash: hash, info: ua.Parse(truncate(r.UserAgent(), 512)), loc: s.geo.Lookup(ip), lang: parseLanguage(r.Header.Get("Accept-Language"))}
	if l.PasswordHash != "" && !s.unlocked(w, r, l, hash) {
		return
	}
	s.deliver(w, r, l, v, status == links.Active, now)
}

// ended applies the link's chosen behaviour once its window or scan limit is over. It reports whether it
// answered the visitor. In the "keep redirecting, no longer counted" mode it does not: that code is still
// a working code, so the visitor goes through the normal path (password, routing rules) and is just not counted.
func (s *Server) ended(w http.ResponseWriter, r *http.Request, l *links.Link) bool {
	switch l.ExpiryMode {
	case links.ShowExpiredPage:
		s.errorPage(w, http.StatusGone, "This link has expired", "This QR code is no longer active.")
		return true
	case links.RedirectFallbackURL:
		http.Redirect(w, r, l.FallbackURL, http.StatusFound)
		return true
	}
	return false
}

// deliver sends the visitor where this code points and, while the code is
// being tracked, records the scan.
func (s *Server) deliver(w http.ResponseWriter, r *http.Request, l *links.Link, v visitor, counted bool, now time.Time) {
	// HEAD requests (prefetchers, uptime checks) are served but never counted.
	count := counted && r.Method != http.MethodHead && v.hash != ""
	if l.DestinationURL == "" && l.Content != "" { // a vCard or calendar event
		if count {
			s.record(r, l, "", v, now)
		}
		s.serveDocument(w, r, l)
		return
	}
	target := l.DestinationURL
	if l.HasRules {
		if rules, err := s.links.Rules(r.Context(), l.ID); err == nil {
			if t, ok := matchRule(rules, v, now, l.ID); ok {
				target = t
			}
		}
	}
	if count {
		s.record(r, l, target, v, now)
	}
	status := http.StatusFound
	if r.Method == http.MethodPost { // after a password form: do not resubmit it
		status = http.StatusSeeOther
	}
	http.Redirect(w, r, target, status)
}

// matchRule returns the address of the first rule the visitor satisfies.
func matchRule(rules []links.Rule, v visitor, now time.Time, linkID int64) (string, bool) {
	for i, ru := range rules {
		var hit bool
		switch ru.Match {
		case "time":
			if tr, _, err := links.ParseTimeRule(ru.Value); err == nil {
				hit = tr.Match(now)
			}
		case "split":
			if pct, _, err := links.ParseSplit(ru.Value); err == nil {
				hit = links.SplitHit(pct, v.hash, linkID, i)
			}
		case "os":
			hit = strings.EqualFold(v.info.OS, ru.Value)
		case "device":
			hit = strings.EqualFold(v.info.DeviceClass, ru.Value)
		case "country":
			hit = strings.EqualFold(v.loc.CountryISO, ru.Value)
		case "language":
			want, have := strings.ToLower(ru.Value), strings.ToLower(v.lang)
			hit = have != "" && (have == want || strings.HasPrefix(have, want+"-"))
		}
		if hit {
			return ru.URL, true
		}
	}
	return "", false
}

// serveDocument returns a vCard or calendar event so the phone offers to save it.
func (s *Server) serveDocument(w http.ResponseWriter, r *http.Request, l *links.Link) {
	ct, name := "text/vcard; charset=utf-8", "contact.vcf"
	if l.QRType == "event" {
		ct, name = "text/calendar; charset=utf-8", "event.ics"
	}
	w.Header().Set("Content-Type", ct)
	w.Header().Set("Content-Disposition", fmt.Sprintf(`inline; filename="%s"`, name))
	if r.Method != http.MethodHead {
		w.Write([]byte(l.Content))
	}
}

// unlocked returns true when the visitor may proceed. For a password-protected
// code it shows the password page, and checks the answer on POST. Wrong
// answers are throttled per visitor and per code.
func (s *Server) unlocked(w http.ResponseWriter, r *http.Request, l *links.Link, hash string) bool {
	page := func(status int, msg, csrf string) {
		s.render(w, status, "unlock", pageData{Title: "Protected", CSRF: csrf, Error: msg, Data: map[string]string{"Code": l.Code}})
	}
	if r.Method != http.MethodPost {
		tok := randString()
		s.setCookie(w, unlockCookie, tok, "/r/", s.now().Add(30*time.Minute))
		if r.Method == http.MethodHead {
			w.WriteHeader(http.StatusOK)
			return false
		}
		page(http.StatusOK, "", tok)
		return false
	}
	c, err := r.Cookie(unlockCookie)
	if err != nil || !eq(r.PostFormValue("csrf"), c.Value) {
		s.errorPage(w, http.StatusForbidden, "Forbidden", "That page expired. Scan the code again.")
		return false
	}
	if hash != "" && !s.pwLimit.Allow(hash+l.Code) {
		w.Header().Set("Retry-After", "60")
		s.errorPage(w, http.StatusTooManyRequests, "Too many attempts", "Please wait a minute before trying again.")
		return false
	}
	pw := r.PostFormValue("password")
	if len(pw) > 72 || bcrypt.CompareHashAndPassword([]byte(l.PasswordHash), []byte(pw)) != nil {
		tok := randString()
		s.setCookie(w, unlockCookie, tok, "/r/", s.now().Add(30*time.Minute))
		page(http.StatusUnauthorized, "That password is not right.", tok)
		return false
	}
	return true
}

func (s *Server) record(r *http.Request, l *links.Link, target string, v visitor, now time.Time) {
	sc := scans.Scan{
		LinkID:      l.ID,
		At:          now,
		IPHash:      v.hash,
		Country:     v.loc.CountryISO,
		CountryName: v.loc.Country,
		Region:      v.loc.Region,
		City:        v.loc.City,
		Language:    v.lang,
		Destination: target, // what this scan was actually sent to (rules can differ per visitor)
		DeviceClass: v.info.DeviceClass,
		OS:          v.info.OS,
		Browser:     v.info.Browser,
		RefererHost: refererHost(r.Referer()),
		UserAgent:   truncate(r.UserAgent(), 512),
		IsBot:       v.info.IsBot,
	}
	// The full address is kept only if the operator has switched that on.
	if s.cfg.StoreFullIP {
		sc.IP = v.ip
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
