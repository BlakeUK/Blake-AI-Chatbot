package web

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"os"
	"strings"
	"testing"
)

// The files the PDF manual is built from. tools/manual/build_manual.py hashes the same list in the same way.
var manualSources = []string{
	"web/templates/help.html", "internal/web/tracking.go", "internal/web/help.go", "internal/qrtypes/qrtypes.go",
	"tools/manual/build_manual.py", "tools/manual/manual.css",
}

func manualSourceHash(t *testing.T) string {
	t.Helper()
	h := sha256.New()
	for _, rel := range manualSources {
		b, err := os.ReadFile("../../" + rel)
		if err != nil {
			t.Fatal(err)
		}
		h.Write(b)
		h.Write([]byte{0})
	}
	return hex.EncodeToString(h.Sum(nil))
}

func TestManualPDFIsServedAndUpToDate(t *testing.T) {
	h := newHarness(t)
	if r, _ := h.do(noRedirect(), "GET", "/admin/manual.pdf", nil, nil); r.StatusCode != http.StatusSeeOther {
		t.Errorf("the manual needs a sign-in: %d", r.StatusCode)
	}
	c := h.adminClient()
	resp, body := h.do(c, "GET", "/admin/manual.pdf", nil, nil)
	if resp.StatusCode != 200 || resp.Header.Get("Content-Type") != "application/pdf" || !strings.Contains(resp.Header.Get("Content-Disposition"), "attachment") {
		t.Fatalf("manual: %d %s %s", resp.StatusCode, resp.Header.Get("Content-Type"), resp.Header.Get("Content-Disposition"))
	}
	if !strings.HasPrefix(body, "%PDF-") || len(body) < 200_000 {
		t.Fatalf("this does not look like the full manual (%d bytes)", len(body))
	}
	if !bytes.Contains([]byte(body), []byte("Blake UK Group")) || !bytes.Contains([]byte(body), []byte("User Manual")) {
		t.Error("the PDF's title and author are missing")
	}
	// Help (or the generator) changed since the PDF was built: rebuild it.
	if want := manualSourceHash(t); !strings.Contains(body, want) {
		t.Errorf("the PDF manual is out of date. Help or the manual generator changed after it was built.\n"+
			"Rebuild it: QRTRACK_BIN=<built qrtrack> GEOIP_DB=<city.mmdb> python3 tools/manual/build_manual.py\n(source hash now %s)", want[:12])
	}
	_, help := h.do(c, "GET", "/admin/help", nil, nil)
	if !strings.Contains(help, `href="/admin/manual.pdf"`) || !strings.Contains(help, "Download the PDF manual") {
		t.Error("Help should offer the PDF download")
	}
}
