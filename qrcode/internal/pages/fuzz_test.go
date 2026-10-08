package pages

import (
	"strings"
	"testing"
	"unicode/utf8"
)

func FuzzCleanLinkTarget(f *testing.F) {
	for _, s := range []string{"https://a.example", "mailto:a@b.co", "tel:+441142235000", "javascript:alert(1)", "", "//x", "https://[::1]/", "https://a.example/\x00", "TEL:+44 1", strings.Repeat("a", 5000)} {
		f.Add(s)
	}
	f.Fuzz(func(t *testing.T, raw string) {
		got, err := CleanLinkTarget(raw)
		if err != nil {
			return
		}
		// whatever is accepted must be a safe scheme, free of control characters and spaces, and stable when cleaned again
		l := strings.ToLower(got)
		if !(strings.HasPrefix(l, "https://") || strings.HasPrefix(l, "http://") || strings.HasPrefix(l, "mailto:") || strings.HasPrefix(l, "tel:")) {
			t.Fatalf("accepted %q as %q", raw, got)
		}
		for _, r := range got {
			if r < 0x20 || r == 0x7f || r == ' ' {
				t.Fatalf("accepted %q with a control character or space: %q", raw, got)
			}
		}
		if again, err := CleanLinkTarget(got); err != nil || again != got {
			t.Fatalf("not stable: %q -> %q -> %q (%v)", raw, got, again, err)
		}
	})
}

func FuzzInputClean(f *testing.F) {
	f.Add("slug-1", "Name", "visionplus", "midnight", "#dd9833", "T", "S", "Title", "https://a.example", "auto", "d")
	f.Add("", "", "", "", "#zzz", "\x00", "\xff", "", "javascript:1", "skull", strings.Repeat("d", 300))
	f.Fuzz(func(t *testing.T, slug, name, brand, theme, accent, title, sub, it, url, icon, desc string) {
		in := Input{Slug: slug, Name: name, Brand: brand, Theme: theme, Accent: accent, Title: title, Subtitle: sub,
			Items: []ItemInput{{Row: 0, Title: it, URL: url, Icon: icon, Description: desc}}}
		out, errs := in.Clean()
		if len(errs) != 0 {
			return
		}
		if !slugRe.MatchString(out.Slug) || !utf8.ValidString(out.Name) || len(out.Items) == 0 {
			t.Fatalf("accepted a bad page: %+v", out)
		}
		if _, ok := BrandByID(out.Brand); !ok {
			t.Fatalf("accepted unknown brand %q", out.Brand)
		}
		if out.Accent != "" {
			if _, ok := hexRGB(out.Accent); !ok {
				t.Fatalf("accepted a non-colour accent %q", out.Accent)
			}
		}
		for _, it := range out.Items {
			if it.Icon == "auto" || (it.Icon != "" && Icon(it.Icon) == "") {
				t.Fatalf("unresolved icon %q", it.Icon)
			}
		}
	})
}

func FuzzResolveAccent(f *testing.F) {
	for _, s := range []string{"", "#dd9833", "#000", "#GGGGGG", "red", "#ffffff", `#fff" onload="x`} {
		f.Add(s)
	}
	f.Fuzz(func(t *testing.T, accent string) {
		for _, b := range Brands {
			for _, th := range Themes {
				l, err := Resolve(b, th, strings.ToLower(accent))
				if err != nil {
					continue
				}
				css := l.CSS()
				if strings.ContainsAny(css, "\"'<>()\\") || strings.Count(css, "#") != 4 {
					t.Fatalf("css built from %q is not a plain set of colours: %q", accent, css)
				}
			}
		}
	})
}
