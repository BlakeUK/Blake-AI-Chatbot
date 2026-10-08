package qrtypes

import (
	"strings"
	"testing"
)

func FuzzBuild(f *testing.F) {
	f.Add("url", "https://a.example", "x", "y", "z")
	f.Add("wifi", "ssid;:\\\"", "pw,;", "WPA", "on")
	f.Add("vcard", "A\r\nB", "\x00", "+44", "a@b.co")
	f.Add("smart_url", "https://a.example", "os", "ios", "https://b.example")
	f.Add("event", "T", "2026-13-45T99:99", "x", "")
	f.Add("location", "91", "181", "", "")
	f.Fuzz(func(t *testing.T, typ, a, b, c, d string) {
		spec, ok := Get(typ)
		if !ok {
			return
		}
		in := map[string]string{}
		vals := []string{a, b, c, d}
		for i, fld := range spec.Fields {
			in[fld.Name] = vals[i%4]
		}
		for _, kind := range []string{KindStatic, KindDynamic} {
			built, errs := Build(spec, kind, in, london, now)
			if len(errs) != 0 {
				continue
			}
			if kind == KindStatic && (built.Content == "" || len(built.Content) > MaxStaticBytes) {
				t.Fatalf("%s static: empty or oversized content (%d bytes)", typ, len(built.Content))
			}
			if kind == KindDynamic && built.Target == "" && built.Document == "" {
				t.Fatalf("%s dynamic: nothing to deliver", typ)
			}
			if built.Target != "" && !(strings.HasPrefix(built.Target, "https://") || strings.HasPrefix(built.Target, "http://")) {
				t.Fatalf("%s: redirect target %q is not a web address", typ, built.Target)
			}
			for _, r := range built.Rules {
				if !(strings.HasPrefix(r.URL, "https://") || strings.HasPrefix(r.URL, "http://")) {
					t.Fatalf("%s: rule target %q", typ, r.URL)
				}
			}
		}
	})
}
