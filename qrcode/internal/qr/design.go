package qr

import (
	"fmt"
	"image/color"
	"math"
	"regexp"
	"strconv"
	"strings"
	"unicode"
	"unicode/utf8"
)

// Design is the look of a QR code. The zero value is the plain black-on-white
// square code, so every existing code keeps its appearance.
type Design struct {
	FG       string `json:"fg,omitempty"`        // dot colour, "#rrggbb"
	BG       string `json:"bg,omitempty"`        // background colour
	Pattern  string `json:"pattern,omitempty"`   // square | rounded | dots
	Eye      string `json:"eye,omitempty"`       // square | rounded | circle
	EyeColor string `json:"eye_color,omitempty"` // "" means the same as FG
	Frame    string `json:"frame,omitempty"`     // none | box | banner
	CTA      string `json:"cta,omitempty"`       // call to action under the code, e.g. "SCAN ME"
}

// MinContrast is the lowest WCAG contrast ratio allowed between the code and
// its background. Anything lighter and many phone cameras struggle.
const MinContrast = 4.0

var (
	hexRe       = regexp.MustCompile(`^#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})$`)
	Patterns    = []string{"square", "rounded", "dots"}
	Eyes        = []string{"square", "rounded", "circle"}
	Frames      = []string{"none", "box", "banner"}
	maxCTARunes = 24
)

func oneOf(v string, set []string) bool {
	for _, s := range set {
		if s == v {
			return true
		}
	}
	return false
}

func parseHex(s string) (color.RGBA, bool) {
	if !hexRe.MatchString(s) {
		return color.RGBA{}, false
	}
	h := s[1:]
	if len(h) == 3 {
		h = string([]byte{h[0], h[0], h[1], h[1], h[2], h[2]})
	}
	n, _ := strconv.ParseUint(h, 16, 32)
	return color.RGBA{R: uint8(n >> 16), G: uint8(n >> 8), B: uint8(n), A: 255}, true
}

func hexOf(c color.RGBA) string { return fmt.Sprintf("#%02x%02x%02x", c.R, c.G, c.B) }

func channel(v uint8) float64 {
	f := float64(v) / 255
	if f <= 0.03928 {
		return f / 12.92
	}
	return math.Pow((f+0.055)/1.055, 2.4)
}

// luminance is the WCAG relative luminance, 0 (black) to 1 (white).
func luminance(c color.RGBA) float64 {
	return 0.2126*channel(c.R) + 0.7152*channel(c.G) + 0.0722*channel(c.B)
}

// Contrast returns the WCAG contrast ratio of two colours, 1 to 21.
func Contrast(a, b color.RGBA) float64 {
	la, lb := luminance(a), luminance(b)
	if la < lb {
		la, lb = lb, la
	}
	return (la + 0.05) / (lb + 0.05)
}

// Normalise fills in defaults and checks every field. It refuses designs that
// would not scan reliably: low contrast, or a code lighter than its background
// (inverted codes are unreadable on many phones).
func (d Design) Normalise() (Design, error) {
	if d.FG == "" {
		d.FG = "#000000"
	}
	if d.BG == "" {
		d.BG = "#ffffff"
	}
	if d.Pattern == "" {
		d.Pattern = "square"
	}
	if d.Eye == "" {
		d.Eye = "square"
	}
	if d.Frame == "" {
		d.Frame = "none"
	}
	fg, ok := parseHex(d.FG)
	if !ok {
		return d, userErr("The code colour must be a hex colour such as #1a2b3c.")
	}
	bg, ok := parseHex(d.BG)
	if !ok {
		return d, userErr("The background colour must be a hex colour such as #ffffff.")
	}
	d.FG, d.BG = hexOf(fg), hexOf(bg)
	eye := fg
	if d.EyeColor != "" {
		if eye, ok = parseHex(d.EyeColor); !ok {
			return d, userErr("The corner colour must be a hex colour such as #1a2b3c.")
		}
		d.EyeColor = hexOf(eye)
	}
	if !oneOf(d.Pattern, Patterns) {
		return d, userErr("Choose a dot style: square, rounded or dots.")
	}
	if !oneOf(d.Eye, Eyes) {
		return d, userErr("Choose a corner style: square, rounded or circle.")
	}
	if !oneOf(d.Frame, Frames) {
		return d, userErr("Choose a frame: none, box or banner.")
	}
	for _, c := range []struct {
		name string
		col  color.RGBA
	}{{"code", fg}, {"corner", eye}} {
		if luminance(c.col) >= luminance(bg) {
			return d, userErr(fmt.Sprintf("The %s colour must be darker than the background, or the code will not scan on many phones.", c.name))
		}
		if r := Contrast(c.col, bg); r < MinContrast {
			return d, userErr(fmt.Sprintf("The %s colour is too close to the background (contrast %.1f, need at least %.0f). Pick a darker colour.", c.name, r, MinContrast))
		}
	}
	d.CTA = strings.TrimSpace(d.CTA)
	if utf8.RuneCountInString(d.CTA) > maxCTARunes {
		return d, userErr(fmt.Sprintf("The text under the code must be %d characters or fewer.", maxCTARunes))
	}
	for _, r := range d.CTA {
		if unicode.IsControl(r) {
			return d, userErr("The text under the code must not contain control characters.")
		}
	}
	if d.Frame != "none" && d.CTA == "" {
		d.CTA = "SCAN ME"
	}
	return d, nil
}

// EyeCol returns the effective corner colour.
func (d Design) EyeCol() string {
	if d.EyeColor != "" {
		return d.EyeColor
	}
	return d.FG
}
