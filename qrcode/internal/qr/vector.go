package qr

import (
	"bytes"
	"compress/zlib"
	"encoding/hex"
	"fmt"
	"html"
	"image"
	"image/color"
	"image/png"
	"math"
	"strings"
	"sync"

	"golang.org/x/image/font"
	"golang.org/x/image/font/gofont/gobold"
	"golang.org/x/image/font/sfnt"
	"golang.org/x/image/math/fixed"
)

// One drawing routine, three outputs. drawVector walks the laid-out code and tells a sink what to draw; the
// SVG sink writes exactly the text RenderSVG always has, and the PDF and EPS sinks write the same shapes as
// print-ready vectors (with the label turned into outlines, so no font is needed to print it).

// run is a horizontal row of dark modules (the "square" pattern is drawn as runs).
type run struct {
	x, y float64
	n    int
}

type sink interface {
	Begin(w, h float64)
	Rect(x, y, w, h, rx float64, fill string) // fill "" means the surrounding group's fill
	Circle(cx, cy, r float64, fill string)
	GroupBegin(fill string)
	GroupEnd()
	Runs(runs []run)
	RingRect(x, y, w, rxOuter, inset, rxInner float64, fill string) // a square ring with a true hole
	RingCircle(cx, cy, rOuter, rInner float64, fill string)
	Image(pngBytes []byte, x, y, w, h float64)
	Text(s string, cx, cy, size float64, fill string)
	End() ([]byte, error)
}

// drawVector draws the code. With transparent set the background is left out; a box frame keeps its own
// light plate (the code is dark on it), everything else becomes see-through, including the gaps inside the
// three corner squares.
func drawVector(g *geom, s sink, transparent bool) {
	holes := transparent && g.d.Frame != "box"
	s.Begin(g.w, g.h)
	if !transparent {
		s.Rect(0, 0, g.w, g.h, 0, g.d.BG)
	}
	ox, oy := g.qx+quiet, g.qy+quiet
	switch g.d.Frame {
	case "box":
		s.Rect(0, 0, g.w, g.h, g.boxR, g.d.FG)
		s.Rect(g.qx, g.qy, g.q, g.q, 1.2, g.d.BG)
	case "banner":
		s.Rect(0, g.bandY, g.w, g.bandH, 1.4, g.d.FG)
	}

	skip := func(cx, cy int) bool {
		if _, _, ok := g.inEye(cx, cy); ok {
			return true
		}
		if g.logoSide > 0 {
			half := g.logoSide/2 + 0.6
			mx, my := float64(cx)+0.5-float64(g.n)/2, float64(cy)+0.5-float64(g.n)/2
			return insideRR(mx, my, 0, 0, half, half, 0.9)
		}
		return false
	}
	s.GroupBegin(g.d.FG)
	switch g.d.Pattern {
	case "square":
		var runs []run
		for y := 0; y < g.n; y++ {
			for x := 0; x < g.n; {
				if !g.bm[y][x] || skip(x, y) {
					x++
					continue
				}
				start := x
				for x < g.n && g.bm[y][x] && !skip(x, y) {
					x++
				}
				runs = append(runs, run{ox + float64(start), oy + float64(y), x - start})
			}
		}
		s.Runs(runs)
	case "rounded":
		for y := 0; y < g.n; y++ {
			for x := 0; x < g.n; x++ {
				if g.bm[y][x] && !skip(x, y) {
					s.Rect(ox+float64(x)+0.04, oy+float64(y)+0.04, 0.92, 0.92, roundedR, "")
				}
			}
		}
	case "dots":
		for y := 0; y < g.n; y++ {
			for x := 0; x < g.n; x++ {
				if g.bm[y][x] && !skip(x, y) {
					s.Circle(ox+float64(x)+0.5, oy+float64(y)+0.5, dotR, "")
				}
			}
		}
	}
	s.GroupEnd()

	for _, p := range [][2]int{{0, 0}, {g.n - 7, 0}, {0, g.n - 7}} {
		drawEye(g, s, ox+float64(p[0]), oy+float64(p[1]), holes)
	}

	if g.logoSide > 0 {
		half := g.logoSide/2 + 0.6
		cx, cy := ox+float64(g.n)/2, oy+float64(g.n)/2
		if !holes {
			s.Rect(cx-half, cy-half, 2*half, 2*half, 0.9, g.d.BG)
		}
		s.Image(g.logo, cx-g.logoSide/2, cy-g.logoSide/2, g.logoSide, g.logoSide)
	}
	if g.label != "" {
		cx, cy := g.labelCentre()
		s.Text(g.label, cx, cy, g.labelSize(), g.d.BG)
	}
}

// drawEye draws one corner square at (x,y), the top-left of its 7x7 area. Normally the white gap is painted in
// the background colour; with holes it is a real gap, so the code can sit on any plain light surface.
func drawEye(g *geom, s sink, x, y float64, holes bool) {
	switch g.d.Eye {
	case "circle":
		cx, cy := x+3.5, y+3.5
		if holes {
			s.RingCircle(cx, cy, 3.5, 2.5, g.d.EyeCol())
			s.Circle(cx, cy, 1.5, g.d.EyeCol())
			return
		}
		s.Circle(cx, cy, 3.5, g.d.EyeCol())
		s.Circle(cx, cy, 2.5, g.d.BG)
		s.Circle(cx, cy, 1.5, g.d.EyeCol())
	case "rounded":
		if holes {
			s.RingRect(x, y, 7, 1.8, 1, 1.1, g.d.EyeCol())
			s.Rect(x+2, y+2, 3, 3, 0.7, g.d.EyeCol())
			return
		}
		s.Rect(x, y, 7, 7, 1.8, g.d.EyeCol())
		s.Rect(x+1, y+1, 5, 5, 1.1, g.d.BG)
		s.Rect(x+2, y+2, 3, 3, 0.7, g.d.EyeCol())
	default:
		if holes {
			s.RingRect(x, y, 7, 0, 1, 0, g.d.EyeCol())
			s.Rect(x+2, y+2, 3, 3, 0, g.d.EyeCol())
			return
		}
		s.Rect(x, y, 7, 7, 0, g.d.EyeCol())
		s.Rect(x+1, y+1, 5, 5, 0, g.d.BG)
		s.Rect(x+2, y+2, 3, 3, 0, g.d.EyeCol())
	}
}

// ---------------------------------------------------------------- SVG

type svgSink struct{ b strings.Builder }

func (s *svgSink) Begin(w, h float64) {
	fmt.Fprintf(&s.b, `<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 %s %s" width="%s" height="%s" shape-rendering="geometricPrecision">`,
		ff(w), ff(h), ff(w*8), ff(h*8))
}

func (s *svgSink) Rect(x, y, w, h, rx float64, fill string) {
	s.b.WriteString("<rect")
	if x != 0 {
		fmt.Fprintf(&s.b, ` x="%s"`, ff(x))
	}
	if y != 0 {
		fmt.Fprintf(&s.b, ` y="%s"`, ff(y))
	}
	fmt.Fprintf(&s.b, ` width="%s" height="%s"`, ff(w), ff(h))
	if rx != 0 {
		fmt.Fprintf(&s.b, ` rx="%s"`, ff(rx))
	}
	if fill != "" {
		fmt.Fprintf(&s.b, ` fill="%s"`, fill)
	}
	s.b.WriteString("/>")
}

func (s *svgSink) Circle(cx, cy, r float64, fill string) {
	fmt.Fprintf(&s.b, `<circle cx="%s" cy="%s" r="%s"`, ff(cx), ff(cy), ff(r))
	if fill != "" {
		fmt.Fprintf(&s.b, ` fill="%s"`, fill)
	}
	s.b.WriteString("/>")
}

func (s *svgSink) GroupBegin(fill string) { fmt.Fprintf(&s.b, `<g fill="%s">`, fill) }
func (s *svgSink) GroupEnd()              { s.b.WriteString(`</g>`) }

func (s *svgSink) Runs(runs []run) {
	s.b.WriteString(`<path d="`)
	for _, r := range runs {
		fmt.Fprintf(&s.b, "M%s %sh%dv1h-%dz", ff(r.x), ff(r.y), r.n, r.n)
	}
	s.b.WriteString(`"/>`)
}

func svgRRect(x, y, w, h, r float64) string {
	if r <= 0 {
		return fmt.Sprintf("M%s %sh%sv%sh-%sz", ff(x), ff(y), ff(w), ff(h), ff(w))
	}
	return fmt.Sprintf("M%s %sh%sa%s %s 0 0 1 %s %sv%sa%s %s 0 0 1 -%s %sh-%sa%s %s 0 0 1 -%s -%sv-%sa%s %s 0 0 1 %s -%sz",
		ff(x+r), ff(y), ff(w-2*r), ff(r), ff(r), ff(r), ff(r), ff(h-2*r), ff(r), ff(r), ff(r), ff(r), ff(w-2*r), ff(r), ff(r), ff(r), ff(r), ff(h-2*r), ff(r), ff(r), ff(r), ff(r))
}

func svgCircle(cx, cy, r float64) string {
	return fmt.Sprintf("M%s %sa%s %s 0 1 0 %s 0a%s %s 0 1 0 -%s 0z", ff(cx-r), ff(cy), ff(r), ff(r), ff(2*r), ff(r), ff(r), ff(2*r))
}

func (s *svgSink) RingRect(x, y, w, rxO, inset, rxI float64, fill string) {
	fmt.Fprintf(&s.b, `<path fill-rule="evenodd" d="%s%s" fill="%s"/>`, svgRRect(x, y, w, w, rxO), svgRRect(x+inset, y+inset, w-2*inset, w-2*inset, rxI), fill)
}

func (s *svgSink) RingCircle(cx, cy, rO, rI float64, fill string) {
	fmt.Fprintf(&s.b, `<path fill-rule="evenodd" d="%s%s" fill="%s"/>`, svgCircle(cx, cy, rO), svgCircle(cx, cy, rI), fill)
}

func (s *svgSink) Image(b []byte, x, y, w, h float64) {
	uri := "data:image/png;base64," + b64(b)
	fmt.Fprintf(&s.b, `<image x="%s" y="%s" width="%s" height="%s" preserveAspectRatio="xMidYMid meet" href="%s" xlink:href="%s"/>`, ff(x), ff(y), ff(w), ff(h), uri, uri)
}

func (s *svgSink) Text(t string, cx, cy, size float64, fill string) {
	fmt.Fprintf(&s.b, `<text x="%s" y="%s" text-anchor="middle" dominant-baseline="central" font-family="Helvetica, Arial, sans-serif" font-weight="700" font-size="%s" fill="%s">%s</text>`,
		ff(cx), ff(cy+size*0.04), ff(size), fill, html.EscapeString(t))
}

func (s *svgSink) End() ([]byte, error) {
	s.b.WriteString(`</svg>`)
	return []byte(s.b.String()), nil
}

// ---------------------------------------------------------------- PDF and EPS

const (
	pdfWidthPt = 283.4646 // 100 mm: vector, so it can be scaled to any size without losing sharpness
	kappa      = 0.5522847498
)

type psImage struct {
	img        image.Image
	x, y, w, h float64 // in module units, canvas coordinates (y down)
}

// psSink writes PDF page content or EPS code; the two share every drawing operator but their names.
type psSink struct {
	eps    bool
	k      float64
	w, h   float64
	body   bytes.Buffer
	group  string
	images []psImage
	bg     string
}

func newPSSink(eps bool, bg string) *psSink { return &psSink{eps: eps, bg: bg} }

func (p *psSink) X(x float64) float64 { return x * p.k }
func (p *psSink) Y(y float64) float64 { return (p.h - y) * p.k }

func (p *psSink) Begin(w, h float64) {
	p.w, p.h = w, h
	p.k = pdfWidthPt / w
}

func (p *psSink) colour(hexcol string) {
	if hexcol == "" {
		hexcol = p.group
	}
	c, _ := parseHex(hexcol)
	op := "rg"
	if p.eps {
		op = "setrgbcolor"
	}
	fmt.Fprintf(&p.body, "%.4f %.4f %.4f %s\n", float64(c.R)/255, float64(c.G)/255, float64(c.B)/255, op)
}

func (p *psSink) start() {
	if p.eps {
		p.body.WriteString("newpath\n")
	}
}
func (p *psSink) mv(x, y float64) {
	op := "m"
	if p.eps {
		op = "moveto"
	}
	fmt.Fprintf(&p.body, "%.3f %.3f %s\n", p.X(x), p.Y(y), op)
}
func (p *psSink) ln(x, y float64) {
	op := "l"
	if p.eps {
		op = "lineto"
	}
	fmt.Fprintf(&p.body, "%.3f %.3f %s\n", p.X(x), p.Y(y), op)
}
func (p *psSink) cv(x1, y1, x2, y2, x3, y3 float64) {
	op := "c"
	if p.eps {
		op = "curveto"
	}
	fmt.Fprintf(&p.body, "%.3f %.3f %.3f %.3f %.3f %.3f %s\n", p.X(x1), p.Y(y1), p.X(x2), p.Y(y2), p.X(x3), p.Y(y3), op)
}
func (p *psSink) cl() {
	if p.eps {
		p.body.WriteString("closepath\n")
	} else {
		p.body.WriteString("h\n")
	}
}
func (p *psSink) fill(evenodd bool) {
	switch {
	case p.eps && evenodd:
		p.body.WriteString("eofill\n")
	case p.eps:
		p.body.WriteString("fill\n")
	case evenodd:
		p.body.WriteString("f*\n")
	default:
		p.body.WriteString("f\n")
	}
}

func (p *psSink) rrectPath(x, y, w, h, r float64) {
	r = math.Min(r, math.Min(w, h)/2)
	if r <= 0.0001 {
		p.mv(x, y)
		p.ln(x+w, y)
		p.ln(x+w, y+h)
		p.ln(x, y+h)
		p.cl()
		return
	}
	c := r * kappa
	p.mv(x+r, y)
	p.ln(x+w-r, y)
	p.cv(x+w-r+c, y, x+w, y+r-c, x+w, y+r)
	p.ln(x+w, y+h-r)
	p.cv(x+w, y+h-r+c, x+w-r+c, y+h, x+w-r, y+h)
	p.ln(x+r, y+h)
	p.cv(x+r-c, y+h, x, y+h-r+c, x, y+h-r)
	p.ln(x, y+r)
	p.cv(x, y+r-c, x+r-c, y, x+r, y)
	p.cl()
}

func (p *psSink) circlePath(cx, cy, r float64) {
	c := r * kappa
	p.mv(cx+r, cy)
	p.cv(cx+r, cy+c, cx+c, cy+r, cx, cy+r)
	p.cv(cx-c, cy+r, cx-r, cy+c, cx-r, cy)
	p.cv(cx-r, cy-c, cx-c, cy-r, cx, cy-r)
	p.cv(cx+c, cy-r, cx+r, cy-c, cx+r, cy)
	p.cl()
}

func (p *psSink) Rect(x, y, w, h, rx float64, fill string) {
	p.colour(fill)
	p.start()
	p.rrectPath(x, y, w, h, rx)
	p.fill(false)
}
func (p *psSink) Circle(cx, cy, r float64, fill string) {
	p.colour(fill)
	p.start()
	p.circlePath(cx, cy, r)
	p.fill(false)
}
func (p *psSink) GroupBegin(fill string) { p.group = fill }
func (p *psSink) GroupEnd()              {}
func (p *psSink) Runs(runs []run) {
	p.colour("")
	p.start()
	for _, r := range runs {
		p.rrectPath(r.x, r.y, float64(r.n), 1, 0)
	}
	p.fill(false)
}
func (p *psSink) RingRect(x, y, w, rxO, inset, rxI float64, fill string) {
	p.colour(fill)
	p.start()
	p.rrectPath(x, y, w, w, rxO)
	p.rrectPath(x+inset, y+inset, w-2*inset, w-2*inset, rxI)
	p.fill(true)
}
func (p *psSink) RingCircle(cx, cy, rO, rI float64, fill string) {
	p.colour(fill)
	p.start()
	p.circlePath(cx, cy, rO)
	p.circlePath(cx, cy, rI)
	p.fill(true)
}

func (p *psSink) Image(b []byte, x, y, w, h float64) {
	img, err := png.Decode(bytes.NewReader(b))
	if err != nil {
		return
	}
	lb := img.Bounds()
	iw, ih := w, h // keep the logo's proportions, centred in its square, as the other formats do
	if lb.Dx() > lb.Dy() {
		ih = w * float64(lb.Dy()) / float64(lb.Dx())
	} else {
		iw = h * float64(lb.Dx()) / float64(lb.Dy())
	}
	p.images = append(p.images, psImage{img, x + (w-iw)/2, y + (h-ih)/2, iw, ih})
	n := len(p.images)
	px, py, pw, ph := p.X(x+(w-iw)/2), p.Y(y+(h-ih)/2+ih), iw*p.k, ih*p.k
	if !p.eps {
		fmt.Fprintf(&p.body, "q %.3f 0 0 %.3f %.3f %.3f cm /Im%d Do Q\n", pw, ph, px, py, n)
		return
	}
	// EPS has no transparency: the logo is flattened onto the background colour, which is what is behind it
	bgc, _ := parseHex(p.bg)
	rgb := make([]byte, 0, lb.Dx()*lb.Dy()*3)
	for yy := lb.Min.Y; yy < lb.Max.Y; yy++ {
		for xx := lb.Min.X; xx < lb.Max.X; xx++ {
			c := color.NRGBAModel.Convert(img.At(xx, yy)).(color.NRGBA)
			a := float64(c.A) / 255
			rgb = append(rgb, byte(float64(c.R)*a+float64(bgc.R)*(1-a)+0.5), byte(float64(c.G)*a+float64(bgc.G)*(1-a)+0.5), byte(float64(c.B)*a+float64(bgc.B)*(1-a)+0.5))
		}
	}
	fmt.Fprintf(&p.body, "gsave\n%.3f %.3f translate\n%.3f %.3f scale\n%d %d 8 [%d 0 0 -%d 0 %d]\ncurrentfile /ASCIIHexDecode filter\nfalse 3 colorimage\n",
		px, py, pw, ph, lb.Dx(), lb.Dy(), lb.Dx(), lb.Dy(), lb.Dy())
	hx := hex.EncodeToString(rgb)
	for i := 0; i < len(hx); i += 96 {
		end := i + 96
		if end > len(hx) {
			end = len(hx)
		}
		p.body.WriteString(hx[i:end] + "\n")
	}
	p.body.WriteString(">\ngrestore\n")
}

var (
	otfOnce sync.Once
	otf     *sfnt.Font
	otfErr  error
)

// Text writes the label as filled outlines of the same bold font the PNG uses, positioned as the PNG does.
func (p *psSink) Text(s string, cx, cy, size float64, fill string) {
	otfOnce.Do(func() { otf, otfErr = sfnt.Parse(gobold.TTF) })
	if otfErr != nil {
		return
	}
	const ppem = 1000
	pp := fixed.I(ppem)
	var buf sfnt.Buffer
	scale := size / ppem
	type glyph struct {
		idx sfnt.GlyphIndex
		adv float64
	}
	var gl []glyph
	total := 0.0
	var prev sfnt.GlyphIndex
	for i, r := range s {
		idx, err := otf.GlyphIndex(&buf, r)
		if err != nil {
			continue
		}
		if i > 0 {
			if k, err := otf.Kern(&buf, prev, idx, pp, font.HintingNone); err == nil {
				total += float64(k) / 64
			}
		}
		adv, err := otf.GlyphAdvance(&buf, idx, pp, font.HintingNone)
		if err != nil {
			continue
		}
		gl = append(gl, glyph{idx, float64(adv) / 64})
		total += float64(adv) / 64
		prev = idx
	}
	m, err := otf.Metrics(&buf, pp, font.HintingNone)
	if err != nil {
		return
	}
	baseline := cy + (float64(m.Ascent)/64-float64(m.Descent)/64)/2*scale
	x0 := cx - total*scale/2
	p.colour(fill)
	p.start()
	pen := 0.0
	for _, g := range gl {
		segs, err := otf.LoadGlyph(&buf, g.idx, pp, nil)
		if err != nil {
			continue
		}
		pt := func(f fixed.Point26_6) (float64, float64) {
			return x0 + (pen+float64(f.X)/64)*scale, baseline + float64(f.Y)/64*scale
		}
		open := false
		var cur [2]float64
		for _, sg := range segs {
			switch sg.Op {
			case sfnt.SegmentOpMoveTo:
				if open {
					p.cl()
				}
				x, y := pt(sg.Args[0])
				p.mv(x, y)
				cur, open = [2]float64{x, y}, true
			case sfnt.SegmentOpLineTo:
				x, y := pt(sg.Args[0])
				p.ln(x, y)
				cur = [2]float64{x, y}
			case sfnt.SegmentOpQuadTo: // a quadratic curve, as the equivalent cubic
				cxq, cyq := pt(sg.Args[0])
				x, y := pt(sg.Args[1])
				p.cv(cur[0]+2.0/3*(cxq-cur[0]), cur[1]+2.0/3*(cyq-cur[1]), x+2.0/3*(cxq-x), y+2.0/3*(cyq-y), x, y)
				cur = [2]float64{x, y}
			case sfnt.SegmentOpCubeTo:
				x1, y1 := pt(sg.Args[0])
				x2, y2 := pt(sg.Args[1])
				x, y := pt(sg.Args[2])
				p.cv(x1, y1, x2, y2, x, y)
				cur = [2]float64{x, y}
			}
		}
		if open {
			p.cl()
		}
		pen += g.adv
	}
	p.fill(false)
}

func (p *psSink) End() ([]byte, error) {
	if p.eps {
		var out bytes.Buffer
		wpt, hpt := p.w*p.k, p.h*p.k
		fmt.Fprintf(&out, "%%!PS-Adobe-3.0 EPSF-3.0\n%%%%BoundingBox: 0 0 %d %d\n%%%%HiResBoundingBox: 0 0 %.3f %.3f\n%%%%Title: QR code\n%%%%Creator: Blake UK QR tracker\n%%%%LanguageLevel: 2\n%%%%EndComments\n",
			int(math.Ceil(wpt)), int(math.Ceil(hpt)), wpt, hpt)
		out.Write(p.body.Bytes())
		out.WriteString("showpage\n%%EOF\n")
		return out.Bytes(), nil
	}
	return p.pdf()
}

func deflate(b []byte) []byte {
	var z bytes.Buffer
	w := zlib.NewWriter(&z)
	w.Write(b)
	w.Close()
	return z.Bytes()
}

func (p *psSink) pdf() ([]byte, error) {
	wpt, hpt := p.w*p.k, p.h*p.k
	var out bytes.Buffer
	var offs []int
	obj := func(body string) {
		offs = append(offs, out.Len())
		fmt.Fprintf(&out, "%d 0 obj\n%s\nendobj\n", len(offs), body)
	}
	stream := func(dict string, data []byte) {
		offs = append(offs, out.Len())
		fmt.Fprintf(&out, "%d 0 obj\n<< %s /Length %d /Filter /FlateDecode >>\nstream\n", len(offs), dict, len(data))
		out.Write(data)
		out.WriteString("\nendstream\nendobj\n")
	}
	out.WriteString("%PDF-1.4\n%\xe2\xe3\xcf\xd3\n")
	xobjs := ""
	// object numbers: 1 catalog, 2 pages, 3 page, 4 content, 5 info, then images (colour, and soft mask when needed)
	next := 6
	type img struct {
		colour, mask int
	}
	var ids []img
	for i := range p.images {
		id := img{colour: next}
		next++
		if hasAlpha(p.images[i].img) {
			id.mask = next
			next++
		}
		ids = append(ids, id)
		xobjs += fmt.Sprintf("/Im%d %d 0 R ", i+1, id.colour)
	}
	obj("<< /Type /Catalog /Pages 2 0 R >>")
	obj("<< /Type /Pages /Kids [3 0 R] /Count 1 >>")
	obj(fmt.Sprintf("<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.3f %.3f] /Contents 4 0 R /Resources << /XObject << %s>> >> >>", wpt, hpt, xobjs))
	stream("", deflate(p.body.Bytes()))
	obj("<< /Title (QR code) /Producer (Blake UK QR tracker) >>")
	for i, im := range p.images {
		b := im.img.Bounds()
		rgb := make([]byte, 0, b.Dx()*b.Dy()*3)
		alpha := make([]byte, 0, b.Dx()*b.Dy())
		for y := b.Min.Y; y < b.Max.Y; y++ {
			for x := b.Min.X; x < b.Max.X; x++ {
				c := color.NRGBAModel.Convert(im.img.At(x, y)).(color.NRGBA)
				rgb = append(rgb, c.R, c.G, c.B)
				alpha = append(alpha, c.A)
			}
		}
		dict := fmt.Sprintf("/Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8", b.Dx(), b.Dy())
		if ids[i].mask != 0 {
			dict += fmt.Sprintf(" /SMask %d 0 R", ids[i].mask)
		}
		stream(dict, deflate(rgb))
		if ids[i].mask != 0 {
			stream(fmt.Sprintf("/Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent 8", b.Dx(), b.Dy()), deflate(alpha))
		}
	}
	xref := out.Len()
	fmt.Fprintf(&out, "xref\n0 %d\n0000000000 65535 f \n", len(offs)+1)
	for _, o := range offs {
		fmt.Fprintf(&out, "%010d 00000 n \n", o)
	}
	fmt.Fprintf(&out, "trailer\n<< /Size %d /Root 1 0 R /Info 5 0 R >>\nstartxref\n%d\n%%%%EOF\n", len(offs)+1, xref)
	return out.Bytes(), nil
}

func hasAlpha(img image.Image) bool {
	b := img.Bounds()
	for y := b.Min.Y; y < b.Max.Y; y++ {
		for x := b.Min.X; x < b.Max.X; x++ {
			if _, _, _, a := img.At(x, y).RGBA(); a != 0xffff {
				return true
			}
		}
	}
	return false
}

// RenderPDF draws the code as a one-page vector PDF (100 mm wide, scalable to any size). Transparent leaves the
// background out. The label is drawn as outlines, so nothing depends on a font.
func RenderPDF(o Options) ([]byte, error) {
	g, err := newGeom(o)
	if err != nil {
		return nil, err
	}
	s := newPSSink(false, g.d.BG)
	drawVector(g, s, o.Transparent)
	return s.End()
}

// RenderEPS draws the same vector as Encapsulated PostScript, which some print shops still ask for.
func RenderEPS(o Options) ([]byte, error) {
	g, err := newGeom(o)
	if err != nil {
		return nil, err
	}
	s := newPSSink(true, g.d.BG)
	drawVector(g, s, o.Transparent)
	return s.End()
}
