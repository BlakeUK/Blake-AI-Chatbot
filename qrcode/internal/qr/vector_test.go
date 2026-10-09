package qr

import (
	"bytes"
	"fmt"
	"image"
	"image/color"
	"image/draw"
	"image/png"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"testing"
)

func runTool(t *testing.T, name string, args ...string) {
	t.Helper()
	if out, err := exec.Command(name, args...).CombinedOutput(); err != nil {
		t.Fatalf("%s %v: %v\n%s", name, args, err, out)
	}
}

func readPNG(t *testing.T, path string) image.Image {
	t.Helper()
	f, err := os.Open(path)
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	img, err := png.Decode(f)
	if err != nil {
		t.Fatal(err)
	}
	return img
}

// pdfPNG and epsPNG rasterise with the same tools a printer's software would use. A positive width is in
// pixels; otherwise dpi is used.
func pdfPNG(t *testing.T, data []byte, dir, name string, width, dpi int) string {
	t.Helper()
	in, out := filepath.Join(dir, name+".pdf"), filepath.Join(dir, name)
	os.WriteFile(in, data, 0o600)
	args := []string{"-png", "-singlefile"}
	if width > 0 {
		args = append(args, "-scale-to-x", strconv.Itoa(width), "-scale-to-y", "-1")
	} else {
		args = append(args, "-r", strconv.Itoa(dpi))
	}
	runTool(t, "pdftoppm", append(args, in, out)...)
	return out + ".png"
}

func epsPNG(t *testing.T, data []byte, dir, name string, alpha bool, dpi int) string {
	t.Helper()
	in, out := filepath.Join(dir, name+".eps"), filepath.Join(dir, name+".eps.png")
	os.WriteFile(in, data, 0o600)
	dev := "png16m"
	if alpha {
		dev = "pngalpha"
	}
	runTool(t, "gs", "-q", "-dSAFER", "-dBATCH", "-dNOPAUSE", "-dEPSCrop", "-dGraphicsAlphaBits=4", "-dTextAlphaBits=4", "-sDEVICE="+dev, "-r"+strconv.Itoa(dpi), "-sOutputFile="+out, in)
	return out
}

// pdfAlphaPNG renders a PDF keeping transparency (pdftoppm cannot).
func pdfAlphaPNG(t *testing.T, data []byte, dir, name string, dpi int) string {
	t.Helper()
	in, out := filepath.Join(dir, name+".pdf"), filepath.Join(dir, name+".pdf.png")
	os.WriteFile(in, data, 0o600)
	runTool(t, "gs", "-q", "-dSAFER", "-dBATCH", "-dNOPAUSE", "-dGraphicsAlphaBits=4", "-sDEVICE=pngalpha", "-r"+strconv.Itoa(dpi), "-sOutputFile="+out, in)
	return out
}

func gray(c color.Color) float64 {
	r, g, b, _ := c.RGBA()
	return (0.299*float64(r) + 0.587*float64(g) + 0.114*float64(b)) / 65535
}

// disagreement is the share of pixels that are dark in one image and light in the other.
func disagreement(a, b image.Image) float64 {
	w := min(a.Bounds().Dx(), b.Bounds().Dx())
	h := min(a.Bounds().Dy(), b.Bounds().Dy())
	bad := 0
	for y := 0; y < h; y++ {
		for x := 0; x < w; x++ {
			if (gray(a.At(a.Bounds().Min.X+x, a.Bounds().Min.Y+y)) < 0.5) != (gray(b.At(b.Bounds().Min.X+x, b.Bounds().Min.Y+y)) < 0.5) {
				bad++
			}
		}
	}
	return float64(bad) / float64(w*h)
}

func styles(t *testing.T) []Options {
	logo := makeLogo(t, 400)
	var out []Options
	for _, pat := range Patterns {
		for _, eye := range Eyes {
			for _, fr := range Frames {
				for _, withLogo := range []bool{false, true} {
					o := Options{Content: sample, ECC: "H", Design: Design{FG: "#0b2a6f", Pattern: pat, Eye: eye, Frame: fr, CTA: "SCAN ME"}}
					if withLogo {
						o.Logo = logo
					}
					out = append(out, o)
				}
			}
		}
	}
	return out
}

func label(o Options) string {
	return fmt.Sprintf("%s/%s/%s/logo=%v", o.Design.Pattern, o.Design.Eye, o.Design.Frame, len(o.Logo) > 0)
}

// Every style, as a PDF and as EPS: it must scan, and it must look like the PNG (a shape, the label or the logo
// in the wrong place shows up as a large disagreement).
func TestPDFAndEPSScanAndMatchThePNG(t *testing.T) {
	needTool(t, "zbarimg", "pdftoppm", "gs")
	dir := t.TempDir()
	for i, o := range styles(t) {
		name := fmt.Sprintf("s%d", i)
		ref, err := RenderPNG(o, 512)
		if err != nil {
			t.Fatal(err)
		}
		refImg, _ := png.Decode(bytes.NewReader(ref))

		pdf, err := RenderPDF(o)
		if err != nil {
			t.Fatal(err)
		}
		// It must scan at screen resolution and at print resolution. For print, one of 300 or 600 dpi is enough:
		// zbar is known to be fussy about anti-aliased circular corner squares at a few sizes, whichever tool drew them.
		scans := func(format string, render func(dpi int) string) {
			if got := zbarDecode(t, render(150)); got != sample {
				t.Errorf("%s %s does NOT scan at 150 dpi: %q", format, label(o), got)
			}
			if zbarDecode(t, render(300)) != sample && zbarDecode(t, render(600)) != sample {
				t.Errorf("%s %s does NOT scan at 300 or 600 dpi", format, label(o))
			}
		}
		scans("PDF", func(dpi int) string { return pdfPNG(t, pdf, dir, fmt.Sprintf("%s-%d", name, dpi), 0, dpi) })
		if d := disagreement(readPNG(t, pdfPNG(t, pdf, dir, name, 512, 0)), refImg); d > 0.03 {
			t.Errorf("PDF %s differs from the PNG in %.1f%% of pixels", label(o), d*100)
		}

		eps, err := RenderEPS(o)
		if err != nil {
			t.Fatal(err)
		}
		scans("EPS", func(dpi int) string { return epsPNG(t, eps, dir, fmt.Sprintf("%s-%d", name, dpi), false, dpi) })
		if d := disagreement(readPNG(t, epsPNG(t, eps, dir, name+"cmp", false, 130)), refImg); d > 0.03 { // 130 dpi is 512 px wide
			t.Errorf("EPS %s differs from the PNG in %.1f%% of pixels", label(o), d*100)
		}
	}
}

func alphaAt(img image.Image, fx, fy float64) uint32 {
	b := img.Bounds()
	_, _, _, a := img.At(b.Min.X+int(fx*float64(b.Dx())), b.Min.Y+int(fy*float64(b.Dy()))).RGBA()
	return a >> 8
}

func composited(t *testing.T, img image.Image, bg color.Color, path string) string {
	t.Helper()
	dst := image.NewRGBA(img.Bounds())
	draw.Draw(dst, dst.Bounds(), &image.Uniform{bg}, image.Point{}, draw.Src)
	draw.Draw(dst, dst.Bounds(), img, img.Bounds().Min, draw.Over)
	var buf bytes.Buffer
	png.Encode(&buf, dst)
	os.WriteFile(path, buf.Bytes(), 0o600)
	return path
}

// A transparent code must be genuinely see-through at its corners and inside the corner squares, still solid
// where the code is, and must still scan when placed on white or on a light grey.
func TestTransparentBackgroundsInEveryFormat(t *testing.T) {
	needTool(t, "zbarimg", "rsvg-convert", "gs")
	dir := t.TempDir()
	logo := makeLogo(t, 400)
	n := 0
	for _, pat := range Patterns {
		for _, eye := range Eyes {
			for _, fr := range Frames {
				for _, withLogo := range []bool{false, true} {
					o := Options{Content: sample, ECC: "H", Transparent: true, Design: Design{FG: "#0b2a6f", Pattern: pat, Eye: eye, Frame: fr, CTA: "SCAN ME"}}
					if withLogo {
						o.Logo = logo
					}
					g, _ := newGeom(o)
					ox, oy := g.qx+quiet, g.qy+quiet
					gapX, gapY := (ox+1.5)/g.w, (oy+3.5)/g.h // inside the corner square's light gap
					cornerX, cornerY := 0.002, 0.002
					boxPlateX, boxPlateY := (g.qx+0.5)/g.w, (g.qy+0.5)/g.h

					renders := map[string]image.Image{}
					b, _ := RenderPNG(o, 512)
					renders["png"], _ = png.Decode(bytes.NewReader(b))
					svg, _ := RenderSVG(o)
					sp, sop := filepath.Join(dir, "a.svg"), filepath.Join(dir, "a.svg.png")
					os.WriteFile(sp, svg, 0o600)
					runTool(t, "rsvg-convert", "-w", "640", "-o", sop, sp)
					renders["svg"] = readPNG(t, sop)
					pdf, _ := RenderPDF(o)
					renders["pdf"] = readPNG(t, pdfAlphaPNG(t, pdf, dir, "a", 300))
					eps, _ := RenderEPS(o)
					renders["eps"] = readPNG(t, epsPNG(t, eps, dir, "a", true, 300))

					for format, img := range renders {
						who := fmt.Sprintf("%s %s", format, label(o))
						if a := alphaAt(img, cornerX, cornerY); a != 0 {
							t.Errorf("%s: the corner should be see-through, alpha=%d", who, a)
						}
						if fr == "box" {
							if a := alphaAt(img, boxPlateX, boxPlateY); a < 250 {
								t.Errorf("%s: a box frame keeps its light plate, alpha=%d", who, a)
							}
						} else if !withLogo || true {
							if a := alphaAt(img, gapX, gapY); a != 0 {
								t.Errorf("%s: the gap inside a corner square should be see-through, alpha=%d", who, a)
							}
						}
						if a := alphaAt(img, (ox+3.5)/g.w, (oy+3.5)/g.h); a < 250 { // the centre of a corner square
							t.Errorf("%s: the centre of a corner square should be solid, alpha=%d", who, a)
						}
						n++
						for bgName, bg := range map[string]color.Color{"white": color.White, "grey": color.RGBA{0xe6, 0xe6, 0xe6, 255}} {
							decodes := func(im image.Image) bool {
								return zbarDecode(t, composited(t, im, bg, filepath.Join(dir, "c.png"))) == sample
							}
							if format != "pdf" && format != "eps" {
								if !decodes(img) {
									t.Errorf("%s on %s does NOT scan", who, bgName)
								}
								continue
							}
							// vector files must scan at screen resolution and at one print resolution (zbar is fussy about
							// anti-aliased circular corner squares at a few sizes in between, whichever tool drew them)
							at := func(dpi int) image.Image {
								if format == "pdf" {
									return readPNG(t, pdfAlphaPNG(t, pdf, dir, fmt.Sprintf("s%d", dpi), dpi))
								}
								return readPNG(t, epsPNG(t, eps, dir, fmt.Sprintf("s%d", dpi), true, dpi))
							}
							if !decodes(at(150)) {
								t.Errorf("%s on %s does NOT scan at 150 dpi", who, bgName)
							} else if !decodes(img) && !decodes(at(600)) {
								t.Errorf("%s on %s does NOT scan at 300 or 600 dpi", who, bgName)
							}
						}
					}
				}
			}
		}
	}
	t.Logf("%d transparent renders checked", n)
}

func TestOpaqueOutputsAreNotTransparent(t *testing.T) {
	needTool(t, "gs")
	dir := t.TempDir()
	o := Options{Content: sample, ECC: "H", Design: Design{FG: "#0b2a6f", Pattern: "dots", Eye: "circle", Frame: "none"}}
	pdf, _ := RenderPDF(o)
	eps, _ := RenderEPS(o)
	b, _ := RenderPNG(o, 256)
	pn, _ := png.Decode(bytes.NewReader(b))
	for name, img := range map[string]image.Image{"png": pn, "pdf": readPNG(t, pdfAlphaPNG(t, pdf, dir, "o", 300)), "eps": readPNG(t, epsPNG(t, eps, dir, "o", true, 300))} {
		if a := alphaAt(img, 0.002, 0.002); a != 255 {
			t.Errorf("%s: the default background is solid, alpha=%d", name, a)
		}
	}
}

func TestPDFAndEPSAreWellFormedAndNeedNoFonts(t *testing.T) {
	o := Options{Content: sample, ECC: "H", Design: Design{FG: "#0b2a6f", Pattern: "rounded", Eye: "rounded", Frame: "box", CTA: "SCAN ME"}, Logo: makeLogo(t, 400)}
	pdf, err := RenderPDF(o)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.HasPrefix(pdf, []byte("%PDF-1.4")) || !bytes.HasSuffix(pdf, []byte("%%EOF\n")) {
		t.Error("a PDF starts with its version header and ends with its end-of-file marker")
	}
	// the cross-reference table must point at every object, or some viewers refuse the file
	m := regexp.MustCompile(`startxref\n(\d+)\n`).FindSubmatch(pdf)
	if m == nil {
		t.Fatal("no startxref")
	}
	xr, _ := strconv.Atoi(string(m[1]))
	if !bytes.HasPrefix(pdf[xr:], []byte("xref\n0 ")) {
		t.Errorf("startxref does not point at the xref table")
	}
	lines := strings.Split(string(pdf[xr:]), "\n")
	count, _ := strconv.Atoi(strings.Fields(lines[1])[1])
	for i := 1; i < count; i++ {
		off, _ := strconv.Atoi(strings.Fields(lines[2+i])[0])
		if !strings.HasPrefix(string(pdf[off:off+20]), strconv.Itoa(i)+" 0 obj") {
			t.Errorf("object %d is not at offset %d", i, off)
		}
	}
	if bytes.Contains(pdf, []byte("/Font")) || bytes.Contains(pdf, []byte("Helvetica")) {
		t.Error("the PDF must not depend on any font: the label is drawn as outlines")
	}
	if !bytes.Contains(pdf, []byte("/MediaBox [0 0 283.465")) {
		t.Error("the page should be 100 mm wide")
	}
	eps, err := RenderEPS(o)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.HasPrefix(eps, []byte("%!PS-Adobe-3.0 EPSF-3.0\n%%BoundingBox: 0 0 284 ")) || !bytes.HasSuffix(eps, []byte("%%EOF\n")) {
		t.Errorf("not a valid EPS header/footer: %.80q", eps)
	}
	if bytes.Contains(eps, []byte("findfont")) || bytes.Contains(eps, []byte("selectfont")) {
		t.Error("the EPS must not depend on any font")
	}
}

func TestTransparentSVGHasRealHolesAndOpaqueSVGIsUnchanged(t *testing.T) {
	d := Design{FG: "#0b2a6f", Pattern: "square", Eye: "circle", Frame: "none"}
	opaque, _ := RenderSVG(Options{Content: sample, Design: d})
	clear, _ := RenderSVG(Options{Content: sample, Design: d, Transparent: true})
	if bytes.Contains(opaque, []byte("evenodd")) || !bytes.Contains(opaque, []byte(`<rect width=`)) {
		t.Error("the normal SVG keeps its solid background")
	}
	if !bytes.Contains(clear, []byte(`fill-rule="evenodd"`)) || bytes.Contains(clear, []byte(`<rect width="`)) {
		t.Error("the transparent SVG has no background rectangle and draws the corner squares with real holes")
	}
}
