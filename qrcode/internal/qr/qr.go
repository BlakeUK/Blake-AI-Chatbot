// Package qr renders QR codes as PNG or SVG with a selectable error
// correction level, colours, dot and corner styles, a centre logo and a frame.
package qr

// Sizes are the PNG pixel sizes offered for download.
var Sizes = []int{256, 512, 1024}

// ValidECC reports whether s is one of L, M, Q, H.
func ValidECC(s string) bool {
	switch s {
	case "L", "M", "Q", "H":
		return true
	}
	return false
}

// ValidSize reports whether px is one of the offered PNG sizes.
func ValidSize(px int) bool {
	for _, s := range Sizes {
		if s == px {
			return true
		}
	}
	return false
}

// PNG renders content as a plain black-on-white PNG px pixels wide.
func PNG(content, ecc string, px int) ([]byte, error) {
	return RenderPNG(Options{Content: content, ECC: ecc}, px)
}

// SVG renders content as a plain black-on-white vector image.
func SVG(content, ecc string) ([]byte, error) {
	return RenderSVG(Options{Content: content, ECC: ecc})
}
