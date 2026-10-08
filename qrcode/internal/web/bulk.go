package web

import (
	"archive/zip"
	"bytes"
	"context"
	"encoding/csv"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/auth"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/links"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/qr"
)

const (
	bulkMaxRowsSVG = 1000 // SVG is cheap to draw
	bulkMaxRowsPNG = 250  // PNG is rasterised, which is slower
)

type bulkPage struct {
	Templates []links.Template
	Campaigns []string
	Errors    []string
	Campaign  string
	Format    string
}

func (s *Server) bulkForm(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	s.renderBulk(w, sess, http.StatusOK, bulkPage{Format: "svg"}, r.Context())
}

func (s *Server) renderBulk(w http.ResponseWriter, sess *auth.Session, status int, p bulkPage, ctx context.Context) {
	p.Templates, _ = s.links.Templates(ctx)
	p.Campaigns = s.campaignSuggestions(ctx)
	s.render(w, status, "bulk", s.page(sess, "Bulk QR codes", p))
}

// bulkCreate makes one tracked code per row of an uploaded CSV, all or nothing,
// and returns a ZIP of the images plus a CSV mapping each row to its code.
//
// The CSV needs a header row with "label" and "url" columns; "campaign" is optional.
func (s *Server) bulkCreate(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	ctx := r.Context()
	fail := func(msgs ...string) {
		s.renderBulk(w, sess, http.StatusUnprocessableEntity, bulkPage{Errors: msgs, Campaign: r.PostFormValue("campaign"), Format: r.PostFormValue("format")}, ctx)
	}
	format := r.PostFormValue("format")
	if format != "svg" && format != "png" && format != "both" {
		format = "svg"
	}
	file, _, err := r.FormFile("csv")
	if err != nil {
		fail("Choose a CSV file to upload.")
		return
	}
	defer file.Close()
	raw, err := io.ReadAll(io.LimitReader(file, 1<<20))
	if err != nil {
		fail("The file could not be read.")
		return
	}
	cr := csv.NewReader(bytes.NewReader(bytes.TrimPrefix(raw, []byte("\xef\xbb\xbf")))) // tolerate Excel's byte-order mark
	cr.FieldsPerRecord = -1
	cr.TrimLeadingSpace = true
	records, err := cr.ReadAll()
	if err != nil || len(records) < 2 {
		fail("The file must be a CSV with a header row and at least one data row.")
		return
	}
	col := map[string]int{}
	for i, h := range records[0] {
		col[strings.ToLower(strings.TrimSpace(h))] = i
	}
	li, lok := col["label"]
	ui, uok := col["url"]
	if !uok {
		ui, uok = col["destination"]
	}
	if !lok || !uok {
		fail(`The header row must include "label" and "url" columns.`)
		return
	}
	ci, cok := col["campaign"]
	rows := records[1:]
	limit := bulkMaxRowsSVG
	if format != "svg" {
		limit = bulkMaxRowsPNG
	}
	if len(rows) > limit {
		fail(fmt.Sprintf("That is %d rows; the limit is %d for this image format. Split the file, or choose SVG only.", len(rows), limit))
		return
	}

	// shared settings
	design, derr := designFrom(r.PostFormValue).Normalise()
	var logo []byte
	if tid := parseID(r.PostFormValue("template")); tid > 0 {
		if t, tl, err := s.links.TemplateByID(ctx, tid); err == nil {
			var d qr.Design
			json.Unmarshal([]byte(t.Design), &d)
			design, derr = d.Normalise()
			logo = tl
		}
	}
	if derr != nil {
		fail(derr.Error())
		return
	}
	dj, _ := json.Marshal(design)
	start := s.now().UTC().Truncate(time.Second)
	d := presets[r.PostFormValue("window")]
	if d == 0 {
		d = presets["90d"]
	}
	ecc := r.PostFormValue("qr_ecc")
	if !qr.ValidECC(ecc) {
		ecc = "M"
	}
	defCampaign := r.PostFormValue("campaign")

	var inputs []links.Input
	var problems []string
	for n, rec := range rows {
		get := func(i int) string {
			if i < len(rec) {
				return strings.TrimSpace(rec[i])
			}
			return ""
		}
		if get(li) == "" && get(ui) == "" {
			continue // a blank line
		}
		camp := defCampaign
		if cok && get(ci) != "" {
			camp = get(ci)
		}
		data, _ := json.Marshal(map[string]string{"url": get(ui)})
		in := links.Input{Label: get(li), Destination: get(ui), Campaign: camp, Kind: links.KindDynamic, QRType: "url",
			Data: string(data), Design: string(dj), Start: start, End: start.Add(d), ExpiryMode: links.RedirectUntracked, QRECC: ecc, Logo: logo}
		clean, errs := in.Clean(s.host)
		if len(errs) > 0 {
			for _, msg := range errs {
				problems = append(problems, fmt.Sprintf("Row %d (%s): %s", n+2, in.Label, msg))
			}
			continue
		}
		inputs = append(inputs, clean)
	}
	if len(problems) > 0 {
		if len(problems) > 15 {
			problems = append(problems[:15], fmt.Sprintf("...and %d more problems. Nothing was created.", len(problems)-15))
		}
		fail(problems...)
		return
	}
	if len(inputs) == 0 {
		fail("The file has no data rows.")
		return
	}
	made, err := s.links.CreateMany(ctx, inputs)
	if err != nil {
		s.serverError(w, "bulk create", err)
		return
	}
	s.auth.Audit(ctx, sess.User.Username, "qr.bulk", fmt.Sprintf("%d codes", len(made)), "")

	// Drawing can take a while for many PNGs, so allow this response longer than usual.
	http.NewResponseController(w).SetWriteDeadline(time.Now().Add(3 * time.Minute))
	w.Header().Set("Content-Type", "application/zip")
	w.Header().Set("Content-Disposition", `attachment; filename="qr-codes.zip"`)
	zw := zip.NewWriter(w)
	mapping := &bytes.Buffer{}
	mw := csv.NewWriter(mapping)
	mw.Write([]string{"label", "campaign", "destination", "short_url", "code", "file"})
	for _, l := range made {
		o := qr.Options{Content: s.shortURL(l.Code), ECC: l.QRECC, Design: design, Logo: logo}
		base := fmt.Sprintf("qr-%s", l.Code)
		if format == "svg" || format == "both" {
			if b, err := qr.RenderSVG(o); err == nil {
				addZip(zw, base+".svg", b)
			}
		}
		if format == "png" || format == "both" {
			if b, err := qr.RenderPNG(o, 512); err == nil {
				addZip(zw, base+".png", b)
			}
		}
		mw.Write([]string{csvCell(l.Label), csvCell(l.Campaign), l.DestinationURL, s.shortURL(l.Code), l.Code, base})
	}
	mw.Flush()
	addZip(zw, "codes.csv", mapping.Bytes())
	zw.Close()
}

func addZip(zw *zip.Writer, name string, b []byte) {
	f, err := zw.Create(name)
	if err == nil {
		f.Write(b)
	}
}

// csvCell neutralises spreadsheet formulas in user-supplied text.
func csvCell(s string) string {
	if s != "" && strings.ContainsRune("=+-@\t\r", rune(s[0])) {
		return "'" + s
	}
	return s
}

func parseID(s string) int64 {
	var n int64
	fmt.Sscan(s, &n)
	return n
}
