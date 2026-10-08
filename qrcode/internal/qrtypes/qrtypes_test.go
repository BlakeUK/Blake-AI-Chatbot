package qrtypes

import (
	"strings"
	"testing"
	"time"
)

var (
	london, _ = time.LoadLocation("Europe/London")
	now       = time.Date(2026, 10, 8, 9, 0, 0, 0, time.UTC)
)

func build(t *testing.T, typ, kind string, in map[string]string) (Built, map[string]string) {
	t.Helper()
	s, ok := Get(typ)
	if !ok {
		t.Fatalf("no type %q", typ)
	}
	return Build(s, kind, in, london, now)
}

func mustBuild(t *testing.T, typ, kind string, in map[string]string) Built {
	t.Helper()
	b, errs := build(t, typ, kind, in)
	if len(errs) > 0 {
		t.Fatalf("%s/%s: %v", typ, kind, errs)
	}
	return b
}

func TestCatalogueIsConsistent(t *testing.T) {
	seen := map[string]bool{}
	for _, s := range All() {
		if seen[s.Type] {
			t.Errorf("duplicate type %s", s.Type)
		}
		seen[s.Type] = true
		if !s.Static && !s.Dynamic {
			t.Errorf("%s supports neither static nor dynamic", s.Type)
		}
		names := map[string]bool{}
		for _, f := range s.Fields {
			if names[f.Name] {
				t.Errorf("%s: duplicate field %s", s.Type, f.Name)
			}
			names[f.Name] = true
			if f.Kind == Select && len(f.Options) == 0 {
				t.Errorf("%s.%s: select with no options", s.Type, f.Name)
			}
		}
	}
	// the rules the product relies on
	for typ, want := range map[string][2]bool{"wifi": {true, false}, "text": {true, false}, "app_stores": {false, true}, "smart_url": {false, true}, "url": {true, true}, "vcard": {true, true}} {
		s, _ := Get(typ)
		if s.Static != want[0] || s.Dynamic != want[1] {
			t.Errorf("%s static/dynamic = %v/%v, want %v", typ, s.Static, s.Dynamic, want)
		}
	}
	if len(For(KindStatic)) == 0 || len(For(KindDynamic)) == 0 {
		t.Error("no types offered")
	}
	// A static-only type must refuse to be dynamic, and vice versa.
	if _, errs := build(t, "wifi", KindDynamic, map[string]string{"ssid": "x", "password": "y"}); errs["_"] == "" {
		t.Error("wifi accepted as dynamic")
	}
	if _, errs := build(t, "app_stores", KindStatic, map[string]string{}); errs["_"] == "" {
		t.Error("app stores accepted as static")
	}
}

func TestURLStaticVsDynamic(t *testing.T) {
	in := map[string]string{"url": "https://www.blake-uk.com/category/aerials.html"}
	st := mustBuild(t, "url", KindStatic, in)
	if st.Content != in["url"] || st.Target != "" {
		t.Errorf("static url: %+v", st)
	}
	dy := mustBuild(t, "url", KindDynamic, in)
	if dy.Target != in["url"] || dy.Content != "" {
		t.Errorf("dynamic url: %+v", dy)
	}
	for _, bad := range []string{"javascript:alert(1)", "data:text/html,x", "ftp://x.example", "not a url", "https://u:p@x.example"} {
		if _, errs := build(t, "url", KindStatic, map[string]string{"url": bad}); errs["url"] == "" {
			t.Errorf("accepted %q", bad)
		}
	}
}

func TestSocialAndGoogleHostChecks(t *testing.T) {
	ok := map[string]string{"facebook": "https://www.facebook.com/blakeuk", "instagram": "https://instagram.com/blakeuk", "youtube": "https://youtu.be/abc",
		"tiktok": "https://www.tiktok.com/@blake", "x": "https://x.com/blake", "pinterest": "https://pin.it/abc", "linkedin": "https://www.linkedin.com/company/blake",
		"google_form": "https://forms.gle/abc", "google_review": "https://g.page/r/abc/review"}
	for typ, u := range ok {
		if b := mustBuild(t, typ, KindDynamic, map[string]string{"url": u}); b.Target != u {
			t.Errorf("%s: %+v", typ, b)
		}
	}
	for typ, u := range map[string]string{"facebook": "https://evil.example/facebook.com", "instagram": "https://facebook.com/x", "youtube": "https://notyoutube.com/x",
		"google_form": "https://evil.example/forms", "x": "https://facebook.com.evil.example/"} {
		if _, errs := build(t, typ, KindDynamic, map[string]string{"url": u}); errs["url"] == "" {
			t.Errorf("%s accepted a link to %s", typ, u)
		}
	}
}

func TestWiFiFormatAndEscaping(t *testing.T) {
	b := mustBuild(t, "wifi", KindStatic, map[string]string{"ssid": `My;Net:"1"`, "password": `p\a;ss,w:d`, "security": "WPA", "hidden": "on"})
	want := `WIFI:T:WPA;S:My\;Net\:\"1\";P:p\\a\;ss\,w\:d;H:true;;`
	if b.Content != want {
		t.Errorf("wifi = %s\nwant   %s", b.Content, want)
	}
	open := mustBuild(t, "wifi", KindStatic, map[string]string{"ssid": "Guest", "security": "nopass"})
	if open.Content != "WIFI:T:nopass;S:Guest;;" {
		t.Errorf("open wifi = %s", open.Content)
	}
	if _, errs := build(t, "wifi", KindStatic, map[string]string{"ssid": "Guest", "security": "WPA"}); errs["password"] == "" {
		t.Error("a secured network needs a password")
	}
	if _, errs := build(t, "wifi", KindStatic, map[string]string{"ssid": "x", "password": "y", "security": "WPA9"}); errs["security"] == "" {
		t.Error("unknown security accepted")
	}
}

func TestVCardAndEvent(t *testing.T) {
	in := map[string]string{"first": "Ann", "last": "O'Neil; Jr", "org": "Blake UK, Ltd", "phone": "+441142235000", "email": "ann@blake-uk.com", "website": "https://www.blake-uk.com", "city": "Sheffield", "note": "line1\nline2"}
	st := mustBuild(t, "vcard", KindStatic, in)
	for _, want := range []string{"BEGIN:VCARD\r\n", "VERSION:3.0", `N:O'Neil\; Jr;Ann;;;`, "FN:Ann O'Neil\\; Jr", `ORG:Blake UK\, Ltd`, "TEL;TYPE=WORK,VOICE:+441142235000", "EMAIL;TYPE=INTERNET:ann@blake-uk.com", "URL:https://www.blake-uk.com", `NOTE:line1\nline2`, "END:VCARD\r\n"} {
		if !strings.Contains(st.Content, want) {
			t.Errorf("vcard missing %q:\n%s", want, st.Content)
		}
	}
	dy := mustBuild(t, "vcard", KindDynamic, in)
	if dy.Document != st.Content || !strings.HasPrefix(dy.DocType, "text/vcard") || dy.Content != "" || dy.Target != "" {
		t.Errorf("dynamic vcard: %+v", dy)
	}
	if _, errs := build(t, "vcard", KindStatic, map[string]string{"phone": "123"}); len(errs) == 0 {
		t.Error("vcard with no name accepted")
	}
	if _, errs := build(t, "vcard", KindStatic, map[string]string{"first": "A", "email": "nope"}); errs["email"] == "" {
		t.Error("bad email accepted in vcard")
	}

	ev := mustBuild(t, "event", KindStatic, map[string]string{"title": "Open day, 2026", "start": "2026-10-20T10:00", "end": "2026-10-20T16:30", "place": "Sheffield"})
	for _, want := range []string{"BEGIN:VCALENDAR", "BEGIN:VEVENT", "DTSTART:20261020T090000Z", "DTEND:20261020T153000Z", `SUMMARY:Open day\, 2026`, "LOCATION:Sheffield", "END:VCALENDAR", "DTSTAMP:20261008T090000Z"} {
		if !strings.Contains(ev.Content, want) { // 10:00 BST is 09:00 UTC
			t.Errorf("event missing %q:\n%s", want, ev.Content)
		}
	}
	if _, errs := build(t, "event", KindStatic, map[string]string{"title": "x", "start": "2026-10-20T10:00", "end": "2026-10-20T09:00"}); errs["end"] == "" {
		t.Error("end before start accepted")
	}
}

func TestContactTypes(t *testing.T) {
	if b := mustBuild(t, "email", KindStatic, map[string]string{"to": "sales@blake-uk.com", "subject": "Quote & price", "body": "Hi there"}); b.Content != "mailto:sales@blake-uk.com?body=Hi%20there&subject=Quote%20%26%20price" {
		t.Errorf("email = %s", b.Content)
	}
	if b := mustBuild(t, "email", KindStatic, map[string]string{"to": "a@b.co"}); b.Content != "mailto:a@b.co" {
		t.Errorf("bare email = %s", b.Content)
	}
	if _, errs := build(t, "email", KindStatic, map[string]string{"to": "not-an-email"}); errs["to"] == "" {
		t.Error("bad email accepted")
	}
	if b := mustBuild(t, "sms", KindStatic, map[string]string{"number": "+44 7700 900123", "message": "Hello"}); b.Content != "SMSTO:+447700900123:Hello" {
		t.Errorf("sms = %s", b.Content)
	}
	if b := mustBuild(t, "phone", KindStatic, map[string]string{"number": "0114 223 5000"}); b.Content != "tel:01142235000" {
		t.Errorf("phone = %s", b.Content)
	}
	for _, bad := range []string{"abc", "12", "+44;rm -rf", "tel:123456"} {
		if _, errs := build(t, "phone", KindStatic, map[string]string{"number": bad}); errs["number"] == "" {
			t.Errorf("phone %q accepted", bad)
		}
	}
	wa := mustBuild(t, "whatsapp", KindDynamic, map[string]string{"number": "+44 7700 900123", "message": "Hi, I need help"})
	if wa.Target != "https://wa.me/447700900123?text=Hi%2C+I+need+help" {
		t.Errorf("whatsapp = %s", wa.Target)
	}
	if st := mustBuild(t, "whatsapp", KindStatic, map[string]string{"number": "447700900123"}); st.Content != "https://wa.me/447700900123" {
		t.Errorf("static whatsapp = %s", st.Content)
	}
	if _, errs := build(t, "whatsapp", KindStatic, map[string]string{"number": "12"}); errs["number"] == "" {
		t.Error("short whatsapp number accepted")
	}
}

func TestLocationAndText(t *testing.T) {
	if b := mustBuild(t, "location", KindStatic, map[string]string{"lat": "53.3811", "lng": "-1.4701"}); b.Content != "geo:53.3811,-1.4701" {
		t.Errorf("geo = %s", b.Content)
	}
	if b := mustBuild(t, "location", KindDynamic, map[string]string{"lat": "53.3811", "lng": "-1.4701"}); b.Target != "https://www.google.com/maps?q=53.3811,-1.4701" {
		t.Errorf("maps = %s", b.Target)
	}
	for _, in := range []map[string]string{{"lat": "91", "lng": "0"}, {"lat": "0", "lng": "181"}, {"lat": "x", "lng": "0"}} {
		if _, errs := build(t, "location", KindStatic, in); len(errs) == 0 {
			t.Errorf("location %v accepted", in)
		}
	}
	if b := mustBuild(t, "text", KindStatic, map[string]string{"text": "Hello\nworld"}); b.Content != "Hello\nworld" {
		t.Errorf("text = %q", b.Content)
	}
	// size limits
	if _, errs := build(t, "text", KindStatic, map[string]string{"text": strings.Repeat("x", 801)}); errs["text"] == "" {
		t.Error("801-char text accepted")
	}
	long := map[string]string{"first": "A", "note": strings.Repeat("n", 200), "org": strings.Repeat("o", 100), "title": strings.Repeat("t", 100),
		"street": strings.Repeat("s", 120), "city": strings.Repeat("c", 80), "country": strings.Repeat("k", 60), "email": "a@b.co", "website": "https://" + strings.Repeat("w", 280) + ".com"}
	if _, errs := build(t, "vcard", KindStatic, long); errs["_"] == "" {
		t.Errorf("an oversized vCard must be refused as too big for a QR code: %v", errs)
	}
	// control characters never reach a payload
	if _, errs := build(t, "text", KindStatic, map[string]string{"text": "a\x00b"}); errs["text"] == "" {
		t.Error("NUL accepted")
	}
}

func TestAppStoresAndSmartURLRules(t *testing.T) {
	b := mustBuild(t, "app_stores", KindDynamic, map[string]string{"ios_url": "https://apps.apple.com/app/id1", "android_url": "https://play.google.com/store/apps/details?id=x", "url": "https://www.example.com/app"})
	if b.Target != "https://www.example.com/app" || len(b.Rules) != 2 || b.Rules[0] != (Rule{"os", "iOS", "https://apps.apple.com/app/id1"}) || b.Rules[1].Value != "Android" {
		t.Errorf("app stores: %+v", b)
	}
	in := map[string]string{"url": "https://www.blake-uk.com/", "r1_match": "country", "r1_value": "fr", "r1_url": "https://www.blake-uk.com/fr", "r2_match": "language", "r2_value": "EN-gb", "r2_url": "https://www.blake-uk.com/en-gb",
		"r3_match": "os", "r3_value": "ios", "r3_url": "https://apps.apple.com/x", "r4_match": "device", "r4_value": "Mobile", "r4_url": "https://m.blake-uk.com/"}
	s := mustBuild(t, "smart_url", KindDynamic, in)
	want := []Rule{{"country", "FR", "https://www.blake-uk.com/fr"}, {"language", "en-gb", "https://www.blake-uk.com/en-gb"}, {"os", "iOS", "https://apps.apple.com/x"}, {"device", "mobile", "https://m.blake-uk.com/"}}
	if len(s.Rules) != 4 {
		t.Fatalf("rules: %+v", s.Rules)
	}
	for i, r := range want {
		if s.Rules[i] != r {
			t.Errorf("rule %d = %+v, want %+v", i+1, s.Rules[i], r)
		}
	}
	bad := map[string]map[string]string{
		"no value":      {"r1_match": "os", "r1_url": "https://a.example"},
		"unknown os":    {"r1_match": "os", "r1_value": "BeOS", "r1_url": "https://a.example"},
		"bad country":   {"r1_match": "country", "r1_value": "Britain", "r1_url": "https://a.example"},
		"bad language":  {"r1_match": "language", "r1_value": "english!", "r1_url": "https://a.example"},
		"rule bad url":  {"r1_match": "os", "r1_value": "iOS", "r1_url": "javascript:1"},
		"missing match": {"r1_value": "iOS", "r1_url": "https://a.example"},
	}
	for name, extra := range bad {
		in := map[string]string{"url": "https://www.blake-uk.com/"}
		for k, v := range extra {
			in[k] = v
		}
		if _, errs := build(t, "smart_url", KindDynamic, in); len(errs) == 0 {
			t.Errorf("%s accepted", name)
		}
	}
}

func TestEveryTypeExplainsItself(t *testing.T) {
	for _, s := range All() {
		if len(s.HowTo) < 40 {
			t.Errorf("%s has no useful HowTo text: %q", s.Type, s.HowTo)
		}
		if s.Description == "" {
			t.Errorf("%s has no description", s.Type)
		}
		for _, f := range s.Fields {
			if f.Label == "" {
				t.Errorf("%s.%s has no label", s.Type, f.Name)
			}
		}
	}
}
