package web

import (
	"fmt"
	"html"
	"html/template"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/auth"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/links"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/qr"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/scans"
)

func (s *Server) shortURL(code string) string { return s.cfg.BaseURL + "/r/" + code }

// ---------- list ----------

type linkRow struct {
	Link     *links.Link
	Status   links.Status
	ShortURL string
	Totals   scans.Totals
}

func (s *Server) list(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	page, _ := strconv.Atoi(r.URL.Query().Get("page"))
	if page < 1 {
		page = 1
	}
	ls, total, err := s.links.List(r.Context(), page, perPage)
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
		rows[i] = linkRow{Link: l, Status: l.StatusAt(now), ShortURL: s.shortURL(l.Code), Totals: totals[l.ID]}
	}
	pages := (total + perPage - 1) / perPage
	s.render(w, http.StatusOK, "links", s.page(sess, "Links", map[string]any{
		"Rows": rows, "Page": page, "Pages": pages, "Total": total,
		"HasPrev": page > 1, "HasNext": page < pages,
	}))
}

func (s *Server) serverError(w http.ResponseWriter, what string, err error) {
	s.log.Error(what, "err", err)
	s.errorPage(w, http.StatusInternalServerError, "Something went wrong", "The request could not be completed.")
}

// ---------- create / edit ----------

const inputLayout = "2006-01-02T15:04"

type linkForm struct {
	ID          int64
	Editing     bool
	Label       string
	Destination string
	Window      string
	Start, End  string
	ExpiryMode  string
	FallbackURL string
	QRECC       string
	Errors      map[string]string
}

var presets = map[string]time.Duration{
	"24h": 24 * time.Hour,
	"7d":  7 * 24 * time.Hour,
	"30d": 30 * 24 * time.Hour,
	"90d": 90 * 24 * time.Hour,
}

func (s *Server) newForm(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	now := s.now().In(s.cfg.Location)
	f := linkForm{Window: "7d", ExpiryMode: string(links.RedirectUntracked), QRECC: "M",
		Start: now.Format(inputLayout), End: now.Add(presets["7d"]).Format(inputLayout)}
	s.render(w, http.StatusOK, "link_form", s.page(sess, "New link", f))
}

// parseForm turns the submitted form into a validated links.Input. For a
// preset window the window starts now; for "custom" the typed start and end
// are read as Europe/London local time and stored as UTC.
func (s *Server) parseForm(r *http.Request) (links.Input, linkForm) {
	f := linkForm{
		Label: r.PostFormValue("label"), Destination: strings.TrimSpace(r.PostFormValue("destination")),
		Window: r.PostFormValue("window"), Start: r.PostFormValue("start"), End: r.PostFormValue("end"),
		ExpiryMode: r.PostFormValue("expiry_mode"), FallbackURL: strings.TrimSpace(r.PostFormValue("fallback_url")),
		QRECC: r.PostFormValue("qr_ecc"), Errors: map[string]string{},
	}
	in := links.Input{
		Label: f.Label, Destination: f.Destination, ExpiryMode: links.ExpiryMode(f.ExpiryMode),
		FallbackURL: f.FallbackURL, QRECC: f.QRECC,
	}
	if d, ok := presets[f.Window]; ok {
		in.Start = s.now().UTC().Truncate(time.Second)
		in.End = in.Start.Add(d)
	} else {
		st, e1 := time.ParseInLocation(inputLayout, f.Start, s.cfg.Location)
		en, e2 := time.ParseInLocation(inputLayout, f.End, s.cfg.Location)
		if e1 != nil || e2 != nil {
			f.Errors["window"] = "Enter a valid start and end date and time."
		} else {
			in.Start, in.End = st.UTC(), en.UTC()
		}
	}
	clean, errs := in.Clean(s.host)
	for k, v := range errs {
		if _, exists := f.Errors[k]; !exists {
			f.Errors[k] = v
		}
	}
	return clean, f
}

func (s *Server) create(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	in, f := s.parseForm(r)
	if len(f.Errors) > 0 {
		s.render(w, http.StatusUnprocessableEntity, "link_form", s.page(sess, "New link", f))
		return
	}
	l, err := s.links.Create(r.Context(), in)
	if err != nil {
		s.serverError(w, "create link", err)
		return
	}
	http.Redirect(w, r, fmt.Sprintf("/admin/links/%d", l.ID), http.StatusSeeOther)
}

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

func (s *Server) editForm(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	l := s.loadLink(w, r)
	if l == nil {
		return
	}
	f := linkForm{ID: l.ID, Editing: true, Label: l.Label, Destination: l.DestinationURL, Window: "custom",
		Start: l.TrackStart.In(s.cfg.Location).Format(inputLayout), End: l.TrackEnd.In(s.cfg.Location).Format(inputLayout),
		ExpiryMode: string(l.ExpiryMode), FallbackURL: l.FallbackURL, QRECC: l.QRECC}
	s.render(w, http.StatusOK, "link_form", s.page(sess, "Edit link", f))
}

func (s *Server) update(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	l := s.loadLink(w, r)
	if l == nil {
		return
	}
	in, f := s.parseForm(r)
	f.ID, f.Editing = l.ID, true
	if len(f.Errors) > 0 {
		s.render(w, http.StatusUnprocessableEntity, "link_form", s.page(sess, "Edit link", f))
		return
	}
	if err := s.links.Update(r.Context(), l.ID, in); err != nil {
		s.serverError(w, "update link", err)
		return
	}
	http.Redirect(w, r, fmt.Sprintf("/admin/links/%d", l.ID), http.StatusSeeOther)
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
	for _, c := range []struct{ title, col string }{
		{"Device", "device_class"}, {"Operating system", "os"}, {"Browser", "browser"},
		{"Country", "country"}, {"Referrer", "referer_host"},
	} {
		rows, err := scans.Breakdown(ctx, s.db, l.ID, c.col, 10, includeBots)
		if err != nil {
			s.serverError(w, "breakdown", err)
			return
		}
		sections = append(sections, section{c.title, rows})
	}

	s.render(w, http.StatusOK, "link_detail", s.page(sess, "Link: "+l.Label, map[string]any{
		"Link": l, "Status": status, "ShortURL": s.shortURL(l.Code), "Totals": totals[l.ID],
		"Chart": chartSVG(series, hourly), "ChartHourly": hourly, "Sections": sections,
		"Countdown": countdown(l, now), "IncludeBots": includeBots, "Sizes": qr.Sizes,
	}))
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

func (s *Server) qrECC(r *http.Request, l *links.Link) string {
	if v := r.URL.Query().Get("ecc"); qr.ValidECC(v) {
		return v
	}
	return l.QRECC
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
	b, err := qr.PNG(s.shortURL(l.Code), s.qrECC(r, l), size)
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
	b, err := qr.SVG(s.shortURL(l.Code), s.qrECC(r, l))
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

func (s *Server) csvExport(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	l := s.loadLink(w, r)
	if l == nil {
		return
	}
	w.Header().Set("Content-Type", "text/csv; charset=utf-8")
	w.Header().Set("Content-Disposition", fmt.Sprintf(`attachment; filename="scans-%s.csv"`, l.Code))
	if err := scans.ExportCSV(r.Context(), s.db, w, l.ID); err != nil {
		s.log.Error("csv export", "err", err) // headers already sent; nothing more can be done
	}
}
