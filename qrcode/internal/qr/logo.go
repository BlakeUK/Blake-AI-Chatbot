package qr

import (
	"bytes"
	"image"
	"image/draw"
	_ "image/gif"  // register decoders
	_ "image/jpeg" // register decoders
	"image/png"
	"io"

	xdraw "golang.org/x/image/draw"
)

const (
	// MaxLogoBytes is the largest logo upload accepted.
	MaxLogoBytes = 1 << 20
	maxLogoSide  = 4000 // pixels: refuse decompression bombs before decoding
	storedSide   = 256  // the stored logo is shrunk to fit this
)

// ProcessLogo validates an uploaded logo and returns a clean PNG. The image is
// fully decoded and re-encoded, which discards anything that was not pixel
// data (metadata, appended payloads, scripts in odd formats). Only PNG, JPEG
// and GIF are accepted; SVG is refused because it can carry script.
func ProcessLogo(r io.Reader) ([]byte, error) {
	raw, err := io.ReadAll(io.LimitReader(r, MaxLogoBytes+1))
	if err != nil {
		return nil, err
	}
	if len(raw) > MaxLogoBytes {
		return nil, userErr("The logo must be 1 MB or smaller.")
	}
	if len(raw) == 0 {
		return nil, userErr("The logo file is empty.")
	}
	cfg, format, err := image.DecodeConfig(bytes.NewReader(raw))
	if err != nil || (format != "png" && format != "jpeg" && format != "gif") {
		return nil, userErr("The logo must be a PNG, JPEG or GIF image.")
	}
	if cfg.Width < 16 || cfg.Height < 16 {
		return nil, userErr("The logo is too small. Use at least 16 pixels each way.")
	}
	if cfg.Width > maxLogoSide || cfg.Height > maxLogoSide {
		return nil, userErr("The logo is too large. Use an image up to 4000 pixels each way.")
	}
	img, _, err := image.Decode(bytes.NewReader(raw))
	if err != nil {
		return nil, userErr("The logo image could not be read; it may be damaged.")
	}
	b := img.Bounds()
	w, h := b.Dx(), b.Dy()
	scale := float64(storedSide) / float64(max(w, h))
	if scale > 1 {
		scale = 1
	}
	nw, nh := max(int(float64(w)*scale+0.5), 1), max(int(float64(h)*scale+0.5), 1)
	dst := image.NewNRGBA(image.Rect(0, 0, nw, nh))
	xdraw.CatmullRom.Scale(dst, dst.Bounds(), img, b, xdraw.Over, nil)
	var out bytes.Buffer
	if err := png.Encode(&out, dst); err != nil {
		return nil, err
	}
	return out.Bytes(), nil
}

// decodeLogo reads a stored (already processed) logo.
func decodeLogo(b []byte) (image.Image, error) {
	img, err := png.Decode(bytes.NewReader(b))
	if err != nil {
		return nil, err
	}
	n := image.NewNRGBA(img.Bounds())
	draw.Draw(n, n.Bounds(), img, img.Bounds().Min, draw.Src)
	return n, nil
}
