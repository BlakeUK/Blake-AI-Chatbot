package web

import (
	"bytes"
	"fmt"
	"image/png"
	"net/url"
	"strings"
	"testing"
)

func TestDownloadsInEveryFormat(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	dynID, dyn := h.create(c, "dynamic", "url", url.Values{"f_url": {"https://www.blake-uk.com/support.html"}})
	statID, stat := h.create(c, "static", "text", url.Values{"f_text": {"Thank you for visiting."}})
	for _, tc := range []struct {
		name string
		id   int64
		code string
	}{{"dynamic", dynID, dyn}, {"static", statID, stat}} {
		get := func(q string) (int, http_header, string) {
			resp, body := h.do(c, "GET", fmt.Sprintf("/admin/links/%d/download?%s", tc.id, q), nil, nil)
			return resp.StatusCode, http_header{resp.Header.Get("Content-Type"), resp.Header.Get("Content-Disposition")}, body
		}
		for _, f := range []struct{ q, ctype, name, magic string }{
			{"format=pdf", "application/pdf", "qr-" + tc.code + ".pdf", "%PDF-1.4"},
			{"format=eps", "application/postscript", "qr-" + tc.code + ".eps", "%!PS-Adobe-3.0 EPSF-3.0"},
			{"format=svg", "image/svg+xml", "qr-" + tc.code + ".svg", "<svg"},
			{"format=png", "image/png", "qr-" + tc.code + "-1024.png", "\x89PNG"},
			{"format=png&size=512", "image/png", "qr-" + tc.code + "-512.png", "\x89PNG"},
			{"format=pdf&bg=transparent", "application/pdf", "qr-" + tc.code + "-transparent.pdf", "%PDF-1.4"},
			{"format=svg&bg=transparent", "image/svg+xml", "qr-" + tc.code + "-transparent.svg", "<svg"},
		} {
			status, hd, body := get(f.q)
			if status != 200 || hd.ctype != f.ctype || !strings.HasPrefix(body, f.magic) || hd.disp != `attachment; filename="`+f.name+`"` {
				t.Errorf("%s %s: status %d type %q disposition %q starts %.12q", tc.name, f.q, status, hd.ctype, hd.disp, body)
			}
		}
		// transparency really is in the file
		_, _, opaque := get("format=png&size=256")
		_, _, clear := get("format=png&size=256&bg=transparent")
		oi, _ := png.Decode(bytes.NewReader([]byte(opaque)))
		ci, _ := png.Decode(bytes.NewReader([]byte(clear)))
		if _, _, _, a := oi.At(1, 1).RGBA(); a>>8 != 255 {
			t.Errorf("%s: the normal PNG corner should be solid, alpha %d", tc.name, a>>8)
		}
		if _, _, _, a := ci.At(1, 1).RGBA(); a != 0 {
			t.Errorf("%s: the transparent PNG corner should be see-through, alpha %d", tc.name, a>>8)
		}
		_, _, svgOpaque := get("format=svg")
		_, _, svgClear := get("format=svg&bg=transparent")
		if !strings.Contains(svgOpaque, `<rect width=`) || strings.Contains(svgClear, `<rect width="`) {
			t.Errorf("%s: only the transparent SVG should leave out the background rectangle", tc.name)
		}
		// bad requests
		for _, bad := range []string{"", "format=gif", "format=PDF", "format=png&size=300", "format=png&size=abc", "format=pdf&size=999999"} {
			status, _, _ := get(bad)
			want := 400
			if strings.HasPrefix(bad, "format=pdf&size") { // the size is ignored for vector files
				want = 200
			}
			if status != want {
				t.Errorf("%s %q: status %d, want %d", tc.name, bad, status, want)
			}
		}
	}
	// the code's page offers all of this
	_, page := h.do(c, "GET", fmt.Sprintf("/admin/links/%d", dynID), nil, nil)
	for _, want := range []string{"Print files and transparent versions", fmt.Sprintf(`action="/admin/links/%d/download"`, dynID), `value="pdf"`, `value="eps"`, `name="bg" value="transparent"`, "only scans on a plain light surface"} {
		if !strings.Contains(page, want) {
			t.Errorf("the code page should contain %q", want)
		}
	}
	// signed out
	if r, _ := h.do(noRedirect(), "GET", fmt.Sprintf("/admin/links/%d/download?format=pdf", dynID), nil, nil); r.StatusCode != 303 {
		t.Errorf("signed out: %d, want a redirect to sign in", r.StatusCode)
	}
}

type http_header struct{ ctype, disp string }

func TestHelpExplainsTheNewRoutingAndFiles(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	_, help := h.do(c, "GET", "/admin/help", nil, nil)
	for _, want := range []string{"Time of day (UK time)", "Mon-Fri 08:00-16:30", "Share of visitors (A/B test)", "Where visitors were sent", "Once a code has ended",
		"<strong>PDF</strong> is a vector file", "<strong>EPS</strong>", "Transparent background", "Never put a transparent code on a photo"} {
		if !strings.Contains(help, want) {
			t.Errorf("Help should say %q", want)
		}
	}
}
