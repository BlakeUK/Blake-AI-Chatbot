package qr

import (
	"bytes"
	"encoding/base64"
	"fmt"
	"image"
	"image/color"
	"image/draw"
	"image/png"
	"math"
	"strconv"
	"strings"
	"sync"
	"unicode/utf8"

	qrcode "github.com/skip2/go-qrcode"
	xdraw "golang.org/x/image/draw"
	"golang.org/x/image/font"
	"golang.org/x/image/font/gofont/gobold"
	"golang.org/x/image/font/opentype"
	"golang.org/x/image/math/fixed"
)

// Options is everything needed to draw one code.
type Options struct {
	Content string
	ECC     string // L, M, Q or H
	Design  Design
	Logo    []byte // a PNG produced by ProcessLogo, or nil

	// Transparent leaves the background out, so the code can sit on any plain light surface. A box frame keeps
	// its own light plate, as the code is dark on it.
	Transparent bool
}

const (
	quiet    = 4.0  // blank border around the code, in modules (the QR standard)
	logoFrac = 0.22 // logo side as a fraction of the code side (about 5% of its area)
	roundedR = 0.35 // corner radius of a "rounded" dot, in modules
	dotR     = 0.46 // radius of a "dots" dot
	ss       = 3    // anti-aliasing: ss x ss samples per pixel
)

// geom is a laid-out code in "module units" (one data square = 1.0). The PNG
// and SVG outputs are both drawn from this one description so they match.
type geom struct {
	n          int
	bm         [][]bool
	d          Design
	fg, bg, ey color.RGBA
	qx, qy, q  float64 // the code area including its quiet zone
	w, h       float64 // whole canvas
	boxR       float64
	bandY      float64 // top of the label band
	bandH      float64
	label      string
	logoSide   float64 // 0 means no logo
	logo       []byte
}

func levelOf(ecc string) qrcode.RecoveryLevel {
	switch ecc {
	case "L":
		return qrcode.Low
	case "Q":
		return qrcode.High
	case "H":
		return qrcode.Highest
	}
	return qrcode.Medium
}

func newGeom(o Options) (*geom, error) {
	d, err := o.Design.Normalise()
	if err != nil {
		return nil, err
	}
	ecc := o.ECC
	if !ValidECC(ecc) {
		ecc = "M"
	}
	if len(o.Logo) > 0 && (ecc == "L" || ecc == "M") {
		ecc = "Q" // a logo hides part of the code; the extra error correction makes up for it
	}
	q, err := qrcode.New(o.Content, levelOf(ecc))
	if err != nil {
		return nil, userErr("That is too long to fit in a QR code.")
	}
	q.DisableBorder = true
	bm := q.Bitmap()
	g := &geom{n: len(bm), bm: bm, d: d, logo: o.Logo}
	g.fg, _ = parseHex(d.FG)
	g.bg, _ = parseHex(d.BG)
	g.ey, _ = parseHex(d.EyeCol())
	g.q = float64(g.n) + 2*quiet
	if len(o.Logo) > 0 {
		g.logoSide = float64(g.n) * logoFrac
	}
	switch d.Frame {
	case "box":
		const border, band = 1.2, 4.6
		g.w, g.h = g.q+2*border, g.q+2*border+band
		g.qx, g.qy, g.boxR = border, border, 2.4
		g.bandY, g.bandH = g.qy+g.q, g.h-(g.qy+g.q)
		g.label = d.CTA
	case "banner":
		const gap, band = 0.6, 4.2
		g.w, g.h = g.q, g.q+gap+band
		g.bandY, g.bandH = g.q+gap, band
		g.label = d.CTA
	default:
		g.w, g.h = g.q, g.q
	}
	return g, nil
}

// insideRR reports whether (px,py) is inside a rounded rectangle given by its
// centre, half-sizes and corner radius.
func insideRR(px, py, cx, cy, hx, hy, r float64) bool {
	qx := math.Abs(px-cx) - (hx - r)
	qy := math.Abs(py-cy) - (hy - r)
	return math.Hypot(math.Max(qx, 0), math.Max(qy, 0))+math.Min(math.Max(qx, qy), 0)-r <= 0
}

func (g *geom) inEye(cx, cy int) (ex, ey float64, ok bool) {
	n := g.n
	switch {
	case cx < 7 && cy < 7:
		return 0, 0, true
	case cx >= n-7 && cy < 7:
		return float64(n - 7), 0, true
	case cx < 7 && cy >= n-7:
		return 0, float64(n - 7), true
	}
	return 0, 0, false
}

// eyeDark reports whether point (ex,ey), measured from the top-left of a 7x7
// finder pattern, is drawn in the corner colour (otherwise it is background).
func (g *geom) eyeDark(ex, ey float64) bool {
	dx, dy := ex-3.5, ey-3.5
	switch g.d.Eye {
	case "circle":
		r := math.Hypot(dx, dy)
		return (r <= 3.5 && r > 2.5) || r <= 1.5
	case "rounded":
		outer := insideRR(dx, dy, 0, 0, 3.5, 3.5, 1.8)
		mid := insideRR(dx, dy, 0, 0, 2.5, 2.5, 1.1)
		core := insideRR(dx, dy, 0, 0, 1.5, 1.5, 0.7)
		return (outer && !mid) || core
	}
	d := math.Max(math.Abs(dx), math.Abs(dy))
	return (d <= 3.5 && d > 2.5) || d <= 1.5
}

func (g *geom) dotDark(u, v float64) bool {
	switch g.d.Pattern {
	case "rounded":
		return insideRR(u, v, 0.5, 0.5, 0.46, 0.46, roundedR)
	case "dots":
		return math.Hypot(u-0.5, v-0.5) <= dotR
	}
	return true
}

// clear is the see-through pixel used for a transparent background.
var clear = color.RGBA{}

func (g *geom) empty(see bool) color.RGBA {
	if see {
		return clear
	}
	return g.bg
}

// code returns the colour of the code area at canvas point (x,y).
func (g *geom) code(x, y float64) color.RGBA { return g.codeC(x, y, false) }

// codeC is code, but with see set the background (the quiet zone, the gaps in the corner squares and behind
// the logo) comes back see-through instead of the background colour.
func (g *geom) codeC(x, y float64, see bool) color.RGBA {
	mx, my := x-g.qx-quiet, y-g.qy-quiet
	if mx < 0 || my < 0 || mx >= float64(g.n) || my >= float64(g.n) {
		return g.empty(see)
	}
	if g.logoSide > 0 {
		half := g.logoSide/2 + 0.6
		if insideRR(mx, my, float64(g.n)/2, float64(g.n)/2, half, half, 0.9) {
			return g.empty(see)
		}
	}
	cx, cy := int(mx), int(my)
	if ox, oy, ok := g.inEye(cx, cy); ok {
		if g.eyeDark(mx-ox, my-oy) {
			return g.ey
		}
		return g.empty(see)
	}
	if g.bm[cy][cx] && g.dotDark(mx-float64(cx), my-float64(cy)) {
		return g.fg
	}
	return g.empty(see)
}

// sample returns the colour of the whole image (frame included) at (x,y).
func (g *geom) sample(x, y float64) color.RGBA { return g.sampleC(x, y, false) }

// sampleC is sample with an optional see-through background. A box frame keeps its own light plate (the code
// is dark on it) and is see-through only outside its rounded corners.
func (g *geom) sampleC(x, y float64, see bool) color.RGBA {
	switch g.d.Frame {
	case "box":
		if !insideRR(x, y, g.w/2, g.h/2, g.w/2, g.h/2, g.boxR) {
			return g.empty(see)
		}
		if insideRR(x, y, g.qx+g.q/2, g.qy+g.q/2, g.q/2, g.q/2, 1.2) {
			return g.code(x, y)
		}
		return g.fg
	case "banner":
		if y < g.q {
			return g.codeC(x, y, see)
		}
		if insideRR(x, y, g.w/2, g.bandY+g.bandH/2, g.w/2, g.bandH/2, 1.4) {
			return g.fg
		}
		return g.empty(see)
	}
	return g.codeC(x, y, see)
}

// labelSize returns the CTA font size in module units, shrunk to fit.
func (g *geom) labelSize() float64 {
	avail := g.w - 3
	size := math.Min(2.6, g.bandH*0.55)
	if w := 0.72 * float64(utf8.RuneCountInString(g.label)) * size; w > avail {
		size *= avail / w
	}
	return size
}

func (g *geom) labelCentre() (float64, float64) { return g.w / 2, g.bandY + g.bandH/2 }

var (
	fontOnce sync.Once
	boldFont *opentype.Font
	fontErr  error
)

func face(px float64) (font.Face, error) {
	fontOnce.Do(func() { boldFont, fontErr = opentype.Parse(gobold.TTF) })
	if fontErr != nil {
		return nil, fontErr
	}
	return opentype.NewFace(boldFont, &opentype.FaceOptions{Size: px, DPI: 72, Hinting: font.HintingFull})
}

// RenderPNG draws the code as a PNG that is px pixels wide.
func RenderPNG(o Options, px int) ([]byte, error) {
	if !ValidSize(px) {
		return nil, fmt.Errorf("unsupported size %d", px)
	}
	g, err := newGeom(o)
	if err != nil {
		return nil, err
	}
	scale := float64(px) / g.w
	hpx := int(math.Round(g.h * scale))
	if o.Transparent {
		return g.renderPNGAlpha(px, hpx, scale)
	}
	img := image.NewRGBA(image.Rect(0, 0, px, hpx))
	for y := 0; y < hpx; y++ {
		for x := 0; x < px; x++ {
			var r, gr, b int
			for sy := 0; sy < ss; sy++ {
				for sx := 0; sx < ss; sx++ {
					c := g.sample((float64(x)+(float64(sx)+0.5)/ss)/scale, (float64(y)+(float64(sy)+0.5)/ss)/scale)
					r += int(c.R)
					gr += int(c.G)
					b += int(c.B)
				}
			}
			img.SetRGBA(x, y, color.RGBA{uint8(r / (ss * ss)), uint8(gr / (ss * ss)), uint8(b / (ss * ss)), 255})
		}
	}
	if g.logoSide > 0 {
		if err := g.overlayLogo(img, scale); err != nil {
			return nil, err
		}
	}
	if g.label != "" {
		if err := g.drawLabel(img, scale); err != nil {
			return nil, err
		}
	}
	var buf bytes.Buffer
	if err := png.Encode(&buf, img); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}

func (g *geom) overlayLogo(dst draw.Image, scale float64) error {
	logo, err := decodeLogo(g.logo)
	if err != nil {
		return userErr("The stored logo could not be read.")
	}
	cx := (g.qx + quiet + float64(g.n)/2) * scale
	cy := (g.qy + quiet + float64(g.n)/2) * scale
	side := g.logoSide * scale
	lb := logo.Bounds()
	w, h := side, side
	if lb.Dx() > lb.Dy() {
		h = side * float64(lb.Dy()) / float64(lb.Dx())
	} else {
		w = side * float64(lb.Dx()) / float64(lb.Dy())
	}
	r := image.Rect(int(cx-w/2+0.5), int(cy-h/2+0.5), int(cx+w/2+0.5), int(cy+h/2+0.5))
	xdraw.CatmullRom.Scale(dst, r, logo, lb, xdraw.Over, nil)
	return nil
}

func (g *geom) drawLabel(dst draw.Image, scale float64) error {
	size := g.labelSize() * scale
	var f font.Face
	var err error
	maxW := fixed.I(int((g.w - 3) * scale))
	for ; size >= 6; size -= 0.5 {
		if f, err = face(size); err != nil {
			return err
		}
		if (&font.Drawer{Face: f}).MeasureString(g.label) <= maxW {
			break
		}
	}
	d := &font.Drawer{Dst: dst, Src: image.NewUniform(g.bg), Face: f}
	w := d.MeasureString(g.label)
	cx, cy := g.labelCentre()
	m := f.Metrics()
	d.Dot = fixed.Point26_6{
		X: fixed.I(int(cx*scale)) - w/2,
		Y: fixed.I(int(cy*scale)) + (m.Ascent-m.Descent)/2,
	}
	d.DrawString(g.label)
	return nil
}

func ff(v float64) string {
	s := strconv.FormatFloat(v, 'f', 3, 64)
	s = strings.TrimRight(strings.TrimRight(s, "0"), ".")
	if s == "" || s == "-" {
		return "0"
	}
	return s
}

// RenderSVG draws the code as a scalable vector image.
func RenderSVG(o Options) ([]byte, error) {
	g, err := newGeom(o)
	if err != nil {
		return nil, err
	}
	s := &svgSink{}
	drawVector(g, s, o.Transparent)
	return s.End()
}

func b64(b []byte) string { return base64.StdEncoding.EncodeToString(b) }

// renderPNGAlpha draws the code with a see-through background. Each pixel is the average of its sub-samples,
// weighted by how much of it is solid, so edges blend cleanly onto whatever the code is placed on.
func (g *geom) renderPNGAlpha(px, hpx int, scale float64) ([]byte, error) {
	img := image.NewNRGBA(image.Rect(0, 0, px, hpx))
	for y := 0; y < hpx; y++ {
		for x := 0; x < px; x++ {
			var r, gr, b, solid int
			for sy := 0; sy < ss; sy++ {
				for sx := 0; sx < ss; sx++ {
					c := g.sampleC((float64(x)+(float64(sx)+0.5)/ss)/scale, (float64(y)+(float64(sy)+0.5)/ss)/scale, true)
					if c.A == 0 {
						continue
					}
					r += int(c.R)
					gr += int(c.G)
					b += int(c.B)
					solid++
				}
			}
			if solid > 0 {
				img.SetNRGBA(x, y, color.NRGBA{uint8(r / solid), uint8(gr / solid), uint8(b / solid), uint8(solid * 255 / (ss * ss))})
			}
		}
	}
	if g.logoSide > 0 {
		if err := g.overlayLogo(img, scale); err != nil {
			return nil, err
		}
	}
	if g.label != "" {
		if err := g.drawLabel(img, scale); err != nil {
			return nil, err
		}
	}
	var buf bytes.Buffer
	if err := png.Encode(&buf, img); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}
