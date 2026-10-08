package web

import (
	"strings"
	"testing"
	"unicode/utf8"
)

func FuzzParseLanguage(f *testing.F) {
	for _, s := range []string{"", "en-GB,en;q=0.9", "fr", "*", "x", strings.Repeat("a", 500), "en-\x00", "zh-Hans-CN"} {
		f.Add(s)
	}
	f.Fuzz(func(t *testing.T, h string) {
		got := parseLanguage(h)
		if len(got) > 35 || !utf8.ValidString(got) || strings.ContainsAny(got, " ,;<>\"'\x00") {
			t.Fatalf("parseLanguage(%q) = %q", h, got)
		}
	})
}

func FuzzCSVCellAndRefererAndTruncate(f *testing.F) {
	for _, s := range []string{"=cmd|' /C calc'!A0", "+1", "@x", "-1", "ok", "\t\r", "https://a.example/p?q=1#f", "not a url", "http://[::1"} {
		f.Add(s)
	}
	f.Fuzz(func(t *testing.T, s string) {
		if c := csvCell(s); c != "" && strings.ContainsRune("=+-@\t\r", rune(c[0])) {
			t.Fatalf("csvCell(%q) = %q still starts like a formula", s, c)
		}
		if h := refererHost(s); strings.ContainsAny(h, "/?#@ ") || len(h) > 253 {
			t.Fatalf("refererHost(%q) = %q", s, h)
		}
		if tr := truncate(s, 16); len(tr) > 16 || !utf8.ValidString(tr) && utf8.ValidString(s) {
			t.Fatalf("truncate(%q) = %q", s, tr)
		}
	})
}
