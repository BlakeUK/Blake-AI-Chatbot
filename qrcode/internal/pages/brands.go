// Package pages implements Link pages: a hosted "all our links" page for a
// company, in a chosen theme, with views and clicks counted.
package pages

import (
	"net/url"
	"strings"
)

// Brand is a company a page can be made for: its logos (one for light
// backgrounds, one for dark) and its colours.
type Brand struct {
	ID           string
	Name         string
	Website      string // a good first link and the default for new pages
	LogoOnLight  string // path under /static
	LogoOnDark   string
	Accent       string // the brand colour, for light backgrounds
	AccentOnDark string // a lighter tint of it that stays readable on dark backgrounds
	Title        string // default heading
	// ExampleLinks are suggested starting links. They are placeholders to edit,
	// not facts about the company's real accounts.
	ExampleLinks []ItemInput
}

// Brands are the three companies pages can be made for.
var Brands = []Brand{
	{
		ID: "blake-uk", Name: "Blake UK", Website: "https://www.blake-uk.com/",
		LogoOnLight: "/static/img/brands/blake-uk-on-light.png", LogoOnDark: "/static/img/brands/blake-uk-on-dark.png",
		Accent: "#485cc7", AccentOnDark: "#8694df", Title: "Official Links",
		ExampleLinks: []ItemInput{
			{Title: "Website", URL: "https://www.blake-uk.com/", Icon: "website"},
			{Title: "Free technical support", URL: "https://www.blake-uk.com/support.html", Icon: "link"},
			{Title: "Instruction manuals", URL: "https://www.blake-uk.com/instruction-manuals.html", Icon: "link"},
		},
	},
	{
		ID: "visionplus", Name: "VisionPlus", Website: "https://www.visionplus.co.uk",
		LogoOnLight: "/static/img/brands/visionplus-on-light.png", LogoOnDark: "/static/img/brands/visionplus-on-dark.png",
		Accent: "#dd9833", AccentOnDark: "#dd9833", Title: "Official Links",
		ExampleLinks: []ItemInput{
			{Title: "Website", URL: "https://www.visionplus.co.uk", Icon: "website"},
			{Title: "Facebook", URL: "https://www.facebook.com/visionplusuk", Icon: "facebook"},
			{Title: "Instagram", URL: "https://www.instagram.com/visionplusuk", Icon: "instagram"},
			{Title: "LinkedIn", URL: "https://www.linkedin.com/company/visionplusuk", Icon: "linkedin"},
			{Title: "TikTok", URL: "https://www.tiktok.com/@visionplusuk", Icon: "tiktok"},
		},
	},
	{
		ID: "solwise", Name: "Solwise", Website: "https://www.solwise.co.uk",
		LogoOnLight: "/static/img/brands/solwise-on-light.png", LogoOnDark: "/static/img/brands/solwise-on-dark.png",
		Accent: "#28225e", AccentOnDark: "#8c83d7", Title: "Official Links",
		ExampleLinks: []ItemInput{
			{Title: "Website", URL: "https://www.solwise.co.uk", Icon: "website"},
		},
	},
}

// BrandByID returns one brand.
func BrandByID(id string) (Brand, bool) {
	for _, b := range Brands {
		if b.ID == id {
			return b, true
		}
	}
	return Brand{}, false
}

// Theme is a visual style. It decides the layout and the base colours; the
// brand supplies the logo and the accent colour.
type Theme struct {
	ID          string
	Name        string
	Description string
	Dark        bool // a dark page, so the brand's on-dark logo and tint are used
}

// Themes are the available styles.
var Themes = []Theme{
	{ID: "midnight", Name: "Midnight", Dark: true, Description: "Deep navy with glowing accent curves, glass buttons and round icon badges."},
	{ID: "daylight", Name: "Daylight", Dark: false, Description: "Clean and light: white buttons on a soft background, with the brand colour for icons."},
	{ID: "bold", Name: "Bold", Dark: true, Description: "A dark page tinted with the brand colour and solid, brand-coloured buttons."},
}

// ThemeByID returns one theme.
func ThemeByID(id string) (Theme, bool) {
	for _, t := range Themes {
		if t.ID == id {
			return t, true
		}
	}
	return Theme{}, false
}

// IconNames are the icons a button can use ("auto" picks one from the address).
var IconNames = []string{"auto", "website", "facebook", "instagram", "linkedin", "tiktok", "youtube", "x", "pinterest", "whatsapp", "email", "phone", "location", "link"}

// socialIcons are the icons that also appear in the row of social buttons.
var socialIcons = map[string]bool{"facebook": true, "instagram": true, "linkedin": true, "tiktok": true, "youtube": true, "x": true, "pinterest": true, "whatsapp": true}

// IsSocial reports whether an icon belongs in the social row.
func IsSocial(icon string) bool { return socialIcons[icon] }

// Icon returns the SVG markup for an icon name, or the generic link icon.
func Icon(name string) string {
	if m, ok := iconMarkup[name]; ok {
		return m
	}
	return iconMarkup["link"]
}

var hostIcons = []struct{ suffix, icon string }{
	{"facebook.com", "facebook"}, {"fb.com", "facebook"}, {"fb.me", "facebook"},
	{"instagram.com", "instagram"}, {"linkedin.com", "linkedin"}, {"lnkd.in", "linkedin"},
	{"tiktok.com", "tiktok"}, {"youtube.com", "youtube"}, {"youtu.be", "youtube"},
	{"x.com", "x"}, {"twitter.com", "x"}, {"pinterest.com", "pinterest"}, {"pin.it", "pinterest"},
	{"wa.me", "whatsapp"}, {"whatsapp.com", "whatsapp"},
	{"maps.google.com", "location"}, {"maps.app.goo.gl", "location"},
}

// DetectIcon chooses an icon from a button's address: the social network's
// logo, an envelope for email, a handset for phone, otherwise a globe.
func DetectIcon(raw string) string {
	l := strings.ToLower(strings.TrimSpace(raw))
	switch {
	case strings.HasPrefix(l, "mailto:"):
		return "email"
	case strings.HasPrefix(l, "tel:"):
		return "phone"
	}
	u, err := url.Parse(l)
	if err != nil {
		return "link"
	}
	host := u.Hostname()
	for _, h := range hostIcons {
		if host == h.suffix || strings.HasSuffix(host, "."+h.suffix) {
			return h.icon
		}
	}
	return "website"
}
