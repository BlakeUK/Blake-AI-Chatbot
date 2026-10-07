// Package qr renders QR codes as PNG or SVG with a selectable error
// correction level.
package qr

import (
	"fmt"
	"strings"

	qrcode "github.com/skip2/go-qrcode"
)

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

func level(ecc string) qrcode.RecoveryLevel {
	switch ecc {
	case "L":
		return qrcode.Low
	case "Q":
		return qrcode.High // skip2/go-qrcode names the four levels Low, Medium, High, Highest = L, M, Q, H
	case "H":
		return qrcode.Highest
	}
	return qrcode.Medium
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

// PNG renders content as a square PNG of px pixels (including the quiet zone).
func PNG(content, ecc string, px int) ([]byte, error) {
	if !ValidSize(px) {
		return nil, fmt.Errorf("unsupported size %d", px)
	}
	q, err := qrcode.New(content, level(ecc))
	if err != nil {
		return nil, err
	}
	return q.PNG(px)
}

// SVG renders content as a scalable vector image. Each row's dark modules are
// merged into runs so the file stays small.
func SVG(content, ecc string) ([]byte, error) {
	q, err := qrcode.New(content, level(ecc))
	if err != nil {
		return nil, err
	}
	bm := q.Bitmap() // includes the 4-module quiet zone
	n := len(bm)
	var b strings.Builder
	fmt.Fprintf(&b, `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" shape-rendering="crispEdges">`, n, n, n*8, n*8)
	fmt.Fprintf(&b, `<rect width="%d" height="%d" fill="#ffffff"/><path fill="#000000" d="`, n, n)
	for y, row := range bm {
		for x := 0; x < n; {
			if !row[x] {
				x++
				continue
			}
			start := x
			for x < n && row[x] {
				x++
			}
			fmt.Fprintf(&b, "M%d %dh%dv1h-%dz", start, y, x-start, x-start)
		}
	}
	b.WriteString(`"/></svg>`)
	return []byte(b.String()), nil
}
