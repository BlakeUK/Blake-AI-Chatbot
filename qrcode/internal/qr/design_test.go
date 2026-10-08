package qr

import (
	"bytes"
	"encoding/xml"
	"fmt"
	"image"
	"image/color"
	"image/png"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

func TestNormaliseDefaultsAndRules(t *testing.T) {
	d, err := Design{}.Normalise()
	if err != nil || d.FG != "#000000" || d.BG != "#ffffff" || d.Pattern != "square" || d.Eye != "square" || d.Frame != "none" || d.CTA != "" {
		t.Fatalf("zero design must be plain black on white: %+v %v", d, err)
	}
	d, err = Design{FG: "#036", BG: "#FFF", Frame: "box"}.Normalise()
	if err != nil || d.FG != "#003366" || d.BG != "#ffffff" || d.CTA != "SCAN ME" {
		t.Errorf("short hex / default CTA: %+v %v", d, err)
	}
	good := []Design{{FG: "#0b2a6f"}, {FG: "#1b5e20", BG: "#fff8e1"}, {FG: "#000", EyeColor: "#8b0000"}}
	for _, g := range good {
		if _, err := g.Normalise(); err != nil {
			t.Errorf("%+v rejected: %v", g, err)
		}
	}
	bad := map[string]Design{
		"yellow on white":   {FG: "#ffeb3b"},
		"light grey":        {FG: "#bbbbbb"},
		"identical":         {FG: "#ffffff", BG: "#ffffff"},
		"inverted":          {FG: "#ffffff", BG: "#000000"},
		"not a colour":      {FG: "red"},
		"bad hex":           {FG: "#12345"},
		"bad background":    {BG: "#zzzzzz"},
		"pale corners":      {FG: "#000000", EyeColor: "#ffff99"},
		"unknown pattern":   {Pattern: "stars"},
		"unknown eye":       {Eye: "triangle"},
		"unknown frame":     {Frame: "neon"},
		"cta too long":      {Frame: "box", CTA: strings.Repeat("x", 25)},
		"cta control chars": {Frame: "box", CTA: "a\x00b"},
		"colour injection":  {FG: `#000" onload="x`},
	}
	for name, d := range bad {
		if _, err := d.Normalise(); err == nil {
			t.Errorf("%s accepted: %+v", name, d)
		}
	}
	if c := Contrast(color.RGBA{0, 0, 0, 255}, color.RGBA{255, 255, 255, 255}); c < 20.9 || c > 21.1 {
		t.Errorf("black/white contrast = %v, want 21", c)
	}
}

func TestSVGIsWellFormedAndSafe(t *testing.T) {
	logo := makeLogo(t, 300)
	for _, d := range []Design{{}, {Pattern: "dots", Eye: "circle", Frame: "box", CTA: `A&B <i onload="x">`}, {Pattern: "rounded", Eye: "rounded", Frame: "banner"}} {
		b, err := RenderSVG(Options{Content: sample, ECC: "H", Design: d, Logo: logo})
		if err != nil {
			t.Fatal(err)
		}
		dec := xml.NewDecoder(bytes.NewReader(b))
		for {
			if _, err := dec.Token(); err != nil {
				if err.Error() == "EOF" {
					break
				}
				t.Fatalf("not well-formed XML (%+v): %v", d, err)
			}
		}
		s := string(b)
		if strings.Contains(s, "<i onload") || strings.Contains(s, "<script") {
			t.Errorf("unescaped markup in SVG for %+v", d)
		}
		if d.CTA != "" && !strings.Contains(s, "A&amp;B &lt;i onload=&#34;x&#34;&gt;") {
			t.Errorf("call to action was not XML-escaped: %.200s", s[strings.Index(s, "<text"):])
		}
		if !strings.Contains(s, "<image") {
			t.Error("logo missing from SVG")
		}
		if d.Frame != "none" && d.Frame != "" && !strings.Contains(s, "<text") {
			t.Error("call to action missing from SVG")
		}
	}
}

func TestFramedPNGGrowsTaller(t *testing.T) {
	size := func(d Design) (int, int) {
		b, err := RenderPNG(Options{Content: sample, ECC: "M", Design: d}, 512)
		if err != nil {
			t.Fatal(err)
		}
		img, err := png.Decode(bytes.NewReader(b))
		if err != nil {
			t.Fatal(err)
		}
		return img.Bounds().Dx(), img.Bounds().Dy()
	}
	w0, h0 := size(Design{})
	w1, h1 := size(Design{Frame: "box"})
	w2, h2 := size(Design{Frame: "banner"})
	if w0 != 512 || h0 != 512 || w1 != 512 || w2 != 512 {
		t.Errorf("width must always be the requested size: %d %d %d", w0, w1, w2)
	}
	if h1 <= 512 || h2 <= 512 {
		t.Errorf("a frame adds a label band: heights %d %d", h1, h2)
	}
}

func makeLogo(t *testing.T, side int) []byte {
	t.Helper()
	img := image.NewRGBA(image.Rect(0, 0, side, side))
	for y := 0; y < side; y++ {
		for x := 0; x < side; x++ {
			dx, dy := float64(x-side/2), float64(y-side/2)
			if dx*dx+dy*dy < float64(side*side)/4*0.8 {
				img.Set(x, y, color.RGBA{200, 30, 30, 255}) // red disc
				if x > side/3 && x < 2*side/3 && y > side/3 && y < 2*side/3 {
					img.Set(x, y, color.RGBA{255, 255, 255, 255})
				}
			}
		}
	}
	var buf bytes.Buffer
	png.Encode(&buf, img)
	out, err := ProcessLogo(&buf)
	if err != nil {
		t.Fatal(err)
	}
	return out
}

func TestProcessLogoRejectsBadUploads(t *testing.T) {
	var big bytes.Buffer
	png.Encode(&big, image.NewGray(image.Rect(0, 0, 5000, 5000))) // tiny when compressed, huge when decoded
	var small bytes.Buffer
	png.Encode(&small, image.NewGray(image.Rect(0, 0, 8, 8)))
	var ok bytes.Buffer
	png.Encode(&ok, image.NewGray(image.Rect(0, 0, 64, 64)))
	trunc := ok.Bytes()[:ok.Len()/2]
	cases := map[string][]byte{
		"text":               []byte("hello"),
		"html":               []byte("<html><script>alert(1)</script></html>"),
		"svg":                []byte(`<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>`),
		"empty":              {},
		"decompression bomb": big.Bytes(),
		"too small":          small.Bytes(),
		"truncated png":      trunc,
		"over 1MB":           append(ok.Bytes(), bytes.Repeat([]byte{0}, MaxLogoBytes)...),
	}
	for name, data := range cases {
		if _, err := ProcessLogo(bytes.NewReader(data)); err == nil {
			t.Errorf("%s accepted", name)
		}
	}
	// A valid upload comes back as a clean PNG no larger than 256px, with trailing junk removed.
	withJunk := append(append([]byte{}, ok.Bytes()...), []byte("<?php evil ?>")...)
	out, err := ProcessLogo(bytes.NewReader(withJunk))
	if err != nil {
		t.Fatal(err)
	}
	if bytes.Contains(out, []byte("evil")) {
		t.Error("appended payload survived re-encoding")
	}
	img, err := png.Decode(bytes.NewReader(out))
	if err != nil || img.Bounds().Dx() > 256 {
		t.Errorf("stored logo: %v %v", err, img.Bounds())
	}
}

// ---------- the scannability matrix, decoded by real software ----------

func zbarDecode(t *testing.T, path string) string {
	t.Helper()
	out, err := exec.Command("zbarimg", "--raw", "-q", "-Sdisable", "-Sqrcode.enable", path).Output()
	if err != nil {
		return ""
	}
	return strings.TrimSpace(string(out))
}

func needTool(t *testing.T, names ...string) {
	for _, n := range names {
		if _, err := exec.LookPath(n); err != nil {
			t.Skipf("%s not installed", n)
		}
	}
}

func TestEveryStyleStillScans(t *testing.T) {
	needTool(t, "zbarimg")
	dir := t.TempDir()
	navy := "#0b2a6f"
	n, failed := 0, 0
	try := func(name string, o Options, px int) {
		t.Helper()
		b, err := RenderPNG(o, px)
		if err != nil {
			t.Errorf("%s: render: %v", name, err)
			return
		}
		p := filepath.Join(dir, fmt.Sprintf("%d.png", n))
		os.WriteFile(p, b, 0o600)
		n++
		if got := zbarDecode(t, p); got != strings.TrimSpace(o.Content) { // the reader trims the trailing line break
			failed++
			t.Errorf("%s does NOT scan: decoded %q", name, got)
		}
	}
	for _, pat := range Patterns {
		for _, eye := range Eyes {
			for _, fr := range Frames {
				d := Design{FG: navy, Pattern: pat, Eye: eye, Frame: fr, CTA: "SCAN ME"}
				try(fmt.Sprintf("pattern=%s eye=%s frame=%s", pat, eye, fr), Options{Content: sample, ECC: "M", Design: d}, 512)
			}
		}
	}
	// colours, including a coloured background and a different corner colour
	for name, d := range map[string]Design{
		"black":          {},
		"dark green":     {FG: "#1b5e20", BG: "#fff8e1"},
		"maroon corners": {FG: "#000000", EyeColor: "#8b0000", Pattern: "rounded", Eye: "rounded"},
		"deep purple":    {FG: "#311b92", Pattern: "dots", Eye: "circle"},
	} {
		try("colours "+name, Options{Content: sample, ECC: "M", Design: d}, 512)
	}
	// every size, and every error-correction level
	for _, px := range Sizes {
		try(fmt.Sprintf("size %d", px), Options{Content: sample, ECC: "M", Design: Design{Pattern: "dots", Eye: "circle", Frame: "box"}}, px)
	}
	for _, ecc := range []string{"L", "M", "Q", "H"} {
		try("ecc "+ecc, Options{Content: sample, ECC: ecc, Design: Design{Pattern: "rounded"}}, 512)
	}
	// content of different lengths and kinds (a long URL, a vCard, Wi-Fi, unicode)
	for name, c := range map[string]string{
		"long url":  "https://www.blake-uk.com/category/aerials-tv-highgain.html?utm_source=poster&utm_campaign=autumn-launch-2026&ref=abcdefghijklmnop",
		"vcard":     "BEGIN:VCARD\r\nVERSION:3.0\r\nN:Smith;Ann;;;\r\nFN:Ann Smith\r\nORG:Blake UK\r\nTEL;TYPE=WORK,VOICE:+441142235000\r\nEND:VCARD\r\n",
		"wifi":      `WIFI:T:WPA;S:Blake Guest;P:correct\;horse;;`,
		"unicode":   "Café \u00e9\u00e8 \u2603 \u4e2d\u6587",
		"multiline": "line one\nline two\nline three",
	} {
		try("content "+name, Options{Content: c, ECC: "M", Design: Design{FG: navy, Pattern: "rounded", Eye: "rounded", Frame: "banner"}}, 1024)
	}
	t.Logf("%d styled codes rendered and decoded, %d failed", n, failed)
}

func TestLogoCodesStillScan(t *testing.T) {
	needTool(t, "zbarimg")
	dir := t.TempDir()
	logo := makeLogo(t, 400)
	n := 0
	for _, ecc := range []string{"L", "M", "Q", "H"} { // L and M are raised automatically
		for _, pat := range Patterns {
			for _, fr := range []string{"none", "box"} {
				o := Options{Content: sample, ECC: ecc, Logo: logo, Design: Design{FG: "#0b2a6f", Pattern: pat, Eye: "rounded", Frame: fr}}
				b, err := RenderPNG(o, 512)
				if err != nil {
					t.Fatal(err)
				}
				p := filepath.Join(dir, fmt.Sprintf("l%d.png", n))
				n++
				os.WriteFile(p, b, 0o600)
				if got := zbarDecode(t, p); got != sample {
					t.Errorf("logo code (ecc=%s pattern=%s frame=%s) does NOT scan: %q", ecc, pat, fr, got)
				}
			}
		}
	}
	// and the logo really is drawn: the middle of the image is not plain code
	b, _ := RenderPNG(Options{Content: sample, ECC: "H", Logo: logo}, 512)
	img, _ := png.Decode(bytes.NewReader(b))
	r, g, bl, _ := img.At(256, 256).RGBA()
	_ = g
	_ = bl
	if r>>8 < 150 {
		t.Error("expected the red logo disc in the centre of the image")
	}
}

func TestSVGOutputScansToo(t *testing.T) {
	needTool(t, "zbarimg", "rsvg-convert")
	dir := t.TempDir()
	logo := makeLogo(t, 400)
	n := 0
	for _, pat := range Patterns {
		for _, fr := range Frames {
			for _, withLogo := range []bool{false, true} {
				o := Options{Content: sample, ECC: "H", Design: Design{FG: "#0b2a6f", Pattern: pat, Eye: "circle", Frame: fr}}
				if withLogo {
					o.Logo = logo
				}
				svg, err := RenderSVG(o)
				if err != nil {
					t.Fatal(err)
				}
				sp, pp := filepath.Join(dir, fmt.Sprintf("%d.svg", n)), filepath.Join(dir, fmt.Sprintf("%d.png", n))
				n++
				os.WriteFile(sp, svg, 0o600)
				if out, err := exec.Command("rsvg-convert", "-w", "640", "-o", pp, sp).CombinedOutput(); err != nil {
					t.Fatalf("rsvg-convert: %v %s", err, out)
				}
				if got := zbarDecode(t, pp); got != sample {
					t.Errorf("SVG (pattern=%s frame=%s logo=%v) does NOT scan: %q", pat, fr, withLogo, got)
				}
			}
		}
	}
}
