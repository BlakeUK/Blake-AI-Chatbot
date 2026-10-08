// Package qrtypes is the catalogue of QR code types (link, Wi-Fi, vCard,
// social, app stores ...). Each type declares the form fields it needs and how
// those fields become either the exact text of a static QR code or the target
// of a dynamic (tracked) one. The web layer renders the forms from this
// catalogue, so adding a type means adding one entry here.
package qrtypes

import (
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"time"
	"unicode"
	"unicode/utf8"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/links"
)

const (
	KindStatic  = "static"
	KindDynamic = "dynamic"

	// MaxStaticBytes keeps static content comfortably inside what a QR code can
	// hold even at the highest error correction with a logo in the middle.
	MaxStaticBytes = 900
)

type FieldKind string

const (
	Text     FieldKind = "text"
	URL      FieldKind = "url"
	Email    FieldKind = "email"
	Tel      FieldKind = "tel"
	Password FieldKind = "password"
	Textarea FieldKind = "textarea"
	Select   FieldKind = "select"
	Number   FieldKind = "number"
	DateTime FieldKind = "datetime-local"
	Checkbox FieldKind = "checkbox"
)

type Option struct{ Value, Label string }

type Field struct {
	Name        string
	Label       string
	Help        string
	Placeholder string
	Kind        FieldKind
	Required    bool
	Max         int // maximum characters (0 = default 200)
	Options     []Option
}

type Spec struct {
	Type        string
	Label       string
	Group       string // Links, Contact, Social, Other
	Description string
	HowTo       string // plain-English "what to enter" guidance, shown in the form and in Help
	Static      bool   // can be a static code
	Dynamic     bool   // can be a dynamic (tracked) code
	Fields      []Field
}

// Rule is one smart-routing rule: if the visitor matches, send them to URL.
type Rule struct {
	Match string // os | device | language | country
	Value string
	URL   string
}

// Built is the result of validating a form for one type.
type Built struct {
	Content  string // static: the exact text encoded in the QR code
	Target   string // dynamic: where a scan is redirected
	Document string // dynamic vCard / event: the document served instead of a redirect
	DocType  string // MIME type of Document
	Rules    []Rule // dynamic: smart routing, first match wins; Target is the fallback
	Summary  string // short description for lists
}

var (
	osOptions     = []Option{{"iOS", "iPhone / iPad (iOS)"}, {"Android", "Android"}, {"Windows", "Windows"}, {"macOS", "Mac (macOS)"}, {"Linux", "Linux"}, {"ChromeOS", "ChromeOS"}}
	deviceOptions = []Option{{"mobile", "Mobile phone"}, {"tablet", "Tablet"}, {"desktop", "Desktop computer"}}
	matchOptions  = []Option{{"os", "Operating system"}, {"device", "Device type"}, {"language", "Language"}, {"country", "Country"}}
	langRe        = regexp.MustCompile(`^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?$`)
	countryRe     = regexp.MustCompile(`^[A-Za-z]{2}$`)
	phoneRe       = regexp.MustCompile(`^\+?[0-9][0-9 ()./-]{4,24}$`)
	emailRe       = regexp.MustCompile(`^[^@\s]+@[^@\s]+\.[^@\s]+$`)
)

func urlField(name, label, help, ph string) Field {
	return Field{Name: name, Label: label, Help: help, Placeholder: ph, Kind: URL, Required: true, Max: links.MaxURLLen}
}

func social(typ, label, host string) Spec {
	return Spec{Type: typ, Label: label, Group: "Social", Static: true, Dynamic: true,
		Description: "Send people to your " + label + " page, profile or post.",
		Fields:      []Field{urlField("url", label+" link", "Paste the address of your "+label+" page.", "https://www."+host+"/yourpage")}}
}

var catalogue = withHowTo(buildCatalogue())

const socialHowTo = "Open the page, profile or post in your browser (or the app's Share button) and copy its link, then paste it here. The address is checked, so a link to the wrong site is caught."

var howTo = map[string]string{
	"url":           "Type or paste the full web address, starting with https://. Open it in your browser first to make sure it works. As a dynamic code you can change the address later without reprinting; as a static code it is built into the picture for good.",
	"google_form":   "In Google Forms click Send, choose the link tab and copy the link (it starts docs.google.com or forms.gle). Paste it here. Scanning opens the form on the phone.",
	"google_review": "In your Google Business Profile choose Ask for reviews and copy your short review link (it starts g.page). Paste it here. Scanning opens your Google review box.",
	"app_stores":    "Paste your App Store link, your Google Play link and a web page for everyone else. iPhones and iPads go to the App Store, Android phones to Google Play, and computers to the web page. Use one printed code for all of them.",
	"smart_url":     "Set a default address, then add up to five rules. Each rule says: if the visitor's device, system, language or country is this, send them to that address. Rules are checked top to bottom and the first match wins; anyone who matches none goes to the default. Country rules need two-letter codes such as GB or FR.",
	"vcard":         "Fill in the details you want people to save. Only a name or a company is required. Scanning offers to add the contact to the phone. As a dynamic code you can correct the details later without reprinting; as a static code the details are printed in the picture, which makes the code denser, so keep it short.",
	"email":         "Enter the address to write to, and optionally a subject and a message. Scanning opens the phone's email app with these already filled in.",
	"sms":           "Enter the phone number with its country code (for example +44) and an optional message. Scanning opens the messages app ready to send.",
	"phone":         "Enter the phone number with its country code. Scanning offers to call it.",
	"whatsapp":      "Enter the WhatsApp number with its country code, digits only (for example 447700900123), and an optional first message. Scanning opens a WhatsApp chat with that number.",
	"location":      "Enter the latitude and longitude of the place. In Google Maps, right-click the spot and click the two numbers at the top of the menu to copy them. Scanning opens the map at that point.",
	"event":         "Give the event a title, a start and an end (UK time), and optionally where and some details. Scanning offers to add the event to the phone's calendar.",
	"wifi":          "Enter the network name exactly as it appears (capital letters matter), the password, and the security type (most business and home Wi-Fi is WPA). Scanning offers to join the network without anyone typing the password. Wi-Fi codes are always static, because the phone is not online yet when it scans.",
	"text":          "Type the message. Scanning simply shows the text. It needs no internet connection.",
}

func withHowTo(s []Spec) []Spec {
	for i := range s {
		if h, ok := howTo[s[i].Type]; ok {
			s[i].HowTo = h
		} else if s[i].Group == "Social" {
			s[i].HowTo = socialHowTo
		}
	}
	return s
}

func buildCatalogue() []Spec {
	s := []Spec{
		{Type: "url", Label: "Website link", Group: "Links", Static: true, Dynamic: true,
			Description: "Open any web page.", Fields: []Field{urlField("url", "Web address", "", "https://www.blake-uk.com/")}},
		{Type: "google_form", Label: "Google Form", Group: "Links", Static: true, Dynamic: true,
			Description: "Open a survey or form.", Fields: []Field{urlField("url", "Form link", "Must be a docs.google.com or forms.gle link.", "https://forms.gle/...")}},
		{Type: "google_review", Label: "Google review", Group: "Links", Static: true, Dynamic: true,
			Description: "Ask for a review on Google.", Fields: []Field{urlField("url", "Review link", "Your Google review link (g.page or google.com).", "https://g.page/r/.../review")}},
		{Type: "app_stores", Label: "App stores", Group: "Links", Dynamic: true,
			Description: "One code, the right store: iPhones go to the App Store, Android to Google Play.",
			Fields: []Field{
				urlField("ios_url", "App Store link", "Where iPhone and iPad users go.", "https://apps.apple.com/..."),
				urlField("android_url", "Google Play link", "Where Android users go.", "https://play.google.com/store/apps/..."),
				urlField("url", "Everyone else", "Computers and other devices. A web page about the app is ideal.", "https://www.example.com/app"),
			}},
		smartURL(),
		{Type: "vcard", Label: "Contact card (vCard)", Group: "Contact", Static: true, Dynamic: true,
			Description: "Save a contact to the phone's address book.",
			Fields: []Field{
				{Name: "first", Label: "First name", Kind: Text, Max: 60},
				{Name: "last", Label: "Last name", Kind: Text, Max: 60},
				{Name: "org", Label: "Company", Kind: Text, Max: 100},
				{Name: "title", Label: "Job title", Kind: Text, Max: 100},
				{Name: "phone", Label: "Phone", Kind: Tel, Max: 30},
				{Name: "mobile", Label: "Mobile", Kind: Tel, Max: 30},
				{Name: "email", Label: "Email", Kind: Email, Max: 120},
				{Name: "website", Label: "Website", Kind: URL, Max: 300},
				{Name: "street", Label: "Street", Kind: Text, Max: 120},
				{Name: "city", Label: "Town / city", Kind: Text, Max: 80},
				{Name: "postcode", Label: "Postcode", Kind: Text, Max: 20},
				{Name: "country", Label: "Country", Kind: Text, Max: 60},
				{Name: "note", Label: "Note", Kind: Text, Max: 200},
			}},
		{Type: "email", Label: "Email", Group: "Contact", Static: true,
			Description: "Start an email to an address.",
			Fields: []Field{
				{Name: "to", Label: "Send to", Kind: Email, Required: true, Max: 120},
				{Name: "subject", Label: "Subject", Kind: Text, Max: 150},
				{Name: "body", Label: "Message", Kind: Textarea, Max: 400},
			}},
		{Type: "sms", Label: "SMS text message", Group: "Contact", Static: true,
			Description: "Start a text message.",
			Fields: []Field{
				{Name: "number", Label: "Phone number", Kind: Tel, Required: true, Max: 30, Placeholder: "+447700900123"},
				{Name: "message", Label: "Message", Kind: Textarea, Max: 300},
			}},
		{Type: "phone", Label: "Phone call", Group: "Contact", Static: true,
			Description: "Dial a number.",
			Fields:      []Field{{Name: "number", Label: "Phone number", Kind: Tel, Required: true, Max: 30, Placeholder: "+441142235000"}}},
		{Type: "whatsapp", Label: "WhatsApp", Group: "Contact", Static: true, Dynamic: true,
			Description: "Open a WhatsApp chat, optionally with a message ready to send.",
			Fields: []Field{
				{Name: "number", Label: "WhatsApp number", Help: "With the country code, e.g. 447700900123.", Kind: Tel, Required: true, Max: 30},
				{Name: "message", Label: "Message", Kind: Textarea, Max: 300},
			}},
		social("facebook", "Facebook", "facebook.com"), social("instagram", "Instagram", "instagram.com"),
		social("youtube", "YouTube", "youtube.com"), social("tiktok", "TikTok", "tiktok.com"),
		social("x", "X (Twitter)", "x.com"), social("pinterest", "Pinterest", "pinterest.com"),
		social("linkedin", "LinkedIn", "linkedin.com"),
		{Type: "location", Label: "Location", Group: "Other", Static: true, Dynamic: true,
			Description: "Show a place on the map.",
			Fields: []Field{
				{Name: "lat", Label: "Latitude", Kind: Number, Required: true, Placeholder: "53.3811"},
				{Name: "lng", Label: "Longitude", Kind: Number, Required: true, Placeholder: "-1.4701"},
				{Name: "label", Label: "Place name", Kind: Text, Max: 100},
			}},
		{Type: "event", Label: "Calendar event", Group: "Other", Static: true, Dynamic: true,
			Description: "Add an event to the phone's calendar.",
			Fields: []Field{
				{Name: "title", Label: "Event title", Kind: Text, Required: true, Max: 120},
				{Name: "start", Label: "Starts (UK time)", Kind: DateTime, Required: true},
				{Name: "end", Label: "Ends (UK time)", Kind: DateTime, Required: true},
				{Name: "place", Label: "Where", Kind: Text, Max: 150},
				{Name: "description", Label: "Details", Kind: Textarea, Max: 300},
			}},
		{Type: "wifi", Label: "Wi-Fi network", Group: "Other", Static: true,
			Description: "Let guests join Wi-Fi by scanning. Static only: it has to work before they are online.",
			Fields: []Field{
				{Name: "ssid", Label: "Network name (SSID)", Kind: Text, Required: true, Max: 32},
				{Name: "password", Label: "Password", Kind: Text, Max: 63},
				{Name: "security", Label: "Security", Kind: Select, Options: []Option{{"WPA", "WPA / WPA2 / WPA3"}, {"WEP", "WEP"}, {"nopass", "None (open)"}}},
				{Name: "hidden", Label: "Hidden network", Kind: Checkbox},
			}},
		{Type: "text", Label: "Plain text", Group: "Other", Static: true,
			Description: "Show a short piece of text.",
			Fields:      []Field{{Name: "text", Label: "Text", Kind: Textarea, Required: true, Max: 800}}},
	}
	return s
}

func smartURL() Spec {
	f := []Field{urlField("url", "Default address", "Used when no rule below matches.", "https://www.blake-uk.com/")}
	for i := 1; i <= 5; i++ {
		n := strconv.Itoa(i)
		f = append(f,
			Field{Name: "r" + n + "_match", Label: "Rule " + n + ": if the visitor's", Kind: Select, Options: append([]Option{{"", "(not used)"}}, matchOptions...)},
			Field{Name: "r" + n + "_value", Label: "Rule " + n + ": is", Help: "Pick the system or device, or type a language (en, fr-FR) or a country code (GB, FR).", Kind: Text, Max: 12},
			Field{Name: "r" + n + "_url", Label: "Rule " + n + ": send them to", Kind: URL, Max: links.MaxURLLen})
	}
	return Spec{Type: "smart_url", Label: "Smart URL", Group: "Links", Dynamic: true,
		Description: "Send people to different pages by device, system, language or country.", Fields: f}
}

// All returns every type in display order.
func All() []Spec { return catalogue }

// Get returns one type by its identifier.
func Get(t string) (Spec, bool) {
	for _, s := range catalogue {
		if s.Type == t {
			return s, true
		}
	}
	return Spec{}, false
}

// For returns the types available for a kind (static or dynamic).
func For(kind string) []Spec {
	var out []Spec
	for _, s := range catalogue {
		if (kind == KindStatic && s.Static) || (kind == KindDynamic && s.Dynamic) {
			out = append(out, s)
		}
	}
	return out
}

// Supports reports whether the type can be created as the given kind.
func (s Spec) Supports(kind string) bool {
	return (kind == KindStatic && s.Static) || (kind == KindDynamic && s.Dynamic)
}

func (s Spec) field(name string) (Field, bool) {
	for _, f := range s.Fields {
		if f.Name == name {
			return f, true
		}
	}
	return Field{}, false
}

// Build validates the submitted values for a type and kind. errs maps a field
// name to a message for the person filling in the form; it is empty on success.
func Build(spec Spec, kind string, in map[string]string, loc *time.Location, now time.Time) (Built, map[string]string) {
	errs := map[string]string{}
	if !spec.Supports(kind) {
		errs["_"] = spec.Label + " cannot be a " + kind + " QR code."
		return Built{}, errs
	}
	v := map[string]string{}
	for _, f := range spec.Fields {
		val := strings.TrimSpace(in[f.Name])
		if f.Kind == Textarea {
			val = strings.TrimSpace(strings.ReplaceAll(in[f.Name], "\r\n", "\n"))
		}
		max := f.Max
		if max == 0 {
			max = 200
		}
		if utf8.RuneCountInString(val) > max {
			errs[f.Name] = fmt.Sprintf("%s must be %d characters or fewer.", f.Label, max)
		}
		for _, r := range val {
			if unicode.IsControl(r) && r != '\n' {
				errs[f.Name] = f.Label + " must not contain control characters."
				break
			}
		}
		if f.Required && val == "" {
			errs[f.Name] = f.Label + " is required."
		}
		v[f.Name] = val
	}
	if len(errs) > 0 {
		return Built{}, errs
	}

	var b Built
	switch spec.Type {
	case "url", "google_form", "google_review", "facebook", "instagram", "youtube", "tiktok", "x", "pinterest", "linkedin":
		u := checkURL(spec, v, "url", errs)
		if len(errs) > 0 {
			return Built{}, errs
		}
		b = linkOnly(kind, u)
	case "app_stores":
		ios := checkURL(spec, v, "ios_url", errs)
		and := checkURL(spec, v, "android_url", errs)
		def := checkURL(spec, v, "url", errs)
		if len(errs) > 0 {
			return Built{}, errs
		}
		b = Built{Target: def, Summary: "App stores", Rules: []Rule{{"os", "iOS", ios}, {"os", "Android", and}}}
	case "smart_url":
		def := checkURL(spec, v, "url", errs)
		rules := smartRules(spec, v, errs)
		if len(errs) > 0 {
			return Built{}, errs
		}
		b = Built{Target: def, Rules: rules, Summary: fmt.Sprintf("Smart URL (%d rules)", len(rules))}
	case "whatsapp":
		digits := onlyDigits(v["number"])
		if len(digits) < 7 || len(digits) > 15 {
			errs["number"] = "Enter the full number with country code, 7 to 15 digits."
			return Built{}, errs
		}
		u := "https://wa.me/" + digits
		if v["message"] != "" {
			u += "?text=" + url.QueryEscape(v["message"])
		}
		b = linkOnly(kind, u)
		b.Summary = "WhatsApp +" + digits
	case "location":
		lat, e1 := strconv.ParseFloat(v["lat"], 64)
		lng, e2 := strconv.ParseFloat(v["lng"], 64)
		if e1 != nil || lat < -90 || lat > 90 {
			errs["lat"] = "Latitude must be a number from -90 to 90."
		}
		if e2 != nil || lng < -180 || lng > 180 {
			errs["lng"] = "Longitude must be a number from -180 to 180."
		}
		if len(errs) > 0 {
			return Built{}, errs
		}
		coords := fmt.Sprintf("%s,%s", strconv.FormatFloat(lat, 'f', -1, 64), strconv.FormatFloat(lng, 'f', -1, 64))
		if kind == KindStatic {
			b = Built{Content: "geo:" + coords}
		} else {
			b = Built{Target: "https://www.google.com/maps?q=" + coords}
		}
		b.Summary = "Location " + coords
	case "vcard":
		doc, err := vcard(v)
		if err != "" {
			errs["first"] = err
			return Built{}, errs
		}
		if w := v["website"]; w != "" {
			if _, e := links.ValidateURL(w); e != nil {
				errs["website"] = "Website " + e.Error() + "."
				return Built{}, errs
			}
		}
		if m := v["email"]; m != "" && !emailRe.MatchString(m) {
			errs["email"] = "Enter a valid email address."
			return Built{}, errs
		}
		if kind == KindStatic {
			b = Built{Content: doc}
		} else {
			b = Built{Document: doc, DocType: "text/vcard; charset=utf-8"}
		}
		b.Summary = "Contact " + strings.TrimSpace(v["first"]+" "+v["last"])
	case "event":
		st, e1 := time.ParseInLocation("2006-01-02T15:04", v["start"], loc)
		en, e2 := time.ParseInLocation("2006-01-02T15:04", v["end"], loc)
		if e1 != nil {
			errs["start"] = "Enter a valid start date and time."
		}
		if e2 != nil {
			errs["end"] = "Enter a valid end date and time."
		}
		if len(errs) == 0 && !en.After(st) {
			errs["end"] = "The end must be after the start."
		}
		if len(errs) > 0 {
			return Built{}, errs
		}
		doc := ical(v, st, en, now)
		if kind == KindStatic {
			b = Built{Content: doc}
		} else {
			b = Built{Document: doc, DocType: "text/calendar; charset=utf-8"}
		}
		b.Summary = "Event " + v["title"]
	case "email":
		if !emailRe.MatchString(v["to"]) {
			errs["to"] = "Enter a valid email address."
			return Built{}, errs
		}
		q := url.Values{}
		if v["subject"] != "" {
			q.Set("subject", v["subject"])
		}
		if v["body"] != "" {
			q.Set("body", v["body"])
		}
		c := "mailto:" + v["to"]
		if len(q) > 0 {
			c += "?" + strings.ReplaceAll(q.Encode(), "+", "%20")
		}
		b = Built{Content: c, Summary: "Email " + v["to"]}
	case "sms":
		n := phone(v["number"], "number", errs)
		if len(errs) > 0 {
			return Built{}, errs
		}
		b = Built{Content: "SMSTO:" + n + ":" + v["message"], Summary: "SMS " + n}
	case "phone":
		n := phone(v["number"], "number", errs)
		if len(errs) > 0 {
			return Built{}, errs
		}
		b = Built{Content: "tel:" + n, Summary: "Phone " + n}
	case "wifi":
		sec := v["security"]
		if sec == "" {
			sec = "WPA"
		}
		if sec != "WPA" && sec != "WEP" && sec != "nopass" {
			errs["security"] = "Choose the security type."
			return Built{}, errs
		}
		if sec != "nopass" && v["password"] == "" {
			errs["password"] = "Enter the Wi-Fi password, or choose None for an open network."
			return Built{}, errs
		}
		c := "WIFI:T:" + sec + ";S:" + wifiEsc(v["ssid"]) + ";"
		if sec != "nopass" {
			c += "P:" + wifiEsc(v["password"]) + ";"
		}
		if v["hidden"] == "on" || v["hidden"] == "true" {
			c += "H:true;"
		}
		b = Built{Content: c + ";", Summary: "Wi-Fi " + v["ssid"]}
	case "text":
		b = Built{Content: v["text"], Summary: "Text"}
	default:
		errs["_"] = "Unknown QR code type."
		return Built{}, errs
	}

	if kind == KindStatic && len(b.Content) > MaxStaticBytes {
		errs["_"] = fmt.Sprintf("That is too much to fit in a QR code (%d bytes, limit %d). Shorten it.", len(b.Content), MaxStaticBytes)
		return Built{}, errs
	}
	if kind == KindDynamic && len(b.Document) > 4000 {
		errs["_"] = "That document is too long."
		return Built{}, errs
	}
	return b, errs
}

// linkOnly builds a type whose whole job is "open this address".
func linkOnly(kind, u string) Built {
	if kind == KindStatic {
		return Built{Content: u, Summary: links.SiteName(u)}
	}
	return Built{Target: u, Summary: links.SiteName(u)}
}

var socialHosts = map[string][]string{
	"facebook": {"facebook.com", "fb.com", "fb.me", "fb.watch"}, "instagram": {"instagram.com", "instagr.am"},
	"youtube": {"youtube.com", "youtu.be"}, "tiktok": {"tiktok.com"}, "x": {"x.com", "twitter.com", "t.co"},
	"pinterest": {"pinterest.com", "pin.it", "pinterest.co.uk"}, "linkedin": {"linkedin.com", "lnkd.in"},
	"google_form":   {"docs.google.com", "forms.gle", "forms.google.com"},
	"google_review": {"g.page", "google.com", "google.co.uk", "goo.gl", "maps.app.goo.gl", "share.google"},
}

func checkURL(spec Spec, v map[string]string, name string, errs map[string]string) string {
	f, _ := spec.field(name)
	u, err := links.ValidateURL(v[name])
	if err != nil {
		errs[name] = f.Label + " " + err.Error() + "."
		return ""
	}
	if hosts, ok := socialHosts[spec.Type]; ok {
		parsed, _ := url.Parse(u)
		host := strings.ToLower(parsed.Hostname())
		match := false
		for _, h := range hosts {
			if host == h || strings.HasSuffix(host, "."+h) {
				match = true
			}
		}
		if !match {
			errs[name] = fmt.Sprintf("That does not look like a %s link (expected %s).", spec.Label, hosts[0])
			return ""
		}
	}
	return u
}

func smartRules(spec Spec, v map[string]string, errs map[string]string) []Rule {
	var out []Rule
	for i := 1; i <= 5; i++ {
		n := strconv.Itoa(i)
		m, val, target := v["r"+n+"_match"], v["r"+n+"_value"], v["r"+n+"_url"]
		if m == "" && val == "" && target == "" {
			continue
		}
		if m == "" {
			errs["r"+n+"_match"] = "Choose what rule " + n + " looks at."
			continue
		}
		u, err := links.ValidateURL(target)
		if err != nil {
			errs["r"+n+"_url"] = "Rule " + n + " address " + err.Error() + "."
			continue
		}
		switch m {
		case "os":
			if !hasOption(osOptions, val) {
				errs["r"+n+"_value"] = "Rule " + n + ": type one of iOS, Android, Windows, macOS, Linux, ChromeOS."
				continue
			}
			val = optionValue(osOptions, val)
		case "device":
			if !hasOption(deviceOptions, val) {
				errs["r"+n+"_value"] = "Rule " + n + ": type mobile, tablet or desktop."
				continue
			}
			val = optionValue(deviceOptions, val)
		case "language":
			if !langRe.MatchString(val) {
				errs["r"+n+"_value"] = "Rule " + n + ": type a language such as en or fr-FR."
				continue
			}
			val = strings.ToLower(val)
		case "country":
			if !countryRe.MatchString(val) {
				errs["r"+n+"_value"] = "Rule " + n + ": type a two-letter country code such as GB or FR."
				continue
			}
			val = strings.ToUpper(val)
		default:
			errs["r"+n+"_match"] = "Unknown rule type."
			continue
		}
		out = append(out, Rule{Match: m, Value: val, URL: u})
	}
	return out
}

func hasOption(opts []Option, v string) bool { return optionValue(opts, v) != "" }

func optionValue(opts []Option, v string) string {
	for _, o := range opts {
		if strings.EqualFold(o.Value, v) {
			return o.Value
		}
	}
	return ""
}

func onlyDigits(s string) string {
	var b strings.Builder
	for _, r := range s {
		if r >= '0' && r <= '9' {
			b.WriteRune(r)
		}
	}
	return b.String()
}

func phone(s, field string, errs map[string]string) string {
	if !phoneRe.MatchString(s) {
		errs[field] = "Enter a phone number, for example +441142235000."
		return ""
	}
	out := strings.Builder{}
	for i, r := range s {
		if (r >= '0' && r <= '9') || (i == 0 && r == '+') {
			out.WriteRune(r)
		}
	}
	return out.String()
}

// wifiEsc escapes the characters that are special in the WIFI: format.
func wifiEsc(s string) string {
	r := strings.NewReplacer(`\`, `\\`, `;`, `\;`, `,`, `\,`, `:`, `\:`, `"`, `\"`)
	return r.Replace(s)
}

// textEsc escapes text for vCard and iCalendar values.
func textEsc(s string) string {
	r := strings.NewReplacer(`\`, `\\`, "\n", `\n`, ",", `\,`, ";", `\;`)
	return r.Replace(s)
}

func vcard(v map[string]string) (string, string) {
	if v["first"] == "" && v["last"] == "" && v["org"] == "" {
		return "", "Enter at least a name or a company."
	}
	name := strings.TrimSpace(v["first"] + " " + v["last"])
	if name == "" {
		name = v["org"]
	}
	var l []string
	l = append(l, "BEGIN:VCARD", "VERSION:3.0",
		"N:"+textEsc(v["last"])+";"+textEsc(v["first"])+";;;", "FN:"+textEsc(name))
	add := func(prop, val string) {
		if val != "" {
			l = append(l, prop+":"+textEsc(val))
		}
	}
	add("ORG", v["org"])
	add("TITLE", v["title"])
	if v["phone"] != "" {
		l = append(l, "TEL;TYPE=WORK,VOICE:"+textEsc(v["phone"]))
	}
	if v["mobile"] != "" {
		l = append(l, "TEL;TYPE=CELL,VOICE:"+textEsc(v["mobile"]))
	}
	if v["email"] != "" {
		l = append(l, "EMAIL;TYPE=INTERNET:"+textEsc(v["email"]))
	}
	if v["website"] != "" {
		l = append(l, "URL:"+v["website"])
	}
	if v["street"] != "" || v["city"] != "" || v["postcode"] != "" || v["country"] != "" {
		l = append(l, "ADR;TYPE=WORK:;;"+textEsc(v["street"])+";"+textEsc(v["city"])+";;"+textEsc(v["postcode"])+";"+textEsc(v["country"]))
	}
	add("NOTE", v["note"])
	l = append(l, "END:VCARD")
	return strings.Join(l, "\r\n") + "\r\n", ""
}

func ical(v map[string]string, start, end, now time.Time) string {
	uid := make([]byte, 8)
	rand.Read(uid)
	f := func(t time.Time) string { return t.UTC().Format("20060102T150405Z") }
	l := []string{"BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//qrtrack//EN", "BEGIN:VEVENT",
		"UID:" + hex.EncodeToString(uid) + "@qrtrack", "DTSTAMP:" + f(now), "DTSTART:" + f(start), "DTEND:" + f(end),
		"SUMMARY:" + textEsc(v["title"])}
	if v["place"] != "" {
		l = append(l, "LOCATION:"+textEsc(v["place"]))
	}
	if v["description"] != "" {
		l = append(l, "DESCRIPTION:"+textEsc(v["description"]))
	}
	l = append(l, "END:VEVENT", "END:VCALENDAR")
	return strings.Join(l, "\r\n") + "\r\n"
}
