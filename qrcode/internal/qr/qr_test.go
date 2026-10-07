package qr

import (
	"bytes"
	"encoding/xml"
	"fmt"
	"image/png"
	"strings"
	"testing"
)

const sample = "https://qr.example.com/r/AbCd1234"

func TestPNGSizes(t *testing.T) {
	for _, px := range Sizes {
		b, err := PNG(sample, "M", px)
		if err != nil {
			t.Fatal(err)
		}
		img, err := png.Decode(bytes.NewReader(b))
		if err != nil {
			t.Fatalf("not a valid PNG: %v", err)
		}
		if got := img.Bounds().Dx(); got != px {
			t.Errorf("width %d want %d", got, px)
		}
	}
	if _, err := PNG(sample, "M", 300); err == nil {
		t.Error("expected unsupported size error")
	}
}

func TestSVGIsWellFormedAndScalable(t *testing.T) {
	b, err := SVG(sample, "M")
	if err != nil {
		t.Fatal(err)
	}
	dec := xml.NewDecoder(bytes.NewReader(b))
	for {
		if _, err := dec.Token(); err != nil {
			if err.Error() == "EOF" {
				break
			}
			t.Fatalf("SVG is not well-formed XML: %v", err)
		}
	}
	s := string(b)
	if !strings.Contains(s, `viewBox="0 0 `) || !strings.Contains(s, "<path") {
		t.Errorf("unexpected SVG: %.120s", s)
	}
}

// Higher error-correction levels must produce symbols at least as large for
// the same content; this catches a wrong level mapping.
func TestECCLevelsMapMonotonically(t *testing.T) {
	long := sample + "?utm_source=poster&utm_campaign=autumn-launch-2026&ref=abcdefghijklmnop"
	size := func(ecc string) int {
		b, err := SVG(long, ecc)
		if err != nil {
			t.Fatal(err)
		}
		var n int
		idx := strings.Index(string(b), `viewBox="0 0 `)
		if _, err := fmtSscan(string(b)[idx+len(`viewBox="0 0 `):], &n); err != nil {
			t.Fatal(err)
		}
		return n
	}
	l, m, q, h := size("L"), size("M"), size("Q"), size("H")
	if !(l <= m && m <= q && q <= h) || l >= h {
		t.Errorf("symbol sizes not monotonic: L=%d M=%d Q=%d H=%d", l, m, q, h)
	}
	if ValidECC("X") || !ValidECC("Q") {
		t.Error("ValidECC wrong")
	}
}

func fmtSscan(s string, n *int) (int, error) { return fmt.Sscanf(s, "%d", n) }
