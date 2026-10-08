// Package web is the HTTP layer: the public redirect endpoint and the admin UI.
package web

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/subtle"
	"database/sql"
	"encoding/base64"
	"errors"
	"fmt"
	"html/template"
	"io/fs"
	"log/slog"
	"net"
	"net/http"
	"net/url"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/auth"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/geo"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/links"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/pages"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/scans"
)

const (
	sessionCookie = "qrtrack_session"
	preCSRFCookie = "qrtrack_csrf"
	unlockCookie  = "qrtrack_unlock"
	perPage       = 25
)

type Config struct {
	BaseURL         string // public origin, no trailing slash, e.g. https://qr.example.com
	TrustedProxies  []*net.IPNet
	RateLimitPerMin int  // per hashed client, on /r/<code>
	StoreFullIP     bool // keep the visitor address with each scan (off by default)
	RetentionDays   int  // how long scans, page events and the activity log are kept; shown in Help (main runs the purge)
	Location        *time.Location
}

type Server struct {
	cfg        Config
	log        *slog.Logger
	db         *sql.DB
	links      *links.Store
	auth       *auth.Service
	hasher     *scans.Hasher
	writer     *scans.Writer
	geo        *geo.Resolver
	limit      *limiter
	pwLimit    *limiter // wrong-password attempts on protected codes
	pages      *pages.Store
	pageEvents *pages.Writer
	public     *template.Template // the standalone public link page
	now        func() time.Time
	tmpl       map[string]*template.Template
	assets     fs.FS
	secure     bool
	host       string
}

type Deps struct {
	DB         *sql.DB
	Links      *links.Store
	Auth       *auth.Service
	Hasher     *scans.Hasher
	Writer     *scans.Writer
	Geo        *geo.Resolver
	Now        func() time.Time
	Log        *slog.Logger
	Assets     fs.FS
	Pages      *pages.Store  // optional: created from DB when nil
	PageEvents *pages.Writer // optional: page views and clicks are simply not counted when nil
}

func New(cfg Config, d Deps) (*Server, error) {
	u, err := url.Parse(cfg.BaseURL)
	if err != nil || u.Host == "" || (u.Scheme != "http" && u.Scheme != "https") {
		return nil, fmt.Errorf("BASE_URL %q must be an absolute http(s) URL", cfg.BaseURL)
	}
	if cfg.Location == nil {
		cfg.Location = time.UTC
	}
	if cfg.RateLimitPerMin <= 0 {
		cfg.RateLimitPerMin = 30
	}
	if d.Now == nil {
		d.Now = time.Now
	}
	if d.Pages == nil {
		d.Pages = pages.NewStore(d.DB)
	}
	s := &Server{
		pages: d.Pages, pageEvents: d.PageEvents,
		cfg: cfg, log: d.Log, db: d.DB, links: d.Links, auth: d.Auth, hasher: d.Hasher,
		writer: d.Writer, geo: d.Geo, now: d.Now, assets: d.Assets,
		secure: u.Scheme == "https", host: u.Host,
		limit:   newLimiter(cfg.RateLimitPerMin, d.Now),
		pwLimit: newLimiter(6, d.Now),
	}
	if err := s.loadTemplates(); err != nil {
		return nil, err
	}
	return s, nil
}

func (s *Server) loadTemplates() error {
	funcs := template.FuncMap{
		"dt":      func(t time.Time) string { return t.In(s.cfg.Location).Format("02 Jan 2006 15:04") },
		"dtShort": func(t time.Time) string { return t.In(s.cfg.Location).Format("02 Jan 15:04") },
		"dtSec":   func(t time.Time) string { return t.In(s.cfg.Location).Format("02 Jan 2006 15:04:05") },
		"site":    links.SiteName,
		"lang":    languageLabel,
		"place": func(r scans.Row) string {
			var p []string
			for _, v := range []string{r.City, r.Region, r.CountryName} {
				if v != "" {
					p = append(p, v)
				}
			}
			if len(p) == 0 {
				return "unknown"
			}
			return strings.Join(p, ", ")
		},
		"trunc": func(n int, v string) string {
			if utf8.RuneCountInString(v) <= n {
				return v
			}
			r := []rune(v)
			return string(r[:n]) + "…"
		},
		"pct":  func(f float64) string { return fmt.Sprintf("%.1f", f) },
		"prev": func(p int) int { return p - 1 },
		"next": func(p int) int { return p + 1 },
	}
	pages := []string{"pages", "page_form", "page_detail", "login", "password", "links", "link_choose", "link_form", "link_detail", "campaigns", "users", "bulk", "templates", "template_form", "help", "unlock", "error"}
	s.tmpl = map[string]*template.Template{}
	for _, p := range pages {
		t, err := template.New("").Funcs(funcs).ParseFS(s.assets, "web/templates/layout.html", "web/templates/design_card.html", "web/templates/"+p+".html")
		if err != nil {
			return fmt.Errorf("template %s: %w", p, err)
		}
		s.tmpl[p] = t
	}
	pub, err := template.New("").Funcs(funcs).ParseFS(s.assets, "web/templates/public_page.html")
	if err != nil {
		return fmt.Errorf("template public: %w", err)
	}
	s.public = pub
	return nil
}

// Handler returns the fully wrapped HTTP handler.
func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()

	static, _ := fs.Sub(s.assets, "web/static")
	mux.Handle("GET /static/", http.StripPrefix("/static/", cacheFor(time.Hour, http.FileServer(http.FS(static)))))
	mux.HandleFunc("GET /robots.txt", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/plain; charset=utf-8")
		fmt.Fprint(w, "User-agent: *\nDisallow: /\n")
	})
	mux.HandleFunc("GET /healthz", s.health)
	mux.HandleFunc("GET /{$}", func(w http.ResponseWriter, r *http.Request) { http.Redirect(w, r, "/admin/", http.StatusFound) })
	mux.HandleFunc("GET /r/{code}", s.redirect)
	mux.HandleFunc("GET /l/{slug}", s.publicPage)
	mux.HandleFunc("GET /l/{slug}/theme.css", s.pageCSS)
	mux.HandleFunc("GET /l/{slug}/go/{id}", s.pageGo)
	mux.HandleFunc("POST /r/{code}", s.redirect) // password form for protected codes

	mux.HandleFunc("GET /admin/login", s.loginForm)
	mux.HandleFunc("POST /admin/login", s.loginSubmit)
	mux.HandleFunc("GET /admin", func(w http.ResponseWriter, r *http.Request) { http.Redirect(w, r, "/admin/", http.StatusFound) })
	mux.HandleFunc("POST /admin/logout", s.authed(s.logout))
	mux.HandleFunc("GET /admin/password", s.authed(s.passwordForm))
	mux.HandleFunc("POST /admin/password", s.authed(s.passwordSubmit))
	mux.HandleFunc("GET /admin/{$}", s.authed(s.list))
	mux.HandleFunc("GET /admin/campaigns", s.authed(s.campaigns))
	mux.HandleFunc("GET /admin/scans.csv", s.authed(s.csvExportAll))
	mux.HandleFunc("GET /admin/links/new", s.authed(s.newLink))
	mux.HandleFunc("GET /admin/preview.svg", s.authed(s.preview))
	mux.HandleFunc("GET /admin/help", s.authed(s.help))
	mux.HandleFunc("GET /admin/manual.pdf", s.authed(s.manual))
	mux.HandleFunc("GET /admin/pages", s.authed(s.pagesList))
	mux.HandleFunc("GET /admin/pages/new", s.authed(s.pageNew))
	mux.HandleFunc("POST /admin/pages", s.authed(s.pageCreate))
	mux.HandleFunc("GET /admin/pages/{id}", s.authed(s.pageDetail))
	mux.HandleFunc("GET /admin/pages/{id}/edit", s.authed(s.pageEdit))
	mux.HandleFunc("POST /admin/pages/{id}", s.authed(s.pageUpdate))
	mux.HandleFunc("POST /admin/pages/{id}/toggle", s.authed(s.pageToggle))
	mux.HandleFunc("POST /admin/pages/{id}/delete", s.authed(s.pageDelete))
	mux.HandleFunc("POST /admin/pages/{id}/qr", s.authed(s.pageQR))
	mux.HandleFunc("GET /admin/pages/preview", s.authed(s.previewPage))
	mux.HandleFunc("GET /admin/pages/preview.css", s.authed(s.previewCSS))
	mux.HandleFunc("GET /admin/templates", s.authed(s.templatesPage))
	mux.HandleFunc("GET /admin/templates/new", s.authed(s.designNew))
	mux.HandleFunc("POST /admin/templates", s.authed(s.designSave))
	mux.HandleFunc("POST /admin/templates/{id}/delete", s.authed(s.deleteTemplate))
	mux.HandleFunc("GET /admin/bulk", s.authed(s.bulkForm))
	mux.HandleFunc("POST /admin/bulk", s.authed(s.bulkCreate))
	mux.HandleFunc("GET /admin/users", s.adminOnly(s.usersList))
	mux.HandleFunc("POST /admin/users", s.adminOnly(s.userCreate))
	mux.HandleFunc("POST /admin/users/{id}/reset", s.adminOnly(s.userReset))
	mux.HandleFunc("POST /admin/users/{id}/delete", s.adminOnly(s.userDelete))
	mux.HandleFunc("POST /admin/users/{id}/role", s.adminOnly(s.userRole))
	mux.HandleFunc("POST /admin/links", s.authed(s.create))
	mux.HandleFunc("GET /admin/links/{id}", s.authed(s.detail))
	mux.HandleFunc("GET /admin/links/{id}/edit", s.authed(s.editForm))
	mux.HandleFunc("POST /admin/links/{id}", s.authed(s.update))
	mux.HandleFunc("POST /admin/links/{id}/toggle", s.authed(s.toggle))
	mux.HandleFunc("POST /admin/links/{id}/delete", s.authed(s.remove))
	mux.HandleFunc("GET /admin/links/{id}/qr.png", s.authed(s.qrPNG))
	mux.HandleFunc("GET /admin/links/{id}/qr.svg", s.authed(s.qrSVG))
	mux.HandleFunc("GET /admin/links/{id}/scans.csv", s.authed(s.csvExport))

	mux.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		s.errorPage(w, http.StatusNotFound, "Not found", "There is nothing at this address.")
	})
	return s.recoverer(s.headers(s.limitBody(mux)))
}

// ---------- middleware ----------

func (s *Server) headers(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		h := w.Header()
		h.Set("Content-Security-Policy", s.csp())
		h.Set("X-Content-Type-Options", "nosniff")
		h.Set("Referrer-Policy", "strict-origin-when-cross-origin")
		h.Set("X-Frame-Options", "DENY")
		h.Set("X-Robots-Tag", "noindex, nofollow")
		if s.secure {
			h.Set("Strict-Transport-Security", "max-age=15552000")
		}
		if strings.HasPrefix(r.URL.Path, "/admin") {
			h.Set("Cache-Control", "no-store")
		}
		next.ServeHTTP(w, r)
	})
}

// csp is the Content-Security-Policy sent with every page: scripts, styles and
// images only from this site, no framing.
func (s *Server) csp() string {
	return "default-src 'self'; img-src 'self'; style-src 'self'; script-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'"
}

func (s *Server) limitBody(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		limit := int64(64 << 10)
		if r.Method == http.MethodPost && (strings.HasPrefix(r.URL.Path, "/admin/links") || r.URL.Path == "/admin/bulk" || r.URL.Path == "/admin/templates") {
			limit = 3 << 20 // a logo (up to 1 MB) or a CSV file
		}
		r.Body = http.MaxBytesReader(w, r.Body, limit)
		next.ServeHTTP(w, r)
	})
}

func (s *Server) recoverer(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		defer func() {
			if v := recover(); v != nil {
				s.log.Error("panic in handler", "path", r.URL.Path, "panic", v)
				s.errorPage(w, http.StatusInternalServerError, "Something went wrong", "The request could not be completed.")
			}
		}()
		next.ServeHTTP(w, r)
	})
}

func cacheFor(d time.Duration, h http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Cache-Control", fmt.Sprintf("public, max-age=%d", int(d.Seconds())))
		h.ServeHTTP(w, r)
	})
}

// ---------- client address ----------

func (s *Server) trusted(ip net.IP) bool {
	for _, n := range s.cfg.TrustedProxies {
		if n.Contains(ip) {
			return true
		}
	}
	return false
}

// clientIP returns the caller's address. X-Forwarded-For is consulted only
// when the TCP peer is a trusted proxy; the list is then read right to left
// and the first address that is not itself a trusted proxy is the client, so
// a forged leftmost entry from the client is never believed.
func (s *Server) clientIP(r *http.Request) string {
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		host = r.RemoteAddr
	}
	peer := net.ParseIP(host)
	if peer == nil || !s.trusted(peer) {
		return host
	}
	parts := strings.Split(strings.Join(r.Header.Values("X-Forwarded-For"), ","), ",")
	for i := len(parts) - 1; i >= 0; i-- {
		p := net.ParseIP(strings.TrimSpace(parts[i]))
		if p == nil {
			continue
		}
		if !s.trusted(p) {
			return p.String()
		}
	}
	return peer.String()
}

// ---------- rendering ----------

type pageData struct {
	Title string
	User  *auth.User
	CSRF  string
	Flash string
	Error string
	Data  any
	Help  *helpInfo // the "What this is / How to use it" box; set automatically by render

	GeoCredit bool // show the DB-IP attribution its licence requires
	FullIP    bool // full IP storage is switched on: say so on every page
}

func (s *Server) render(w http.ResponseWriter, status int, page string, pd pageData) {
	if h, ok := pageHelp[page]; ok && pd.Help == nil {
		pd.Help = &h
	}
	t, ok := s.tmpl[page]
	if !ok {
		http.Error(w, "template missing", http.StatusInternalServerError)
		return
	}
	var buf bytes.Buffer
	if err := t.ExecuteTemplate(&buf, "layout", pd); err != nil {
		s.log.Error("template render failed", "page", page, "err", err)
		http.Error(w, "internal error", http.StatusInternalServerError)
		return
	}
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.WriteHeader(status)
	w.Write(buf.Bytes())
}

func (s *Server) errorPage(w http.ResponseWriter, status int, title, msg string) {
	w.Header().Set("Cache-Control", "no-store")
	s.render(w, status, "error", pageData{Title: title, Data: map[string]string{"Heading": title, "Message": msg}})
}

func (s *Server) health(w http.ResponseWriter, r *http.Request) {
	ctx, cancel := context.WithTimeout(r.Context(), 2*time.Second)
	defer cancel()
	if err := s.db.PingContext(ctx); err != nil {
		http.Error(w, "db unavailable", http.StatusServiceUnavailable)
		return
	}
	w.Header().Set("Content-Type", "text/plain; charset=utf-8")
	fmt.Fprintln(w, "ok")
}

// ---------- CSRF and auth plumbing ----------

func randString() string {
	b := make([]byte, 32)
	rand.Read(b)
	return base64.RawURLEncoding.EncodeToString(b)
}

func eq(a, b string) bool {
	return a != "" && subtle.ConstantTimeCompare([]byte(a), []byte(b)) == 1
}

func (s *Server) setCookie(w http.ResponseWriter, name, val, path string, expires time.Time) {
	c := &http.Cookie{Name: name, Value: val, Path: path, HttpOnly: true, Secure: s.secure, SameSite: http.SameSiteStrictMode}
	if !expires.IsZero() {
		c.Expires = expires
	} else if val == "" {
		c.MaxAge = -1
	}
	http.SetCookie(w, c)
}

type authedHandler func(w http.ResponseWriter, r *http.Request, sess *auth.Session)

// authed wraps a handler so it only runs for a valid session, forces the
// first-login password change, and checks the CSRF token on every POST.
func (s *Server) authed(h authedHandler) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		c, err := r.Cookie(sessionCookie)
		var sess *auth.Session
		if err == nil {
			sess, err = s.auth.Authenticate(r.Context(), c.Value)
		}
		if err != nil || sess == nil {
			if err != nil && !errors.Is(err, auth.ErrNoSession) && !errors.Is(err, http.ErrNoCookie) {
				s.log.Error("session lookup failed", "err", err)
			}
			http.Redirect(w, r, "/admin/login", http.StatusSeeOther)
			return
		}
		if r.Method == http.MethodPost {
			// ParseForm alone would mark a multipart upload (a logo, a CSV) as
			// parsed without reading it, hiding the CSRF token inside it.
			var err error
			if strings.HasPrefix(r.Header.Get("Content-Type"), "multipart/form-data") {
				err = r.ParseMultipartForm(4 << 20)
			} else {
				err = r.ParseForm()
			}
			if err != nil {
				s.errorPage(w, http.StatusBadRequest, "Bad request", "The form could not be read.")
				return
			}
			if !eq(r.PostFormValue("csrf"), sess.CSRF) {
				s.errorPage(w, http.StatusForbidden, "Forbidden", "The security token on that form was missing or wrong. Go back, reload the page and try again.")
				return
			}
		}
		if sess.User.MustChange && r.URL.Path != "/admin/password" && r.URL.Path != "/admin/logout" {
			http.Redirect(w, r, "/admin/password", http.StatusSeeOther)
			return
		}
		h(w, r, sess)
	}
}

func (s *Server) page(sess *auth.Session, title string, data any) pageData {
	return pageData{Title: title, User: &sess.User, CSRF: sess.CSRF, Data: data,
		GeoCredit: strings.Contains(s.geo.DatabaseType(), "DBIP"), FullIP: s.cfg.StoreFullIP}
}

// ---------- login / logout / password ----------

func (s *Server) loginForm(w http.ResponseWriter, r *http.Request) {
	tok := randString()
	s.setCookie(w, preCSRFCookie, tok, "/admin", s.now().Add(time.Hour))
	s.render(w, http.StatusOK, "login", pageData{Title: "Sign in", CSRF: tok})
}

func (s *Server) loginSubmit(w http.ResponseWriter, r *http.Request) {
	if err := r.ParseForm(); err != nil {
		s.errorPage(w, http.StatusBadRequest, "Bad request", "The form could not be read.")
		return
	}
	cookie, err := r.Cookie(preCSRFCookie)
	if err != nil || !eq(r.PostFormValue("csrf"), cookie.Value) {
		s.errorPage(w, http.StatusForbidden, "Forbidden", "The sign-in form expired. Go back, reload the page and try again.")
		return
	}
	tok := randString()
	s.setCookie(w, preCSRFCookie, tok, "/admin", s.now().Add(time.Hour))

	token, err := s.auth.Login(r.Context(), r.PostFormValue("username"), r.PostFormValue("password"), s.clientIP(r))
	var locked *auth.LockedError
	switch {
	case errors.As(err, &locked):
		mins := int(locked.Until.Sub(s.now()).Minutes()) + 1
		w.Header().Set("Retry-After", fmt.Sprint(mins*60))
		s.render(w, http.StatusTooManyRequests, "login", pageData{Title: "Sign in", CSRF: tok,
			Error: fmt.Sprintf("Too many failed attempts. Try again in about %d minutes.", mins)})
	case errors.Is(err, auth.ErrInvalid):
		s.render(w, http.StatusUnauthorized, "login", pageData{Title: "Sign in", CSRF: tok, Error: "Invalid username or password."})
	case err != nil:
		s.log.Error("login failed", "err", err)
		s.errorPage(w, http.StatusInternalServerError, "Something went wrong", "Sign-in is unavailable right now.")
	default:
		s.setCookie(w, sessionCookie, token, "/", s.now().Add(s.auth.AbsTTL))
		http.Redirect(w, r, "/admin/", http.StatusSeeOther)
	}
}

func (s *Server) logout(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	if c, err := r.Cookie(sessionCookie); err == nil {
		s.auth.Logout(r.Context(), c.Value)
	}
	s.setCookie(w, sessionCookie, "", "/", time.Time{})
	http.Redirect(w, r, "/admin/login", http.StatusSeeOther)
}

func (s *Server) passwordForm(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	s.render(w, http.StatusOK, "password", s.page(sess, "Change password", map[string]any{"Forced": sess.User.MustChange}))
}

func (s *Server) passwordSubmit(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	pd := s.page(sess, "Change password", map[string]any{"Forced": sess.User.MustChange})
	if r.PostFormValue("new") != r.PostFormValue("confirm") {
		pd.Error = "The new password and its confirmation do not match."
		s.render(w, http.StatusUnprocessableEntity, "password", pd)
		return
	}
	if err := s.auth.ChangePassword(r.Context(), sess.User.ID, r.PostFormValue("current"), r.PostFormValue("new"), sess.ID); err != nil {
		var pe *auth.PolicyError
		if errors.As(err, &pe) {
			pd.Error = pe.Msg
			s.render(w, http.StatusUnprocessableEntity, "password", pd)
			return
		}
		s.log.Error("password change failed", "err", err)
		s.errorPage(w, http.StatusInternalServerError, "Something went wrong", "The password could not be changed.")
		return
	}
	http.Redirect(w, r, "/admin/", http.StatusSeeOther)
}
