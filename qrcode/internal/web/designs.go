package web

import (
	"context"
	"encoding/json"
	"html/template"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/auth"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/links"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/qr"
)

func (s *Server) designOnlyView(ctx context.Context) *formView {
	fv := &formView{DesignOnly: true, QRECC: "M", Errors: map[string]string{}, Patterns: qr.Patterns, Eyes: qr.Eyes, Frames: qr.Frames}
	fv.Design, _ = qr.Design{}.Normalise()
	return fv
}

// designNew shows the standalone design page, optionally pre-filled from a
// saved design so it can be edited (saving under the same name replaces it).
func (s *Server) designNew(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	fv := s.designOnlyView(r.Context())
	if tid := parseID(r.URL.Query().Get("template")); tid > 0 {
		if t, _, err := s.links.TemplateByID(r.Context(), tid); err == nil {
			var d qr.Design
			json.Unmarshal([]byte(t.Design), &d)
			if nd, err := d.Normalise(); err == nil {
				fv.Design, fv.TemplateID, fv.TemplateName, fv.HasLogo = nd, t.ID, t.Name, t.HasLogo
			}
		}
	}
	s.render(w, http.StatusOK, "template_form", s.page(sess, "New design", fv))
}

// designSave stores a design (colours, styles, frame, text and logo) under a
// name, without creating a QR code. It is used by the standalone design page
// and by the "Save design only" button on the QR code form.
func (s *Server) designSave(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	ctx := r.Context()
	fv := s.designOnlyView(ctx)
	fv.TemplateName = strings.Join(strings.Fields(r.PostFormValue("template_name")), " ")
	fv.TemplateID = parseID(r.PostFormValue("template_id"))
	fv.LinkID = parseID(r.PostFormValue("link_id"))
	fv.QRECC = r.PostFormValue("qr_ecc")
	if !qr.ValidECC(fv.QRECC) {
		fv.QRECC = "M"
	}

	switch n := utf8.RuneCountInString(fv.TemplateName); {
	case n == 0:
		fv.Errors["template_name"] = "Give the design a name so you can find it later."
	case n > 60:
		fv.Errors["template_name"] = "The name must be 60 characters or fewer."
	}
	d, derr := designFrom(r.PostFormValue).Normalise()
	fv.Design = d
	if derr != nil {
		fv.Errors["design"] = derr.Error()
		fv.Design = designFrom(r.PostFormValue)
	}
	dj, _ := json.Marshal(d)

	// the logo: a new upload, else the one already attached to the design or code being worked on
	var logo []byte
	if r.PostFormValue("remove_logo") == "" {
		if f, _, err := r.FormFile("logo"); err == nil {
			b, lerr := qr.ProcessLogo(f)
			f.Close()
			if lerr != nil {
				fv.Errors["logo"] = lerr.Error()
			} else {
				logo = b
			}
		} else if fv.TemplateID > 0 {
			_, logo, _ = s.links.TemplateByID(ctx, fv.TemplateID)
		} else if fv.LinkID > 0 {
			logo, _ = s.links.Logo(ctx, fv.LinkID)
		}
	}
	fv.HasLogo = len(logo) > 0

	if len(fv.Errors) == 0 {
		if _, err := qr.RenderSVG(qr.Options{Content: s.shortURL("AbCd1234"), ECC: fv.QRECC, Design: d, Logo: logo}); err != nil {
			fv.Errors["design"] = err.Error()
		}
	}
	if len(fv.Errors) > 0 {
		s.render(w, http.StatusUnprocessableEntity, "template_form", s.page(sess, "New design", fv))
		return
	}
	if err := s.links.SaveTemplate(ctx, fv.TemplateName, string(dj), logo); err != nil {
		s.serverError(w, "save design", err)
		return
	}
	s.auth.Audit(ctx, sess.User.Username, "design.save", fv.TemplateName, "")
	http.Redirect(w, r, "/admin/templates?saved="+url.QueryEscape(fv.TemplateName), http.StatusSeeOther)
}

type designRow struct {
	links.Template
	Src template.URL // the address of a thumbnail of it (see formView.PreviewSrc)
}

func (s *Server) templatesPage(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	list, err := s.links.Templates(r.Context())
	if err != nil {
		s.serverError(w, "templates", err)
		return
	}
	rows := make([]designRow, len(list))
	for i, t := range list {
		var d qr.Design
		json.Unmarshal([]byte(t.Design), &d)
		v := url.Values{}
		v.Set("fg", d.FG)
		v.Set("bg", d.BG)
		v.Set("pattern", d.Pattern)
		v.Set("eye", d.Eye)
		v.Set("eye_color", d.EyeColor)
		v.Set("frame", d.Frame)
		v.Set("cta", d.CTA)
		v.Set("template", strconv.FormatInt(t.ID, 10))
		rows[i] = designRow{Template: t, Src: template.URL("/admin/preview.svg?" + v.Encode())}
	}
	pd := s.page(sess, "Saved designs", map[string]any{"Designs": rows})
	if name := r.URL.Query().Get("saved"); name != "" {
		pd.Flash = "Saved the design \u201c" + truncate(name, 60) + "\u201d. You can now use it on any QR code."
	}
	s.render(w, http.StatusOK, "templates", pd)
}
