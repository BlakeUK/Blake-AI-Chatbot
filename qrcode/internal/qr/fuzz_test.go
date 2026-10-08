package qr

import (
	"bytes"
	"image"
	"image/png"
	"testing"
)

func FuzzProcessLogo(f *testing.F) {
	var ok bytes.Buffer
	png.Encode(&ok, image.NewRGBA(image.Rect(0, 0, 40, 40)))
	f.Add(ok.Bytes())
	f.Add([]byte("GIF89a"))
	f.Add([]byte("\xff\xd8\xff\xe0"))
	f.Add([]byte("<svg/>"))
	f.Add(append(ok.Bytes()[:30], 0xff, 0xff, 0xff, 0xff))
	f.Fuzz(func(t *testing.T, data []byte) {
		out, err := ProcessLogo(bytes.NewReader(data))
		if err != nil {
			return
		}
		img, derr := png.Decode(bytes.NewReader(out))
		if derr != nil || img.Bounds().Dx() > 256 || img.Bounds().Dy() > 256 {
			t.Fatalf("accepted a logo but stored something unusable: %v %v", derr, img != nil)
		}
	})
}

func FuzzDesignNormalise(f *testing.F) {
	f.Add("#000000", "#ffffff", "square", "square", "", "none", "")
	f.Add("#zz", "", "stars", "x", "#12", "neon", "\x00")
	f.Fuzz(func(t *testing.T, fg, bg, pat, eye, eyec, frame, cta string) {
		d, err := Design{FG: fg, BG: bg, Pattern: pat, Eye: eye, EyeColor: eyec, Frame: frame, CTA: cta}.Normalise()
		if err != nil {
			return
		}
		// anything accepted must really be drawable without error or panic
		if _, err := RenderSVG(Options{Content: "https://x.example/abc", ECC: "M", Design: d}); err != nil {
			t.Fatalf("accepted design %+v but could not draw it: %v", d, err)
		}
	})
}
