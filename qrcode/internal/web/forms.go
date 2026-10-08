package web

import (
	"context"
	"encoding/json"
	"html/template"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"

	"golang.org/x/crypto/bcrypt"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/links"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/qr"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/qrtypes"
)

// fieldView is one type-specific input, ready to render.
type fieldView struct {
	Label, Help, Placeholder, Kind, Input, Value, Error string
	Required, Checked                                   bool
	Max                                                 int
	Options                                             []qrtypes.Option
}

// formView is everything the create/edit page needs.
type formView struct {
	Editing     bool
	ID          int64
	Kind        string
	Type        string
	Spec        qrtypes.Spec
	Fixed       bool // an existing static code: its content is printed in the code and cannot change
	FixedText   string
	Label       string
	Campaign    string
	Fields      []fieldView
	Window      string
	Start, End  string
	ExpiryMode  string
	FallbackURL string
	MaxScans    string
	HasPassword bool
	QRECC       string
	Design      qr.Design
	HasLogo     bool
	TemplateID  int64
	Templates   []links.Template
	Suggestions []string
	Errors      map[string]string
	Patterns    []string
	Eyes        []string
	Frames      []string

	DesignOnly   bool   // the standalone "New design" page, which has no QR code attached
	TemplateName string // the name of the design being edited or saved
	LinkID       int64  // the code being edited, so a design saved from its form keeps its logo
}

var presets = map[string]time.Duration{
	"24h": 24 * time.Hour,
	"7d":  7 * 24 * time.Hour,
	"30d": 30 * 24 * time.Hour,
	"90d": 90 * 24 * time.Hour,
}

const inputLayout = "2006-01-02T15:04"

func (s *Server) baseForm(ctx context.Context, kind string, spec qrtypes.Spec) *formView {
	now := s.now().In(s.cfg.Location)
	fv := &formView{
		Kind: kind, Type: spec.Type, Spec: spec, Window: "7d", ExpiryMode: string(links.RedirectUntracked), QRECC: "M",
		Start: now.Format(inputLayout), End: now.Add(presets["7d"]).Format(inputLayout),
		Errors: map[string]string{}, Patterns: qr.Patterns, Eyes: qr.Eyes, Frames: qr.Frames,
	}
	fv.Templates, _ = s.links.Templates(ctx)
	fv.Suggestions = s.campaignSuggestions(ctx)
	fv.Design, _ = qr.Design{}.Normalise()
	fv.setFields(nil, nil)
	return fv
}

func (fv *formView) setFields(vals, errs map[string]string) {
	fv.Fields = fv.Fields[:0]
	for _, f := range fv.Spec.Fields {
		v := vals[f.Name]
		fv.Fields = append(fv.Fields, fieldView{
			Label: f.Label, Help: f.Help, Placeholder: f.Placeholder, Kind: string(f.Kind), Input: "f_" + f.Name,
			Value: v, Error: errs[f.Name], Required: f.Required, Checked: v == "on" || v == "true", Max: f.Max, Options: f.Options,
		})
	}
}

// formFromLink fills the form from a saved code, for editing.
func (s *Server) formFromLink(ctx context.Context, l *links.Link, spec qrtypes.Spec) *formView {
	fv := s.baseForm(ctx, l.Kind, spec)
	fv.Editing, fv.ID, fv.LinkID = true, l.ID, l.ID
	fv.Label, fv.Campaign, fv.QRECC = l.Label, l.Campaign, l.QRECC
	fv.HasLogo, fv.HasPassword = l.HasLogo, l.PasswordHash != ""
	if l.MaxScans > 0 {
		fv.MaxScans = strconv.Itoa(l.MaxScans)
	}
	var d qr.Design
	json.Unmarshal([]byte(l.Design), &d)
	if nd, err := d.Normalise(); err == nil {
		fv.Design = nd
	}
	if l.IsStatic() {
		fv.Fixed, fv.FixedText = true, l.Content
		return fv
	}
	vals := map[string]string{}
	json.Unmarshal([]byte(l.Data), &vals)
	fv.setFields(vals, nil)
	fv.Window = "custom"
	fv.Start = l.TrackStart.In(s.cfg.Location).Format(inputLayout)
	fv.End = l.TrackEnd.In(s.cfg.Location).Format(inputLayout)
	fv.ExpiryMode, fv.FallbackURL = string(l.ExpiryMode), l.FallbackURL
	return fv
}

// designFrom reads a design from request values (form or query string).
func designFrom(get func(string) string) qr.Design {
	d := qr.Design{
		FG: strings.TrimSpace(get("fg")), BG: strings.TrimSpace(get("bg")), Pattern: get("pattern"), Eye: get("eye"),
		EyeColor: strings.TrimSpace(get("eye_color")), Frame: get("frame"), CTA: get("cta"),
	}
	if get("eye_same") != "" { // "corners match the dots"
		d.EyeColor = ""
	}
	return d
}

// PreviewSrc is the address of the preview image. It is built here and marked
// as a URL because html/template would otherwise treat everything after the ?
// as one value and escape the & and = between the parameters.
func (fv *formView) PreviewSrc() template.URL {
	return template.URL("/admin/preview.svg?" + fv.PreviewQS())
}

// PreviewQS is the query string for the live preview image.
func (fv *formView) PreviewQS() string {
	v := url.Values{}
	v.Set("fg", fv.Design.FG)
	v.Set("bg", fv.Design.BG)
	v.Set("pattern", fv.Design.Pattern)
	v.Set("eye", fv.Design.Eye)
	if fv.Design.EyeColor != "" {
		v.Set("eye_color", fv.Design.EyeColor)
	}
	v.Set("frame", fv.Design.Frame)
	v.Set("cta", fv.Design.CTA)
	v.Set("qr_ecc", fv.QRECC)
	if fv.Editing {
		v.Set("link", strconv.FormatInt(fv.ID, 10))
	} else if fv.TemplateID > 0 {
		v.Set("template", strconv.FormatInt(fv.TemplateID, 10))
	}
	return v.Encode()
}

// parseLinkForm validates a submitted create/edit form. existing is nil when
// creating. It returns the input to store and the view to re-render on error.
func (s *Server) parseLinkForm(r *http.Request, kind string, spec qrtypes.Spec, existing *links.Link) (links.Input, *formView) {
	ctx := r.Context()
	fv := s.baseForm(ctx, kind, spec)
	fv.Label = r.PostFormValue("label")
	fv.Campaign = r.PostFormValue("campaign")
	fv.QRECC = r.PostFormValue("qr_ecc")
	if existing != nil {
		fv.Editing, fv.ID = true, existing.ID
	}
	in := links.Input{Label: fv.Label, Campaign: fv.Campaign, Kind: kind, QRType: spec.Type, QRECC: fv.QRECC}

	// ----- the type's own fields -----
	vals := map[string]string{}
	for _, f := range spec.Fields {
		vals[f.Name] = r.PostFormValue("f_" + f.Name)
	}
	if existing != nil && existing.IsStatic() {
		fv.Fixed, fv.FixedText = true, existing.Content
		in.Content, in.Data = existing.Content, existing.Data
	} else {
		built, ferrs := qrtypes.Build(spec, kind, vals, s.cfg.Location, s.now())
		fv.setFields(vals, ferrs)
		for k, v := range ferrs {
			if k == "_" {
				fv.Errors["_"] = v
			} else {
				fv.Errors["f_"+k] = v
			}
		}
		if len(ferrs) == 0 {
			data, _ := json.Marshal(vals)
			in.Data = string(data)
			if kind == qrtypes.KindStatic {
				in.Content = built.Content
			} else {
				in.Destination = built.Target
				if built.Document != "" {
					in.Content = built.Document
				}
				for _, ru := range built.Rules {
					in.Rules = append(in.Rules, links.Rule{Match: ru.Match, Value: ru.Value, URL: ru.URL})
				}
			}
		}
	}

	// ----- tracking options (dynamic codes only) -----
	if kind == qrtypes.KindDynamic {
		fv.Window, fv.Start, fv.End = r.PostFormValue("window"), r.PostFormValue("start"), r.PostFormValue("end")
		fv.ExpiryMode, fv.FallbackURL = r.PostFormValue("expiry_mode"), strings.TrimSpace(r.PostFormValue("fallback_url"))
		in.ExpiryMode, in.FallbackURL = links.ExpiryMode(fv.ExpiryMode), fv.FallbackURL
		if d, ok := presets[fv.Window]; ok {
			in.Start = s.now().UTC().Truncate(time.Second)
			in.End = in.Start.Add(d)
		} else {
			st, e1 := time.ParseInLocation(inputLayout, fv.Start, s.cfg.Location)
			en, e2 := time.ParseInLocation(inputLayout, fv.End, s.cfg.Location)
			if e1 != nil || e2 != nil {
				fv.Errors["window"] = "Enter a valid start and end date and time."
			} else {
				in.Start, in.End = st.UTC(), en.UTC()
			}
		}
		fv.MaxScans = strings.TrimSpace(r.PostFormValue("max_scans"))
		if fv.MaxScans != "" {
			n, err := strconv.Atoi(fv.MaxScans)
			if err != nil || n < 0 {
				fv.Errors["max_scans"] = "Enter a whole number, or leave it empty for no limit."
			}
			in.MaxScans = n
		}
		switch pw := r.PostFormValue("link_password"); {
		case pw != "":
			if len(pw) < 4 || len(pw) > 72 {
				fv.Errors["password"] = "The password must be 4 to 72 characters."
			} else {
				cost := s.auth.Cost
				if cost > 10 {
					cost = 10
				}
				h, err := bcrypt.GenerateFromPassword([]byte(pw), cost)
				if err != nil {
					fv.Errors["password"] = "The password could not be set."
				}
				in.PasswordHash = string(h)
			}
		case existing != nil && existing.PasswordHash != "" && r.PostFormValue("clear_password") == "":
			in.KeepPassword, fv.HasPassword = true, true
		}
	}

	// ----- design and logo -----
	d := designFrom(r.PostFormValue)
	nd, derr := d.Normalise()
	fv.Design = nd
	if derr != nil {
		fv.Errors["design"] = derr.Error()
		fv.Design, _ = qr.Design{}.Normalise()
		fv.Design = d
	}
	dj, _ := json.Marshal(nd)
	in.Design = string(dj)
	if tid, _ := strconv.ParseInt(r.PostFormValue("template_id"), 10, 64); tid > 0 {
		fv.TemplateID = tid
	}
	var logo []byte
	switch {
	case r.PostFormValue("remove_logo") != "":
		in.LogoSet = true
	default:
		if f, _, err := r.FormFile("logo"); err == nil {
			b, lerr := qr.ProcessLogo(f)
			f.Close()
			if lerr != nil {
				fv.Errors["logo"] = lerr.Error()
			} else {
				logo, in.Logo, in.LogoSet = b, b, true
			}
		} else if existing == nil && fv.TemplateID > 0 {
			if _, tl, err := s.links.TemplateByID(ctx, fv.TemplateID); err == nil && len(tl) > 0 {
				logo, in.Logo, in.LogoSet = tl, tl, true
			}
		} else if existing != nil && existing.HasLogo {
			logo, _ = s.links.Logo(ctx, existing.ID)
		}
	}
	fv.HasLogo = len(logo) > 0 || (existing != nil && existing.HasLogo && !in.LogoSet)

	// ----- general validation -----
	clean, errs := in.Clean(s.host)
	for k, v := range errs {
		if _, seen := fv.Errors[k]; !seen {
			switch k {
			case "destination":
				// the address lives in a type field; show the message there
				fv.Errors["_"] = v
			default:
				fv.Errors[k] = v
			}
		}
	}
	// a code that cannot be drawn must be caught now, not at print time
	if len(fv.Errors) == 0 {
		content := clean.Content
		if kind == qrtypes.KindDynamic {
			content = s.cfg.BaseURL + "/r/AbCd1234"
		}
		if _, err := qr.RenderSVG(qr.Options{Content: content, ECC: clean.QRECC, Design: nd, Logo: logo}); err != nil {
			fv.Errors["_"] = err.Error()
		}
	}
	return clean, fv
}

// saveTemplateIfAsked stores the design as a reusable template when the form asks.
func (s *Server) saveTemplateIfAsked(ctx context.Context, r *http.Request, in links.Input, existing *links.Link) error {
	name := strings.TrimSpace(r.PostFormValue("template_name"))
	if name == "" {
		return nil
	}
	logo := in.Logo
	if len(logo) == 0 && !in.LogoSet && existing != nil && existing.HasLogo {
		logo, _ = s.links.Logo(ctx, existing.ID)
	}
	return s.links.SaveTemplate(ctx, name, in.Design, logo)
}
