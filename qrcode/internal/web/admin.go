package web

import (
	"context"
	"encoding/json"
	"fmt"
	"html"
	"html/template"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/auth"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/links"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/qr"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/qrtypes"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/scans"
)

func (s *Server) shortURL(code string) string { return s.cfg.BaseURL + "/r/" + code }

// ---------- list ----------

type linkRow struct {
	Link     *links.Link
	Status   links.Status
	ShortURL string
	Site     string // friendly destination name, e.g. Facebook
	Type     string // human name of the QR type
	Totals   scans.Totals
}

func typeLabel(t string) string {
	if sp, ok := qrtypes.Get(t); ok {
		return sp.Label
	}
	return t
}

func (s *Server) list(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	page, _ := strconv.Atoi(r.URL.Query().Get("page"))
	if page < 1 {
		page = 1
	}
	filter := links.Filter{Campaign: strings.TrimSpace(r.URL.Query().Get("campaign")), Uncategorised: r.URL.Query().Get("uncategorised") == "1"}
	ls, total, err := s.links.List(r.Context(), page, perPage, filter)
	if err != nil {
		s.serverError(w, "list links", err)
		return
	}
	ids := make([]int64, len(ls))
	for i, l := range ls {
		ids[i] = l.ID
	}
	totals, err := scans.TotalsFor(r.Context(), s.db, ids)
	if err != nil {
		s.serverError(w, "totals", err)
		return
	}
	now := s.now()
	rows := make([]linkRow, len(ls))
	for i, l := range ls {
		rows[i] = linkRow{Link: l, Status: l.StatusAt(now), ShortURL: s.shortURL(l.Code), Site: links.SiteName(l.DestinationURL), Type: typeLabel(l.QRType), Totals: totals[l.ID]}
	}
	// Keep the active filter on the pager links.
	q := url.Values{}
	if filter.Uncategorised {
		q.Set("uncategorised", "1")
	} else if filter.Campaign != "" {
		q.Set("campaign", filter.Campaign)
	}
	extra := ""
	if len(q) > 0 {
		extra = "&" + q.Encode()
	}
	pages := (total + perPage - 1) / perPage
	s.render(w, http.StatusOK, "links", s.page(sess, "Links", map[string]any{
		"Rows": rows, "Page": page, "Pages": pages, "Total": total,
		"HasPrev": page > 1, "HasNext": page < pages,
		"Filter": filter, "Extra": extra,
	}))
}

// ---------- campaigns ----------

func (s *Server) campaigns(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	rows, err := scans.CampaignTotals(r.Context(), s.db)
	if err != nil {
		s.serverError(w, "campaign totals", err)
		return
	}
	var all scans.CampaignRow
	for _, c := range rows {
		all.Links += c.Links
		all.Scans += c.Scans
		all.Unique += c.Unique
		all.Bots += c.Bots
	}
	s.render(w, http.StatusOK, "campaigns", s.page(sess, "Campaigns", map[string]any{"Rows": rows, "All": all}))
}

func (s *Server) serverError(w http.ResponseWriter, what string, err error) {
	s.log.Error(what, "err", err)
	s.errorPage(w, http.StatusInternalServerError, "Something went wrong", "The request could not be completed.")
}

// ---------- create / edit ----------

const recentPerPage = 50

func (s *Server) loadLink(w http.ResponseWriter, r *http.Request) *links.Link {
	id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil {
		s.errorPage(w, http.StatusNotFound, "Not found", "That link does not exist.")
		return nil
	}
	l, err := s.links.Get(r.Context(), id)
	if err == links.ErrNotFound {
		s.errorPage(w, http.StatusNotFound, "Not found", "That link does not exist.")
		return nil
	}
	if err != nil {
		s.serverError(w, "load link", err)
		return nil
	}
	return l
}

// newLink first asks what kind of code (static or dynamic) and which type,
// then shows that type's form.
func (s *Server) newLink(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	kind, typ := r.URL.Query().Get("kind"), r.URL.Query().Get("type")
	spec, ok := qrtypes.Get(typ)
	if (kind != qrtypes.KindStatic && kind != qrtypes.KindDynamic) || !ok || !spec.Supports(kind) {
		s.render(w, http.StatusOK, "link_choose", s.page(sess, "New QR code", map[string]any{
			"Dynamic": qrtypes.For(qrtypes.KindDynamic), "Static": qrtypes.For(qrtypes.KindStatic),
		}))
		return
	}
	fv := s.baseForm(r.Context(), kind, spec)
	if tid, _ := strconv.ParseInt(r.URL.Query().Get("template"), 10, 64); tid > 0 {
		if t, _, err := s.links.TemplateByID(r.Context(), tid); err == nil {
			var d qr.Design
			json.Unmarshal([]byte(t.Design), &d)
			if nd, err := d.Normalise(); err == nil {
				fv.Design, fv.TemplateID, fv.HasLogo = nd, t.ID, t.HasLogo
			}
		}
	}
	s.render(w, http.StatusOK, "link_form", s.page(sess, "New "+spec.Label+" QR code", fv))
}

func (s *Server) create(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	kind, typ := r.PostFormValue("kind"), r.PostFormValue("type")
	spec, ok := qrtypes.Get(typ)
	if (kind != qrtypes.KindStatic && kind != qrtypes.KindDynamic) || !ok || !spec.Supports(kind) {
		s.errorPage(w, http.StatusBadRequest, "Bad request", "Choose a type of QR code first.")
		return
	}
	in, fv := s.parseLinkForm(r, kind, spec, nil)
	if len(fv.Errors) > 0 {
		s.render(w, http.StatusUnprocessableEntity, "link_form", s.page(sess, "New "+spec.Label+" QR code", fv))
		return
	}
	l, err := s.links.Create(r.Context(), in)
	if err != nil {
		s.serverError(w, "create link", err)
		return
	}
	if err := s.saveTemplateIfAsked(r.Context(), r, in, nil); err != nil {
		s.log.Warn("template not saved", "err", err)
	}
	s.auth.Audit(r.Context(), sess.User.Username, "qr.create", l.Code, kind+" "+typ)
	http.Redirect(w, r, fmt.Sprintf("/admin/links/%d", l.ID), http.StatusSeeOther)
}

func (s *Server) editForm(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	l := s.loadLink(w, r)
	if l == nil {
		return
	}
	spec, _ := qrtypes.Get(l.QRType)
	s.render(w, http.StatusOK, "link_form", s.page(sess, "Edit QR code", s.formFromLink(r.Context(), l, spec)))
}

func (s *Server) update(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	l := s.loadLink(w, r)
	if l == nil {
		return
	}
	spec, _ := qrtypes.Get(l.QRType)
	in, fv := s.parseLinkForm(r, l.Kind, spec, l)
	if len(fv.Errors) > 0 {
		s.render(w, http.StatusUnprocessableEntity, "link_form", s.page(sess, "Edit QR code", fv))
		return
	}
	if err := s.links.Update(r.Context(), l.ID, in); err != nil {
		s.serverError(w, "update link", err)
		return
	}
	if err := s.saveTemplateIfAsked(r.Context(), r, in, l); err != nil {
		s.log.Warn("template not saved", "err", err)
	}
	s.auth.Audit(r.Context(), sess.User.Username, "qr.update", l.Code, "")
	http.Redirect(w, r, fmt.Sprintf("/admin/links/%d", l.ID), http.StatusSeeOther)
}

// preview draws a sample code in the design given by the query string, for the
// live preview beside the form. A design that would not scan is refused with a
// plain-text reason the page shows instead of an image.
func (s *Server) preview(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	q := r.URL.Query().Get
	d, err := designFrom(q).Normalise()
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}
	ecc := q("qr_ecc")
	if !qr.ValidECC(ecc) {
		ecc = "M"
	}
	var logo []byte
	if id, _ := strconv.ParseInt(q("link"), 10, 64); id > 0 {
		logo, _ = s.links.Logo(r.Context(), id)
	} else if tid, _ := strconv.ParseInt(q("template"), 10, 64); tid > 0 {
		_, logo, _ = s.links.TemplateByID(r.Context(), tid)
	}
	svg, err := qr.RenderSVG(qr.Options{Content: s.shortURL("AbCd1234"), ECC: ecc, Design: d, Logo: logo})
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}
	w.Header().Set("Content-Type", "image/svg+xml")
	w.Write(svg)
}

// ---------- saved designs ----------

func (s *Server) deleteTemplate(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	id, _ := strconv.ParseInt(r.PathValue("id"), 10, 64)
	s.links.DeleteTemplate(r.Context(), id)
	http.Redirect(w, r, "/admin/templates", http.StatusSeeOther)
}

func (s *Server) toggle(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	l := s.loadLink(w, r)
	if l == nil {
		return
	}
	if err := s.links.SetEnabled(r.Context(), l.ID, !l.Enabled); err != nil {
		s.serverError(w, "toggle link", err)
		return
	}
	http.Redirect(w, r, fmt.Sprintf("/admin/links/%d", l.ID), http.StatusSeeOther)
}

func (s *Server) remove(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	l := s.loadLink(w, r)
	if l == nil {
		return
	}
	if err := s.links.Delete(r.Context(), l.ID); err != nil {
		s.serverError(w, "delete link", err)
		return
	}
	http.Redirect(w, r, "/admin/", http.StatusSeeOther)
}

// defaultCampaigns are offered in the form so common placements are one click.
var defaultCampaigns = []string{"Leaflet", "Exhibition stand", "Product box", "Packaging", "Poster", "Van / vehicle", "Email", "Social media", "Trade counter"}

// campaignSuggestions merges the usual placements with campaigns already in
// use, without duplicates (ignoring case).
func (s *Server) campaignSuggestions(ctx context.Context) []string {
	seen := map[string]bool{}
	var out []string
	existing, _ := s.links.Campaigns(ctx)
	for _, c := range append(existing, defaultCampaigns...) {
		if k := strings.ToLower(c); !seen[k] {
			seen[k] = true
			out = append(out, c)
		}
	}
	return out
}

// ---------- detail ----------

func (s *Server) detail(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	l := s.loadLink(w, r)
	if l == nil {
		return
	}
	ctx := r.Context()
	includeBots := r.URL.Query().Get("bots") == "1"
	now := s.now()

	totals, err := scans.TotalsFor(ctx, s.db, []int64{l.ID})
	if err != nil {
		s.serverError(w, "totals", err)
		return
	}
	status := l.StatusAt(now)
	from, to := l.TrackStart, now
	if to.After(l.TrackEnd) {
		to = l.TrackEnd
	}
	if first, last, ok, err := scans.Extent(ctx, s.db, l.ID); err != nil {
		s.serverError(w, "extent", err)
		return
	} else if ok {
		// Scans logged under an earlier window must still appear on the graph.
		if first.Before(from) {
			from = first
		}
		if last.Add(time.Second).After(to) {
			to = last.Add(time.Second)
		}
	}
	if status != links.Scheduled && !to.After(from) {
		to = from.Add(time.Second) // window just opened: draw one empty bar
	}
	// Hourly bars for a tracking window under 3 days, daily otherwise (spec); the
	// extra bound only matters if a window was edited after scans were recorded.
	hourly := l.TrackEnd.Sub(l.TrackStart) < 72*time.Hour && to.Sub(from) < 14*24*time.Hour
	var series []scans.Bucket
	if status != links.Scheduled || to.After(from) {
		var err error
		series, err = scans.Series(ctx, s.db, l.ID, from, to, hourly, includeBots, s.cfg.Location)
		if err != nil {
			s.serverError(w, "series", err)
			return
		}
	}
	type section struct {
		Title string
		Rows  []scans.Count
	}
	var sections []section
	dims := []struct{ title, col string }{
		{"Device", "device_class"}, {"Operating system", "os"}, {"Browser", "browser"}, {"Language", "language"},
		{"Country", "country"}, {"Region", "region"}, {"Town", "city"}, {"Referrer", "referer_host"},
	}
	if l.HasRules { // with routing rules, where people were actually sent is the number that shows an A/B test or a time rule working
		dims = append([]struct{ title, col string }{{"Where visitors were sent", "destination"}}, dims...)
	}
	for _, c := range dims {
		rows, err := scans.Breakdown(ctx, s.db, l.ID, c.col, 10, includeBots)
		if err != nil {
			s.serverError(w, "breakdown", err)
			return
		}
		if c.col == "language" {
			for i := range rows {
				if l := languageLabel(rows[i].Label); l != "" {
					rows[i].Label = l
				}
			}
		}
		sections = append(sections, section{c.title, rows})
	}

	// Individual scans, newest first, 50 to a page.
	sp, _ := strconv.Atoi(r.URL.Query().Get("sp"))
	if sp < 1 {
		sp = 1
	}
	recent, recentTotal, err := scans.Recent(ctx, s.db, l.ID, recentPerPage, (sp-1)*recentPerPage, includeBots)
	if err != nil {
		s.serverError(w, "recent scans", err)
		return
	}
	recentPages := int((recentTotal + recentPerPage - 1) / recentPerPage)
	botsQ := ""
	if includeBots {
		botsQ = "&bots=1"
	}

	s.render(w, http.StatusOK, "link_detail", s.page(sess, "Link: "+l.Label, map[string]any{
		"Link": l, "Status": status, "ShortURL": s.shortURL(l.Code), "Totals": totals[l.ID],
		"Chart": chartSVG(series, hourly), "ChartHourly": hourly, "Sections": sections,
		"Countdown": countdown(l, now), "IncludeBots": includeBots, "Sizes": qr.Sizes,
		"Site": links.SiteName(l.DestinationURL), "TypeLabel": typeLabel(l.QRType), "Rules": linkRules(r.Context(), s.links, l), "Recent": recent, "RecentTotal": recentTotal,
		"SP": sp, "SPages": recentPages, "SPHasPrev": sp > 1, "SPHasNext": sp < recentPages, "BotsQ": botsQ,
		"ShowIP": s.cfg.StoreFullIP,
	}))
}

func linkRules(ctx context.Context, st *links.Store, l *links.Link) []links.Rule {
	if !l.HasRules {
		return nil
	}
	rules, _ := st.Rules(ctx, l.ID)
	return rules
}

func countdown(l *links.Link, now time.Time) string {
	switch l.StatusAt(now) {
	case links.Scheduled:
		return "Starts in " + human(l.TrackStart.Sub(now))
	case links.Active:
		return human(l.TrackEnd.Sub(now)) + " left in the tracking window"
	}
	return "Window ended " + human(now.Sub(l.TrackEnd)) + " ago"
}

func human(d time.Duration) string {
	if d < 0 {
		d = -d
	}
	days := int(d.Hours() / 24)
	hours := int(d.Hours()) % 24
	mins := int(d.Minutes()) % 60
	switch {
	case days > 0:
		return fmt.Sprintf("%dd %dh", days, hours)
	case hours > 0:
		return fmt.Sprintf("%dh %dm", hours, mins)
	}
	return fmt.Sprintf("%dm", mins)
}

// chartSVG draws the scans-over-time bars as inline SVG on the server, so the
// page needs no chart library and no JavaScript. Styling is by CSS class:
// the Content-Security-Policy forbids inline style attributes.
func chartSVG(b []scans.Bucket, hourly bool) template.HTML {
	if len(b) == 0 {
		return template.HTML(`<p class="muted">No data yet: the tracking window has not started.</p>`)
	}
	const w, h, padL, padB, padT = 720.0, 220.0, 36.0, 28.0, 10.0
	var max int64 = 1
	for _, x := range b {
		if x.Count > max {
			max = x.Count
		}
	}
	plotW, plotH := w-padL-4, h-padB-padT
	bw := plotW / float64(len(b))
	var sb strings.Builder
	fmt.Fprintf(&sb, `<svg class="chart" viewBox="0 0 %.0f %.0f" role="img" aria-label="Scans over time">`, w, h)
	fmt.Fprintf(&sb, `<line class="axis" x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"/>`, padL, padT+plotH, w-4, padT+plotH)
	fmt.Fprintf(&sb, `<text class="tick" x="%.1f" y="%.1f" text-anchor="end">%d</text>`, padL-4, padT+8, max)
	fmt.Fprintf(&sb, `<text class="tick" x="%.1f" y="%.1f" text-anchor="end">0</text>`, padL-4, padT+plotH)
	step := (len(b) + 9) / 10
	for i, x := range b {
		bh := plotH * float64(x.Count) / float64(max)
		bx := padL + float64(i)*bw
		fmt.Fprintf(&sb, `<rect class="bar" x="%.2f" y="%.2f" width="%.2f" height="%.2f"><title>%s: %d</title></rect>`,
			bx+0.5, padT+plotH-bh, maxf(bw-1, 1), bh, html.EscapeString(x.Label), x.Count)
		if i%step == 0 {
			lbl := x.Label
			if hourly {
				lbl = x.Start.Format("15:04")
			}
			fmt.Fprintf(&sb, `<text class="tick" x="%.1f" y="%.1f" text-anchor="middle">%s</text>`, bx+bw/2, h-10, html.EscapeString(lbl))
		}
	}
	sb.WriteString(`</svg>`)
	return template.HTML(sb.String())
}

func maxf(a, b float64) float64 {
	if a > b {
		return a
	}
	return b
}

// ---------- QR and CSV downloads ----------

// qrOptions is what to draw for a saved code: the short tracking address for a
// dynamic code, or the content itself for a static one, in the code's own
// design, logo and error-correction level.
func (s *Server) qrOptions(ctx context.Context, r *http.Request, l *links.Link) qr.Options {
	content := s.shortURL(l.Code)
	if l.IsStatic() {
		content = l.Content
	}
	var d qr.Design
	json.Unmarshal([]byte(l.Design), &d)
	if nd, err := d.Normalise(); err == nil {
		d = nd
	} else {
		d = qr.Design{}
	}
	ecc := l.QRECC
	if v := r.URL.Query().Get("ecc"); qr.ValidECC(v) {
		ecc = v
	}
	var logo []byte
	if l.HasLogo {
		logo, _ = s.links.Logo(ctx, l.ID)
	}
	return qr.Options{Content: content, ECC: ecc, Design: d, Logo: logo}
}

func (s *Server) qrPNG(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	l := s.loadLink(w, r)
	if l == nil {
		return
	}
	size := 512
	if v := r.URL.Query().Get("size"); v != "" {
		n, err := strconv.Atoi(v)
		if err != nil || !qr.ValidSize(n) {
			s.errorPage(w, http.StatusBadRequest, "Bad request", "Size must be 256, 512 or 1024.")
			return
		}
		size = n
	}
	b, err := qr.RenderPNG(s.qrOptions(r.Context(), r, l), size)
	if err != nil {
		s.serverError(w, "render qr png", err)
		return
	}
	w.Header().Set("Content-Type", "image/png")
	if r.URL.Query().Get("download") == "1" {
		w.Header().Set("Content-Disposition", fmt.Sprintf(`attachment; filename="qr-%s-%d.png"`, l.Code, size))
	}
	w.Write(b)
}

func (s *Server) qrSVG(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	l := s.loadLink(w, r)
	if l == nil {
		return
	}
	b, err := qr.RenderSVG(s.qrOptions(r.Context(), r, l))
	if err != nil {
		s.serverError(w, "render qr svg", err)
		return
	}
	w.Header().Set("Content-Type", "image/svg+xml")
	if r.URL.Query().Get("download") == "1" {
		w.Header().Set("Content-Disposition", fmt.Sprintf(`attachment; filename="qr-%s.svg"`, l.Code))
	}
	w.Write(b)
}

// qrDownload serves the code as a file in the format asked for: png (with a size), svg, pdf or eps, optionally with a
// transparent background. PDF and EPS are vector files for printers; transparent is for plain light surfaces.
func (s *Server) qrDownload(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	l := s.loadLink(w, r)
	if l == nil {
		return
	}
	q := r.URL.Query()
	o := s.qrOptions(r.Context(), r, l)
	o.Transparent = q.Get("bg") == "transparent"
	name := "qr-" + l.Code
	if o.Transparent {
		name += "-transparent"
	}
	var (
		b    []byte
		err  error
		ctyp string
	)
	switch q.Get("format") {
	case "png":
		size := 1024
		if v := q.Get("size"); v != "" {
			n, perr := strconv.Atoi(v)
			if perr != nil || !qr.ValidSize(n) {
				s.errorPage(w, http.StatusBadRequest, "Bad request", "Size must be 256, 512 or 1024.")
				return
			}
			size = n
		}
		b, err = qr.RenderPNG(o, size)
		ctyp, name = "image/png", fmt.Sprintf("%s-%d.png", name, size)
	case "svg":
		b, err = qr.RenderSVG(o)
		ctyp, name = "image/svg+xml", name+".svg"
	case "pdf":
		b, err = qr.RenderPDF(o)
		ctyp, name = "application/pdf", name+".pdf"
	case "eps":
		b, err = qr.RenderEPS(o)
		ctyp, name = "application/postscript", name+".eps"
	default:
		s.errorPage(w, http.StatusBadRequest, "Bad request", "Choose PNG, SVG, PDF or EPS.")
		return
	}
	if err != nil {
		s.serverError(w, "render qr download", err)
		return
	}
	w.Header().Set("Content-Type", ctyp)
	w.Header().Set("Content-Disposition", fmt.Sprintf(`attachment; filename="%s"`, name))
	w.Write(b)
}

func (s *Server) csvExport(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	l := s.loadLink(w, r)
	if l == nil {
		return
	}
	s.writeCSV(w, r, l.ID, fmt.Sprintf("scans-%s.csv", l.Code))
}

// csvExportAll is every scan of every link, with the campaign on each row, for
// reporting in a spreadsheet.
func (s *Server) csvExportAll(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	s.writeCSV(w, r, 0, "scans-all.csv")
}

func (s *Server) writeCSV(w http.ResponseWriter, r *http.Request, linkID int64, name string) {
	w.Header().Set("Content-Type", "text/csv; charset=utf-8")
	w.Header().Set("Content-Disposition", fmt.Sprintf(`attachment; filename="%s"`, name))
	if err := scans.ExportCSV(r.Context(), s.db, w, linkID, s.cfg.Location); err != nil {
		s.log.Error("csv export", "err", err) // headers already sent; nothing more can be done
	}
}
