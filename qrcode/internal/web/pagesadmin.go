package web

import (
	"encoding/json"
	"errors"
	"fmt"
	"html/template"
	"image/color"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/auth"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/links"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/pages"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/qr"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/scans"
)

func (s *Server) pageURL(slug string) string { return s.cfg.BaseURL + "/l/" + slug }

// ---------- list ----------

type pageRow struct {
	Page   *pages.Page
	Brand  pages.Brand
	Theme  pages.Theme
	URL    string
	Totals pages.Totals
}

func (s *Server) pagesList(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	list, err := s.pages.List(r.Context())
	if err != nil {
		s.serverError(w, "list pages", err)
		return
	}
	totals, err := pages.PageTotals(r.Context(), s.db)
	if err != nil {
		s.serverError(w, "page totals", err)
		return
	}
	rows := make([]pageRow, len(list))
	for i, p := range list {
		b, _ := pages.BrandByID(p.Brand)
		t, _ := pages.ThemeByID(p.Theme)
		rows[i] = pageRow{Page: p, Brand: b, Theme: t, URL: s.pageURL(p.Slug), Totals: totals[p.ID]}
	}
	s.render(w, http.StatusOK, "pages", s.page(sess, "Link pages", map[string]any{"Rows": rows, "Brands": pages.Brands}))
}

// ---------- the editor ----------

type rowView struct {
	ID                                 int64
	Title, URL, Desc, Icon             string
	ErrTitle, ErrURL, ErrDesc, ErrIcon string
}

type pageForm struct {
	Editing                                           bool
	ID                                                int64
	Slug, Name, Brand, Theme, Accent, Title, Subtitle string
	ShowURLs, ShowSocials, AccentSame                 bool
	Rows                                              []rowView
	Errors                                            map[string]string
	Brands                                            []pages.Brand
	Themes                                            []pages.Theme
	Icons                                             []string
	BaseURL                                           string
	AccentDefault                                     string
}

func (s *Server) newPageForm() *pageForm {
	return &pageForm{Errors: map[string]string{}, Brands: pages.Brands, Themes: pages.Themes, Icons: pages.IconNames, BaseURL: s.cfg.BaseURL,
		ShowURLs: true, ShowSocials: true, AccentSame: true, Theme: pages.Themes[0].ID, Brand: pages.Brands[0].ID}
}

// fillRows turns buttons into editable rows and adds blank ones so there is always room to add more.
func (f *pageForm) fillRows(items []pages.ItemInput) {
	f.Rows = f.Rows[:0]
	for _, it := range items {
		f.Rows = append(f.Rows, rowView{ID: it.ID, Title: it.Title, URL: it.URL, Desc: it.Description, Icon: it.Icon})
	}
	blanks := 3
	if len(f.Rows)+blanks < 5 {
		blanks = 5 - len(f.Rows)
	}
	for i := 0; i < blanks; i++ {
		f.Rows = append(f.Rows, rowView{Icon: "auto"})
	}
}

// PreviewSrc is the address of the live preview frame for the form's current values.
func (f *pageForm) PreviewSrc() template.URL {
	v := url.Values{"brand": {f.Brand}, "theme": {f.Theme}, "title": {f.Title}, "subtitle": {f.Subtitle}}
	if !f.AccentSame {
		v.Set("accent", f.Accent)
	}
	if f.ShowURLs {
		v.Set("show_urls", "1")
	}
	if f.ShowSocials {
		v.Set("show_socials", "1")
	}
	for _, r := range f.Rows {
		if r.Title == "" && r.URL == "" {
			continue
		}
		v.Add("it_title", r.Title)
		v.Add("it_url", r.URL)
		v.Add("it_icon", r.Icon)
		v.Add("it_desc", r.Desc)
	}
	return template.URL("/admin/pages/preview?" + v.Encode())
}

func (s *Server) pageNew(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	f := s.newPageForm()
	if b, ok := pages.BrandByID(r.URL.Query().Get("brand")); ok {
		f.Brand = b.ID
	}
	b, _ := pages.BrandByID(f.Brand)
	if r.URL.Query().Get("example") == "1" {
		f.Name, f.Slug, f.Title = b.Name+" Official Links", pages.Slugify(b.Name+" links"), b.Title
		f.fillRows(pages.Example(b.ID))
	} else {
		f.fillRows(nil)
	}
	s.render(w, http.StatusOK, "page_form", s.page(sess, "New link page", f))
}

func (s *Server) pageEdit(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	p := s.loadPage(w, r)
	if p == nil {
		return
	}
	f := s.newPageForm()
	f.Editing, f.ID = true, p.ID
	f.Slug, f.Name, f.Brand, f.Theme, f.Accent, f.Title, f.Subtitle = p.Slug, p.Name, p.Brand, p.Theme, p.Accent, p.Title, p.Subtitle
	f.ShowURLs, f.ShowSocials, f.AccentSame = p.ShowURLs, p.ShowSocials, p.Accent == ""
	var items []pages.ItemInput
	for _, it := range p.Items {
		items = append(items, pages.ItemInput{ID: it.ID, Title: it.Title, URL: it.URL, Description: it.Description, Icon: it.Icon})
	}
	f.fillRows(items)
	s.render(w, http.StatusOK, "page_form", s.page(sess, "Edit link page", f))
}

// parsePageForm reads the editor's fields; the button columns are parallel lists.
func (s *Server) parsePageForm(r *http.Request, existing *pages.Page) (pages.Input, *pageForm) {
	f := s.newPageForm()
	f.Slug, f.Name, f.Brand, f.Theme = r.PostFormValue("slug"), r.PostFormValue("name"), r.PostFormValue("brand"), r.PostFormValue("theme")
	f.Title, f.Subtitle = r.PostFormValue("title"), r.PostFormValue("subtitle")
	f.ShowURLs, f.ShowSocials = r.PostFormValue("show_urls") != "", r.PostFormValue("show_socials") != ""
	f.AccentSame = r.PostFormValue("accent_same") != ""
	if !f.AccentSame {
		f.Accent = strings.TrimSpace(r.PostFormValue("accent"))
	}
	if existing != nil {
		f.Editing, f.ID = true, existing.ID
	}
	if f.Slug == "" && existing == nil {
		f.Slug = pages.Slugify(f.Name)
	}
	ids, titles, urls, icons, descs := r.PostForm["item_id"], r.PostForm["item_title"], r.PostForm["item_url"], r.PostForm["item_icon"], r.PostForm["item_desc"]
	at := func(l []string, i int) string {
		if i < len(l) {
			return l[i]
		}
		return ""
	}
	var items []pages.ItemInput
	for i := range urls {
		id, _ := strconv.ParseInt(at(ids, i), 10, 64)
		items = append(items, pages.ItemInput{ID: id, Row: i, Title: at(titles, i), URL: at(urls, i), Description: at(descs, i), Icon: at(icons, i)})
	}
	in := pages.Input{Slug: f.Slug, Name: f.Name, Brand: f.Brand, Theme: f.Theme, Accent: f.Accent, Title: f.Title, Subtitle: f.Subtitle,
		ShowURLs: f.ShowURLs, ShowSocials: f.ShowSocials, Items: items}
	clean, errs := in.Clean()
	f.Errors = errs
	f.Rows = f.Rows[:0]
	for i, it := range items {
		f.Rows = append(f.Rows, rowView{ID: it.ID, Title: it.Title, URL: it.URL, Desc: it.Description, Icon: it.Icon,
			ErrTitle: errs[fmt.Sprintf("item%d_title", i)], ErrURL: errs[fmt.Sprintf("item%d_url", i)],
			ErrDesc: errs[fmt.Sprintf("item%d_desc", i)], ErrIcon: errs[fmt.Sprintf("item%d_icon", i)]})
	}
	if len(f.Rows) == 0 {
		f.fillRows(nil)
	}
	return clean, f
}

func (s *Server) pageCreate(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	in, f := s.parsePageForm(r, nil)
	if len(f.Errors) == 0 {
		p, err := s.pages.Create(r.Context(), in)
		if err == nil {
			s.auth.Audit(r.Context(), sess.User.Username, "page.create", p.Slug, p.Brand+" "+p.Theme)
			http.Redirect(w, r, fmt.Sprintf("/admin/pages/%d", p.ID), http.StatusSeeOther)
			return
		}
		if !errors.Is(err, pages.ErrSlugTaken) {
			s.serverError(w, "create page", err)
			return
		}
		f.Errors["slug"] = err.Error()
	}
	s.render(w, http.StatusUnprocessableEntity, "page_form", s.page(sess, "New link page", f))
}

func (s *Server) pageUpdate(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	p := s.loadPage(w, r)
	if p == nil {
		return
	}
	in, f := s.parsePageForm(r, p)
	if len(f.Errors) == 0 {
		err := s.pages.Update(r.Context(), p.ID, in)
		if err == nil && in.Slug != p.Slug {
			// QR codes made for this page follow it to its new address
			for _, suffix := range []string{"", "?s=qr"} {
				if _, rerr := s.links.Repoint(r.Context(), s.pageURL(p.Slug)+suffix, s.pageURL(in.Slug)+suffix); rerr != nil {
					s.log.Error("could not repoint QR codes after a page address change", "err", rerr)
				}
			}
		}
		if err == nil {
			s.auth.Audit(r.Context(), sess.User.Username, "page.update", in.Slug, "")
			http.Redirect(w, r, fmt.Sprintf("/admin/pages/%d", p.ID), http.StatusSeeOther)
			return
		}
		if errors.Is(err, pages.ErrSlugTaken) {
			f.Errors["slug"] = err.Error()
		} else {
			s.serverError(w, "update page", err)
			return
		}
	}
	s.render(w, http.StatusUnprocessableEntity, "page_form", s.page(sess, "Edit link page", f))
}

func (s *Server) loadPage(w http.ResponseWriter, r *http.Request) *pages.Page {
	id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil {
		s.errorPage(w, http.StatusNotFound, "Not found", "That page does not exist.")
		return nil
	}
	p, err := s.pages.Get(r.Context(), id)
	if errors.Is(err, pages.ErrNotFound) {
		s.errorPage(w, http.StatusNotFound, "Not found", "That page does not exist.")
		return nil
	}
	if err != nil {
		s.serverError(w, "load page", err)
		return nil
	}
	return p
}

func (s *Server) pageToggle(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	p := s.loadPage(w, r)
	if p == nil {
		return
	}
	if err := s.pages.SetEnabled(r.Context(), p.ID, !p.Enabled); err != nil {
		s.serverError(w, "toggle page", err)
		return
	}
	http.Redirect(w, r, fmt.Sprintf("/admin/pages/%d", p.ID), http.StatusSeeOther)
}

func (s *Server) pageDelete(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	p := s.loadPage(w, r)
	if p == nil {
		return
	}
	if err := s.pages.Delete(r.Context(), p.ID); err != nil {
		s.serverError(w, "delete page", err)
		return
	}
	s.auth.Audit(r.Context(), sess.User.Username, "page.delete", p.Slug, "")
	http.Redirect(w, r, "/admin/pages", http.StatusSeeOther)
}

// ---------- statistics ----------

type itemRow struct {
	Item   pages.Item
	Icon   template.HTML
	Clicks int64
	Last   time.Time
	Pct    float64
}

func (s *Server) pageDetail(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	p := s.loadPage(w, r)
	if p == nil {
		return
	}
	ctx := r.Context()
	tot, err := pages.Totalled(ctx, s.db, p.ID)
	if err != nil {
		s.serverError(w, "page totals", err)
		return
	}
	per, err := pages.ItemClicks(ctx, s.db, p.ID)
	if err != nil {
		s.serverError(w, "item clicks", err)
		return
	}
	var maxClicks int64 = 1
	for _, st := range per {
		if st.Clicks > maxClicks {
			maxClicks = st.Clicks
		}
	}
	rows := make([]itemRow, len(p.Items))
	for i, it := range p.Items {
		st := per[it.ID]
		rows[i] = itemRow{Item: it, Icon: template.HTML(pages.Icon(it.Icon)), Clicks: st.Clicks, Last: st.Last, Pct: float64(st.Clicks) * 100 / float64(maxClicks)}
	}
	now := s.now()
	from := now.AddDate(0, 0, -29)
	if p.CreatedAt.After(from) {
		from = p.CreatedAt
	}
	hours, err := pages.ViewHours(ctx, s.db, p.ID, from.Add(-time.Hour), now.Add(time.Hour))
	if err != nil {
		s.serverError(w, "page views", err)
		return
	}
	chart := chartSVG(scans.Regroup(hours, from.Add(-time.Hour), now.Add(time.Hour), false, s.cfg.Location), false)

	type section struct {
		Title string
		Rows  []scans.Count
	}
	var sections []section
	for _, c := range []struct{ title, key string }{{"Came from", "source"}, {"Device", "device"}, {"Operating system", "os"}, {"Language", "language"}, {"Country", "country"}, {"Referrer", "referrer"}} {
		bd, err := pages.ViewBreakdown(ctx, s.db, p.ID, c.key, 8)
		if err != nil {
			s.serverError(w, "breakdown", err)
			return
		}
		if c.key == "language" {
			for i := range bd {
				if l := languageLabel(bd[i].Label); l != "" {
					bd[i].Label = l
				}
			}
		}
		sections = append(sections, section{c.title, bd})
	}
	qrs, _ := s.links.ByDestination(ctx, s.pageURL(p.Slug), s.pageURL(p.Slug)+"?s=qr")
	brand, _ := pages.BrandByID(p.Brand)
	theme, _ := pages.ThemeByID(p.Theme)
	ctr := "0"
	if tot.Views > 0 {
		ctr = fmt.Sprintf("%.0f", float64(tot.Clicks)*100/float64(tot.Views))
	}
	s.render(w, http.StatusOK, "page_detail", s.page(sess, "Link page: "+p.Name, map[string]any{
		"Page": p, "Brand": brand, "Theme": theme, "URL": s.pageURL(p.Slug), "Totals": tot, "Items": rows, "Chart": chart,
		"Sections": sections, "QRs": qrs, "CTR": ctr, "BaseURL": s.cfg.BaseURL,
	}))
}

// pageQR makes a normal, tracked QR code that opens this page. It is created
// like any other dynamic code, so all the design, download and statistics
// tools of QR codes apply, and views that arrive through it are marked "QR".
func (s *Server) pageQR(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	p := s.loadPage(w, r)
	if p == nil {
		return
	}
	brand, _ := pages.BrandByID(p.Brand)
	dest := s.pageURL(p.Slug) + "?s=qr"
	data, _ := json.Marshal(map[string]string{"url": dest})
	// the brand colour if it is dark enough to scan, otherwise a dark neutral
	fg := brand.Accent
	if look, err := pages.Resolve(brand, pages.Themes[1], ""); err != nil || qr.Contrast(hexToRGBA(look.Accent), hexToRGBA("#ffffff")) < qr.MinContrast {
		fg = "#202830"
	}
	design, _ := json.Marshal(qr.Design{FG: fg, BG: "#ffffff", Pattern: "rounded", Eye: "rounded", Frame: "box", CTA: "SCAN FOR OUR LINKS"})
	start := s.now().UTC().Truncate(time.Second)
	in, errs := links.Input{
		Label: "QR for " + p.Name, Campaign: "Link pages", Kind: links.KindDynamic, QRType: "url", Data: string(data), Destination: dest,
		Start: start, End: start.AddDate(10, 0, 0), ExpiryMode: links.RedirectUntracked, QRECC: "M", Design: string(design),
	}.Clean(s.host)
	if len(errs) > 0 {
		s.serverError(w, "make page qr", fmt.Errorf("%v", errs))
		return
	}
	l, err := s.links.Create(r.Context(), in)
	if err != nil {
		s.serverError(w, "make page qr", err)
		return
	}
	s.auth.Audit(r.Context(), sess.User.Username, "qr.create", l.Code, "for page "+p.Slug)
	http.Redirect(w, r, fmt.Sprintf("/admin/links/%d", l.ID), http.StatusSeeOther)
}

func hexToRGBA(s string) color.RGBA {
	var c color.RGBA
	if len(s) == 7 {
		fmt.Sscanf(s, "#%02x%02x%02x", &c.R, &c.G, &c.B)
	}
	c.A = 255
	return c
}
