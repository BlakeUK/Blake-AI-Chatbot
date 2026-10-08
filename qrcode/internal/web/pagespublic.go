package web

import (
	"bytes"
	"fmt"
	"html/template"
	"net/http"
	"net/url"
	"strconv"
	"strings"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/auth"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/pages"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/ua"
)

// publicItem is one button as drawn on the public page.
type publicItem struct {
	Title, Desc string
	Href        template.URL // trusted: built here from validated data
	Icon        template.HTML
	New         bool // open in a new tab (the live preview)
}

// publicView is everything the public page template needs.
type publicView struct {
	Look     pages.Look
	Title    string
	Subtitle string
	Items    []publicItem
	Socials  []publicItem
	CSSHref  template.URL
	Preview  bool
	Chevron  template.HTML
	Deco     bool
}

// displayTarget is the address as shown under a button's title.
func displayTarget(raw string) string {
	switch {
	case strings.HasPrefix(raw, "mailto:"):
		return strings.TrimPrefix(raw, "mailto:")
	case strings.HasPrefix(raw, "tel:"):
		return strings.TrimPrefix(raw, "tel:")
	}
	return raw
}

// buildView turns a page (or the live preview's settings) into what is drawn.
// Web links go through the tracked /go address; mail and phone links are direct.
func (s *Server) buildView(p pages.Page, preview bool) (publicView, error) {
	brand, _ := pages.BrandByID(p.Brand)
	theme, _ := pages.ThemeByID(p.Theme)
	look, err := pages.Resolve(brand, theme, p.Accent)
	if err != nil {
		look, _ = pages.Resolve(brand, theme, "") // never serve a broken page because of a bad stored accent
	}
	v := publicView{Look: look, Title: p.Title, Subtitle: p.Subtitle, Preview: preview, Chevron: template.HTML(pages.Icon("chevron")), Deco: true}
	if v.Title == "" {
		v.Title = brand.Title
	}
	for _, it := range p.Items {
		pi := publicItem{Title: it.Title, Icon: template.HTML(pages.Icon(it.Icon)), New: preview}
		switch {
		case strings.HasPrefix(it.URL, "mailto:"), strings.HasPrefix(it.URL, "tel:"):
			pi.Href = template.URL(it.URL)
		case preview:
			pi.Href = template.URL(it.URL)
		default:
			pi.Href = template.URL(fmt.Sprintf("/l/%s/go/%d", url.PathEscape(p.Slug), it.ID))
		}
		if it.Description != "" {
			pi.Desc = it.Description
		} else if p.ShowURLs {
			pi.Desc = displayTarget(it.URL)
		}
		v.Items = append(v.Items, pi)
		if p.ShowSocials && pages.IsSocial(it.Icon) {
			v.Socials = append(v.Socials, pi)
		}
	}
	return v, nil
}

func (s *Server) renderPublic(w http.ResponseWriter, status int, v publicView) {
	var buf bytes.Buffer
	if err := s.public.ExecuteTemplate(&buf, "public", v); err != nil {
		s.log.Error("public page render failed", "err", err)
		http.Error(w, "internal error", http.StatusInternalServerError)
		return
	}
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(status)
	w.Write(buf.Bytes())
}

// pageGone is what a visitor sees for a page that does not exist or is switched off.
func (s *Server) pageGone(w http.ResponseWriter) {
	s.errorPage(w, http.StatusNotFound, "Page not found", "This page does not exist or is not available any more.")
}

// publicPage serves a link page and counts the view. The page is sent first;
// recording happens afterwards through a non-blocking queue.
func (s *Server) publicPage(w http.ResponseWriter, r *http.Request) {
	p, err := s.pages.BySlug(r.Context(), r.PathValue("slug"))
	if err != nil || !p.Enabled {
		s.pageGone(w)
		return
	}
	v, err := s.buildView(*p, false)
	if err != nil {
		s.serverError(w, "build page", err)
		return
	}
	v.CSSHref = template.URL(fmt.Sprintf("/l/%s/theme.css?v=%d", url.PathEscape(p.Slug), p.UpdatedAt.Unix()))
	s.renderPublic(w, http.StatusOK, v)
	if r.Method == http.MethodGet {
		source := "direct"
		if r.URL.Query().Get("s") == "qr" {
			source = "qr"
		}
		s.recordPageEvent(r, p.ID, 0, "view", source)
	}
}

// pageCSS serves the page's colours as a stylesheet. Colours are delivered as
// a same-origin file rather than a style attribute because the Content
// Security Policy forbids inline styles.
func (s *Server) pageCSS(w http.ResponseWriter, r *http.Request) {
	p, err := s.pages.BySlug(r.Context(), r.PathValue("slug"))
	if err != nil || !p.Enabled {
		http.NotFound(w, r)
		return
	}
	v, _ := s.buildView(*p, false)
	w.Header().Set("Content-Type", "text/css; charset=utf-8")
	w.Header().Set("Cache-Control", "public, max-age=300")
	fmt.Fprint(w, v.Look.CSS())
}

// pageGo counts a click and sends the visitor to the button's address, which is
// read from the database and never from the request.
func (s *Server) pageGo(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Cache-Control", "no-store")
	p, err := s.pages.BySlug(r.Context(), r.PathValue("slug"))
	if err != nil || !p.Enabled {
		s.pageGone(w)
		return
	}
	id, _ := strconv.ParseInt(r.PathValue("id"), 10, 64)
	it, err := s.pages.ItemOf(r.Context(), p.ID, id)
	if err != nil || !(strings.HasPrefix(it.URL, "https://") || strings.HasPrefix(it.URL, "http://")) {
		s.pageGone(w)
		return
	}
	if r.Method == http.MethodGet {
		s.recordPageEvent(r, p.ID, it.ID, "click", "direct")
	}
	http.Redirect(w, r, it.URL, http.StatusFound)
}

func (s *Server) recordPageEvent(r *http.Request, pageID, itemID int64, kind, source string) {
	if s.pageEvents == nil {
		return
	}
	ip := s.clientIP(r)
	hash, err := s.hasher.Hash(r.Context(), ip)
	if err != nil || hash == "" || !s.limit.Allow(hash) {
		return
	}
	agent := truncate(r.UserAgent(), 512)
	info := ua.Parse(agent)
	loc := s.geo.Lookup(ip)
	s.pageEvents.Submit(pages.Event{
		PageID: pageID, ItemID: itemID, At: s.now(), Kind: kind, Source: source, IPHash: hash,
		Country: loc.CountryISO, CountryName: loc.Country, DeviceClass: info.DeviceClass, OS: info.OS, Browser: info.Browser,
		Language: parseLanguage(r.Header.Get("Accept-Language")), RefererHost: refererHost(r.Referer()), IsBot: info.IsBot,
	})
}

// ---------- the live preview (signed-in editors only) ----------

// previewPage is the HTML inside the editor's preview frame. It draws the page
// from the form's current, unsaved values, so changing a theme or typing a new
// button shows at once. Nothing is counted and nothing is saved.
func (s *Server) previewPage(w http.ResponseWriter, r *http.Request, _ *auth.Session) {
	q := r.URL.Query()
	p := pages.Page{
		Brand: q.Get("brand"), Theme: q.Get("theme"), Accent: q.Get("accent"), Title: q.Get("title"), Subtitle: q.Get("subtitle"),
		ShowURLs: q.Get("show_urls") != "", ShowSocials: q.Get("show_socials") != "", Slug: "preview",
	}
	if _, ok := pages.BrandByID(p.Brand); !ok {
		p.Brand = pages.Brands[0].ID
	}
	if _, ok := pages.ThemeByID(p.Theme); !ok {
		p.Theme = pages.Themes[0].ID
	}
	titles, urls, icons, descs := q["it_title"], q["it_url"], q["it_icon"], q["it_desc"]
	for i := range urls {
		in := pages.ItemInput{URL: urls[i]}
		if i < len(titles) {
			in.Title = titles[i]
		}
		if i < len(icons) {
			in.Icon = icons[i]
		}
		if i < len(descs) {
			in.Description = descs[i]
		}
		if strings.TrimSpace(in.Title) == "" && strings.TrimSpace(in.URL) == "" {
			continue
		}
		target, err := pages.CleanLinkTarget(in.URL)
		if err != nil {
			continue // an unfinished row simply is not drawn yet
		}
		icon := in.Icon
		if icon == "" || icon == "auto" {
			icon = pages.DetectIcon(target)
		}
		title := strings.TrimSpace(in.Title)
		if title == "" {
			title = "(no title yet)"
		}
		p.Items = append(p.Items, pages.Item{Title: truncate(title, pages.MaxTitleRunes), URL: target, Description: truncate(strings.TrimSpace(in.Description), pages.MaxDescRunes), Icon: icon})
	}
	if len(p.Items) == 0 && len(urls) == 0 {
		for _, ex := range pages.Example(p.Brand) {
			t, _ := pages.CleanLinkTarget(ex.URL)
			p.Items = append(p.Items, pages.Item{Title: ex.Title, URL: t, Icon: ex.Icon})
		}
	}
	v, _ := s.buildView(p, true)
	cq := url.Values{"brand": {p.Brand}, "theme": {p.Theme}, "accent": {p.Accent}}
	v.CSSHref = template.URL("/admin/pages/preview.css?" + cq.Encode())
	// the editor embeds this page in a frame of its own site: allow exactly that
	w.Header().Set("X-Frame-Options", "SAMEORIGIN")
	w.Header().Set("Content-Security-Policy", strings.Replace(s.csp(), "frame-ancestors 'none'", "frame-ancestors 'self'", 1))
	s.renderPublic(w, http.StatusOK, v)
}

func (s *Server) previewCSS(w http.ResponseWriter, r *http.Request, _ *auth.Session) {
	q := r.URL.Query()
	brand, ok := pages.BrandByID(q.Get("brand"))
	if !ok {
		brand = pages.Brands[0]
	}
	theme, ok := pages.ThemeByID(q.Get("theme"))
	if !ok {
		theme = pages.Themes[0]
	}
	look, err := pages.Resolve(brand, theme, strings.ToLower(strings.TrimSpace(q.Get("accent"))))
	if err != nil {
		look, _ = pages.Resolve(brand, theme, "") // an unreadable accent just previews as the brand colour
	}
	w.Header().Set("Content-Type", "text/css; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	fmt.Fprint(w, look.CSS())
}
