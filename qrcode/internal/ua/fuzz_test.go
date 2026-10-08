package ua

import "testing"

func FuzzParse(f *testing.F) {
	for _, s := range []string{"", "Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 Safari/604.1", "Googlebot/2.1", "\x00\xff", "WhatsApp/2", string(make([]byte, 5000))} {
		f.Add(s)
	}
	f.Fuzz(func(t *testing.T, s string) {
		i := Parse(s)
		if i.DeviceClass == "" {
			t.Fatalf("no device class for %q", s)
		}
	})
}
